<?php
declare(strict_types=1);

namespace Lib;

/**
 * Role -> action table. The portal checks it at the gateway and every service checks it
 * again, so a bypassed gateway still cannot widen access. Guests are further limited to
 * their own records by the services ("inaccessible records return 404").
 */
final class Rbac
{
    public const ROLES = ['guest', 'reception', 'housekeeping', 'maintenance', 'cashier', 'manager', 'it', 'steward'];

    private const ALLOW = [
        'reservation.create'    => ['guest', 'reception', 'manager'],
        'reservation.read'      => ['guest', 'reception', 'cashier', 'manager', 'service'],
        'reservation.cancel'    => ['guest', 'reception', 'manager'],
        'reservation.guarantee' => ['manager'],
        'checkout.start'        => ['guest', 'reception', 'manager'],
        'stay.checkin'          => ['reception', 'manager'],
        'stay.checkout'         => ['reception', 'manager'],
        'arrivals.read'         => ['reception', 'manager'],
        'room.read'             => ['reception', 'housekeeping', 'maintenance', 'manager'],
        'room.status'           => ['housekeeping', 'manager'],
        'cleaning.read'         => ['housekeeping', 'manager'],
        'maintenance.request'   => ['maintenance', 'manager'],
        'charge.post'           => ['cashier', 'manager'],
        'folio.read'            => ['reception', 'cashier', 'manager', 'service'],
        'folio.settle'          => ['reception', 'cashier', 'manager', 'service'],
        'refund.request'        => ['cashier', 'manager'],
        'refund.approve'        => ['manager'],
        'reports.read'          => ['manager', 'it'],
        'capacity.reserve'      => ['service'],
    ];

    public static function can(string $role, string $action): bool
    {
        return in_array($role, self::ALLOW[$action] ?? [], true);
    }

    public static function require(string $role, string $action): void
    {
        if (!self::can($role, $action)) {
            throw new ApiException(403, 'FORBIDDEN', 'Your role may not do this');
        }
    }

    /** Staff roles need a second factor at login. */
    public static function isStaff(string $role): bool
    {
        return $role !== 'guest';
    }
}
