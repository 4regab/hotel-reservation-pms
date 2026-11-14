<?php
declare(strict_types=1);

namespace Portal\Web;

use Closure;
use Lib\ApiException;
use Lib\Dates;
use Lib\Http;
use Lib\Router;
use Lib\Routing;

/** Cashier and finance pages (payments, reconciliation, refunds, accounting exports) and the reports dashboard. */
final class StaffFinance
{
    public static function register(Router $router, Closure $web): void
    {
        $g = fn (string $path, callable $h) => $router->add('GET', $path, $web($h));
        $p = fn (string $path, callable $h) => $router->add('POST', $path, $web($h));
        $g('/staff/finance', self::finance(...));
        $p('/staff/finance/reconcile/{id}', self::reconcile(...));
        $p('/staff/finance/reconcile-batch', self::reconcileBatch(...));
        $p('/staff/finance/refunds', self::refundRequest(...));
        $p('/staff/finance/exports', self::exportRequest(...));
        $g('/staff/finance/exports/{id}/download', self::exportDownload(...));
        $g('/staff/reports', self::reports(...));
    }

    public static function finance(Ctx $c): array
    {
        $c->require('cashier', 'manager');
        $tab = in_array($c->q('tab'), ['payments', 'refunds', 'exports'], true) ? $c->q('tab') : 'payments';
        $to = Dates::isDate($c->q('to')) ? $c->q('to') : today();
        $from = Dates::isDate($c->q('from')) ? $c->q('from') : plus_days($to, -13);
        $vars = ['tab' => $tab, 'from' => $from, 'to' => $to, 'me' => $c->user()['accountId']];
        if ($tab === 'payments') {
            $filter = $c->q('show') === 'open' ? '&reconciled=0' : '';
            $pay = $c->svc('billing', 'GET', '/internal/payments?from=' . $from . '&to=' . $to . $filter)['payments'];
            $codes = [];
            $ids = array_values(array_unique(array_column($pay, 'reservationId')));
            foreach (array_chunk($ids, 80) as $chunk) {
                foreach ($c->svc('booking', 'GET', '/internal/reservations?limit=200&ids=' . implode(',', $chunk))['reservations'] as $r) {
                    $codes[$r['reservationId']] = [$r['code'], $r['guestName']];
                }
            }
            $vars += ['payments' => $pay, 'codes' => $codes, 'show' => $c->q('show')];
        } elseif ($tab === 'refunds') {
            $vars += ['refunds' => $c->svc('billing', 'GET', '/internal/refunds')['refunds']];
        } else {
            $vars += ['exports' => $c->svc('billing', 'GET', '/internal/exports')['exports']];
        }
        return View::page($c, 'staff/finance', $vars, 'Payments and finance');
    }

    public static function reconcile(Ctx $c, string $id): array
    {
        $c->require('cashier', 'manager');
        return $c->act(fn () => $c->svc('billing', 'POST', '/internal/payments/' . $id . '/reconcile', []), 'Payment matched.', $c->post('back', '/staff/finance'));
    }

    public static function reconcileBatch(Ctx $c): array
    {
        $c->require('cashier', 'manager');
        return $c->act(function () use ($c): void {
            $r = $c->svc('billing', 'POST', '/internal/payments/batch/reconcile', ['date' => $c->post('date')]);
            $c->flash('ok', $r['reconciled'] . ' payments matched.');
        }, '', '/staff/finance');
    }

    public static function refundRequest(Ctx $c): array
    {
        $c->require('cashier', 'manager');
        return $c->act(function () use ($c): void {
            $c->svc('billing', 'POST', '/internal/refunds', ['paymentId' => $c->post('paymentId'), 'amountMinor' => StaffSite::centavos($c->post('amount')), 'reason' => $c->post('reason', 'MANUAL')]);
        }, 'Refund requested. A manager other than you must approve it.', '/staff/finance?tab=refunds', '/staff/finance');
    }

    public static function exportRequest(Ctx $c): array
    {
        $c->require('cashier', 'manager');
        return $c->act(fn () => $c->svc('billing', 'POST', '/internal/exports', ['from' => $c->post('from'), 'to' => $c->post('to')]), 'Export prepared. A manager must approve it before it can be downloaded.', '/staff/finance?tab=exports');
    }

    public static function exportDownload(Ctx $c, string $id): array
    {
        $c->require('cashier', 'manager');
        $d = $c->svc('billing', 'GET', '/internal/exports/' . $id . '/download');
        return View::download($d['csv'], $d['filename'], 'text/csv; charset=utf-8');
    }

    // ---- reports ----------------------------------------------------------------------------------------------

    public static function reports(Ctx $c): array
    {
        $c->require('manager', 'it', 'cashier');
        $today = today();
        $from = Dates::isDate($c->q('from')) ? $c->q('from') : plus_days($today, -29);
        $to = Dates::isDate($c->q('to')) ? $c->q('to') : plus_days($today, 14);
        $err = [];
        $call = function (string $svc, string $path) use ($c, &$err): ?array {
            try {
                return $c->svc($svc, 'GET', $path);
            } catch (ApiException $e) {
                $err[] = $svc;
                return null;
            }
        };
        $q = '?from=' . $from . '&to=' . $to;
        $occ = $call('booking', '/internal/reports/occupancy' . $q);
        $past = min($to, $today);
        $rev = $call('billing', '/internal/reports/revenue?from=' . $from . '&to=' . $past);
        $ops = $call('roomops', '/internal/reports/ops?from=' . $from . '&to=' . $past);
        $health = [];
        foreach (['booking', 'roomops', 'billing'] as $svc) {
            [$code] = Http::call('GET', Routing::url($svc) . '/healthz', [], null, 3);
            $health[$svc] = $code === 200;
        }
        return View::page($c, 'staff/reports', ['occ' => $occ, 'rev' => $rev, 'ops' => $ops, 'health' => $health, 'from' => $from, 'to' => $to, 'today' => $today, 'unavailable' => $err], 'Reports');
    }
}
