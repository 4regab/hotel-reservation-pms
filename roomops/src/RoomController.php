<?php
declare(strict_types=1);

namespace RoomOps;

use Lib\ApiException;
use Lib\Audit;
use Lib\Dates;
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
        return $this->stays->checkIn($u, $r->json(), $r->ip());
    }

    public function checkOut(Request $r): array
    {
        $u = $this->user($r, 'stay.checkout');
        return $this->stays->checkOut($u, $r->json(), $r->ip());
    }

    public function maintenance(Request $r): array
    {
        $u = $this->user($r, 'maintenance.request');
        return $this->maint->request($u, $r->json(), $r->ip());
    }

    public function maintenanceList(Request $r): array
    {
        $this->user($r, 'maintenance.read');
        return $this->maint->list((string) ($r->query['status'] ?? ''));
    }

    public function maintenanceReject(Request $r, array $p): array
    {
        $u = $this->user($r, 'maintenance.decide');
        return $this->maint->reject($u, $this->uuid($p['id']), (string) ($r->json()['note'] ?? ''), $r->ip());
    }

    public function maintenanceRetry(Request $r, array $p): array
    {
        $u = $this->user($r, 'maintenance.decide');
        return $this->maint->retry($u, $this->uuid($p['id']), $r->ip());
    }

    /** GET /internal/rooms. Reception and managers also see which reservation occupies a room. */
    public function rooms(Request $r): array
    {
        $u = $this->user($r, 'room.read');
        $withStay = in_array($u['role'], ['reception', 'manager'], true);
        $sql = 'SELECT r.room_id AS roomId, r.room_type_id AS roomTypeId, r.room_number AS roomNumber, r.floor, r.view_type AS view, r.bed_type AS bedType, r.readiness, r.version, r.notes'
            . ($withStay ? ', (SELECT s.reservation_id FROM room_assignment a JOIN stay s ON s.stay_id = a.stay_id WHERE a.room_id = r.room_id AND a.active = 1 LIMIT 1) AS reservationId' : '')
            . ' FROM room r';
        $args = [];
        if (!empty($r->query['roomTypeId']) && Uuid::isValid((string) $r->query['roomTypeId'])) {
            $sql .= ' WHERE r.room_type_id = ?';
            $args[] = $r->query['roomTypeId'];
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY r.room_number');
        $st->execute($args);
        return [200, ['rooms' => array_map(function ($row) {
            $row['floor'] = (int) $row['floor'];
            $row['version'] = (int) $row['version'];
            return $row;
        }, $st->fetchAll())]];
    }

    public function arrivals(Request $r): array
    {
        $this->user($r, 'arrivals.read');
        return [200, ['arrivals' => $this->pdo->query("SELECT reservation_id AS reservationId, room_type_id AS roomTypeId, check_in AS checkIn, check_out AS checkOut, quantity, status FROM arrival WHERE status = 'EXPECTED' ORDER BY check_in LIMIT 200")->fetchAll()]];
    }

    /** GET /internal/stays?reservationIds=a,b,c : rooms and times for reservations that are or were in house. */
    public function stays(Request $r): array
    {
        $this->user($r, 'stay.read');
        $ids = array_values(array_filter(explode(',', (string) ($r->query['reservationIds'] ?? '')), fn ($i) => Uuid::isValid($i)));
        $ids = array_slice($ids, 0, 100);
        if ($ids === []) {
            return [200, ['stays' => []]];
        }
        $st = $this->pdo->prepare(
            'SELECT s.reservation_id, s.stay_id, s.actual_check_in, s.actual_check_out, s.identity_doc_type, s.override_reason, ro.room_id, ro.room_number
               FROM stay s LEFT JOIN room_assignment a ON a.stay_id = s.stay_id LEFT JOIN room ro ON ro.room_id = a.room_id
              WHERE s.reservation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ro.room_number'
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $k = $row['reservation_id'];
            $out[$k] ??= ['reservationId' => $k, 'stayId' => $row['stay_id'], 'checkedInAt' => Dates::sqlToIso($row['actual_check_in']), 'checkedOutAt' => Dates::sqlToIso($row['actual_check_out']), 'identityDocType' => $row['identity_doc_type'], 'earlyOverride' => $row['override_reason'], 'rooms' => []];
            if ($row['room_id'] !== null) {
                $out[$k]['rooms'][] = ['roomId' => $row['room_id'], 'roomNumber' => $row['room_number']];
            }
        }
        return [200, ['stays' => array_values($out)]];
    }

    /** GET /internal/cleaning-tasks?status=OPEN,IN_PROGRESS,CLEANED (default: not done). */
    public function cleaningTasks(Request $r): array
    {
        $this->user($r, 'cleaning.read');
        $all = ['OPEN', 'IN_PROGRESS', 'CLEANED', 'DONE'];
        $want = array_values(array_intersect(explode(',', (string) ($r->query['status'] ?? 'OPEN,IN_PROGRESS,CLEANED')), $all)) ?: ['OPEN', 'IN_PROGRESS', 'CLEANED'];
        $st = $this->pdo->prepare(
            'SELECT t.task_id AS taskId, t.room_id AS roomId, r.room_number AS roomNumber, r.floor, r.version AS roomVersion, t.status, t.assignee, t.created_at AS createdAt, t.cleaned_at AS cleanedAt, t.completed_at AS completedAt
               FROM cleaning_task t JOIN room r ON r.room_id = t.room_id WHERE t.status IN (' . implode(',', array_fill(0, count($want), '?')) . ') ORDER BY t.created_at LIMIT 200'
        );
        $st->execute($want);
        return [200, ['tasks' => $st->fetchAll()]];
    }

    /** PATCH /internal/rooms/{id}/status: rejects stale versions and invalid transitions. READY is reached only by inspection. */
    public function setStatus(Request $r, array $p): array
    {
        $u = $this->user($r, 'room.status');
        $b = $r->json();
        $to = $b['status'] ?? null;
        $expected = $b['expectedVersion'] ?? null;
        if (!Uuid::isValid($p['id']) || !is_string($to) || !in_array($to, RoomRules::STATUSES, true) || !is_int($expected)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'status (one of ' . implode(', ', RoomRules::STATUSES) . ') and integer expectedVersion are required');
        }
        return Tx::run($this->pdo, function () use ($p, $to, $expected, $u, $r): array {
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
            }
            if ($to === 'OUT_OF_SERVICE' || $room['readiness'] === 'OUT_OF_SERVICE') {
                Audit::record($this->pdo, $u, 'room.status', 'room', $p['id'], ['room' => $room['room_number'], 'from' => $room['readiness'], 'to' => $to], $r->ip());
            }
            return [200, ['roomId' => $p['id'], 'roomNumber' => $room['room_number'], 'readiness' => $to, 'version' => $expected + 1]];
        });
    }

    /** POST /internal/cleaning-tasks/{id}/clean : the cleaner reports the room clean. The room waits for inspection. */
    public function markClean(Request $r, array $p): array
    {
        $u = $this->user($r, 'room.status');
        return Tx::run($this->pdo, function () use ($p, $u): array {
            $t = $this->task($this->uuid($p['id']));
            if ($t['status'] !== 'IN_PROGRESS') {
                throw new ApiException(409, 'INVALID_TRANSITION', 'Start cleaning first (the room must be CLEANING)');
            }
            if ($t['assignee'] !== $u['accountId'] && $u['role'] !== 'manager') {
                throw new ApiException(403, 'FORBIDDEN', 'This task belongs to another cleaner');
            }
            $this->pdo->prepare("UPDATE cleaning_task SET status = 'CLEANED', cleaned_at = UTC_TIMESTAMP() WHERE task_id = ?")->execute([$p['id']]);
            return [200, ['taskId' => $p['id'], 'status' => 'CLEANED']];
        });
    }

    /** POST /internal/cleaning-tasks/{id}/inspect : a second person approves the room. Sets it READY. */
    public function inspect(Request $r, array $p): array
    {
        $u = $this->user($r, 'room.inspect');
        return Tx::run($this->pdo, function () use ($p, $u, $r): array {
            $t = $this->task($this->uuid($p['id']));
            if ($t['status'] !== 'CLEANED') {
                throw new ApiException(409, 'INVALID_TRANSITION', 'The cleaner must mark the room clean before inspection');
            }
            if ($t['assignee'] === $u['accountId'] && $u['role'] !== 'manager') {
                throw new ApiException(403, 'SELF_INSPECTION', 'A room is inspected by someone other than the person who cleaned it');
            }
            $this->pdo->prepare("UPDATE cleaning_task SET status = 'DONE', completed_at = UTC_TIMESTAMP(), inspected_by = ? WHERE task_id = ?")->execute([$u['accountId'], $p['id']]);
            $this->pdo->prepare("UPDATE room SET readiness = 'READY', version = version + 1 WHERE room_id = ? AND readiness = 'CLEANING'")->execute([$t['room_id']]);
            Audit::record($this->pdo, $u, 'room.inspected', 'room', $t['room_id'], ['taskId' => $p['id']], $r->ip());
            return [200, ['taskId' => $p['id'], 'status' => 'DONE', 'roomId' => $t['room_id']]];
        });
    }

    /** POST /internal/arrival-overrides : reception asks for an early arrival. */
    public function overrideRequest(Request $r): array
    {
        $u = $this->user($r, 'override.request');
        $b = $r->json();
        $rid = $this->uuid((string) ($b['reservationId'] ?? ''));
        $reason = trim((string) ($b['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw new ApiException(422, 'INVALID_REQUEST', 'A reason of at most 200 characters is required');
        }
        $this->pdo->prepare("INSERT INTO arrival_override (reservation_id, requested_by, reason, status, created_at) VALUES (?, ?, ?, 'PENDING', UTC_TIMESTAMP())
                             ON DUPLICATE KEY UPDATE reason = IF(status = 'PENDING', VALUES(reason), reason)")->execute([$rid, $u['accountId'], $reason]);
        Audit::record($this->pdo, $u, 'override.requested', 'reservation', $rid, ['reason' => $reason], $r->ip());
        return [201, ['reservationId' => $rid, 'status' => 'PENDING']];
    }

    public function overrideList(Request $r): array
    {
        $this->user($r, 'override.decide');
        $st = $this->pdo->prepare('SELECT reservation_id AS reservationId, reason, status, requested_by AS requestedBy, created_at AS createdAt FROM arrival_override WHERE status = ? ORDER BY created_at LIMIT 100');
        $st->execute([in_array($r->query['status'] ?? '', ['PENDING', 'APPROVED', 'REJECTED'], true) ? $r->query['status'] : 'PENDING']);
        return [200, ['overrides' => $st->fetchAll()]];
    }

    public function overrideDecide(Request $r, array $p, bool $approve): array
    {
        $u = $this->user($r, 'override.decide');
        $rid = $this->uuid($p['id']);
        $st = $this->pdo->prepare("UPDATE arrival_override SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE reservation_id = ? AND status = 'PENDING'");
        $st->execute([$approve ? 'APPROVED' : 'REJECTED', $u['accountId'], $rid]);
        if ($st->rowCount() === 0) {
            throw new ApiException(404, 'NOT_FOUND', 'No pending request for this reservation');
        }
        Audit::record($this->pdo, $u, $approve ? 'override.approved' : 'override.rejected', 'reservation', $rid, [], $r->ip());
        return [200, ['reservationId' => $rid, 'status' => $approve ? 'APPROVED' : 'REJECTED']];
    }

    /** GET /internal/reports/ops : medians that need stay and cleaning timestamps (spec KPIs). */
    public function opsReport(Request $r): array
    {
        $this->user($r, 'reports.read');
        $from = Dates::isDate((string) ($r->query['from'] ?? '')) ? $r->query['from'] : gmdate('Y-m-d', time() - 30 * 86400);
        $to = Dates::isDate((string) ($r->query['to'] ?? '')) ? $r->query['to'] : gmdate('Y-m-d');
        $ready = $this->pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, created_at, completed_at) FROM cleaning_task WHERE status = 'DONE' AND completed_at >= ? AND completed_at < DATE_ADD(?, INTERVAL 1 DAY)");
        $ready->execute([$from, $to]);
        $queue = $this->pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, queue_started_at, actual_check_in) FROM stay WHERE queue_started_at IS NOT NULL AND actual_check_in >= ? AND actual_check_in < DATE_ADD(?, INTERVAL 1 DAY)');
        $queue->execute([$from, $to]);
        $counts = $this->pdo->query('SELECT readiness, COUNT(*) AS n FROM room GROUP BY readiness')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $q = array_map('intval', $queue->fetchAll(\PDO::FETCH_COLUMN));
        $rd = array_map('intval', $ready->fetchAll(\PDO::FETCH_COLUMN));
        return [200, [
            'checkoutToReadyMedianMinutes' => self::median($rd), 'checkoutToReadySamples' => count($rd),
            'queueToKeyMedianMinutes' => ($m = self::median($q)) === null ? null : round($m / 60, 1), 'queueToKeySamples' => count($q),
            'roomsByStatus' => array_map('intval', $counts),
        ]];
    }

    /** @param list<int> $v */
    public static function median(array $v): ?float
    {
        if ($v === []) {
            return null;
        }
        sort($v);
        $n = count($v);
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    public function audit(Request $r): array
    {
        $this->user($r, 'audit.read');
        return [200, ['entries' => Audit::search($this->pdo, $r->query)]];
    }

    public function outbox(Request $r): array
    {
        $this->user($r, 'ops.read');
        return [200, Audit::outboxHealth($this->pdo)];
    }

    /** POST /internal/events: ReservationConfirmed / ReservationCancelled / ReservationNoShow feed the arrivals view. */
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
            'ReservationNoShow'    => fn () => $this->pdo->prepare("UPDATE arrival SET status = 'NO_SHOW' WHERE reservation_id = ? AND status = 'EXPECTED'")->execute([$d['reservationId']]),
            default                => static function (): void {
            },
        };
        return [200, ['status' => Inbox::once($this->pdo, $e['eventId'], $apply) ? 'applied' : 'duplicate']];
    }

    private function task(string $id): array
    {
        $st = $this->pdo->prepare('SELECT task_id, room_id, status, assignee FROM cleaning_task WHERE task_id = ? FOR UPDATE');
        $st->execute([$id]);
        return $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
    }

    private function uuid(string $v): string
    {
        return Uuid::isValid($v) ? $v : throw new ApiException(404, 'NOT_FOUND');
    }

    private function user(Request $r, string $action): array
    {
        $u = ServiceAuth::authenticate($r)['user'];
        Rbac::require($u['role'], $action);
        return $u;
    }
}
