<?php
declare(strict_types=1);

// Payment webhooks: bad signatures, replays, wrong amounts, and payments that arrive after the hold expired or the booking was cancelled.
// Runs against the real stack (webhook -> billing -> outbox -> booking) and a real SQL Server.
require __DIR__ . '/lib.php';

echo "payments\n";
$standard = roomTypeId('Standard');
$secret = (string) getenv('PAYMONGO_WEBHOOK_SECRET');

/** Create a hold and a checkout session for it. @return array{0:string,1:string,2:int,3:string,4:string} reservation id, session id, amount, in, out */
function holdAndCheckout(string $acct, string $typeId): array
{
    [$in, $out] = freshStay(2);
    leaveFree('Standard', $in, $out, 5);
    [$s, $h] = holdVia($acct, $typeId, $in, $out);
    check($s === 201, 'hold created: ' . json_encode($h));
    [$s, $co] = svcCall('billing', 'POST', "/internal/reservations/{$h['reservationId']}/checkout", 'guest', $acct, [], 'co-' . uuid());
    check($s === 201, 'checkout session created');
    return [$h['reservationId'], $co['sessionId'], (int) $co['amountMinor'], $in, $out];
}

function status(string $rid): string
{
    $st = admin('booking')->prepare('SELECT status FROM reservation WHERE reservation_id = ?');
    $st->execute([$rid]);
    return (string) $st->fetchColumn();
}

function night(string $typeId, string $date): array
{
    $st = admin('booking')->prepare('SELECT held, confirmed FROM inventory_night WHERE room_type_id = ? AND stay_date = ?');
    $st->execute([$typeId, $date]);
    return array_map('intval', $st->fetch());
}

// ---- 1. bad signatures and wrong amounts never confirm anything
$acct = uuid();
[$rid, $sid, $amount, $in, $out] = holdAndCheckout($acct, $standard);
[$body, $sig] = paidWebhook($sid, $rid, $amount);
[$s] = postWebhook($body, 't=' . time() . ',te=' . str_repeat('0', 64) . ',li=');
check($s === 403, 'a forged signature is refused (403)');
[$s] = postWebhook($body . ' ', $sig);
check($s === 403, 'a body changed after signing is refused (403)');
[$s] = postWebhook($body, '');
check($s === 403, 'a missing signature is refused (403)');
check(status($rid) === 'HELD', 'the reservation is still HELD after refused webhooks');

[$wrongBody, $wrongSig] = paidWebhook($sid, $rid, $amount - 100);
[$s, $d] = postWebhook($wrongBody, $wrongSig);
check($s === 200 && $d['result'] === 'review', 'a payment for the wrong amount is kept for finance review, not accepted');
sleep(3);
check(status($rid) === 'HELD', 'a wrong-amount payment does not confirm the booking');

// ---- 2. the real payment, replayed many times, takes effect once
[$body, $sig] = paidWebhook($sid, $rid, $amount, 'pay_replay_' . bin2hex(random_bytes(6)), 'evt_replay_' . bin2hex(random_bytes(6)));
[$s, $first] = postWebhook($body, $sig);
check($s === 200 && $first['result'] === 'captured', 'first delivery is captured');
for ($i = 0; $i < 4; $i++) {
    [$s, $again] = postWebhook($body, $sig);
    check($s === 200 && $again['result'] === 'duplicate', "replay #" . ($i + 1) . ' is acknowledged as a duplicate');
}
until(fn () => status($rid) === 'CONFIRMED', 'booking confirmed');
$L = admin('billing');
$n = $L->prepare("SELECT COUNT(*) FROM payment WHERE reservation_id = ? AND status = 'CAPTURED'");
$n->execute([$rid]);
check((int) $n->fetchColumn() === 1, 'exactly one captured payment row after 5 deliveries');
$ev = $L->prepare("SELECT COUNT(*) FROM outbox WHERE event_type = 'PaymentCaptured' AND correlation_id = ?");
$ev->execute([$rid]);
check((int) $ev->fetchColumn() === 1, 'exactly one PaymentCaptured event was queued');
$c = night($standard, $in);
check($c['held'] === 0 && $c['confirmed'] === 16, 'stock moved from held to confirmed once (' . json_encode($c) . ')');
$em = admin('portal')->prepare("SELECT COUNT(*) FROM notification_job WHERE reservation_id = ? AND type = 'EMAIL_CONFIRMATION'");
$em->execute([$rid]);
until(function () use ($em, $rid) { $em->execute([$rid]); return (int) $em->fetchColumn() === 1; }, 'one confirmation email job');
check(true, 'exactly one confirmation email job for the booking');

