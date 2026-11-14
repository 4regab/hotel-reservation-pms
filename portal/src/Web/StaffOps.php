<?php
declare(strict_types=1);

namespace Portal\Web;

use Closure;
use Lib\ApiException;
use Lib\Router;
use Lib\Uuid;

/** Housekeeping board, maintenance blocks, the manager's approvals queue, rates and OTA partner allotments. */
final class StaffOps
{
    public static function register(Router $router, Closure $web): void
    {
        $g = fn (string $path, callable $h) => $router->add('GET', $path, $web($h));
        $p = fn (string $path, callable $h) => $router->add('POST', $path, $web($h));

        $g('/staff/housekeeping', self::housekeeping(...));
        $p('/staff/housekeeping/rooms/{id}/status', self::roomStatus(...));
        $p('/staff/housekeeping/tasks/{id}/clean', self::markClean(...));
        $p('/staff/housekeeping/tasks/{id}/inspect', self::inspect(...));
        $g('/staff/maintenance', self::maintenance(...));
        $p('/staff/maintenance', self::maintenancePost(...));

        $g('/staff/approvals', self::approvals(...));
        $p('/staff/approvals/refunds/{id}/{verb}', self::refundDecision(...));
        $p('/staff/approvals/maintenance/{id}/{verb}', self::maintenanceDecision(...));
        $p('/staff/approvals/overrides/{id}/{verb}', self::overrideDecision(...));
        $p('/staff/approvals/exports/{id}/{verb}', self::exportDecision(...));
        $p('/staff/ota/allotments/{id}/{verb}', self::allotmentDecision(...));

        $g('/staff/settings', self::settings(...));
        $p('/staff/settings/room-types/{id}', self::settingsPost(...));

        $g('/staff/ota', self::ota(...));
        $p('/staff/ota/allotments', self::allotmentRequest(...));
        $p('/staff/ota/import', self::otaImport(...));
        $g('/staff/ota/template.csv', self::otaTemplate(...));
    }

    // ---- housekeeping -----------------------------------------------------------------------------------------

    public static function housekeeping(Ctx $c): array
    {
        $c->require('housekeeping', 'manager');
        $rooms = $c->svc('roomops', 'GET', '/internal/rooms')['rooms'];
        $tasks = $c->svc('roomops', 'GET', '/internal/cleaning-tasks')['tasks'];
        $byRoom = [];
        foreach ($tasks as $t) {
            $byRoom[$t['roomId']] = $t;
        }
        $types = [];
        foreach ($c->svcAsPortal('booking', 'GET', '/internal/room-types')['roomTypes'] as $t) {
            $types[$t['roomTypeId']] = $t['name'];
        }
        $floors = [];
        foreach ($rooms as $r) {
            $floors[$r['floor']][] = $r;
        }
        ksort($floors);
        $counts = array_count_values(array_column($rooms, 'readiness'));
        return View::page($c, 'staff/housekeeping', ['tasks' => $tasks, 'floors' => $floors, 'byRoom' => $byRoom, 'types' => $types, 'counts' => $counts, 'me' => $c->user()['accountId']], 'Housekeeping');
    }

    public static function roomStatus(Ctx $c, string $id): array
    {
        $c->require('housekeeping', 'manager');
        return $c->act(function () use ($c, $id): void {
            $c->svc('roomops', 'PATCH', '/internal/rooms/' . $id . '/status', ['status' => $c->post('status'), 'expectedVersion' => (int) $c->post('version')]);
        }, 'Room updated.', '/staff/housekeeping');
    }

    public static function markClean(Ctx $c, string $id): array
    {
        $c->require('housekeeping', 'manager');
        return $c->act(fn () => $c->svc('roomops', 'POST', '/internal/cleaning-tasks/' . $id . '/clean', []), 'Marked clean. It now waits for inspection.', '/staff/housekeeping');
    }

    public static function inspect(Ctx $c, string $id): array
    {
        $c->require('housekeeping', 'manager');
        return $c->act(fn () => $c->svc('roomops', 'POST', '/internal/cleaning-tasks/' . $id . '/inspect', []), 'Inspected. The room is ready for sale.', '/staff/housekeeping');
    }

    // ---- maintenance ------------------------------------------------------------------------------------------

