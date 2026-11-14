<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;
use Lib\Http;
use Lib\Uuid;

/**
 * The only place that knows PayMongo field names. Two modes:
 *  - mock:    no network, no keys. Checkout URLs point at the portal's demo pay page and the
 *             "paid" webhook is built and signed locally with PAYMONGO_WEBHOOK_SECRET.
 *  - sandbox: real calls to api.paymongo.com with PAYMONGO_SECRET_KEY (a test key, sk_test_...).
 * Card numbers and CVV never reach this system: PayMongo hosts the card form.
 */
final class PayMongoAdapter
{
    private const API = 'https://api.paymongo.com/v1';

    public function __construct(private string $mode, private string $secretKey, private string $webhookSecret, private string $publicBaseUrl)
    {
    }

    public static function fromEnv(): self
    {
        $mode = getenv('PAYMONGO_MODE') ?: 'mock';
        if (!in_array($mode, ['mock', 'sandbox'], true)) {
            throw new \RuntimeException('PAYMONGO_MODE must be mock or sandbox');
        }
        return new self($mode, (string) getenv('PAYMONGO_SECRET_KEY'), (string) getenv('PAYMONGO_WEBHOOK_SECRET'), rtrim((string) (getenv('PUBLIC_BASE_URL') ?: 'http://localhost:8080'), '/'));
    }

    public function isMock(): bool
    {
        return $this->mode === 'mock';
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    /** @return array{sessionId:string,checkoutUrl:string} */
    public function createCheckout(string $reservationId, int $amountMinor, string $currency, string $description): array
    {
        if ($this->isMock()) {
            $id = 'cs_mock_' . bin2hex(random_bytes(8));
            return ['sessionId' => $id, 'checkoutUrl' => "{$this->publicBaseUrl}/mock/paymongo/checkout/{$id}"];
        }
        $body = ['data' => ['attributes' => [
            'line_items'           => [['currency' => $currency, 'amount' => $amountMinor, 'name' => $description, 'quantity' => 1]],
            'payment_method_types' => ['card', 'gcash', 'paymaya'],
            'description'          => $description,
            'metadata'             => ['reservationId' => $reservationId],
            'success_url'          => "{$this->publicBaseUrl}/reservations/{$reservationId}?returned=1",   // display only; never proves payment
            'cancel_url'           => "{$this->publicBaseUrl}/reservations/{$reservationId}",
        ]]];
        [$status, $text] = Http::call('POST', self::API . '/checkout_sessions', ['Authorization: Basic ' . base64_encode($this->secretKey . ':'), 'Content-Type: application/json'], json_encode($body), 15);
        $d = json_decode($text, true);
        if ($status < 200 || $status >= 300 || !isset($d['data']['id'], $d['data']['attributes']['checkout_url'])) {
            throw new ApiException(503, 'PAYMENT_PROVIDER_UNAVAILABLE', 'Could not start checkout with the payment provider');
        }
        return ['sessionId' => (string) $d['data']['id'], 'checkoutUrl' => (string) $d['data']['attributes']['checkout_url']];
    }

    /** Ask the provider to refund a payment. Mock mode always succeeds. */
    public function refund(string $providerPaymentId, int $amountMinor): void
    {
        if ($this->isMock()) {
            return;
        }
        $body = ['data' => ['attributes' => ['payment_id' => $providerPaymentId, 'amount' => $amountMinor, 'reason' => 'others']]];
        [$status] = Http::call('POST', self::API . '/refunds', ['Authorization: Basic ' . base64_encode($this->secretKey . ':'), 'Content-Type: application/json'], json_encode($body), 15);
        if ($status < 200 || $status >= 300) {
            throw new ApiException(503, 'PAYMENT_PROVIDER_UNAVAILABLE', 'The payment provider did not accept the refund');
        }
    }

    /**
     * Header format: t=<unix time>,te=<test signature>,li=<live signature>.
     * Signature = HMAC-SHA256 of "<t>.<raw body>" with the webhook secret. Uses the original raw body.
     * ponytail: no timestamp tolerance; replays are harmless because provider event and payment ids are unique.
     */
    public static function verify(string $rawBody, ?string $header, string $secret, bool $live = false): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        $given = $parts[$live ? 'li' : 'te'] ?? '';
        $t = $parts['t'] ?? '';
        return $t !== '' && $given !== '' && hash_equals(hash_hmac('sha256', $t . '.' . $rawBody, $secret), $given);
    }

