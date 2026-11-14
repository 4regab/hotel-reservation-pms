<?php
declare(strict_types=1);

namespace Booking;

use Lib\Dates;

final class CancellationPolicy
{
    public const FREE_HOURS = 48;

    /**
     * Free if at least $freeHours before arrival (check-in 14:00 Manila). Later cancellation
     * loses the first night for every booked room.
     */
    public static function penaltyMinor(string $checkIn, int $now, int $freeHours, int $nightlyMinor, int $quantity): int
    {
        return Dates::arrivalAt($checkIn) - $now >= $freeHours * 3600 ? 0 : $nightlyMinor * $quantity;
    }
}
