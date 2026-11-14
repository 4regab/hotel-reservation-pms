<?php
declare(strict_types=1);

// Full guest-to-housekeeping journey over HTTP against the real stack and a real SQL Server:
// search -> hold -> checkout -> mock payment webhook -> confirm -> email logged -> check-in -> check-out -> cleaning -> ready.
require __DIR__ . '/lib.php';

echo "journey\n";
$today = Lib\Dates::manilaToday();
$guest = new Client(base());
$email = 'journey.' . bin2hex(random_bytes(4)) . '@example.test';
[$s, $d] = $guest->post('/v1/register', ['email' => $email, 'password' => 'journey-pass-123', 'fullName' => 'Journey Tester', 'phone' => '+63 917 000 1111']);
check($s === 201, 'guest registers');
$guest->login($email, 'journey-pass-123');

// Arrive today so check-in is legal without an override.
$ci = $today;
$co = (new DateTimeImmutable($today))->modify('+2 day')->format('Y-m-d');
[$s, $av] = $guest->get("/v1/availability?checkIn={$ci}&checkOut={$co}&quantity=1");
check($s === 200 && count($av['options']) === 3, 'availability lists 3 room types');
// Use a room type that is for sale tonight AND has a ready room, so the test does not depend on how busy the demo data is.
$ready = admin('roomops')->query("SELECT DISTINCT room_type_id FROM room WHERE readiness = 'READY'")->fetchAll(PDO::FETCH_COLUMN);
$opt = array_values(array_filter($av['options'], fn ($o) => $o['quoteId'] !== null && in_array($o['roomTypeId'], $ready, true)))[0] ?? null;
check($opt !== null, 'a room type is for sale and has a ready room');
check($opt['quoteId'] !== null && $opt['totalMinor'] === 2 * $opt['nightlyMinor'], 'quote is nightly x nights');

[$s, $hold] = $guest->post('/v1/reservations', ['quoteId' => $opt['quoteId'], 'roomTypeId' => $opt['roomTypeId'], 'checkIn' => $ci, 'checkOut' => $co, 'quantity' => 1], 'k-' . uuid());
check($s === 201 && $hold['status'] === 'HELD', 'hold created');
$rid = $hold['reservationId'];
check(strtotime($hold['holdExpiresAt']) - time() > 540, 'hold lasts about 10 minutes');

[$s, $co1] = $guest->post("/v1/reservations/{$rid}/checkout", [], 'k-' . uuid());
check($s === 201 && str_contains($co1['checkoutUrl'], '/mock/paymongo/checkout/'), 'checkout session created');
$attempts = '/v1/mock/paymongo/sessions/' . rawurlencode($co1['sessionId']) . '/attempts';
[$s, $sess] = $guest->get('/v1/mock/paymongo/sessions/' . rawurlencode($co1['sessionId']));
check($s === 200 && $sess['reservationId'] === $rid && $sess['amountMinor'] === $hold['amountMinor'], 'test checkout shows the saved amount');
$card = fn (string $n) => ['method' => 'card', 'cardNumber' => $n, 'cardExpiry' => '12/' . (int) date('y', strtotime('+3 years')), 'cardCvc' => '123', 'cardName' => 'Journey Tester'];
[$s, $bad] = $guest->post($attempts, ['cardExpiry' => '13/99', 'cardCvc' => '1', 'cardName' => ''] + $card('4343434343434346'));
check($s === 422 && isset($bad['error']['fields']['cardNumber'], $bad['error']['fields']['cardExpiry'], $bad['error']['fields']['cardCvc'], $bad['error']['fields']['cardName']), 'card form errors are checked on the server');
[$s, $declined] = $guest->post($attempts, $card('4111 1111 1111 1111'));
check($s === 200 && $declined['outcome'] === 'failed' && $declined['reason'] === 'declined' && $declined['result'] === 'ignored', 'declined test card: failed event sent and ignored');
check(($guest->get("/v1/reservations/{$rid}")[1]['status'] ?? '') === 'HELD', 'after a declined card the room is still held');
[$s, $pay] = $guest->post($attempts, $card('4343 4343 4343 4345'));
check($s === 200 && $pay['outcome'] === 'paid' && $pay['last4'] === '4345' && $pay['result'] === 'captured', 'success test card: signed paid event captured the payment');
check(!str_contains(json_encode($pay), '4343434343434345'), 'the full card number is never sent back');

until(fn () => ($guest->get("/v1/reservations/{$rid}")[1]['status'] ?? '') === 'CONFIRMED', 'reservation CONFIRMED');
check(true, 'reservation confirmed only after verified payment event');

$mail = until(function () use ($email) {
    $st = db('PORTAL')->prepare("SELECT status, subject FROM email_log WHERE to_email = ? AND template = 'booking_confirmation'");
    $st->execute([$email]);
    return $st->fetch();
}, 'confirmation email logged');
check(in_array($mail['status'], ['LOGGED', 'SENT'], true), "confirmation email recorded ({$mail['status']})");

$rec = staff('reception');
[$s, $x] = $rec->post('/v1/stays/check-in', ['reservationId' => $rid, 'identityVerified' => true, 'identityDocType' => 'PASSPORT'], 'k-' . uuid());
check($s === 201, 'reception checks in: ' . json_encode($x));
$roomId = $x['rooms'][0]['roomId'];

until(fn () => ($rec->get("/v1/folios/{$rid}")[1]['folioOpen'] ?? false) === true, 'folio open', 20);
[$s, $x] = $rec->post('/v1/stays/check-out', ['reservationId' => $rid]);
check($s === 200 && $x['status'] === 'CHECKED_OUT', 'reception checks out: ' . json_encode($x));

$hk = staff('housekeeping');
[$s, $rooms] = $hk->get('/v1/rooms');
$room = array_values(array_filter($rooms['rooms'], fn ($r) => $r['roomId'] === $roomId))[0];
check($room['readiness'] === 'DIRTY', 'room is DIRTY after check-out');
[$s, $x] = $hk->req('PATCH', "/v1/rooms/{$roomId}/status", ['status' => 'CLEANING', 'expectedVersion' => $room['version']]);
check($s === 200, 'housekeeping starts cleaning');
[$s, $tasks] = $hk->get('/v1/cleaning-tasks');
$task = array_values(array_filter($tasks['tasks'], fn ($t) => $t['roomId'] === $roomId))[0];
[$s, $x] = $hk->post("/v1/cleaning-tasks/{$task['taskId']}/clean");
check($s === 200 && $x['status'] === 'CLEANED', 'cleaner marks the room clean');
[$s, $x] = $hk->post("/v1/cleaning-tasks/{$task['taskId']}/inspect");
check($s === 403 && $x['error']['code'] === 'SELF_INSPECTION', 'the cleaner cannot inspect their own work');
$mgr = staff('manager');
[$s, $x] = $mgr->post("/v1/cleaning-tasks/{$task['taskId']}/inspect");
check($s === 200 && $x['status'] === 'DONE', 'a second person inspects and releases the room');
[$s, $rooms] = $hk->get('/v1/rooms');
$room = array_values(array_filter($rooms['rooms'], fn ($r) => $r['roomId'] === $roomId))[0];
check($room['readiness'] === 'READY', 'room is READY again');
until(fn () => ($guest->get("/v1/reservations/{$rid}")[1]['status'] ?? '') === 'CHECKED_OUT', 'reservation CHECKED_OUT');
check(true, 'reservation reached CHECKED_OUT');

echo "journey passed ({$GLOBALS['passed']} checks)\n";
