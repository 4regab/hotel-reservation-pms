<?php
declare(strict_types=1);

namespace Lib;

/** Canonical event envelope. Guest contact details never go in `data`. */
final class Event
{
    public static function envelope(string $type, string $aggregateId, int $aggregateVersion, string $correlationId, array $data, ?int $now = null): array
    {
        Pii::assertClean($data);
        return [
            'eventId'          => Uuid::v4(),
            'schemaVersion'    => 1,
            'eventType'        => $type,
            'occurredAt'       => Dates::iso($now),
            'aggregateId'      => $aggregateId,
            'aggregateVersion' => $aggregateVersion,
            'correlationId'    => $correlationId,
            'data'             => $data,
        ];
    }
}
