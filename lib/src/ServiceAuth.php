<?php
declare(strict_types=1);

namespace Lib;

/**
 * Service-to-service authentication. Every private request carries a bearer credential that belongs to one caller/callee
 * pair (env TOKEN_<CALLER>_TO_<CALLEE>, for example TOKEN_PORTAL_TO_BOOKING) and, when a person is acting, a user context
 * signed with the same pair secret. A service holds only the pair secrets it sends or receives, so a leak in one container
 * cannot be used to pose as another service. The receiving service names itself in env SERVICE_NAME.
 */
final class ServiceAuth
{
    public static function sign(string $payloadB64, string $secret): string
    {
        return hash_hmac('sha256', $payloadB64, $secret);
    }

    /** @param ?array{accountId:string,role:string} $user @return list<string> */
    public static function headers(string $caller, string $callee, ?array $user = null, ?string $idempotencyKey = null): array
    {
        $secret = (string) getenv('TOKEN_' . strtoupper($caller) . '_TO_' . strtoupper($callee));
        $h = ['Authorization: Bearer ' . $secret, 'X-Service-Caller: ' . $caller, 'Content-Type: application/json'];
        if ($user !== null) {
            $b64 = base64_encode(json_encode(['accountId' => $user['accountId'], 'role' => $user['role']]));
            $h[] = 'X-User-Context: ' . $b64;
            $h[] = 'X-User-Signature: ' . self::sign($b64, $secret);
        }
        if ($idempotencyKey !== null) {
            $h[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $h;
    }

    /**
     * Returns the verified caller and the acting user (or a 'service' pseudo-user when none).
     * @return array{caller:string,user:array{accountId:string,role:string}}
     */
    public static function authenticate(Request $r): array
    {
        $caller = (string) $r->header('X-Service-Caller');
        $auth = (string) $r->header('Authorization');
        $self = (string) getenv('SERVICE_NAME');
        $secret = preg_match('/^[a-z]{3,20}$/', $caller) && preg_match('/^[a-z]{3,20}$/', $self) ? (string) getenv('TOKEN_' . strtoupper($caller) . '_TO_' . strtoupper($self)) : '';
        if ($secret === '' || !str_starts_with($auth, 'Bearer ') || !hash_equals($secret, substr($auth, 7))) {
            throw new ApiException(401, 'UNAUTHENTICATED', 'Missing or invalid service credentials');
        }
        $b64 = $r->header('X-User-Context');
        if ($b64 === null) {
            return ['caller' => $caller, 'user' => ['accountId' => 'service:' . $caller, 'role' => 'service']];
        }
        if (!hash_equals(self::sign($b64, $secret), (string) $r->header('X-User-Signature'))) {
            throw new ApiException(401, 'UNAUTHENTICATED', 'Bad user context signature');
        }
        $u = json_decode((string) base64_decode($b64, true), true);
        if (!is_array($u) || !is_string($u['accountId'] ?? null) || !is_string($u['role'] ?? null) || !in_array($u['role'], Rbac::ROLES, true)) {
            throw new ApiException(401, 'UNAUTHENTICATED', 'Bad user context');
        }
        return ['caller' => $caller, 'user' => ['accountId' => $u['accountId'], 'role' => $u['role']]];
    }
}
