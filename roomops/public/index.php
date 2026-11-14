<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

use Lib\App;
use Lib\Db;
use Lib\Router;
use RoomOps\MaintenanceCoordinator;
use RoomOps\RoomController;
use RoomOps\StayCoordinator;

$c = static function (): RoomController {
    static $ctl = null;
    if ($ctl === null) {
        $pdo = Db::connect('ROOMOPS');
        $ctl = new RoomController($pdo, new StayCoordinator($pdo), new MaintenanceCoordinator($pdo));
    }
    return $ctl;
};

$router = new Router();
$router->add('GET', '/healthz', fn () => [200, ['service' => 'roomops', 'status' => 'ok']]);
$router->add('POST', '/internal/stays/check-in', fn ($r) => $c()->checkIn($r));
$router->add('POST', '/internal/stays/check-out', fn ($r) => $c()->checkOut($r));
$router->add('PATCH', '/internal/rooms/{id}/status', fn ($r, $p) => $c()->setStatus($r, $p));
$router->add('GET', '/internal/rooms', fn ($r) => $c()->rooms($r));
$router->add('GET', '/internal/arrivals', fn ($r) => $c()->arrivals($r));
$router->add('GET', '/internal/cleaning-tasks', fn ($r) => $c()->cleaningTasks($r));
$router->add('POST', '/internal/maintenance-blocks', fn ($r) => $c()->maintenance($r));
$router->add('POST', '/internal/events', fn ($r) => $c()->events($r));

App::run($router);
