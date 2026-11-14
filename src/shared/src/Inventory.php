<?php
declare(strict_types=1);

namespace Lib;

/**
 * Pure stock arithmetic for dated room-type inventory. No database here, so it
 * is unit-testable. The Booking service loads locked rows, calls these functions,
 * and writes the result back inside one transaction.
 *
 * A row is: ['capacity'=>int,'blocked'=>int,'held'=>int,'confirmed'=>int,'ota'=>int]
 * Rules: every counter >= 0, and blocked+held+confirmed+ota <= capacity.
 */
final class Inventory
{
    public const BUCKETS = ['blocked', 'held', 'confirmed', 'ota'];

    public static function available(array $row): int
    {
        return $row['capacity'] - $row['blocked'] - $row['held'] - $row['confirmed'] - $row['ota'];
    }

    /** Smallest availability across the nights (what a guest can book for the whole stay). */
    public static function minAvailable(array $rows, array $nights): int
    {
        $min = PHP_INT_MAX;
        foreach ($nights as $n) {
            $min = min($min, self::available(self::row($rows, $n)));
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * Add $qty to $bucket on every night, or throw InsufficientStock and change nothing.
     * Returns a new rows array; the input is never modified.
     */
    public static function reserve(array $rows, array $nights, int $qty, string $bucket): array
    {
        self::guard($nights, $qty, $bucket);
        foreach ($nights as $n) {
            if (self::available(self::row($rows, $n)) < $qty) {
                throw new InsufficientStock($n);
            }
        }
        foreach ($nights as $n) {
            $rows[$n][$bucket] += $qty;
        }
        return $rows;
    }

    /** Give $qty back from $bucket on every night. */
    public static function release(array $rows, array $nights, int $qty, string $bucket): array
    {
        self::guard($nights, $qty, $bucket);
        foreach ($nights as $n) {
            if (self::row($rows, $n)[$bucket] < $qty) {
                throw new \LogicException("cannot release {$qty} from {$bucket} on {$n}");
            }
        }
        foreach ($nights as $n) {
            $rows[$n][$bucket] -= $qty;
        }
        return $rows;
    }

    /** Move stock between buckets (e.g. held -> confirmed). Total used stays the same. */
    public static function move(array $rows, array $nights, int $qty, string $from, string $to): array
    {
        self::guard($nights, $qty, $from);
        self::guard($nights, $qty, $to);
        foreach ($nights as $n) {
            if (self::row($rows, $n)[$from] < $qty) {
                throw new \LogicException("cannot move {$qty} out of {$from} on {$n}");
            }
        }
        foreach ($nights as $n) {
            $rows[$n][$from] -= $qty;
            $rows[$n][$to] += $qty;
        }
        return $rows;
    }

    private static function row(array $rows, string $night): array
    {
        return $rows[$night] ?? throw new \LogicException("no inventory row for {$night}");
    }

    private static function guard(array $nights, int $qty, string $bucket): void
    {
        if ($nights === [] || count(array_unique($nights)) !== count($nights)) {
            throw new \InvalidArgumentException('nights must be a non-empty list without duplicates');
        }
        if ($qty < 1 || !in_array($bucket, self::BUCKETS, true)) {
            throw new \InvalidArgumentException('bad quantity or bucket');
        }
    }
}
