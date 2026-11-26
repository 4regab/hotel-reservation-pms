<?php
declare(strict_types=1);

// Fails when PHP code under src/ holds a SQL statement of its own. Data access goes through stored procedures
// (Lib\Db::call), which read from views. Usage: php scripts/check-no-inline-sql.php [path ...]   (default: src)
$root = dirname(__DIR__);
$paths = array_slice($argv, 1) ?: ['src'];
$pattern = '/\b(SELECT\s+[\w*@(\[].*\bFROM\b|SELECT\s+(?:TOP|COUNT|1\b)|INSERT\s+INTO|UPDATE\s+\[?\w+\]?\s+SET|DELETE\s+FROM|MERGE\s+(?:INTO\s+)?\w+|WITH\s*\(\s*(?:UPDLOCK|HOLDLOCK|ROWLOCK))/';
$found = [];
foreach ($paths as $path) {
    $base = str_starts_with($path, '/') ? $path : $root . '/' . $path;
    $files = is_file($base) ? [$base] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $name = (string) $file;
        if (!str_ends_with($name, '.php') || str_contains($name, '/views/')) {
            continue;
        }
        foreach (file($name) ?: [] as $i => $text) {
            $trim = ltrim($text);
            if ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*') || str_starts_with($trim, '#')) {
                continue;
            }
            if (preg_match($pattern, $text)) {
                $found[] = substr($name, strlen($root) + 1) . ':' . ($i + 1);
            }
        }
    }
}
if ($found) {
    fwrite(STDERR, 'Inline SQL in PHP (' . count($found) . "):\n  " . implode("\n  ", $found) . "\nMove it into a stored procedure or a view and call it with Lib\\Db::call.\n");
    exit(1);
}
echo "No inline SQL in PHP.\n";
