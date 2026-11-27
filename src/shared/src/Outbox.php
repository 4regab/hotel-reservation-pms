<?php
declare(strict_types=1);

namespace Lib;

final class Outbox
{
    /** Write an event and one delivery row per subscribed handler. Call inside the business transaction. */
    public static function add(\PDO $pdo, string $type, string $aggregateId, int $aggregateVersion, string $correlationId, array $data): string
    {
        $env = Event::envelope($type, $aggregateId, $aggregateVersion, $correlationId, $data);
        Db::call($pdo, 'usp_outbox_add', [
            'event_id' => $env['eventId'], 'event_type' => $type, 'aggregate_id' => $aggregateId, 'aggregate_version' => $aggregateVersion,
            'correlation_id' => $correlationId, 'payload' => json_encode($env, JSON_UNESCAPED_SLASHES),
            'handlers' => json_encode(Routing::handlers($type)),
        ]);
        return $env['eventId'];
    }
}
