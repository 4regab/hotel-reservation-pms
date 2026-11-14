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
$router->add('GET', '/healthz', function () use ($c) {
    $c();
    return [200, ['service' => 'roomops', 'status' => 'ok']];
});
$router->add('POST', '/internal/stays/check-in', fn ($r) => $c()->checkIn($r));
$router->add('POST', '/internal/stays/check-out', fn ($r) => $c()->checkOut($r));
$router->add('PATCH', '/internal/rooms/{id}/status', fn ($r, $p) => $c()->setStatus($r, $p));
$router->add('GET', '/internal/rooms', fn ($r) => $c()->rooms($r));
$router->add('GET', '/internal/arrivals', fn ($r) => $c()->arrivals($r));
$router->add('GET', '/internal/cleaning-tasks', fn ($r) => $c()->cleaningTasks($r));
$router->add('POST', '/internal/maintenance-blocks', fn ($r) => $c()->maintenance($r));
$router->add('GET', '/internal/maintenance-blocks', fn ($r) => $c()->maintenanceList($r));
$router->add('POST', '/internal/maintenance-blocks/{id}/reject', fn ($r, $p) => $c()->maintenanceReject($r, $p));
$router->add('POST', '/internal/maintenance-blocks/{id}/retry', fn ($r, $p) => $c()->maintenanceRetry($r, $p));
$router->add('GET', '/internal/stays', fn ($r) => $c()->stays($r));
$router->add('POST', '/internal/cleaning-tasks/{id}/clean', fn ($r, $p) => $c()->markClean($r, $p));
$router->add('POST', '/internal/cleaning-tasks/{id}/inspect', fn ($r, $p) => $c()->inspect($r, $p));
$router->add('POST', '/internal/arrival-overrides', fn ($r) => $c()->overrideRequest($r));
$router->add('GET', '/internal/arrival-overrides', fn ($r) => $c()->overrideList($r));
$router->add('GET', '/internal/arrival-overrides/{id}', fn ($r, $p) => $c()->overrideStatus($r, $p));
$router->add('POST', '/internal/arrival-overrides/{id}/approve', fn ($r, $p) => $c()->overrideDecide($r, $p, true));
$router->add('POST', '/internal/arrival-overrides/{id}/reject', fn ($r, $p) => $c()->overrideDecide($r, $p, false));
$router->add('GET', '/internal/reports/ops', fn ($r) => $c()->opsReport($r));
$router->add('GET', '/internal/audit', fn ($r) => $c()->audit($r));
$router->add('GET', '/internal/ops/outbox', fn ($r) => $c()->outbox($r));
$router->add('POST', '/internal/events', fn ($r) => $c()->events($r));

App::run($router);
