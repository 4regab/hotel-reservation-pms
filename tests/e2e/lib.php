<?php
declare(strict_types=1);

// Tiny HTTP test client with a cookie jar, plus assertion helpers. Used by the e2e, concurrency and webhook tests.
// Needs the stack running (scripts/dev-stack.sh start, or docker compose up).
require dirname(__DIR__, 2) . '/autoload.php';

final class Client
{
    private array $cookies = [];
    public string $csrf = '';

    public function __construct(private string $base)
    {
    }

    /** @return array{0:int,1:array|string,2:array} status, decoded JSON (or raw text), response headers */
    public function req(string $method, string $path, ?array $json = null, array $headers = [], ?string $raw = null, bool $form = false): array
    {
        $h = $headers;
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        if ($this->csrf !== '' && $method !== 'GET') {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $body = $raw;
        if ($json !== null) {
            $body = json_encode($json);
            $h[] = 'Content-Type: application/json';
        }
        if ($form) {
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0]]);
        $text = @file_get_contents($this->base . $path, false, $ctx);
        $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($lines[0]) && preg_match('#\s(\d{3})#', $lines[0], $m) ? (int) $m[1] : 0;
        $resp = [];
        foreach ($lines as $l) {
            if (stripos($l, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $l, $m)) {
                $this->cookies[$m[1]] = $m[2];
            } elseif (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $resp[strtolower(trim($k))] = trim($v);
            }
        }
        $data = json_decode((string) $text, true);
        return [$status, is_array($data) ? $data : (string) $text, $resp];
    }

    public function get(string $p): array
    {
        return $this->req('GET', $p);
    }

    public function post(string $p, array $b = [], ?string $idem = null): array
    {
        return $this->req('POST', $p, $b, $idem ? ['Idempotency-Key: ' . $idem] : []);
    }

    public function login(string $email, string $password = 'harborlight-demo', ?string $totpSecret = null): void
    {
        $b = ['email' => $email, 'password' => $password];
        if ($totpSecret !== null) {
            $b['otp'] = Portal\Totp::code($totpSecret, time());
        }
        [$s, $d] = $this->post('/v1/login', $b);
        check($s === 200, "login {$email}: " . json_encode($d));
        $this->csrf = $d['csrfToken'];
    }
}

function base(): string
{
    return rtrim(getenv('E2E_BASE') ?: 'http://127.0.0.1:8100', '/');
}

function staff(string $role): Client
{
    $c = new Client(base());
    $c->login("{$role}@example.test", getenv('DEMO_PASSWORD') ?: 'harborlight-demo', getenv('DEMO_TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP');
    return $c;
}

$GLOBALS['passed'] = 0;
function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
    $GLOBALS['passed']++;
    echo "  ok  {$what}\n";
}

/** Poll until $fn returns a truthy value (events are delivered by the job loop). */
function until(callable $fn, string $what, int $seconds = 30): mixed
{
    $end = time() + $seconds;
    do {
        $v = $fn();
        if ($v) {
            return $v;
        }
        usleep(300000);
    } while (time() < $end);
    check(false, "timed out waiting for: {$what}");
    return null;
}

function db(string $svc): PDO
{
    return Lib\Db::connect(strtoupper($svc));
}

function uuid(): string
{
    return Lib\Uuid::v4();
}

