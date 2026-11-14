<?php
declare(strict_types=1);

// 20 guests try to hold the LAST room of a type for the same night at the same moment.
// Exactly one hold may succeed. The rest must be refused with 409, stock must never go negative, and nothing may be oversold.
// Also checks the same race when 3 rooms are left. Runs against the real stack and a real SQL Server.
require __DIR__ . '/lib.php';

/** Fire the same hold request from many guests at once with curl_multi. @return list<array{0:int,1:array}> */
function parallelHolds(string $typeId, string $in, string $out, int $guests): array
{
    [$s, $av] = svcCall('booking', 'GET', "/internal/availability?checkIn={$in}&checkOut={$out}&quantity=1", 'guest', uuid());
    $opt = array_values(array_filter($av['options'], fn ($o) => $o['roomTypeId'] === $typeId))[0];
    check($opt['quoteId'] !== null, 'a quote exists while at least one room is free');
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $guests; $i++) {
        $acct = uuid();
        $body = json_encode(['quoteId' => $opt['quoteId'], 'roomTypeId' => $typeId, 'checkIn' => $in, 'checkOut' => $out, 'quantity' => 1,
            'guest' => ['fullName' => "Racer {$i}", 'email' => "racer{$i}@example.test", 'phone' => '+63 900 0000']]);
        $ch = curl_init(svcUrl('booking') . '/internal/reservations');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => Lib\ServiceAuth::headers('portal', 'booking', ['accountId' => $acct, 'role' => 'guest'], 'race-' . uuid())]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($handles as $ch) {
        $out[] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($ch), true) ?: []];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $out;
}

echo "concurrency\n";
$suite = roomTypeId('Suite');
foreach ([1, 3] as $left) {
    [$in, $out] = freshStay(2);
    leaveFree('Suite', $in, $out, $left);
    $results = parallelHolds($suite, $in, $out, 20);
    $ok = count(array_filter($results, fn ($r) => $r[0] === 201));
    $refused = count(array_filter($results, fn ($r) => $r[0] === 409));
    check($ok === $left, "{$left} room(s) left, 20 parallel holds: exactly {$left} succeeded (got {$ok})");
    check($ok + $refused === 20, "every other request was refused with 409, none failed another way (ok={$ok}, 409={$refused})");

    $b = admin('booking');
    $rows = $b->prepare('SELECT stay_date, capacity, blocked, held, confirmed, ota_remaining FROM inventory_night WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ? ORDER BY stay_date');
    $rows->execute([$suite, $in, $out]);
    foreach ($rows->fetchAll() as $n) {
        check((int) $n['held'] === $ok, "night {$n['stay_date']}: held counter equals the holds that succeeded");
        check((int) $n['held'] + (int) $n['confirmed'] + (int) $n['blocked'] + (int) $n['ota_remaining'] <= (int) $n['capacity'], "night {$n['stay_date']}: never above capacity");
    }
    $cnt = $b->prepare("SELECT COUNT(*) FROM reservation WHERE room_type_id = ? AND check_in = ? AND status = 'HELD'");
    $cnt->execute([$suite, $in]);
    check((int) $cnt->fetchColumn() === $ok, 'reservation rows match the counters');
    $alloc = $b->prepare("SELECT COUNT(*) FROM allocation a JOIN reservation r ON r.reservation_id = a.reservation_id WHERE r.room_type_id = ? AND r.check_in = ? AND a.state = 'HELD'");
    $alloc->execute([$suite, $in]);
    check((int) $alloc->fetchColumn() === $ok * 2, 'both nights of every successful hold are saved (all nights or none)');
}

// Database safety net: even a buggy writer cannot push stock past capacity.
[$in] = freshStay(1);
leaveFree('Suite', $in, (new DateTimeImmutable($in))->modify('+1 day')->format('Y-m-d'), 0);
try {
    admin('booking')->prepare('UPDATE inventory_night SET held = held + 1 WHERE room_type_id = ? AND stay_date = ?')->execute([$suite, $in]);
    check(false, 'the database refused to go above capacity');
} catch (PDOException $e) {
    check(str_contains($e->getMessage(), 'ck_night_capacity') || str_contains($e->getMessage(), 'Check constraint'), 'the database refuses stock above capacity (CHECK constraint)');
}
echo "concurrency passed ({$GLOBALS['passed']} checks)\n";
