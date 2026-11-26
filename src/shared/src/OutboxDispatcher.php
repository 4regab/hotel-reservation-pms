<?php
declare(strict_types=1);

namespace Lib;

/**
 * Scheduled CLI job: deliver committed outbox events over authenticated HTTPS and retry failures.
 * A delivery is acknowledged only after the receiver answers 2xx (its inbox + update committed).
 */
final class OutboxDispatcher
{
    public function __construct(private \PDO $pdo, private string $service)
    {
    }

    /** @return array{delivered:int,failed:int,review:int} */
    public function run(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $rows = Db::call($this->pdo, 'usp_outbox_due', ['row_limit' => $limit])->fetchAll();
        $out = ['delivered' => 0, 'failed' => 0, 'review' => 0];
        foreach ($rows as $row) {
            [$status] = Http::call('POST', Routing::url($row['handler_name']) . '/internal/events', ServiceAuth::headers($this->service, $row['handler_name']), $row['payload'], 5);
            if ($status >= 200 && $status < 300) {
                Db::call($this->pdo, 'usp_outbox_mark_delivered', ['event_id' => $row['event_id'], 'handler_name' => $row['handler_name']]);
                $out['delivered']++;
                continue;
            }
            $attempts = (int) $row['attempt_count'] + 1;
            $next = Retry::afterFailure($attempts);
            Db::call($this->pdo, 'usp_outbox_mark_failed', [
                'event_id' => $row['event_id'], 'handler_name' => $row['handler_name'], 'attempt_count' => $attempts, 'status' => $next['status'],
                'delay_seconds' => (int) $next['delay'], 'last_error' => $status === 0 ? 'unreachable' : "http {$status}",
            ]);
            $out[$next['status'] === 'REVIEW' ? 'review' : 'failed']++;
            Log::info('event delivery failed', ['eventId' => $row['event_id'], 'handler' => $row['handler_name'], 'attempt' => $attempts, 'status' => $next['status']]);
        }
        return $out;
    }
}
