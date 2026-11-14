<?php
declare(strict_types=1);

namespace Portal\Web;

use Closure;
use Lib\ApiException;
use Lib\Rbac;
use Lib\Router;
use Lib\Uuid;

/** IT and privacy lead pages (users, audit log, health, privacy requests) and the data steward's duplicate merge. */
final class StaffAdmin
{
    public static function register(Router $router, Closure $web): void
    {
        $g = fn (string $path, callable $h) => $router->add('GET', $path, $web($h));
        $p = fn (string $path, callable $h) => $router->add('POST', $path, $web($h));
        $g('/staff/admin/users', self::users(...));
        $p('/staff/admin/users', self::userCreate(...));
        $p('/staff/admin/users/{id}/role', self::userRole(...));
        $p('/staff/admin/users/{id}/active', self::userActive(...));
        $p('/staff/admin/users/{id}/totp', self::userTotp(...));
        $p('/staff/admin/users/{id}/reset-link', self::userResetLink(...));
        $g('/staff/admin/audit', self::audit(...));
        $g('/staff/admin/health', self::health(...));
        $g('/staff/admin/privacy', self::privacyQueue(...));
        $g('/staff/admin/privacy/{id}', self::privacyDetail(...));
        $p('/staff/admin/privacy/{id}/{verb}', self::privacyAct(...));
        $g('/staff/guests/duplicates', self::duplicates(...));
        $p('/staff/guests/merge', self::merge(...));
    }

    // ---- users -------------------------------------------------------------------------------------------------

    public static function users(Ctx $c): array
    {
        $c->require('it');
        $role = $c->q('role');
        $q = $c->q('q');
        return View::page($c, 'staff/users', ['accounts' => $c->accounts->listAccounts($role, $q), 'role' => $role, 'q' => $q, 'me' => $c->user()['accountId']], 'Users and roles');
    }

    public static function userCreate(Ctx $c): array
    {
        $u = $c->require('it');
        try {
            $c->checkCsrf();
            $made = $c->accounts->createStaff($u, ['email' => $c->post('email'), 'fullName' => $c->post('fullName'), 'role' => $c->post('role'), 'phone' => $c->post('phone')], $c->req->ip());
            return View::page($c, 'staff/credentials', ['made' => $made, 'email' => strtolower($c->post('email')), 'intro' => 'Account created.'], 'New staff account');
        } catch (ApiException $e) {
            $c->flash('error', Ctx::friendly($e));
            return View::redirect('/staff/admin/users');
        }
    }

    public static function userRole(Ctx $c, string $id): array
    {
        $u = $c->require('it');
        return $c->act(fn () => $c->accounts->setRole($u, $id, $c->post('role'), $c->req->ip()), 'Role changed. The person was signed out everywhere.', '/staff/admin/users');
    }

    public static function userActive(Ctx $c, string $id): array
    {
        $u = $c->require('it');
        return $c->act(fn () => $c->accounts->setActive($u, $id, $c->post('active') === '1', $c->req->ip()), $c->post('active') === '1' ? 'Account enabled.' : 'Account disabled and signed out.', '/staff/admin/users');
    }

    public static function userTotp(Ctx $c, string $id): array
    {
        $u = $c->require('it');
        try {
            $c->checkCsrf();
            $t = $c->accounts->resetTotp($u, $id, $c->req->ip());
            return View::page($c, 'staff/credentials', ['made' => $t, 'email' => $c->post('email'), 'intro' => 'Second factor reset. The person must add this new secret to their authenticator app.'], 'New second factor');
        } catch (ApiException $e) {
            $c->flash('error', Ctx::friendly($e));
            return View::redirect('/staff/admin/users');
        }
    }

    public static function userResetLink(Ctx $c, string $id): array
    {
        $u = $c->require('it');
        return $c->act(fn () => $c->accounts->sendResetLink($u, $id, $c->req->ip()), 'Password reset link queued by email.', '/staff/admin/users');
    }

    // ---- audit and health ---------------------------------------------------------------------------------------

    public static function audit(Ctx $c): array
    {
        $u = $c->require('it', 'manager');
        $f = ['action' => $c->q('action'), 'actor' => Uuid::isValid($c->q('actor')) ? $c->q('actor') : '', 'from' => $c->q('from'), 'to' => $c->q('to')];
        $feed = $c->oversight->auditFeed($u, array_filter($f, fn ($v) => $v !== ''));
        return View::page($c, 'staff/audit', ['feed' => $feed, 'f' => $f], 'Audit log');
    }

    public static function health(Ctx $c): array
    {
        $u = $c->require('it', 'manager');
        return View::page($c, 'staff/health', ['h' => $c->oversight->health($u), 'emails' => $c->oversight->recentEmails()], 'System health');
    }

    // ---- privacy requests -----------------------------------------------------------------------------------------

    public static function privacyQueue(Ctx $c): array
    {
        $c->require('it');
        $status = $c->q('status');
        return View::page($c, 'staff/privacy_queue', ['rows' => $c->privacy->queue($status), 'status' => $status], 'Privacy requests');
    }

    public static function privacyDetail(Ctx $c, string $id): array
    {
        $c->require('it');
        $req = $c->privacy->find($id);
        $req['correction'] = $req['type'] === 'CORRECTION' ? json_decode((string) $req['details'], true) : null;
        return View::page($c, 'staff/privacy_detail', ['q' => $req], 'Privacy request');
    }

    public static function privacyAct(Ctx $c, string $id, string $verb): array
    {
        $u = $c->require('it');
        if (!in_array($verb, ['review', 'complete', 'reject'], true)) {
            throw new ApiException(404, 'NOT_FOUND');
        }
        return $c->act(function () use ($c, $u, $id, $verb): void {
            match ($verb) {
                'review' => $c->privacy->startReview($u, $id, $c->req->ip()),
                'complete' => $c->privacy->complete($u, $id, $c->post('note'), $c->req->ip()),
                'reject' => $c->privacy->reject($u, $id, $c->post('note'), $c->req->ip()),
            };
        }, match ($verb) { 'review' => 'Request is now in review.', 'complete' => 'Request completed.', default => 'Request declined and the guest was told why.' }, '/staff/admin/privacy/' . $id);
    }

    // ---- data steward ---------------------------------------------------------------------------------------------

    public static function duplicates(Ctx $c): array
    {
        $c->require('steward');
        $d = $c->svc('booking', 'GET', '/internal/guests/duplicates');
        return View::page($c, 'staff/duplicates', ['groups' => $d['groups'], 'active' => $d['activeGuests']], 'Possible duplicate guests');
    }

    public static function merge(Ctx $c): array
    {
        $c->require('steward');
        return $c->act(function () use ($c): void {
            $keep = $c->post('keepGuestId');
            $drop = $c->post('mergeGuestId');
            $r = $c->svc('booking', 'POST', '/internal/guests/merge', ['keepGuestId' => $keep, 'mergeGuestId' => $drop, 'note' => $c->post('note')]);
            $c->flash('ok', 'Merged. ' . $r['reservationsMoved'] . ' ' . ($r['reservationsMoved'] === 1 ? 'booking moved' : 'bookings moved') . ' to the kept record.');
        }, '', '/staff/guests/duplicates');
    }
}
