<?php
declare(strict_types=1);

namespace Lib;

/** Keeps contact and card data out of logs and events. Matches on key names. */
final class Pii
{
    private const FRAGMENTS = ['email', 'phone', 'fullname', 'guestname', 'card', 'cvv', 'cvc', 'password', 'secret', 'token', 'authorization'];

    public static function isSensitiveKey(string $key): bool
    {
        $k = strtolower(str_replace(['_', '-'], '', $key));
        foreach (self::FRAGMENTS as $f) {
            if (str_contains($k, $f)) {
                return true;
            }
        }
        return false;
    }

    /** Throws if any key in the payload looks like personal or card data. */
    public static function assertClean(array $data, string $path = 'data'): void
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && self::isSensitiveKey($k)) {
                throw new \InvalidArgumentException("sensitive field '{$path}.{$k}' must not be in an event");
            }
            if (is_array($v)) {
                self::assertClean($v, $path . '.' . $k);
            }
        }
    }

    public static function scrub(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && self::isSensitiveKey($k)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::scrub($v);
            }
        }
        return $data;
    }
}
