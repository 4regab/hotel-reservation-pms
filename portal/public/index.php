<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

use Lib\ApiException;
use Lib\App;
use Lib\Db;
use Lib\Rbac;
use Lib\Router;
use Portal\Auth;
use Portal\EventConsumer;
use Portal\Gateway;
use Portal\Pages;
use Portal\RateLimit;

// Connect lazily so /healthz and static demo pages work while the database starts.
$db = static function (): PDO {
    static $pdo = null;
    return $pdo ??= Db::connect('PORTAL');
};
$limit = static fn (): RateLimit => new RateLimit($db());
$auth = static function () use ($db, $limit): Auth {
    static $a = null;
    return $a ??= new Auth($db(), $limit());
};
// Page helper: opens the session only if the browser already has one.
$page = static function (callable $make) use ($auth): array {
    $user = $auth()->user();
    return $make($auth()->csrf(), $user);
};

$router = new Router();
$router->add('GET', '/healthz', fn () => [200, ['service' => 'portal', 'status' => 'ok']]);

// ---- browser pages
$router->add('GET', '/', fn () => $page(fn ($c, $u) => Pages::index($c, $u)));
$router->add('GET', '/login', fn () => $page(fn ($c) => Pages::login($c)));
$router->add('GET', '/staff', fn () => $page(fn ($c, $u) => Pages::staff($c, $u)));
$router->add('GET', '/reservations/{id}', fn ($r, $p) => $page(fn ($c, $u) => Pages::reservation($p['id'], $c, $u)));
$router->add('GET', '/mock/paymongo/checkout/{id}', function ($r, $p) use ($page) {
    if ((getenv('PAYMONGO_MODE') ?: 'mock') !== 'mock') {
        throw new ApiException(404, 'NOT_FOUND');
    }
    return $page(fn ($c, $u) => Pages::mockCheckout($p['id'], $c, $u));
});

// ---- session API
$router->add('POST', '/v1/login', fn ($r) => $auth()->login($r));
$router->add('POST', '/v1/logout', function ($r) use ($auth) {
    $auth()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED');
    $auth()->requireCsrf($r);
    return $auth()->logout();
});
$router->add('GET', '/v1/me', fn () => [200, ($auth()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Sign in first')) + ['csrfToken' => $auth()->csrf()]]);

// ---- local read-only reporting copy
$router->add('GET', '/v1/reports/summary', function () use ($auth, $db) {
    $u = $auth()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Sign in first');
    Rbac::require($u['role'], 'reports.read');
    $rows = $db()->query('SELECT day, confirmed, cancelled, revenue_minor AS revenueMinor, refunded_minor AS refundedMinor, check_ins AS checkIns, check_outs AS checkOuts, updated_at AS updatedAt FROM report_daily ORDER BY day DESC LIMIT 30')->fetchAll();
    return [200, ['currency' => 'PHP', 'note' => 'Reporting copy only; never used to sell rooms.', 'days' => $rows]];
});

// ---- private event endpoint (other services -> portal)
$router->add('POST', '/internal/events', fn ($r) => (new EventConsumer($db()))->handle($r));

// ---- public API gateway (/v1/...)
(new Gateway($auth, $limit))->register($router);

App::run($router);
