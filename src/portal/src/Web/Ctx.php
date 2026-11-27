<?php
declare(strict_types=1);

namespace Portal\Web;

use Lib\ApiException;
use Lib\Db;
use Lib\Peer;
use Lib\Rbac;
use Lib\Request;
use Portal\Accounts;
use Portal\Auth;
use Portal\Privacy;
use Portal\Oversight;

/** Everything a page handler needs for one request: who is signed in, form helpers, and calls to the services as that person. */
final class Ctx
{
    private ?array $account = null;
    private ?string $anonCsrf = null;

    public function __construct(
        public readonly Request $req,
        public readonly Auth $auth,
        public readonly \PDO $pdo,
        public readonly Accounts $accounts,
        public readonly Privacy $privacy,
        public readonly Oversight $oversight,
    ) {
        require_once __DIR__ . '/helpers.php';
    }

    /** @return ?array{accountId:string,role:string} */
    public function user(): ?array
    {
        return $this->auth->user();
    }

    public function role(): string
    {
        return $this->user()['role'] ?? 'anonymous';
    }

    public function isStaff(): bool
    {
        $u = $this->user();
        return $u !== null && Rbac::isStaff($u['role']);
    }

    public function can(string $action): bool
    {
        $u = $this->user();
        return $u !== null && Rbac::can($u['role'], $action);
    }

    /** Gate: sends anonymous visitors to sign in, and other roles to a "not allowed" page. */
    public function require(string ...$roles): array
    {
        $u = $this->user() ?? throw new ApiException(401, 'UNAUTHENTICATED', 'Please sign in to continue.');
        if ($roles !== [] && !in_array($u['role'], $roles, true)) {
            throw new ApiException(403, 'FORBIDDEN', 'Your role does not include this page.');
        }
        return $u;
    }

    /** @return array{full_name:string,email:string,phone:string,marketing_consent:int}|null */
    public function account(): ?array
    {
        $u = $this->user();
        if ($u === null) {
            return null;
        }
        if ($this->account === null) {
            $this->account = Db::call($this->pdo, 'usp_auth_getProfile', ['account_id' => $u['accountId']])->fetch() ?: [];
        }
        return $this->account ?: null;
    }

    // ---- CSRF: session token when signed in, a cookie token for anonymous forms --------------------------

    public function csrf(): string
    {
        if ($this->user() !== null) {
            return $this->auth->csrf();
        }
        if ($this->anonCsrf === null) {
            $c = (string) ($_COOKIE['HLCSRF'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $c)) {
                $c = bin2hex(random_bytes(16));
                setcookie('HLCSRF', $c, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => getenv('COOKIE_SECURE') === '1']);
            }
            $this->anonCsrf = $c;
        }
        return $this->anonCsrf;
    }

    public function checkCsrf(): void
    {
        $given = (string) ($_POST['_csrf'] ?? '');
        $real = $this->user() !== null ? $this->auth->csrf() : (string) ($_COOKIE['HLCSRF'] ?? '');
        if ($real === '' || !hash_equals($real, $given)) {
            throw new ApiException(403, 'CSRF_FAILED', 'This form has expired. Reload the page and try again.');
        }
    }

    public function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function postFlag(string $key): bool
    {
        return !empty($_POST[$key]);
    }

    public function q(string $key, string $default = ''): string
    {
        $v = $this->req->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    // ---- messages shown after a redirect -----------------------------------------------------------------

    public function flash(string $type, string $message): void
    {
        $this->auth->start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash'][] = [$type, $message];
        }
    }

    /** @return list<array{0:string,1:string}> */
    public function takeFlash(): array
    {
        if ($this->user() === null || session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    // ---- service calls as the signed-in person -----------------------------------------------------------

    /** Calls a service with the user's signed context. Non-2xx answers become ApiException. */
    public function svc(string $service, string $method, string $path, ?array $body = null, ?string $idem = null): array
    {
        return Peer::ok('portal', $service, $method, $path, $this->user(), $body, $idem);
    }

    /** Same, but returns [status, data] without throwing, for pages that handle 404 or 409 themselves. */
    public function svcRaw(string $service, string $method, string $path, ?array $body = null, ?string $idem = null): array
    {
        return Peer::call('portal', $service, $method, $path, $this->user(), $body, $idem);
    }

    /** As the portal itself (no user context). Use only after the caller proved ownership. */
    public function svcAsPortal(string $service, string $method, string $path): array
    {
        return Peer::ok('portal', $service, $method, $path);
    }

    /**
     * Run a form action. On success flash $ok and redirect; on a refused action flash the reason and redirect back.
     * @return array{0:int,1:string,2:array}
     */
    public function act(callable $fn, string $ok, string $to, ?string $back = null): array
    {
        try {
            $this->checkCsrf();
            $fn();
            if ($ok !== '') {
                $this->flash('ok', $ok);
            }
            return View::redirect($to);
        } catch (ApiException $e) {
            if ($e->status === 401) {
                throw $e;
            }
            $this->flash('error', self::friendly($e));
            return View::redirect($back ?? $to);
        }
    }

    /** Short, human wording for the errors a person can fix. */
    public static function friendly(ApiException $e): string
    {
        $m = $e->getMessage();
        return match ($e->errorCode) {
            'INVENTORY_CONFLICT' => 'Sorry, those dates just sold out. Please search again.',
            'INVALID_QUOTE' => 'That price has expired. Please search again to get a fresh price.',
            'SERVICE_UNAVAILABLE' => 'A part of the system is busy. Please try again in a moment.',
            'RATE_LIMITED' => 'Too many tries. Please wait a minute and try again.',
            'NOT_FOUND' => 'We could not find that record.',
            default => $m !== '' && $m !== $e->errorCode ? $m : 'Something went wrong. Please try again.',
        };
    }
}
