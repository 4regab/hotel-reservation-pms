<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Db;
use Lib\Http;
use Lib\Log;
use Lib\Uuid;

/**
 * Sends email through the Resend HTTPS API when RESEND_API_KEY is set. With no key the message is only written to the
 * email_log table (status LOGGED) so the whole flow works offline. Every email that is sent or logged is recorded in email_log.
 * Env: RESEND_API_KEY, MAIL_FROM (for example "MA Hotel <reservations@your-domain.example>").
 */
final class Mailer
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(private \PDO $pdo)
    {
    }

    public static function live(): bool
    {
        return (string) getenv('RESEND_API_KEY') !== '';
    }

    /** @return string SENT or LOGGED. Throws ApiException when the provider refuses or cannot be reached (the job retries). */
    public function send(string $template, string $to, string $subject, string $html, string $text, ?string $jobId = null): string
    {
        $status = 'LOGGED';
        $providerId = null;
        if (self::live()) {
            $from = (string) getenv('MAIL_FROM');
            if ($from === '') {
                throw new ApiException(503, 'MAIL_NOT_CONFIGURED', 'MAIL_FROM is required when RESEND_API_KEY is set');
            }
            $headers = ['Authorization: Bearer ' . getenv('RESEND_API_KEY'), 'Content-Type: application/json'];
            if ($jobId !== null) {
                $headers[] = 'Idempotency-Key: hl-' . $jobId;   // a retried job never sends twice
            }
            [$code, $body] = Http::call('POST', self::ENDPOINT, $headers, json_encode(['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => $text]), 15);
            if ($code < 200 || $code >= 300) {
                $d = json_decode($body, true);
                $why = $code === 0 ? 'Mail provider not reachable' : 'Mail provider answered ' . $code . (is_array($d) && isset($d['message']) ? ': ' . mb_substr((string) $d['message'], 0, 120) : '');
                throw new ApiException(502, 'MAIL_PROVIDER_ERROR', $why);
            }
            $d = json_decode($body, true);
            $providerId = is_array($d) && isset($d['id']) ? (string) $d['id'] : null;
            $status = 'SENT';
        }
        $this->record($template, $to, $subject, $html, $status, $providerId, null, $jobId);
        Log::info('email ' . strtolower($status), ['template' => $template, 'jobId' => $jobId]);   // no address or body in logs
        return $status;
    }

    /** Terminal failure after the last retry. */
    public function recordFailure(string $template, string $to, string $subject, string $error, ?string $jobId): void
    {
        $this->record($template, $to, $subject, '', 'FAILED', null, mb_substr($error, 0, 200), $jobId);
    }

    public function alreadyDone(string $jobId): bool
    {
        return Db::call($this->pdo, 'usp_emailLog_isDone', ['job_id' => $jobId])->fetchColumn() !== false;
    }

    private function record(string $template, string $to, string $subject, string $html, string $status, ?string $providerId, ?string $error, ?string $jobId): void
    {
        Db::call($this->pdo, 'usp_emailLog_insert', ['email_id' => Uuid::v4(), 'template' => $template, 'to_email' => $to, 'subject' => $subject, 'body_html' => $html, 'status' => $status, 'provider_id' => $providerId, 'error' => $error, 'job_id' => $jobId]);
    }
}
