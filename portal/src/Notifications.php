<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Log;
use Lib\Peer;
use Lib\Retry;
use Lib\Uuid;

/**
 * Notification outbox: jobs are queued by event handlers (in the same transaction as the event) and sent here.
 * A failed email never changes a reservation. A job retries with growing delays and is marked FAILED after five attempts.
 */
final class Notifications
{
    public function __construct(private \PDO $pdo, private Mailer $mailer)
    {
    }

    public static function queue(\PDO $pdo, string $type, ?string $reservationId, ?string $accountId = null, ?array $payload = null): string
    {
        $id = Uuid::v4();
        $pdo->prepare("INSERT INTO notification_job (job_id, type, reservation_id, account_id, payload, status, attempts, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, 'PENDING', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([$id, $type, $reservationId, $accountId, $payload === null ? null : json_encode($payload), ]);
        return $id;
    }

    /** @return array{sent:int,failed:int} */
    public function run(int $limit = 50): array
    {
        $jobs = $this->pdo->query("SELECT job_id, type, reservation_id, account_id, payload, attempts FROM notification_job WHERE status = 'PENDING' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()) ORDER BY created_at LIMIT " . max(1, $limit))->fetchAll();
        $out = ['sent' => 0, 'failed' => 0];
        foreach ($jobs as $job) {
            try {
                if ($this->mailer->alreadyDone($job['job_id']) || $this->process($job)) {
                    $this->pdo->prepare("UPDATE notification_job SET status = 'SENT', sent_at = UTC_TIMESTAMP(), attempts = attempts + 1, last_error = NULL, payload = NULL WHERE job_id = ?")->execute([$job['job_id']]);
                    $out['sent']++;
                }
            } catch (ApiException $e) {
                $attempts = (int) $job['attempts'] + 1;
                $next = Retry::afterFailure($attempts);
                $final = $next['status'] === 'REVIEW';
                $this->pdo->prepare('UPDATE notification_job SET status = ?, attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE job_id = ?')
                    ->execute([$final ? 'FAILED' : 'PENDING', $attempts, mb_substr($e->getMessage(), 0, 200), $next['delay'], $job['job_id']]);
                if ($final) {
                    $this->mailer->recordFailure($job['type'], '(see job)', $job['type'], $e->getMessage(), $job['job_id']);
                }
                Log::info('notification failed', ['jobId' => $job['job_id'], 'attempt' => $attempts, 'error' => $e->getMessage()]);
                $out['failed']++;
            }
        }
        return $out;
    }

    /** @return bool true when finished (sent, logged, or intentionally skipped) */
    private function process(array $job): bool
    {
        if ($job['type'] === 'EMAIL_PASSWORD_RESET') {
            $p = json_decode((string) $job['payload'], true) ?: [];
            $a = $this->pdo->prepare('SELECT email, full_name FROM account WHERE account_id = ? AND active = 1');
            $a->execute([$job['account_id']]);
            $acc = $a->fetch();
            if ($acc === false || !isset($p['token'])) {
                return true;
            }
            [$subject, $html, $text] = Emails::passwordReset($acc['full_name'], (string) $p['token']);
            $this->mailer->send('password_reset', $acc['email'], $subject, $html, $text, $job['job_id']);
            return true;
        }
        $res = Peer::ok('portal', 'booking', 'GET', '/internal/reservations/' . $job['reservation_id']);   // contact details are read now, never stored in events
        $to = (string) ($res['guestEmail'] ?? '');
        if ($to === '' || str_ends_with($to, '.invalid')) {
            return true;   // partner bookings and deleted guests have no address
        }
        switch ($job['type']) {
            case 'EMAIL_CONFIRMATION':
                [$s, $h, $t] = Emails::bookingConfirmation($res);
                $this->mailer->send('booking_confirmation', $to, $s, $h, $t, $job['job_id']);
                return true;
            case 'EMAIL_CANCELLATION':
                [$s, $h, $t] = Emails::cancellation($res);
                $this->mailer->send('booking_cancellation', $to, $s, $h, $t, $job['job_id']);
                return true;
            case 'EMAIL_REFUND':
                $p = json_decode((string) $job['payload'], true) ?: [];
                [$s, $h, $t] = Emails::refund($res, (int) ($p['amountMinor'] ?? 0));
                $this->mailer->send('refund', $to, $s, $h, $t, $job['job_id']);
                return true;
        }
        return true;
    }
}
