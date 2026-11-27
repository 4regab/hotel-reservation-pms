<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;
use Lib\Audit;
use Lib\Db;
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
        $row = $this->one('usp_folio_getSummary', ['reservation_id' => $reservationId, 'lock' => $lock ? 1 : 0]);
        $f = $row['folio_id'] === null ? null : $row;
        $paid = (int) $row['paid_minor'];
        $refunded = (int) $row['refunded_minor'];
        $total = $f === null ? 0 : (int) $f['total_minor'];
        return [
            'reservationId' => $reservationId, 'folioOpen' => $f !== null && $f['status'] === 'OPEN', 'folioStatus' => $f['status'] ?? null,
            'totalMinor' => $total, 'paidMinor' => $paid, 'refundedMinor' => $refunded, 'balanceMinor' => $f === null ? 0 : $total - $paid + $refunded,
            'currency' => $f['currency'] ?? 'PHP',
        ];
    }

    /** StayCheckedIn: open the bill with the room charge and attach earlier payments. Safe to repeat. */
    public function open(string $reservationId, int $amountMinor, string $currency, string $guarantee = 'NONE'): void
    {
        Tx::run($this->pdo, function () use ($reservationId, $amountMinor, $currency, $guarantee): void {
            // The procedure does nothing when the bill already exists. Ids are made here and passed in.
            Db::call($this->pdo, 'usp_folio_open', [
                'reservation_id' => $reservationId, 'folio_id' => Uuid::v4(), 'charge_id' => Uuid::v4(), 'payment_id' => Uuid::v4(),
                'amount_minor' => $amountMinor, 'currency' => $currency, 'guarantee' => $guarantee,
            ]);
        });
    }

    /** StayCheckedOut: close the bill. */
    public function close(string $reservationId): void
    {
        Db::call($this->pdo, 'usp_folio_close', ['reservation_id' => $reservationId]);
    }

    public function postCharge(string $reservationId, string $description, int $amountMinor, array $user, ?string $ip = null): array
    {
        if ($description === '' || strlen($description) > 120 || $amountMinor <= 0) {
            throw new ApiException(422, 'INVALID_REQUEST', 'description and a positive amountMinor (centavos) are required');
        }
        return Tx::run($this->pdo, function () use ($reservationId, $description, $amountMinor, $user, $ip): array {
            $f = $this->one('usp_folio_getForUpdate', ['reservation_id' => $reservationId]) ?? throw new ApiException(404, 'NOT_FOUND', 'No open bill for this reservation');
            if ($f['status'] !== 'OPEN') {
                throw new ApiException(409, 'FOLIO_CLOSED');
            }
            $id = Uuid::v4();
            Db::call($this->pdo, 'usp_folio_addCharge', [
                'folio_id' => $f['folio_id'], 'charge_id' => $id, 'description' => $description, 'amount_minor' => $amountMinor,
                'currency' => $f['currency'], 'created_by' => $user['accountId'],
            ]);
            Audit::record($this->pdo, $user, 'folio.charge', 'reservation', $reservationId, ['chargeId' => $id, 'amountMinor' => $amountMinor], $ip);
            return [201, ['chargeId' => $id] + $this->summary($reservationId)];
        });
    }

    /** Take the outstanding balance on site (cash/terminal). No effect when nothing is owed. */
    public function settle(string $reservationId, array $user, string $method = 'CASH', ?string $ip = null): array
    {
        $accountId = $user['accountId'];
        if (!in_array($method, ['CASH', 'CARD_TERMINAL', 'GCASH'], true)) {
            throw new ApiException(422, 'INVALID_REQUEST', 'method must be CASH, CARD_TERMINAL or GCASH');
        }
        return Tx::run($this->pdo, function () use ($reservationId, $accountId, $user, $method, $ip): array {
            $s = $this->summary($reservationId, true);
            if (!$s['folioOpen']) {
                throw new ApiException(409, 'FOLIO_NOT_OPEN');
            }
            if ($s['balanceMinor'] <= 0) {
                return [200, $s + ['settledMinor' => 0]];
            }
            $paymentId = Uuid::v4();
            $f = $this->one('usp_folio_getId', ['reservation_id' => $reservationId]);
            Db::call($this->pdo, 'usp_payment_insertOnsite', [
                'payment_id' => $paymentId, 'reservation_id' => $reservationId, 'folio_id' => $f['folio_id'], 'method' => $method,
                'provider_payment_id' => 'onsite-' . $paymentId, 'amount_minor' => $s['balanceMinor'], 'currency' => $s['currency'],
            ]);
            Audit::record($this->pdo, $user, 'payment.onsite', 'reservation', $reservationId, ['paymentId' => $paymentId, 'amountMinor' => $s['balanceMinor'], 'method' => $method], $ip);
            Outbox::add($this->pdo, 'PaymentCaptured', $paymentId, 1, $reservationId, ['paymentId' => $paymentId, 'reservationId' => $reservationId, 'amountMinor' => $s['balanceMinor'], 'currency' => $s['currency'], 'method' => 'ONSITE']);
            return [200, $this->summary($reservationId) + ['settledMinor' => $s['balanceMinor'], 'settledBy' => $accountId]];
        });
    }

    /** One refund request per payment (unique payment_id). Returns the refund id. */
    public function requestRefund(string $paymentId, int $amountMinor, string $requestedBy, string $reason, ?array $user = null, ?string $ip = null): array
    {
        $p = $this->one('usp_payment_get', ['payment_id' => $paymentId]) ?? throw new ApiException(404, 'NOT_FOUND', 'Unknown payment');
        if ($p['status'] !== 'CAPTURED' || $amountMinor <= 0 || $amountMinor > (int) $p['amount_minor']) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Refund must be a positive amount up to the captured payment');
        }
        $id = Uuid::v4();
        try {
            Db::call($this->pdo, 'usp_refund_request', [
                'refund_id' => $id, 'payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'requested_by' => $requestedBy, 'reason' => $reason,
            ]);
        } catch (\PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                $existing = $this->one('usp_refund_getByPayment', ['payment_id' => $paymentId]);
                throw new ApiException(409, 'REFUND_ALREADY_REQUESTED', 'This payment already has a refund request', ['refundId' => $existing['refund_id']]);
            }
            throw $e;
        }
        Audit::record($this->pdo, $user ?? ['accountId' => 'system', 'role' => 'service'], 'refund.requested', 'refund', $id, ['paymentId' => $paymentId, 'amountMinor' => $amountMinor, 'reason' => $reason], $ip);
        return [201, ['refundId' => $id, 'status' => 'REQUESTED', 'approval' => 'PENDING']];
    }

    /** RefundRequested event from Booking. Finds the payment by id or by reservation. Duplicate requests are ignored. */
    public function requestFromEvent(array $d): void
    {
        $paymentId = $d['paymentId'] ?? $this->col('usp_payment_getFirstCaptured', ['reservation_id' => $d['reservationId']]);
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
    public function approveRefund(string $refundId, array $user, ?string $ip = null): array
    {
        $r = Tx::run($this->pdo, function () use ($refundId, $user, $ip): array {
            $r = $this->one('usp_refund_getForApproval', ['refund_id' => $refundId])
                ?? throw new ApiException(404, 'NOT_FOUND');
            RefundPolicy::assertCanApprove($user['role'], $user['accountId'], $r['requested_by']);
            if ($r['status'] === 'COMPLETED') {
                throw new ApiException(409, 'REFUND_ALREADY_COMPLETED');
            }
            if ($r['status'] !== 'REQUESTED') {
                throw new ApiException(409, 'REFUND_NOT_PENDING', 'This refund was already decided');
            }
            Db::call($this->pdo, 'usp_refund_approve', ['refund_id' => $refundId, 'approved_by' => $user['accountId']]);
            Audit::record($this->pdo, $user, 'refund.approved', 'refund', $refundId, ['amountMinor' => (int) $r['amount_minor']], $ip);
            return $r;
        });
        if (!str_starts_with((string) $r['provider_payment_id'], 'onsite-')) {
            $this->provider->refund((string) $r['provider_payment_id'], (int) $r['amount_minor']);
        }
        Tx::run($this->pdo, function () use ($r, $refundId): void {
            Db::call($this->pdo, 'usp_refund_complete', ['refund_id' => $refundId]);
            Outbox::add($this->pdo, 'RefundCompleted', $refundId, 1, $r['reservation_id'], [
                'refundId' => $refundId, 'paymentId' => $r['payment_id'], 'reservationId' => $r['reservation_id'], 'amountMinor' => (int) $r['amount_minor'], 'currency' => $r['currency'],
            ]);
        });
        return [200, ['refundId' => $refundId, 'status' => 'COMPLETED', 'approval' => 'APPROVED']];
    }

    private function one(string $proc, array $params): ?array
    {
        return Db::call($this->pdo, $proc, $params)->fetch() ?: null;
    }

    private function col(string $proc, array $params): mixed
    {
        return Db::call($this->pdo, $proc, $params)->fetchColumn();
    }
}
