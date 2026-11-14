<?php
declare(strict_types=1);

// With the PHP built-in server (development and CI), let it serve real files under /assets directly.
if (PHP_SAPI === 'cli-server') {
    $p = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($p) && str_starts_with($p, '/assets/') && is_file(__DIR__ . $p)) {
        return false;
    }
}

require dirname(__DIR__, 2) . '/autoload.php';

use Lib\ApiException;
use Lib\App;
use Lib\Db;
use Lib\Rbac;
use Lib\Request;
use Lib\Router;
use Portal\Accounts;
use Portal\Auth;
use Portal\EventConsumer;
use Portal\Gateway;
use Portal\Oversight;
use Portal\Privacy;
use Portal\RateLimit;
use Portal\Web\Ctx;
use Portal\Web\GuestSite;
use Portal\Web\PublicSite;
use Portal\Web\StaffSite;
use Portal\Web\View;

// Connect lazily so /healthz and static pages work while the database starts.
$db = static function (): PDO {
    static $pdo = null;
    return $pdo ??= Db::connect('PORTAL');
};
$limit = static fn (): RateLimit => new RateLimit($db());
$auth = static function () use ($db, $limit): Auth {
    static $a = null;
    return $a ??= new Auth($db(), $limit());
};
$ctx = static function (Request $r) use ($db, $limit, $auth): Ctx {
    $accounts = new Accounts($db(), $limit());
    return new Ctx($r, $auth(), $db(), $accounts, new Privacy($db(), $accounts), new Oversight($db(), $accounts));
};

/** Wrap a page handler: builds the context and turns errors into sign-in redirects or friendly error pages. */
$web = static function (callable $handler) use ($ctx): Closure {
    return function (Request $r, array $p) use ($handler, $ctx): array {
        $c = $ctx($r);
        try {
            return $handler($c, ...array_values($p));
        } catch (ApiException $e) {
            if ($e->status === 401) {
                return View::redirect('/login?next=' . rawurlencode($r->method === 'GET' ? $r->path . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '') : '/account'));
            }
            return match (true) {
                $e->status === 403 => View::error($c, 403, 'Not allowed', Ctx::friendly($e)),
                $e->status === 404 => View::error($c, 404, 'Page not found', 'We could not find what you were looking for.'),
                $e->status === 429 => View::error($c, 429, 'Slow down a little', Ctx::friendly($e)),
                $e->status >= 500 => View::error($c, $e->status, 'Something is not working', Ctx::friendly($e)),
                default => View::error($c, $e->status, 'That did not work', Ctx::friendly($e)),
            };
        }
    };
};

$router = new Router();
$router->add('GET', '/healthz', function () use ($db) {
    $db()->query('SELECT 1');
    return [200, ['service' => 'portal', 'status' => 'ok']];
});

// ---- browser pages
$router->add('GET', '/', $web(PublicSite::home(...)));
$router->add('GET', '/rooms', $web(PublicSite::rooms(...)));
$router->add('GET', '/rooms/{slug}', $web(PublicSite::room(...)));
$router->add('GET', '/search', $web(PublicSite::search(...)));
$router->add('POST', '/book', $web(PublicSite::bookStart(...)));
$router->add('POST', '/book/hold', $web(PublicSite::bookHold(...)));
$router->add('GET', '/book/pay/{id}', $web(PublicSite::pay(...)));
$router->add('POST', '/book/pay/{id}', $web(PublicSite::startPayment(...)));
$router->add('GET', '/book/confirmation/{id}', $web(PublicSite::confirmation(...)));
$router->add('GET', '/book/status/{id}', $web(PublicSite::status(...)));
$router->add('GET', '/mock/paymongo/checkout/{id}', $web(PublicSite::mockCheckout(...)));
$router->add('POST', '/mock/paymongo/checkout/{id}', $web(PublicSite::mockPay(...)));
$router->add('GET', '/login', $web(PublicSite::login(...)));
$router->add('POST', '/login', $web(PublicSite::loginPost(...)));
$router->add('POST', '/logout', $web(PublicSite::logout(...)));
$router->add('GET', '/register', $web(PublicSite::register(...)));
$router->add('POST', '/register', $web(PublicSite::registerPost(...)));
$router->add('GET', '/forgot-password', $web(PublicSite::forgot(...)));
$router->add('POST', '/forgot-password', $web(PublicSite::forgotPost(...)));
$router->add('GET', '/reset-password', $web(PublicSite::reset(...)));
$router->add('POST', '/reset-password', $web(PublicSite::resetPost(...)));

