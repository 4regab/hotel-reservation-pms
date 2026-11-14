<?php
declare(strict_types=1);

namespace Lib;

final class Db
{
    /** Connect with <PREFIX>_DB_HOST / _PORT / _NAME / _USER / _PASS. Each service has its own database and credentials. */
    public static function connect(string $prefix): \PDO
    {
        $p = strtoupper($prefix) . '_DB_';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv($p . 'HOST') ?: 'mysql', getenv($p . 'PORT') ?: '3306', getenv($p . 'NAME'));
        $pdo = new \PDO($dsn, (string) getenv($p . 'USER'), (string) getenv($p . 'PASS'), [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
