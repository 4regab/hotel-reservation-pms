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
        $this->pdo->prepare('INSERT INTO rate_limit (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$bucket, $window]);
        $st = $this->pdo->prepare('SELECT hits FROM rate_limit WHERE bucket = ? AND window_start = ?');
        $st->execute([$bucket, $window]);
        if ((int) $st->fetchColumn() > $limit) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many requests, slow down');
        }
    }
}
