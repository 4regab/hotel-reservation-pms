<?php
declare(strict_types=1);

namespace Portal;

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
        $st = $this->pdo->prepare('SELECT data FROM session WHERE session_id = ? AND updated_at > ?');
        $st->execute([$id, time() - $this->ttl]);
        $d = $st->fetchColumn();
        return $d === false ? '' : (string) $d;
    }

    public function write(string $id, string $data): bool
    {
        $this->pdo->prepare('INSERT INTO session (session_id, data, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)')->execute([$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM session WHERE session_id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = $this->pdo->prepare('DELETE FROM session WHERE updated_at < ?');
        $st->execute([time() - $max_lifetime]);
        return $st->rowCount();
    }
}
