<?php
declare(strict_types=1);

// Migrations runner. Creates each service database and its limited login (if missing), then applies
// every not-yet-applied migrations/*.sql file in name order, once. Safe to run again.
// A file is split into batches on lines that hold only GO, and the whole file commits or rolls back together.
// Env: DB_ADMIN_HOST, DB_ADMIN_PORT, DB_ADMIN_USER, DB_ADMIN_PASS (empty user = Windows sign-in), plus <SERVICE>_DB_NAME/_USER/_PASS.
// Usage: php scripts/migrate.php [service ...]   (default: all four)
require dirname(__DIR__) . '/autoload.php';

$root = dirname(__DIR__);
$services = array_slice($argv, 1) ?: ['booking', 'roomops', 'billing', 'portal'];
$admin = new PDO(Lib\Db::dsn(getenv('DB_ADMIN_HOST') ?: '127.0.0.1', getenv('DB_ADMIN_PORT') ?: '1433'), (string) getenv('DB_ADMIN_USER'), (string) getenv('DB_ADMIN_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$ident = static fn (string $s): string => '[' . str_replace(']', ']]', $s) . ']';
$text = static fn (string $s): string => "N'" . str_replace("'", "''", $s) . "'";

foreach ($services as $svc) {
    $p = strtoupper($svc) . '_DB_';
    $name = (string) getenv($p . 'NAME');
    $user = (string) getenv($p . 'USER');
    $pass = (string) getenv($p . 'PASS');
    if ($name === '' || $user === '' || $pass === '') {
        fwrite(STDERR, "missing {$p}NAME/USER/PASS\n");
        exit(2);
    }
    $admin->exec("USE master; IF DB_ID({$text($name)}) IS NULL CREATE DATABASE {$ident($name)} COLLATE Latin1_General_100_CI_AI_SC_UTF8");
    // Plain reads see the last committed data instead of waiting on writers, as in MySQL. Locking reads (UPDLOCK) still wait.
    $st = $admin->prepare('SELECT is_read_committed_snapshot_on FROM sys.databases WHERE name = ?');
    $st->execute([$name]);
    if ((int) $st->fetchColumn() !== 1) {
        $admin->exec("ALTER DATABASE {$ident($name)} SET READ_COMMITTED_SNAPSHOT ON WITH ROLLBACK IMMEDIATE");
    }
    $admin->exec('USE ' . $ident($name));
    $admin->exec("IF OBJECT_ID(N'dbo.schema_migrations', N'U') IS NULL CREATE TABLE schema_migrations (filename NVARCHAR(120) NOT NULL PRIMARY KEY, applied_at DATETIME2(0) NOT NULL)");
    $done = $admin->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob("{$root}/{$svc}/migrations/*.sql") ?: [];
    sort($files);
    foreach ($files as $f) {
        $base = basename($f);
        if (in_array($base, $done, true)) {
            continue;
        }
        echo "[{$svc}] applying {$base}\n";
        $admin->beginTransaction();
        try {
            foreach (preg_split('/^\s*GO\s*$/mi', (string) file_get_contents($f)) as $batch) {
                if (trim($batch) !== '') {
                    $admin->exec($batch);
                }
            }
            $admin->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, DATETRUNC(SECOND, SYSUTCDATETIME()))')->execute([$base]);
            $admin->commit();
        } catch (Throwable $e) {
            if ($admin->inTransaction()) {
                $admin->rollBack();
            }
            fwrite(STDERR, "[{$svc}] {$base} failed: {$e->getMessage()}\n");
            exit(1);
        }
    }
    // One limited login per database: data changes only, no schema changes, and no user in any other database.
    // ponytail: CHECK_POLICY OFF because the generated hex passwords fail Windows' character-mix rule; their length is what makes them strong.
    $login = "CREATE LOGIN {$ident($user)} WITH PASSWORD = {$text($pass)}, DEFAULT_DATABASE = {$ident($name)}, CHECK_POLICY = OFF";
    $admin->exec("IF SUSER_ID({$text($user)}) IS NULL EXEC({$text($login)}) ELSE ALTER LOGIN {$ident($user)} WITH PASSWORD = {$text($pass)}");
    $admin->exec("IF USER_ID({$text($user)}) IS NULL CREATE USER {$ident($user)} FOR LOGIN {$ident($user)}");
    // Per-table grants so audit_log stays append-only (no UPDATE or DELETE) and schema_migrations is untouchable.
    $old = $admin->prepare("SELECT p.permission_name, s.name AS sch, o.name AS obj FROM sys.database_permissions p
                              JOIN sys.objects o ON o.object_id = p.major_id JOIN sys.schemas s ON s.schema_id = o.schema_id
                             WHERE p.class = 1 AND p.grantee_principal_id = USER_ID(?)");
    $old->execute([$user]);
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $admin->exec("REVOKE {$r['permission_name']} ON {$ident($r['sch'])}.{$ident($r['obj'])} FROM {$ident($user)}");
    }
    foreach ($admin->query('SELECT name FROM sys.tables WHERE is_ms_shipped = 0')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($table === 'schema_migrations') {
            continue;
        }
        $privs = $table === 'audit_log' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
        $admin->exec("GRANT {$privs} ON dbo.{$ident($table)} TO {$ident($user)}");
    }
}
echo "migrations up to date\n";
