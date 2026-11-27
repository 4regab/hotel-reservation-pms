<?php
declare(strict_types=1);

namespace Lib;

final class Inbox
{
    /**
     * Apply an event at most once. The inbox row and the local update commit together, so a
     * crash cannot leave one without the other. Returns false when the event was already applied.
     */
    public static function once(\PDO $pdo, string $eventId, callable $apply): bool
    {
        return (bool) Tx::run($pdo, static function () use ($pdo, $eventId, $apply): bool {
            // HOLDLOCK keeps a second delivery of the same event waiting here until this one commits.
            if ((int) Db::call($pdo, 'usp_inbox_record', ['event_id' => $eventId])->fetchColumn() === 0) {
                return false;
            }
            $apply();
            return true;
        });
    }
}
