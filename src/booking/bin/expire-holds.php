<?php
declare(strict_types=1);

// Scheduled job: release stock for holds older than 10 minutes. Uses the same workflow and locks as confirmation.
require dirname(__DIR__, 3) . '/autoload.php';

$pdo = Lib\Db::connect('BOOKING');
$repo = new Booking\BookingRepository($pdo);
$inv = new Booking\InventoryAllocator($pdo);
$wf = new Booking\BookingWorkflow($pdo, $repo, $inv);
$n = 0;
foreach ($repo->expiredHoldIds() as $id) {
    $n += $wf->expire($id, time()) ? 1 : 0;
}
echo json_encode(['expired' => $n]), "\n";
