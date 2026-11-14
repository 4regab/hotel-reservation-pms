<?php
declare(strict_types=1);

namespace Lib;

final class ReservationState
{
    public const HELD = 'HELD';
    public const CONFIRMED = 'CONFIRMED';
    public const EXPIRED = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';
    public const CHECKED_IN = 'CHECKED_IN';
    public const CHECKED_OUT = 'CHECKED_OUT';
    public const NO_SHOW = 'NO_SHOW';

    private const MAP = [
        self::HELD       => [self::CONFIRMED, self::EXPIRED, self::CANCELLED],
        self::CONFIRMED  => [self::CHECKED_IN, self::CANCELLED, self::NO_SHOW],
        self::CHECKED_IN => [self::CHECKED_OUT],
        // An expired hold may only be confirmed after a fresh allocation (late payment).
        self::EXPIRED    => [self::CONFIRMED],
    ];

    public static function canTransition(string $from, string $to, bool $freshAllocation = false): bool
    {
        if (!in_array($to, self::MAP[$from] ?? [], true)) {
            return false;
        }
        return !($from === self::EXPIRED && $to === self::CONFIRMED) || $freshAllocation;
    }

    public static function assert(string $from, string $to, bool $freshAllocation = false): void
    {
        if (!self::canTransition($from, $to, $freshAllocation)) {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', "Reservation cannot go from {$from} to {$to}");
        }
    }
}
