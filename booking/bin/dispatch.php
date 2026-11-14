<?php
declare(strict_types=1);

// Scheduled job: deliver Booking's outbox events and retry failed deliveries (flag for review after 5 attempts).
require dirname(__DIR__, 2) . '/autoload.php';

echo json_encode((new Lib\OutboxDispatcher(Lib\Db::connect('BOOKING'), 'booking'))->run()), "\n";