    public static function maintenance(Ctx $c): array
    {
        $c->require('housekeeping', 'maintenance', 'manager', 'reception');
        $blocks = $c->svc('roomops', 'GET', '/internal/maintenance-blocks')['blocks'];
        $rooms = $c->can('maintenance.request') ? $c->svc('roomops', 'GET', '/internal/rooms')['rooms'] : [];
        return View::page($c, 'staff/maintenance', ['blocks' => $blocks, 'rooms' => $rooms, 'requestId' => Uuid::v4()], 'Maintenance');
    }

    public static function maintenancePost(Ctx $c): array
    {
        $c->require('housekeeping', 'maintenance', 'manager');
        try {
            $c->checkCsrf();
            $c->svc('roomops', 'POST', '/internal/maintenance-blocks', ['requestId' => $c->post('requestId'), 'roomId' => $c->post('roomId'), 'startDate' => $c->post('startDate'), 'endDate' => $c->post('endDate'), 'reason' => $c->post('reason')]);
            $c->flash('ok', 'Room blocked. It will not be sold for those dates.');
        } catch (ApiException $e) {
            if ($e->errorCode === 'MAINTENANCE_CONFLICT') {
                $c->flash('ok', 'A guest or a full house is in the way. The request was sent to the manager to decide.');
            } else {
                $c->flash('error', Ctx::friendly($e));
            }
        }
        return View::redirect('/staff/maintenance');
    }

    // ---- approvals --------------------------------------------------------------------------------------------

    public static function approvals(Ctx $c): array
    {
        $c->require('manager');
        $get = function (string $svc, string $path, string $key) use ($c): array {
            try {
                return $c->svc($svc, 'GET', $path)[$key];
            } catch (ApiException) {
                return [];
            }
        };
        $refunds = $get('billing', '/internal/refunds?status=REQUESTED', 'refunds');
        $res = [];
        foreach ($refunds as $r) {
            try {
                $res[$r['reservationId']] = $c->svc('booking', 'GET', '/internal/reservations/' . $r['reservationId']);
            } catch (ApiException) {
            }
        }
        return View::page($c, 'staff/approvals', [
            'refunds' => $refunds, 'refundRes' => $res, 'me' => $c->user()['accountId'],
            'guarantees' => $get('booking', '/internal/reservations?guaranteeRequested=1', 'reservations'),
            'conflicts' => $get('roomops', '/internal/maintenance-blocks?status=CONFLICT', 'blocks'),
            'overrides' => $get('roomops', '/internal/arrival-overrides', 'overrides'),
            'allotments' => $get('booking', '/internal/ota/allotments?status=PENDING', 'allotments'),
            'exports' => array_values(array_filter($get('billing', '/internal/exports', 'exports'), fn ($e) => $e['status'] === 'REQUESTED')),
        ], 'Approvals');
    }

    private static function verb(string $v): string
    {
        return in_array($v, ['approve', 'reject', 'retry', 'return'], true) ? $v : throw new ApiException(404, 'NOT_FOUND');
    }

    public static function refundDecision(Ctx $c, string $id, string $verb): array
    {
        $c->require('manager');
        $verb = self::verb($verb);
        return $c->act(function () use ($c, $id, $verb): void {
            $c->svc('billing', 'POST', '/internal/refunds/' . $id . '/' . $verb, $verb === 'reject' ? ['note' => $c->post('note')] : []);
        }, $verb === 'approve' ? 'Refund approved and sent.' : 'Refund declined.', '/staff/approvals');
    }

    public static function maintenanceDecision(Ctx $c, string $id, string $verb): array
    {
        $c->require('manager');
        $verb = self::verb($verb);
        return $c->act(function () use ($c, $id, $verb): void {
            $c->svc('roomops', 'POST', '/internal/maintenance-blocks/' . $id . '/' . $verb, ['note' => $c->post('note')]);
        }, $verb === 'retry' ? 'Block approved.' : 'Block rejected.', '/staff/approvals');
    }

    public static function overrideDecision(Ctx $c, string $id, string $verb): array
    {
        $c->require('manager');
        $verb = self::verb($verb);
        return $c->act(fn () => $c->svc('roomops', 'POST', '/internal/arrival-overrides/' . $id . '/' . $verb, []), $verb === 'approve' ? 'Early arrival approved.' : 'Early arrival declined.', '/staff/approvals');
    }

