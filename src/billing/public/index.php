<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/autoload.php';

use Billing\BillingController;
use Billing\FinanceService;
use Billing\FolioService;
use Billing\PayMongoAdapter;
use Billing\PaymentProcessor;
use Lib\App;
use Lib\Db;
use Lib\Router;

$c = static function (): BillingController {
    static $ctl = null;
    if ($ctl === null) {
        $pdo = Db::connect('BILLING');
        $provider = PayMongoAdapter::fromEnv();
        $ctl = new BillingController($pdo, $provider, new PaymentProcessor($pdo), new FolioService($pdo, $provider), new FinanceService($pdo));
    }
    return $ctl;
};

$router = new Router();
$router->add('GET', '/healthz', function () use ($c) {
    $c();
    return [200, ['service' => 'billing', 'status' => 'ok']];
});
$router->add('POST', '/internal/reservations/{id}/checkout', fn ($r, $p) => $c()->checkout($r, $p));
$router->add('POST', '/internal/webhooks/paymongo', fn ($r) => $c()->webhook($r));
$router->add('GET', '/internal/mock/paymongo/sessions/{id}', fn ($r, $p) => $c()->mockSession($r, $p));
$router->add('POST', '/internal/mock/paymongo/sessions/{id}/attempts', fn ($r, $p) => $c()->mockAttempt($r, $p));
$router->add('GET', '/internal/folios/{id}', fn ($r, $p) => $c()->folio($r, $p));
$router->add('POST', '/internal/folios/{id}/charges', fn ($r, $p) => $c()->charge($r, $p));
$router->add('POST', '/internal/folios/{id}/settle', fn ($r, $p) => $c()->settle($r, $p));
$router->add('POST', '/internal/refunds', fn ($r) => $c()->requestRefund($r));
$router->add('POST', '/internal/refunds/{id}/approve', fn ($r, $p) => $c()->approveRefund($r, $p));
$router->add('GET', '/internal/payments', fn ($r) => $c()->payments($r));
$router->add('POST', '/internal/payments/{id}/reconcile', fn ($r, $p) => $c()->reconcile($r, $p));
$router->add('GET', '/internal/refunds', fn ($r) => $c()->refunds($r));
$router->add('POST', '/internal/refunds/{id}/reject', fn ($r, $p) => $c()->rejectRefund($r, $p));
$router->add('POST', '/internal/reservations/{id}/onsite-payment', fn ($r, $p) => $c()->onsitePayment($r, $p));
$router->add('GET', '/internal/receipts/{id}', fn ($r, $p) => $c()->receipt($r, $p));
$router->add('POST', '/internal/exports', fn ($r) => $c()->exportRequest($r));
$router->add('GET', '/internal/exports', fn ($r) => $c()->exports($r));
$router->add('POST', '/internal/exports/{id}/approve', fn ($r, $p) => $c()->exportDecide($r, $p, true));
$router->add('POST', '/internal/exports/{id}/reject', fn ($r, $p) => $c()->exportDecide($r, $p, false));
$router->add('GET', '/internal/exports/{id}/download', fn ($r, $p) => $c()->exportDownload($r, $p));
$router->add('GET', '/internal/reports/revenue', fn ($r) => $c()->revenue($r));
$router->add('GET', '/internal/audit', fn ($r) => $c()->audit($r));
$router->add('GET', '/internal/ops/outbox', fn ($r) => $c()->outbox($r));
$router->add('POST', '/internal/events', fn ($r) => $c()->events($r));

App::run($router);
