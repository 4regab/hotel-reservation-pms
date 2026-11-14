<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;

/** Fixed-window request counter kept in the portal database. */
final class RateLimit
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function hit(string $bucket, int $limit, int $windowSeconds): void
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $this->pdo->prepare('MERGE rate_limit WITH (HOLDLOCK) AS d USING (SELECT ? AS bucket, CAST(? AS INT) AS window_start) AS s ON d.bucket = s.bucket AND d.window_start = s.window_start
                             WHEN MATCHED THEN UPDATE SET hits = d.hits + 1
                             WHEN NOT MATCHED THEN INSERT (bucket, window_start, hits) VALUES (s.bucket, s.window_start, 1);')->execute([$bucket, $window]);
        $st = $this->pdo->prepare('SELECT hits FROM rate_limit WHERE bucket = ? AND window_start = ?');
        $st->execute([$bucket, $window]);
        if ((int) $st->fetchColumn() > $limit) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many requests, slow down');
        }
    }
}
