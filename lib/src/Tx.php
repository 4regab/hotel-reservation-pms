<?php
declare(strict_types=1);

namespace Lib;

final class Tx
{
    /** Run $fn in one InnoDB transaction; joins an already open one. Rolls back on any exception. */
    public static function run(\PDO $pdo, callable $fn): mixed
    {
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
