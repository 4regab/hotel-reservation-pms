<?php
declare(strict_types=1);

namespace Lib;

final class Db
{
    /** Connect with <PREFIX>_DB_HOST / _PORT / _NAME / _USER / _PASS. Each service has its own database and credentials. */
    public static function connect(string $prefix): \PDO
    {
        $p = strtoupper($prefix) . '_DB_';
        $pdo = new \PDO(self::dsn(getenv($p . 'HOST') ?: 'mssql', getenv($p . 'PORT') ?: '1433', (string) getenv($p . 'NAME')), (string) getenv($p . 'USER'), (string) getenv($p . 'PASS'), [
            \PDO::ATTR_ERRMODE                     => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE          => \PDO::FETCH_ASSOC,
            \PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true, // INT columns come back as PHP ints (BIGINT still comes back as a string)
        ]);
        // Wait at most 50 seconds for a row lock, like MySQL's default, instead of forever.
        $pdo->exec('SET LOCK_TIMEOUT 50000');
        return $pdo;
    }

    /**
     * SQL Server connection string. An empty user and password means Windows sign-in (local development).
     * DB_TRUST_SERVER_CERT=0 makes the driver check the server's TLS certificate; the default trusts a self-signed one.
     */
    public static function dsn(string $host, string $port, string $database = ''): string
    {
        $trust = getenv('DB_TRUST_SERVER_CERT') === '0' ? 'no' : 'yes';
        return "sqlsrv:Server={$host},{$port};Encrypt=yes;TrustServerCertificate={$trust}" . ($database === '' ? '' : ";Database={$database}");
    }

    /** The statement `Db::call` runs: EXEC dbo.<proc> @a = ?, @b = ?. Names are checked, so nothing from outside can reach the text. */
    public static function callSql(string $proc, array $names): string
    {
        $ok = static fn (string $n): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n) === 1;
        if (!$ok($proc)) {
            throw new \InvalidArgumentException("bad procedure name {$proc}");
        }
        foreach ($names as $n) {
            if (!$ok((string) $n)) {
                throw new \InvalidArgumentException("bad parameter name {$n}");
            }
        }
        return 'EXEC dbo.' . $proc . ($names === [] ? '' : ' ' . implode(', ', array_map(static fn ($n): string => '@' . $n . ' = ?', $names)));
    }

    /**
     * Call a stored procedure. Parameters are keyed by their name without the @ and travel as bound values.
     * The procedure's result set (if any) is read from the returned statement. A procedure never opens or ends a
     * transaction: the caller does, so one `Tx::run` still covers several calls.
     */
    public static function call(\PDO $pdo, string $proc, array $params = []): \PDOStatement
    {
        $st = $pdo->prepare(self::callSql($proc, array_keys($params)));
        $st->execute(array_values($params));
        return $st;
    }

    /** True when the error is a duplicate key (unique constraint 2627 or unique index 2601). */
    public static function isDuplicateKey(\PDOException $e): bool
    {
        return in_array($e->errorInfo[1] ?? 0, [2601, 2627], true);
    }
}
