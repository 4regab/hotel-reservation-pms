<?php
declare(strict_types=1);

// Fails when one service uses another service's code.
// A service may use Lib\ (src/shared) and its own namespace. src/shared may use no service at all.
// Services talk to each other over HTTP and events only. See docs/adr/0003-service-boundaries.md.
// Reads real PHP names (use lines, new, static calls), so words inside strings and comments are ignored.
// Usage: php scripts/check-boundaries.php

$root = dirname(__DIR__);
$owner = ['booking' => 'Booking', 'roomops' => 'RoomOps', 'billing' => 'Billing', 'portal' => 'Portal', 'shared' => 'Lib'];
$services = ['Booking', 'RoomOps', 'Billing', 'Portal'];
$names = [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

$found = [];
$files = 0;
foreach ($owner as $dir => $ns) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $files++;
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $tok) {
            if (!is_array($tok) || !in_array($tok[0], $names, true)) {
                continue;
            }
            $first = explode('\\', ltrim($tok[1], '\\'))[0];
            if (in_array($first, $services, true) && $first !== $ns) {
                $found[] = sprintf('%s:%d  %s uses %s', substr($file->getPathname(), strlen($root) + 1), $tok[2], $ns, ltrim($tok[1], '\\'));
            }
        }
    }
}

if ($found) {
    echo implode("\n", $found), "\n", count($found), " boundary violation(s) in {$files} files. Services may only use Lib\\ and their own namespace.\n";
    exit(1);
}
echo "boundaries ok: {$files} files, no service uses another service's code\n";
