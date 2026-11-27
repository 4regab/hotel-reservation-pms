<?php
declare(strict_types=1);

namespace Portal;

use Lib\Db;

/** PHP sessions kept in the portal database (the session table). */
final class SessionStore implements \SessionHandlerInterface
{
    public function __construct(private \PDO $pdo, private int $ttl = 7200)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $d = Db::call($this->pdo, 'usp_session_read', ['session_id' => $id, 'min_updated_at' => time() - $this->ttl])->fetchColumn();
        return $d === false ? '' : (string) $d;
    }

    public function write(string $id, string $data): bool
    {
        Db::call($this->pdo, 'usp_session_write', ['session_id' => $id, 'data' => $data, 'updated_at' => time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        Db::call($this->pdo, 'usp_session_destroy', ['session_id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return (int) Db::call($this->pdo, 'usp_session_purge', ['before' => time() - $max_lifetime])->fetchColumn();
    }
}
