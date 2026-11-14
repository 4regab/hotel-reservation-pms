<?php
declare(strict_types=1);

namespace Billing;

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
            $st = $this->pdo->prepare('SELECT reservation_id, amount_minor, currency FROM checkout_session WHERE session_id = ? FOR UPDATE');
            $st->execute([$p['sessionId']]);
            $s = $st->fetch();
            // Amount, currency and reservation must match the checkout we created; otherwise keep it for finance review.
            $ok = $s !== false && $s['reservation_id'] === $p['reservationId'] && (int) $s['amount_minor'] === $p['amountMinor'] && $s['currency'] === $p['currency'];

            $f = $this->pdo->prepare('SELECT folio_id FROM folio WHERE reservation_id = ?');
            $f->execute([$p['reservationId']]);
            $folioId = $f->fetchColumn() ?: null;

            $paymentId = Uuid::v4();
            try {
                $this->pdo->prepare('INSERT INTO payment (payment_id, reservation_id, folio_id, provider, provider_payment_id, provider_event_id, amount_minor, currency, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                    ->execute([$paymentId, $p['reservationId'], $folioId, 'PAYMONGO', $p['providerPaymentId'], $p['providerEventId'], $p['amountMinor'], $p['currency'], $ok ? 'CAPTURED' : 'REVIEW']);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    return 'duplicate';
                }
                throw $e;
            }
            if (!$ok) {
                return 'review';
            }
            $this->pdo->prepare("UPDATE checkout_session SET status = 'PAID' WHERE session_id = ?")->execute([$p['sessionId']]);
            Outbox::add($this->pdo, 'PaymentCaptured', $paymentId, 1, $p['reservationId'], [
                'paymentId' => $paymentId, 'reservationId' => $p['reservationId'], 'amountMinor' => $p['amountMinor'], 'currency' => $p['currency'],
            ]);
            return 'captured';
        });
    }
}
