<?php
declare(strict_types=1);

namespace Lib;

/** Private REST call to another service. Timeouts and unreachable peers surface as 503. */
final class Peer
{
    /**
     * @param ?array{accountId:string,role:string} $user
     * @return array{0:int,1:array} status and decoded JSON body
     */
    public static function call(string $caller, string $service, string $method, string $path, ?array $user = null, ?array $body = null, ?string $idempotencyKey = null): array
    {
        [$status, $text] = Http::call($method, Routing::url($service) . $path, ServiceAuth::headers($caller, $service, $user, $idempotencyKey), $body === null ? null : json_encode($body), 8);
        if ($status === 0 || $status >= 500) {
            throw new ApiException(503, 'SERVICE_UNAVAILABLE', "{$service} is not reachable right now");
        }
        $decoded = json_decode($text, true);
        return [$status, is_array($decoded) ? $decoded : []];
    }

    /** Like call() but turns any non-2xx answer into the same error for the caller. */
    public static function ok(string $caller, string $service, string $method, string $path, ?array $user = null, ?array $body = null, ?string $idempotencyKey = null): array
    {
        [$status, $data] = self::call($caller, $service, $method, $path, $user, $body, $idempotencyKey);
        if ($status === 401) {   // our own service credentials were refused: a deployment problem, never the visitor's sign-in
            throw new ApiException(502, 'UPSTREAM_AUTH_FAILED', "{$service} refused this service's credentials");
        }
        if ($status >= 400) {
            $e = $data['error'] ?? [];
            throw new ApiException($status, (string) ($e['code'] ?? 'UPSTREAM_ERROR'), (string) ($e['message'] ?? ''));
        }
        return $data;
    }
}
