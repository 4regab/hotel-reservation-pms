<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

use Booking\BookingRepository;
use Booking\BookingWorkflow;
use Booking\InventoryAllocator;
use Booking\ReservationController;
use Lib\App;
use Lib\Db;
use Lib\Router;

// Connect lazily so /healthz works even while the database is starting.
$c = static function (): ReservationController {
    static $ctl = null;
    if ($ctl === null) {
        $pdo = Db::connect('BOOKING');
        $repo = new BookingRepository($pdo);
        $inv = new InventoryAllocator($pdo);
        $ctl = new ReservationController($pdo, $repo, $inv, new BookingWorkflow($pdo, $repo, $inv));
    }
    return $ctl;
};

$router = new Router();
$router->add('GET', '/healthz', fn () => [200, ['service' => 'booking', 'status' => 'ok']]);
$router->add('GET', '/internal/availability', fn ($r) => $c()->availability($r));
$router->add('POST', '/internal/reservations', fn ($r) => $c()->create($r));
$router->add('GET', '/internal/reservations/{id}', fn ($r, $p) => $c()->get($r, $p));
$router->add('POST', '/internal/reservations/{id}/cancel', fn ($r, $p) => $c()->cancel($r, $p));
$router->add('POST', '/internal/reservations/{id}/approve-guarantee', fn ($r, $p) => $c()->approveGuarantee($r, $p));
$router->add('POST', '/internal/maintenance-capacity', fn ($r) => $c()->maintenanceCapacity($r));
$router->add('POST', '/internal/events', fn ($r) => $c()->events($r));

App::run($router);
