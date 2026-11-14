<?php
declare(strict_types=1);

namespace Booking;

use Lib\ReservationState as S;

final class PaymentOutcome
{
    public const CONFIRM = 'confirm';
    public const REALLOCATE = 'reallocate';
    public const REFUND = 'refund';
    public const IGNORE = 'ignore';

    /** What a verified payment means for a reservation in this status. */
    public static function forStatus(string $status): string
    {
        return match ($status) {
            S::HELD      => self::CONFIRM,
            S::EXPIRED   => self::REALLOCATE,   // late payment: try a fresh allocation, else refund
            S::CANCELLED => self::REFUND,       // late payment after cancellation: stay cancelled, refund
            default      => self::IGNORE,       // already confirmed / in house / finished: nothing to do
        };
    }
}
