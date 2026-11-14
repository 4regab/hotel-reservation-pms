<?php
declare(strict_types=1);

// Scheduled job: deliver Billing outbox events and retry failures (flag for review after 5 attempts).
require dirname(__DIR__, 2) . '/autoload.php';

echo json_encode((new Lib\OutboxDispatcher(Lib\Db::connect('BILLING'), 'billing'))->run()), "\n";
