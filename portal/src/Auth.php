<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
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

    public function login(Request $r): array
    {
        $this->limit->hit('login:' . $r->ip(), 10, 60);
        $b = $r->json();
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $st = $this->pdo->prepare('SELECT account_id, role, password_hash, totp_secret FROM account WHERE email = ?');
        $st->execute([$email]);
        $a = $st->fetch();
        $passwordOk = password_verify((string) ($b['password'] ?? ''), $a['password_hash'] ?? self::DUMMY_HASH);
        if ($a === false || !$passwordOk) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Wrong email or password');
        }
        if (Rbac::isStaff($a['role'])) {
            $otp = (string) ($b['otp'] ?? '');
            if ($otp === '') {
                throw new ApiException(401, 'OTP_REQUIRED', 'Staff sign-in needs the 6-digit code from the authenticator app');
            }
            if (!Totp::verify((string) $a['totp_secret'], $otp, time())) {
                throw new ApiException(401, 'INVALID_CREDENTIALS', 'Wrong email, password or code');
            }
        }
        $this->start(true);
        session_regenerate_id(true);
        $_SESSION['user'] = ['accountId' => $a['account_id'], 'role' => $a['role']];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
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
