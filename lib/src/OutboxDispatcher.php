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
        $rows = $this->pdo->query(
            "SELECT d.event_id, d.handler_name, d.attempt_count, o.payload
               FROM outbox_delivery d JOIN outbox o ON o.event_id = d.event_id
              WHERE d.status = 'PENDING' AND d.next_attempt_at <= UTC_TIMESTAMP()
              ORDER BY o.created_at LIMIT {$limit}"
        )->fetchAll();
        $out = ['delivered' => 0, 'failed' => 0, 'review' => 0];
        foreach ($rows as $row) {
            [$status] = Http::call('POST', Routing::url($row['handler_name']) . '/internal/events', ServiceAuth::headers($this->service, $row['handler_name']), $row['payload'], 5);
            if ($status >= 200 && $status < 300) {
                $this->pdo->prepare("UPDATE outbox_delivery SET status = 'DELIVERED', delivered_at = UTC_TIMESTAMP(), last_error = NULL WHERE event_id = ? AND handler_name = ?")
                    ->execute([$row['event_id'], $row['handler_name']]);
                $out['delivered']++;
                continue;
            }
            $attempts = (int) $row['attempt_count'] + 1;
            $next = Retry::afterFailure($attempts);
            $this->pdo->prepare('UPDATE outbox_delivery SET attempt_count = ?, status = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), last_error = ? WHERE event_id = ? AND handler_name = ?')
                ->execute([$attempts, $next['status'], $next['delay'], $status === 0 ? 'unreachable' : "http {$status}", $row['event_id'], $row['handler_name']]);
            $out[$next['status'] === 'REVIEW' ? 'review' : 'failed']++;
            Log::info('event delivery failed', ['eventId' => $row['event_id'], 'handler' => $row['handler_name'], 'attempt' => $attempts, 'status' => $next['status']]);
        }
        return $out;
    }
}
