<?php
declare(strict_types=1);

// Logic checks: plain PHP, no database, no network. Run: php tests/run.php (exit code 1 on any failure).
require dirname(__DIR__) . '/autoload.php';

function check(bool $ok, string $what): void
{
    if (!$ok) {
        throw new RuntimeException($what);
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    check($expected === $actual, $what . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

/** Assert that $fn throws $class (and, for ApiException, the given HTTP status). */
function throws(callable $fn, string $class, ?int $status = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, 'threw ' . $e::class . ' instead of ' . $class . ': ' . $e->getMessage());
        if ($status !== null) {
            same($status, $e->status, 'http status');
        }
        return;
    }
    throw new RuntimeException("expected {$class} but nothing was thrown");
}

$total = 0;
$failed = 0;
foreach (glob(__DIR__ . '/cases/*.php') as $file) {
    foreach ((require $file) as $name => $case) {
        $total++;
        try {
            $case();
            echo "ok    {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "FAIL  {$name}: {$e->getMessage()}\n";
        }
    }
}
echo "\n{$total} checks, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