    public static function signatureHeader(string $rawBody, string $secret, int $t): string
    {
        return 't=' . $t . ',te=' . hash_hmac('sha256', $t . '.' . $rawBody, $secret) . ',li=';
    }

    /**
     * Translate a provider event into our own shape. Returns null for event types we do not act on.
     * @return array{providerEventId:string,providerPaymentId:string,sessionId:string,reservationId:string,amountMinor:int,currency:string}|null
     */
    public static function parsePaid(array $event): ?array
    {
        $attrs = $event['data']['attributes'] ?? [];
        if (($attrs['type'] ?? '') !== 'checkout_session.payment.paid') {
            return null;
        }
        $session = $attrs['data'] ?? [];
        $payment = $session['attributes']['payments'][0] ?? [];
        $out = [
            'providerEventId'   => (string) ($event['data']['id'] ?? ''),
            'providerPaymentId' => (string) ($payment['id'] ?? ''),
            'sessionId'         => (string) ($session['id'] ?? ''),
            'reservationId'     => (string) ($session['attributes']['metadata']['reservationId'] ?? ''),
            'amountMinor'       => (int) ($payment['attributes']['amount'] ?? 0),
            'currency'          => (string) ($payment['attributes']['currency'] ?? ''),
        ];
        if (in_array('', [$out['providerEventId'], $out['providerPaymentId'], $out['sessionId'], $out['reservationId'], $out['currency']], true) || $out['amountMinor'] <= 0) {
            throw new ApiException(422, 'INVALID_WEBHOOK', 'Webhook payload is missing required fields');
        }
        return $out;
    }

    /** Build the "paid" event PayMongo would send (mock mode only). Only the method and the last four card digits are included. */
    public static function mockPaidEvent(string $sessionId, string $reservationId, int $amountMinor, string $currency, string $method = 'card', string $last4 = ''): array
    {
        $source = ['type' => $method] + ($last4 !== '' ? ['last4' => $last4] : []);
        return ['data' => ['id' => 'evt_mock_' . Uuid::v4(), 'type' => 'event', 'attributes' => [
            'type' => 'checkout_session.payment.paid',
            'data' => ['id' => $sessionId, 'type' => 'checkout_session', 'attributes' => [
                'metadata' => ['reservationId' => $reservationId],
                'payments' => [['id' => 'pay_mock_' . bin2hex(random_bytes(8)), 'attributes' => ['amount' => $amountMinor, 'currency' => $currency, 'status' => 'paid', 'source' => $source]]],
            ]],
        ]]];
    }

    /** Build the "payment.failed" event PayMongo would send (mock mode only). Billing acknowledges it and records nothing. */
    public static function mockFailedEvent(string $sessionId, string $reservationId, int $amountMinor, string $currency, string $method, string $reason): array
    {
        return ['data' => ['id' => 'evt_mock_' . Uuid::v4(), 'type' => 'event', 'attributes' => [
            'type' => 'payment.failed',
            'data' => ['id' => 'pay_mock_' . bin2hex(random_bytes(8)), 'type' => 'payment', 'attributes' => [
                'amount' => $amountMinor, 'currency' => $currency, 'status' => 'failed', 'source' => ['type' => $method],
                'last_payment_error' => ['code' => $reason], 'metadata' => ['reservationId' => $reservationId, 'checkoutSessionId' => $sessionId],
            ]],
        ]]];
    }
}
