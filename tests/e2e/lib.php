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
