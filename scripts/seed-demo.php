<?php
declare(strict_types=1);

/**
 * Demo data for HarborLight: staff accounts for every role, about 40 fictional guests, and roughly 90 days of
 * bookings, payments, stays, cleaning, refunds and exports so the dashboards and reports look alive.
 * All names, emails and phone numbers are invented. Accounts use the demo password from DEMO_PASSWORD. DEMO USE ONLY.
 *
 * Usage:  php scripts/seed-demo.php            seed only if there are no reservations yet
 *         php scripts/seed-demo.php --reset    first clear business data (needs ALLOW_SEED_RESET=1; development only)
 * Connects with the admin credentials (DB_ADMIN_*) because it writes to all four databases.
 * The generator is seeded with a fixed number, so every run builds the same hotel, shifted to today's date.
 */
require dirname(__DIR__) . '/autoload.php';

use Lib\Dates;
use Lib\Uuid;

mt_srand(20261114);
$reset = in_array('--reset', $argv, true);

function admin(string $svc): PDO
{
    $name = (string) getenv(strtoupper($svc) . '_DB_NAME');
    // Same fetch mode as Lib\Db::connect: INT columns come back as PHP ints (BIGINT stays a string).
    return new PDO(\Lib\Db::dsn(getenv('DB_ADMIN_HOST') ?: '127.0.0.1', getenv('DB_ADMIN_PORT') ?: '1433', $name),
        (string) getenv('DB_ADMIN_USER'), (string) getenv('DB_ADMIN_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true]);
}

function ins(PDO $pdo, string $table, array $row): void
{
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_map(fn ($c) => "[$c]", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
}

/** Manila local date + clock time -> UTC datetime string. */
function utc(string $ymd, int $h, int $m = 0, int $s = 0): string
{
    return gmdate('Y-m-d H:i:s', (new DateTimeImmutable(sprintf('%s %02d:%02d:%02d', $ymd, $h, $m, $s), new DateTimeZone('Asia/Manila')))->getTimestamp());
}

function addDays(string $ymd, int $n): string
{
    return (new DateTimeImmutable($ymd))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

function pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

function chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

$B = admin('booking');
$R = admin('roomops');
$L = admin('billing');
$P = admin('portal');
$today = Dates::manilaToday();

if ((int) $B->query('SELECT COUNT(*) FROM reservation')->fetchColumn() > 0 && !$reset) {
    echo "Reservations already exist. Nothing seeded. (Use --reset with ALLOW_SEED_RESET=1 to start over in development.)\n";
    exit(0);
}
if ($reset) {
    if (getenv('ALLOW_SEED_RESET') !== '1') {
        fwrite(STDERR, "--reset needs ALLOW_SEED_RESET=1. This clears bookings, stays, payments and guests.\n");
        exit(2);
    }
    foreach ([[$B, ['allocation', 'ota_booking', 'ota_import', 'ota_allotment_night', 'ota_allotment', 'maintenance_capacity', 'guest_merge', 'reservation', 'guest', 'inventory_night', 'outbox_delivery', 'outbox', 'inbox', 'idempotency_key', 'audit_log']],
        [$R, ['room_assignment', 'cleaning_task', 'maintenance_block', 'arrival_override', 'stay', 'arrival', 'outbox_delivery', 'outbox', 'inbox', 'idempotency_key', 'audit_log']],
        [$L, ['refund', 'charge', 'payment', 'folio', 'checkout_session', 'accounting_export', 'outbox_delivery', 'outbox', 'inbox', 'idempotency_key', 'audit_log']],
        [$P, ['privacy_request', 'password_reset', 'email_log', 'notification_job', 'report_daily', 'session', 'rate_limit', 'inbox', 'audit_log']]] as [$pdo, $tables]) {
        // SQL Server cannot TRUNCATE a table that foreign keys point at: switch the constraints off, DELETE, switch them on (and check them).
        // audit_log has no foreign keys, so it is truncated, which also restarts its id at 1 like MySQL did.
        foreach ($tables as $t) {
            $pdo->exec("ALTER TABLE [{$t}] NOCHECK CONSTRAINT ALL");
        }
        foreach ($tables as $t) {
            $pdo->exec(($t === 'audit_log' ? 'TRUNCATE TABLE' : 'DELETE FROM') . " [{$t}]");
        }
        foreach ($tables as $t) {
            $pdo->exec("ALTER TABLE [{$t}] WITH CHECK CHECK CONSTRAINT ALL");
        }
    }
    $P->exec("DELETE FROM account WHERE email LIKE '%@example.com' OR email LIKE '%@example.org' OR email LIKE '%@example.net' OR email LIKE 'journey.%' OR email LIKE 'test.%'");
    $R->exec("UPDATE room SET readiness = 'READY', version = 1");
}

// ------------------------------------------------------------------------------------------------ accounts
$pass = getenv('DEMO_PASSWORD') ?: 'harborlight-demo';
$totp = getenv('DEMO_TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP';
$hash = password_hash($pass, PASSWORD_DEFAULT);
$staffDefs = [
    ['guest@example.test', 'guest', 'Demo Guest', null],
    ['reception@example.test', 'reception', 'Rosalie Manalang', $totp],
    ['reception2@example.test', 'reception', 'Dante Villareal', $totp],
    ['housekeeping@example.test', 'housekeeping', 'Corazon Labrador', $totp],
    ['housekeeping2@example.test', 'housekeeping', 'Benjie Ramos', $totp],
    ['maintenance@example.test', 'maintenance', 'Efren Sabado', $totp],
    ['cashier@example.test', 'cashier', 'Lourdes Capistrano', $totp],
    ['manager@example.test', 'manager', 'Teodoro Salvador', $totp],
    ['manager2@example.test', 'manager', 'Imelda Cordero', $totp],
    ['it@example.test', 'it', 'Aurelio Bernardo', $totp],
    ['steward@example.test', 'steward', 'Perla Soriano', $totp],
];
$acct = [];
foreach ($staffDefs as [$email, $role, $name, $secret]) {
    $row = $P->prepare('SELECT account_id FROM account WHERE email = ?');
    $row->execute([$email]);
    $id = $row->fetchColumn();
    if ($id === false) {
        $id = Uuid::v4();
        ins($P, 'account', ['account_id' => $id, 'email' => $email, 'password_hash' => $hash, 'role' => $role, 'full_name' => $name, 'phone' => '+63 917 555 ' . sprintf('%04d', mt_rand(1000, 9999)), 'totp_secret' => $secret, 'created_at' => utc(addDays($today, -120), 9), 'last_login_at' => utc(addDays($today, -mt_rand(0, 2)), mt_rand(8, 17), mt_rand(0, 59))]);
    }
    $P->prepare('UPDATE account SET full_name = ?, role = ?, password_hash = ?, totp_secret = ?, active = 1 WHERE account_id = ?')->execute([$name, $role, $hash, $secret, $id]);
    $acct[$email] = (string) $id;
}
$staffId = fn (string $role) => match ($role) { 'reception' => pick([$acct['reception@example.test'], $acct['reception2@example.test']]), 'housekeeping' => pick([$acct['housekeeping@example.test'], $acct['housekeeping2@example.test']]),
    'manager' => pick([$acct['manager@example.test'], $acct['manager2@example.test']]), default => $acct[$role . '@example.test'] };

// ------------------------------------------------------------------------------------------------ guests
$first = ['Maribel', 'Joaquin', 'Katrina', 'Dennis', 'Liza', 'Rafael', 'Bea', 'Marco', 'Cielo', 'Gabriel', 'Trisha', 'Nestor', 'Aileen', 'Paolo', 'Rowena', 'Jericho', 'Lorna', 'Andres', 'Charmaine', 'Arvin',
    'Danica', 'Emmanuel', 'Fatima', 'Gregorio', 'Hazel', 'Ismael', 'Jasmine', 'Kenneth', 'Leah', 'Mateo', 'Noreen', 'Oliver', 'Patricia', 'Ramil', 'Rhea', 'Sergio', 'Tanya', 'Ulysses', 'Vivian', 'Wilfredo'];
$last = ['Fontanilla', 'Alcantara', 'Bautista', 'Dizon', 'Esguerra', 'Villanueva', 'Macaraeg', 'Pangilinan', 'Salonga', 'Tolentino', 'Umali', 'Valdez', 'Zamora', 'Ocampo', 'Mangubat', 'Pascual', 'Quirino', 'Rosales',
    'Sarmiento', 'Trinidad', 'Padilla', 'Navarro', 'Panganiban', 'Mendoza', 'Cabrera', 'Delos Santos', 'Galang', 'Ignacio', 'Jimenez', 'Katigbak', 'Lumbao', 'Marquez', 'Natividad', 'Orquiza', 'Peralta', 'Quimpo',
    'Samonte', 'Tanchuco', 'Urbano', 'Yambao'];
$domains = ['example.com', 'example.org', 'example.net'];
$guests = [];
for ($i = 0; $i < 40; $i++) {
    $fn = $first[$i];
    $ln = $last[($i * 7 + 3) % 40];
    $slug = strtolower(str_replace(' ', '', $fn . '.' . $ln));
    $guests[] = ['id' => Uuid::v4(), 'name' => "$fn $ln", 'email' => $slug . '@' . $domains[$i % 3], 'phone' => sprintf('+63 9%02d %03d %04d', 15 + $i % 20, mt_rand(100, 999), mt_rand(1000, 9999)), 'account' => null, 'created' => addDays($today, -mt_rand(40, 400))];
}
// three planted duplicate pairs for the data steward to find
$guests[37]['name'] = 'Ulysses Urbano'; $dupBase = [[5, 'Rafael Alcantara'], [14, 'Rowena Esguerra'], [26, 'Jasmine Galang']];
foreach ($dupBase as $k => [$idx, $nm]) {
    $d = $guests[$idx];
    $extra = ['id' => Uuid::v4(), 'name' => $k === 0 ? 'Rafael  Alcantara' : ($k === 1 ? 'Esguerra Rowena' : $d['name']), 'email' => $k === 2 ? 'jasmine.g' . $k . '@example.com' : 'r.' . $d['email'], 'phone' => $k === 1 ? '0' . substr(preg_replace('/\D/', '', $d['phone']), 2) : $d['phone'], 'account' => null, 'created' => addDays($today, -mt_rand(5, 60))];
    if ($k === 2) {
        $extra['email'] = $d['email'];   // same email
        $extra['name'] = 'Jasmine G.';
    }
    $guests[] = $extra;
}
// 16 guests have a website account
foreach ([0, 1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 17] as $gi) {
    $g = &$guests[$gi];
    $aid = Uuid::v4();
    ins($P, 'account', ['account_id' => $aid, 'email' => $g['email'], 'password_hash' => $hash, 'role' => 'guest', 'full_name' => $g['name'], 'phone' => $g['phone'], 'totp_secret' => null, 'created_at' => utc($g['created'], 10), 'marketing_consent' => mt_rand(0, 1)]);
    $g['account'] = $aid;
    unset($g);
}
$demoGuest = $P->query("SELECT account_id FROM account WHERE email = 'guest@example.test'")->fetchColumn();
$guests[] = ['id' => Uuid::v4(), 'name' => 'Demo Guest', 'email' => 'guest@example.test', 'phone' => '+63 900 000 0000', 'account' => $demoGuest, 'created' => addDays($today, -30)];
foreach ($guests as $g) {
    ins($B, 'guest', ['guest_id' => $g['id'], 'account_id' => $g['account'], 'full_name' => $g['name'], 'email' => $g['email'], 'phone' => $g['phone'], 'created_at' => utc($g['created'], 11)]);
}
$nGuests = count($guests);

// ------------------------------------------------------------------------------------------------ rooms and types
$types = [];
foreach ($B->query('SELECT room_type_id, name, capacity, nightly_rate_minor FROM room_type ORDER BY nightly_rate_minor')->fetchAll() as $t) {
    $types[$t['name']] = ['id' => $t['room_type_id'], 'cap' => (int) $t['capacity'], 'rate' => (int) $t['nightly_rate_minor']];
}
$typeOf = array_column(array_map(fn ($n, $t) => ['n' => $n, 'id' => $t['id']], array_keys($types), $types), 'n', 'id');
$rooms = [];
foreach ($R->query('SELECT room_id, room_type_id, room_number FROM room ORDER BY room_number')->fetchAll() as $r) {
    $rooms[$r['room_id']] = ['id' => $r['room_id'], 'type' => $r['room_type_id'], 'no' => $r['room_number']];
}
$busy = [];   // room_id => list of [start, end)
$blockedRooms = [];

// ------------------------------------------------------------------------------------------------ maintenance blocks (before planning, they use capacity)
$start = addDays($today, -90);
$end = addDays($today, 45);
$nightsSpan = [];
for ($d = $start; $d <= addDays($end, 6); $d = addDays($d, 1)) {
    $nightsSpan[] = $d;
}
$cnt = [];   // [typeId][night] => ['conf'=>, 'blocked'=>, 'ota'=>]
foreach ($types as $t) {
    foreach ($nightsSpan as $n) {
        $cnt[$t['id']][$n] = ['conf' => 0, 'blocked' => 0, 'ota' => 0];
    }
}
$roomByNo = array_column($rooms, null, 'no');
$blockDefs = [
    ['204', addDays($today, -1), addDays($today, 4), 'APPROVED', 'Air conditioning compressor replacement'],
    ['117', addDays($today, 9), addDays($today, 13), 'APPROVED', 'Repaint and carpet cleaning'],
    ['305', addDays($today, -40), addDays($today, -37), 'APPROVED', 'Bathroom tile repair (finished)'],
];
foreach ($blockDefs as [$no, $s, $e, $status, $reason]) {
    $rm = $roomByNo[$no];
    $req = Uuid::v4();
    ins($R, 'maintenance_block', ['block_id' => Uuid::v4(), 'room_id' => $rm['id'], 'start_date' => $s, 'end_date' => $e, 'status' => $status, 'reason' => $reason, 'request_id' => $req, 'requested_by' => $staffId('maintenance'), 'created_at' => utc(addDays($s, -3), 9), 'decided_by' => $staffId('manager'), 'decided_at' => utc(addDays($s, -3), 10)]);
    ins($B, 'maintenance_capacity', ['request_id' => $req, 'room_type_id' => $rm['type'], 'start_date' => $s, 'end_date' => $e, 'created_at' => utc(addDays($s, -3), 10)]);
    for ($d = $s; $d < $e; $d = addDays($d, 1)) {
        $cnt[$rm['type']][$d]['blocked']++;
    }
    $busy[$rm['id']][] = [$s, $e];
}

// ------------------------------------------------------------------------------------------------ plan reservations
$total = 45;
$lenW = [1, 1, 2, 2, 2, 3, 3, 3, 4, 4, 5, 6];
$typeW = ['Standard', 'Standard', 'Standard', 'Standard', 'Standard', 'Standard', 'Deluxe', 'Deluxe', 'Deluxe', 'Suite'];
$plan = [];
$used = function (string $tid, string $n) use (&$cnt): int {
    return $cnt[$tid][$n]['conf'] + $cnt[$tid][$n]['blocked'] + $cnt[$tid][$n]['ota'];
};
for ($ci = $start; $ci <= $end; $ci = addDays($ci, 1)) {
    $dow = (int) (new DateTimeImmutable($ci))->format('N');
    $lead = (strtotime($ci) - strtotime($today)) / 86400;
    $target = 0.50 + (($dow === 5 || $dow === 6) ? 0.20 : ($dow === 7 ? 0.05 : 0.0)) + 0.06 * sin(strtotime($ci) / 86400 / 9) + (mt_rand(-5, 5) / 100);
    if ($lead > 3) {
        $target *= max(0.35, 1 - ($lead - 3) / 60);
    }
    $arrivals = (int) round($target * $total / 2.8);
    for ($k = 0, $tries = 0; $k < $arrivals && $tries < 60; $tries++) {
        $tn = pick($typeW);
        $tid = $types[$tn]['id'];
        $len = pick($lenW);
        $qty = chance(0.08) ? 2 : 1;
        $ns = [];
        for ($i = 0; $i < $len; $i++) {
            $ns[] = addDays($ci, $i);
        }
        $ok = true;
        foreach ($ns as $n) {
            if ($used($tid, $n) + $qty > $types[$tn]['cap'] || $used($tid, $n) + $qty > $types[$tn]['cap'] * min(0.97, $target + 0.30)) {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }
        foreach ($ns as $n) {
            $cnt[$tid][$n]['conf'] += $qty;
        }
        $plan[] = ['tn' => $tn, 'tid' => $tid, 'ci' => $ci, 'co' => addDays($ci, $len), 'len' => $len, 'qty' => $qty, 'nights' => $ns];
        $k++;
    }
}

// one-time guests get their own unique name combinations, so they never look like duplicates of each other
$combos = [];
foreach ($first as $fi => $fn) {
    foreach ($last as $li => $ln) {
        if (($fi * 7 + 3) % 40 !== $li) {
            $combos[] = [$fn, $ln];
        }
    }
}
for ($i = count($combos) - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$combos[$i], $combos[$j]] = [$combos[$j], $combos[$i]];
}
$oneTime = [];
$newGuest = function () use (&$combos, &$oneTime, $domains, $today): array {
    [$fn, $ln] = array_pop($combos);
    $n = count($oneTime) + 1;
    $g = ['id' => Uuid::v4(), 'name' => "$fn $ln", 'email' => strtolower(str_replace(' ', '', $fn . '.' . $ln)) . $n . '@' . $domains[$n % 3], 'phone' => sprintf('+63 9%02d %03d %04d', mt_rand(10, 99), mt_rand(100, 999), $n + 1000), 'account' => null, 'created' => $today];
    $oneTime[] = $g;
    return $g;
};
// channel, guest, status
$partners = ['baybook', 'tripwise', 'islandhop'];
foreach ($plan as $i => &$r) {
    $r['id'] = Uuid::v4();
    $x = mt_rand(1, 100);
    $r['source'] = $x <= 62 ? 'DIRECT' : ($x <= 75 ? 'WALK_IN' : 'OTA');
    if ($r['source'] === 'OTA' && $r['tn'] === 'Suite') {
        $r['source'] = 'DIRECT';
    }
    // repeat guests (the named sample guests) book about a third of the direct stays; everyone else is a one-time guest
    $r['guest'] = ($r['source'] === 'DIRECT' && chance(0.35)) ? $guests[min($nGuests - 1, (int) floor(pow(mt_rand() / mt_getrandmax(), 1.6) * $nGuests))] : $newGuest();
    $r['adults'] = min($r['tn'] === 'Standard' ? 2 : ($r['tn'] === 'Deluxe' ? 3 : 4), 1 + (chance(0.55) ? 1 : 0) + (chance(0.1) ? 1 : 0)) * 1;
    $lead = $r['source'] === 'WALK_IN' ? 0 : mt_rand(1, 45);
    $r['created'] = utc(addDays($r['ci'], -$lead), mt_rand(7, 22), mt_rand(0, 59), mt_rand(0, 59));
    if ($r['source'] === 'WALK_IN') {
        $r['created'] = utc($r['ci'], mt_rand(10, 17), mt_rand(0, 59));
    }
    $r['code'] = Booking\BookingRepository::newCode();
    $r['quote'] = $types[$r['tn']]['rate'] * $r['qty'] * $r['len'];
    $r['guarantee'] = $r['source'] === 'OTA' ? 'OTA_PREPAID' : (chance(0.06) && $r['source'] === 'DIRECT' ? 'PAY_AT_HOTEL' : 'PAID');
    $r['partner'] = pick($partners);
    $r['cancelled'] = false;
    if ($r['co'] < $today) {
        $y = mt_rand(1, 100);
        $r['status'] = $y <= 90 ? 'CHECKED_OUT' : ($y <= 96 ? 'CANCELLED' : 'NO_SHOW');
    } elseif ($r['co'] === $today) {
        $r['status'] = chance(0.2) ? 'CANCELLED' : 'CHECKED_IN';
    } elseif ($r['ci'] <= $today) {
        $r['status'] = $r['ci'] === $today && chance(0.4) ? 'CONFIRMED' : 'CHECKED_IN';
    } else {
        $r['status'] = chance(0.07) ? 'CANCELLED' : 'CONFIRMED';
    }
    if (in_array($r['status'], ['CANCELLED', 'NO_SHOW'], true)) {
        foreach ($r['nights'] as $n) {   // these nights are sold again, so free the counters
            $cnt[$r['tid']][$n]['conf'] -= $r['qty'];
        }
    }
}
unset($r);
// a few of today's departures were already checked out this morning, so the housekeeping board has real work
$todayOut = array_keys(array_filter($plan, fn ($p) => $p['co'] === $today && $p['status'] === 'CHECKED_IN'));
foreach (array_slice($todayOut, 0, 4) as $i => $pi) {
    $plan[$pi]['status'] = 'CHECKED_OUT';
    $plan[$pi]['boardState'] = ['OPEN', 'OPEN', 'IN_PROGRESS', 'CLEANED'][$i];
}
// make sure the front desk has arrivals today
$haveArr = count(array_filter($plan, fn ($p) => $p['ci'] === $today && $p['status'] === 'CONFIRMED'));
foreach ($plan as $pi => $p) {
    if ($haveArr >= 6) {
        break;
    }
    if ($p['ci'] === $today && $p['status'] === 'CHECKED_IN' && $p['co'] > $today) {
        $plan[$pi]['status'] = 'CONFIRMED';
        $haveArr++;
    }
}

// ------------------------------------------------------------------------------------------------ OTA allotments (one per partner and room type)
$allot = [];
foreach ($plan as $p) {
    if ($p['source'] === 'OTA' && !in_array($p['status'], ['CANCELLED', 'NO_SHOW'], true)) {
        $k = $p['partner'] . '|' . $p['tid'];
        foreach ($p['nights'] as $n) {
            $allot[$k]['used'][$n] = ($allot[$k]['used'][$n] ?? 0) + $p['qty'];
        }
    }
}
$allotId = [];
foreach ($allot as $k => $a) {
    [$pid, $tid] = explode('|', $k);
    $id = Uuid::v4();
    $allotId[$k] = $id;
    $q = max($a['used']) + 1;
    ins($B, 'ota_allotment', ['allotment_id' => $id, 'partner_id' => $pid, 'room_type_id' => $tid, 'start_date' => $start, 'end_date' => addDays($end, 6), 'quantity' => $q, 'status' => 'APPROVED', 'requested_by' => $staffId('reception'), 'decided_by' => $staffId('manager'), 'decided_at' => utc(addDays($start, -2), 12), 'created_at' => utc(addDays($start, -3), 15)]);
    foreach ($a['used'] as $n => $u) {
        $left = 0;
        if ($n >= $today) {   // past nights are settled, future nights keep one spare room for the partner when capacity allows
            $room = $types[$typeOf[$tid]]['cap'] - $used($tid, $n);
            $left = max(0, min(1, $room));
            $cnt[$tid][$n]['ota'] += $left;
        }
        ins($B, 'ota_allotment_night', ['allotment_id' => $id, 'stay_date' => $n, 'reserved' => $u + $left, 'used' => $u]);
    }
}
$pendingId = Uuid::v4();
ins($B, 'ota_allotment', ['allotment_id' => $pendingId, 'partner_id' => 'islandhop', 'room_type_id' => $types['Deluxe']['id'], 'start_date' => addDays($today, 20), 'end_date' => addDays($today, 27), 'quantity' => 3, 'status' => 'PENDING', 'requested_by' => $acct['reception@example.test'], 'created_at' => utc(addDays($today, -1), 16)]);

// ------------------------------------------------------------------------------------------------ write booking data
foreach ($types as $t) {
    foreach ($nightsSpan as $n) {
        $c = $cnt[$t['id']][$n];
        ins($B, 'inventory_night', ['room_type_id' => $t['id'], 'stay_date' => $n, 'capacity' => $t['cap'], 'blocked' => $c['blocked'], 'held' => 0, 'confirmed' => $c['conf'], 'ota_remaining' => $c['ota']]);
    }
}
$firstSeen = [];
foreach ($plan as $p) {
    $firstSeen[$p['guest']['id']] = min($firstSeen[$p['guest']['id']] ?? $p['created'], $p['created']);
}
foreach ($oneTime as $g) {
    ins($B, 'guest', ['guest_id' => $g['id'], 'account_id' => null, 'full_name' => $g['name'], 'email' => $g['email'], 'phone' => $g['phone'], 'created_at' => $firstSeen[$g['id']] ?? utc($today, 11)]);
}
$otaSeq = 1000;
$withMoney = [];   // reservations with payments, for the billing step
foreach ($plan as &$r) {
    $st = $r['status'];
    $created = $r['created'];
    $paidAt = date('Y-m-d H:i:s', strtotime($created . ' UTC') + mt_rand(90, 320));
    $isPaid = $r['guarantee'] === 'PAID';
    $r['paidAt'] = $isPaid ? $paidAt : null;
    $confirmedAt = $r['source'] === 'OTA' ? $created : ($isPaid ? date('Y-m-d H:i:s', strtotime($paidAt . ' UTC') + mt_rand(2, 25)) : date('Y-m-d H:i:s', strtotime($created . ' UTC') + 600));
    $penalty = 0;
    $cancelledAt = null;
    if ($st === 'CANCELLED') {
        $before = mt_rand(1, 100) <= 70 ? mt_rand(3, 20) : 1;
        $cancelledAt = utc(addDays($r['ci'], -$before), mt_rand(8, 21), mt_rand(0, 59));
        if (strtotime($cancelledAt . ' UTC') < strtotime($confirmedAt . ' UTC')) {
            $cancelledAt = date('Y-m-d H:i:s', strtotime($confirmedAt . ' UTC') + 3600);
        }
        $penalty = (strtotime(utc($r['ci'], 14) . ' UTC') - strtotime($cancelledAt . ' UTC') >= 48 * 3600) ? 0 : $types[$r['tn']]['rate'] * $r['qty'];
        $r['cancelledAt'] = $cancelledAt;
    }
    $r['penalty'] = $penalty;
    $stateAlloc = in_array($st, ['CANCELLED', 'NO_SHOW'], true) ? 'RELEASED' : 'CONFIRMED';
    ins($B, 'reservation', [
        'reservation_id' => $r['id'], 'code' => $r['code'], 'guest_id' => $r['guest']['id'], 'room_type_id' => $r['tid'], 'check_in' => $r['ci'], 'check_out' => $r['co'], 'quantity' => $r['qty'], 'status' => $st, 'guarantee' => $r['guarantee'],
        'quote_minor' => $r['quote'], 'nightly_minor' => $types[$r['tn']]['rate'], 'currency' => 'PHP', 'free_cancel_hours' => 48, 'hold_expires_at' => date('Y-m-d H:i:s', strtotime($created . ' UTC') + 600), 'version' => 4,
        'created_at' => $created, 'updated_at' => $cancelledAt ?? $confirmedAt, 'source' => $r['source'], 'adults' => $r['adults'], 'special_requests' => chance(0.18) ? pick(['Late arrival after 9 PM', 'High floor please', 'Quiet room away from the elevator', 'Extra pillows', 'Celebrating an anniversary', 'Baby cot needed']) : null,
        'paid_at' => $r['paidAt'], 'confirmed_at' => $confirmedAt, 'cancelled_at' => $cancelledAt, 'no_show_at' => $st === 'NO_SHOW' ? utc(addDays($r['ci'], 0), 22) : null, 'penalty_minor' => $st === 'NO_SHOW' ? $types[$r['tn']]['rate'] * $r['qty'] : $penalty,
    ]);
    foreach ($r['nights'] as $n) {
        ins($B, 'allocation', ['reservation_id' => $r['id'], 'stay_date' => $n, 'room_type_id' => $r['tid'], 'quantity' => $r['qty'], 'state' => $stateAlloc]);
    }
    if ($r['source'] === 'OTA') {
        $k = $r['partner'] . '|' . $r['tid'];
        ins($B, 'ota_booking', ['partner_id' => $r['partner'], 'external_booking_id' => strtoupper(substr($r['partner'], 0, 2)) . '-' . (++$otaSeq), 'reservation_id' => $r['id'], 'allotment_ref' => $allotId[$k] ?? null]);
    }
}
unset($r);
// a sample of OTA import runs for the history list
foreach ($partners as $pi => $pid) {
    ins($B, 'ota_import', ['import_id' => Uuid::v4(), 'partner_id' => $pid, 'filename' => $pid . '-' . addDays($today, -7 * ($pi + 1)) . '.csv', 'rows_total' => 24 + $pi * 3, 'rows_ok' => 22 + $pi * 3, 'rows_rejected' => 2, 'report' => json_encode([['line' => 5, 'result' => 'REJECTED', 'reason' => 'No reserved allotment left for these dates and room type']]), 'imported_by' => $staffId('reception'), 'created_at' => utc(addDays($today, -7 * ($pi + 1)), 10)]);
}
// one historic guest merge
ins($B, 'guest_merge', ['merge_id' => Uuid::v4(), 'kept_guest_id' => $guests[2]['id'], 'merged_guest_id' => Uuid::v4(), 'reservations_moved' => 1, 'merged_by' => $acct['steward@example.test'], 'note' => 'Same guest booked twice with a typo in the email', 'created_at' => utc(addDays($today, -20), 11)]);

// ------------------------------------------------------------------------------------------------ room operations
$freeRoom = function (array $r) use (&$busy, $rooms): array {
    $chosen = [];
    foreach ($rooms as $rm) {
        if ($rm['type'] !== $r['tid']) {
            continue;
        }
        $clash = false;
        foreach ($busy[$rm['id']] ?? [] as [$s, $e]) {
            if ($s < $r['co'] && $e > $r['ci']) {
                $clash = true;
                break;
            }
        }
        if (!$clash) {
            $chosen[] = $rm;
        }
        if (count($chosen) === $r['qty']) {
            break;
        }
    }
    return $chosen;
};
$boardState = [];
$occupied = [];
$doc = ['PASSPORT', 'DRIVERS_LICENSE', 'NATIONAL_ID', 'NATIONAL_ID', 'DRIVERS_LICENSE'];
foreach ($plan as $r) {
    $st = $r['status'];
    ins($R, 'arrival', ['reservation_id' => $r['id'], 'room_type_id' => $r['tid'], 'check_in' => $r['ci'], 'check_out' => $r['co'], 'quantity' => $r['qty'], 'status' => match ($st) { 'CONFIRMED' => 'EXPECTED', 'CANCELLED' => 'CANCELLED', 'NO_SHOW' => 'NO_SHOW', default => 'ARRIVED' }]);
    if (!in_array($st, ['CHECKED_IN', 'CHECKED_OUT'], true)) {
        continue;
    }
    $chosen = $freeRoom($r);
    if (count($chosen) < $r['qty']) {
        fwrite(STDERR, "warning: no free room for {$r['code']}\n");
        continue;
    }
    $stayId = Uuid::v4();
    $in = utc($r['ci'], mt_rand(14, 19), mt_rand(0, 59));
    $out = $st === 'CHECKED_OUT' ? utc($r['co'], mt_rand(8, 11), mt_rand(0, 59)) : null;
    ins($R, 'stay', ['stay_id' => $stayId, 'reservation_id' => $r['id'], 'check_in' => $r['ci'], 'check_out' => $r['co'], 'actual_check_in' => $in, 'actual_check_out' => $out, 'identity_verified_by' => $staffId('reception'), 'identity_doc_type' => pick($doc),
        'queue_started_at' => date('Y-m-d H:i:s', strtotime($in . ' UTC') - mt_rand(80, 540))]);
    foreach ($chosen as $rm) {
        $busy[$rm['id']][] = [$r['ci'], isset($r['boardState']) ? addDays($r['co'], 1) : $r['co']];   // a room still being cleaned is not resold today
        ins($R, 'room_assignment', ['assignment_id' => Uuid::v4(), 'stay_id' => $stayId, 'room_id' => $rm['id'], 'start_date' => $r['ci'], 'end_date' => $r['co'], 'active' => $st === 'CHECKED_IN' ? 1 : 0]);
        if ($st === 'CHECKED_IN') {
            $occupied[$rm['id']] = true;
        }
        if ($st === 'CHECKED_OUT') {
            $state = $r['boardState'] ?? 'DONE';
            $created = $out;
            $cleaner = $staffId('housekeeping');
            $cleaned = date('Y-m-d H:i:s', strtotime($created . ' UTC') + mt_rand(20, 65) * 60);
            $done = date('Y-m-d H:i:s', strtotime($cleaned . ' UTC') + mt_rand(4, 18) * 60);
            ins($R, 'cleaning_task', ['task_id' => Uuid::v4(), 'room_id' => $rm['id'], 'assignee' => $state === 'OPEN' ? null : $cleaner, 'status' => $state, 'created_at' => $created,
                'cleaned_at' => in_array($state, ['DONE', 'CLEANED'], true) ? $cleaned : null, 'completed_at' => $state === 'DONE' ? $done : null, 'inspected_by' => $state === 'DONE' ? ($cleaner === $acct['housekeeping@example.test'] ? $acct['housekeeping2@example.test'] : $acct['housekeeping@example.test']) : null]);
            if ($state !== 'DONE') {
                $boardState[$rm['id']] = $state === 'OPEN' ? 'DIRTY' : 'CLEANING';
            }
        }
    }
    $r['stayId'] = $stayId;
}
// a maintenance request waiting in the manager's queue: a guest is staying in the room
$inHouseRoom = array_key_first($occupied);
ins($R, 'maintenance_block', ['block_id' => Uuid::v4(), 'room_id' => $inHouseRoom, 'start_date' => $today, 'end_date' => addDays($today, 2), 'status' => 'CONFLICT', 'reason' => 'Ceiling water stain, needs inspection', 'request_id' => Uuid::v4(), 'requested_by' => $acct['housekeeping@example.test'], 'created_at' => utc($today, 9)]);
foreach ($rooms as $rm) {
    $state = isset($occupied[$rm['id']]) ? 'OCCUPIED' : ($boardState[$rm['id']] ?? ($rm['no'] === '204' ? 'OUT_OF_SERVICE' : 'READY'));
    $R->prepare('UPDATE room SET readiness = ?, version = ? WHERE room_id = ?')->execute([$state, mt_rand(3, 40), $rm['id']]);
}
// an early-arrival request waiting for the manager
$future = array_values(array_filter($plan, fn ($p) => $p['status'] === 'CONFIRMED' && $p['ci'] > $today && $p['ci'] <= addDays($today, 3)));
if ($future) {
    ins($R, 'arrival_override', ['reservation_id' => $future[0]['id'], 'requested_by' => $acct['reception@example.test'], 'reason' => 'Guest lands at 6 AM and asked for an early room', 'status' => 'PENDING', 'created_at' => utc($today, 12)]);
}

// ------------------------------------------------------------------------------------------------ billing
$methodsOnline = ['CARD', 'CARD', 'CARD', 'GCASH', 'GCASH', 'MAYA'];
$extras = [['Minibar', 35000, 90000], ['Laundry', 45000, 120000], ['Restaurant', 60000, 280000], ['Parking', 30000, 60000], ['Airport shuttle', 120000, 120000], ['Spa treatment', 150000, 320000]];
$bizDayAfter = function (string $ymd): string {
    $d = new DateTimeImmutable($ymd);
    do {
        $d = $d->modify('+1 day');
    } while ((int) $d->format('N') >= 6);
    return $d->format('Y-m-d');
};
$recon = function (string $paidUtc) use ($today, $bizDayAfter, $acct): array {
    $day = (new DateTimeImmutable($paidUtc . ' UTC'))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
    if ($day >= addDays($today, -1)) {
        return ['reconciled_at' => null, 'reconciled_by' => null];
    }
    $late = chance(0.04);
    $when = utc($late ? addDays($bizDayAfter($bizDayAfter($day)), 0) : $bizDayAfter($day), $late ? 15 : mt_rand(9, 16), mt_rand(0, 59));
    return ['reconciled_at' => strtotime($when . ' UTC') > time() ? null : $when, 'reconciled_by' => $acct['cashier@example.test']];
};
$refundCount = 0;
foreach ($plan as $r) {
    $st = $r['status'];
    $rid = $r['id'];
    $payId = null;
    $payment = function (string $provider, ?string $method, string $ref, int $amount, string $at, ?string $folio) use ($L, $rid, $recon, &$payId): string {
        $pid = Uuid::v4();
        ins($L, 'payment', ['payment_id' => $pid, 'reservation_id' => $rid, 'folio_id' => $folio, 'provider' => $provider, 'method' => $method, 'provider_payment_id' => $ref, 'provider_event_id' => $provider === 'PAYMONGO' ? 'evt_' . bin2hex(random_bytes(8)) : null,
            'amount_minor' => $amount, 'currency' => 'PHP', 'status' => 'CAPTURED', 'created_at' => $at] + $recon($at));
        return $pid;
    };
    $folioId = null;
    $needFolio = in_array($st, ['CHECKED_IN', 'CHECKED_OUT'], true);
    if ($needFolio) {
        $folioId = Uuid::v4();
    }
    // payment before arrival
    if ($r['guarantee'] === 'PAID' && !in_array($st, ['NO_SHOW'], true) || ($r['guarantee'] === 'PAID' && $st === 'NO_SHOW')) {
        if ($r['source'] === 'DIRECT') {
            $sid = 'cs_' . bin2hex(random_bytes(8));
            ins($L, 'checkout_session', ['session_id' => $sid, 'reservation_id' => $rid, 'amount_minor' => $r['quote'], 'currency' => 'PHP', 'status' => 'PAID', 'checkout_url' => 'https://checkout.example.invalid/' . $sid, 'created_at' => date('Y-m-d H:i:s', strtotime($r['paidAt'] . ' UTC') - 70)]);
            $payId = $payment('PAYMONGO', pick($methodsOnline), 'pay_' . bin2hex(random_bytes(8)), $r['quote'], $r['paidAt'], null);
        } else {
            $payId = $payment('ONSITE', pick(['CASH', 'CASH', 'CARD_TERMINAL', 'GCASH']), 'onsite-' . Uuid::v4(), $r['quote'], $r['paidAt'], null);
        }
    }
    if ($needFolio) {
        $in = utc($r['ci'], 15);
        $extraLines = [];
        if (chance(0.38)) {
            for ($e = mt_rand(1, 3); $e > 0; $e--) {
                [$d, $lo, $hi] = pick($extras);
                $extraLines[] = [$d, (int) (round(mt_rand($lo, $hi) / 5000) * 5000)];
            }
        }
        $extraSum = array_sum(array_column($extraLines, 1));
        ins($L, 'folio', ['folio_id' => $folioId, 'reservation_id' => $rid, 'status' => $st === 'CHECKED_OUT' ? 'CLOSED' : 'OPEN', 'total_minor' => $r['quote'] + $extraSum, 'currency' => 'PHP', 'created_at' => $in]);
        ins($L, 'charge', ['charge_id' => Uuid::v4(), 'folio_id' => $folioId, 'description' => 'Room charge', 'amount_minor' => $r['quote'], 'currency' => 'PHP', 'created_by' => 'system', 'created_at' => $in]);
        foreach ($extraLines as $i => [$desc, $amt]) {
            ins($L, 'charge', ['charge_id' => Uuid::v4(), 'folio_id' => $folioId, 'description' => $desc, 'amount_minor' => $amt, 'currency' => 'PHP', 'created_by' => $acct['cashier@example.test'], 'created_at' => date('Y-m-d H:i:s', strtotime($in . ' UTC') + 3600 * (3 + 6 * $i))]);
        }
        if ($payId) {
            $L->prepare('UPDATE payment SET folio_id = ? WHERE payment_id = ?')->execute([$folioId, $payId]);
        }
        if ($r['source'] === 'OTA') {
            $payment('OTA', 'PARTNER', 'ota-' . $rid, $r['quote'], $in, $folioId);
        }
        $owed = ($r['guarantee'] === 'PAY_AT_HOTEL' ? $r['quote'] : 0) + $extraSum;
        if ($st === 'CHECKED_OUT' && $owed > 0) {
            $payment('ONSITE', pick(['CASH', 'CARD_TERMINAL', 'CARD_TERMINAL', 'GCASH']), 'onsite-' . Uuid::v4(), $owed, utc($r['co'], mt_rand(8, 11), mt_rand(0, 59)), $folioId);
        }
    }
    // refunds for cancelled bookings that had paid
    if ($st === 'CANCELLED' && $payId !== null) {
        $amt = $r['quote'] - $r['penalty'];
        if ($amt > 0) {
            $refundCount++;
            $at = date('Y-m-d H:i:s', strtotime($r['cancelledAt'] . ' UTC') + 60);
            $recent = $r['cancelledAt'] >= utc(addDays($today, -2), 0);
            $rej = $refundCount === 4;
            ins($L, 'refund', ['refund_id' => Uuid::v4(), 'payment_id' => $payId, 'amount_minor' => $amt, 'approval' => $rej ? 'REJECTED' : ($recent ? 'PENDING' : 'APPROVED'), 'status' => $rej ? 'REJECTED' : ($recent ? 'REQUESTED' : 'COMPLETED'), 'requested_by' => 'system', 'approved_by' => $recent && !$rej ? null : $staffId('manager'),
                'reason' => 'CANCELLATION', 'created_at' => $at, 'completed_at' => $recent || $rej ? null : date('Y-m-d H:i:s', strtotime($at . ' UTC') + mt_rand(3600, 86400)), 'decision_note' => $rej ? 'Duplicate of an earlier refund already sent to the guest.' : null]);
        }
    }
    if ($st === 'NO_SHOW' && $payId !== null) {
        $amt = $r['quote'] - $types[$r['tn']]['rate'] * $r['qty'];
        if ($amt > 0) {
            ins($L, 'refund', ['refund_id' => Uuid::v4(), 'payment_id' => $payId, 'amount_minor' => $amt, 'approval' => 'APPROVED', 'status' => 'COMPLETED', 'requested_by' => 'system', 'approved_by' => $staffId('manager'), 'reason' => 'NO_SHOW', 'created_at' => utc($r['ci'], 23), 'completed_at' => utc(addDays($r['ci'], 1), 11)]);
        }
    }
}
// a cashier-requested goodwill refund for the manager (second manager can approve it)
$onePay = $L->query("SELECT TOP (1) p.payment_id, p.amount_minor FROM payment p LEFT JOIN refund r ON r.payment_id = p.payment_id WHERE p.status = 'CAPTURED' AND p.provider = 'PAYMONGO' AND r.refund_id IS NULL ORDER BY p.created_at DESC")->fetch();
if ($onePay) {
    ins($L, 'refund', ['refund_id' => Uuid::v4(), 'payment_id' => $onePay['payment_id'], 'amount_minor' => 100000, 'approval' => 'PENDING', 'status' => 'REQUESTED', 'requested_by' => $acct['cashier@example.test'], 'reason' => 'SERVICE_ISSUE', 'created_at' => utc($today, 11)]);
}
// accounting exports: one approved last week, one prepared and waiting for approval
$fin = new Billing\FinanceService($L);
foreach ([[addDays($today, -14), addDays($today, -8), true], [addDays($today, -7), addDays($today, -1), false]] as [$f, $t, $approved]) {
    [, $x] = $fin->requestExport($f, $t, ['accountId' => $acct['cashier@example.test'], 'role' => 'cashier'], null);
    if ($approved) {
        $L->prepare("UPDATE accounting_export SET status = 'APPROVED', approved_by = ?, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME()), downloads = 1 WHERE export_id = ?")->execute([$acct['manager@example.test'], $x['exportId']]);
    }
}

// ------------------------------------------------------------------------------------------------ portal data
$daily = [];
foreach ($plan as $r) {
    $d = substr($r['created'], 0, 10);
    if ($d < addDays($today, -30) || $d > $today) {
        continue;
    }
    $daily[$d]['confirmed'] = ($daily[$d]['confirmed'] ?? 0) + (in_array($r['status'], ['CANCELLED'], true) ? 0 : 1);
    $daily[$d]['revenue'] = ($daily[$d]['revenue'] ?? 0) + ($r['guarantee'] === 'PAID' ? $r['quote'] : 0);
    $daily[$d]['cancelled'] = ($daily[$d]['cancelled'] ?? 0) + ($r['status'] === 'CANCELLED' ? 1 : 0);
}
foreach ($daily as $d => $v) {
    ins($P, 'report_daily', ['day' => $d, 'confirmed' => $v['confirmed'], 'cancelled' => $v['cancelled'], 'revenue_minor' => $v['revenue'], 'refunded_minor' => 0, 'check_ins' => $v['confirmed'], 'check_outs' => $v['confirmed'], 'updated_at' => utc($d, 23)]);
}
// recent confirmation emails (logged, since no mail key is set in the demo)
$recent = array_values(array_filter($plan, fn ($p) => $p['source'] === 'DIRECT' && $p['status'] !== 'CANCELLED' && $p['created'] >= utc(addDays($today, -10), 0)));
foreach (array_slice($recent, 0, 25) as $r) {
    $view = ['code' => $r['code'], 'roomTypeName' => $r['tn'], 'quantity' => $r['qty'], 'checkIn' => $r['ci'], 'checkOut' => $r['co'], 'amountMinor' => $r['quote'], 'guarantee' => $r['guarantee'], 'reservationId' => $r['id'], 'guestName' => $r['guest']['name'], 'freeCancelHours' => 48];
    [$s, $h] = Portal\Emails::bookingConfirmation($view);
    ins($P, 'email_log', ['email_id' => Uuid::v4(), 'template' => 'booking_confirmation', 'to_email' => $r['guest']['email'], 'subject' => $s, 'body_html' => $h, 'status' => 'LOGGED', 'created_at' => date('Y-m-d H:i:s', strtotime($r['created'] . ' UTC') + 420)]);
}
foreach ([['OPEN', 'ACCESS', 'I would like a copy of the information you hold about me.', 2], ['IN_REVIEW', 'CORRECTION', json_encode(['fullName' => 'Maribel R. Fontanilla', 'email' => $guests[0]['email'], 'phone' => $guests[0]['phone'], 'note' => 'Please add my middle initial.']), 9]] as $i => [$status, $type, $details, $ago]) {
    $gi = $i === 0 ? 3 : 0;
    ins($P, 'privacy_request', ['request_id' => Uuid::v4(), 'account_id' => $guests[$gi]['account'], 'type' => $type, 'details' => $details, 'status' => $status, 'handled_by' => $status === 'IN_REVIEW' ? $acct['it@example.test'] : null, 'due_at' => utc(addDays($today, 30 - $ago), 12), 'created_at' => utc(addDays($today, -$ago), 14)]);
}
ins($P, 'privacy_request', ['request_id' => Uuid::v4(), 'account_id' => $guests[4]['account'], 'type' => 'ACCESS', 'details' => '', 'status' => 'COMPLETED', 'handled_by' => $acct['it@example.test'], 'resolution_note' => 'Your data file is ready to download.', 'result' => json_encode(['generatedAt' => gmdate('c'), 'note' => 'Sample data file from the demo.']), 'due_at' => utc(addDays($today, 10), 12), 'created_at' => utc(addDays($today, -20), 9), 'completed_at' => utc(addDays($today, -17), 10)]);

// ------------------------------------------------------------------------------------------------ audit trails (sampled, consistent with the data above)
$audit = function (PDO $pdo, string $at, string $actor, string $role, string $action, string $tt, string $tid, ?array $detail = null) {
    ins($pdo, 'audit_log', ['at' => $at . '.000', 'actor_id' => $actor, 'actor_role' => $role, 'action' => $action, 'target_type' => $tt, 'target_id' => $tid, 'detail' => $detail ? json_encode($detail) : null, 'ip' => '10.0.0.' . mt_rand(2, 40)]);
};
foreach ($staffDefs as [$email, $role, , ]) {
    if ($role !== 'guest') {
        $audit($P, utc(addDays($today, -120), 9, 5), $acct['it@example.test'], 'it', 'user.created', 'account', $acct[$email], ['role' => $role]);
        for ($d = 0; $d < 12; $d++) {
            if (chance(0.7)) {
                $audit($P, utc(addDays($today, -$d), mt_rand(7, 9), mt_rand(0, 59), mt_rand(0, 59)), $acct[$email], $role, 'auth.login', 'account', $acct[$email]);
            }
        }
    }
}
$audit($P, utc(addDays($today, -3), 15), $acct['steward@example.test'], 'steward', 'auth.login_failed', 'account', $acct['steward@example.test'], ['why' => 'otp']);
foreach ($plan as $r) {
    if (empty($r['stayId'])) {
        continue;
    }
    if ($r['ci'] >= addDays($today, -30)) {
        $audit($R, utc($r['ci'], mt_rand(14, 19), mt_rand(0, 59)), $staffId('reception'), 'reception', 'stay.check_in', 'reservation', $r['id'], ['stayId' => $r['stayId']]);
        if ($r['status'] === 'CHECKED_OUT') {
            $audit($R, utc($r['co'], mt_rand(8, 11), mt_rand(0, 59)), $staffId('reception'), 'reception', 'stay.check_out', 'reservation', $r['id'], ['stayId' => $r['stayId']]);
        }
    }
}
foreach ($plan as $r) {
    if ($r['status'] === 'CANCELLED' && ($r['cancelledAt'] ?? '') >= utc(addDays($today, -30), 0)) {
        $audit($B, $r['cancelledAt'], $r['guest']['account'] ?? $acct['guest@example.test'], 'guest', 'reservation.cancel', 'reservation', $r['id'], ['code' => $r['code'], 'penaltyMinor' => $r['penalty']]);
    }
    if ($r['source'] === 'DIRECT' && $r['created'] >= utc(addDays($today, -10), 0)) {
        $audit($B, $r['created'], $r['guest']['account'] ?? $acct['guest@example.test'], 'guest', 'reservation.hold', 'reservation', $r['id'], ['checkIn' => $r['ci'], 'checkOut' => $r['co']]);
    }
}
foreach ($partners as $pi => $pid) {
    $audit($B, utc(addDays($today, -7 * ($pi + 1)), 10, 3), $staffId('reception'), 'reception', 'ota.import', 'ota_import', Uuid::v4(), ['partner' => $pid]);
}
$audit($B, utc(addDays($today, -20), 11), $acct['steward@example.test'], 'steward', 'guest.merge', 'guest', $guests[2]['id'], ['reservationsMoved' => 1]);
$audit($B, utc(addDays($today, -30), 10), $acct['manager@example.test'], 'manager', 'roomtype.update', 'room_type', $types['Deluxe']['id'], ['before' => ['nightlyMinor' => 520000], 'after' => ['nightlyMinor' => 560000]]);
foreach ($L->query("SELECT TOP (12) refund_id, amount_minor, completed_at FROM refund WHERE status = 'COMPLETED' ORDER BY created_at DESC")->fetchAll() as $rf) {
    $audit($L, (string) $rf['completed_at'], $acct['manager@example.test'], 'manager', 'refund.approved', 'refund', $rf['refund_id'], ['amountMinor' => (int) $rf['amount_minor']]);
}
foreach ($L->query('SELECT export_id, batch_ref, status FROM accounting_export')->fetchAll() as $ex) {
    $audit($L, utc(addDays($today, -1), 9), $acct['cashier@example.test'], 'cashier', 'export.requested', 'export', $ex['export_id'], ['batch' => $ex['batch_ref']]);
    if ($ex['status'] === 'APPROVED') {
        $audit($L, utc(addDays($today, -1), 10), $acct['manager@example.test'], 'manager', 'export.approved', 'export', $ex['export_id'], ['batch' => $ex['batch_ref']]);
    }
}
$audit($R, utc(addDays($today, -2), 9), $acct['maintenance@example.test'], 'maintenance', 'maintenance.requested', 'room', $roomByNo['204']['id'], ['status' => 'PENDING']);
$audit($R, utc(addDays($today, -2), 10), $acct['manager@example.test'], 'manager', 'maintenance.approved', 'room', $roomByNo['204']['id']);

$counts = [
    'guests' => (int) $B->query('SELECT COUNT(*) FROM guest')->fetchColumn(), 'reservations' => (int) $B->query('SELECT COUNT(*) FROM reservation')->fetchColumn(),
    'payments' => (int) $L->query('SELECT COUNT(*) FROM payment')->fetchColumn(), 'stays' => (int) $R->query('SELECT COUNT(*) FROM stay')->fetchColumn(), 'cleaning_tasks' => (int) $R->query('SELECT COUNT(*) FROM cleaning_task')->fetchColumn(),
    'refunds' => (int) $L->query('SELECT COUNT(*) FROM refund')->fetchColumn(), 'accounts' => (int) $P->query('SELECT COUNT(*) FROM account')->fetchColumn(),
];
echo 'seeded ' . json_encode($counts) . "\n";
