<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Audit;
use Lib\Db;
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
        if ((int) Db::call($this->pdo, 'usp_privacy_countOpen', ['account_id' => $accountId, 'type' => $type])->fetchColumn() > 0) {
            throw new ApiException(409, 'ALREADY_OPEN', 'You already have an open request of this kind.');
        }
        $id = Uuid::v4();
        Db::call($this->pdo, 'usp_privacy_file', ['request_id' => $id, 'account_id' => $accountId, 'type' => $type, 'details' => $details]);
        Audit::record($this->pdo, ['accountId' => $accountId, 'role' => 'guest'], 'privacy.requested', 'privacy_request', $id, ['type' => $type], $ip);
        return $id;
    }

    public function forAccount(string $accountId): array
    {
        return Db::call($this->pdo, 'usp_privacy_listForAccount', ['account_id' => $accountId])->fetchAll();
    }

    /** JSON file for a completed access request. Only the owner may download it. */
    public function result(string $requestId, string $accountId): string
    {
        $r = Db::call($this->pdo, 'usp_privacy_getResult', ['request_id' => $requestId, 'account_id' => $accountId])->fetchColumn();
        return $r !== false && $r !== null ? (string) $r : throw new ApiException(404, 'NOT_FOUND');
    }

    public function queue(string $status = ''): array
    {
        return Db::call($this->pdo, 'usp_privacy_queue', ['status' => in_array($status, ['OPEN', 'IN_REVIEW', 'COMPLETED', 'REJECTED'], true) ? $status : null])->fetchAll();
    }

    public function find(string $id): array
    {
        return Db::call($this->pdo, 'usp_privacy_get', ['request_id' => $id])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
    }

    public function startReview(array $actor, string $id, ?string $ip): void
    {
        if ((int) Db::call($this->pdo, 'usp_privacy_startReview', ['request_id' => $id, 'handled_by' => $actor['accountId']])->fetchColumn() === 0) {
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
        if ((int) Db::call($this->pdo, 'usp_privacy_reject', ['request_id' => $id, 'handled_by' => $actor['accountId'], 'note' => $note])->fetchColumn() === 0) {
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
            if (Db::call($this->pdo, 'usp_account_emailTaken', ['email' => $c['email'], 'account_id' => $acc])->fetchColumn() !== false) {
                throw new ApiException(409, 'EMAIL_TAKEN', 'Another account already uses that email address.');
            }
            Peer::ok('portal', 'booking', 'POST', '/internal/privacy/correct', $actor, ['accountId' => $acc, 'fullName' => $c['fullName'], 'email' => $c['email'], 'phone' => $c['phone']]);
            Db::call($this->pdo, 'usp_account_correct', ['account_id' => $acc, 'full_name' => $c['fullName'], 'email' => $c['email'], 'phone' => $c['phone']]);
        } else {
            Peer::ok('portal', 'booking', 'POST', '/internal/privacy/anonymize', $actor, ['accountId' => $acc]);   // 409 while a booking is still live
            Tx::run($this->pdo, function () use ($acc, $req): void {
                Db::call($this->pdo, 'usp_account_anonymize', [
                    'account_id' => $acc, 'old_email' => $req['email'], 'new_email' => 'deleted-' . substr($acc, 0, 8) . '@deleted.invalid',
                    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                ]);
            });
            $this->accounts->dropSessions($acc);
        }
        Db::call($this->pdo, 'usp_privacy_complete', ['request_id' => $id, 'handled_by' => $actor['accountId'], 'note' => mb_substr(trim($note), 0, 400) ?: null, 'result' => $result]);
        Audit::record($this->pdo, $actor, 'privacy.completed', 'privacy_request', $id, ['type' => $req['type']], $ip);
    }

    private function compileAccess(array $actor, string $accountId): array
    {
        $acct = Db::call($this->pdo, 'usp_account_getExport', ['account_id' => $accountId])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
        $mail = Db::call($this->pdo, 'usp_email_listFor', ['to_email' => $acct['email']]);
        $booking = Peer::ok('portal', 'booking', 'GET', '/internal/privacy/guest-data?accountId=' . $accountId, $actor);
        return ['generatedAt' => gmdate('c'), 'account' => $acct, 'guestRecord' => $booking['guest'], 'reservations' => $booking['reservations'], 'emailsSent' => $mail->fetchAll()];
    }
}
