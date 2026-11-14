<?php
declare(strict_types=1);

// The test checkout (PAYMONGO_MODE=mock) must answer like PayMongo's test mode and must never let a real card through.
use Billing\MockCheckout;

$now = gmmktime(12, 0, 0, 11, 15, 2026);

return [
    'mock checkout: every published test card passes the Luhn check and has its documented outcome' => function (): void {
        foreach (MockCheckout::TEST_CARDS as $num => [$brand, $outcome]) {
            check(MockCheckout::luhn((string) $num) || $num === 5123000000000002, "{$num} passes Luhn (PayMongo lists one that does not)");
            same([], MockCheckout::validateCard((string) $num, '12/30', '123', 'Test', gmmktime(0, 0, 0, 1, 1, 2027)), "{$num} is accepted by the form");
            same($brand, MockCheckout::brand((string) $num), "{$num} brand");
            same($outcome, MockCheckout::cardOutcome(chunk_split((string) $num, 4, ' ')), "{$num} outcome, typed with spaces");
        }
        same('paid', MockCheckout::cardOutcome('4343434343434345'), 'success card');
        same('3ds', MockCheckout::cardOutcome('4120000000000007'), '3-D Secure card');
        same('insufficient', MockCheckout::cardOutcome('5100000000000198'), 'insufficient funds card');
        same('declined', MockCheckout::cardOutcome('4111111111111111'), 'generic decline card');
    },
    'mock checkout: any other card number is declined, even a valid-looking one' => function (): void {
        same('not_test_card', MockCheckout::cardOutcome('4242424242424242'), 'a Luhn-valid number that is not a PayMongo test card');
        same('not_test_card', MockCheckout::cardOutcome('5555555555554444'), 'another valid-looking number');
    },
    'mock checkout: the card form is checked on the server' => function () use ($now): void {
        same([], MockCheckout::validateCard('4343 4343 4343 4345', '12/30', '123', 'Ana Cruz', $now), 'a complete card');
        $e = MockCheckout::validateCard('4343434343434346', '13/30', '12', ' ', $now);
        same(['cardNumber', 'cardExpiry', 'cardCvc', 'cardName'], array_keys($e), 'bad Luhn, month 13, short CVC, empty name');
        check(isset(MockCheckout::validateCard('4343434343434345', '10/26', '123', 'A', $now)['cardExpiry']), 'October 2026 is past on 15 November 2026');
        same([], MockCheckout::validateCard('4343434343434345', '11/26', '123', 'A', $now), 'the current month is still valid');
    },
    'mock checkout: e-wallet numbers and the 3-D Secure ticket' => function () use ($now): void {
        check(MockCheckout::validPhone('0917 123 4567') && MockCheckout::validPhone('+639171234567'), 'Philippine mobile numbers');
        check(!MockCheckout::validPhone('12345') && !MockCheckout::validPhone('0817 123 4567'), 'not a mobile number');
        $t = MockCheckout::threeDsToken('cs_mock_1', '0007', 'secret', $now);
        same('0007', MockCheckout::checkThreeDsToken($t, 'cs_mock_1', 'secret', $now + 60), 'a genuine ticket gives back the last four digits');
        same(null, MockCheckout::checkThreeDsToken($t, 'cs_mock_2', 'secret', $now), 'ticket for another session');
        same(null, MockCheckout::checkThreeDsToken($t, 'cs_mock_1', 'other', $now), 'ticket signed with another secret');
        same(null, MockCheckout::checkThreeDsToken($t, 'cs_mock_1', 'secret', $now + 601), 'expired ticket');
        check(!str_contains($t, '4120000000000007'), 'the ticket holds no card number');
    },
];
