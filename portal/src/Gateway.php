<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Http;
use Lib\Rbac;
use Lib\Request;
use Lib\Router;
use Lib\Routing;
use Lib\ServiceAuth;

/**
 * The public /v1 API. Checks the session, role and CSRF token, then forwards to the private
 * service over the private network with a signed user context. The portal never decides a sale
 * or changes a payment; the owning service does.
 */
final class Gateway
{
    /**
     * [method, public path, service, private path, RBAC action, mode, flags]
     * mode: public (rate limited) | session (login + CSRF on writes) | webhook (provider signature checked by Billing)
     * flags: guest = fill the guest contact from the signed-in account
     */
    private const ROUTES = [
        ['GET',   '/v1/availability',                          'booking', '/internal/availability',                          null,                    'public'],
        ['POST',  '/v1/reservations',                          'booking', '/internal/reservations',                          'reservation.create',    'session', 'guest'],
        ['GET',   '/v1/reservations/{id}',                     'booking', '/internal/reservations/{id}',                     'reservation.read',      'session'],
        ['POST',  '/v1/reservations/{id}/checkout',            'billing', '/internal/reservations/{id}/checkout',            'checkout.start',        'session'],
        ['POST',  '/v1/reservations/{id}/cancel',              'booking', '/internal/reservations/{id}/cancel',              'reservation.cancel',    'session'],
        ['POST',  '/v1/reservations/{id}/approve-guarantee',   'booking', '/internal/reservations/{id}/approve-guarantee',   'reservation.guarantee', 'session'],
        ['POST',  '/v1/webhooks/paymongo',                     'billing', '/internal/webhooks/paymongo',                     null,                    'webhook'],
        ['POST',  '/v1/stays/check-in',                        'roomops', '/internal/stays/check-in',                        'stay.checkin',          'session'],
        ['POST',  '/v1/stays/check-out',                       'roomops', '/internal/stays/check-out',                       'stay.checkout',         'session'],
        ['PATCH', '/v1/rooms/{id}/status',                     'roomops', '/internal/rooms/{id}/status',                     'room.status',           'session'],
        ['GET',   '/v1/rooms',                                 'roomops', '/internal/rooms',                                 'room.read',             'session'],
        ['GET',   '/v1/arrivals',                              'roomops', '/internal/arrivals',                              'arrivals.read',         'session'],
        ['GET',   '/v1/cleaning-tasks',                        'roomops', '/internal/cleaning-tasks',                        'cleaning.read',         'session'],
        ['POST',  '/v1/maintenance-blocks',                    'roomops', '/internal/maintenance-blocks',                    'maintenance.request',   'session'],
        ['GET',   '/v1/folios/{id}',                           'billing', '/internal/folios/{id}',                           'folio.read',            'session'],
        ['POST',  '/v1/folios/{id}/charges',                   'billing', '/internal/folios/{id}/charges',                   'charge.post',           'session'],
        ['POST',  '/v1/refunds',                               'billing', '/internal/refunds',                               'refund.request',        'session'],
        ['POST',  '/v1/refunds/{id}/approve',                  'billing', '/internal/refunds/{id}/approve',                  'refund.approve',        'session'],
        ['POST',  '/v1/mock/paymongo/pay',                     'billing', '/internal/mock/paymongo/pay',                     'checkout.start',        'session'],
    ];

    /** @param \Closure():Auth $auth @param \Closure():RateLimit $limit  (lazy, so the database connects only when a route needs it) */
    public function __construct(private \Closure $auth, private \Closure $limit)
    {
    }

    public function register(Router $router): void
    {
        foreach (self::ROUTES as $route) {
            $router->add($route[0], $route[1], fn (Request $r, array $p) => $this->forward($r, $p, $route));
        }
    }

    private function forward(Request $r, array $p, array $route): array
    {
        [$method, , $service, $target, $action, $mode] = $route;
        $user = null;
        if ($mode === 'public') {
            ($this->limit)()->hit('public:' . $r->ip(), 60, 60);
        } elseif ($mode === 'session') {
            $user = ($this->auth)()->user() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Sign in first');
            Rbac::require($user['role'], (string) $action);
            if ($method !== 'GET') {
                ($this->auth)()->requireCsrf($r);
            }
        }

        $path = (string) preg_replace_callback('#\{(\w+)\}#', fn ($m) => rawurlencode($p[$m[1]]), $target);
        $body = $r->body;
        if (($route[6] ?? '') === 'guest' && $user !== null) {
            $b = $r->json();
            if ($user['role'] === 'guest') {
                unset($b['onBehalfOfGuestId']);   // guests book for themselves only
                $a = ($this->auth)()->account($user['accountId']) ?? throw new ApiException(401, 'UNAUTHENTICATED');
                $b['guest'] = ['fullName' => $a['full_name'], 'email' => $a['email'], 'phone' => $a['phone']];
            }
            $body = json_encode($b);
        }

        $headers = ServiceAuth::headers('portal', $user, $r->header('Idempotency-Key'));
        if ($mode === 'webhook') {
            // Billing verifies the ORIGINAL body and signature, so both are passed through untouched.
            $headers[] = 'Paymongo-Signature: ' . (string) $r->header('Paymongo-Signature');
        }
        $url = Routing::url($service) . $path . ($r->query ? '?' . http_build_query($r->query) : '');
        [$status, $text] = Http::call($method, $url, $headers, $method === 'GET' ? null : $body, 12);
        if ($status === 0) {
            throw new ApiException(503, 'SERVICE_UNAVAILABLE', "{$service} is not reachable right now");
        }
        $data = json_decode($text, true);
        return [$status, is_array($data) ? $data : ['error' => ['code' => 'UPSTREAM_ERROR', 'message' => 'Unexpected answer from ' . $service]]];
    }
}
