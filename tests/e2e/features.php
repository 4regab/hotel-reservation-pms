<?php
declare(strict_types=1);

// The business rules added on top of the basic journey, driven over HTTP against the real stack and a real SQL Server:
// early-arrival approval, no-show, refund and export separation of duties, OTA allotment import, duplicate merge, maintenance conflict,
// privacy requests, password reset, and "who may see what".
require __DIR__ . '/lib.php';

echo "features\n";
$std = roomTypeId('Standard');
$ids = ['reception' => uuid(), 'manager' => uuid(), 'manager2' => uuid(), 'cashier' => uuid(), 'steward' => uuid(), 'it' => uuid(), 'housekeeping' => uuid()];
$as = fn (string $who, string $svc, string $m, string $p, ?array $b = null, ?string $idem = null) => svcCall($svc, $m, $p, str_replace('2', '', $who), $ids[$who], $b, $idem);

/** A confirmed booking for a guest, paid at the desk. */
function confirmed(array $ids, string $typeName, string $in, string $out, ?string $acct = null): string
{
    $typeId = roomTypeId($typeName);
    leaveFree($typeName, $in, $out, 3);
    [$s, $h] = holdVia($acct ?? uuid(), $typeId, $in, $out);
    check($s === 201, "hold for {$typeName}: " . json_encode($h));
    [$s] = svcCall('billing', 'POST', "/internal/reservations/{$h['reservationId']}/onsite-payment", 'reception', $ids['reception'], ['method' => 'CASH']);
    check($s === 201, 'desk payment recorded');
    $st = admin('booking')->prepare('SELECT status FROM reservation WHERE reservation_id = ?');
    until(function () use ($st, $h) { $st->execute([$h['reservationId']]); return $st->fetchColumn() === 'CONFIRMED'; }, 'confirmed');
    return $h['reservationId'];
}

// ---- early arrival needs the manager
[$in, $out] = freshStay(2);
$rid = confirmed($ids, 'Standard', $in, $out);
$ci = ['reservationId' => $rid, 'identityVerified' => true, 'identityDocType' => 'NATIONAL_ID'];
until(fn () => ($as('reception', 'roomops', 'GET', '/internal/rooms')[0] === 200) && admin('roomops')->query("SELECT COUNT(*) FROM arrival WHERE reservation_id = '{$rid}'")->fetchColumn() > 0, 'arrival row');
[$s, $d] = $as('reception', 'roomops', 'POST', '/internal/stays/check-in', $ci);
check($s === 409 && $d['error']['code'] === 'EARLY_ARRIVAL', 'check-in before the arrival date is refused for reception');
[$s] = $as('reception', 'roomops', 'POST', '/internal/arrival-overrides', ['reservationId' => $rid, 'reason' => 'Flight lands at 6 AM']);
check($s === 201, 'reception asks the manager');
[$s, $d] = $as('reception', 'roomops', 'POST', "/internal/arrival-overrides/{$rid}/approve", []);
check($s === 403, 'reception cannot approve their own request');
[$s] = $as('manager', 'roomops', 'POST', "/internal/arrival-overrides/{$rid}/approve", []);
check($s === 200, 'manager approves');
[$s, $d] = $as('reception', 'roomops', 'POST', '/internal/stays/check-in', $ci);
check($s === 201, 'check-in now works: ' . json_encode($d));
$ov = admin('roomops')->query("SELECT override_reason FROM stay WHERE reservation_id = '{$rid}'")->fetchColumn();
check($ov === 'Flight lands at 6 AM', 'the early arrival reason is saved on the stay');
check(admin('roomops')->query("SELECT COUNT(*) FROM audit_log WHERE action = 'stay.early_check_in' AND target_id = '{$rid}'")->fetchColumn() == 1, 'and written to the audit log');

