<?php
declare(strict_types=1);

namespace Lib;

/** Which services consume which event (spec section 6.6 event table). */
final class Routing
{
    public const SUBSCRIBERS = [
        'ReservationConfirmed'     => ['roomops', 'portal'],
        'ReservationCancelled'     => ['roomops', 'portal'],
        'ReservationNoShow'        => ['roomops', 'portal'],
        'PaymentCaptured'          => ['booking', 'portal'],
        'RefundRequested'          => ['billing'],
        'RefundCompleted'          => ['booking', 'portal'],
        'StayCheckedIn'            => ['booking', 'billing', 'portal'],
        'StayCheckedOut'           => ['booking', 'billing', 'portal'],
        'MaintenanceBlockApproved' => ['portal'],
    ];

    /** @return list<string> */
    public static function handlers(string $eventType): array
    {
        return self::SUBSCRIBERS[$eventType] ?? throw new \InvalidArgumentException("unknown event type {$eventType}");
    }

    /** Base URL of a service on the private network, from env, e.g. BOOKING_URL. */
    public static function url(string $service): string
    {
        return rtrim((string) getenv(strtoupper($service) . '_URL'), '/');
    }
}