// ---- 3. payment arrives after the hold expired, and a room is still free: a fresh allocation confirms it
[$rid2, $sid2, $amount2, $in2, $out2] = holdAndCheckout(uuid(), $standard);
admin('booking')->prepare('UPDATE reservation SET hold_expires_at = DATEADD(MINUTE, -1, DATETRUNC(SECOND, SYSUTCDATETIME())) WHERE reservation_id = ?')->execute([$rid2]);
until(fn () => status($rid2) === 'EXPIRED', 'hold expired by the job runner');
check(night($standard, $in2)['held'] === 0, 'expiry gave the stock back');
[$body, $sig] = paidWebhook($sid2, $rid2, $amount2);
[$s, $d] = postWebhook($body, $sig);
check($s === 200 && $d['result'] === 'captured', 'late payment is recorded');
until(fn () => status($rid2) === 'CONFIRMED', 'late payment confirms with a fresh allocation');
check(night($standard, $in2)['confirmed'] === 16, 'the fresh allocation took one room (' . json_encode(night($standard, $in2)) . ')');

// ---- 4. payment arrives after expiry and nothing is left: stays EXPIRED, exactly one refund request
[$rid3, $sid3, $amount3, $in3, $out3] = holdAndCheckout(uuid(), $standard);
admin('booking')->prepare('UPDATE reservation SET hold_expires_at = DATEADD(MINUTE, -1, DATETRUNC(SECOND, SYSUTCDATETIME())) WHERE reservation_id = ?')->execute([$rid3]);
until(fn () => status($rid3) === 'EXPIRED', 'hold expired');
leaveFree('Standard', $in3, $out3, 0);   // the last rooms were sold meanwhile
[$body, $sig] = paidWebhook($sid3, $rid3, $amount3);
postWebhook($body, $sig);
postWebhook($body, $sig);
$rf = $L->prepare('SELECT COUNT(*) AS n, MIN(reason) AS reason FROM refund r JOIN payment p ON p.payment_id = r.payment_id WHERE p.reservation_id = ?');
until(function () use ($rf, $rid3) { $rf->execute([$rid3]); return (int) $rf->fetch()['n'] >= 1; }, 'refund request filed');
sleep(2);
$rf->execute([$rid3]);
$r = $rf->fetch();
check((int) $r['n'] === 1 && $r['reason'] === 'LATE_NO_STOCK', 'exactly one refund request, reason LATE_NO_STOCK');
check(status($rid3) === 'EXPIRED', 'the reservation stays unconfirmed (EXPIRED)');

// ---- 5. payment arrives after the guest cancelled: stays CANCELLED, exactly one refund request
$acct5 = uuid();
[$rid5, $sid5, $amount5] = holdAndCheckout($acct5, $standard);
[$s] = svcCall('booking', 'POST', "/internal/reservations/{$rid5}/cancel", 'guest', $acct5, []);
check($s === 200, 'guest cancels the hold');
[$body, $sig] = paidWebhook($sid5, $rid5, $amount5);
postWebhook($body, $sig);
until(function () use ($rf, $rid5) { $rf->execute([$rid5]); return (int) $rf->fetch()['n'] >= 1; }, 'refund request for the late payment');
sleep(2);
$rf->execute([$rid5]);
$r = $rf->fetch();
check((int) $r['n'] === 1 && $r['reason'] === 'LATE_AFTER_CANCEL', 'exactly one refund request, reason LATE_AFTER_CANCEL');
check(status($rid5) === 'CANCELLED', 'the reservation stays CANCELLED');

