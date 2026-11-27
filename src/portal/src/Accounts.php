<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Audit;
use Lib\Db;
use Lib\Rbac;
use Lib\Tx;
use Lib\Uuid;

/** Registration, password rules and reset, and user/role administration for the IT lead. */
final class Accounts
{
    public const MIN_PASSWORD = 10;
    private const RESET_TTL = 3600;

    public function __construct(private \PDO $pdo, private RateLimit $limit)
    {
    }

    public static function checkPassword(string $pw, string $email = ''): void
    {
        if (mb_strlen($pw) < self::MIN_PASSWORD || mb_strlen($pw) > 200) {
            throw new ApiException(422, 'WEAK_PASSWORD', 'Use a password of at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if ($email !== '' && stripos($pw, strstr($email, '@', true) ?: $email) !== false && strlen(strstr($email, '@', true) ?: '') >= 4) {
            throw new ApiException(422, 'WEAK_PASSWORD', 'The password must not contain your email name.');
        }
    }

    /** @return array{accountId:string} */
    public function register(array $b, string $ip): array
    {
        $this->limit->hit('register:' . $ip, 10, 3600);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $name = trim((string) ($b['fullName'] ?? ''));
        $phone = trim((string) ($b['phone'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160 || $name === '' || mb_strlen($name) > 120 || $phone === '' || strlen($phone) > 40) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Enter a valid email, your full name and a phone number.');
        }
        self::checkPassword((string) ($b['password'] ?? ''), $email);
        $id = Uuid::v4();
        try {
            Db::call($this->pdo, 'usp_account_insert', [
                'account_id' => $id, 'email' => $email, 'password_hash' => password_hash((string) $b['password'], PASSWORD_DEFAULT), 'role' => 'guest',
                'full_name' => $name, 'phone' => $phone, 'totp_secret' => null, 'marketing_consent' => !empty($b['marketingConsent']) ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                throw new ApiException(409, 'EMAIL_TAKEN', 'An account with this email already exists. Sign in or reset your password.');
            }
            throw $e;
        }
        Audit::record($this->pdo, ['accountId' => $id, 'role' => 'guest'], 'account.registered', 'account', $id, [], $ip);
        return ['accountId' => $id];
    }

    /** Always succeeds from the caller's point of view, so the page never reveals which emails have accounts. */
    public function requestReset(string $email, string $ip): void
    {
        $this->limit->hit('reset:' . $ip, 5, 3600);
        $email = strtolower(trim($email));
        $id = Db::call($this->pdo, 'usp_account_findActiveByEmail', ['email' => $email])->fetchColumn();
        if ($id === false) {
            return;
        }
        $this->limit->hit('reset-acct:' . $id, 3, 3600);
        $this->issueReset((string) $id);
    }

    /** Create a one-time token and queue the email. The raw token lives only in the job payload until it is sent. */
    public function issueReset(string $accountId): void
    {
        $token = bin2hex(random_bytes(32));
        Tx::run($this->pdo, function () use ($accountId, $token): void {
            Db::call($this->pdo, 'usp_reset_create', ['token_hash' => hash('sha256', $token), 'account_id' => $accountId, 'ttl_seconds' => self::RESET_TTL]);
            Notifications::queue($this->pdo, 'EMAIL_PASSWORD_RESET', null, $accountId, ['token' => $token]);
        });
    }

    public function resetTokenValid(string $token): bool
    {
        return Db::call($this->pdo, 'usp_reset_isValid', ['token_hash' => hash('sha256', $token)])->fetchColumn() !== false;
    }

    public function completeReset(string $token, string $newPassword, string $ip): void
    {
        $this->limit->hit('reset-confirm:' . $ip, 10, 3600);
        Tx::run($this->pdo, function () use ($token, $newPassword, $ip): void {
            $r = Db::call($this->pdo, 'usp_reset_lock', ['token_hash' => hash('sha256', $token)])->fetch();
            if ($r === false) {
                throw new ApiException(410, 'RESET_LINK_INVALID', 'This link has expired or was already used. Ask for a new one.');
            }
            self::checkPassword($newPassword, $r['email']);
            Db::call($this->pdo, 'usp_account_setPassword', ['account_id' => $r['account_id'], 'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'clear_lock' => 1]);
            Db::call($this->pdo, 'usp_reset_markUsed', ['account_id' => $r['account_id']]);
            $this->dropSessions($r['account_id']);
            Audit::record($this->pdo, ['accountId' => $r['account_id'], 'role' => 'guest'], 'account.password_reset', 'account', $r['account_id'], [], $ip);
        });
    }

    public function changePassword(string $accountId, string $current, string $new, ?string $ip): void
    {
        $this->limit->hit('chpw:' . $accountId, 10, 3600);
        $a = Db::call($this->pdo, 'usp_account_getCredentials', ['account_id' => $accountId])->fetch() ?: throw new ApiException(401, 'UNAUTHENTICATED');
        if (!password_verify($current, $a['password_hash'])) {
            throw new ApiException(403, 'WRONG_PASSWORD', 'Your current password is not correct.');
        }
        self::checkPassword($new, $a['email']);
        Db::call($this->pdo, 'usp_account_setPassword', ['account_id' => $accountId, 'password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        Audit::record($this->pdo, ['accountId' => $accountId, 'role' => $a['role']], 'account.password_changed', 'account', $accountId, [], $ip);
    }

    public function updateProfile(string $accountId, string $name, string $phone, bool $marketing, ?string $ip): void
    {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '' || mb_strlen($name) > 120 || $phone === '' || strlen($phone) > 40) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Enter your full name and a phone number.');
        }
        Db::call($this->pdo, 'usp_account_updateProfile', ['account_id' => $accountId, 'full_name' => $name, 'phone' => $phone, 'marketing_consent' => $marketing ? 1 : 0]);
    }

    // ---- user and role administration (IT lead) ------------------------------------------------------------

    public function listAccounts(string $role = '', string $q = ''): array
    {
        $like = $q === '' ? null : '%' . str_replace(['%', '_', '['], ['\\%', '\\_', '\\['], $q) . '%';
        return Db::call($this->pdo, 'usp_account_list', ['role' => in_array($role, Rbac::ROLES, true) ? $role : null, 'like' => $like])->fetchAll();
    }

    /** @return array{accountId:string,tempPassword:string,totpSecret:string,otpauthUri:string} shown once */
    public function createStaff(array $actor, array $b, ?string $ip): array
    {
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $name = trim((string) ($b['fullName'] ?? ''));
        $role = (string) ($b['role'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || mb_strlen($name) > 120 || !in_array($role, Rbac::ROLES, true) || $role === 'guest') {
            throw new ApiException(422, 'INVALID_REQUEST', 'Enter a valid email, a full name and a staff role.');
        }
        $temp = self::randomPassword();
        $secret = self::randomBase32();
        $id = Uuid::v4();
        try {
            Db::call($this->pdo, 'usp_account_insert', [
                'account_id' => $id, 'email' => $email, 'password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'role' => $role,
                'full_name' => $name, 'phone' => trim((string) ($b['phone'] ?? '')), 'totp_secret' => $secret,
            ]);
        } catch (\PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                throw new ApiException(409, 'EMAIL_TAKEN', 'An account with this email already exists.');
            }
            throw $e;
        }
        Audit::record($this->pdo, $actor, 'user.created', 'account', $id, ['role' => $role], $ip);
        return ['accountId' => $id, 'tempPassword' => $temp, 'totpSecret' => $secret, 'otpauthUri' => self::otpauth($email, $secret)];
    }

    public function setRole(array $actor, string $id, string $role, ?string $ip): void
    {
        if (!in_array($role, Rbac::ROLES, true)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Unknown role.');
        }
        $this->guardOther($actor, $id);
        $a = $this->account($id);
        if (($a['role'] === 'guest') !== ($role === 'guest')) {
            throw new ApiException(422, 'INVALID_REQUEST', 'A guest account cannot become staff, or the other way round. Create a staff account instead.');
        }
        Db::call($this->pdo, 'usp_account_setRole', ['account_id' => $id, 'role' => $role]);
        $this->dropSessions($id);
        Audit::record($this->pdo, $actor, 'user.role_changed', 'account', $id, ['from' => $a['role'], 'to' => $role], $ip);
    }

    public function setActive(array $actor, string $id, bool $active, ?string $ip): void
    {
        $this->guardOther($actor, $id);
        $this->account($id);
        Db::call($this->pdo, 'usp_account_setActive', ['account_id' => $id, 'active' => $active ? 1 : 0]);
        if (!$active) {
            $this->dropSessions($id);
        }
        Audit::record($this->pdo, $actor, $active ? 'user.enabled' : 'user.disabled', 'account', $id, [], $ip);
    }

    /** @return array{totpSecret:string,otpauthUri:string} */
    public function resetTotp(array $actor, string $id, ?string $ip): array
    {
        $a = $this->account($id);
        if ($a['role'] === 'guest') {
            throw new ApiException(422, 'INVALID_REQUEST', 'Guests do not use a second factor.');
        }
        $secret = self::randomBase32();
        Db::call($this->pdo, 'usp_account_setTotp', ['account_id' => $id, 'totp_secret' => $secret]);
        $this->dropSessions($id);
        Audit::record($this->pdo, $actor, 'user.totp_reset', 'account', $id, [], $ip);
        return ['totpSecret' => $secret, 'otpauthUri' => self::otpauth($a['email'], $secret)];
    }

    public function sendResetLink(array $actor, string $id, ?string $ip): void
    {
        $this->account($id);
        $this->issueReset($id);
        Audit::record($this->pdo, $actor, 'user.reset_link_sent', 'account', $id, [], $ip);
    }

    /** Names for audit rows: account id => "Full name (role)". */
    public function labels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($i) => Uuid::isValid((string) $i))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Db::call($this->pdo, 'usp_account_labels', ['ids' => json_encode($ids)])->fetchAll() as $a) {
            $out[$a['account_id']] = $a['full_name'] . ' (' . $a['role'] . ')';
        }
        return $out;
    }

    public function dropSessions(string $accountId): void
    {
        // Session data is the serialized PHP session, which holds the account id as plain text.
        Db::call($this->pdo, 'usp_session_dropForAccount', ['account_id' => $accountId]);
    }

    private function guardOther(array $actor, string $id): void
    {
        if ($actor['accountId'] === $id) {
            throw new ApiException(409, 'SELF_CHANGE', 'You cannot change your own role or disable your own account. Ask another IT lead.');
        }
    }

    private function account(string $id): array
    {
        return Db::call($this->pdo, 'usp_account_get', ['account_id' => $id])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
    }

    public static function randomPassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $p = '';
        for ($i = 0; $i < 16; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $p;
    }

    public static function randomBase32(): string
    {
        $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = '';
        for ($i = 0; $i < 32; $i++) {
            $s .= $a[random_int(0, 31)];
        }
        return $s;
    }

    public static function otpauth(string $email, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode('MA Hotel:' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode('MA Hotel') . '&digits=6&period=30';
    }
}
