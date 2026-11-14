<?php
declare(strict_types=1);

namespace Portal\Web;

use Closure;
use Lib\ApiException;
use Lib\Rbac;
use Lib\Router;
use Lib\Uuid;

/** Staff area: dashboard, front desk, reservation actions, walk-in booking and guest search. */
final class StaffSite
{
    public static function register(Router $router, Closure $web): void
    {
        $g = fn (string $path, callable $h) => $router->add('GET', $path, $web($h));
        $p = fn (string $path, callable $h) => $router->add('POST', $path, $web($h));

        $g('/staff', self::dashboard(...));
        $g('/staff/account', GuestSite::profile(...));
        $g('/staff/reception', self::reception(...));
        $g('/staff/reservations/{id}', self::reservation(...));
        foreach (['check-in' => 'checkIn', 'check-out' => 'checkOut', 'charge' => 'charge', 'cancel' => 'cancel', 'no-show' => 'noShow', 'request-guarantee' => 'requestGuarantee',
            'approve-guarantee' => 'approveGuarantee', 'onsite-payment' => 'onsitePayment', 'request-override' => 'requestOverride'] as $path => $method) {
            $p('/staff/reservations/{id}/' . $path, [self::class, $method]);
        }
        $g('/staff/walk-in', self::walkIn(...));
        $p('/staff/walk-in', self::walkInBook(...));
        $g('/staff/guests', self::guests(...));

        StaffOps::register($router, $web);
        StaffFinance::register($router, $web);
        StaffAdmin::register($router, $web);
    }

