<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Audit;
use Lib\Http;
use Lib\Peer;
use Lib\Routing;

/** Audit trail merged from every service, plus job, outbox and service health for the IT lead. */
final class Oversight
{
    private const SERVICES = ['booking', 'roomops', 'billing'];

    public function __construct(private \PDO $pdo, private Accounts $accounts)
    {
    }

    /** Newest first across all four audit trails. A service that is down is reported, not hidden. */
    public function auditFeed(array $actor, array $filters): array
    {
        $q = array_intersect_key($filters, array_flip(['action', 'actor', 'from', 'to']));
        $rows = [];
        foreach (Audit::search($this->pdo, $q, 100) as $r) {
            $rows[] = $r + ['source' => 'portal'];
        }
        $unavailable = [];
        foreach (self::SERVICES as $svc) {
            try {
                $d = Peer::ok('portal', $svc, 'GET', '/internal/audit?' . http_build_query($q), $actor);
                foreach ($d['entries'] as $r) {
                    $rows[] = $r + ['source' => $svc];
                }
            } catch (ApiException) {
                $unavailable[] = $svc;
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        $rows = array_slice($rows, 0, 200);
        $labels = $this->accounts->labels(array_column($rows, 'actorId'));
        foreach ($rows as &$r) {
            $r['actorLabel'] = $labels[$r['actorId']] ?? $r['actorId'];
            $r['detail'] = is_string($r['detail'] ?? null) ? json_decode($r['detail'], true) : ($r['detail'] ?? null);
        }
        return ['entries' => $rows, 'unavailable' => $unavailable];
    }

    /** @return array{services:list<array>,outbox:array,notifications:array,emails:array} */
    public function health(array $actor): array
    {
        $services = [];
        $outbox = [];
        foreach (array_merge(self::SERVICES, ['portal']) as $svc) {
            $t = microtime(true);
            $ok = false;
            if ($svc === 'portal') {
                $ok = true;
            } else {
                [$code] = Http::call('GET', Routing::url($svc) . '/healthz', [], null, 3);
                $ok = $code === 200;
            }
            $services[] = ['name' => $svc, 'ok' => $ok, 'ms' => (int) round((microtime(true) - $t) * 1000)];
            if ($svc !== 'portal' && $ok) {
                try {
                    $outbox[$svc] = Peer::ok('portal', $svc, 'GET', '/internal/ops/outbox', $actor);
                } catch (ApiException) {
                    $outbox[$svc] = null;
                }
            }
        }
        $jobs = $this->pdo->query("SELECT status, COUNT(*) AS n FROM notification_job GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $failed = $this->pdo->query("SELECT job_id, type, attempts, last_error, created_at FROM notification_job WHERE status IN ('FAILED') OR (status = 'PENDING' AND attempts > 0) ORDER BY created_at DESC LIMIT 20")->fetchAll();
        $mail = $this->pdo->query("SELECT status, COUNT(*) AS n FROM email_log WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $oldest = $this->pdo->query("SELECT MIN(created_at) FROM notification_job WHERE status = 'PENDING'")->fetchColumn();
        return [
            'services' => $services, 'outbox' => $outbox,
            'notifications' => ['pending' => (int) ($jobs['PENDING'] ?? 0), 'sent' => (int) ($jobs['SENT'] ?? 0), 'failed' => (int) ($jobs['FAILED'] ?? 0), 'oldestPending' => $oldest ?: null, 'attention' => $failed],
            'emails' => ['sent' => (int) ($mail['SENT'] ?? 0), 'logged' => (int) ($mail['LOGGED'] ?? 0), 'failed' => (int) ($mail['FAILED'] ?? 0), 'live' => Mailer::live()],
        ];
    }

    /** Recent emails for the IT page (subject and status only, never the body). */
    public function recentEmails(int $limit = 25): array
    {
        return $this->pdo->query('SELECT email_id, template, to_email, subject, status, created_at FROM email_log ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll();
    }
}
