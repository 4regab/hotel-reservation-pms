<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;
use Lib\Audit;
use Lib\Csv;
use Lib\Dates;
use Lib\Outbox;
use Lib\Tx;
use Lib\Uuid;

/** Payment lists, reconciliation, refund decisions, approved accounting exports and revenue figures. */
final class FinanceService
{
    private const MANILA = "CAST(DATEADD(HOUR, 8, %s) AS DATE)";

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array{0:string,1:string} validated from/to (defaults: last 30 days) */
    public static function range(array $q, int $defaultDays = 30): array
    {
        $to = Dates::isDate((string) ($q['to'] ?? '')) ? (string) $q['to'] : Dates::manilaToday();
        $from = Dates::isDate((string) ($q['from'] ?? '')) ? (string) $q['from'] : (new \DateTimeImmutable($to))->modify('-' . ($defaultDays - 1) . ' days')->format('Y-m-d');
        if ($to < $from || (strtotime($to) - strtotime($from)) / 86400 > 400) {
            throw new ApiException(422, 'INVALID_REQUEST', 'from/to must be dates, to not before from, at most 400 days apart');
        }
        return [$from, $to];
    }

    /** GET /internal/payments?from&to&reconciled=0|1 */
    public function payments(array $q): array
    {
        [$from, $to] = self::range($q);
        $day = sprintf(self::MANILA, 'p.created_at');
        $where = "{$day} BETWEEN ? AND ?";
        $args = [$from, $to];
        if (($q['reconciled'] ?? '') === '0') {
            $where .= " AND p.reconciled_at IS NULL AND p.status = 'CAPTURED'";
        } elseif (($q['reconciled'] ?? '') === '1') {
            $where .= ' AND p.reconciled_at IS NOT NULL';
        }
        if (($q['status'] ?? '') === 'REVIEW') {
            $where .= " AND p.status = 'REVIEW'";
        }
        $st = $this->pdo->prepare(
            "SELECT TOP (300) p.payment_id AS paymentId, p.reservation_id AS reservationId, p.provider, p.method, p.provider_payment_id AS providerPaymentId, p.amount_minor AS amountMinor, p.currency, p.status,
                    p.created_at AS createdAt, p.reconciled_at AS reconciledAt, {$day} AS day,
                    (SELECT r.status FROM refund r WHERE r.payment_id = p.payment_id) AS refundStatus
               FROM payment p WHERE {$where} ORDER BY p.created_at DESC"
        );
        $st->execute($args);
        return [200, ['from' => $from, 'to' => $to, 'payments' => array_map(fn ($r) => ['amountMinor' => (int) $r['amountMinor']] + $r, $st->fetchAll())]];
    }

    public function reconcile(string $paymentId, array $user, ?string $ip): array
    {
        $st = $this->pdo->prepare("UPDATE payment SET reconciled_at = DATETRUNC(SECOND, SYSUTCDATETIME()), reconciled_by = ? WHERE payment_id = ? AND status = 'CAPTURED' AND reconciled_at IS NULL");
        $st->execute([$user['accountId'], $paymentId]);
        if ($st->rowCount() === 0) {
            throw new ApiException(409, 'NOT_RECONCILABLE', 'Payment not found, not captured, or already reconciled');
        }
        Audit::record($this->pdo, $user, 'payment.reconciled', 'payment', $paymentId, [], $ip);
        return [200, ['paymentId' => $paymentId, 'reconciled' => true]];
    }

