<?php
declare(strict_types=1);

namespace Lib;

/**
 * Idempotency-Key handling. Key + caller + request hash + saved response are kept 24 hours.
 * Same key + same request -> saved response. Same key + different request -> 409.
 */
final class Idempotency
{
    public const TTL = 86400;

    public static function hash(string $method, string $path, string $body): string
    {
        return hash('sha256', $method . "\n" . $path . "\n" . $body);
    }

    /**
     * Run $handler once per key. Only 2xx results are saved, so a failed attempt can be retried.
     * ponytail: two truly simultaneous first uses of one key are separated by the table's primary key
     * (the loser gets a DB error and the client retries); upgrade path is a row lock on the key.
     * @param callable():array{0:int,1:array} $handler
     * @return array{0:int,1:array,2:bool} status, body, replayed
     */
    public static function run(IdempotencyStore $store, ?string $key, string $caller, string $requestHash, callable $handler, int $now): array
    {
        if ($key === null || $key === '' || strlen($key) > 128) {
            throw new ApiException(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key header is required (max 128 chars)');
        }
        $found = $store->find($key, $caller);
        if ($found !== null && $now - $found['created_at'] < self::TTL) {
            if (!hash_equals($found['request_hash'], $requestHash)) {
                throw new ApiException(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key was already used with a different request');
            }
            return [$found['status'], json_decode($found['body'], true), true];
        }
        [$status, $body] = $handler();
        if ($status >= 200 && $status < 300) {
            $store->save($key, $caller, $requestHash, $status, json_encode($body, JSON_UNESCAPED_SLASHES), $now);
        }
        return [$status, $body, false];
    }
}
