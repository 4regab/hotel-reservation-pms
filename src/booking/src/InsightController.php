<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
use Lib\Dates;
use Lib\Db;
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
        $totalRooms = (int) Db::call($this->pdo, 'usp_insight_totalRooms')->fetchColumn();

        $sold = Db::call($this->pdo, 'usp_insight_soldByNight', ['from_date' => $from, 'to_date' => $to]);
        $perDay = [];
        $perType = [];
        foreach ($sold->fetchAll() as $s) {
            $perDay[$s['stay_date']]['rooms'] = ($perDay[$s['stay_date']]['rooms'] ?? 0) + (int) $s['rooms'];
            $perDay[$s['stay_date']]['revenue'] = ($perDay[$s['stay_date']]['revenue'] ?? 0) + (int) $s['revenue'];
            $perType[$s['room_type_id']]['rooms'] = ($perType[$s['room_type_id']]['rooms'] ?? 0) + (int) $s['rooms'];
            $perType[$s['room_type_id']]['revenue'] = ($perType[$s['room_type_id']]['revenue'] ?? 0) + (int) $s['revenue'];
        }
        $inv = Db::call($this->pdo, 'usp_insight_stockByNight', ['from_date' => $from, 'to_date' => $to]);
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
        foreach (Db::call($this->pdo, 'usp_insight_listRoomTypes')->fetchAll() as $t) {
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
        $c = Db::call($this->pdo, 'usp_insight_kpiCounts', ['from_date' => $from, 'to_date' => $to])->fetch();
        $oversold = (int) $c['oversold'];
        $confirmed = (int) $c['confirmed'];
        $p = ['n' => $c['paid_n'], 'fast' => $c['paid_fast']];
        $dup = GuestController::groups(Db::call($this->pdo, 'usp_insight_listActiveGuests')->fetchAll());
        $activeGuests = (int) $c['active_guests'];
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
        $st = Db::call($this->pdo, 'usp_insight_bookingCounts', ['from_date' => $from, 'to_date' => $to]);
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