$router->add('GET', '/account', $web(GuestSite::account(...)));
$router->add('GET', '/account/bookings/{id}', $web(GuestSite::booking(...)));
$router->add('POST', '/account/bookings/{id}/cancel', $web(GuestSite::cancel(...)));
$router->add('GET', '/account/bookings/{id}/confirmation', $web(GuestSite::confirmationDoc(...)));
$router->add('GET', '/account/bookings/{id}/receipt', $web(GuestSite::receipt(...)));
$router->add('GET', '/account/profile', $web(GuestSite::profile(...)));
$router->add('POST', '/account/profile', $web(GuestSite::profilePost(...)));
$router->add('POST', '/account/password', $web(GuestSite::passwordPost(...)));
$router->add('GET', '/account/privacy', $web(GuestSite::privacy(...)));
$router->add('POST', '/account/privacy', $web(GuestSite::privacyPost(...)));
$router->add('GET', '/account/privacy/{id}/download', $web(GuestSite::privacyDownload(...)));

if (class_exists(StaffSite::class)) {
    StaffSite::register($router, $web);
}

// ---- session and account API
$router->add('POST', '/v1/login', fn ($r) => $auth()->login($r));
$router->add('POST', '/v1/logout', function ($r) use ($auth) {
    $auth()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED');
    $auth()->requireCsrf($r);
    return $auth()->logout();
});
$router->add('GET', '/v1/me', fn () => [200, ($auth()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Sign in first')) + ['csrfToken' => $auth()->csrf()]]);
$router->add('POST', '/v1/register', function (Request $r) use ($db, $limit) {
    $out = (new Accounts($db(), $limit()))->register($r->json(), $r->ip());
    return [201, $out];
});
$router->add('POST', '/v1/password-reset', function (Request $r) use ($db, $limit) {
    (new Accounts($db(), $limit()))->requestReset((string) ($r->json()['email'] ?? ''), $r->ip());
    return [202, ['status' => 'If an account exists, a reset link was sent.']];
});
$router->add('POST', '/v1/password-reset/confirm', function (Request $r) use ($db, $limit) {
    $b = $r->json();
    (new Accounts($db(), $limit()))->completeReset((string) ($b['token'] ?? ''), (string) ($b['password'] ?? ''), $r->ip());
    return [200, ['status' => 'Password changed.']];
});

// ---- read-only reporting copy kept from event counters (never used to sell rooms)
$router->add('GET', '/v1/reports/summary', function () use ($auth, $db) {
    $u = $auth()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Sign in first');
    Rbac::require($u['role'], 'reports.read');
    $rows = $db()->query('SELECT day, confirmed, cancelled, revenue_minor AS revenueMinor, refunded_minor AS refundedMinor, check_ins AS checkIns, check_outs AS checkOuts, updated_at AS updatedAt FROM report_daily ORDER BY day DESC OFFSET 0 ROWS FETCH NEXT 30 ROWS ONLY')->fetchAll();
    // BIGINT columns come back as strings: the API sends numbers.
    foreach ($rows as &$row) {
        $row['revenueMinor'] = (int) $row['revenueMinor'];
        $row['refundedMinor'] = (int) $row['refundedMinor'];
    }
    unset($row);
    return [200, ['currency' => 'PHP', 'note' => 'Reporting copy only; never used to sell rooms.', 'days' => $rows]];
});

// ---- private event endpoint (other services -> portal)
$router->add('POST', '/internal/events', fn ($r) => (new EventConsumer($db()))->handle($r));

// ---- public API gateway (/v1/...)
(new Gateway($auth, $limit))->register($router);

App::run($router);
