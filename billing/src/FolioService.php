<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;
use Lib\Outbox;
use Lib\Tx;
use Lib\Uuid;

/** Guest bills (folios), charges, on-site settlement and refunds. Only Billing writes these records. */
final class FolioService
{
    public function __construct(private \PDO $pdo, private PayMongoAdapter $provider)
    {
    }

    public function summary(string $reservationId, bool $lock = false): array
    {
        $f = $this->one('SELECT folio_id, status, total_minor, currency FROM folio WHERE reservation_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$reservationId]);
        $paid = (int) $this->col("SELECT COALESCE(SUM(amount_minor), 0) FROM payment WHERE reservation_id = ? AND status = 'CAPTURED'", [$reservationId]);
        $refunded = (int) $this->col("SELECT COALESCE(SUM(r.amount_minor), 0) FROM refund r JOIN payment p ON p.payment_id = r.payment_id WHERE p.reservation_id = ? AND r.status = 'COMPLETED'", [$reservationId]);
        $total = $f === null ? 0 : (int) $f['total_minor'];
        return [
            'reservationId' => $reservationId, 'folioOpen' => $f !== null && $f['status'] === 'OPEN', 'folioStatus' => $f['status'] ?? null,
            'totalMinor' => $total, 'paidMinor' => $paid, 'refundedMinor' => $refunded, 'balanceMinor' => $f === null ? 0 : $total - $paid + $refunded,
            'currency' => $f['currency'] ?? 'PHP',
        ];
    }

    /** StayCheckedIn: open the bill with the room charge and attach earlier payments. Safe to repeat. */
    public function open(string $reservationId, int $amountMinor, string $currency): void
    {
        Tx::run($this->pdo, function () use ($reservationId, $amountMinor, $currency): void {
            if ($this->one('SELECT 1 AS x FROM folio WHERE reservation_id = ? FOR UPDATE', [$reservationId]) !== null) {
                return;
            }
            $id = Uuid::v4();
            $this->pdo->prepare("INSERT INTO folio (folio_id, reservation_id, status, total_minor, currency, created_at) VALUES (?, ?, 'OPEN', ?, ?, UTC_TIMESTAMP())")->execute([$id, $reservationId, $amountMinor, $currency]);
            $this->pdo->prepare('INSERT INTO charge (charge_id, folio_id, description, amount_minor, currency, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([Uuid::v4(), $id, 'Room charge', $amountMinor, $currency, 'system']);
            $this->pdo->prepare('UPDATE payment SET folio_id = ? WHERE reservation_id = ? AND folio_id IS NULL')->execute([$id, $reservationId]);
        });
    }

    /** StayCheckedOut: close the bill. */
    public function close(string $reservationId): void
    {
        $this->pdo->prepare("UPDATE folio SET status = 'CLOSED' WHERE reservation_id = ?")->execute([$reservationId]);
    }

    public function postCharge(string $reservationId, string $description, int $amountMinor, string $accountId): array
    {
        if ($description === '' || strlen($description) > 120 || $amountMinor <= 0) {
            throw new ApiException(422, 'INVALID_REQUEST', 'description and a positive amountMinor (centavos) are required');
        }
        return Tx::run($this->pdo, function () use ($reservationId, $description, $amountMinor, $accountId): array {
            $f = $this->one('SELECT folio_id, status, currency FROM folio WHERE reservation_id = ? FOR UPDATE', [$reservationId]) ?? throw new ApiException(404, 'NOT_FOUND', 'No open bill for this reservation');
            if ($f['status'] !== 'OPEN') {
                throw new ApiException(409, 'FOLIO_CLOSED');
            }
            $id = Uuid::v4();
            $this->pdo->prepare('INSERT INTO charge (charge_id, folio_id, description, amount_minor, currency, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$id, $f['folio_id'], $description, $amountMinor, $f['currency'], $accountId]);
            $this->pdo->prepare('UPDATE folio SET total_minor = total_minor + ? WHERE folio_id = ?')->execute([$amountMinor, $f['folio_id']]);
            return [201, ['chargeId' => $id] + $this->summary($reservationId)];
        });
    }

    /** Take the outstanding balance on site (cash/terminal). No effect when nothing is owed. */
    public function settle(string $reservationId, string $accountId): array
    {
        return Tx::run($this->pdo, function () use ($reservationId, $accountId): array {
            $s = $this->summary($reservationId, true);
            if (!$s['folioOpen']) {
                throw new ApiException(409, 'FOLIO_NOT_OPEN');
            }
            if ($s['balanceMinor'] <= 0) {
                return [200, $s + ['settledMinor' => 0]];
            }
            $paymentId = Uuid::v4();
            $f = $this->one('SELECT folio_id FROM folio WHERE reservation_id = ?', [$reservationId]);
            $this->pdo->prepare("INSERT INTO payment (payment_id, reservation_id, folio_id, provider, provider_payment_id, provider_event_id, amount_minor, currency, status, created_at) VALUES (?, ?, ?, 'ONSITE', ?, NULL, ?, ?, 'CAPTURED', UTC_TIMESTAMP())")
                ->execute([$paymentId, $reservationId, $f['folio_id'], 'onsite-' . $paymentId, $s['balanceMinor'], $s['currency']]);
            Outbox::add($this->pdo, 'PaymentCaptured', $paymentId, 1, $reservationId, ['paymentId' => $paymentId, 'reservationId' => $reservationId, 'amountMinor' => $s['balanceMinor'], 'currency' => $s['currency'], 'method' => 'ONSITE']);
            return [200, $this->summary($reservationId) + ['settledMinor' => $s['balanceMinor'], 'settledBy' => $accountId]];
        });
    }

    /** One refund request per payment (unique payment_id). Returns the refund id. */
    public function requestRefund(string $paymentId, int $amountMinor, string $requestedBy, string $reason): array
    {
        $p = $this->one('SELECT payment_id, amount_minor, status FROM payment WHERE payment_id = ?', [$paymentId]) ?? throw new ApiException(404, 'NOT_FOUND', 'Unknown payment');
        if ($p['status'] !== 'CAPTURED' || $amountMinor <= 0 || $amountMinor > (int) $p['amount_minor']) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Refund must be a positive amount up to the captured payment');
        }
        $id = Uuid::v4();
        try {
            $this->pdo->prepare("INSERT INTO refund (refund_id, payment_id, amount_minor, approval, status, requested_by, reason, created_at) VALUES (?, ?, ?, 'PENDING', 'REQUESTED', ?, ?, UTC_TIMESTAMP())")
                ->execute([$id, $paymentId, $amountMinor, $requestedBy, $reason]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                $existing = $this->one('SELECT refund_id FROM refund WHERE payment_id = ?', [$paymentId]);
                throw new ApiException(409, 'REFUND_ALREADY_REQUESTED', 'This payment already has a refund request', ['refundId' => $existing['refund_id']]);
            }
            throw $e;
        }
        return [201, ['refundId' => $id, 'status' => 'REQUESTED', 'approval' => 'PENDING']];
    }

    /** RefundRequested event from Booking. Finds the payment by id or by reservation. Duplicate requests are ignored. */
    public function requestFromEvent(array $d): void
    {
        $paymentId = $d['paymentId'] ?? $this->col("SELECT payment_id FROM payment WHERE reservation_id = ? AND status = 'CAPTURED' ORDER BY created_at LIMIT 1", [$d['reservationId']]);
        if (!$paymentId) {
            return;
        }
        try {
            $this->requestRefund((string) $paymentId, (int) $d['amountMinor'], 'system', (string) $d['reason']);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'REFUND_ALREADY_REQUESTED') {
                throw $e;
            }
        }
    }

    /** Manager approves; the provider refund is requested AFTER the approval commits (no locks held during the call). */
    public function approveRefund(string $refundId, array $user): array
    {
        $r = Tx::run($this->pdo, function () use ($refundId, $user): array {
            $r = $this->one('SELECT r.refund_id, r.amount_minor, r.status, r.requested_by, p.provider_payment_id, p.reservation_id, p.payment_id, p.currency FROM refund r JOIN payment p ON p.payment_id = r.payment_id WHERE r.refund_id = ? FOR UPDATE OF r', [$refundId])
                ?? throw new ApiException(404, 'NOT_FOUND');
            RefundPolicy::assertCanApprove($user['role'], $user['accountId'], $r['requested_by']);
            if ($r['status'] === 'COMPLETED') {
                throw new ApiException(409, 'REFUND_ALREADY_COMPLETED');
            }
            $this->pdo->prepare("UPDATE refund SET approval = 'APPROVED', approved_by = ? WHERE refund_id = ?")->execute([$user['accountId'], $refundId]);
            return $r;
        });
        if (!str_starts_with((string) $r['provider_payment_id'], 'onsite-')) {
            $this->provider->refund((string) $r['provider_payment_id'], (int) $r['amount_minor']);
        }
        Tx::run($this->pdo, function () use ($r, $refundId): void {
            $this->pdo->prepare("UPDATE refund SET status = 'COMPLETED', completed_at = UTC_TIMESTAMP() WHERE refund_id = ? AND status <> 'COMPLETED'")->execute([$refundId]);
            Outbox::add($this->pdo, 'RefundCompleted', $refundId, 1, $r['reservation_id'], [
                'refundId' => $refundId, 'paymentId' => $r['payment_id'], 'reservationId' => $r['reservation_id'], 'amountMinor' => (int) $r['amount_minor'], 'currency' => $r['currency'],
            ]);
        });
        return [200, ['refundId' => $refundId, 'status' => 'COMPLETED', 'approval' => 'APPROVED']];
    }

    private function one(string $sql, array $args): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: null;
    }

    private function col(string $sql, array $args): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }
}
