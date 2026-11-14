<?php
declare(strict_types=1);

use Lib\ApiException;
use Lib\Idempotency;
use Lib\IdempotencyStore;

/** In-memory stand-in for the idempotency_key table. */
final class MemoryStore implements IdempotencyStore
{
    public array $rows = [];

    public function find(string $key, string $caller): ?array
    {
        return $this->rows[$caller . '|' . $key] ?? null;
    }

    public function save(string $key, string $caller, string $requestHash, int $status, string $body, int $now): void
    {
        $this->rows[$caller . '|' . $key] = ['request_hash' => $requestHash, 'status' => $status, 'body' => $body, 'created_at' => $now];
    }
}

$h = static fn (string $body) => Idempotency::hash('POST', '/v1/reservations', $body);

return [
    'first use runs the handler and saves the response' => function () use ($h): void {
        $s = new MemoryStore();
        $calls = 0;
        [$st, $body, $replayed] = Idempotency::run($s, 'k1', 'acct', $h('{"a":1}'), function () use (&$calls) {
            $calls++;
            return [201, ['reservationId' => 'r1']];
        }, 1000);
        same([201, ['reservationId' => 'r1'], false, 1], [$st, $body, $replayed, $calls], 'first call');
    },
    'same key and same request returns the saved response without running again' => function () use ($h): void {
        $s = new MemoryStore();
        $calls = 0;
        $run = function (int $now) use ($s, $h, &$calls) {
            return Idempotency::run($s, 'k1', 'acct', $h('{"a":1}'), function () use (&$calls) {
                $calls++;
                return [201, ['reservationId' => 'r' . $calls]];
            }, $now);
        };
        $run(1000);
        [$st, $body, $replayed] = $run(1060);
        same([201, ['reservationId' => 'r1'], true, 1], [$st, $body, $replayed, $calls], 'replay');
    },
    'same key with a different request is a 409' => function () use ($h): void {
        $s = new MemoryStore();
        Idempotency::run($s, 'k1', 'acct', $h('{"a":1}'), fn () => [201, ['ok' => true]], 1000);
        throws(fn () => Idempotency::run($s, 'k1', 'acct', $h('{"a":2}'), fn () => [201, []], 1001), ApiException::class, 409);
    },
    'the same key from another caller is independent' => function () use ($h): void {
        $s = new MemoryStore();
        Idempotency::run($s, 'k1', 'alice', $h('{"a":1}'), fn () => [201, ['who' => 'alice']], 1000);
        [, $body, $replayed] = Idempotency::run($s, 'k1', 'bob', $h('{"a":2}'), fn () => [201, ['who' => 'bob']], 1001);
        same(['who' => 'bob'], $body, 'bob gets his own result');
        same(false, $replayed, 'not a replay');
    },
    'records expire after 24 hours' => function () use ($h): void {
        $s = new MemoryStore();
        Idempotency::run($s, 'k1', 'acct', $h('{"a":1}'), fn () => [201, ['n' => 1]], 1000);
        [, $body, $replayed] = Idempotency::run($s, 'k1', 'acct', $h('{"a":2}'), fn () => [201, ['n' => 2]], 1000 + Idempotency::TTL);
        same([['n' => 2], false], [$body, $replayed], 'key reusable after 24h');
    },
    'a failed attempt is not saved, so it can be retried' => function () use ($h): void {
        $s = new MemoryStore();
        Idempotency::run($s, 'k1', 'acct', $h('{}'), fn () => [409, ['error' => 'conflict']], 1000);
        same([], $s->rows, 'nothing saved for a 409');
    },
    'a missing or oversized key is a 422' => function () use ($h): void {
        $s = new MemoryStore();
        throws(fn () => Idempotency::run($s, null, 'a', $h('{}'), fn () => [201, []], 1), ApiException::class, 422);
        throws(fn () => Idempotency::run($s, '', 'a', $h('{}'), fn () => [201, []], 1), ApiException::class, 422);
        throws(fn () => Idempotency::run($s, str_repeat('x', 129), 'a', $h('{}'), fn () => [201, []], 1), ApiException::class, 422);
    },
];
