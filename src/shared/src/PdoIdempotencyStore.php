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
        $r = Db::call($this->pdo, 'usp_idem_find', ['idem_key' => $key, 'caller' => $caller])->fetch();
        return $r === false ? null : ['request_hash' => $r['request_hash'], 'status' => (int) $r['status'], 'body' => $r['body'], 'created_at' => (int) $r['created_at']];
    }

    public function save(string $key, string $caller, string $requestHash, int $status, string $body, int $now): void
    {
        Db::call($this->pdo, 'usp_idem_save', [
            'idem_key' => $key, 'caller' => $caller, 'request_hash' => $requestHash, 'response_status' => $status,
            'response_body' => $body, 'created_epoch' => $now,
        ]);
    }
}
