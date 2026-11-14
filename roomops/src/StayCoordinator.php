<?php
declare(strict_types=1);

namespace RoomOps;

use Lib\ApiException;
use Lib\Audit;
use Lib\Dates;
use Lib\Outbox;
use Lib\Peer;
use Lib\Tx;
use Lib\Uuid;

/**
 * Check-in and check-out. Reads Booking and Billing over private REST BEFORE opening the local
 * transaction, then locks physical rooms in a fixed order and saves stay + assignments + outbox together.
 */
final class StayCoordinator
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function checkIn(array $user, array $b, ?string $ip = null): array
    {
        $rid = $this->uuid($b['reservationId'] ?? null, 'reservationId');
        if (($b['identityVerified'] ?? false) !== true) {
            throw new ApiException(422, 'IDENTITY_NOT_VERIFIED', 'Verify the guest identity before check-in');
        }
        $doc = $b['identityDocType'] ?? '';
        if (!in_array($doc, ['PASSPORT', 'DRIVERS_LICENSE', 'NATIONAL_ID', 'OTHER'], true)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'identityDocType must be PASSPORT, DRIVERS_LICENSE, NATIONAL_ID or OTHER (the document number is never stored)');
        }
        $res = Peer::ok('roomops', 'booking', 'GET', "/internal/reservations/{$rid}", $user);
        if ($res['status'] !== 'CONFIRMED') {
            throw new ApiException(409, 'RESERVATION_NOT_CONFIRMED', "Reservation is {$res['status']}");
        }
        $override = $this->arrivalDate($user, $res, $rid, (string) ($b['overrideReason'] ?? ''));
        if (!in_array($res['guarantee'], ['PAY_AT_HOTEL', 'OTA_PREPAID'], true)) {
            $folio = Peer::ok('roomops', 'billing', 'GET', "/internal/folios/{$rid}", $user);
            if ($folio['paidMinor'] - $folio['refundedMinor'] < $res['amountMinor']) {
                throw new ApiException(409, 'PAYMENT_NOT_VERIFIED', 'No verified payment or approved pay-at-hotel guarantee');
            }
        }
        $qty = (int) $res['quantity'];
        $wanted = $b['roomIds'] ?? null;
        if ($wanted !== null) {
            if (!is_array($wanted) || count($wanted) !== $qty || count(array_unique($wanted)) !== $qty) {
                throw new ApiException(422, 'INVALID_REQUEST', "roomIds must list exactly {$qty} different rooms");
            }
            array_map(fn ($x) => $this->uuid($x, 'roomIds'), $wanted);
        }

        $queueStart = $this->queueStart($b['queueStartedAt'] ?? null);

        return Tx::run($this->pdo, function () use ($user, $rid, $res, $qty, $wanted, $doc, $override, $queueStart, $ip): array {
            $ids = $wanted ?? $this->pickReadyRooms($res['roomTypeId'], $qty, $res['checkIn'], $res['checkOut']);
            sort($ids);   // fixed lock order
            $rooms = [];
            foreach ($ids as $roomId) {
                $room = $this->lockRoom($roomId);
                if ($room['room_type_id'] !== $res['roomTypeId']) {
                    throw new ApiException(409, 'ROOM_TYPE_MISMATCH', 'Room is not of the booked type');
                }
                if ($room['readiness'] !== 'READY') {
                    throw new ApiException(409, 'ROOM_NOT_READY', "Room {$room['room_number']} is {$room['readiness']}");
                }
                if ($this->hasOverlap($roomId, $res['checkIn'], $res['checkOut'])) {
                    throw new ApiException(409, 'ASSIGNMENT_OVERLAP', "Room {$room['room_number']} already has an assignment in these dates");
                }
                $rooms[] = $room;
            }
            $stayId = Uuid::v4();
            try {
                $this->pdo->prepare('INSERT INTO stay (stay_id, reservation_id, check_in, check_out, actual_check_in, identity_verified_by, identity_doc_type, queue_started_at, override_by, override_reason) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?)')
                    ->execute([$stayId, $rid, $res['checkIn'], $res['checkOut'], $user['accountId'], $doc, $queueStart, $override === null ? null : $override['by'], $override['reason'] ?? null]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ApiException(409, 'ALREADY_CHECKED_IN', 'This reservation already has a stay');
                }
                throw $e;
            }
            $assign = $this->pdo->prepare('INSERT INTO room_assignment (assignment_id, stay_id, room_id, start_date, end_date, active) VALUES (?, ?, ?, ?, ?, 1)');
            $occupy = $this->pdo->prepare("UPDATE room SET readiness = 'OCCUPIED', version = version + 1 WHERE room_id = ?");
            foreach ($rooms as $room) {
                $assign->execute([Uuid::v4(), $stayId, $room['room_id'], $res['checkIn'], $res['checkOut']]);
                $occupy->execute([$room['room_id']]);
            }
            $this->pdo->prepare("UPDATE arrival SET status = 'ARRIVED' WHERE reservation_id = ?")->execute([$rid]);
            Audit::record($this->pdo, $user, $override === null ? 'stay.check_in' : 'stay.early_check_in', 'reservation', $rid, ['stayId' => $stayId, 'doc' => $doc, 'override' => $override['reason'] ?? null], $ip);
            Outbox::add($this->pdo, 'StayCheckedIn', $stayId, 1, $rid, ['reservationId' => $rid, 'stayId' => $stayId, 'roomIds' => array_column($rooms, 'room_id')]);
            return [201, ['stayId' => $stayId, 'reservationId' => $rid, 'rooms' => array_map(fn ($r) => ['roomId' => $r['room_id'], 'roomNumber' => $r['room_number']], $rooms)]];
        });
    }

    public function checkOut(array $user, array $b, ?string $ip = null): array
    {
        $rid = $this->uuid($b['reservationId'] ?? null, 'reservationId');
        $stay = $this->one('SELECT stay_id, actual_check_out FROM stay WHERE reservation_id = ?', [$rid]) ?? throw new ApiException(404, 'NOT_FOUND', 'No stay for this reservation');
        if ($stay['actual_check_out'] !== null) {
            throw new ApiException(409, 'ALREADY_CHECKED_OUT');
        }
        $folio = Peer::ok('roomops', 'billing', 'GET', "/internal/folios/{$rid}", $user);
        if (($folio['folioOpen'] ?? false) !== true) {
            throw new ApiException(503, 'FOLIO_NOT_READY', 'The guest bill is not open yet; retry in a few seconds');
        }
        if ($folio['balanceMinor'] > 0) {
            if (($b['settleOnSite'] ?? false) !== true) {
                throw new ApiException(409, 'BALANCE_OUTSTANDING', 'Settle the outstanding balance before check-out', ['balanceMinor' => $folio['balanceMinor'], 'currency' => $folio['currency']]);
            }
            Peer::ok('roomops', 'billing', 'POST', "/internal/folios/{$rid}/settle", $user, []);
        }

        return Tx::run($this->pdo, function () use ($rid, $user, $ip): array {
            $s = $this->one('SELECT stay_id, actual_check_out FROM stay WHERE reservation_id = ? FOR UPDATE', [$rid]);
            if ($s === null || $s['actual_check_out'] !== null) {
                throw new ApiException(409, 'ALREADY_CHECKED_OUT');
            }
            $st = $this->pdo->prepare('SELECT room_id FROM room_assignment WHERE stay_id = ? AND active = 1 ORDER BY room_id');
            $st->execute([$s['stay_id']]);
            $roomIds = $st->fetchAll(\PDO::FETCH_COLUMN);
            $this->pdo->prepare('UPDATE stay SET actual_check_out = UTC_TIMESTAMP() WHERE stay_id = ?')->execute([$s['stay_id']]);
            $this->pdo->prepare('UPDATE room_assignment SET active = 0 WHERE stay_id = ?')->execute([$s['stay_id']]);
            $dirty = $this->pdo->prepare("UPDATE room SET readiness = 'DIRTY', version = version + 1 WHERE room_id = ?");
            $task = $this->pdo->prepare("INSERT INTO cleaning_task (task_id, room_id, assignee, status, created_at) VALUES (?, ?, NULL, 'OPEN', UTC_TIMESTAMP())");
            foreach ($roomIds as $roomId) {
                $dirty->execute([$roomId]);
                $task->execute([Uuid::v4(), $roomId]);
            }
            Audit::record($this->pdo, $user, 'stay.check_out', 'reservation', $rid, ['stayId' => $s['stay_id']], $ip);
            Outbox::add($this->pdo, 'StayCheckedOut', $s['stay_id'], 2, $rid, ['reservationId' => $rid, 'stayId' => $s['stay_id'], 'roomIds' => $roomIds]);
            return [200, ['stayId' => $s['stay_id'], 'reservationId' => $rid, 'status' => 'CHECKED_OUT', 'roomsToClean' => count($roomIds)]];
        });
    }

    /**
     * Arrival-date rule. On the arrival date (or later, before check-out) check-in is normal. Before the arrival date a manager
     * must approve: either an approved request exists, or the acting user is a manager who gives a reason.
     * @return ?array{by:string,reason:string} null when no override was needed
     */
    private function arrivalDate(array $user, array $res, string $rid, string $managerReason): ?array
    {
        $today = Dates::manilaToday();
        if ($today >= $res['checkOut']) {
            throw new ApiException(409, 'STAY_ENDED', 'The stay dates have already passed');
        }
        if ($today >= $res['checkIn']) {
            return null;
        }
        $o = $this->one("SELECT reason, decided_by FROM arrival_override WHERE reservation_id = ? AND status = 'APPROVED'", [$rid]);
        if ($o !== null) {
            return ['by' => (string) $o['decided_by'], 'reason' => (string) $o['reason']];
        }
        $managerReason = trim($managerReason);
        if ($user['role'] === 'manager' && $managerReason !== '') {
            return ['by' => $user['accountId'], 'reason' => mb_substr($managerReason, 0, 200)];
        }
        throw new ApiException(409, 'EARLY_ARRIVAL', "Arrival is on {$res['checkIn']}. A manager must approve an earlier check-in.", ['checkIn' => $res['checkIn']]);
    }

    /** Time the guest joined the queue (for the queue-to-key measure). Ignored when missing or implausible. */
    private function queueStart(mixed $iso): ?string
    {
        if (!is_string($iso) || ($t = strtotime($iso)) === false || $t > time() || $t < time() - 6 * 3600) {
            return null;
        }
        return Dates::sqlUtc($t);
    }

    /** @return list<string> */
    private function pickReadyRooms(string $roomTypeId, int $qty, string $checkIn, string $checkOut): array
    {
        $st = $this->pdo->prepare(
            "SELECT r.room_id FROM room r
              WHERE r.room_type_id = ? AND r.readiness = 'READY'
                AND NOT EXISTS (SELECT 1 FROM room_assignment a WHERE a.room_id = r.room_id AND a.active = 1 AND a.start_date < ? AND a.end_date > ?)
              ORDER BY r.room_number LIMIT " . $qty . ' FOR UPDATE'
        );
        $st->execute([$roomTypeId, $checkOut, $checkIn]);
        $ids = $st->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) < $qty) {
            throw new ApiException(409, 'NO_READY_ROOM', 'Not enough ready rooms of the booked type; try again after cleaning');
        }
        return $ids;
    }

    private function lockRoom(string $roomId): array
    {
        return $this->one('SELECT room_id, room_type_id, room_number, readiness FROM room WHERE room_id = ? FOR UPDATE', [$roomId]) ?? throw new ApiException(404, 'ROOM_NOT_FOUND');
    }

    private function hasOverlap(string $roomId, string $start, string $end): bool
    {
        return $this->one('SELECT 1 AS x FROM room_assignment WHERE room_id = ? AND active = 1 AND start_date < ? AND end_date > ? LIMIT 1', [$roomId, $end, $start]) !== null;
    }

    private function one(string $sql, array $args): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: null;
    }

    private function uuid(mixed $v, string $field): string
    {
        if (!is_string($v) || !Uuid::isValid($v)) {
            throw new ApiException(422, 'INVALID_REQUEST', "{$field} must be a UUID");
        }
        return $v;
    }
}
