<?php
declare(strict_types=1);

use Billing\PayMongoAdapter;
use Billing\RefundPolicy;
use Booking\CancellationPolicy;
use Booking\PaymentOutcome;
use Booking\Quote;
use Lib\ApiException;
use Lib\Event;
use Lib\Pii;
use Lib\Rbac;
use Lib\Request;
use Lib\Retry;
use Lib\ServiceAuth;
use Portal\Totp;
use RoomOps\RoomRules;

return [
    'roles: housekeeping has no payment or identity access, guests cannot approve' => function (): void {
        foreach (['folio.read', 'folio.settle', 'charge.post', 'refund.approve', 'stay.checkin', 'reservation.read'] as $a) {
            check(!Rbac::can('housekeeping', $a), "housekeeping must not do {$a}");
        }
        check(Rbac::can('housekeeping', 'room.status'), 'housekeeping updates room status');
        check(!Rbac::can('guest', 'refund.approve') && !Rbac::can('cashier', 'refund.approve'), 'only managers approve refunds');
        check(Rbac::can('cashier', 'charge.post') && Rbac::can('manager', 'refund.approve'), 'cashier posts, manager approves');
        check(!Rbac::can('steward', 'reservation.guarantee') && !Rbac::can('it', 'stay.checkin'), 'steward and IT cannot approve bookings');
        throws(fn () => Rbac::require('guest', 'stay.checkin'), ApiException::class, 403);
    },
    'refunds: a manager approves, never their own request' => function (): void {
        RefundPolicy::assertCanApprove('manager', 'mgr-1', 'cashier-1');
        RefundPolicy::assertCanApprove('manager', 'mgr-1', 'system');
        throws(fn () => RefundPolicy::assertCanApprove('manager', 'mgr-1', 'mgr-1'), ApiException::class, 403);
        throws(fn () => RefundPolicy::assertCanApprove('cashier', 'cashier-1', 'cashier-1'), ApiException::class, 403);
        throws(fn () => RefundPolicy::assertCanApprove('cashier', 'cashier-2', 'cashier-1'), ApiException::class, 403);
    },
    'rooms: overlap is half-open and OCCUPIED has no manual exit' => function (): void {
        check(RoomRules::overlaps('2026-12-01', '2026-12-04', '2026-12-03', '2026-12-06'), 'overlap');
        check(!RoomRules::overlaps('2026-12-01', '2026-12-04', '2026-12-04', '2026-12-06'), 'back-to-back is fine');
        check(RoomRules::canSetManually('DIRTY', 'CLEANING') && RoomRules::canSetManually('CLEANING', 'READY'), 'cleaning path');
        check(!RoomRules::canSetManually('DIRTY', 'READY'), 'cannot skip cleaning');
        check(!RoomRules::canSetManually('OCCUPIED', 'READY') && !RoomRules::canSetManually('READY', 'OCCUPIED'), 'occupied only via stay events');
    },
    'delivery retries back off and are flagged for review after 5 attempts' => function (): void {
        same(['status' => 'PENDING', 'delay' => 30], Retry::afterFailure(1), 'attempt 1');
        same(['status' => 'PENDING', 'delay' => 240], Retry::afterFailure(4), 'attempt 4');
        same(['status' => 'REVIEW', 'delay' => 0], Retry::afterFailure(5), 'attempt 5');
    },
    'events carry no contact or card data' => function (): void {
        $e = Event::envelope('ReservationConfirmed', 'r1', 2, 'c1', ['reservationId' => 'r1', 'amountMinor' => 250000, 'currency' => 'PHP'], 0);
        same('1970-01-01T00:00:00Z', $e['occurredAt'], 'UTC time');
        same(1, $e['schemaVersion'], 'schema version');
        throws(fn () => Event::envelope('X', 'a', 1, 'c', ['guest' => ['email' => 'a@b.test']]), InvalidArgumentException::class);
        throws(fn () => Event::envelope('X', 'a', 1, 'c', ['cardNumber' => '4242']), InvalidArgumentException::class);
        same('[redacted]', Pii::scrub(['phone' => '123'])['phone'], 'logs are scrubbed');
    },
    'PayMongo: signature verifies the original body and rejects tampering' => function (): void {
        $body = '{"data":{"id":"evt_1"}}';
        $h = PayMongoAdapter::signatureHeader($body, 'whsec_x', 1700000000);
        check(PayMongoAdapter::verify($body, $h, 'whsec_x'), 'valid');
        check(!PayMongoAdapter::verify($body . ' ', $h, 'whsec_x'), 'body changed');
        check(!PayMongoAdapter::verify($body, $h, 'other'), 'wrong secret');
        check(!PayMongoAdapter::verify($body, null, 'whsec_x') && !PayMongoAdapter::verify($body, 't=1,te=', 'whsec_x'), 'missing signature');
        check(!PayMongoAdapter::verify($body, $h, ''), 'no secret configured');
    },
    'PayMongo: paid event is translated to our own fields; other events are ignored' => function (): void {
        $p = PayMongoAdapter::parsePaid(PayMongoAdapter::mockPaidEvent('cs_1', 'res-1', 250000, 'PHP'));
        same([250000, 'PHP', 'res-1', 'cs_1'], [$p['amountMinor'], $p['currency'], $p['reservationId'], $p['sessionId']], 'parsed');
        same(null, PayMongoAdapter::parsePaid(['data' => ['id' => 'e', 'attributes' => ['type' => 'source.chargeable']]]), 'ignored');
        throws(fn () => PayMongoAdapter::parsePaid(['data' => ['id' => 'e', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => []]]]), ApiException::class, 422);
    },
    'quotes: signed, tamper-proof and short-lived' => function (): void {
        $q = Quote::issue(['roomTypeId' => 'x', 'totalMinor' => 250000], 's3cret', 1000);
        same(250000, Quote::verify($q, 's3cret', 1000)['totalMinor'], 'valid quote');
        throws(fn () => Quote::verify($q, 'wrong', 1000), ApiException::class, 422);
        throws(fn () => Quote::verify($q, 's3cret', 1000 + Quote::TTL + 1), ApiException::class, 422);
        [$payload, $sig] = explode('.', $q);
        $cheap = rtrim(strtr(base64_encode(json_encode(['roomTypeId' => 'x', 'totalMinor' => 1, 'exp' => 9999999999])), '+/', '-_'), '=');
        throws(fn () => Quote::verify($cheap . '.' . $sig, 's3cret', 1000), ApiException::class, 422);
    },
    'cancellation: free 48h before 14:00 Manila arrival, else the first night is lost' => function (): void {
        $arrival = Lib\Dates::arrivalAt('2026-12-10');
        same(0, CancellationPolicy::penaltyMinor('2026-12-10', $arrival - 48 * 3600, 48, 250000, 2), 'exactly 48h ahead');
        same(500000, CancellationPolicy::penaltyMinor('2026-12-10', $arrival - 48 * 3600 + 1, 48, 250000, 2), 'one second late, two rooms');
    },
    'late payment decisions' => function (): void {
        same('confirm', PaymentOutcome::forStatus('HELD'), 'held');
        same('reallocate', PaymentOutcome::forStatus('EXPIRED'), 'expired');
        same('refund', PaymentOutcome::forStatus('CANCELLED'), 'cancelled');
        same('ignore', PaymentOutcome::forStatus('CONFIRMED'), 'confirmed');
        same('ignore', PaymentOutcome::forStatus('CHECKED_IN'), 'checked in');
    },
    'staff second factor: RFC 6238 test vector and drift window' => function (): void {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // "12345678901234567890"
        same('94287082', Totp::code($secret, 59, 8), 'RFC 6238 vector');
        check(Totp::verify($secret, Totp::code($secret, 1000000), 1000000), 'current code');
        check(Totp::verify($secret, Totp::code($secret, 1000000 - 30), 1000000), 'previous step');
        check(!Totp::verify($secret, Totp::code($secret, 1000000 + 300), 1000000), 'far future code');
    },
    'service calls: credentials and signed user context cannot be forged' => function (): void {
        putenv('TOKEN_PORTAL=portal-secret');
        $toRequest = function (array $headers): Request {
            $map = [];
            foreach ($headers as $line) {
                [$k, $v] = explode(': ', $line, 2);
                $map[strtolower($k)] = $v;
            }
            return new Request('GET', '/x', [], $map, '');
        };
        $ok = $toRequest(ServiceAuth::headers('portal', ['accountId' => 'acc-1', 'role' => 'guest']));
        same(['accountId' => 'acc-1', 'role' => 'guest'], ServiceAuth::authenticate($ok)['user'], 'verified user');
        same('service', ServiceAuth::authenticate($toRequest(ServiceAuth::headers('portal')))['user']['role'], 'no user -> service');

        $forged = $ok->headers;
        $forged['x-user-context'] = base64_encode(json_encode(['accountId' => 'acc-1', 'role' => 'manager']));
        throws(fn () => ServiceAuth::authenticate(new Request('GET', '/x', [], $forged, '')), ApiException::class, 401);
        $bad = $ok->headers;
        $bad['authorization'] = 'Bearer nope';
        throws(fn () => ServiceAuth::authenticate(new Request('GET', '/x', [], $bad, '')), ApiException::class, 401);
        throws(fn () => ServiceAuth::authenticate(new Request('GET', '/x', [], ['x-service-caller' => 'unknown', 'authorization' => 'Bearer '], '')), ApiException::class, 401);
    },
];
