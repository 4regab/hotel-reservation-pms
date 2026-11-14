<?php
declare(strict_types=1);

namespace Lib;

final class PdoIdempotencyStore implements IdempotencyStore
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(string $key, string $caller): ?array
    {
        $st = $this->pdo->prepare('SELECT request_hash, response_status AS status, response_body AS body, UNIX_TIMESTAMP(created_at) AS created_at FROM idempotency_key WHERE idem_key = ? AND caller = ?');
        $st->execute([$key, $caller]);
        $r = $st->fetch();
        return $r === false ? null : ['request_hash' => $r['request_hash'], 'status' => (int) $r['status'], 'body' => $r['body'], 'created_at' => (int) $r['created_at']];
    }

    public function save(string $key, string $caller, string $requestHash, int $status, string $body, int $now): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO idempotency_key (idem_key, caller, request_hash, response_status, response_body, created_at) VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE request_hash = VALUES(request_hash), response_status = VALUES(response_status), response_body = VALUES(response_body), created_at = VALUES(created_at)'
        );
        $st->execute([$key, $caller, $requestHash, $status, $body, $now]);
    }
}
