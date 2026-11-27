<?php
declare(strict_types=1);

namespace Billing;

use Lib\Db;
use Lib\Outbox;
use Lib\Tx;
use Lib\Uuid;

/**
 * Records a verified provider payment ONCE and commits PaymentCaptured to the outbox in the same
 * transaction. Unique provider event and payment ids make repeated callbacks harmless.
 */
final class PaymentProcessor
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @param array{providerEventId:string,providerPaymentId:string,sessionId:string,reservationId:string,amountMinor:int,currency:string} $p
     *  @return string captured | review | duplicate */
    public function record(array $p): string
    {
        return Tx::run($this->pdo, function () use ($p): string {
            $s = Db::call($this->pdo, 'usp_checkout_getForUpdate', ['session_id' => $p['sessionId']])->fetch();
            // Amount, currency and reservation must match the checkout we created; otherwise keep it for finance review.
            $ok = $s !== false && $s['reservation_id'] === $p['reservationId'] && (int) $s['amount_minor'] === $p['amountMinor'] && $s['currency'] === $p['currency'];

            $folioId = Db::call($this->pdo, 'usp_folio_getId', ['reservation_id' => $p['reservationId']])->fetchColumn() ?: null;

            $paymentId = Uuid::v4();
            try {
                Db::call($this->pdo, 'usp_payment_insertProvider', [
                    'payment_id' => $paymentId, 'reservation_id' => $p['reservationId'], 'folio_id' => $folioId, 'provider' => 'PAYMONGO',
                    'provider_payment_id' => $p['providerPaymentId'], 'provider_event_id' => $p['providerEventId'],
                    'amount_minor' => $p['amountMinor'], 'currency' => $p['currency'], 'status' => $ok ? 'CAPTURED' : 'REVIEW',
                ]);
            } catch (\PDOException $e) {
                if (Db::isDuplicateKey($e)) {
                    return 'duplicate';
                }
                throw $e;
            }
            if (!$ok) {
                return 'review';
            }
            Db::call($this->pdo, 'usp_checkout_markPaid', ['session_id' => $p['sessionId']]);
            Outbox::add($this->pdo, 'PaymentCaptured', $paymentId, 1, $p['reservationId'], [
                'paymentId' => $paymentId, 'reservationId' => $p['reservationId'], 'amountMinor' => $p['amountMinor'], 'currency' => $p['currency'],
            ]);
            return 'captured';
        });
    }
}
