<?php
declare(strict_types=1);

namespace RoomOps;

/** Pure room rules: manual status transitions and date-range overlap. */
final class RoomRules
{
    public const STATUSES = ['READY', 'OCCUPIED', 'DIRTY', 'CLEANING', 'OUT_OF_SERVICE'];

    /**
     * Statuses staff may set by hand. OCCUPIED is set only by check-in and left only by
     * check-out (which marks the room DIRTY), so it has no manual transitions.
     */
    private const MANUAL = [
        'DIRTY'          => ['CLEANING', 'OUT_OF_SERVICE'],
        'CLEANING'       => ['READY', 'DIRTY'],
        'READY'          => ['DIRTY', 'OUT_OF_SERVICE'],
        'OUT_OF_SERVICE' => ['DIRTY'],
    ];

    public static function canSetManually(string $from, string $to): bool
    {
        return in_array($to, self::MANUAL[$from] ?? [], true);
    }

    /** Half-open date ranges [start, end): back-to-back stays do not overlap. Dates are YYYY-MM-DD. */
    public static function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }
}