    public static function exportDecision(Ctx $c, string $id, string $verb): array
    {
        $c->require('manager');
        $verb = self::verb($verb);
        return $c->act(fn () => $c->svc('billing', 'POST', '/internal/exports/' . $id . '/' . $verb, []), $verb === 'approve' ? 'Export approved. Finance can download it.' : 'Export rejected.', '/staff/approvals');
    }

    public static function allotmentDecision(Ctx $c, string $id, string $verb): array
    {
        $c->require('manager');
        $verb = self::verb($verb);
        return $c->act(fn () => $c->svc('booking', 'POST', '/internal/ota/allotments/' . $id . '/' . $verb, []), match ($verb) {
            'approve' => 'Allotment approved. The rooms are set aside for the partner.', 'reject' => 'Allotment declined.', default => 'Unused rooms returned to direct sales.',
        }, $c->post('back', '/staff/approvals'));
    }

    // ---- rates and room types -------------------------------------------------------------------------------

    public static function settings(Ctx $c): array
    {
        $c->require('manager');
        return View::page($c, 'staff/settings', ['types' => $c->svc('booking', 'GET', '/internal/room-types')['roomTypes']], 'Rates and room types');
    }

    public static function settingsPost(Ctx $c, string $id): array
    {
        $c->require('manager');
        return $c->act(function () use ($c, $id): void {
            $c->svc('booking', 'PATCH', '/internal/room-types/' . $id, [
                'nightlyMinor' => StaffSite::centavos($c->post('rate')), 'tagline' => $c->post('tagline'), 'description' => $c->post('description'), 'active' => $c->postFlag('active'),
            ]);
        }, 'Saved. New searches use the new rate. Existing bookings keep the price they were given.', '/staff/settings');
    }

    // ---- OTA partners --------------------------------------------------------------------------------------

    public static function ota(Ctx $c): array
    {
        $c->require('reception', 'manager');
        return View::page($c, 'staff/ota', [
            'partners' => $c->svc('booking', 'GET', '/internal/ota/partners')['partners'], 'types' => $c->svcAsPortal('booking', 'GET', '/internal/room-types')['roomTypes'],
            'allotments' => $c->svc('booking', 'GET', '/internal/ota/allotments')['allotments'], 'imports' => $c->svc('booking', 'GET', '/internal/ota/imports')['imports'],
        ], 'Travel partners');
    }

    public static function allotmentRequest(Ctx $c): array
    {
        $c->require('reception', 'manager');
        return $c->act(function () use ($c): void {
            $c->svc('booking', 'POST', '/internal/ota/allotments', ['partnerId' => $c->post('partnerId'), 'roomTypeId' => $c->post('roomTypeId'), 'startDate' => $c->post('startDate'), 'endDate' => $c->post('endDate'), 'quantity' => (int) $c->post('quantity')]);
        }, $c->role() === 'manager' ? 'Allotment approved. The rooms are set aside.' : 'Allotment request sent to the manager.', '/staff/ota');
    }

    public static function otaImport(Ctx $c): array
    {
        $c->require('reception', 'manager');
        try {
            $c->checkCsrf();
            $f = $_FILES['csv'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                throw new ApiException(422, 'INVALID_REQUEST', 'Choose a CSV file to upload.');
            }
            if ((int) $f['size'] > 262144) {
                throw new ApiException(422, 'INVALID_REQUEST', 'The file is larger than 256 KB.');
            }
            $res = $c->svc('booking', 'POST', '/internal/ota/import', ['partnerId' => $c->post('partnerId'), 'filename' => (string) $f['name'], 'csv' => (string) file_get_contents((string) $f['tmp_name'])]);
            return View::page($c, 'staff/ota_result', ['res' => $res], 'Import result');
        } catch (ApiException $e) {
            $c->flash('error', Ctx::friendly($e));
            return View::redirect('/staff/ota');
        }
    }

    public static function otaTemplate(Ctx $c): array
    {
        $c->require('reception', 'manager');
        $d = plus_days(today(), 30);
        $csv = 'external_booking_id,check_in,check_out,room_type,guest_name,guest_email,guest_phone,quantity,amount_php' . "\nBB-10001,{$d}," . plus_days($d, 2) . ",Standard,Maria Lourdes Santos,maria@example.test,+63 917 555 0100,1,7600.00\n";
        return View::download($csv, 'partner-import-template.csv', 'text/csv; charset=utf-8');
    }
}
