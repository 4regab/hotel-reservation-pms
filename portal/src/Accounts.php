<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Audit;
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
            $this->pdo->prepare("INSERT INTO account (account_id, email, password_hash, role, full_name, phone, totp_secret, marketing_consent, created_at) VALUES (?, ?, ?, 'guest', ?, ?, NULL, ?, UTC_TIMESTAMP())")
                ->execute([$id, $email, password_hash((string) $b['password'], PASSWORD_DEFAULT), $name, $phone, !empty($b['marketingConsent']) ? 1 : 0]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
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
        $st = $this->pdo->prepare('SELECT account_id FROM account WHERE email = ? AND active = 1');
        $st->execute([$email]);
        $id = $st->fetchColumn();
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
            $this->pdo->prepare('INSERT INTO password_reset (token_hash, account_id, expires_at, created_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())')
                ->execute([hash('sha256', $token), $accountId, self::RESET_TTL]);
            Notifications::queue($this->pdo, 'EMAIL_PASSWORD_RESET', null, $accountId, ['token' => $token]);
        });
    }

    public function resetTokenValid(string $token): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM password_reset WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $st->execute([hash('sha256', $token)]);
        return $st->fetchColumn() !== false;
    }

    public function completeReset(string $token, string $newPassword, string $ip): void
    {
        $this->limit->hit('reset-confirm:' . $ip, 10, 3600);
        Tx::run($this->pdo, function () use ($token, $newPassword, $ip): void {
            $st = $this->pdo->prepare('SELECT r.account_id, a.email FROM password_reset r JOIN account a ON a.account_id = r.account_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP() AND a.active = 1 FOR UPDATE');
            $st->execute([hash('sha256', $token)]);
            $r = $st->fetch();
            if ($r === false) {
                throw new ApiException(410, 'RESET_LINK_INVALID', 'This link has expired or was already used. Ask for a new one.');
            }
            self::checkPassword($newPassword, $r['email']);
            $this->pdo->prepare('UPDATE account SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE account_id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $r['account_id']]);
            $this->pdo->prepare('UPDATE password_reset SET used_at = UTC_TIMESTAMP() WHERE account_id = ? AND used_at IS NULL')->execute([$r['account_id']]);
            $this->dropSessions($r['account_id']);
            Audit::record($this->pdo, ['accountId' => $r['account_id'], 'role' => 'guest'], 'account.password_reset', 'account', $r['account_id'], [], $ip);
        });
    }

    public function changePassword(string $accountId, string $current, string $new, ?string $ip): void
    {
        $this->limit->hit('chpw:' . $accountId, 10, 3600);
        $st = $this->pdo->prepare('SELECT password_hash, email, role FROM account WHERE account_id = ?');
        $st->execute([$accountId]);
        $a = $st->fetch() ?: throw new ApiException(401, 'UNAUTHENTICATED');
        if (!password_verify($current, $a['password_hash'])) {
            throw new ApiException(403, 'WRONG_PASSWORD', 'Your current password is not correct.');
        }
        self::checkPassword($new, $a['email']);
        $this->pdo->prepare('UPDATE account SET password_hash = ? WHERE account_id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $accountId]);
        Audit::record($this->pdo, ['accountId' => $accountId, 'role' => $a['role']], 'account.password_changed', 'account', $accountId, [], $ip);
    }

    public function updateProfile(string $accountId, string $name, string $phone, bool $marketing, ?string $ip): void
    {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '' || mb_strlen($name) > 120 || $phone === '' || strlen($phone) > 40) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Enter your full name and a phone number.');
        }
        $this->pdo->prepare('UPDATE account SET full_name = ?, phone = ?, marketing_consent = ? WHERE account_id = ?')->execute([$name, $phone, $marketing ? 1 : 0, $accountId]);
    }

    // ---- user and role administration (IT lead) ------------------------------------------------------------

    public function listAccounts(string $role = '', string $q = ''): array
    {
        $where = ['anonymized_at IS NULL'];
        $args = [];
        if (in_array($role, Rbac::ROLES, true)) {
            $where[] = 'role = ?';
            $args[] = $role;
        }
        if ($q !== '') {
            $where[] = '(email LIKE ? OR full_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($args, $like, $like);
        }
        $st = $this->pdo->prepare('SELECT account_id, email, full_name, role, active, last_login_at, locked_until, totp_secret IS NOT NULL AS has_totp, created_at FROM account WHERE ' . implode(' AND ', $where) . ' ORDER BY role = \'guest\', role, full_name LIMIT 200');
        $st->execute($args);
        return $st->fetchAll();
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
            $this->pdo->prepare('INSERT INTO account (account_id, email, password_hash, role, full_name, phone, totp_secret, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$id, $email, password_hash($temp, PASSWORD_DEFAULT), $role, $name, trim((string) ($b['phone'] ?? '')), $secret]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
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
        $this->pdo->prepare('UPDATE account SET role = ? WHERE account_id = ?')->execute([$role, $id]);
        $this->dropSessions($id);
        Audit::record($this->pdo, $actor, 'user.role_changed', 'account', $id, ['from' => $a['role'], 'to' => $role], $ip);
    }

    public function setActive(array $actor, string $id, bool $active, ?string $ip): void
    {
        $this->guardOther($actor, $id);
        $this->account($id);
        $this->pdo->prepare('UPDATE account SET active = ?, failed_logins = 0, locked_until = NULL WHERE account_id = ?')->execute([$active ? 1 : 0, $id]);
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
        $this->pdo->prepare('UPDATE account SET totp_secret = ? WHERE account_id = ?')->execute([$secret, $id]);
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
        $st = $this->pdo->prepare('SELECT account_id, full_name, role FROM account WHERE account_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $a) {
            $out[$a['account_id']] = $a['full_name'] . ' (' . $a['role'] . ')';
        }
        return $out;
    }

    public function dropSessions(string $accountId): void
    {
        // Session data is the serialized PHP session, which holds the account id as plain text.
        $this->pdo->prepare('DELETE FROM session WHERE data LIKE ?')->execute(['%' . $accountId . '%']);
    }

    private function guardOther(array $actor, string $id): void
    {
        if ($actor['accountId'] === $id) {
            throw new ApiException(409, 'SELF_CHANGE', 'You cannot change your own role or disable your own account. Ask another IT lead.');
        }
    }

    private function account(string $id): array
    {
        $st = $this->pdo->prepare('SELECT account_id, email, role FROM account WHERE account_id = ? AND anonymized_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
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
        return 'otpauth://totp/' . rawurlencode('HarborLight:' . $email) . '?secret=' . $secret . '&issuer=HarborLight&digits=6&period=30';
    }
}
