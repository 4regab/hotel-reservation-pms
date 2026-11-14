<?php
declare(strict_types=1);

// Scheduled job: send queued emails and tidy old counters. A failed email never changes the reservation.
// ponytail: "sending" is a log line (no mail server in the demo). Swap Mailer::send for SMTP when needed.
require dirname(__DIR__, 2) . '/autoload.php';

use Lib\ApiException;
use Lib\Log;
use Lib\Peer;

$pdo = Lib\Db::connect('PORTAL');
$jobs = $pdo->query("SELECT job_id, type, reservation_id, attempts FROM notification_job WHERE status = 'PENDING' ORDER BY created_at LIMIT 50")->fetchAll();
$sent = 0;
foreach ($jobs as $job) {
    try {
        // Contact details are looked up at send time, so they are never stored in events.
        $res = Peer::ok('portal', 'booking', 'GET', '/internal/reservations/' . $job['reservation_id']);
        $st = $pdo->prepare('SELECT 1 FROM account WHERE account_id = ?');
        $st->execute([$res['guestAccountId'] ?? '']);
        if ($st->fetchColumn() === false) {
            throw new ApiException(404, 'NO_ACCOUNT');
        }
        Log::info('email sent', ['jobId' => $job['job_id'], 'type' => $job['type']]);
        $pdo->prepare("UPDATE notification_job SET status = 'SENT', sent_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE job_id = ?")->execute([$job['job_id']]);
        $sent++;
    } catch (ApiException $e) {
        $attempts = (int) $job['attempts'] + 1;
        $pdo->prepare('UPDATE notification_job SET status = ?, attempts = ? WHERE job_id = ?')->execute([$attempts >= Lib\Retry::MAX_ATTEMPTS ? 'FAILED' : 'PENDING', $attempts, $job['job_id']]);
    }
}
$pdo->prepare('DELETE FROM rate_limit WHERE window_start < ?')->execute([time() - 3600]);
echo json_encode(['emailsSent' => $sent]), "\n";
