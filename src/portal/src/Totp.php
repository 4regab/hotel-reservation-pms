<?php
declare(strict_types=1);

namespace Portal;

/** RFC 6238 time-based one-time codes (SHA-1, 30 second steps). Staff second factor. */
final class Totp
{
    public static function base32Decode(string $s): string
    {
        $s = strtoupper(str_replace([' ', '='], '', $s));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($s) as $ch) {
            $v = strpos($alphabet, $ch);
            if ($v === false) {
                throw new \InvalidArgumentException('not base32');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secretBase32, int $time, int $digits = 6): string
    {
        $hash = hash_hmac('sha1', pack('J', intdiv($time, 30)), self::base32Decode($secretBase32), true);
        $offset = ord($hash[19]) & 0x0f;
        $n = (unpack('N', substr($hash, $offset, 4))[1]) & 0x7fffffff;
        return str_pad((string) ($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Accepts the previous, current and next step to allow for clock drift. */
    public static function verify(string $secretBase32, string $code, int $time): bool
    {
        foreach ([-30, 0, 30] as $drift) {
            if (hash_equals(self::code($secretBase32, $time + $drift), $code)) {
                return true;
            }
        }
        return false;
    }
}