// ---- no-show: first night kept, the rest refunded, unused nights back on sale
[$in2, $out2] = freshStay(3);
$rid2 = confirmed($ids, 'Standard', $in2, $out2);
[$s, $d] = $as('reception', 'booking', 'POST', "/internal/reservations/{$rid2}/no-show", []);
check($s === 409 && $d['error']['code'] === 'NOT_DUE', 'a no-show cannot be recorded before the arrival date');
$yesterday = (new DateTimeImmutable(Lib\Dates::manilaToday()))->modify('-1 day')->format('Y-m-d');
$tomorrow = (new DateTimeImmutable(Lib\Dates::manilaToday()))->modify('+2 day')->format('Y-m-d');
// move a booking to the recent past so the rule applies (the test edits data directly because the API refuses past stays)
[$in3, $out3] = freshStay(3);
$rid3 = confirmed($ids, 'Standard', $in3, $out3);
$B = admin('booking');
$B->prepare('UPDATE reservation SET check_in = ?, check_out = ? WHERE reservation_id = ?')->execute([$yesterday, $tomorrow, $rid3]);
$B->prepare('UPDATE allocation SET stay_date = DATEADD(DAY, DATEDIFF(DAY, CAST(? AS DATE), stay_date), CAST(? AS DATE)) WHERE reservation_id = ?')->execute([$in3, $yesterday, $rid3]);
foreach (Lib\Dates::nights($yesterday, $tomorrow) as $n) {
    $B->prepare('MERGE inventory_night WITH (HOLDLOCK) AS d USING (SELECT ? AS room_type_id, CAST(? AS DATE) AS stay_date) AS s ON d.room_type_id = s.room_type_id AND d.stay_date = s.stay_date
        WHEN MATCHED THEN UPDATE SET confirmed = GREATEST(d.confirmed, 1)
        WHEN NOT MATCHED THEN INSERT (room_type_id, stay_date, capacity, blocked, held, confirmed, ota_remaining) VALUES (s.room_type_id, s.stay_date, 20, 0, 0, 1, 0);')->execute([$std, $n]);
}
$amount = (int) $B->query("SELECT quote_minor FROM reservation WHERE reservation_id = '{$rid3}'")->fetchColumn();
[$s, $d] = $as('reception', 'booking', 'POST', "/internal/reservations/{$rid3}/no-show", []);
check($s === 200 && $d['status'] === 'NO_SHOW' && $d['penaltyMinor'] === 380000, 'no-show keeps the first night: ' . json_encode($d));
$refund = until(fn () => admin('billing')->query("SELECT r.amount_minor, r.reason FROM refund r JOIN payment p ON p.payment_id = r.payment_id WHERE p.reservation_id = '{$rid3}'")->fetch(), 'refund request for the rest');
check((int) $refund['amount_minor'] === $amount - 380000 && $refund['reason'] === 'NO_SHOW', 'the other nights are refunded');

// ---- refunds and exports: nobody approves their own
$pay = admin('billing')->query("SELECT payment_id FROM payment WHERE reservation_id = '{$rid}'")->fetchColumn();
[$s, $d] = $as('cashier', 'billing', 'POST', '/internal/refunds', ['paymentId' => $pay, 'amountMinor' => 50000, 'reason' => 'GOODWILL']);
check($s === 201, 'cashier requests a refund');
$rfid = $d['refundId'];
[$s] = $as('cashier', 'billing', 'POST', "/internal/refunds/{$rfid}/approve", []);
check($s === 403, 'a cashier cannot approve a refund');
[$s] = $as('manager', 'billing', 'POST', "/internal/refunds/{$rfid}/reject", ['note' => 'Not warranted']);
check($s === 200, 'a manager can decline with a reason');
$pay2 = admin('billing')->query("SELECT payment_id FROM payment WHERE reservation_id = '{$rid2}'")->fetchColumn();
[$s, $d] = $as('manager', 'billing', 'POST', '/internal/refunds', ['paymentId' => $pay2, 'amountMinor' => 10000, 'reason' => 'GOODWILL']);
[$s2, $d2] = $as('manager', 'billing', 'POST', "/internal/refunds/{$d['refundId']}/approve", []);
check($s2 === 403 && $d2['error']['code'] === 'SELF_APPROVAL', 'a manager cannot approve the refund they asked for');
[$s] = $as('manager2', 'billing', 'POST', "/internal/refunds/{$d['refundId']}/approve", []);
check($s === 200, 'a different manager can');

$yd = (new DateTimeImmutable(Lib\Dates::manilaToday()))->modify('-1 day')->format('Y-m-d');
[$s, $d] = $as('cashier', 'billing', 'POST', '/internal/exports', ['from' => $yd, 'to' => $yd]);
check($s === 201, 'cashier prepares an accounting export');
$xid = $d['exportId'];
[$s] = $as('cashier', 'billing', 'GET', "/internal/exports/{$xid}/download");
check($s === 409, 'an unapproved export cannot be downloaded');
[$s] = $as('cashier', 'billing', 'POST', "/internal/exports/{$xid}/approve", []);
check($s === 403, 'a cashier cannot approve an export');
[$s] = $as('manager', 'billing', 'POST', "/internal/exports/{$xid}/approve", []);
check($s === 200, 'a manager approves');
[$s, $d] = $as('cashier', 'billing', 'GET', "/internal/exports/{$xid}/download");
check($s === 200 && str_starts_with($d['csv'], 'line_id,date,type,reservation_ref,method,provider_reference,amount_php,currency'), 'now it downloads as CSV');
check(hash('sha256', $d['csv']) === admin('billing')->query("SELECT checksum FROM accounting_export WHERE export_id = '{$xid}'")->fetchColumn(), 'and matches its checksum');

// ---- OTA: a partner booking can only use a reserved allotment
[$oin, $oout] = freshStay(2);
leaveFree('Standard', $oin, $oout, 10);
[$s, $d] = $as('reception', 'booking', 'POST', '/internal/ota/allotments', ['partnerId' => 'baybook', 'roomTypeId' => $std, 'startDate' => $oin, 'endDate' => $oout, 'quantity' => 2]);
check($s === 201 && $d['status'] === 'PENDING', 'reception asks for an allotment');
$aid = $d['allotmentId'];
[$s] = $as('reception', 'booking', 'POST', "/internal/ota/allotments/{$aid}/approve", []);
check($s === 403, 'reception cannot approve an allotment');
$csv = "external_booking_id,check_in,check_out,room_type,guest_name,guest_email,guest_phone,quantity,amount_php\nBB-" . substr($aid, 0, 6) . "-1,{$oin},{$oout},Standard,Maria Santos,,+63 917 555 0100,1,\n";
[$s, $d] = $as('reception', 'booking', 'POST', '/internal/ota/import', ['partnerId' => 'baybook', 'filename' => 't.csv', 'csv' => $csv]);
check($s === 200 && $d['rowsOk'] === 0, 'an import with no approved allotment books nothing');
[$s] = $as('manager', 'booking', 'POST', "/internal/ota/allotments/{$aid}/approve", []);
check($s === 200, 'manager approves the allotment');
$night = fn () => admin('booking')->query("SELECT confirmed, ota_remaining FROM inventory_night WHERE room_type_id = '{$std}' AND stay_date = '{$oin}'")->fetch();
check($night()['ota_remaining'] == 2 && $night()['confirmed'] == 10, 'two rooms were set aside for the partner');
$rows = '';
foreach ([1, 2, 3] as $i) {
    $rows .= 'BB-' . substr($aid, 0, 6) . "-{$i},{$oin},{$oout},Standard,Guest {$i},,+63 917 555 010{$i},1,\n";
}
[$s, $d] = $as('reception', 'booking', 'POST', '/internal/ota/import', ['partnerId' => 'baybook', 'filename' => 't.csv', 'csv' => explode("\n", $csv)[0] . "\n" . $rows]);
check($s === 200 && $d['rowsOk'] === 2 && $d['rowsRejected'] === 1, 'two rows import, the third has no allotment left: ' . json_encode([$d['rowsOk'], $d['rowsRejected']]));
check($night()['ota_remaining'] == 0 && $night()['confirmed'] == 12, 'stock moved from the allotment to confirmed, not counted twice');
[$s, $d] = $as('reception', 'booking', 'POST', '/internal/ota/import', ['partnerId' => 'baybook', 'filename' => 't.csv', 'csv' => explode("\n", $csv)[0] . "\n" . $rows]);
check($d['rowsOk'] === 0, 're-importing the same file adds nothing (booking ids are unique)');

// ---- duplicate guests
$g1 = $as('reception', 'booking', 'POST', '/internal/guests', ['fullName' => 'Dup Tester One', 'email' => 'dup.one.' . uuid() . '@example.test', 'phone' => '+63 917 888 0001'])[1]['guestId'];
$g2 = $as('reception', 'booking', 'POST', '/internal/guests', ['fullName' => 'Tester Dup One', 'email' => 'dup.two.' . uuid() . '@example.test', 'phone' => '+63 917 888 0002'])[1]['guestId'];
[$s, $d] = $as('steward', 'booking', 'GET', '/internal/guests/duplicates');
check(in_array(true, array_map(fn ($g) => in_array($g1, array_column($g['guests'], 'guestId'), true) && in_array($g2, array_column($g['guests'], 'guestId'), true), $d['groups']), true), 'the steward sees the pair (same name words)');
[$s] = $as('reception', 'booking', 'POST', '/internal/guests/merge', ['keepGuestId' => $g1, 'mergeGuestId' => $g2]);
check($s === 403, 'reception cannot merge guests');
[$s] = $as('steward', 'booking', 'GET', '/internal/reservations');
check($s === 403, 'the steward cannot see bookings');
[$s, $d] = $as('steward', 'booking', 'POST', '/internal/guests/merge', ['keepGuestId' => $g1, 'mergeGuestId' => $g2, 'note' => 'same person']);
check($s === 200, 'the steward merges them');

// ---- maintenance never silently displaces a guest
$room = admin('roomops')->query("SELECT TOP (1) a.room_id FROM room_assignment a WHERE a.stay_id = (SELECT stay_id FROM stay WHERE reservation_id = '{$rid}')")->fetchColumn();
[$s, $d] = $as('housekeeping', 'roomops', 'POST', '/internal/maintenance-blocks', ['requestId' => uuid(), 'roomId' => $room, 'startDate' => $in, 'endDate' => $out, 'reason' => 'Leaking tap']);
check($s === 409 && $d['error']['code'] === 'MAINTENANCE_CONFLICT', 'a block over a guest\'s stay is refused and escalated');
[$s, $d] = $as('manager', 'roomops', 'GET', '/internal/maintenance-blocks?status=CONFLICT');
$blockId = $d['blocks'][0]['blockId'] ?? null;
check($blockId !== null, 'the manager sees it in the queue');
[$s] = $as('manager', 'roomops', 'POST', "/internal/maintenance-blocks/{$blockId}/reject", ['note' => 'Guest is staying']);
check($s === 200, 'the manager declines it');

// ---- who may see what (through the public portal API)
$hk = staff('housekeeping');
[$s] = $hk->get('/v1/payments');
check($s === 403, 'housekeeping cannot read payments');
[$s] = $hk->get('/v1/guests');
check($s === 403, 'housekeeping cannot read guests');
$guest = new Client(base());
$email = 'privacy.' . bin2hex(random_bytes(4)) . '@example.test';
[$s] = $guest->post('/v1/register', ['email' => $email, 'password' => 'privacy-pass-123', 'fullName' => 'Privacy Tester', 'phone' => '+63 917 000 3333']);
check($s === 201, 'second guest registers');
$guest->login($email, 'privacy-pass-123');
[$s] = $guest->get("/v1/reservations/{$rid}");
check($s === 404, "a guest cannot open someone else's booking (404, not 403)");
[$s, $d] = $guest->get('/v1/reservations');
check($s === 200 && $d['reservations'] === [], 'and sees an empty list of their own');

// ---- privacy requests, end to end through the website forms
$form = function (Client $c, string $path, array $f): array {
    return $c->req('POST', $path, null, [], http_build_query($f + ['_csrf' => $c->csrf]), true);
};
[$s] = $form($guest, '/account/privacy', ['type' => 'ACCESS', 'details' => 'Please send my data']);
check($s === 303, 'guest files an access request');
$it = staff('it');
[$s, $page] = $it->get('/staff/admin/privacy');
check($s === 200 && preg_match('#/staff/admin/privacy/([0-9a-f-]{36})#', $page, $m) === 1, 'IT sees the request in the queue');
$reqId = admin('portal')->query("SELECT request_id FROM privacy_request p JOIN account a ON a.account_id = p.account_id WHERE a.email = " . admin('portal')->quote($email))->fetchColumn();
[$s] = $form($it, "/staff/admin/privacy/{$reqId}/complete", ['note' => 'Done']);
check($s === 303, 'IT completes it');
[$s, $json] = $guest->get("/account/privacy/{$reqId}/download");
check($s === 200 && ($json['account']['email'] ?? '') === $email, 'the guest downloads a file with their own data');
[$s] = $form($guest, '/account/privacy', ['type' => 'DELETION', 'details' => '']);
$delId = admin('portal')->query("SELECT request_id FROM privacy_request p JOIN account a ON a.account_id = p.account_id WHERE a.email = " . admin('portal')->quote($email) . " AND p.type = 'DELETION'")->fetchColumn();
[$s] = $form($it, "/staff/admin/privacy/{$delId}/complete", ['note' => 'Deleted']);
$guest2 = new Client(base());
[$s] = $guest2->post('/v1/login', ['email' => $email, 'password' => 'privacy-pass-123']);
check($s === 401, 'after deletion the account can no longer sign in');
check(admin('portal')->query("SELECT TOP (1) full_name FROM account WHERE email LIKE 'deleted-%' AND anonymized_at IS NOT NULL ORDER BY anonymized_at DESC")->fetchColumn() === 'Deleted guest', 'and the name is gone from the account');

// ---- password reset by email link
$u = new Client(base());
$email2 = 'reset.' . bin2hex(random_bytes(4)) . '@example.test';
$u->post('/v1/register', ['email' => $email2, 'password' => 'old-password-123', 'fullName' => 'Reset Tester', 'phone' => '+63 917 000 4444']);
[$s] = $u->post('/v1/password-reset', ['email' => $email2]);
check($s === 202, 'a reset link is requested');
[$s] = $u->post('/v1/password-reset', ['email' => 'nobody.' . uuid() . '@example.test']);
check($s === 202, 'an unknown address gets the same answer (no way to probe for accounts)');
$P = admin('portal');
$mail = until(function () use ($P, $email2) { $st = $P->prepare("SELECT body_html FROM email_log WHERE to_email = ? AND template = 'password_reset'"); $st->execute([$email2]); return $st->fetchColumn(); }, 'reset email');
check(preg_match('#reset-password\?token=([a-f0-9]{64})#', $mail, $m) === 1, 'the email contains a reset link');
$token = $m[1];
[$s] = $u->post('/v1/password-reset/confirm', ['token' => $token, 'password' => 'short']);
check($s === 422, 'a weak new password is refused');
[$s] = $u->post('/v1/password-reset/confirm', ['token' => $token, 'password' => 'brand-new-pass-456']);
check($s === 200, 'the link sets a new password');
[$s] = $u->post('/v1/password-reset/confirm', ['token' => $token, 'password' => 'another-pass-789']);
check($s === 410, 'the same link cannot be used twice');
$fresh = new Client(base());
[$s] = $fresh->post('/v1/login', ['email' => $email2, 'password' => 'old-password-123']);
check($s === 401, 'the old password stops working');
$fresh->login($email2, 'brand-new-pass-456');
check(true, 'and the new one works');
$job = $P->query("SELECT payload FROM notification_job WHERE type = 'EMAIL_PASSWORD_RESET' AND account_id = (SELECT account_id FROM account WHERE email = " . $P->quote($email2) . ')')->fetchColumn();
check($job === null, 'the raw reset token is wiped from the job once the email is sent');

echo "features passed ({$GLOBALS['passed']} checks)\n";
