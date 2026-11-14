<?php
declare(strict_types=1);

namespace Lib;

/**
 * Append-only audit trail. Every service keeps its own audit_log table (the app database user can
 * INSERT and SELECT on it, never UPDATE or DELETE). The portal's audit viewer merges all four.
 * Detail must be free of contact and card data (Pii::scrub is applied).
 */
final class Audit
{
    /** @param array{accountId:string,role:string} $user */
    public static function record(\PDO $pdo, array $user, string $action, string $targetType, string $targetId, array $detail = [], ?string $ip = null): void
    {
        $pdo->prepare('INSERT INTO audit_log (at, actor_id, actor_role, action, target_type, target_id, detail, ip) VALUES (UTC_TIMESTAMP(3), ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$user['accountId'], $user['role'], $action, $targetType, $targetId, $detail === [] ? null : json_encode(Pii::scrub($detail), JSON_UNESCAPED_SLASHES), $ip]);
    }

    /** @return list<array> newest first. Filters: action (prefix), actor, from, to (dates, UTC). */
    public static function search(\PDO $pdo, array $f, int $limit = 100): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['action'])) {
            $where[] = 'action LIKE ?';
            $args[] = str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['action']) . '%';
        }
        if (!empty($f['actor'])) {
            $where[] = 'actor_id = ?';
            $args[] = (string) $f['actor'];
        }
        if (!empty($f['from']) && Dates::isDate((string) $f['from'])) {
            $where[] = 'at >= ?';
            $args[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to']) && Dates::isDate((string) $f['to'])) {
            $where[] = 'at < DATE_ADD(?, INTERVAL 1 DAY)';
            $args[] = $f['to'];
        }
        $limit = max(1, min(500, $limit));
        $st = $pdo->prepare('SELECT audit_id AS auditId, at, actor_id AS actorId, actor_role AS actorRole, action, target_type AS targetType, target_id AS targetId, detail, ip FROM audit_log WHERE ' . implode(' AND ', $where) . " ORDER BY audit_id DESC LIMIT {$limit}");
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Outbox health for the IT page: delivery counts and the oldest stuck rows. */
    public static function outboxHealth(\PDO $pdo): array
    {
        $counts = $pdo->query('SELECT status, COUNT(*) AS n FROM outbox_delivery GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $stuck = $pdo->query("SELECT d.event_id AS eventId, d.handler_name AS handler, d.status, d.attempt_count AS attempts, d.last_error AS lastError, o.event_type AS eventType, o.created_at AS createdAt
                                FROM outbox_delivery d JOIN outbox o ON o.event_id = d.event_id
                               WHERE d.status <> 'DELIVERED' ORDER BY o.created_at LIMIT 20")->fetchAll();
        return ['pending' => (int) ($counts['PENDING'] ?? 0), 'review' => (int) ($counts['REVIEW'] ?? 0), 'delivered' => (int) ($counts['DELIVERED'] ?? 0), 'stuck' => $stuck];
    }
}
