<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Audit;
use Lib\Peer;
use Lib\Tx;
use Lib\Uuid;

/**
 * Privacy requests (access, correction, deletion). A guest files one while signed in, which is how identity is verified.
 * The IT/privacy lead reviews and completes it. Deletion removes identity from the account and the guest record;
 * stay and payment facts stay for finance, without a name attached. The due date is 30 days from filing.
 */
final class Privacy
{
    public function __construct(private \PDO $pdo, private Accounts $accounts)
    {
    }

    public function file(string $accountId, string $type, string $details, ?array $correction, ?string $ip): string
    {
        if (!in_array($type, ['ACCESS', 'CORRECTION', 'DELETION'], true)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Choose access, correction or deletion.');
        }
        $details = trim($details);
        if ($type === 'CORRECTION') {
            $c = $correction ?? [];
            $name = trim((string) ($c['fullName'] ?? ''));
            $email = strtolower(trim((string) ($c['email'] ?? '')));
            $phone = trim((string) ($c['phone'] ?? ''));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
                throw new ApiException(422, 'INVALID_REQUEST', 'Give the corrected name, email and phone number.');
            }
            $details = json_encode(['fullName' => $name, 'email' => $email, 'phone' => $phone, 'note' => mb_substr($details, 0, 300)]);
        } elseif (mb_strlen($details) > 600) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Keep the note under 600 characters.');
        }
        $open = $this->pdo->prepare("SELECT COUNT(*) FROM privacy_request WHERE account_id = ? AND type = ? AND status IN ('OPEN','IN_REVIEW')");
        $open->execute([$accountId, $type]);
        if ((int) $open->fetchColumn() > 0) {
            throw new ApiException(409, 'ALREADY_OPEN', 'You already have an open request of this kind.');
        }
        $id = Uuid::v4();
        $this->pdo->prepare("INSERT INTO privacy_request (request_id, account_id, type, details, status, due_at, created_at) VALUES (?, ?, ?, ?, 'OPEN', DATEADD(DAY, 30, DATETRUNC(SECOND, SYSUTCDATETIME())), DATETRUNC(SECOND, SYSUTCDATETIME()))")
            ->execute([$id, $accountId, $type, $details]);
        Audit::record($this->pdo, ['accountId' => $accountId, 'role' => 'guest'], 'privacy.requested', 'privacy_request', $id, ['type' => $type], $ip);
        return $id;
    }

    public function forAccount(string $accountId): array
    {
        $st = $this->pdo->prepare('SELECT request_id, type, status, details, resolution_note, due_at, created_at, completed_at, CASE WHEN result IS NOT NULL THEN 1 ELSE 0 END AS has_result FROM privacy_request WHERE account_id = ? ORDER BY created_at DESC');
        $st->execute([$accountId]);
        return $st->fetchAll();
    }

    /** JSON file for a completed access request. Only the owner may download it. */
    public function result(string $requestId, string $accountId): string
    {
        $st = $this->pdo->prepare("SELECT result FROM privacy_request WHERE request_id = ? AND account_id = ? AND status = 'COMPLETED' AND type = 'ACCESS'");
        $st->execute([$requestId, $accountId]);
        $r = $st->fetchColumn();
        return $r !== false && $r !== null ? (string) $r : throw new ApiException(404, 'NOT_FOUND');
    }

    public function queue(string $status = ''): array
    {
        $where = in_array($status, ['OPEN', 'IN_REVIEW', 'COMPLETED', 'REJECTED'], true) ? 'WHERE p.status = ' . $this->pdo->quote($status) : "WHERE p.status IN ('OPEN','IN_REVIEW')";
        return $this->pdo->query("SELECT TOP (100) p.request_id, p.type, p.status, p.details, p.resolution_note, p.due_at, p.created_at, p.completed_at, p.account_id, a.email, a.full_name, CASE WHEN p.due_at < DATETRUNC(SECOND, SYSUTCDATETIME()) THEN 1 ELSE 0 END AS overdue
                                    FROM privacy_request p JOIN account a ON a.account_id = p.account_id {$where} ORDER BY p.due_at")->fetchAll();
    }

    public function find(string $id): array
    {
        $st = $this->pdo->prepare('SELECT p.*, a.email, a.full_name FROM privacy_request p JOIN account a ON a.account_id = p.account_id WHERE p.request_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
    }

    public function startReview(array $actor, string $id, ?string $ip): void
    {
        $st = $this->pdo->prepare("UPDATE privacy_request SET status = 'IN_REVIEW', handled_by = ? WHERE request_id = ? AND status = 'OPEN'");
        $st->execute([$actor['accountId'], $id]);
        if ($st->rowCount() === 0) {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This request is not open.');
        }
        Audit::record($this->pdo, $actor, 'privacy.review_started', 'privacy_request', $id, [], $ip);
    }

    public function reject(array $actor, string $id, string $note, ?string $ip): void
    {
        $note = mb_substr(trim($note), 0, 400);
        if ($note === '') {
            throw new ApiException(422, 'INVALID_REQUEST', 'Explain the decision to the guest.');
        }
        $st = $this->pdo->prepare("UPDATE privacy_request SET status = 'REJECTED', handled_by = ?, resolution_note = ?, completed_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE request_id = ? AND status IN ('OPEN','IN_REVIEW')");
        $st->execute([$actor['accountId'], $note, $id]);
        if ($st->rowCount() === 0) {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This request is already closed.');
        }
        Audit::record($this->pdo, $actor, 'privacy.rejected', 'privacy_request', $id, [], $ip);
    }

    /** Carry out the request and close it. Each branch is safe to retry. */
    public function complete(array $actor, string $id, string $note, ?string $ip): void
    {
        $req = $this->find($id);
        if (!in_array($req['status'], ['OPEN', 'IN_REVIEW'], true)) {
            throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This request is already closed.');
        }
        $acc = $req['account_id'];
        $result = null;
        if ($req['type'] === 'ACCESS') {
            $result = json_encode($this->compileAccess($actor, $acc), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } elseif ($req['type'] === 'CORRECTION') {
            $c = json_decode((string) $req['details'], true);
            if (!is_array($c) || !isset($c['email'])) {
                throw new ApiException(422, 'INVALID_REQUEST', 'This correction request has no new details.');
            }
            $taken = $this->pdo->prepare('SELECT 1 FROM account WHERE email = ? AND account_id <> ?');
            $taken->execute([$c['email'], $acc]);
            if ($taken->fetchColumn() !== false) {
                throw new ApiException(409, 'EMAIL_TAKEN', 'Another account already uses that email address.');
            }
            Peer::ok('portal', 'booking', 'POST', '/internal/privacy/correct', $actor, ['accountId' => $acc, 'fullName' => $c['fullName'], 'email' => $c['email'], 'phone' => $c['phone']]);
            $this->pdo->prepare('UPDATE account SET full_name = ?, email = ?, phone = ? WHERE account_id = ?')->execute([$c['fullName'], $c['email'], $c['phone'], $acc]);
        } else {
            Peer::ok('portal', 'booking', 'POST', '/internal/privacy/anonymize', $actor, ['accountId' => $acc]);   // 409 while a booking is still live
            Tx::run($this->pdo, function () use ($acc, $req): void {
                $this->pdo->prepare('UPDATE email_log SET to_email = ?, body_html = ? WHERE to_email = ?')->execute(['deleted@deleted.invalid', '[removed on request]', $req['email']]);
                $this->pdo->prepare("UPDATE account SET email = ?, full_name = 'Deleted guest', phone = '', password_hash = ?, active = 0, anonymized_at = DATETRUNC(SECOND, SYSUTCDATETIME()), marketing_consent = 0 WHERE account_id = ?")
                    ->execute(['deleted-' . substr($acc, 0, 8) . '@deleted.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $acc]);
                $this->pdo->prepare("UPDATE privacy_request SET details = '[removed]' WHERE account_id = ? AND type = 'CORRECTION'")->execute([$acc]);
            });
            $this->accounts->dropSessions($acc);
        }
        $this->pdo->prepare("UPDATE privacy_request SET status = 'COMPLETED', handled_by = ?, resolution_note = ?, result = ?, completed_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE request_id = ?")
            ->execute([$actor['accountId'], mb_substr(trim($note), 0, 400) ?: null, $result, $id]);
        Audit::record($this->pdo, $actor, 'privacy.completed', 'privacy_request', $id, ['type' => $req['type']], $ip);
    }

    private function compileAccess(array $actor, string $accountId): array
    {
        $a = $this->pdo->prepare('SELECT email, full_name, phone, marketing_consent, created_at, last_login_at FROM account WHERE account_id = ?');
        $a->execute([$accountId]);
        $acct = $a->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
        $mail = $this->pdo->prepare('SELECT template, subject, status, created_at FROM email_log WHERE to_email = ? ORDER BY created_at');
        $mail->execute([$acct['email']]);
        $booking = Peer::ok('portal', 'booking', 'GET', '/internal/privacy/guest-data?accountId=' . $accountId, $actor);
        return ['generatedAt' => gmdate('c'), 'account' => $acct, 'guestRecord' => $booking['guest'], 'reservations' => $booking['reservations'], 'emailsSent' => $mail->fetchAll()];
    }
}
