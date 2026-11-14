<?php
declare(strict_types=1);

namespace Portal;

use Lib\ApiException;
use Lib\Dates;
use Lib\Inbox;
use Lib\Request;
use Lib\ServiceAuth;
use Lib\Uuid;

/** Portal consumer: queues emails and updates read-only reporting copies. Reports never sell rooms. */
final class EventConsumer
{
    private const COLUMNS = ['confirmed', 'cancelled', 'revenue_minor', 'refunded_minor', 'check_ins', 'check_outs'];

    public function __construct(private \PDO $pdo)
    {
    }

    public function handle(Request $r): array
    {
        ServiceAuth::authenticate($r);
        $e = $r->json();
        if (!is_string($e['eventId'] ?? null) || !Uuid::isValid($e['eventId']) || !is_string($e['eventType'] ?? null) || !is_array($e['data'] ?? null)) {
            throw new ApiException(422, 'INVALID_EVENT');
        }
        $d = $e['data'];
        $apply = function () use ($e, $d): void {
            switch ($e['eventType']) {
                case 'ReservationConfirmed':
                    Notifications::queue($this->pdo, 'EMAIL_CONFIRMATION', $d['reservationId']);
                    $this->report('confirmed', 1);
                    break;
                case 'ReservationCancelled':
                    Notifications::queue($this->pdo, 'EMAIL_CANCELLATION', $d['reservationId']);
                    $this->report('cancelled', 1);
                    break;
                case 'PaymentCaptured':
                    $this->report('revenue_minor', (int) $d['amountMinor']);
                    break;
                case 'RefundCompleted':
                    $this->report('refunded_minor', (int) $d['amountMinor']);
                    Notifications::queue($this->pdo, 'EMAIL_REFUND', $d['reservationId'], null, ['amountMinor' => (int) $d['amountMinor']]);
                    break;
                case 'StayCheckedIn':
                    $this->report('check_ins', 1);
                    break;
                case 'StayCheckedOut':
                    $this->report('check_outs', 1);
                    break;
            }
        };
        return [200, ['status' => Inbox::once($this->pdo, $e['eventId'], $apply) ? 'applied' : 'duplicate']];
    }

    private function report(string $column, int $amount): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new \InvalidArgumentException('unknown report column');
        }
        $this->pdo->prepare("MERGE report_daily WITH (HOLDLOCK) AS d USING (SELECT CAST(? AS DATE) AS day, CAST(? AS BIGINT) AS amount) AS s ON d.day = s.day
                             WHEN MATCHED THEN UPDATE SET [{$column}] = d.[{$column}] + s.amount, updated_at = DATETRUNC(SECOND, SYSUTCDATETIME())
                             WHEN NOT MATCHED THEN INSERT (day, [{$column}], updated_at) VALUES (s.day, s.amount, DATETRUNC(SECOND, SYSUTCDATETIME()));")
            ->execute([Dates::manilaToday(), $amount]);
    }
}