    /** Parse "1,250.50" into centavos. */
    public static function centavos(string $v): int
    {
        $v = str_replace([',', ' '], '', $v);
        if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 5000000) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Enter an amount in pesos, for example 1250.50.');
        }
        return (int) round((float) $v * 100);
    }

    // ---- dashboard ------------------------------------------------------------------------------------------

    public static function dashboard(Ctx $c): array
    {
        $u = $c->require(...array_filter(Rbac::ROLES, fn ($r) => $r !== 'guest'));
        $role = $u['role'];
        $cards = [];
        $today = today();
        $try = function (callable $f) {
            try {
                return $f();
            } catch (ApiException) {
                return null;
            }
        };
        if (in_array($role, ['reception', 'manager'], true)) {
            $arr = $try(fn () => $c->svc('booking', 'GET', '/internal/reservations?status=CONFIRMED&checkIn=' . $today . '&limit=200')['reservations']);
            $in = $try(fn () => $c->svc('booking', 'GET', '/internal/reservations?status=CHECKED_IN&limit=200')['reservations']);
            $cards[] = ['Arrivals today', $arr === null ? '-' : (string) count($arr), '/staff/reception?tab=arrivals', 'Check guests in'];
            $cards[] = ['In house', $in === null ? '-' : (string) count($in), '/staff/reception?tab=inhouse', 'See who is staying'];
            $cards[] = ['Departures today', $in === null ? '-' : (string) count(array_filter($in, fn ($r) => $r['checkOut'] <= $today)), '/staff/reception?tab=departures', 'Check guests out'];
        }
        if (in_array($role, ['housekeeping', 'manager'], true)) {
            $t = $try(fn () => $c->svc('roomops', 'GET', '/internal/cleaning-tasks')['tasks']);
            $cards[] = ['Rooms to clean', $t === null ? '-' : (string) count(array_filter($t, fn ($x) => in_array($x['status'], ['OPEN', 'IN_PROGRESS'], true))), '/staff/housekeeping', 'Open the task board'];
            $cards[] = ['Awaiting inspection', $t === null ? '-' : (string) count(array_filter($t, fn ($x) => $x['status'] === 'CLEANED')), '/staff/housekeeping', 'Inspect and release'];
        }
        if ($role === 'maintenance') {
            $b = $try(fn () => $c->svc('roomops', 'GET', '/internal/maintenance-blocks?status=APPROVED')['blocks']);
            $cards[] = ['Active blocks', $b === null ? '-' : (string) count($b), '/staff/maintenance', 'See room blocks'];
        }
        if (in_array($role, ['cashier', 'manager'], true)) {
            $pay = $try(fn () => $c->svc('billing', 'GET', '/internal/payments?reconciled=0')['payments']);
            $ref = $try(fn () => $c->svc('billing', 'GET', '/internal/refunds?status=REQUESTED')['refunds']);
            $cards[] = ['Payments to match', $pay === null ? '-' : (string) count($pay), '/staff/finance', 'Reconcile payments'];
            $cards[] = ['Refunds waiting', $ref === null ? '-' : (string) count($ref), $role === 'manager' ? '/staff/approvals' : '/staff/finance?tab=refunds', 'Review refunds'];
        }
        if ($role === 'manager') {
            $n = self::approvalCount($c);
            $cards[] = ['Waiting for you', (string) $n, '/staff/approvals', 'Open the approvals queue'];
        }
        if ($role === 'steward') {
            $d = $try(fn () => $c->svc('booking', 'GET', '/internal/guests/duplicates'));
            $cards[] = ['Possible duplicate groups', $d === null ? '-' : (string) count($d['groups']), '/staff/guests/duplicates', 'Review duplicates'];
        }
        if ($role === 'it') {
            $q = $c->privacy->queue();
            $cards[] = ['Open privacy requests', (string) count($q), '/staff/admin/privacy', 'Handle requests'];
            $cards[] = ['System health', 'Check', '/staff/admin/health', 'Services, jobs and email'];
            $cards[] = ['Audit log', 'View', '/staff/admin/audit', 'Who did what'];
        }
        return View::page($c, 'staff/dashboard', ['cards' => $cards, 'role' => $role, 'name' => (string) ($c->account()['full_name'] ?? '')], 'Staff dashboard');
    }

    /** Items waiting for a manager: refunds, guarantees, maintenance conflicts, early arrivals, OTA requests, exports. */
    public static function approvalCount(Ctx $c): int
    {
        $n = 0;
        $calls = [
            ['billing', '/internal/refunds?status=REQUESTED', 'refunds'], ['booking', '/internal/reservations?guaranteeRequested=1', 'reservations'],
            ['roomops', '/internal/maintenance-blocks?status=CONFLICT', 'blocks'], ['roomops', '/internal/arrival-overrides', 'overrides'],
            ['booking', '/internal/ota/allotments?status=PENDING', 'allotments'],
        ];
        foreach ($calls as [$svc, $path, $key]) {
            try {
                $n += count($c->svc($svc, 'GET', $path)[$key]);
            } catch (ApiException) {
            }
        }
        try {
            $n += count(array_filter($c->svc('billing', 'GET', '/internal/exports')['exports'], fn ($e) => $e['status'] === 'REQUESTED'));
        } catch (ApiException) {
        }
        return $n;
    }

    // ---- front desk -----------------------------------------------------------------------------------------

    public static function reception(Ctx $c): array
    {
        $c->require('reception', 'manager');
        $today = today();
        $q = $c->q('q');
        $tab = in_array($c->q('tab'), ['arrivals', 'inhouse', 'departures', 'upcoming'], true) ? $c->q('tab') : 'arrivals';
        $arrivals = $c->svc('booking', 'GET', '/internal/reservations?status=CONFIRMED&to=' . $today . '&order=arrival&limit=200')['reservations'];
        $inhouse = $c->svc('booking', 'GET', '/internal/reservations?status=CHECKED_IN&order=arrival&limit=200')['reservations'];
        $departures = array_values(array_filter($inhouse, fn ($r) => $r['checkOut'] <= $today));
        $lists = ['arrivals' => $arrivals, 'inhouse' => $inhouse, 'departures' => $departures];
        if ($tab === 'upcoming') {
            $lists['upcoming'] = $c->svc('booking', 'GET', '/internal/reservations?status=CONFIRMED,HELD&from=' . plus_days($today, 1) . '&order=arrival&limit=100')['reservations'];
        }
        $rows = $q !== '' ? $c->svc('booking', 'GET', '/internal/reservations?limit=50&q=' . rawurlencode($q))['reservations'] : $lists[$tab];
        $ids = array_column(array_filter($rows, fn ($r) => in_array($r['status'], ['CHECKED_IN', 'CHECKED_OUT'], true)), 'reservationId');
        $stays = [];
        if ($ids) {
            foreach ($c->svc('roomops', 'GET', '/internal/stays?reservationIds=' . implode(',', $ids))['stays'] as $s) {
                $stays[$s['reservationId']] = implode(', ', array_column($s['rooms'], 'roomNumber'));
            }
        }
        return View::page($c, 'staff/reception', ['rows' => $rows, 'tab' => $tab, 'q' => $q, 'counts' => ['arrivals' => count($arrivals), 'inhouse' => count($inhouse), 'departures' => count($departures)], 'stays' => $stays, 'today' => $today], 'Front desk');
    }

    public static function reservation(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager', 'cashier');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);
        $role = $c->role();
        $folio = null;
        $stay = null;
        $readyRooms = [];
        $override = null;
        if (in_array($r['status'], ['CHECKED_IN', 'CHECKED_OUT'], true) && $role !== 'cashier') {
            $stay = ($c->svc('roomops', 'GET', '/internal/stays?reservationIds=' . $r['reservationId'])['stays'][0] ?? null);
        }
        if (in_array($r['status'], ['CHECKED_IN', 'CHECKED_OUT'], true)) {
            [$st, $f] = $c->svcRaw('billing', 'GET', '/internal/folios/' . $r['reservationId']);
            $folio = $st === 200 ? $f : null;
        }
        if ($r['status'] === 'CONFIRMED' && in_array($role, ['reception', 'manager'], true)) {
            $readyRooms = array_values(array_filter($c->svc('roomops', 'GET', '/internal/rooms?roomTypeId=' . $r['roomTypeId'])['rooms'], fn ($x) => $x['readiness'] === 'READY'));
            if ($r['checkIn'] > today()) {
                $override = $c->svc('roomops', 'GET', '/internal/arrival-overrides/' . $r['reservationId']);
            }
        }
        $cancel = GuestSite::cancelPreview($r);
        $noShowDue = $r['status'] === 'CONFIRMED' && time() >= \Lib\Dates::arrivalAt($r['checkIn']) + 4 * 3600;
        return View::page($c, 'staff/reservation', ['r' => $r, 'folio' => $folio, 'stay' => $stay, 'ready' => $readyRooms, 'override' => $override, 'cancel' => $cancel, 'noShowDue' => $noShowDue, 'startedAt' => gmdate('c')], 'Booking ' . $r['code']);
    }

    private static function back(string $id): string
    {
        return '/staff/reservations/' . $id;
    }

    public static function checkIn(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager');
        return $c->act(function () use ($c, $id): void {
            $rooms = array_values(array_filter((array) ($_POST['roomIds'] ?? []), fn ($x) => is_string($x) && Uuid::isValid($x)));
            $body = ['reservationId' => $id, 'identityVerified' => $c->postFlag('identityVerified'), 'identityDocType' => $c->post('identityDocType'), 'queueStartedAt' => $c->post('queueStartedAt')];
            if ($rooms !== []) {
                $body['roomIds'] = $rooms;
            }
            if ($c->post('overrideReason') !== '') {
                $body['overrideReason'] = $c->post('overrideReason');
            }
            $c->svc('roomops', 'POST', '/internal/stays/check-in', $body, 'ci-' . $id);
        }, 'Guest checked in. Their bill is opening.', self::back($id));
    }

    public static function checkOut(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager');
        return $c->act(function () use ($c, $id): void {
            $body = ['reservationId' => $id, 'settleOnSite' => $c->postFlag('settleOnSite')];
            if ($c->post('method') !== '') {
                // the settle method travels to Billing through the check-out call: record it first when taking the balance now
                if ($body['settleOnSite']) {
                    $c->svc('billing', 'POST', '/internal/folios/' . $id . '/settle', ['method' => $c->post('method')]);
                }
            }
            $c->svc('roomops', 'POST', '/internal/stays/check-out', $body);
        }, 'Guest checked out. The room is waiting for housekeeping.', self::back($id));
    }

    public static function charge(Ctx $c, string $id): array
    {
        $c->require('cashier', 'manager');
        return $c->act(fn () => $c->svc('billing', 'POST', '/internal/folios/' . $id . '/charges', ['description' => $c->post('description'), 'amountMinor' => self::centavos($c->post('amount'))]), 'Charge added to the bill.', self::back($id));
    }

    public static function cancel(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager');
        return $c->act(fn () => $c->svc('booking', 'POST', '/internal/reservations/' . $id . '/cancel', ['waivePenalty' => $c->postFlag('waive')]), 'Booking cancelled.', self::back($id));
    }

    public static function noShow(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager');
        return $c->act(fn () => $c->svc('booking', 'POST', '/internal/reservations/' . $id . '/no-show', ['waivePenalty' => $c->postFlag('waive')]), 'Marked as no-show. Unused nights are back on sale.', self::back($id));
    }

    public static function requestGuarantee(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager');
        return $c->act(fn () => $c->svc('booking', 'POST', '/internal/reservations/' . $id . '/request-guarantee', []), 'Sent to the manager for approval.', self::back($id));
    }

    public static function approveGuarantee(Ctx $c, string $id): array
    {
        $c->require('manager');
        return $c->act(fn () => $c->svc('booking', 'POST', '/internal/reservations/' . $id . '/approve-guarantee', []), 'Pay-at-hotel guarantee approved. The booking is confirmed.', $c->post('back', self::back($id)));
    }

    public static function onsitePayment(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager', 'cashier');
        return $c->act(fn () => $c->svc('billing', 'POST', '/internal/reservations/' . $id . '/onsite-payment', ['method' => $c->post('method', 'CASH')]), 'Payment recorded. The booking confirms in a few seconds.', self::back($id));
    }

    public static function requestOverride(Ctx $c, string $id): array
    {
        $c->require('reception', 'manager');
        return $c->act(fn () => $c->svc('roomops', 'POST', '/internal/arrival-overrides', ['reservationId' => $id, 'reason' => $c->post('reason')]), 'Early arrival sent to the manager.', self::back($id));
    }

    // ---- walk-in --------------------------------------------------------------------------------------------

    public static function walkIn(Ctx $c): array
    {
        $c->require('reception', 'manager');
        $in = $c->q('checkIn', today());
        $out = $c->q('checkOut', plus_days(today(), 1));
        $adults = max(1, (int) $c->q('adults', '2'));
        $rooms = max(1, min(5, (int) $c->q('rooms', '1')));
        $res = null;
        $error = null;
        if ($c->q('checkIn') !== '') {
            [$st, $d] = $c->svcRaw('booking', 'GET', '/internal/availability?' . http_build_query(['checkIn' => $in, 'checkOut' => $out, 'quantity' => $rooms, 'adults' => $adults]));
            $st === 200 ? $res = $d : $error = (string) ($d['error']['message'] ?? 'Could not check availability.');
        }
        return View::page($c, 'staff/walkin', ['form' => ['checkIn' => $in, 'checkOut' => $out, 'adults' => $adults, 'rooms' => $rooms], 'res' => $res, 'error' => $error], 'Walk-in booking');
    }

    public static function walkInBook(Ctx $c): array
    {
        $c->require('reception', 'manager');
        $back = '/staff/walk-in?' . http_build_query(['checkIn' => $c->post('checkIn'), 'checkOut' => $c->post('checkOut'), 'adults' => $c->post('adults', '1'), 'rooms' => $c->post('rooms', '1')]);
        $newId = null;
        $result = $c->act(function () use ($c, &$newId): void {
            $g = $c->svc('booking', 'POST', '/internal/guests', ['fullName' => $c->post('fullName'), 'email' => $c->post('email'), 'phone' => $c->post('phone')]);
            $r = $c->svc('booking', 'POST', '/internal/reservations', [
                'quoteId' => $c->post('quoteId'), 'roomTypeId' => $c->post('roomTypeId'), 'checkIn' => $c->post('checkIn'), 'checkOut' => $c->post('checkOut'), 'quantity' => (int) $c->post('rooms', '1'),
                'adults' => (int) $c->post('adults', '1'), 'onBehalfOfGuestId' => $g['guestId'],
            ], 'walkin-' . Uuid::v4());
            $newId = $r['reservationId'];
        }, 'Room held for 10 minutes. Take payment to confirm it.', $back, $back);
        return $newId !== null ? View::redirect('/staff/reservations/' . $newId) : $result;
    }

    public static function guests(Ctx $c): array
    {
        $c->require('reception', 'manager', 'steward');
        $q = $c->q('q');
        $list = $c->svc('booking', 'GET', '/internal/guests' . ($q !== '' ? '?q=' . rawurlencode($q) : ''))['guests'];
        return View::page($c, 'staff/guests', ['guests' => $list, 'q' => $q], 'Guests');
    }
}
