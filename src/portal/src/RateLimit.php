<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Db;

/** Fixed-window request counter kept in the portal database. */
final class RateLimit
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function hit(string $bucket, int $limit, int $windowSeconds): void
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        if ((int) Db::call($this->pdo, 'usp_rateLimit_hit', ['bucket' => $bucket, 'window_start' => $window])->fetchColumn() > $limit) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many requests, slow down');
        }
    }
}
