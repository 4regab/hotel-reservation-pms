<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
use Lib\Csv;
use Lib\Db;
use Lib\Dates;
use Lib\InsufficientStock;
use Lib\Inventory;
use Lib\Outbox;
use Lib\Rbac;
use Lib\Request;
use Lib\ServiceAuth;
use Lib\Tx;
use Lib\Uuid;

/**
 * OTA allotments and CSV import. A manager-approved allotment moves stock into the ota_remaining counter.
 * Each imported booking moves it from ota_remaining to confirmed, so the same room is never counted twice.
 */
final class OtaService
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 262144;
    public const HEADER = ['external_booking_id', 'check_in', 'check_out', 'room_type', 'guest_name', 'guest_email', 'guest_phone', 'quantity', 'amount_php'];

    public function __construct(private \PDO $pdo, private BookingRepository $repo, private InventoryAllocator $inv)
    {
    }

    public function partners(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'ota.read');
        return [200, ['partners' => Db::call($this->pdo, 'usp_ota_listPartners')->fetchAll()]];
    }

    /** GET /internal/ota/allotments?status= with how much of each is still unused. */
    public function allotments(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'ota.read');
        $status = !empty($r->query['status']) && in_array($r->query['status'], ['PENDING', 'APPROVED', 'REJECTED', 'RETURNED'], true) ? $r->query['status'] : null;
        $st = Db::call($this->pdo, 'usp_ota_listAllotments', ['status' => $status]);
        return [200, ['allotments' => $st->fetchAll()]];
    }

    /** POST /internal/ota/allotments: reception requests, a manager's own request is approved at once. */
    public function request(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'ota.request');
        $b = $r->json();
        $partner = (string) ($b['partnerId'] ?? '');
        $type = (string) ($b['roomTypeId'] ?? '');
        $start = (string) ($b['startDate'] ?? '');
        $end = (string) ($b['endDate'] ?? '');
        $qty = $b['quantity'] ?? null;
        if (!Dates::isDate($start) || !Dates::isDate($end) || $end <= $start || $start < Dates::manilaToday() || count(Dates::nights($start, $end)) > 90
            || !is_int($qty) || $qty < 1 || $qty > 45 || !Uuid::isValid($type) || $this->repo->roomType($type) === null
            || !$this->partnerActive($partner)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'partnerId, roomTypeId, a future date range (max 90 nights) and a quantity of 1 to 45 are required');
        }
        $id = Uuid::v4();
        Db::call($this->pdo, 'usp_ota_insertAllotment', ['allotment_id' => $id, 'partner_id' => $partner, 'room_type_id' => $type, 'start_date' => $start, 'end_date' => $end, 'quantity' => $qty, 'requested_by' => $user['accountId']]);
        Audit::record($this->pdo, $user, 'ota.allotment_requested', 'ota_allotment', $id, ['partner' => $partner, 'quantity' => $qty, 'start' => $start, 'end' => $end], $r->ip());
        if ($user['role'] === 'manager') {
            $this->decide($id, true, $user, $r->ip());
            return [201, ['allotmentId' => $id, 'status' => 'APPROVED']];
        }
        return [201, ['allotmentId' => $id, 'status' => 'PENDING']];
    }

    public function approve(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'ota.approve');
        return $this->decide($this->uuid($p['id']), true, $user, $r->ip());
    }

    public function reject(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'ota.approve');
        return $this->decide($this->uuid($p['id']), false, $user, $r->ip());
    }

    /** Stop selling: unused allotment goes back to direct sales. Already imported bookings stay confirmed. */
    public function returnUnused(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'ota.approve');
        $id = $this->uuid($p['id']);
        $a = $this->allotment($id) ?? throw new ApiException(404, 'NOT_FOUND');
        if ($a['status'] !== 'APPROVED') {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'Only an approved allotment can be returned');
        }
        $nights = $this->nightsOf($id);
        $this->inv->ensureNights($a['room_type_id'], array_keys($nights));
        $freed = Tx::run($this->pdo, function () use ($id, $a, $user, $r): int {
            Db::call($this->pdo, 'usp_ota_lockAllotment', ['allotment_id' => $id]);
            $nights = $this->nightsOf($id);
            $dates = array_keys($nights);
            $rows = $this->inv->lock($a['room_type_id'], $dates);
            $freedTotal = 0;
            foreach ($nights as $d => $n) {
                $left = (int) $n['reserved'] - (int) $n['used'];
                if ($left > 0) {
                    $rows = Inventory::release($rows, [$d], $left, 'ota');
                    $freedTotal += $left;
                }
                Db::call($this->pdo, 'usp_ota_releaseAllotmentNight', ['allotment_id' => $id, 'stay_date' => $d]);
            }
            $this->inv->save($a['room_type_id'], $rows, $dates);
            Db::call($this->pdo, 'usp_ota_returnAllotment', ['allotment_id' => $id, 'decided_by' => $user['accountId']]);
            Audit::record($this->pdo, $user, 'ota.allotment_returned', 'ota_allotment', $id, ['roomNightsFreed' => $freedTotal], $r->ip());
            return $freedTotal;
        });
        return [200, ['allotmentId' => $id, 'status' => 'RETURNED', 'roomNightsFreed' => $freed]];
    }

    public function imports(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'ota.read');
        return [200, ['imports' => Db::call($this->pdo, 'usp_ota_listImports')->fetchAll()]];
    }

    /** POST /internal/ota/import {partnerId, filename, csv}: every row stands alone, so one bad row never blocks the rest. */
    public function import(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'ota.import');
        $b = $r->json();
        $partner = (string) ($b['partnerId'] ?? '');
        $csv = (string) ($b['csv'] ?? '');
        if (!$this->partnerActive($partner)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Unknown partner');
        }
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Upload a CSV file of at most ' . (self::MAX_BYTES / 1024) . ' KB');
        }
        $rows = Csv::parse($csv);
        $header = array_map('strtolower', array_shift($rows) ?? []);
        foreach (['external_booking_id', 'check_in', 'check_out', 'room_type', 'guest_name'] as $need) {
            if (!in_array($need, $header, true)) {
                throw new ApiException(422, 'INVALID_CSV', "Missing column '{$need}'. Expected: " . implode(', ', self::HEADER));
            }
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new ApiException(422, 'INVALID_CSV', 'At most ' . self::MAX_ROWS . ' rows per file');
        }
        $report = [];
        $ok = 0;
        foreach ($rows as $i => $cells) {
            $line = $i + 2;
            $row = [];
            foreach ($header as $k => $name) {
                $row[$name] = $cells[$k] ?? '';
            }
            try {
                $resId = $this->importRow($partner, $row, $user);
                $ok++;
                $report[] = ['line' => $line, 'externalId' => $row['external_booking_id'], 'result' => 'IMPORTED', 'reservationId' => $resId];
            } catch (ApiException $e) {
                $report[] = ['line' => $line, 'externalId' => $row['external_booking_id'] ?? '', 'result' => 'REJECTED', 'reason' => $e->getMessage()];
            }
        }
        $importId = Uuid::v4();
        Db::call($this->pdo, 'usp_ota_insertImport', [
            'import_id' => $importId, 'partner_id' => $partner, 'filename' => mb_substr((string) ($b['filename'] ?? 'upload.csv'), 0, 120), 'rows_total' => count($rows),
            'rows_ok' => $ok, 'rows_rejected' => count($rows) - $ok, 'report' => json_encode($report), 'imported_by' => $user['accountId'],
        ]);
        Audit::record($this->pdo, $user, 'ota.import', 'ota_import', $importId, ['partner' => $partner, 'rows' => count($rows), 'imported' => $ok], $r->ip());
        return [200, ['importId' => $importId, 'rowsTotal' => count($rows), 'rowsOk' => $ok, 'rowsRejected' => count($rows) - $ok, 'report' => $report]];
    }

    /** @return string new reservation id */
    private function importRow(string $partner, array $row, array $user): string
    {
        $ext = $row['external_booking_id'];
        if ($ext === '' || strlen($ext) > 80 || !preg_match('/^[A-Za-z0-9._\-]+$/', $ext)) {
            throw new ApiException(422, 'ROW', 'external_booking_id is missing or has odd characters');
        }
        if (Db::call($this->pdo, 'usp_ota_bookingExists', ['partner_id' => $partner, 'external_booking_id' => $ext])->fetch() !== false) {
            throw new ApiException(409, 'ROW', 'Already imported (duplicate booking id)');
        }
        $ci = $row['check_in'];
        $co = $row['check_out'];
        if (!Dates::isDate($ci) || !Dates::isDate($co) || $co <= $ci) {
            throw new ApiException(422, 'ROW', 'check_in and check_out must be YYYY-MM-DD with check_out after check_in');
        }
        if ($ci < Dates::manilaToday()) {
            throw new ApiException(422, 'ROW', 'check_in is in the past');
        }
        $nights = Dates::nights($ci, $co);
        if (count($nights) > Dates::MAX_NIGHTS) {
            throw new ApiException(422, 'ROW', 'Stay is longer than ' . Dates::MAX_NIGHTS . ' nights');
        }
        $name = $row['guest_name'];
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ApiException(422, 'ROW', 'guest_name is required');
        }
        $email = strtolower($row['guest_email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(422, 'ROW', 'guest_email is not a valid address');
        }
        $qty = ($row['quantity'] ?? '') === '' ? 1 : filter_var($row['quantity'], FILTER_VALIDATE_INT);
        if ($qty === false || $qty < 1 || $qty > 5) {
            throw new ApiException(422, 'ROW', 'quantity must be 1 to 5');
        }
        $type = null;
        foreach ($this->repo->roomTypes() as $t) {
            if (strcasecmp($t['name'], $row['room_type']) === 0 || strcasecmp($t['slug'], $row['room_type']) === 0) {
                $type = $t;
            }
        }
        $type ?? throw new ApiException(422, 'ROW', "Unknown room_type '{$row['room_type']}'");
        $amount = (int) $type['nightly_rate_minor'] * $qty * count($nights);
        if (($row['amount_php'] ?? '') !== '') {
            if (!is_numeric($row['amount_php']) || (float) $row['amount_php'] <= 0 || (float) $row['amount_php'] > 5000000) {
                throw new ApiException(422, 'ROW', 'amount_php must be a positive number');
            }
            $amount = (int) round((float) $row['amount_php'] * 100);
        }
        $this->inv->ensureNights($type['room_type_id'], $nights);

        return Tx::run($this->pdo, function () use ($partner, $ext, $ci, $co, $nights, $name, $email, $row, $qty, $type, $amount): string {
            // Reservation-level uniqueness is also protected by the (partner_id, external_booking_id) primary key below.
            $alloc = Db::call($this->pdo, 'usp_ota_lockAllotmentForImport', [
                'partner_id' => $partner, 'room_type_id' => $type['room_type_id'], 'check_in' => $ci, 'check_out' => $co, 'quantity' => $qty, 'night_count' => count($nights),
            ]);
            $allotmentId = $alloc->fetchColumn();
            if ($allotmentId === false) {
                throw new ApiException(409, 'ROW', 'No reserved allotment left for these dates and room type');
            }
            try {
                $rows = Inventory::move($this->inv->lock($type['room_type_id'], $nights), $nights, $qty, 'ota', 'confirmed');
            } catch (InsufficientStock | \LogicException $e) {
                throw new ApiException(409, 'ROW', 'Allotment counters do not cover these dates');
            }
            $this->inv->save($type['room_type_id'], $rows, $nights);
            Db::call($this->pdo, 'usp_ota_useAllotmentNights', ['allotment_id' => $allotmentId, 'quantity' => $qty, 'check_in' => $ci, 'check_out' => $co]);

            $guestId = null;
            if ($email !== '') {
                $g = Db::call($this->pdo, 'usp_ota_findGuestByEmail', ['email' => $email]);
                $guestId = $g->fetchColumn() ?: null;
            }
            if ($guestId === null) {
                $guestId = Uuid::v4();
                Db::call($this->pdo, 'usp_ota_insertGuest', [
                    'guest_id' => $guestId, 'full_name' => $name, 'email' => $email !== '' ? $email : strtolower($partner . '-' . $ext) . '@partner.invalid', 'phone' => $row['guest_phone'] ?? '',
                ]);
            }
            $id = Uuid::v4();
            Db::call($this->pdo, 'usp_ota_insertReservation', [
                'reservation_id' => $id, 'code' => BookingRepository::newCode(), 'guest_id' => $guestId, 'room_type_id' => $type['room_type_id'], 'check_in' => $ci, 'check_out' => $co,
                'quantity' => $qty, 'quote_minor' => $amount, 'nightly_minor' => intdiv($amount, max(1, $qty * count($nights))), 'adults' => $qty,
            ]);
            $this->repo->insertAllocations($id, $type['room_type_id'], $nights, $qty, 'CONFIRMED');
            Db::call($this->pdo, 'usp_ota_insertBooking', ['partner_id' => $partner, 'external_booking_id' => $ext, 'reservation_id' => $id, 'allotment_ref' => $allotmentId]);
            Outbox::add($this->pdo, 'ReservationConfirmed', $id, 1, $id, [
                'reservationId' => $id, 'roomTypeId' => $type['room_type_id'], 'checkIn' => $ci, 'checkOut' => $co, 'quantity' => $qty, 'amountMinor' => $amount, 'currency' => 'PHP',
            ]);
            return $id;
        });
    }

    private function decide(string $id, bool $approve, array $user, ?string $ip): array
    {
        $a = $this->allotment($id) ?? throw new ApiException(404, 'NOT_FOUND');
        if ($a['status'] !== 'PENDING') {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This request was already decided');
        }
        if (!$approve) {
            Db::call($this->pdo, 'usp_ota_rejectAllotment', ['allotment_id' => $id, 'decided_by' => $user['accountId']]);
            Audit::record($this->pdo, $user, 'ota.allotment_rejected', 'ota_allotment', $id, [], $ip);
            return [200, ['allotmentId' => $id, 'status' => 'REJECTED']];
        }
        $nights = Dates::nights($a['start_date'], $a['end_date']);
        $this->inv->ensureNights($a['room_type_id'], $nights);
        Tx::run($this->pdo, function () use ($a, $id, $nights, $user, $ip): void {
            $lock = Db::call($this->pdo, 'usp_ota_lockAllotment', ['allotment_id' => $id]);
            if ($lock->fetchColumn() !== 'PENDING') {
                throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This request was already decided');
            }
            $rows = Inventory::reserve($this->inv->lock($a['room_type_id'], $nights), $nights, (int) $a['quantity'], 'ota');
            $this->inv->save($a['room_type_id'], $rows, $nights);
            foreach ($nights as $n) {
                Db::call($this->pdo, 'usp_ota_insertAllotmentNight', ['allotment_id' => $id, 'stay_date' => $n, 'reserved' => $a['quantity']]);
            }
            Db::call($this->pdo, 'usp_ota_approveAllotment', ['allotment_id' => $id, 'decided_by' => $user['accountId']]);
            Audit::record($this->pdo, $user, 'ota.allotment_approved', 'ota_allotment', $id, ['quantity' => (int) $a['quantity']], $ip);
        });
        return [200, ['allotmentId' => $id, 'status' => 'APPROVED']];
    }

    /** @return array<string,array{reserved:int,used:int}> keyed by date */
    private function nightsOf(string $id): array
    {
        $st = Db::call($this->pdo, 'usp_ota_listAllotmentNights', ['allotment_id' => $id]);
        $out = [];
        foreach ($st->fetchAll() as $n) {
            $out[$n['stay_date']] = ['reserved' => (int) $n['reserved'], 'used' => (int) $n['used']];
        }
        return $out;
    }

    private function uuid(string $v): string
    {
        return Uuid::isValid($v) ? $v : throw new ApiException(404, 'NOT_FOUND');
    }

    private function allotment(string $id): ?array
    {
        return Db::call($this->pdo, 'usp_ota_getAllotment', ['allotment_id' => $id])->fetch() ?: null;
    }

    private function partnerActive(string $partner): bool
    {
        return Db::call($this->pdo, 'usp_ota_partnerExists', ['partner_id' => $partner])->fetch() !== false;
    }
}
