<?php
declare(strict_types=1);

// Tiny PSR-4 style autoloader. One source tree, four deployables:
// each service only ever loads Lib\ plus its own namespace.
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    static $map = [
        'Lib\\'     => 'src/shared/src/',
        'Booking\\' => 'src/booking/src/',
        'RoomOps\\' => 'src/roomops/src/',
        'Billing\\' => 'src/billing/src/',
        'Portal\\'  => 'src/portal/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
