<?php
declare(strict_types=1);

// Scheduled job: send queued emails (Resend when RESEND_API_KEY is set, otherwise logged to email_log) and tidy old counters.
// A failed email never changes the reservation; the job retries with growing delays and is marked FAILED after five attempts.
require dirname(__DIR__, 3) . '/autoload.php';

$pdo = Lib\Db::connect('PORTAL');
$result = (new Portal\Notifications($pdo, new Portal\Mailer($pdo)))->run();
Lib\Db::call($pdo, 'usp_rateLimit_purge', ['before' => time() - 3600]);
Lib\Db::call($pdo, 'usp_session_purge', ['before' => time() - 7 * 86400]);
Lib\Db::call($pdo, 'usp_auth_purgeResets');
echo json_encode($result), "\n";
