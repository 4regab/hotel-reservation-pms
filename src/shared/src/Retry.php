<?php
declare(strict_types=1);

namespace Lib;

/** Delivery retry schedule: growing delays, flag for staff review after five failed attempts. */
final class Retry
{
    public const MAX_ATTEMPTS = 5;

    /** @return array{status:string,delay:int} */
    public static function afterFailure(int $failedAttempts): array
    {
        if ($failedAttempts >= self::MAX_ATTEMPTS) {
            return ['status' => 'REVIEW', 'delay' => 0];
        }
        return ['status' => 'PENDING', 'delay' => min(3600, 30 * (2 ** ($failedAttempts - 1)))];
    }
}
