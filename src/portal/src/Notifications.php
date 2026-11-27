<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Db;
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
        Db::call($pdo, 'usp_notification_queue', ['job_id' => $id, 'type' => $type, 'reservation_id' => $reservationId, 'account_id' => $accountId, 'payload' => $payload === null ? null : json_encode($payload)]);
        return $id;
    }

    /** @return array{sent:int,failed:int} */
    public function run(int $limit = 50): array
    {
        $jobs = Db::call($this->pdo, 'usp_notification_listDue', ['limit' => max(1, $limit)])->fetchAll();
        $out = ['sent' => 0, 'failed' => 0];
        foreach ($jobs as $job) {
            try {
                if ($this->mailer->alreadyDone($job['job_id']) || $this->process($job)) {
                    Db::call($this->pdo, 'usp_notification_markSent', ['job_id' => $job['job_id']]);
                    $out['sent']++;
                }
            } catch (ApiException $e) {
                $attempts = (int) $job['attempts'] + 1;
                $next = Retry::afterFailure($attempts);
                $final = $next['status'] === 'REVIEW';
                Db::call($this->pdo, 'usp_notification_markFailed', ['job_id' => $job['job_id'], 'status' => $final ? 'FAILED' : 'PENDING', 'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 200), 'delay_seconds' => $next['delay']]);
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
            $acc = Db::call($this->pdo, 'usp_auth_getActiveContact', ['account_id' => $job['account_id']])->fetch();
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
