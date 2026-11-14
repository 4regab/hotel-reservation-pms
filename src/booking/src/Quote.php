<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;

/**
 * A quote is a signed, short-lived price offer. It is stateless (no stock is reserved) and
 * the hold request must repeat exactly what was quoted.
 */
final class Quote
{
    public const TTL = 900;

    public static function issue(array $fields, string $secret, int $now): string
    {
        $fields['exp'] = $now + self::TTL;
        $p = rtrim(strtr(base64_encode(json_encode($fields)), '+/', '-_'), '=');
        return $p . '.' . hash_hmac('sha256', $p, $secret);
    }

    public static function verify(string $quoteId, string $secret, int $now): array
    {
        $parts = explode('.', $quoteId);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) {
            throw new ApiException(422, 'INVALID_QUOTE', 'Quote is not valid');
        }
        $d = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($d) || ($d['exp'] ?? 0) < $now) {
            throw new ApiException(422, 'INVALID_QUOTE', 'Quote has expired');
        }
        return $d;
    }
}
