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
    public function __construct(private \PDO $pdo, private PayMongoAdapter $provider, private PaymentProcessor $payments, private FolioService $folios, private FinanceService $finance)
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
                $this->pdo->prepare("INSERT INTO checkout_session (session_id, reservation_id, amount_minor, currency, status, checkout_url, created_at) VALUES (?, ?, ?, ?, 'PENDING', ?, DATETRUNC(SECOND, SYSUTCDATETIME()))")
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
        return $this->folios->postCharge($this->uuid($p['id']), (string) ($b['description'] ?? ''), is_int($b['amountMinor'] ?? null) ? $b['amountMinor'] : 0, $user, $r->ip());
    }

    public function settle(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'folio.settle');
        return $this->folios->settle($this->uuid($p['id']), $user, (string) ($r->json()['method'] ?? 'CASH'), $r->ip());
    }

    public function requestRefund(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'refund.request');
        $b = $r->json();
        return $this->folios->requestRefund($this->uuid((string) ($b['paymentId'] ?? '')), is_int($b['amountMinor'] ?? null) ? $b['amountMinor'] : 0, $user['accountId'], mb_substr((string) ($b['reason'] ?? 'MANUAL'), 0, 40), $user, $r->ip());
    }

    public function approveRefund(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'refund.approve');
        return $this->folios->approveRefund($this->uuid($p['id']), $user, $r->ip());
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
                $apply = fn () => $this->folios->open($d['reservationId'], (int) $res['amountMinor'], (string) $res['currency'], (string) ($res['guarantee'] ?? 'NONE'));
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

    // ---- finance (cashier, manager)

    public function payments(Request $r): array
    {
        $this->user($r, 'payment.read');
        return $this->finance->payments($r->query);
    }

    public function reconcile(Request $r, array $p): array
    {
        $u = $this->user($r, 'payment.reconcile');
        if ($p['id'] === 'batch') {
            return $this->finance->reconcileUpTo((string) ($r->json()['date'] ?? ''), $u, $r->ip());
        }
        return $this->finance->reconcile($this->uuid($p['id']), $u, $r->ip());
    }

    public function refunds(Request $r): array
    {
        $this->user($r, 'refund.read');
        return $this->finance->refunds((string) ($r->query['status'] ?? ''));
    }

    public function rejectRefund(Request $r, array $p): array
    {
        $u = $this->user($r, 'refund.approve');
        return $this->finance->rejectRefund($this->uuid($p['id']), (string) ($r->json()['note'] ?? ''), $u, $r->ip());
    }

    /** POST /internal/reservations/{id}/onsite-payment : walk-in pays at the desk for a live hold. */
    public function onsitePayment(Request $r, array $p): array
    {
        $u = $this->user($r, 'payment.onsite');
        $rid = $this->uuid($p['id']);
        $res = Peer::ok('billing', 'booking', 'GET', "/internal/reservations/{$rid}", $u);
        if ($res['status'] !== 'HELD' || strtotime((string) $res['holdExpiresAt']) <= time()) {
            throw new ApiException(409, 'RESERVATION_NOT_PAYABLE', 'Only an unexpired hold can be paid for');
        }
        return $this->finance->onsitePayment($res, (string) ($r->json()['method'] ?? 'CASH'), $u, $r->ip());
    }

    public function receipt(Request $r, array $p): array
    {
        $this->user($r, 'folio.read');
        return [200, $this->finance->receipt($this->uuid($p['id']))];
    }

    public function exportRequest(Request $r): array
    {
        $u = $this->user($r, 'export.request');
        $b = $r->json();
        return $this->finance->requestExport((string) ($b['from'] ?? ''), (string) ($b['to'] ?? ''), $u, $r->ip());
    }

    public function exports(Request $r): array
    {
        $this->user($r, 'export.read');
        return $this->finance->exports();
    }

    public function exportDecide(Request $r, array $p, bool $approve): array
    {
        $u = $this->user($r, 'export.approve');
        return $this->finance->decideExport($this->uuid($p['id']), $approve, $u, $r->ip());
    }

    /** GET /internal/exports/{id}/download : CSV text wrapped in JSON (the portal serves the file). */
    public function exportDownload(Request $r, array $p): array
    {
        $u = $this->user($r, 'export.download');
        [$csv, $name, $sum] = $this->finance->download($this->uuid($p['id']), $u, $r->ip());
        return [200, ['filename' => $name, 'checksum' => $sum, 'csv' => $csv]];
    }

    public function revenue(Request $r): array
    {
        $this->user($r, 'reports.read');
        return $this->finance->revenue($r->query);
    }

    public function audit(Request $r): array
    {
        $this->user($r, 'audit.read');
        return $this->finance->audit($r->query);
    }

    public function outbox(Request $r): array
    {
        $this->user($r, 'ops.read');
        return $this->finance->outbox();
    }

    private function user(Request $r, string $action): array
    {
        $u = ServiceAuth::authenticate($r)['user'];
        Rbac::require($u['role'], $action);
        return $u;
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
