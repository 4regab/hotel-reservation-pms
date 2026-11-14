<?php
declare(strict_types=1);

// Scheduled job: send queued emails (Resend when RESEND_API_KEY is set, otherwise logged to email_log) and tidy old counters.
// A failed email never changes the reservation; the job retries with growing delays and is marked FAILED after five attempts.
require dirname(__DIR__, 2) . '/autoload.php';

$pdo = Lib\Db::connect('PORTAL');
$result = (new Portal\Notifications($pdo, new Portal\Mailer($pdo)))->run();
$pdo->prepare('DELETE FROM rate_limit WHERE window_start < ?')->execute([time() - 3600]);
$pdo->prepare('DELETE FROM session WHERE updated_at < ?')->execute([time() - 7 * 86400]);
$pdo->prepare('DELETE FROM password_reset WHERE expires_at < DATEADD(DAY, -1, DATETRUNC(SECOND, SYSUTCDATETIME()))')->execute();
echo json_encode($result), "\n";
