<?php
declare(strict_types=1);

namespace Portal\Web;

use Lib\ApiException;
use Lib\Dates;

/** Guest account pages: bookings, cancellation, receipts, confirmation print/download, profile and privacy requests. */
final class GuestSite
{
    public static function account(Ctx $c): array
    {
        $c->require('guest');
        $all = $c->svc('booking', 'GET', '/internal/reservations?limit=100')['reservations'];
        $today = today();
        $upcoming = array_values(array_filter($all, fn ($r) => in_array($r['status'], ['HELD', 'CONFIRMED', 'CHECKED_IN'], true) && $r['checkOut'] >= $today && !($r['status'] === 'HELD' && strtotime($r['holdExpiresAt']) <= time())));
        usort($upcoming, fn ($a, $b) => strcmp($a['checkIn'], $b['checkIn']));
        $past = array_values(array_filter($all, fn ($r) => !in_array($r, $upcoming, true)));
        return View::page($c, 'account', ['upcoming' => $upcoming, 'past' => $past, 'name' => (string) ($c->account()['full_name'] ?? '')], 'My bookings');
    }

    public static function booking(Ctx $c, string $id): array
    {
        $c->require('guest');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);
        $cancel = self::cancelPreview($r);
        $receipt = in_array($r['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'CANCELLED', 'NO_SHOW'], true) ? $c->svcAsPortal('billing', 'GET', '/internal/receipts/' . $r['reservationId']) : null;
        return View::page($c, 'booking_detail', ['r' => $r, 'cancel' => $cancel, 'receipt' => $receipt], 'Booking ' . $r['code']);
    }

    /** What cancelling now would cost, using the terms saved with the booking. */
    public static function cancelPreview(array $r): array
    {
        $free = Dates::arrivalAt($r['checkIn']) - time() >= (int) $r['freeCancelHours'] * 3600;
        $penalty = $free || $r['status'] !== 'CONFIRMED' ? 0 : min((int) $r['amountMinor'], (int) $r['nightlyMinor'] * (int) $r['quantity']);
        $paid = $r['guarantee'] === 'PAID';
        return ['allowed' => in_array($r['status'], ['HELD', 'CONFIRMED'], true), 'free' => $free, 'penaltyMinor' => $penalty, 'refundMinor' => $paid ? max(0, (int) $r['amountMinor'] - $penalty) : 0,
            'freeUntil' => gmdate('Y-m-d H:i:s', Dates::arrivalAt($r['checkIn']) - (int) $r['freeCancelHours'] * 3600)];
    }

    public static function cancel(Ctx $c, string $id): array
    {
        $c->require('guest');
        return $c->act(function () use ($c, $id): void {
            $c->svc('booking', 'POST', '/internal/reservations/' . $id . '/cancel', []);
        }, 'Your booking was cancelled. Any refund is on its way to your original payment method.', '/account/bookings/' . $id);
    }

    /** Printable page, or a self-contained HTML file when ?download=1. */
    public static function confirmationDoc(Ctx $c, string $id): array
    {
        $c->require('guest');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);
        if (!in_array($r['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)) {
            $c->flash('error', 'Only confirmed bookings have a confirmation.');
            return View::redirect('/account/bookings/' . $id);
        }
        if ($c->q('download') === '1') {
            $css = (string) @file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');
            $page = View::page($c, 'doc_confirmation', ['r' => $r, 'inlineCss' => $css, 'description' => ''], 'Confirmation ' . $r['code'], 200, 'layout_doc');
            return View::download($page[1], 'MA-Hotel-' . $r['code'] . '.html', 'text/html; charset=utf-8');
        }
        return View::page($c, 'doc_confirmation', ['r' => $r, 'inlineCss' => ''], 'Confirmation ' . $r['code'], 200, 'layout_doc');
    }

    public static function receipt(Ctx $c, string $id): array
    {
        $c->require('guest');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);   // 404 unless it is this guest's booking
        $rc = $c->svcAsPortal('billing', 'GET', '/internal/receipts/' . $r['reservationId']);
        return View::page($c, 'receipt', ['r' => $r, 'rc' => $rc, 'inlineCss' => ''], 'Receipt ' . $r['code'], 200, 'layout_doc');
    }

    public static function profile(Ctx $c): array
    {
        $c->require();
        return View::page($c, 'profile', ['a' => $c->account()], 'My profile');
    }

    public static function profilePost(Ctx $c): array
    {
        $u = $c->require();
        return $c->act(function () use ($c, $u): void {
            $c->accounts->updateProfile($u['accountId'], $c->post('fullName'), $c->post('phone'), $c->postFlag('marketing'), $c->req->ip());
        }, 'Profile saved.', '/account/profile');
    }

    public static function passwordPost(Ctx $c): array
    {
        $u = $c->require();
        return $c->act(function () use ($c, $u): void {
            if (($_POST['new'] ?? '') !== ($_POST['new2'] ?? '')) {
                throw new ApiException(422, 'INVALID_REQUEST', 'The two new passwords do not match.');
            }
            $c->accounts->changePassword($u['accountId'], (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''), $c->req->ip());
        }, 'Password changed.', '/account/profile');
    }

    public static function privacy(Ctx $c): array
    {
        $c->require('guest');
        return View::page($c, 'privacy', ['requests' => $c->privacy->forAccount($c->user()['accountId']), 'a' => $c->account()], 'Your privacy');
    }

    public static function privacyPost(Ctx $c): array
    {
        $u = $c->require('guest');
        return $c->act(function () use ($c, $u): void {
            $type = $c->post('type');
            $c->privacy->file($u['accountId'], $type, $c->post('details'), $type === 'CORRECTION' ? ['fullName' => $c->post('fullName'), 'email' => $c->post('email'), 'phone' => $c->post('phone')] : null, $c->req->ip());
        }, 'Request received. The privacy lead will answer within 30 days.', '/account/privacy');
    }

    public static function privacyDownload(Ctx $c, string $id): array
    {
        $u = $c->require('guest');
        return View::download($c->privacy->result($id, $u['accountId']), 'my-ma-hotel-data.json', 'application/json');
    }
}
