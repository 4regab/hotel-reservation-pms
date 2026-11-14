<?php
declare(strict_types=1);

namespace Lib;

/**
 * PayMongo's published test cards (docs.paymongo.com/docs/payment-acceptance-testing), for the demo checkout
 * (PAYMONGO_MODE=mock only). Billing decides what each card does. The portal only lists them on the test checkout page.
 * They live here so neither service has to use the other's code.
 * card number => [brand, outcome]; outcome: paid | 3ds (ask Approve or Fail) | expired | invalid_cvc | insufficient | declined
 */
final class TestCards
{
    public const LIST = [
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
}