// ---- 6. the test checkout: 3-D Secure fail and approve, e-wallets, other declines. Each outcome travels as a signed webhook.
$attempt = fn (string $acct, string $sid, array $b) => svcCall('billing', 'POST', '/internal/mock/paymongo/sessions/' . rawurlencode($sid) . '/attempts', 'guest', $acct, $b);
$card = fn (string $n) => ['method' => 'card', 'cardNumber' => $n, 'cardExpiry' => '12/' . (int) date('y', strtotime('+3 years')), 'cardCvc' => '123', 'cardName' => 'Payments Tester'];
$paidCount = admin('billing')->prepare("SELECT COUNT(*) FROM payment WHERE reservation_id = ? AND status = 'CAPTURED'");
$paid = function (string $rid) use ($paidCount): int { $paidCount->execute([$rid]); return (int) $paidCount->fetchColumn(); };

$acct6 = uuid();
[$rid6, $sid6] = holdAndCheckout($acct6, $standard);
[$s, $d] = $attempt($acct6, $sid6, $card('4120000000000007'));
check($s === 200 && $d['outcome'] === '3ds' && $d['last4'] === '0007' && $d['threeDsToken'] !== '', '3-D Secure test card asks to authenticate');
[$s, $d2] = $attempt($acct6, $sid6, ['method' => 'card', 'threeDsToken' => $d['threeDsToken'] . 'x', 'decision' => 'approve']);
check($s === 422, 'a changed authentication ticket is refused');
[$s, $d2] = $attempt($acct6, $sid6, ['method' => 'card', 'threeDsToken' => $d['threeDsToken'], 'decision' => 'fail']);
check($s === 200 && $d2['outcome'] === 'failed' && $d2['reason'] === '3ds_failed' && $d2['result'] === 'ignored', 'failed authentication sends payment.failed and records nothing');
check($paid($rid6) === 0 && status($rid6) === 'HELD', 'after a failed authentication there is no payment and the room is still held');
[$s, $d3] = $attempt($acct6, $sid6, ['method' => 'card', 'threeDsToken' => $d['threeDsToken'], 'decision' => 'approve']);
check($s === 200 && $d3['outcome'] === 'paid' && $d3['result'] === 'captured', 'approved authentication captures the payment');
until(fn () => status($rid6) === 'CONFIRMED', '3-D Secure booking confirmed by the webhook');

$acct8 = uuid();
[$rid8, $sid8] = holdAndCheckout($acct8, $standard);
foreach (['5100000000000198' => 'insufficient', '4200000000000018' => 'expired', '4300000000000017' => 'invalid_cvc', '4242424242424242' => 'not_test_card'] as $num => $why) {
    [$s, $d] = $attempt($acct8, $sid8, $card($num));
    check($s === 200 && $d['outcome'] === 'failed' && $d['reason'] === $why, "card {$num}: declined ({$why})");
}
check($paid($rid8) === 0 && status($rid8) === 'HELD', 'declined cards leave no payment and the room still held');

$acct7 = uuid();
[$rid7, $sid7] = holdAndCheckout($acct7, $standard);
[$s] = $attempt($acct7, $sid7, ['method' => 'gcash', 'phone' => '12345', 'decision' => 'authorize']);
check($s === 422, 'e-wallet: a wrong mobile number is refused');
[$s, $d] = $attempt($acct7, $sid7, ['method' => 'paymaya', 'phone' => '0917 123 4567', 'decision' => 'fail']);
check($s === 200 && $d['outcome'] === 'failed' && $paid($rid7) === 0, 'Maya: failed test payment records nothing');
[$s, $d] = $attempt($acct7, $sid7, ['method' => 'gcash', 'phone' => '+639171234567', 'decision' => 'authorize']);
check($s === 200 && $d['outcome'] === 'paid' && $d['result'] === 'captured', 'GCash: authorized test payment is captured');
until(fn () => status($rid7) === 'CONFIRMED', 'e-wallet booking confirmed by the webhook');
check($paid($rid7) === 1, 'exactly one payment for the e-wallet booking');

echo "payments passed ({$GLOBALS['passed']} checks)\n";
