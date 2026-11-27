<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;
use Lib\Audit;
use Lib\Csv;
use Lib\Dates;
use Lib\Db;
use Lib\Outbox;
use Lib\Tx;
use Lib\Uuid;

/** Payment lists, reconciliation, refund decisions, approved accounting exports and revenue figures. */
final class FinanceService
{
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
        $reconciled = in_array($q['reconciled'] ?? '', ['0', '1'], true) ? $q['reconciled'] : null;
        $st = Db::call($this->pdo, 'usp_payment_list', [
            'from_date' => $from, 'to_date' => $to, 'reconciled' => $reconciled, 'status' => ($q['status'] ?? '') === 'REVIEW' ? 'REVIEW' : null,
        ]);
        return [200, ['from' => $from, 'to' => $to, 'payments' => array_map(fn ($r) => ['amountMinor' => (int) $r['amountMinor']] + $r, $st->fetchAll())]];
    }

    public function reconcile(string $paymentId, array $user, ?string $ip): array
    {
        $affected = (int) Db::call($this->pdo, 'usp_payment_reconcile', ['payment_id' => $paymentId, 'reconciled_by' => $user['accountId']])->fetchColumn();
        if ($affected === 0) {
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
        $count = (int) Db::call($this->pdo, 'usp_payment_reconcileUpTo', ['up_to_date' => $date, 'reconciled_by' => $user['accountId']])->fetchColumn();
        Audit::record($this->pdo, $user, 'payment.reconciled_batch', 'payment', $date, ['count' => $count], $ip);
        return [200, ['reconciled' => $count]];
    }

    public function refunds(string $status): array
    {
        $rows = Db::call($this->pdo, 'usp_refund_list', ['status' => in_array($status, ['REQUESTED', 'COMPLETED', 'REJECTED'], true) ? $status : null])->fetchAll();
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
            $r = Db::call($this->pdo, 'usp_refund_getForUpdate', ['refund_id' => $refundId])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
            RefundPolicy::assertCanApprove($user['role'], $user['accountId'], $r['requested_by']);
            if ($r['status'] !== 'REQUESTED') {
                throw new ApiException(409, 'REFUND_NOT_PENDING', 'This refund was already decided');
            }
            Db::call($this->pdo, 'usp_refund_reject', ['refund_id' => $refundId, 'approved_by' => $user['accountId'], 'note' => $note]);
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
            if (Db::call($this->pdo, 'usp_payment_hasCapturedForUpdate', ['reservation_id' => $rid])->fetchColumn() !== false) {
                throw new ApiException(409, 'ALREADY_PAID', 'This reservation already has a captured payment');
            }
            $pid = Uuid::v4();
            $amount = (int) $reservation['amountMinor'];
            Db::call($this->pdo, 'usp_payment_insertOnsite', [
                'payment_id' => $pid, 'reservation_id' => $rid, 'folio_id' => null, 'method' => $method,
                'provider_payment_id' => 'onsite-' . $pid, 'amount_minor' => $amount, 'currency' => $reservation['currency'],
            ]);
            Outbox::add($this->pdo, 'PaymentCaptured', $pid, 1, $rid, ['paymentId' => $pid, 'reservationId' => $rid, 'amountMinor' => $amount, 'currency' => $reservation['currency'], 'method' => 'ONSITE']);
            Audit::record($this->pdo, $user, 'payment.onsite', 'reservation', $rid, ['paymentId' => $pid, 'amountMinor' => $amount, 'method' => $method], $ip);
            return [201, ['paymentId' => $pid, 'reservationId' => $rid, 'amountMinor' => $amount]];
        });
    }

    /** Everything a guest receipt needs. Ownership is checked by the caller against Booking first. */
    public function receipt(string $reservationId): array
    {
        $st = Db::call($this->pdo, 'usp_receipt_get', ['reservation_id' => $reservationId]);
        $pay = $st->fetchAll();
        $st->nextRowset();
        $ref = $st->fetchAll();
        $st->nextRowset();
        $ch = $st->fetchAll();
        $cast = fn (array $rows) => array_map(fn ($r) => ['amountMinor' => (int) $r['amountMinor']] + $r, $rows);
        return ['reservationId' => $reservationId, 'payments' => $cast($pay), 'refunds' => $cast($ref), 'charges' => $cast($ch)];
    }

    // ---- accounting exports -------------------------------------------------------------------------------

    /**
     * Build the lines for closed days only (today is excluded so figures cannot move after export). Payment and refund ids are the line
     * ids, so a retried or re-requested batch carries the same ids and the accounting system can reconcile rejected lines.
     * @return array{rows:list<list<string|int>>,count:int,total:int}
     */
    public function exportLines(string $from, string $to): array
    {
        $st = Db::call($this->pdo, 'usp_export_getLines', ['from_date' => $from, 'to_date' => $to]);
        $payments = $st->fetchAll();
        $st->nextRowset();
        $refunds = $st->fetchAll();
        $rows = [];
        $total = 0;
        foreach ($payments as $x) {
            $rows[] = [$x['payment_id'], $x['day'], 'PAYMENT', $x['reservation_id'], $x['provider'] . ($x['method'] ? '/' . $x['method'] : ''), $x['provider_payment_id'], number_format((int) $x['amount_minor'] / 100, 2, '.', ''), $x['currency']];
            $total += (int) $x['amount_minor'];
        }
        foreach ($refunds as $x) {
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
        Db::call($this->pdo, 'usp_export_insert', [
            'export_id' => $id, 'batch_ref' => $batch, 'period_from' => $from, 'period_to' => $to, 'row_count' => $lines['count'],
            'total_minor' => $lines['total'], 'csv' => $csv, 'checksum' => hash('sha256', $csv), 'requested_by' => $user['accountId'],
        ]);
        Audit::record($this->pdo, $user, 'export.requested', 'export', $id, ['batch' => $batch, 'rows' => $lines['count'], 'totalMinor' => $lines['total']], $ip);
        return [201, ['exportId' => $id, 'batchRef' => $batch, 'rowCount' => $lines['count'], 'totalMinor' => $lines['total'], 'status' => 'REQUESTED']];
    }

    public function exports(): array
    {
        $rows = Db::call($this->pdo, 'usp_export_list')->fetchAll();
        return [200, ['exports' => array_map(fn ($r) => ['rowCount' => (int) $r['rowCount'], 'totalMinor' => (int) $r['totalMinor'], 'downloads' => (int) $r['downloads']] + $r, $rows)]];
    }

    public function decideExport(string $id, bool $approve, array $user, ?string $ip): array
    {
        return Tx::run($this->pdo, function () use ($id, $approve, $user, $ip): array {
            $e = Db::call($this->pdo, 'usp_export_getForUpdate', ['export_id' => $id])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
            if ($e['status'] !== 'REQUESTED') {
                throw new ApiException(409, 'ILLEGAL_TRANSITION', 'This export was already decided');
            }
            if ($e['requested_by'] === $user['accountId']) {
                throw new ApiException(403, 'SELF_APPROVAL', 'An export must be approved by someone other than the person who requested it');
            }
            Db::call($this->pdo, 'usp_export_decide', ['export_id' => $id, 'status' => $approve ? 'APPROVED' : 'REJECTED', 'approved_by' => $user['accountId']]);
            Audit::record($this->pdo, $user, $approve ? 'export.approved' : 'export.rejected', 'export', $id, ['batch' => $e['batch_ref']], $ip);
            return [200, ['exportId' => $id, 'status' => $approve ? 'APPROVED' : 'REJECTED']];
        });
    }

    /** @return array{0:string,1:string,2:string} csv text, file name, checksum. Only approved exports can be downloaded. */
    public function download(string $id, array $user, ?string $ip): array
    {
        $e = Db::call($this->pdo, 'usp_export_get', ['export_id' => $id])->fetch() ?: throw new ApiException(404, 'NOT_FOUND');
        if ($e['status'] !== 'APPROVED') {
            throw new ApiException(409, 'NOT_APPROVED', 'This export has not been approved');
        }
        Db::call($this->pdo, 'usp_export_addDownload', ['export_id' => $id]);
        Audit::record($this->pdo, $user, 'export.downloaded', 'export', $id, ['batch' => $e['batch_ref']], $ip);
        return [(string) $e['csv'], $e['batch_ref'] . '.csv', (string) $e['checksum']];
    }

    // ---- revenue ------------------------------------------------------------------------------------------

    /** GET /internal/reports/revenue : daily cash in and out, by method, plus reconciliation timeliness (spec KPI). */
    public function revenue(array $q): array
    {
        [$from, $to] = self::range($q);
        $pay = Db::call($this->pdo, 'usp_report_paymentsByDay', ['from_date' => $from, 'to_date' => $to]);
        $ref = Db::call($this->pdo, 'usp_report_refundsByDay', ['from_date' => $from, 'to_date' => $to]);
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
        $counts = Db::call($this->pdo, 'usp_report_openCounts')->fetch();
        return [200, [
            'from' => $from, 'to' => $to, 'currency' => 'PHP', 'days' => $days,
            'totals' => ['paymentsMinor' => $tp, 'refundsMinor' => $tr, 'netMinor' => $tp - $tr],
            'byMethod' => array_map(fn ($m, $v) => ['method' => $m, 'amountMinor' => $v], array_keys($byMethod), array_values($byMethod)),
            'reconciliation' => $this->reconciliation($from, $to),
            'openRefunds' => (int) $counts['open_refunds'],
            'paymentsInReview' => (int) $counts['payments_in_review'],
        ]];
    }

    /** Share of payments matched by the end of the next business day (Mon-Fri) after they were taken. Only payments whose deadline passed count. */
    private function reconciliation(string $from, string $to): array
    {
        $st = Db::call($this->pdo, 'usp_report_reconciliationInput', ['from_date' => $from, 'to_date' => $to]);
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
