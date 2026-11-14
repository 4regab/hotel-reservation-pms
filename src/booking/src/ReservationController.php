<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
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
    private const LIST_LIMIT = 200;

    public function __construct(private \PDO $pdo, private BookingRepository $repo, private InventoryAllocator $inv, private BookingWorkflow $wf)
    {
    }

    /** GET /internal/availability: a quote, not reserved stock. */
    public function availability(Request $r): array
    {
        ServiceAuth::authenticate($r);
        $now = time();
        [$nights, $qty] = $this->stay($r->query, $now);
        $adults = max(1, (int) ($r->query['adults'] ?? 1));
        $out = [];
        foreach ($this->repo->roomTypes() as $t) {
            if (!(int) $t['active']) {
                continue;
            }
            $available = Inventory::minAvailable($this->inv->peek($t['room_type_id'], $nights, (int) $t['capacity']), $nights);
            $total = (int) $t['nightly_rate_minor'] * $qty * count($nights);
            $fits = $adults <= (int) $t['max_guests'] * $qty;
            $ok = $available >= $qty && $fits;
            $out[] = CatalogController::typeView($t) + [
                'available' => max(0, $available), 'totalMinor' => $total, 'fits' => $fits,
                'quoteId' => $ok ? Quote::issue([
                    'roomTypeId' => $t['room_type_id'], 'checkIn' => $r->query['checkIn'], 'checkOut' => $r->query['checkOut'], 'quantity' => $qty,
                    'nightlyMinor' => (int) $t['nightly_rate_minor'], 'totalMinor' => $total, 'currency' => $t['currency'],
                ], $this->secret(), $now) : null,
            ];
        }
        return [200, ['checkIn' => $r->query['checkIn'], 'checkOut' => $r->query['checkOut'], 'nights' => count($nights), 'quantity' => $qty, 'adults' => $adults, 'options' => $out]];
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
        $type = $this->repo->roomType($quote['roomTypeId']);
        if ($type === null || !(int) $type['active']) {
            throw new ApiException(422, 'UNKNOWN_ROOM_TYPE', 'This room type is not for sale');
        }
        $adults = $b['adults'] ?? 1;
        if (!is_int($adults) || $adults < 1 || $adults > (int) $type['max_guests'] * $quote['quantity']) {
            throw new ApiException(422, 'INVALID_REQUEST', 'adults must be between 1 and ' . ((int) $type['max_guests'] * $quote['quantity']) . ' for this room');
        }
        $requests = $b['specialRequests'] ?? null;
        if ($requests !== null && (!is_string($requests) || mb_strlen($requests) > 300)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'specialRequests must be text of at most 300 characters');
        }
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
            function () use ($user, $quote, $onBehalf, $b, $now, $adults, $requests, $r): array {
                [$st, $body] = $this->wf->hold($user['accountId'], $quote, $onBehalf, $b['guest'] ?? [], $now, ['adults' => $adults, 'specialRequests' => $requests]);
                if ($st === 201) {
                    Audit::record($this->pdo, $user, 'reservation.hold', 'reservation', $body['reservationId'], ['roomTypeId' => $quote['roomTypeId'], 'checkIn' => $quote['checkIn'], 'checkOut' => $quote['checkOut']], $r->ip());
                    $body['code'] = (string) $this->pdo->query("SELECT code FROM reservation WHERE reservation_id = " . $this->pdo->quote($body['reservationId']))->fetchColumn();
                }
                return [$st, $body];
            }, $now
        ));
        return [$status, $body, $replayed ? ['Idempotent-Replayed' => 'true'] : []];
    }

    /** GET /internal/reservations/{id}. Not yours or not found -> 404. {id} may also be a confirmation code for staff. */
    public function get(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.read');
        return [200, $this->view($this->visible($p['id'], $user), $user)];
    }

    /**
     * GET /internal/reservations: guests see only their own. Filters: q (code, guest name, email), status, checkIn, checkOut (exact dates),
     * from/to (arrival range), guaranteeRequested=1, source.
     */
    public function list(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.list');
        $q = $r->query;
        $where = ['1=1'];
        $args = [];
        if ($user['role'] === 'guest') {
            $where[] = 'g.account_id = ?';
            $args[] = $user['accountId'];
        }
        if (!empty($q['q'])) {
            $like = '%' . str_replace(['%', '_', '['], ['\\%', '\\_', '\\['], trim((string) $q['q'])) . '%';
            $where[] = "(r.code LIKE ? ESCAPE '\\' OR g.full_name LIKE ? ESCAPE '\\' OR g.email LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like, $like);
        }
        if (!empty($q['status'])) {
            $list = array_filter(explode(',', (string) $q['status']), fn ($s) => in_array($s, ['HELD', 'CONFIRMED', 'EXPIRED', 'CANCELLED', 'CHECKED_IN', 'CHECKED_OUT', 'NO_SHOW'], true));
            if ($list !== []) {
                $where[] = 'r.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
                array_push($args, ...$list);
            }
        }
        foreach (['checkIn' => 'r.check_in = ?', 'checkOut' => 'r.check_out = ?', 'from' => 'r.check_in >= ?', 'to' => 'r.check_in <= ?'] as $k => $sql) {
            if (!empty($q[$k]) && Dates::isDate((string) $q[$k])) {
                $where[] = $sql;
                $args[] = $q[$k];
            }
        }
        if (!empty($q['ids'])) {
            $ids = array_slice(array_values(array_filter(explode(',', (string) $q['ids']), fn ($i) => Uuid::isValid($i))), 0, 100);
            $where[] = $ids === [] ? '1=0' : 'r.reservation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
        if (!empty($q['guaranteeRequested'])) {
            $where[] = "r.guarantee_requested = 1 AND r.status = 'HELD' AND r.hold_expires_at > DATETRUNC(SECOND, SYSUTCDATETIME())";
        }
        if (!empty($q['source']) && in_array($q['source'], ['DIRECT', 'WALK_IN', 'OTA'], true)) {
            $where[] = 'r.source = ?';
            $args[] = $q['source'];
        }
        $limit = min(self::LIST_LIMIT, max(1, (int) ($q['limit'] ?? 100)));
        $order = !empty($q['order']) && $q['order'] === 'arrival' ? 'r.check_in, r.created_at' : 'r.created_at DESC';
        $st = $this->pdo->prepare(
            'SELECT r.*, g.account_id AS guest_account_id, g.full_name, g.email, g.phone, t.name AS type_name, t.slug AS type_slug
               FROM reservation r JOIN guest g ON g.guest_id = r.guest_id JOIN room_type t ON t.room_type_id = r.room_type_id
              WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY"
        );
        $st->execute($args);
        return [200, ['reservations' => array_map(fn ($row) => $this->view($row, $user), $st->fetchAll())]];
    }

    public function cancel(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.cancel');
        $row = $this->visible($p['id'], $user);
        $waive = $user['role'] === 'manager' && ($r->json()['waivePenalty'] ?? false) === true;
        $res = $this->wf->cancel($row['reservation_id'], $waive, time());
        Audit::record($this->pdo, $user, 'reservation.cancel', 'reservation', $row['reservation_id'], ['code' => $row['code'], 'penaltyMinor' => $res[1]['penaltyMinor'] ?? 0, 'waived' => $waive], $r->ip());
        return $res;
    }

    public function noShow(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.noshow');
        $row = $this->visible($p['id'], $user);
        return $this->wf->noShow($row['reservation_id'], $user, $user['role'] === 'manager' && ($r->json()['waivePenalty'] ?? false) === true, time());
    }

    public function approveGuarantee(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'reservation.guarantee');
        $row = $this->visible($p['id'], $user);
        return $this->wf->approveGuarantee($row['reservation_id'], time(), $user);
    }

    public function requestGuarantee(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'guarantee.request');
        $row = $this->visible($p['id'], $user);
        return $this->wf->requestGuarantee($row['reservation_id'], $user, time());
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
        try {
            return $this->wf->reserveMaintenance($b['requestId'], $b['roomTypeId'], $b['startDate'], $b['endDate']);
        } catch (\Lib\InsufficientStock $e) {
            throw new ApiException(409, 'INSUFFICIENT_STOCK', 'No spare room of this type on ' . $e->getMessage());
        }
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
            'PaymentCaptured' => function () use ($d, $e): void {
                foreach (['reservationId', 'paymentId', 'amountMinor', 'currency'] as $f) {
                    if (!isset($d[$f])) {
                        throw new ApiException(422, 'INVALID_EVENT', "missing {$f}");
                    }
                }
                $this->wf->onPaymentCaptured($d, is_string($e['occurredAt'] ?? null) ? $e['occurredAt'] : null);
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

    /** Find by UUID or confirmation code. Guests only ever see their own (others get 404). */
    private function visible(string $id, array $user): array
    {
        $row = null;
        if (Uuid::isValid($id)) {
            $row = $this->repo->find($id);
        } elseif ($user['role'] !== 'guest' && preg_match('/^HL[A-Z0-9]{6}$/', strtoupper($id))) {
            $st = $this->pdo->prepare('SELECT reservation_id FROM reservation WHERE code = ?');
            $st->execute([strtoupper($id)]);
            $found = $st->fetchColumn();
            $row = $found ? $this->repo->find((string) $found) : null;
        }
        if ($row === null || ($user['role'] === 'guest' && $row['guest_account_id'] !== $user['accountId'])) {
            throw new ApiException(404, 'NOT_FOUND');
        }
        return $row;
    }

    private function view(array $row, array $user): array
    {
        if (!isset($row['type_name'])) {
            $t = $this->repo->roomType($row['room_type_id']);
            $row['type_name'] = $t['name'] ?? '';
            $row['type_slug'] = $t['slug'] ?? '';
        }
        if (!isset($row['full_name'])) {
            $st = $this->pdo->prepare('SELECT full_name, email, phone FROM guest WHERE guest_id = ?');
            $st->execute([$row['guest_id']]);
            $row += $st->fetch() ?: ['full_name' => '', 'email' => '', 'phone' => ''];
        }
        $role = $user['role'];
        $v = [
            'reservationId' => $row['reservation_id'], 'code' => $row['code'], 'status' => $row['status'], 'guarantee' => $row['guarantee'], 'roomTypeId' => $row['room_type_id'],
            'roomTypeName' => $row['type_name'], 'roomTypeSlug' => $row['type_slug'], 'source' => $row['source'],
            'checkIn' => $row['check_in'], 'checkOut' => $row['check_out'], 'quantity' => (int) $row['quantity'], 'adults' => (int) $row['adults'],
            'amountMinor' => (int) $row['quote_minor'], 'nightlyMinor' => (int) $row['nightly_minor'], 'currency' => $row['currency'],
            'holdExpiresAt' => Dates::sqlToIso($row['hold_expires_at']), 'version' => (int) $row['version'],
            'guaranteeRequested' => (bool) $row['guarantee_requested'], 'penaltyMinor' => (int) $row['penalty_minor'], 'freeCancelHours' => (int) $row['free_cancel_hours'],
            'createdAt' => Dates::sqlToIso($row['created_at']), 'confirmedAt' => Dates::sqlToIso($row['confirmed_at']), 'cancelledAt' => Dates::sqlToIso($row['cancelled_at']),
            'guestName' => $row['full_name'],
        ];
        if (in_array($role, ['guest', 'reception', 'manager', 'service'], true)) {
            $v['specialRequests'] = $row['special_requests'];
        }
        if (in_array($role, ['reception', 'manager', 'service'], true)) {
            $v += ['guestEmail' => $row['email'], 'guestPhone' => $row['phone'], 'guestId' => $row['guest_id']];
        }
        if ($role === 'service') {
            $v['guestAccountId'] = $row['guest_account_id'];
        }
        return $v;
    }

    private function secret(): string
    {
        return (string) getenv('QUOTE_SECRET') ?: throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'QUOTE_SECRET is not configured');
    }
}
