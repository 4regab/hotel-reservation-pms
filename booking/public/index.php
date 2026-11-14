<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

use Booking\BookingRepository;
use Booking\BookingWorkflow;
use Booking\CatalogController;
use Booking\GuestController;
use Booking\InsightController;
use Booking\InventoryAllocator;
use Booking\OtaService;
use Booking\ReservationController;
use Lib\App;
use Lib\Db;
use Lib\Router;

// Connect lazily so /healthz works even while the database is starting.
$wire = static function (): array {
    static $o = null;
    if ($o === null) {
        $pdo = Db::connect('BOOKING');
        $repo = new BookingRepository($pdo);
        $inv = new InventoryAllocator($pdo);
        $o = [
            'res' => new ReservationController($pdo, $repo, $inv, new BookingWorkflow($pdo, $repo, $inv)),
            'catalog' => new CatalogController($pdo, $repo),
            'guest' => new GuestController($pdo),
            'ota' => new OtaService($pdo, $repo, $inv),
            'insight' => new InsightController($pdo),
        ];
    }
    return $o;
};

$router = new Router();
$router->add('GET', '/healthz', function () use ($wire) {
    $wire();   // fails with 503 when the database is down
    return [200, ['service' => 'booking', 'status' => 'ok']];
});
$router->add('GET', '/internal/availability', fn ($r) => $wire()['res']->availability($r));
$router->add('GET', '/internal/room-types', fn ($r) => $wire()['catalog']->list($r));
$router->add('PATCH', '/internal/room-types/{id}', fn ($r, $p) => $wire()['catalog']->update($r, $p));
$router->add('POST', '/internal/reservations', fn ($r) => $wire()['res']->create($r));
$router->add('GET', '/internal/reservations', fn ($r) => $wire()['res']->list($r));
$router->add('GET', '/internal/reservations/{id}', fn ($r, $p) => $wire()['res']->get($r, $p));
$router->add('POST', '/internal/reservations/{id}/cancel', fn ($r, $p) => $wire()['res']->cancel($r, $p));
$router->add('POST', '/internal/reservations/{id}/no-show', fn ($r, $p) => $wire()['res']->noShow($r, $p));
$router->add('POST', '/internal/reservations/{id}/request-guarantee', fn ($r, $p) => $wire()['res']->requestGuarantee($r, $p));
$router->add('POST', '/internal/reservations/{id}/approve-guarantee', fn ($r, $p) => $wire()['res']->approveGuarantee($r, $p));
$router->add('POST', '/internal/maintenance-capacity', fn ($r) => $wire()['res']->maintenanceCapacity($r));
$router->add('POST', '/internal/events', fn ($r) => $wire()['res']->events($r));

$router->add('GET', '/internal/guests', fn ($r) => $wire()['guest']->list($r));
$router->add('POST', '/internal/guests', fn ($r) => $wire()['guest']->create($r));
$router->add('GET', '/internal/guests/duplicates', fn ($r) => $wire()['guest']->duplicates($r));
$router->add('POST', '/internal/guests/merge', fn ($r) => $wire()['guest']->merge($r));
$router->add('GET', '/internal/privacy/guest-data', fn ($r) => $wire()['guest']->privacyExport($r));
$router->add('POST', '/internal/privacy/correct', fn ($r) => $wire()['guest']->privacyCorrect($r));
$router->add('POST', '/internal/privacy/anonymize', fn ($r) => $wire()['guest']->privacyAnonymize($r));

$router->add('GET', '/internal/ota/partners', fn ($r) => $wire()['ota']->partners($r));
$router->add('GET', '/internal/ota/allotments', fn ($r) => $wire()['ota']->allotments($r));
$router->add('POST', '/internal/ota/allotments', fn ($r) => $wire()['ota']->request($r));
$router->add('POST', '/internal/ota/allotments/{id}/approve', fn ($r, $p) => $wire()['ota']->approve($r, $p));
$router->add('POST', '/internal/ota/allotments/{id}/reject', fn ($r, $p) => $wire()['ota']->reject($r, $p));
$router->add('POST', '/internal/ota/allotments/{id}/return', fn ($r, $p) => $wire()['ota']->returnUnused($r, $p));
$router->add('GET', '/internal/ota/imports', fn ($r) => $wire()['ota']->imports($r));
$router->add('POST', '/internal/ota/import', fn ($r) => $wire()['ota']->import($r));

$router->add('GET', '/internal/reports/occupancy', fn ($r) => $wire()['insight']->occupancy($r));
$router->add('GET', '/internal/audit', fn ($r) => $wire()['insight']->audit($r));
$router->add('GET', '/internal/ops/outbox', fn ($r) => $wire()['insight']->outbox($r));

App::run($router);
