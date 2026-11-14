<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;
use Lib\Idempotency;
use Lib\Inbox;
use Lib\Log;
use Lib\PdoIdempotencyStore;
use Lib\Peer;
use Lib\Rbac;
use Lib\Request;
use Lib\ServiceAuth;
use Lib\Tx;
use Lib\Uuid;

/** Private REST routes for the Billing service. */
final class BillingController
{
    public function __construct(private \PDO $pdo, private PayMongoAdapter $provider, private PaymentProcessor $payments, private FolioService $folios)
    {
    }

    /** POST /internal/reservations/{id}/checkout: hosted checkout for the SAVED price (Idempotency-Key required). */
    public function checkout(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'checkout.start');
        $rid = $this->uuid($p['id']);
        $now = time();
        [$status, $body, $replayed] = Idempotency::run(
            new PdoIdempotencyStore($this->pdo), $r->header('Idempotency-Key'), $user['accountId'], Idempotency::hash($r->method, $r->path, $r->body),
            function () use ($user, $rid, $now): array {
                // Read the saved price from Booking (404 for a reservation this user may not see).
                $res = Peer::ok('billing', 'booking', 'GET', "/internal/reservations/{$rid}", $user);
                if ($res['status'] !== 'HELD' || strtotime((string) $res['holdExpiresAt']) <= $now) {
                    throw new ApiException(409, 'RESERVATION_NOT_PAYABLE', 'Only an unexpired hold can be paid for');
                }
                // The provider call happens with NO database transaction or lock open.
                $co = $this->provider->createCheckout($rid, (int) $res['amountMinor'], (string) $res['currency'], 'HarborLight Hotels stay ' . substr($rid, 0, 8));
                $this->pdo->prepare("INSERT INTO checkout_session (session_id, reservation_id, amount_minor, currency, status, checkout_url, created_at) VALUES (?, ?, ?, ?, 'PENDING', ?, UTC_TIMESTAMP())")
                    ->execute([$co['sessionId'], $rid, (int) $res['amountMinor'], $res['currency'], $co['checkoutUrl']]);
                return [201, ['reservationId' => $rid, 'sessionId' => $co['sessionId'], 'checkoutUrl' => $co['checkoutUrl'], 'amountMinor' => (int) $res['amountMinor'], 'currency' => $res['currency']]];
            },
            $now
        );
        return [$status, $body, $replayed ? ['Idempotent-Replayed' => 'true'] : []];
    }

    /** POST /internal/webhooks/paymongo: raw body + original signature forwarded by the portal. */
    public function webhook(Request $r): array
    {
        ServiceAuth::authenticate($r);   // only the portal gateway may forward callbacks
        return $this->handleWebhook($r->body, $r->header('Paymongo-Signature'));
    }

    /** Demo only (PAYMONGO_MODE=mock): act as the provider and send a signed "paid" webhook for a session. */
    public function mockPay(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        if (!$this->provider->isMock()) {
            throw new ApiException(404, 'NOT_FOUND');
        }
        Rbac::require($user['role'], 'checkout.start');
        $id = (string) ($r->json()['sessionId'] ?? '');
        $st = $this->pdo->prepare("SELECT reservation_id, amount_minor, currency FROM checkout_session WHERE session_id = ? AND status = 'PENDING'");
        $st->execute([$id]);
        $s = $st->fetch() ?: throw new ApiException(404, 'NOT_FOUND', 'No pending checkout session');
        $body = json_encode(PayMongoAdapter::mockPaidEvent($id, $s['reservation_id'], (int) $s['amount_minor'], $s['currency']));
        return $this->handleWebhook($body, PayMongoAdapter::signatureHeader($body, $this->provider->webhookSecret(), time()));
    }

    public function folio(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'folio.read');
        return [200, $this->folios->summary($this->uuid($p['id']))];
    }

    public function charge(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'charge.post');
        $b = $r->json();
        return $this->folios->postCharge($this->uuid($p['id']), (string) ($b['description'] ?? ''), is_int($b['amountMinor'] ?? null) ? $b['amountMinor'] : 0, $user['accountId']);
    }

    public function settle(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'folio.settle');
        return $this->folios->settle($this->uuid($p['id']), $user['accountId']);
    }

    public function requestRefund(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'refund.request');
        $b = $r->json();
        return $this->folios->requestRefund($this->uuid((string) ($b['paymentId'] ?? '')), is_int($b['amountMinor'] ?? null) ? $b['amountMinor'] : 0, $user['accountId'], (string) ($b['reason'] ?? 'MANUAL'));
    }

    public function approveRefund(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'refund.approve');
        return $this->folios->approveRefund($this->uuid($p['id']), $user);
    }

    /** POST /internal/events: StayCheckedIn opens the bill, StayCheckedOut closes it, RefundRequested files a refund. */
    public function events(Request $r): array
    {
        ServiceAuth::authenticate($r);
        $e = $r->json();
        if (!is_string($e['eventId'] ?? null) || !Uuid::isValid($e['eventId']) || !is_string($e['eventType'] ?? null) || !is_array($e['data'] ?? null)) {
            throw new ApiException(422, 'INVALID_EVENT');
        }
        $d = $e['data'];
        $apply = static function (): void {
        };
        switch ($e['eventType']) {
            case 'StayCheckedIn':
                // Look the price up BEFORE the local transaction; if Booking is down the delivery is retried.
                $res = Peer::ok('billing', 'booking', 'GET', '/internal/reservations/' . $this->uuid((string) ($d['reservationId'] ?? '')));
                $apply = fn () => $this->folios->open($d['reservationId'], (int) $res['amountMinor'], (string) $res['currency']);
                break;
            case 'StayCheckedOut':
                $apply = fn () => $this->folios->close((string) ($d['reservationId'] ?? ''));
                break;
            case 'RefundRequested':
                $apply = fn () => $this->folios->requestFromEvent($d);
                break;
        }
        return [200, ['status' => Inbox::once($this->pdo, $e['eventId'], $apply) ? 'applied' : 'duplicate']];
    }

    private function handleWebhook(string $rawBody, ?string $signature): array
    {
        if (!PayMongoAdapter::verify($rawBody, $signature, $this->provider->webhookSecret())) {
            throw new ApiException(403, 'INVALID_SIGNATURE', 'Webhook signature check failed');
        }
        $event = json_decode($rawBody, true);
        $paid = is_array($event) ? PayMongoAdapter::parsePaid($event) : throw new ApiException(422, 'INVALID_WEBHOOK');
        if ($paid === null) {
            return [200, ['received' => true, 'result' => 'ignored']];
        }
        $result = $this->payments->record($paid);
        if ($result === 'review') {
            Log::info('payment held for finance review', ['providerPaymentId' => $paid['providerPaymentId']]);
        }
        return [200, ['received' => true, 'result' => $result]];   // acknowledged only after the commit above
    }

    private function uuid(string $v): string
    {
        return Uuid::isValid($v) ? $v : throw new ApiException(404, 'NOT_FOUND');
    }
}
