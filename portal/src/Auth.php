<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Audit;
use Lib\Rbac;
use Lib\Request;

/** Sessions, login with a second factor for staff, and CSRF checks for browser writes. */
final class Auth
{
    public const COOKIE = 'HLSESSID';
    // A valid-looking hash checked when the email is unknown, so timing does not reveal which emails exist.
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakmVr1B8nT0Ur9GtJGu';

    public function __construct(private \PDO $pdo, private RateLimit $limit)
    {
    }

    /** Open the session. Without $create it only opens when the browser already sent the cookie. */
    public function start(bool $create = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || (!$create && !isset($_COOKIE[self::COOKIE]))) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(self::COOKIE);
        // Secure needs HTTPS: set COOKIE_SECURE=1 behind TLS. ponytail: the local demo runs on plain http.
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => getenv('COOKIE_SECURE') === '1', 'path' => '/']);
        session_set_save_handler(new SessionStore($this->pdo), true);
        session_start();
    }

    /** @return ?array{accountId:string,role:string} */
    public function user(): ?array
    {
        $this->start();
        return $_SESSION['user'] ?? null;
    }

    public function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public function requireCsrf(Request $r): void
    {
        $given = (string) $r->header('X-CSRF-Token');
        if ($this->csrf() === '' || !hash_equals($this->csrf(), $given)) {
            throw new ApiException(403, 'CSRF_FAILED', 'Missing or wrong CSRF token');
        }
    }

    public function account(string $accountId): ?array
    {
        $st = $this->pdo->prepare('SELECT account_id, email, role, full_name, phone FROM account WHERE account_id = ?');
        $st->execute([$accountId]);
        return $st->fetch() ?: null;
    }

    /** Per-address limit on sign-in attempts (20 a minute). */
    public function throttle(string $ip): void
    {
        $this->limit->hit('login:' . $ip, 20, 60);
    }

    private const MAX_FAILS = 5;
    private const LOCK_MINUTES = 15;

    public function login(Request $r): array
    {
        $this->limit->hit('login:' . $r->ip(), 20, 60);
        $b = $r->json();
        return $this->attempt(strtolower(trim((string) ($b['email'] ?? ''))), (string) ($b['password'] ?? ''), (string) ($b['otp'] ?? ''), $r->ip());
    }

    /** Shared by the JSON API and the sign-in page. Same message for every failure, so nothing reveals which emails exist. */
    public function attempt(string $email, string $password, string $otp, string $ip): array
    {
        $st = $this->pdo->prepare('SELECT account_id, role, password_hash, totp_secret, active, failed_logins, (locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()) AS locked FROM account WHERE email = ?');
        $st->execute([$email]);
        $a = $st->fetch();
        $passwordOk = password_verify($password, $a['password_hash'] ?? self::DUMMY_HASH);
        if ($a !== false && (int) $a['locked'] === 1) {
            throw new ApiException(429, 'ACCOUNT_LOCKED', 'Too many failed attempts. Try again in ' . self::LOCK_MINUTES . ' minutes or reset your password.');
        }
        $fail = function (string $why) use ($a, $ip): never {
            if ($a !== false) {
                $this->pdo->prepare('UPDATE account SET failed_logins = failed_logins + 1, locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), locked_until) WHERE account_id = ?')
                    ->execute([self::MAX_FAILS, self::LOCK_MINUTES, $a['account_id']]);
                if (Rbac::isStaff($a['role'])) {
                    Audit::record($this->pdo, ['accountId' => $a['account_id'], 'role' => $a['role']], 'auth.login_failed', 'account', $a['account_id'], ['why' => $why], $ip);
                }
            }
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Wrong email, password or code');
        };
        if ($a === false || !$passwordOk || !(int) $a['active']) {
            $fail('password');
        }
        if (Rbac::isStaff($a['role'])) {
            if ($otp === '') {
                throw new ApiException(401, 'OTP_REQUIRED', 'Staff sign-in needs the 6-digit code from the authenticator app');
            }
            if (!Totp::verify((string) $a['totp_secret'], $otp, time())) {
                $fail('otp');
            }
        }
        $this->pdo->prepare('UPDATE account SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE account_id = ?')->execute([$a['account_id']]);
        $this->start(true);
        session_regenerate_id(true);
        $_SESSION['user'] = ['accountId' => $a['account_id'], 'role' => $a['role']];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        if (Rbac::isStaff($a['role'])) {
            Audit::record($this->pdo, $_SESSION['user'], 'auth.login', 'account', $a['account_id'], [], $ip);
        }
        return [200, ['accountId' => $a['account_id'], 'role' => $a['role'], 'csrfToken' => $_SESSION['csrf']]];
    }

    public function logout(): array
    {
        $this->start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return [200, ['loggedOut' => true]];
    }
}
