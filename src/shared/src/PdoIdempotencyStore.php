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
        $st = $this->pdo->prepare("SELECT request_hash, response_status AS status, response_body AS body, DATEDIFF_BIG(SECOND, '1970-01-01', created_at) AS created_at FROM idempotency_key WHERE idem_key = ? AND caller = ?");
        $st->execute([$key, $caller]);
        $r = $st->fetch();
        return $r === false ? null : ['request_hash' => $r['request_hash'], 'status' => (int) $r['status'], 'body' => $r['body'], 'created_at' => (int) $r['created_at']];
    }

    public function save(string $key, string $caller, string $requestHash, int $status, string $body, int $now): void
    {
        $st = $this->pdo->prepare(
            "MERGE idempotency_key WITH (HOLDLOCK) AS t
             USING (SELECT ? AS idem_key, ? AS caller, ? AS request_hash, CAST(? AS INT) AS response_status, ? AS response_body, DATEADD(SECOND, CAST(? AS INT), CAST('1970-01-01' AS DATETIME2(0))) AS created_at) AS s
                ON t.idem_key = s.idem_key AND t.caller = s.caller
             WHEN MATCHED THEN UPDATE SET request_hash = s.request_hash, response_status = s.response_status, response_body = s.response_body, created_at = s.created_at
             WHEN NOT MATCHED THEN INSERT (idem_key, caller, request_hash, response_status, response_body, created_at) VALUES (s.idem_key, s.caller, s.request_hash, s.response_status, s.response_body, s.created_at);"
        );
        $st->execute([$key, $caller, $requestHash, $status, $body, $now]);
    }
}
