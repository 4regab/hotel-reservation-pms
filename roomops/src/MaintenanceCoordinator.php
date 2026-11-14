<?php
declare(strict_types=1);

namespace RoomOps;

use Lib\ApiException;
use Lib\Dates;
use Lib\Outbox;
use Lib\Peer;
use Lib\Tx;
use Lib\Uuid;

/**
 * Asks Booking to block dated capacity BEFORE activating the local block, reusing the same
 * requestId on retries. A block never silently displaces a confirmed guest: conflicts go to the manager.
 */
final class MaintenanceCoordinator
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function request(array $user, array $b): array
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
        if (!is_string($start) || !is_string($end) || !Dates::isDate($start) || !Dates::isDate($end) || $end <= $start || !is_string($b['reason'] ?? null) || $b['reason'] === '') {
            throw new ApiException(422, 'INVALID_REQUEST', 'startDate/endDate (end after start) and reason are required');
        }
        $room = $this->one('SELECT room_id, room_type_id FROM room WHERE room_id = ?', [$roomId]) ?? throw new ApiException(404, 'ROOM_NOT_FOUND');

        // Step 1: save the request (safe to repeat: same requestId finds the same row).
        $block = Tx::run($this->pdo, function () use ($requestId, $roomId, $start, $end, $b, $user): array {
            $existing = $this->one('SELECT block_id, status FROM maintenance_block WHERE request_id = ? FOR UPDATE', [$requestId]);
            if ($existing !== null) {
                return $existing;
            }
            $overlap = $this->one("SELECT 1 AS x FROM room_assignment WHERE room_id = ? AND active = 1 AND start_date < ? AND end_date > ? LIMIT 1", [$roomId, $end, $start]);
            $id = Uuid::v4();
            $this->pdo->prepare('INSERT INTO maintenance_block (block_id, room_id, start_date, end_date, status, reason, request_id, requested_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$id, $roomId, $start, $end, $overlap ? 'CONFLICT' : 'PENDING', $b['reason'], $requestId, $user['accountId']]);
            return ['block_id' => $id, 'status' => $overlap ? 'CONFLICT' : 'PENDING'];
        });
        if ($block['status'] === 'APPROVED') {
            return [200, ['blockId' => $block['block_id'], 'status' => 'APPROVED']];
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
            throw new ApiException(409, 'MAINTENANCE_CONFLICT', 'No spare capacity for this room type in these dates. Escalate to the manager.', ['blockId' => $block['block_id']]);
        }
        if ($code >= 400) {
            throw new ApiException(502, 'UPSTREAM_ERROR', 'Booking refused the capacity block');
        }

        // Step 3: activate the local block and queue the event.
        Tx::run($this->pdo, function () use ($block, $requestId): void {
            $this->pdo->prepare("UPDATE maintenance_block SET status = 'APPROVED' WHERE block_id = ?")->execute([$block['block_id']]);
            Outbox::add($this->pdo, 'MaintenanceBlockApproved', $block['block_id'], 1, $requestId, ['blockId' => $block['block_id'], 'requestId' => $requestId]);
        });
        return [201, ['blockId' => $block['block_id'], 'status' => 'APPROVED']];
    }

    private function one(string $sql, array $args): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: null;
    }
}
