<?php
declare(strict_types=1);

namespace Portal;

/** Display helpers shared by pages and emails. Money is integer centavos; dates are Manila calendar dates. */
final class Fmt
{
    public static function php(int $minor): string
    {
        return ($minor < 0 ? '-' : '') . 'PHP ' . number_format(abs($minor) / 100, 2);
    }

    public static function date(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd))->format('D, j M Y');
    }

    public static function nights(string $in, string $out): int
    {
        return (int) (new \DateTimeImmutable($in))->diff(new \DateTimeImmutable($out))->days;
    }

    /** ISO or SQL UTC time -> "14 Nov 2026, 5:36 PM" in Manila time. */
    public static function dateTime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $t = new \DateTimeImmutable(str_contains($utc, 'T') ? $utc : $utc . ' UTC');
        return $t->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j M Y, g:i A');
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function baseUrl(): string
    {
        return rtrim((string) (getenv('PUBLIC_BASE_URL') ?: 'http://localhost:8080'), '/');
    }
}