    /** Reconcile every unreconciled captured payment up to and including a date. */
    public function reconcileUpTo(string $date, array $user, ?string $ip): array
    {
        if (!Dates::isDate($date)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'date must be YYYY-MM-DD');
        }
        $st = $this->pdo->prepare("UPDATE payment SET reconciled_at = DATETRUNC(SECOND, SYSUTCDATETIME()), reconciled_by = ? WHERE status = 'CAPTURED' AND reconciled_at IS NULL AND " . sprintf(self::MANILA, 'created_at') . ' <= ?');
        $st->execute([$user['accountId'], $date]);
        Audit::record($this->pdo, $user, 'payment.reconciled_batch', 'payment', $date, ['count' => $st->rowCount()], $ip);
        return [200, ['reconciled' => $st->rowCount()]];
    }

    public function refunds(string $status): array
    {
        $where = in_array($status, ['REQUESTED', 'COMPLETED', 'REJECTED'], true) ? 'WHERE r.status = ' . $this->pdo->quote($status) : '';
        $rows = $this->pdo->query(
            "SELECT TOP (200) r.refund_id AS refundId, r.payment_id AS paymentId, p.reservation_id AS reservationId, r.amount_minor AS amountMinor, p.currency, r.approval, r.status, r.requested_by AS requestedBy,
                    r.reason, r.created_at AS createdAt, r.completed_at AS completedAt, r.decision_note AS decisionNote, p.amount_minor AS paymentMinor
               FROM refund r JOIN payment p ON p.payment_id = r.payment_id {$where} ORDER BY r.created_at DESC"
        )->fetchAll();
        return [200, ['refunds' => array_map(fn ($r) => ['amountMinor' => (int) $r['amountMinor'], 'paymentMinor' => (int) $r['paymentMinor']] + $r, $rows)]];
    }

    /** A manager declines a refund (with a note). The requester is told through the audit trail and the queue. */
    public function rejectRefund(string $refundId, string $note, array $user, ?string $ip): array
    {
        $note = mb_substr(trim($note), 0, 200);
        if ($note === '') {
            throw new ApiException(422, 'INVALID_REQUEST', 'A note explaining the decision is required');
        }
        return Tx::run($this->pdo, function () use ($refundId, $note, $user, $ip): array {
            $st = $this->pdo->prepare('SELECT requested_by, status FROM refund WITH (UPDLOCK, ROWLOCK) WHERE refund_id = ?');
            $st->execute([$refundId]);
            $r = $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
            RefundPolicy::assertCanApprove($user['role'], $user['accountId'], $r['requested_by']);
            if ($r['status'] !== 'REQUESTED') {
                throw new ApiException(409, 'REFUND_NOT_PENDING', 'This refund was already decided');
            }
            $this->pdo->prepare("UPDATE refund SET approval = 'REJECTED', status = 'REJECTED', approved_by = ?, decision_note = ? WHERE refund_id = ?")->execute([$user['accountId'], $note, $refundId]);
            Audit::record($this->pdo, $user, 'refund.rejected', 'refund', $refundId, ['note' => $note], $ip);
            return [200, ['refundId' => $refundId, 'status' => 'REJECTED']];
        });
    }

    /** Take payment for a live hold at the front desk (walk-ins). Confirms the booking through the normal PaymentCaptured event. */
    public function onsitePayment(array $reservation, string $method, array $user, ?string $ip): array
    {
        if (!in_array($method, ['CASH', 'CARD_TERMINAL', 'GCASH'], true)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'method must be CASH, CARD_TERMINAL or GCASH');
        }
        $rid = $reservation['reservationId'];
        return Tx::run($this->pdo, function () use ($reservation, $rid, $method, $user, $ip): array {
            $st = $this->pdo->prepare("SELECT 1 FROM payment WITH (UPDLOCK, HOLDLOCK) WHERE reservation_id = ? AND status = 'CAPTURED'");
            $st->execute([$rid]);
            if ($st->fetchColumn() !== false) {
                throw new ApiException(409, 'ALREADY_PAID', 'This reservation already has a captured payment');
            }
            $pid = Uuid::v4();
            $amount = (int) $reservation['amountMinor'];
            $this->pdo->prepare("INSERT INTO payment (payment_id, reservation_id, folio_id, provider, method, provider_payment_id, provider_event_id, amount_minor, currency, status, created_at) VALUES (?, ?, NULL, 'ONSITE', ?, ?, NULL, ?, ?, 'CAPTURED', DATETRUNC(SECOND, SYSUTCDATETIME()))")
                ->execute([$pid, $rid, $method, 'onsite-' . $pid, $amount, $reservation['currency']]);
            Outbox::add($this->pdo, 'PaymentCaptured', $pid, 1, $rid, ['paymentId' => $pid, 'reservationId' => $rid, 'amountMinor' => $amount, 'currency' => $reservation['currency'], 'method' => 'ONSITE']);
            Audit::record($this->pdo, $user, 'payment.onsite', 'reservation', $rid, ['paymentId' => $pid, 'amountMinor' => $amount, 'method' => $method], $ip);
            return [201, ['paymentId' => $pid, 'reservationId' => $rid, 'amountMinor' => $amount]];
        });
    }

    /** Everything a guest receipt needs. Ownership is checked by the caller against Booking first. */
    public function receipt(string $reservationId): array
    {
        $pay = $this->pdo->prepare("SELECT payment_id AS paymentId, provider, method, provider_payment_id AS reference, amount_minor AS amountMinor, currency, status, created_at AS createdAt FROM payment WHERE reservation_id = ? AND status = 'CAPTURED' ORDER BY created_at");
        $pay->execute([$reservationId]);
        $ref = $this->pdo->prepare("SELECT r.refund_id AS refundId, r.amount_minor AS amountMinor, r.status, r.reason, r.completed_at AS completedAt FROM refund r JOIN payment p ON p.payment_id = r.payment_id WHERE p.reservation_id = ? ORDER BY r.created_at");
        $ref->execute([$reservationId]);
        $ch = $this->pdo->prepare('SELECT c.description, c.amount_minor AS amountMinor, c.created_at AS createdAt FROM charge c JOIN folio f ON f.folio_id = c.folio_id WHERE f.reservation_id = ? ORDER BY c.created_at');
        $ch->execute([$reservationId]);
        $cast = fn (array $rows) => array_map(fn ($r) => ['amountMinor' => (int) $r['amountMinor']] + $r, $rows);
        return ['reservationId' => $reservationId, 'payments' => $cast($pay->fetchAll()), 'refunds' => $cast($ref->fetchAll()), 'charges' => $cast($ch->fetchAll())];
    }

    // ---- accounting exports -------------------------------------------------------------------------------

    /**
     * Build the lines for closed days only (today is excluded so figures cannot move after export). Payment and refund ids are the line
     * ids, so a retried or re-requested batch carries the same ids and the accounting system can reconcile rejected lines.
     * @return array{rows:list<list<string|int>>,count:int,total:int}
     */
    public function exportLines(string $from, string $to): array
    {
        $day = sprintf(self::MANILA, 'created_at');
        $p = $this->pdo->prepare("SELECT payment_id, {$day} AS day, provider, method, provider_payment_id, amount_minor, currency, reservation_id FROM payment WHERE status = 'CAPTURED' AND {$day} BETWEEN ? AND ? ORDER BY created_at, payment_id");
        $p->execute([$from, $to]);
        $rf = $this->pdo->prepare("SELECT r.refund_id, " . sprintf(self::MANILA, 'r.completed_at') . " AS day, p.provider, p.method, p.provider_payment_id, r.amount_minor, p.currency, p.reservation_id
                                     FROM refund r JOIN payment p ON p.payment_id = r.payment_id WHERE r.status = 'COMPLETED' AND " . sprintf(self::MANILA, 'r.completed_at') . ' BETWEEN ? AND ? ORDER BY r.completed_at, r.refund_id');
        $rf->execute([$from, $to]);
        $rows = [];
        $total = 0;
        foreach ($p->fetchAll() as $x) {
            $rows[] = [$x['payment_id'], $x['day'], 'PAYMENT', $x['reservation_id'], $x['provider'] . ($x['method'] ? '/' . $x['method'] : ''), $x['provider_payment_id'], number_format((int) $x['amount_minor'] / 100, 2, '.', ''), $x['currency']];
            $total += (int) $x['amount_minor'];
        }
        foreach ($rf->fetchAll() as $x) {
            $rows[] = [$x['refund_id'], $x['day'], 'REFUND', $x['reservation_id'], $x['provider'] . ($x['method'] ? '/' . $x['method'] : ''), $x['provider_payment_id'], '-' . number_format((int) $x['amount_minor'] / 100, 2, '.', ''), $x['currency']];
            $total -= (int) $x['amount_minor'];
        }
        usort($rows, fn ($a, $b) => [$a[1], $a[2], $a[0]] <=> [$b[1], $b[2], $b[0]]);
        return ['rows' => $rows, 'count' => count($rows), 'total' => $total];
    }

    public function requestExport(string $from, string $to, array $user, ?string $ip): array
    {
        if (!Dates::isDate($from) || !Dates::isDate($to) || $to < $from || (strtotime($to) - strtotime($from)) / 86400 > 92 || $to >= Dates::manilaToday()) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Pick closed days only (before today), at most 93 days');
        }
        $lines = $this->exportLines($from, $to);
        $batch = 'HLX-' . str_replace('-', '', $from) . '-' . strtoupper(bin2hex(random_bytes(3)));
        $header = ['line_id', 'date', 'type', 'reservation_ref', 'method', 'provider_reference', 'amount_php', 'currency'];
        $csv = Csv::write([$header, ...array_map(fn ($r) => $r, $lines['rows'])]);
        $id = Uuid::v4();
        $this->pdo->prepare("INSERT INTO accounting_export (export_id, batch_ref, period_from, period_to, status, row_count, total_minor, csv, checksum, requested_by, created_at) VALUES (?, ?, ?, ?, 'REQUESTED', ?, ?, ?, ?, ?, DATETRUNC(SECOND, SYSUTCDATETIME()))")
            ->execute([$id, $batch, $from, $to, $lines['count'], $lines['total'], $csv, hash('sha256', $csv), $user['accountId']]);
        Audit::record($this->pdo, $user, 'export.requested', 'export', $id, ['batch' => $batch, 'rows' => $lines['count'], 'totalMinor' => $lines['total']], $ip);
        return [201, ['exportId' => $id, 'batchRef' => $batch, 'rowCount' => $lines['count'], 'totalMinor' => $lines['total'], 'status' => 'REQUESTED']];
    }

    public function exports(): array
    {
        $rows = $this->pdo->query('SELECT TOP (50) export_id AS exportId, batch_ref AS batchRef, period_from AS periodFrom, period_to AS periodTo, status, row_count AS [rowCount], total_minor AS totalMinor, requested_by AS requestedBy, approved_by AS approvedBy, downloads, created_at AS createdAt, checksum FROM accounting_export ORDER BY created_at DESC')->fetchAll();
        return [200, ['exports' => array_map(fn ($r) => ['rowCount' => (int) $r['rowCount'], 'totalMinor' => (int) $r['totalMinor'], 'downloads' => (int) $r['downloads']] + $r, $rows)]];
    }

    public function decideExport(string $id, bool $approve, array $user, ?string $ip): array
    {
        return Tx::run($this->pdo, function () use ($id, $approve, $user, $ip): array {
            $st = $this->pdo->prepare('SELECT requested_by, status, batch_ref FROM accounting_export WITH (UPDLOCK, ROWLOCK) WHERE export_id = ?');
            $st->execute([$id]);
            $e = $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
            if ($e['status'] !== 'REQUESTED') {
                throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This export was already decided');
            }
            if ($e['requested_by'] === $user['accountId']) {
                throw new ApiException(403, 'SELF_APPROVAL', 'An export must be approved by someone other than the person who requested it');
            }
            $this->pdo->prepare('UPDATE accounting_export SET status = ?, approved_by = ?, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE export_id = ?')->execute([$approve ? 'APPROVED' : 'REJECTED', $user['accountId'], $id]);
            Audit::record($this->pdo, $user, $approve ? 'export.approved' : 'export.rejected', 'export', $id, ['batch' => $e['batch_ref']], $ip);
            return [200, ['exportId' => $id, 'status' => $approve ? 'APPROVED' : 'REJECTED']];
        });
    }

    /** @return array{0:string,1:string,2:string} csv text, file name, checksum. Only approved exports can be downloaded. */
    public function download(string $id, array $user, ?string $ip): array
    {
        $st = $this->pdo->prepare('SELECT csv, batch_ref, checksum, status FROM accounting_export WHERE export_id = ?');
        $st->execute([$id]);
        $e = $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
        if ($e['status'] !== 'APPROVED') {
            throw new ApiException(409, 'NOT_APPROVED', 'This export has not been approved');
        }
        $this->pdo->prepare('UPDATE accounting_export SET downloads = downloads + 1 WHERE export_id = ?')->execute([$id]);
        Audit::record($this->pdo, $user, 'export.downloaded', 'export', $id, ['batch' => $e['batch_ref']], $ip);
        return [(string) $e['csv'], $e['batch_ref'] . '.csv', (string) $e['checksum']];
    }

    // ---- revenue ------------------------------------------------------------------------------------------

    /** GET /internal/reports/revenue : daily cash in and out, by method, plus reconciliation timeliness (spec KPI). */
    public function revenue(array $q): array
    {
        [$from, $to] = self::range($q);
        $day = sprintf(self::MANILA, 'created_at');
        $pay = $this->pdo->prepare("SELECT {$day} AS day, COALESCE(method, provider) AS pay_method, SUM(amount_minor) AS total, COUNT(*) AS n FROM payment WHERE status = 'CAPTURED' AND {$day} BETWEEN ? AND ? GROUP BY {$day}, COALESCE(method, provider)");
        $pay->execute([$from, $to]);
        $rdy = sprintf(self::MANILA, 'completed_at');
        $ref = $this->pdo->prepare("SELECT {$rdy} AS day, SUM(amount_minor) AS total FROM refund WHERE status = 'COMPLETED' AND {$rdy} BETWEEN ? AND ? GROUP BY {$rdy}");
        $ref->execute([$from, $to]);
        $perDay = [];
        $byMethod = [];
        foreach ($pay->fetchAll() as $r) {
            $perDay[$r['day']]['payments'] = ($perDay[$r['day']]['payments'] ?? 0) + (int) $r['total'];
            $byMethod[$r['pay_method']] = ($byMethod[$r['pay_method']] ?? 0) + (int) $r['total'];
        }
        foreach ($ref->fetchAll() as $r) {
            $perDay[$r['day']]['refunds'] = (int) $r['total'];
        }
        $days = [];
        $tp = $tr = 0;
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $p = $perDay[$k]['payments'] ?? 0;
            $r = $perDay[$k]['refunds'] ?? 0;
            $days[] = ['date' => $k, 'paymentsMinor' => $p, 'refundsMinor' => $r, 'netMinor' => $p - $r];
            $tp += $p;
            $tr += $r;
        }
        arsort($byMethod);
        return [200, [
            'from' => $from, 'to' => $to, 'currency' => 'PHP', 'days' => $days,
            'totals' => ['paymentsMinor' => $tp, 'refundsMinor' => $tr, 'netMinor' => $tp - $tr],
            'byMethod' => array_map(fn ($m, $v) => ['method' => $m, 'amountMinor' => $v], array_keys($byMethod), array_values($byMethod)),
            'reconciliation' => $this->reconciliation($from, $to),
            'openRefunds' => (int) $this->pdo->query("SELECT COUNT(*) FROM refund WHERE status = 'REQUESTED'")->fetchColumn(),
            'paymentsInReview' => (int) $this->pdo->query("SELECT COUNT(*) FROM payment WHERE status = 'REVIEW'")->fetchColumn(),
        ]];
    }

    /** Share of payments matched by the end of the next business day (Mon-Fri) after they were taken. Only payments whose deadline passed count. */
    private function reconciliation(string $from, string $to): array
    {
        $st = $this->pdo->prepare("SELECT " . sprintf(self::MANILA, 'created_at') . " AS day, reconciled_at FROM payment WHERE status = 'CAPTURED' AND " . sprintf(self::MANILA, 'created_at') . ' BETWEEN ? AND ?');
        $st->execute([$from, $to]);
        $due = $ok = 0;
        $now = time();
        foreach ($st->fetchAll() as $p) {
            $deadline = self::nextBusinessDayEnd($p['day']);
            if ($deadline > $now) {
                continue;
            }
            $due++;
            if ($p['reconciled_at'] !== null && strtotime($p['reconciled_at'] . ' UTC') <= $deadline) {
                $ok++;
            }
        }
        return ['due' => $due, 'matchedOnTime' => $ok, 'rate' => $due ? round($ok / $due, 4) : null];
    }

    /** Unix time of 23:59:59 Manila on the next Monday-Friday after $day. */
    public static function nextBusinessDayEnd(string $day): int
    {
        $d = new \DateTimeImmutable($day, new \DateTimeZone(Dates::TZ));
        do {
            $d = $d->modify('+1 day');
        } while ((int) $d->format('N') >= 6);
        return $d->setTime(23, 59, 59)->getTimestamp();
    }

    public function audit(array $q): array
    {
        return [200, ['entries' => Audit::search($this->pdo, $q)]];
    }

    public function outbox(): array
    {
        return [200, Audit::outboxHealth($this->pdo)];
    }
}
