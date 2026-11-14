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
        ['GET', '/v1/room-types', 'booking', '/internal/room-types', null, 'public'],
        ['PATCH', '/v1/room-types/{id}', 'booking', '/internal/room-types/{id}', 'roomtype.update', 'session'],
        ['GET', '/v1/reservations', 'booking', '/internal/reservations', 'reservation.list', 'session'],
        ['POST', '/v1/reservations/{id}/no-show', 'booking', '/internal/reservations/{id}/no-show', 'reservation.noshow', 'session'],
        ['POST', '/v1/reservations/{id}/request-guarantee', 'booking', '/internal/reservations/{id}/request-guarantee', 'guarantee.request', 'session'],
        ['POST', '/v1/reservations/{id}/onsite-payment', 'billing', '/internal/reservations/{id}/onsite-payment', 'payment.onsite', 'session'],
        ['GET', '/v1/guests', 'booking', '/internal/guests', 'guest.read', 'session'],
        ['POST', '/v1/guests', 'booking', '/internal/guests', 'guest.create', 'session'],
        ['GET', '/v1/guests/duplicates', 'booking', '/internal/guests/duplicates', 'guest.merge', 'session'],
        ['POST', '/v1/guests/merge', 'booking', '/internal/guests/merge', 'guest.merge', 'session'],
        ['GET', '/v1/ota/partners', 'booking', '/internal/ota/partners', 'ota.read', 'session'],
        ['GET', '/v1/ota/allotments', 'booking', '/internal/ota/allotments', 'ota.read', 'session'],
        ['POST', '/v1/ota/allotments', 'booking', '/internal/ota/allotments', 'ota.request', 'session'],
        ['POST', '/v1/ota/allotments/{id}/approve', 'booking', '/internal/ota/allotments/{id}/approve', 'ota.approve', 'session'],
        ['POST', '/v1/ota/allotments/{id}/reject', 'booking', '/internal/ota/allotments/{id}/reject', 'ota.approve', 'session'],
        ['POST', '/v1/ota/allotments/{id}/return', 'booking', '/internal/ota/allotments/{id}/return', 'ota.approve', 'session'],
        ['POST', '/v1/ota/import', 'booking', '/internal/ota/import', 'ota.import', 'session'],
        ['GET', '/v1/maintenance-blocks', 'roomops', '/internal/maintenance-blocks', 'maintenance.read', 'session'],
        ['POST', '/v1/maintenance-blocks/{id}/reject', 'roomops', '/internal/maintenance-blocks/{id}/reject', 'maintenance.decide', 'session'],
        ['POST', '/v1/maintenance-blocks/{id}/retry', 'roomops', '/internal/maintenance-blocks/{id}/retry', 'maintenance.decide', 'session'],
        ['POST', '/v1/cleaning-tasks/{id}/clean', 'roomops', '/internal/cleaning-tasks/{id}/clean', 'room.status', 'session'],
        ['POST', '/v1/cleaning-tasks/{id}/inspect', 'roomops', '/internal/cleaning-tasks/{id}/inspect', 'room.inspect', 'session'],
        ['POST', '/v1/arrival-overrides', 'roomops', '/internal/arrival-overrides', 'override.request', 'session'],
        ['GET', '/v1/arrival-overrides', 'roomops', '/internal/arrival-overrides', 'override.decide', 'session'],
        ['POST', '/v1/arrival-overrides/{id}/approve', 'roomops', '/internal/arrival-overrides/{id}/approve', 'override.decide', 'session'],
        ['POST', '/v1/arrival-overrides/{id}/reject', 'roomops', '/internal/arrival-overrides/{id}/reject', 'override.decide', 'session'],
        ['GET', '/v1/payments', 'billing', '/internal/payments', 'payment.read', 'session'],
        ['POST', '/v1/payments/{id}/reconcile', 'billing', '/internal/payments/{id}/reconcile', 'payment.reconcile', 'session'],
        ['GET', '/v1/refunds', 'billing', '/internal/refunds', 'refund.read', 'session'],
        ['POST', '/v1/refunds/{id}/reject', 'billing', '/internal/refunds/{id}/reject', 'refund.approve', 'session'],
        ['GET', '/v1/exports', 'billing', '/internal/exports', 'export.read', 'session'],
        ['POST', '/v1/exports', 'billing', '/internal/exports', 'export.request', 'session'],
        ['POST', '/v1/exports/{id}/approve', 'billing', '/internal/exports/{id}/approve', 'export.approve', 'session'],
        ['POST', '/v1/exports/{id}/reject', 'billing', '/internal/exports/{id}/reject', 'export.approve', 'session'],
        ['GET', '/v1/exports/{id}/download', 'billing', '/internal/exports/{id}/download', 'export.download', 'session'],
        ['GET', '/v1/reports/occupancy', 'booking', '/internal/reports/occupancy', 'reports.read', 'session'],
        ['GET', '/v1/reports/revenue', 'billing', '/internal/reports/revenue', 'reports.read', 'session'],
        ['GET', '/v1/reports/operations', 'roomops', '/internal/reports/ops', 'reports.read', 'session'],
        ['GET',   '/v1/mock/paymongo/sessions/{id}',           'billing', '/internal/mock/paymongo/sessions/{id}',           'checkout.start',        'session'],
        ['POST',  '/v1/mock/paymongo/sessions/{id}/attempts',  'billing', '/internal/mock/paymongo/sessions/{id}/attempts',  'checkout.start',        'session'],
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

        $headers = ServiceAuth::headers('portal', $service, $user, $r->header('Idempotency-Key'));
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
