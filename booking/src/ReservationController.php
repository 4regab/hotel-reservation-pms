<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Dates;
use Lib\Idempotency;
use Lib\Inbox;
use Lib\Inventory;
use Lib\PdoIdempotencyStore;
use Lib\Rbac;
use Lib\Request;
use Lib\ReservationState;
use Lib\ServiceAuth;
use Lib\Tx;
use Lib\Uuid;

/** Validates requests and calls BookingWorkflow. Private REST routes for the Booking service. */
final class ReservationController
{
    public function __construct(private \PDO $pdo, private BookingRepository $repo, private InventoryAllocator $inv, private BookingWorkflow $wf)
    {
    }

    /** GET /internal/availability: a quote, not reserved stock. */
    public function availability(Request $r): array
    {
        ServiceAuth::authenticate($r);
        $now = time();
        [$nights, $qty] = $this->stay($r->query, $now);
        $out = [];
        foreach ($this->repo->roomTypes() as $t) {
            $available = Inventory::minAvailable($this->inv->peek($t['room_type_id'], $nights, (int) $t['capacity']), $nights);
            $total = (int) $t['nightly_rate_minor'] * $qty * count($nights);
            $ok = $available >= $qty;
            $out[] = [
                'roomTypeId' => $t['room_type_id'], 'name' => $t['name'], 'available' => max(0, $available), 'nightlyMinor' => (int) $t['nightly_rate_minor'],
                'totalMinor' => $total, 'currency' => $t['currency'],
                'quoteId' => $ok ? Quote::issue([
                    'roomTypeId' => $t['room_type_id'], 'checkIn' => $r->query['checkIn'], 'checkOut' => $r->query['checkOut'], 'quantity' => $qty,
                    'nightlyMinor' => (int) $t['nightly_rate_minor'], 'totalMinor' => $total, 'currency' => $t['currency'],
                ], $this->secret(), $now) : null,
            ];
        }
        return [200, ['checkIn' => $r->query['checkIn'], 'checkOut' => $r->query['checkOut'], 'nights' => count($nights), 'quantity' => $qty, 'options' => $out]];
    }

    /** POST /internal/reservations (Idempotency-Key required). */
    public function create(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.create');
        $b = $r->json();
        $now = time();
        $quote = Quote::verify((string) ($b['quoteId'] ?? ''), $this->secret(), $now);
        foreach (['roomTypeId', 'checkIn', 'checkOut', 'quantity'] as $f) {
            if (($b[$f] ?? null) !== $quote[$f]) {
                throw new ApiException(422, 'QUOTE_MISMATCH', "{$f} does not match the quote");
            }
        }
        [$nights] = $this->stay($quote, $now);
        $onBehalf = $b['onBehalfOfGuestId'] ?? null;
        if ($onBehalf !== null) {
            if (!in_array($user['role'], ['reception', 'manager'], true)) {
                throw new ApiException(403, 'FORBIDDEN', 'Only reception may book for a guest');
            }
            if (!is_string($onBehalf) || !Uuid::isValid($onBehalf)) {
                throw new ApiException(422, 'INVALID_REQUEST', 'onBehalfOfGuestId must be a UUID');
            }
        } else {
            $g = $b['guest'] ?? [];
            foreach (['fullName', 'email', 'phone'] as $f) {
                if (!is_string($g[$f] ?? null) || trim($g[$f]) === '') {
                    throw new ApiException(422, 'INVALID_REQUEST', "guest.{$f} is required");
                }
            }
        }
        $this->inv->ensureNights($quote['roomTypeId'], $nights);   // outside the booking transaction
        // The idempotency record is written in the SAME transaction as the hold.
        [$status, $body, $replayed] = Tx::run($this->pdo, fn () => Idempotency::run(
            new PdoIdempotencyStore($this->pdo), $r->header('Idempotency-Key'), $user['accountId'], Idempotency::hash($r->method, $r->path, $r->body),
            fn () => $this->wf->hold($user['accountId'], $quote, $onBehalf, $b['guest'] ?? [], $now), $now
        ));
        return [$status, $body, $replayed ? ['Idempotent-Replayed' => 'true'] : []];
    }

    /** GET /internal/reservations/{id}. Not yours or not found -> 404. */
    public function get(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.read');
        return [200, $this->view($this->visible($p['id'], $user), $user)];
    }

    public function cancel(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.cancel');
        $this->visible($p['id'], $user);
        return $this->wf->cancel($p['id'], $user['role'] === 'manager' && ($r->json()['waivePenalty'] ?? false) === true, time());
    }

    public function approveGuarantee(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.guarantee');
        $this->visible($p['id'], $user);
        return $this->wf->approveGuarantee($p['id'], time());
    }