/** Admin connection to one service database (tests prepare and inspect data directly; the app users cannot do this). */
function admin(string $svc): PDO
{
    $name = (string) getenv(strtoupper($svc) . '_DB_NAME');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_ADMIN_HOST') ?: '127.0.0.1', getenv('DB_ADMIN_PORT') ?: '3306', $name),
        (string) (getenv('DB_ADMIN_USER') ?: 'root'), (string) getenv('DB_ADMIN_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Private service URL from env (BOOKING_URL ...). */
function svcUrl(string $svc): string
{
    return Lib\Routing::url($svc);
}

/**
 * Call a private service the way the portal does (pair credential + signed user context).
 * @return array{0:int,1:array|string}
 */
function svcCall(string $svc, string $method, string $path, string $role, string $accountId, ?array $body = null, ?string $idem = null): array
{
    [$s, $t] = Lib\Http::call($method, svcUrl($svc) . $path, Lib\ServiceAuth::headers('portal', $svc, ['accountId' => $accountId, 'role' => $role], $idem), $body === null ? null : json_encode($body), 30);
    $d = json_decode($t, true);
    return [$s, is_array($d) ? $d : $t];
}

/** Far-future date pair that no other test or demo data uses. */
function freshStay(int $nights = 1): array
{
    $start = (new DateTimeImmutable(Lib\Dates::manilaToday()))->modify('+' . random_int(400, 900) . ' day');
    return [$start->format('Y-m-d'), $start->modify("+{$nights} day")->format('Y-m-d')];
}

function roomTypeId(string $name): string
{
    return (string) admin('booking')->query('SELECT room_type_id FROM room_type WHERE name = ' . admin('booking')->quote($name))->fetchColumn();
}

/** Make the nights of a stay have exactly $left rooms free of this type (the rest are marked confirmed). */
function leaveFree(string $typeName, string $in, string $out, int $left): void
{
    $b = admin('booking');
    $t = $b->query('SELECT room_type_id, capacity FROM room_type WHERE name = ' . $b->quote($typeName))->fetch();
    foreach (Lib\Dates::nights($in, $out) as $n) {
        $b->prepare('INSERT INTO inventory_night (room_type_id, stay_date, capacity, blocked, held, confirmed, ota_remaining) VALUES (?, ?, ?, 0, 0, ?, 0) ON DUPLICATE KEY UPDATE held = 0, confirmed = VALUES(confirmed), blocked = 0, ota_remaining = 0')
            ->execute([$t['room_type_id'], $n, $t['capacity'], $t['capacity'] - $left]);
    }
}

/** A signed "payment succeeded" webhook for a checkout session, like the provider would send. */
function paidWebhook(string $sessionId, string $reservationId, int $amountMinor, ?string $paymentId = null, ?string $eventId = null): array
{
    $ev = Billing\PayMongoAdapter::mockPaidEvent($sessionId, $reservationId, $amountMinor, 'PHP');
    if ($paymentId !== null) {
        $ev['data']['attributes']['data']['attributes']['payments'][0]['id'] = $paymentId;
    }
    if ($eventId !== null) {
        $ev['data']['id'] = $eventId;
    }
    $body = json_encode($ev);
    return [$body, Billing\PayMongoAdapter::signatureHeader($body, (string) getenv('PAYMONGO_WEBHOOK_SECRET'), time())];
}

function postWebhook(string $body, string $signature): array
{
    $c = new Client(base());
    return $c->req('POST', '/v1/webhooks/paymongo', null, ['Paymongo-Signature: ' . $signature, 'Content-Type: application/json'], $body);
}

function holdVia(string $accountId, string $roomTypeId, string $in, string $out, int $qty = 1, ?string $idem = null): array
{
    [$s, $av] = svcCall('booking', 'GET', "/internal/availability?checkIn={$in}&checkOut={$out}&quantity={$qty}", 'guest', $accountId);
    if ($s !== 200) {
        return [$s, $av];
    }
    $opt = array_values(array_filter($av['options'], fn ($o) => $o['roomTypeId'] === $roomTypeId))[0];
    if ($opt['quoteId'] === null) {
        return [409, ['error' => ['code' => 'INVENTORY_CONFLICT']]];
    }
    return svcCall('booking', 'POST', '/internal/reservations', 'guest', $accountId, [
        'quoteId' => $opt['quoteId'], 'roomTypeId' => $roomTypeId, 'checkIn' => $in, 'checkOut' => $out, 'quantity' => $qty,
        'guest' => ['fullName' => 'Test Guest', 'email' => 'test.guest@example.test', 'phone' => '+63 900 0000'],
    ], $idem ?? 'k-' . uuid());
}
