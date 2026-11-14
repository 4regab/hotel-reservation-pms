<?php
declare(strict_types=1);

namespace RoomOps;

use Lib\ApiException;
use Lib\Inbox;
use Lib\Rbac;
use Lib\Request;
use Lib\ServiceAuth;
use Lib\Tx;
use Lib\Uuid;

/** Private REST routes for the Room Operations service. */
final class RoomController
{
    public function __construct(private \PDO $pdo, private StayCoordinator $stays, private MaintenanceCoordinator $maint)
    {
    }

    public function checkIn(Request $r): array
    {
        $u = $this->user($r, 'stay.checkin');
        return $this->stays->checkIn($u, $r->json());
    }

    public function checkOut(Request $r): array
    {
        $u = $this->user($r, 'stay.checkout');
        return $this->stays->checkOut($u, $r->json());
    }

    public function maintenance(Request $r): array
    {
        $u = $this->user($r, 'maintenance.request');
        return $this->maint->request($u, $r->json());
    }

    public function rooms(Request $r): array
    {
        $this->user($r, 'room.read');
        return [200, ['rooms' => $this->pdo->query('SELECT room_id AS roomId, room_type_id AS roomTypeId, room_number AS roomNumber, readiness, version FROM room ORDER BY room_number')->fetchAll()]];
    }

    public function arrivals(Request $r): array
    {
        $this->user($r, 'arrivals.read');
        return [200, ['arrivals' => $this->pdo->query("SELECT reservation_id AS reservationId, room_type_id AS roomTypeId, check_in AS checkIn, check_out AS checkOut, quantity, status FROM arrival WHERE status = 'EXPECTED' ORDER BY check_in LIMIT 200")->fetchAll()]];
    }

    public function cleaningTasks(Request $r): array
    {
        $this->user($r, 'cleaning.read');
        return [200, ['tasks' => $this->pdo->query("SELECT t.task_id AS taskId, t.room_id AS roomId, r.room_number AS roomNumber, t.status, t.assignee FROM cleaning_task t JOIN room r ON r.room_id = t.room_id WHERE t.status <> 'DONE' ORDER BY t.created_at LIMIT 200")->fetchAll()]];
    }

    /** PATCH /internal/rooms/{id}/status: rejects stale versions and invalid transitions. */
    public function setStatus(Request $r, array $p): array
    {
        $u = $this->user($r, 'room.status');
        $b = $r->json();
        $to = $b['status'] ?? null;
        $expected = $b['expectedVersion'] ?? null;
        if (!Uuid::isValid($p['id']) || !is_string($to) || !in_array($to, RoomRules::STATUSES, true) || !is_int($expected)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'status (one of ' . implode(', ', RoomRules::STATUSES) . ') and integer expectedVersion are required');
        }
        return Tx::run($this->pdo, function () use ($p, $to, $expected, $u): array {
            $st = $this->pdo->prepare('SELECT room_id, room_number, readiness, version FROM room WHERE room_id = ? FOR UPDATE');
            $st->execute([$p['id']]);
            $room = $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
            if ((int) $room['version'] !== $expected) {
                throw new ApiException(409, 'STALE_VERSION', 'The room changed; reload and retry', ['currentVersion' => (int) $room['version']]);
            }
            if (!RoomRules::canSetManually($room['readiness'], $to)) {
                throw new ApiException(409, 'INVALID_TRANSITION', "Room cannot go from {$room['readiness']} to {$to}");
            }
            $this->pdo->prepare('UPDATE room SET readiness = ?, version = version + 1 WHERE room_id = ?')->execute([$to, $p['id']]);
            if ($to === 'CLEANING') {
                $this->pdo->prepare("UPDATE cleaning_task SET status = 'IN_PROGRESS', assignee = ? WHERE room_id = ? AND status = 'OPEN'")->execute([$u['accountId'], $p['id']]);
            } elseif ($to === 'READY' && $room['readiness'] === 'CLEANING') {
                $this->pdo->prepare("UPDATE cleaning_task SET status = 'DONE', completed_at = UTC_TIMESTAMP() WHERE room_id = ? AND status <> 'DONE'")->execute([$p['id']]);
            }
            return [200, ['roomId' => $p['id'], 'roomNumber' => $room['room_number'], 'readiness' => $to, 'version' => $expected + 1]];
        });
    }

    /** POST /internal/events: ReservationConfirmed / ReservationCancelled feed the arrivals view. */
    public function events(Request $r): array
    {
        ServiceAuth::authenticate($r);
        $e = $r->json();
        if (!is_string($e['eventId'] ?? null) || !Uuid::isValid($e['eventId']) || !is_string($e['eventType'] ?? null) || !is_array($e['data'] ?? null)) {
            throw new ApiException(422, 'INVALID_EVENT');
        }
        $d = $e['data'];
        $apply = match ($e['eventType']) {
            'ReservationConfirmed' => fn () => $this->pdo->prepare("INSERT INTO arrival (reservation_id, room_type_id, check_in, check_out, quantity, status) VALUES (?, ?, ?, ?, ?, 'EXPECTED') ON DUPLICATE KEY UPDATE status = IF(status = 'ARRIVED', status, 'EXPECTED')")
                ->execute([$d['reservationId'], $d['roomTypeId'], $d['checkIn'], $d['checkOut'], $d['quantity']]),
            'ReservationCancelled' => fn () => $this->pdo->prepare("UPDATE arrival SET status = 'CANCELLED' WHERE reservation_id = ? AND status = 'EXPECTED'")->execute([$d['reservationId']]),
            default                => static function (): void {
            },
        };
        return [200, ['status' => Inbox::once($this->pdo, $e['eventId'], $apply) ? 'applied' : 'duplicate']];
    }

    private function user(Request $r, string $action): array
    {
        $u = ServiceAuth::authenticate($r)['user'];
        Rbac::require($u['role'], $action);
        return $u;
    }
}
