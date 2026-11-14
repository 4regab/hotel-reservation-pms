<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
use Lib\Dates;
use Lib\Rbac;
use Lib\Request;
use Lib\ServiceAuth;

/** Read-only views for managers and IT: occupancy and KPI report, audit trail, delivery health. */
final class InsightController
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * GET /internal/reports/occupancy?from=&to= (default: 30 days back to 14 days ahead, at most 190 days).
     * Revenue per night is the saved nightly price of every confirmed, in-house or finished room-night (no-shows and cancellations excluded).
     */
    public function occupancy(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'reports.read');
        $today = Dates::manilaToday();
        $from = $this->date($r->query['from'] ?? null) ?? (new \DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
        $to = $this->date($r->query['to'] ?? null) ?? (new \DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
        if ($to < $from || count(Dates::nights($from, (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'))) > 190) {
            throw new ApiException(422, 'INVALID_REQUEST', 'from/to must be dates, to not before from, at most 190 days');
        }
        $totalRooms = (int) $this->pdo->query('SELECT COALESCE(SUM(capacity), 0) FROM room_type')->fetchColumn();

        $sold = $this->pdo->prepare(
            "SELECT a.stay_date, a.room_type_id, SUM(a.quantity) AS rooms, SUM(a.quantity * r.nightly_minor) AS revenue
               FROM allocation a JOIN reservation r ON r.reservation_id = a.reservation_id
              WHERE a.state = 'CONFIRMED' AND r.status IN ('CONFIRMED','CHECKED_IN','CHECKED_OUT') AND a.stay_date BETWEEN ? AND ?
              GROUP BY a.stay_date, a.room_type_id"
        );
        $sold->execute([$from, $to]);
        $perDay = [];
        $perType = [];
        foreach ($sold->fetchAll() as $s) {
            $perDay[$s['stay_date']]['rooms'] = ($perDay[$s['stay_date']]['rooms'] ?? 0) + (int) $s['rooms'];
            $perDay[$s['stay_date']]['revenue'] = ($perDay[$s['stay_date']]['revenue'] ?? 0) + (int) $s['revenue'];
            $perType[$s['room_type_id']]['rooms'] = ($perType[$s['room_type_id']]['rooms'] ?? 0) + (int) $s['rooms'];
            $perType[$s['room_type_id']]['revenue'] = ($perType[$s['room_type_id']]['revenue'] ?? 0) + (int) $s['revenue'];
        }
        $inv = $this->pdo->prepare('SELECT stay_date, SUM(blocked) AS blocked, SUM(held) AS held, SUM(ota_remaining) AS ota FROM inventory_night WHERE stay_date BETWEEN ? AND ? GROUP BY stay_date');
        $inv->execute([$from, $to]);
        $invRows = array_column($inv->fetchAll(), null, 'stay_date');

        $days = [];
        $sumRooms = $sumRev = $sumAvail = 0;
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $blocked = (int) ($invRows[$k]['blocked'] ?? 0);
            $avail = max(1, $totalRooms - $blocked);
            $rooms = $perDay[$k]['rooms'] ?? 0;
            $rev = $perDay[$k]['revenue'] ?? 0;
            $days[] = [
                'date' => $k, 'roomsSold' => $rooms, 'roomsAvailable' => $avail, 'blocked' => $blocked, 'held' => (int) ($invRows[$k]['held'] ?? 0), 'otaReserved' => (int) ($invRows[$k]['ota'] ?? 0),
                'occupancy' => round($rooms / $avail, 4), 'revenueMinor' => $rev, 'adrMinor' => $rooms > 0 ? intdiv($rev, $rooms) : 0, 'revparMinor' => intdiv($rev, $avail),
            ];
            $sumRooms += $rooms;
            $sumRev += $rev;
            $sumAvail += $avail;
        }
        $types = [];
        foreach ($this->pdo->query('SELECT room_type_id, name FROM room_type ORDER BY nightly_rate_minor')->fetchAll() as $t) {
            $types[] = ['roomTypeId' => $t['room_type_id'], 'name' => $t['name'], 'roomNights' => $perType[$t['room_type_id']]['rooms'] ?? 0, 'revenueMinor' => $perType[$t['room_type_id']]['revenue'] ?? 0];
        }
        return [200, [
            'from' => $from, 'to' => $to, 'today' => $today, 'currency' => 'PHP', 'days' => $days, 'byRoomType' => $types,
            'totals' => ['roomNights' => $sumRooms, 'roomNightsAvailable' => $sumAvail, 'revenueMinor' => $sumRev, 'occupancy' => $sumAvail ? round($sumRooms / $sumAvail, 4) : 0,
                'adrMinor' => $sumRooms ? intdiv($sumRev, $sumRooms) : 0, 'revparMinor' => $sumAvail ? intdiv($sumRev, $sumAvail) : 0],
            'kpis' => $this->kpis($from, $to),
            'bookings' => $this->bookingCounts($from, $to),
        ]];
    }

    /** Spec section 4.5 measures that this service can calculate. */
    private function kpis(string $from, string $to): array
    {
        $oversold = (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_night WHERE blocked + held + confirmed + ota_remaining > capacity')->fetchColumn();
        $confirmed = (int) $this->pdo->query("SELECT COUNT(*) FROM reservation WHERE status IN ('CONFIRMED','CHECKED_IN','CHECKED_OUT')")->fetchColumn();
        $paid = $this->pdo->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN DATEDIFF_BIG(SECOND, paid_at, confirmed_at) <= 120 THEN 1 ELSE 0 END) AS fast FROM reservation
                                      WHERE source = 'DIRECT' AND guarantee = 'PAID' AND paid_at IS NOT NULL AND confirmed_at IS NOT NULL AND created_at >= ? AND created_at < DATEADD(DAY, 1, CAST(? AS DATE))");
        $paid->execute([$from, $to]);
        $p = $paid->fetch();
        $dup = GuestController::groups($this->pdo->query("SELECT g.guest_id, g.full_name, g.email, g.phone, CASE WHEN g.account_id IS NOT NULL THEN 1 ELSE 0 END AS has_account, g.created_at, 0 AS bookings FROM guest g WHERE g.merged_into IS NULL AND g.anonymized_at IS NULL")->fetchAll());
        $activeGuests = (int) $this->pdo->query('SELECT COUNT(*) FROM guest WHERE merged_into IS NULL AND anonymized_at IS NULL')->fetchColumn();
        $dupGuests = 0;
        foreach ($dup as $g) {
            $dupGuests += count($g['guests']) - 1;
        }
        return [
            'oversoldNights' => $oversold, 'confirmedBookings' => $confirmed, 'oversoldRate' => $confirmed ? round($oversold / $confirmed, 4) : 0.0,
            'paidDirectBookings' => (int) $p['n'], 'paidDirectWithin2Min' => (int) $p['fast'], 'paidDirectWithin2MinRate' => (int) $p['n'] ? round((int) $p['fast'] / (int) $p['n'], 4) : null,
            'activeGuests' => $activeGuests, 'duplicateGuestRecords' => $dupGuests, 'duplicateRate' => $activeGuests ? round($dupGuests / $activeGuests, 4) : 0.0,
        ];
    }

    private function bookingCounts(string $from, string $to): array
    {
        $st = $this->pdo->prepare(
            "SELECT status, source, COUNT(*) AS n FROM reservation WHERE created_at >= ? AND created_at < DATEADD(DAY, 1, CAST(? AS DATE)) GROUP BY status, source"
        );
        $st->execute([$from, $to]);
        $byStatus = [];
        $bySource = [];
        foreach ($st->fetchAll() as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + (int) $row['n'];
            if (in_array($row['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)) {
                $bySource[$row['source']] = ($bySource[$row['source']] ?? 0) + (int) $row['n'];
            }
        }
        return ['byStatus' => $byStatus, 'bySource' => $bySource];
    }

    public function audit(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'audit.read');
        return [200, ['entries' => Audit::search($this->pdo, $r->query)]];
    }

    public function outbox(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'ops.read');
        return [200, Audit::outboxHealth($this->pdo)];
    }

    private function date(mixed $v): ?string
    {
        return is_string($v) && Dates::isDate($v) ? $v : null;
    }
}
