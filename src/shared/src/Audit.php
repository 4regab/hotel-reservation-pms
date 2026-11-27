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
        Db::call($pdo, 'usp_audit_write', [
            'actor_id' => $user['accountId'], 'actor_role' => $user['role'], 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId,
            'detail' => $detail === [] ? null : json_encode(Pii::scrub($detail), JSON_UNESCAPED_SLASHES), 'ip' => $ip,
        ]);
    }

    /** @return list<array> newest first. Filters: action (prefix), actor, from, to (dates, UTC). */
    public static function search(\PDO $pdo, array $f, int $limit = 100): array
    {
        $st = Db::call($pdo, 'usp_audit_search', [
            'action_prefix' => !empty($f['action']) ? (string) $f['action'] : null,
            'actor' => !empty($f['actor']) ? (string) $f['actor'] : null,
            'from_date' => !empty($f['from']) && Dates::isDate((string) $f['from']) ? (string) $f['from'] : null,
            'to_date' => !empty($f['to']) && Dates::isDate((string) $f['to']) ? (string) $f['to'] : null,
            'row_limit' => max(1, min(500, $limit)),
        ]);
        // audit_id is BIGINT, which the driver returns as a string; the API has always returned a number.
        return array_map(static fn (array $r): array => ['auditId' => (int) $r['auditId']] + $r, $st->fetchAll());
    }

    /** Outbox health for the IT page: delivery counts and the oldest stuck rows. */
    public static function outboxHealth(\PDO $pdo): array
    {
        $counts = Db::call($pdo, 'usp_outbox_counts')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $stuck = Db::call($pdo, 'usp_outbox_stuck', ['row_limit' => 20])->fetchAll();
        return ['pending' => (int) ($counts['PENDING'] ?? 0), 'review' => (int) ($counts['REVIEW'] ?? 0), 'delivered' => (int) ($counts['DELIVERED'] ?? 0), 'stuck' => $stuck];
    }
}
