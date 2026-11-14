<?php
declare(strict_types=1);

// Migrations runner. Creates each service database and its limited user (if missing), then applies
// every not-yet-applied migrations/*.sql file in name order, once. Safe to run again.
// Env: DB_ADMIN_HOST, DB_ADMIN_PORT, DB_ADMIN_USER, DB_ADMIN_PASS, plus <SERVICE>_DB_NAME/_USER/_PASS.
// Usage: php scripts/migrate.php [service ...]   (default: all four)

$root = dirname(__DIR__);
$services = array_slice($argv, 1) ?: ['booking', 'roomops', 'billing', 'portal'];
$host = getenv('DB_ADMIN_HOST') ?: '127.0.0.1';
$port = getenv('DB_ADMIN_PORT') ?: '3306';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", (string) (getenv('DB_ADMIN_USER') ?: 'root'), (string) getenv('DB_ADMIN_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true, // lets one file hold many statements
]);
$ident = static fn (string $s): string => '`' . str_replace('`', '', $s) . '`';
$hostPattern = getenv('DB_APP_HOST_PATTERN') ?: '%';

foreach ($services as $svc) {
    $p = strtoupper($svc) . '_DB_';
    $name = (string) getenv($p . 'NAME');
    $user = (string) getenv($p . 'USER');
    $pass = (string) getenv($p . 'PASS');
    if ($name === '' || $user === '' || $pass === '') {
        fwrite(STDERR, "missing {$p}NAME/USER/PASS\n");
        exit(2);
    }
    $admin->exec("CREATE DATABASE IF NOT EXISTS {$ident($name)} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $admin->exec('USE ' . $ident($name));
    $admin->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(120) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
    $done = $admin->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob("{$root}/{$svc}/migrations/*.sql") ?: [];
    sort($files);
    foreach ($files as $f) {
        $base = basename($f);
        if (in_array($base, $done, true)) {
            continue;
        }
        echo "[{$svc}] applying {$base}\n";
        $admin->exec((string) file_get_contents($f));
        $admin->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, UTC_TIMESTAMP())')->execute([$base]);
    }
    // One limited user per database: data changes only, no schema changes.
    $q = $admin->quote($user) . '@' . $admin->quote($hostPattern);
    $admin->exec("CREATE USER IF NOT EXISTS {$q} IDENTIFIED BY " . $admin->quote($pass));
    $admin->exec("ALTER USER {$q} IDENTIFIED BY " . $admin->quote($pass));
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON {$ident($name)}.* TO {$q}");
}
echo "migrations up to date\n";
