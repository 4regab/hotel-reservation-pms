<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

use Billing\BillingController;
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
        $ctl = new BillingController($pdo, $provider, new PaymentProcessor($pdo), new FolioService($pdo, $provider));
    }
    return $ctl;
};

$router = new Router();
$router->add('GET', '/healthz', fn () => [200, ['service' => 'billing', 'status' => 'ok']]);
$router->add('POST', '/internal/reservations/{id}/checkout', fn ($r, $p) => $c()->checkout($r, $p));
$router->add('POST', '/internal/webhooks/paymongo', fn ($r) => $c()->webhook($r));
$router->add('POST', '/internal/mock/paymongo/pay', fn ($r) => $c()->mockPay($r));
$router->add('GET', '/internal/folios/{id}', fn ($r, $p) => $c()->folio($r, $p));
$router->add('POST', '/internal/folios/{id}/charges', fn ($r, $p) => $c()->charge($r, $p));
$router->add('POST', '/internal/folios/{id}/settle', fn ($r, $p) => $c()->settle($r, $p));
$router->add('POST', '/internal/refunds', fn ($r) => $c()->requestRefund($r));
$router->add('POST', '/internal/refunds/{id}/approve', fn ($r, $p) => $c()->approveRefund($r, $p));
$router->add('POST', '/internal/events', fn ($r) => $c()->events($r));

App::run($router);
