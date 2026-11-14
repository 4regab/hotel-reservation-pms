<?php
declare(strict_types=1);

namespace Billing;

/**
 * The demo checkout's rules (PAYMONGO_MODE=mock only). Decides what a test card or a test e-wallet answer does,
 * the way PayMongo's test mode does. No network, no storage: card details live only for the length of one request,
 * and only the outcome and the last four digits leave this class.
 */
final class MockCheckout
{
    /**
     * PayMongo's published test cards (docs.paymongo.com/docs/payment-acceptance-testing).
     * outcome: paid | 3ds (ask Approve or Fail) | expired | invalid_cvc | insufficient | declined
     */
    public const TEST_CARDS = [
        '4343434343434345' => ['Visa', 'paid'],
        '4571736000000075' => ['Visa', 'paid'],
        '5123000000000002' => ['Mastercard', 'paid'],
        '5123000000000001' => ['Mastercard', 'paid'],
        '4120000000000007' => ['Visa', '3ds'],
        '4200000000000018' => ['Visa', 'expired'],
        '4300000000000017' => ['Visa', 'invalid_cvc'],
        '5100000000000198' => ['Mastercard', 'insufficient'],
        '4111111111111111' => ['Visa', 'declined'],
    ];

    public const MESSAGES = [
        'expired' => 'The card was declined because it has expired. Try another card.',
        'invalid_cvc' => 'The card was declined because the security code (CVC) is wrong.',
        'insufficient' => 'The card was declined for insufficient funds. Try another card.',
        'declined' => 'The card was declined by the bank. Try another card.',
        'not_test_card' => 'This test checkout only accepts PayMongo test card numbers. No real card can be charged here.',
        '3ds_failed' => 'The card was not authenticated, so no payment was made.',
        'wallet_failed' => 'The e-wallet payment was not authorized, so no payment was made.',
    ];

    public static function digits(string $s): string
    {
        return (string) preg_replace('/\D+/', '', $s);
    }

    public static function brand(string $number): string
    {
        $n = self::digits($number);
        return match (true) {
            str_starts_with($n, '4') => 'Visa',
            (bool) preg_match('/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/', $n) => 'Mastercard',
            default => 'Card',
        };
    }

    public static function luhn(string $number): bool
    {
        $n = self::digits($number);
        if (strlen($n) < 12) {
            return false;
        }
        $sum = 0;
        foreach (array_reverse(str_split($n)) as $i => $d) {
            $d = (int) $d;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    /** @return array<string,string> field => message, empty when the form is complete */
    public static function validateCard(string $number, string $expiry, string $cvc, string $name, int $now): array
    {
        $err = [];
        $n = self::digits($number);
        // PayMongo lists 5123000000000002 although it fails the Luhn check, so listed test cards skip that check.
        if (strlen($n) !== 16 || (!self::luhn($n) && !isset(self::TEST_CARDS[$n]))) {
            $err['cardNumber'] = 'Enter a valid 16-digit card number.';
        }
        if (!preg_match('/^\s*(\d{2})\s*\/\s*(\d{2})\s*$/', $expiry, $m) || (int) $m[1] < 1 || (int) $m[1] > 12) {
            $err['cardExpiry'] = 'Enter the expiry date as MM/YY.';
        } else {
            $lastDay = gmmktime(23, 59, 59, (int) $m[1] + 1, 0, 2000 + (int) $m[2]);
            if ($lastDay < $now) {
                $err['cardExpiry'] = 'This expiry date is in the past.';
            }
        }
        if (!preg_match('/^\d{3}$/', trim($cvc))) {
            $err['cardCvc'] = 'Enter the 3-digit security code from the back of the card.';
        }
        if (trim($name) === '' || mb_strlen(trim($name)) > 80) {
            $err['cardName'] = 'Enter the name on the card.';
        }
        return $err;
    }

    /** What a valid card number does. Any number that is not a PayMongo test card is declined. */
    public static function cardOutcome(string $number): string
    {
        return self::TEST_CARDS[self::digits($number)][1] ?? 'not_test_card';
    }

    /** Philippine mobile number, as 09XXXXXXXXX or +639XXXXXXXXX. */
    public static function validPhone(string $phone): bool
    {
        return (bool) preg_match('/^(\+63|0)9\d{9}$/', (string) preg_replace('/[\s-]+/', '', $phone));
    }

    /** A short signed ticket for the 3-D Secure step, so no card data has to be kept between the two requests. */
    public static function threeDsToken(string $sessionId, string $last4, string $secret, int $now): string
    {
        $payload = $sessionId . '.' . $last4 . '.' . ($now + 600);
        return $payload . '.' . hash_hmac('sha256', '3ds.' . $payload, $secret);
    }

    /** @return ?string the last four digits when the ticket is genuine, for this session and not expired */
    public static function checkThreeDsToken(string $token, string $sessionId, string $secret, int $now): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            return null;
        }
        [$sid, $last4, $exp, $sig] = $parts;
        $ok = hash_equals(hash_hmac('sha256', '3ds.' . $sid . '.' . $last4 . '.' . $exp, $secret), $sig);
        return $ok && $sid === $sessionId && (int) $exp >= $now && preg_match('/^\d{4}$/', $last4) ? $last4 : null;
    }
}
