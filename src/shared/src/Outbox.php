<?php
declare(strict_types=1);

namespace Lib;

final class Outbox
{
    /** Write an event and one delivery row per subscribed handler. Call inside the business transaction. */
    public static function add(\PDO $pdo, string $type, string $aggregateId, int $aggregateVersion, string $correlationId, array $data): string
    {
        $env = Event::envelope($type, $aggregateId, $aggregateVersion, $correlationId, $data);
        $pdo->prepare('INSERT INTO outbox (event_id, event_type, aggregate_id, aggregate_version, correlation_id, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, DATETRUNC(SECOND, SYSUTCDATETIME()))')
            ->execute([$env['eventId'], $type, $aggregateId, $aggregateVersion, $correlationId, json_encode($env, JSON_UNESCAPED_SLASHES)]);
        $ins = $pdo->prepare("INSERT INTO outbox_delivery (event_id, handler_name, status, attempt_count, next_attempt_at) VALUES (?, ?, 'PENDING', 0, DATETRUNC(SECOND, SYSUTCDATETIME()))");
        foreach (Routing::handlers($type) as $handler) {
            $ins->execute([$env['eventId'], $handler]);
        }
        return $env['eventId'];
    }
}
