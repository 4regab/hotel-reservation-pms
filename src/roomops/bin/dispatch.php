<?php
declare(strict_types=1);

// Scheduled job: deliver Room Operations outbox events and retry failures (flag for review after 5 attempts).
require dirname(__DIR__, 3) . '/autoload.php';

echo json_encode((new Lib\OutboxDispatcher(Lib\Db::connect('ROOMOPS'), 'roomops'))->run()), "\n";
