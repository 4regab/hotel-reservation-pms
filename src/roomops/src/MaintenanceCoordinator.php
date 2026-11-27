<?php
declare(strict_types=1);

namespace RoomOps;

use Lib\ApiException;
use Lib\Audit;
use Lib\Db;
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
        $room = Db::call($this->pdo, 'usp_roomops_getRoom', ['room_id' => $roomId])->fetch() ?: throw new ApiException(404, 'ROOM_NOT_FOUND');

        // Step 1: save the request (safe to repeat: same requestId finds the same row).
        $block = Tx::run($this->pdo, function () use ($requestId, $roomId, $start, $end, $reason, $user, $ip): array {
            $existing = Db::call($this->pdo, 'usp_roomops_lockBlockByRequest', ['request_id' => $requestId])->fetch();
            if ($existing !== false) {
                return $existing;
            }
            $overlap = $this->overlap($roomId, $start, $end);
            $id = Uuid::v4();
            $status = $overlap ? 'CONFLICT' : 'PENDING';
            Db::call($this->pdo, 'usp_roomops_insertBlock', ['block_id' => $id, 'room_id' => $roomId, 'start_date' => $start, 'end_date' => $end, 'status' => $status, 'reason' => $reason, 'request_id' => $requestId, 'requested_by' => $user['accountId']]);
            Audit::record($this->pdo, $user, 'maintenance.requested', 'room', $roomId, ['blockId' => $id, 'status' => $status, 'start' => $start, 'end' => $end], $ip);
            return ['block_id' => $id, 'status' => $status];
        });
        return $this->proceed($block, $room, $start, $end, $requestId, $user, $ip);
    }

    /** Manager retries a conflicted block (for example after a guest was moved or a booking cancelled). */
    public function retry(array $user, string $blockId, ?string $ip): array
    {
        $b = Db::call($this->pdo, 'usp_roomops_getBlock', ['block_id' => $blockId])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
        if ($b['status'] !== 'CONFLICT') {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'Only a conflicted block can be retried');
        }
        if ($this->overlap($b['room_id'], $b['start_date'], $b['end_date'])) {
            throw new ApiException(409, 'MAINTENANCE_CONFLICT', 'A guest is still assigned to this room in these dates');
        }
        Db::call($this->pdo, 'usp_roomops_setBlockStatus', ['block_id' => $blockId, 'status' => 'PENDING', 'only_from' => 'CONFLICT']);
        Audit::record($this->pdo, $user, 'maintenance.retried', 'room', $b['room_id'], ['blockId' => $blockId], $ip);
        // A fresh request id: the earlier one may be remembered by Booking as a refused request.
        $newReq = Uuid::v4();
        Db::call($this->pdo, 'usp_roomops_setBlockRequestId', ['block_id' => $blockId, 'request_id' => $newReq]);
        return $this->proceed(['block_id' => $blockId, 'status' => 'PENDING'], ['room_id' => $b['room_id'], 'room_type_id' => $b['room_type_id']], $b['start_date'], $b['end_date'], $newReq, $user, $ip);
    }

    public function reject(array $user, string $blockId, string $note, ?string $ip): array
    {
        $note = mb_substr(trim($note), 0, 200);
        $affected = (int) Db::call($this->pdo, 'usp_roomops_rejectBlock', ['block_id' => $blockId, 'decided_by' => $user['accountId'], 'note' => $note !== '' ? $note : null])->fetchColumn();
        if ($affected === 0) {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'Only a pending or conflicted block can be rejected');
        }
        Audit::record($this->pdo, $user, 'maintenance.rejected', 'maintenance_block', $blockId, ['note' => $note], $ip);
        return [200, ['blockId' => $blockId, 'status' => 'REJECTED']];
    }

    public function list(string $status): array
    {
        $filter = in_array($status, ['PENDING', 'APPROVED', 'CONFLICT', 'REJECTED'], true) ? $status : null;
        return [200, ['blocks' => Db::call($this->pdo, 'usp_roomops_listBlocks', ['status' => $filter])->fetchAll()]];
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
            Db::call($this->pdo, 'usp_roomops_setBlockStatus', ['block_id' => $block['block_id'], 'status' => 'CONFLICT']);
            Audit::record($this->pdo, $user, 'maintenance.conflict', 'room', $room['room_id'], ['blockId' => $block['block_id']], $ip);
            throw new ApiException(409, 'MAINTENANCE_CONFLICT', 'No spare capacity for this room type in these dates. Escalate to the manager.', ['blockId' => $block['block_id']]);
        }
        if ($code >= 400) {
            throw new ApiException(502, 'UPSTREAM_ERROR', 'Booking refused the capacity block');
        }

        // Step 3: activate the local block, take the room out of service if the block covers today, queue the event.
        Tx::run($this->pdo, function () use ($block, $requestId, $room, $start, $end, $user, $ip): void {
            Db::call($this->pdo, 'usp_roomops_approveBlock', ['block_id' => $block['block_id'], 'decided_by' => $user['accountId']]);
            $today = Dates::manilaToday();
            if ($start <= $today && $today < $end) {
                Db::call($this->pdo, 'usp_roomops_takeRoomOutOfService', ['room_id' => $room['room_id']]);
            }
            Outbox::add($this->pdo, 'MaintenanceBlockApproved', $block['block_id'], 1, $requestId, ['blockId' => $block['block_id'], 'requestId' => $requestId]);
            Audit::record($this->pdo, $user, 'maintenance.approved', 'room', $room['room_id'], ['blockId' => $block['block_id']], $ip);
        });
        return [201, ['blockId' => $block['block_id'], 'status' => 'APPROVED']];
    }

    private function overlap(string $roomId, string $start, string $end): bool
    {
        return Db::call($this->pdo, 'usp_roomops_hasAssignmentOverlap', ['room_id' => $roomId, 'start_date' => $start, 'end_date' => $end])->fetch() !== false;
    }
}
