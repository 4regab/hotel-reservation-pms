<?php
declare(strict_types=1);

namespace Lib;

/** Stay dates are Asia/Manila calendar dates (YYYY-MM-DD). Event and row times are UTC. */
final class Dates
{
    public const TZ = 'Asia/Manila';
    public const MAX_NIGHTS = 30;

    public static function isDate(string $d): bool
    {
        $x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d, new \DateTimeZone('UTC'));
        return $x !== false && $x->format('Y-m-d') === $d;
    }

    /**
     * Every night from check-in up to, but excluding, check-out.
     * @return list<string>
     */
    public static function nights(string $checkIn, string $checkOut): array
    {
        if (!self::isDate($checkIn) || !self::isDate($checkOut) || $checkOut <= $checkIn) {
            throw new \InvalidArgumentException('invalid stay dates');
        }
        $out = [];
        for ($d = new \DateTimeImmutable($checkIn, new \DateTimeZone('UTC')); $d->format('Y-m-d') < $checkOut; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    public static function manilaToday(?int $now = null): string
    {
        return (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** Check-in time is 14:00 Manila on the arrival date. */
    public static function arrivalAt(string $checkIn): int
    {
        return (new \DateTimeImmutable($checkIn . ' 14:00:00', new \DateTimeZone(self::TZ)))->getTimestamp();
    }

    public static function sqlUtc(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? time());
    }

    public static function iso(?int $ts = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts ?? time());
    }

    /** DB DATETIME (UTC) -> ISO-8601 */
    public static function sqlToIso(?string $sql): ?string
    {
        return $sql === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($sql . ' UTC'));
    }
}