    /** POST /internal/maintenance-capacity (Room Operations only). */
    public function maintenanceCapacity(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'capacity.reserve');
        $b = $r->json();
        foreach (['requestId', 'roomTypeId'] as $f) {
            if (!is_string($b[$f] ?? null) || !Uuid::isValid($b[$f])) {
                throw new ApiException(422, 'INVALID_REQUEST', "{$f} must be a UUID");
            }
        }
        if (!is_string($b['startDate'] ?? null) || !is_string($b['endDate'] ?? null) || !Dates::isDate($b['startDate']) || !Dates::isDate($b['endDate']) || $b['endDate'] <= $b['startDate']) {
            throw new ApiException(422, 'INVALID_REQUEST', 'startDate/endDate must be dates with endDate after startDate');
        }
        $nights = Dates::nights($b['startDate'], $b['endDate']);
        if (count($nights) > 366 || $this->repo->roomType($b['roomTypeId']) === null) {
            throw new ApiException(422, 'INVALID_REQUEST', 'unknown room type or range too long');
        }
        $this->inv->ensureNights($b['roomTypeId'], $nights);
        return $this->wf->reserveMaintenance($b['requestId'], $b['roomTypeId'], $b['startDate'], $b['endDate']);
    }

    /** POST /internal/events: consume PaymentCaptured, StayCheckedIn, StayCheckedOut. Deduplicated by eventId. */
    public function events(Request $r): array
    {
        ServiceAuth::authenticate($r);
        $e = $r->json();
        if (!is_string($e['eventId'] ?? null) || !Uuid::isValid($e['eventId']) || !is_string($e['eventType'] ?? null) || !is_array($e['data'] ?? null)) {
            throw new ApiException(422, 'INVALID_EVENT');
        }
        $d = $e['data'];
        $apply = match ($e['eventType']) {
            'PaymentCaptured' => function () use ($d): void {
                foreach (['reservationId', 'paymentId', 'amountMinor', 'currency'] as $f) {
                    if (!isset($d[$f])) {
                        throw new ApiException(422, 'INVALID_EVENT', "missing {$f}");
                    }
                }
                $this->wf->onPaymentCaptured($d);
            },
            'StayCheckedIn'  => fn () => $this->wf->onStay(ReservationState::CHECKED_IN, (string) ($d['reservationId'] ?? '')),
            'StayCheckedOut' => fn () => $this->wf->onStay(ReservationState::CHECKED_OUT, (string) ($d['reservationId'] ?? '')),
            default          => static function (): void {   // RefundCompleted etc.: nothing to change here
            },
        };
        return [200, ['status' => Inbox::once($this->pdo, $e['eventId'], $apply) ? 'applied' : 'duplicate']];
    }

    /** @return array{0:list<string>,1:int} nights and quantity, validated */
    private function stay(array $in, int $now): array
    {
        $ci = $in['checkIn'] ?? null;
        $co = $in['checkOut'] ?? null;
        $qty = filter_var($in['quantity'] ?? 1, FILTER_VALIDATE_INT);
        if (!is_string($ci) || !is_string($co) || !Dates::isDate($ci) || !Dates::isDate($co) || $co <= $ci) {
            throw new ApiException(422, 'INVALID_REQUEST', 'checkIn and checkOut must be dates (YYYY-MM-DD) with checkOut after checkIn');
        }
        $nights = Dates::nights($ci, $co);
        if (count($nights) > Dates::MAX_NIGHTS || $ci < Dates::manilaToday($now)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'stay must start today or later and be at most ' . Dates::MAX_NIGHTS . ' nights');
        }
        if ($qty === false || $qty < 1 || $qty > 5) {
            throw new ApiException(422, 'INVALID_REQUEST', 'quantity must be 1 to 5');
        }
        return [$nights, $qty];
    }

    private function visible(string $id, array $user): array
    {
        $row = Uuid::isValid($id) ? $this->repo->find($id) : null;
        if ($row === null || ($user['role'] === 'guest' && $row['guest_account_id'] !== $user['accountId'])) {
            throw new ApiException(404, 'NOT_FOUND');
        }
        return $row;
    }

    private function view(array $row, array $user): array
    {
        return [
            'reservationId' => $row['reservation_id'], 'status' => $row['status'], 'guarantee' => $row['guarantee'], 'roomTypeId' => $row['room_type_id'],
            'checkIn' => $row['check_in'], 'checkOut' => $row['check_out'], 'quantity' => (int) $row['quantity'], 'amountMinor' => (int) $row['quote_minor'],
            'currency' => $row['currency'], 'holdExpiresAt' => Dates::sqlToIso($row['hold_expires_at']), 'version' => (int) $row['version'],
        ] + ($user['role'] === 'service' ? ['guestAccountId' => $row['guest_account_id']] : []);
    }

    private function secret(): string
    {
        return (string) getenv('QUOTE_SECRET') ?: throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'QUOTE_SECRET is not configured');
    }
}
