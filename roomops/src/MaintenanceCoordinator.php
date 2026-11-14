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
 * Asks Booking to block dated capacity BEFORE activating the local block, reusing the same
 * requestId on retries. A block never silently displaces a confirmed guest: conflicts go to the manager,
 * who can reject the block or retry it after the situation changed.
 */
final class MaintenanceCoordinator
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function request(array $user, array $b, ?string $ip = null): array
    {
        $requestId = $b['requestId'] ?? null;
        $roomId = $b['roomId'] ?? null;
        $start = $b['startDate'] ?? null;
        $end = $b['endDate'] ?? null;
        foreach (['requestId' => $requestId, 'roomId' => $roomId] as $f => $v) {
            if (!is_string($v) || !Uuid::isValid($v)) {
                throw new ApiException(422, 'INVALID_REQUEST', "{$f} must be a UUID");
            }
        }
        $reason = is_string($b['reason'] ?? null) ? trim($b['reason']) : '';
        if (!is_string($start) || !is_string($end) || !Dates::isDate($start) || !Dates::isDate($end) || $end <= $start || $reason === '' || mb_strlen($reason) > 200
            || $start < Dates::manilaToday() || count(Dates::nights($start, $end)) > 120) {
            throw new ApiException(422, 'INVALID_REQUEST', 'startDate (today or later) and endDate (after start, at most 120 nights) and a reason of up to 200 characters are required');
        }
        $room = $this->one('SELECT room_id, room_type_id FROM room WHERE room_id = ?', [$roomId]) ?? throw new ApiException(404, 'ROOM_NOT_FOUND');

        // Step 1: save the request (safe to repeat: same requestId finds the same row).
        $block = Tx::run($this->pdo, function () use ($requestId, $roomId, $start, $end, $reason, $user, $ip): array {
            $existing = $this->one('SELECT block_id, status FROM maintenance_block WITH (UPDLOCK, HOLDLOCK) WHERE request_id = ?', [$requestId]);
            if ($existing !== null) {
                return $existing;
            }
            $overlap = $this->overlap($roomId, $start, $end);
            $id = Uuid::v4();
            $status = $overlap ? 'CONFLICT' : 'PENDING';
            $this->pdo->prepare('INSERT INTO maintenance_block (block_id, room_id, start_date, end_date, status, reason, request_id, requested_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATETRUNC(SECOND, SYSUTCDATETIME()))')
                ->execute([$id, $roomId, $start, $end, $status, $reason, $requestId, $user['accountId']]);
            Audit::record($this->pdo, $user, 'maintenance.requested', 'room', $roomId, ['blockId' => $id, 'status' => $status, 'start' => $start, 'end' => $end], $ip);
            return ['block_id' => $id, 'status' => $status];
        });
        return $this->proceed($block, $room, $start, $end, $requestId, $user, $ip);
    }

    /** Manager retries a conflicted block (for example after a guest was moved or a booking cancelled). */
    public function retry(array $user, string $blockId, ?string $ip): array
    {
        $b = $this->one('SELECT b.*, r.room_type_id FROM maintenance_block b JOIN room r ON r.room_id = b.room_id WHERE b.block_id = ?', [$blockId]) ?? throw new ApiException(404, 'NOT_FOUND');
        if ($b['status'] !== 'CONFLICT') {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'Only a conflicted block can be retried');
        }
        if ($this->overlap($b['room_id'], $b['start_date'], $b['end_date'])) {
            throw new ApiException(409, 'MAINTENANCE_CONFLICT', 'A guest is still assigned to this room in these dates');
        }
        $this->pdo->prepare("UPDATE maintenance_block SET status = 'PENDING' WHERE block_id = ? AND status = 'CONFLICT'")->execute([$blockId]);
        Audit::record($this->pdo, $user, 'maintenance.retried', 'room', $b['room_id'], ['blockId' => $blockId], $ip);
        // A fresh request id: the earlier one may be remembered by Booking as a refused request.
        $newReq = Uuid::v4();
        $this->pdo->prepare('UPDATE maintenance_block SET request_id = ? WHERE block_id = ?')->execute([$newReq, $blockId]);
        return $this->proceed(['block_id' => $blockId, 'status' => 'PENDING'], ['room_id' => $b['room_id'], 'room_type_id' => $b['room_type_id']], $b['start_date'], $b['end_date'], $newReq, $user, $ip);
    }

    public function reject(array $user, string $blockId, string $note, ?string $ip): array
    {
        $note = mb_substr(trim($note), 0, 200);
        $st = $this->pdo->prepare("UPDATE maintenance_block SET status = 'REJECTED', decided_by = ?, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME()), decision_note = ? WHERE block_id = ? AND status IN ('CONFLICT','PENDING')");
        $st->execute([$user['accountId'], $note !== '' ? $note : null, $blockId]);
        if ($st->rowCount() === 0) {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'Only a pending or conflicted block can be rejected');
        }
        Audit::record($this->pdo, $user, 'maintenance.rejected', 'maintenance_block', $blockId, ['note' => $note], $ip);
        return [200, ['blockId' => $blockId, 'status' => 'REJECTED']];
    }

    public function list(string $status): array
    {
        $where = in_array($status, ['PENDING', 'APPROVED', 'CONFLICT', 'REJECTED'], true) ? 'WHERE b.status = ' . $this->pdo->quote($status) : '';
        return [200, ['blocks' => $this->pdo->query(
            "SELECT TOP (100) b.block_id AS blockId, b.room_id AS roomId, r.room_number AS roomNumber, b.start_date AS startDate, b.end_date AS endDate, b.status, b.reason, b.requested_by AS requestedBy, b.created_at AS createdAt, b.decision_note AS decisionNote
               FROM maintenance_block b JOIN room r ON r.room_id = b.room_id {$where} ORDER BY b.created_at DESC"
        )->fetchAll()]];
    }

    private function proceed(array $block, array $room, string $start, string $end, string $requestId, array $user, ?string $ip): array
    {
        if ($block['status'] === 'APPROVED') {
            return [200, ['blockId' => $block['block_id'], 'status' => 'APPROVED']];
        }
        if ($block['status'] === 'REJECTED') {
            throw new ApiException(409, 'MAINTENANCE_REJECTED', 'The manager rejected this block');
        }
        if ($block['status'] === 'CONFLICT') {
            throw new ApiException(409, 'MAINTENANCE_CONFLICT', 'A guest is assigned to this room in these dates. Escalate to the manager.', ['blockId' => $block['block_id']]);
        }

        // Step 2: ask Booking for the dated capacity block (no local locks held during the call).
        [$code] = Peer::call('roomops', 'booking', 'POST', '/internal/maintenance-capacity', null, [
            'requestId' => $requestId, 'roomTypeId' => $room['room_type_id'], 'startDate' => $start, 'endDate' => $end,
        ]);
        if ($code === 409) {
            $this->pdo->prepare("UPDATE maintenance_block SET status = 'CONFLICT' WHERE block_id = ?")->execute([$block['block_id']]);
            Audit::record($this->pdo, $user, 'maintenance.conflict', 'room', $room['room_id'], ['blockId' => $block['block_id']], $ip);
            throw new ApiException(409, 'MAINTENANCE_CONFLICT', 'No spare capacity for this room type in these dates. Escalate to the manager.', ['blockId' => $block['block_id']]);
        }
        if ($code >= 400) {
            throw new ApiException(502, 'UPSTREAM_ERROR', 'Booking refused the capacity block');
        }

        // Step 3: activate the local block, take the room out of service if the block covers today, queue the event.
        Tx::run($this->pdo, function () use ($block, $requestId, $room, $start, $end, $user, $ip): void {
            $this->pdo->prepare("UPDATE maintenance_block SET status = 'APPROVED', decided_by = COALESCE(decided_by, ?), decided_at = COALESCE(decided_at, DATETRUNC(SECOND, SYSUTCDATETIME())) WHERE block_id = ?")->execute([$user['accountId'], $block['block_id']]);
            $today = Dates::manilaToday();
            if ($start <= $today && $today < $end) {
                $this->pdo->prepare("UPDATE room SET readiness = 'OUT_OF_SERVICE', version = version + 1 WHERE room_id = ? AND readiness IN ('READY','DIRTY')")->execute([$room['room_id']]);
            }
            Outbox::add($this->pdo, 'MaintenanceBlockApproved', $block['block_id'], 1, $requestId, ['blockId' => $block['block_id'], 'requestId' => $requestId]);
            Audit::record($this->pdo, $user, 'maintenance.approved', 'room', $room['room_id'], ['blockId' => $block['block_id']], $ip);
        });
        return [201, ['blockId' => $block['block_id'], 'status' => 'APPROVED']];
    }

    private function overlap(string $roomId, string $start, string $end): bool
    {
        return $this->one('SELECT TOP (1) 1 AS x FROM room_assignment WHERE room_id = ? AND active = 1 AND start_date < ? AND end_date > ?', [$roomId, $end, $start]) !== null;
    }

    private function one(string $sql, array $args): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: null;
    }
}
