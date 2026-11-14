<?php
declare(strict_types=1);

namespace Lib;

/**
 * Role -> action table. The portal checks it at the gateway and every service checks it
 * again, so a bypassed gateway still cannot widen access. Guests are further limited to
 * their own records by the services ("inaccessible records return 404").
 * Housekeeping and maintenance never get payment or identity actions. Stewards never get booking or payment actions.
 */
final class Rbac
{
    public const ROLES = ['guest', 'reception', 'housekeeping', 'maintenance', 'cashier', 'manager', 'it', 'steward'];

    private const ALLOW = [
        // reservations
        'reservation.create'    => ['guest', 'reception', 'manager'],
        'reservation.read'      => ['guest', 'reception', 'cashier', 'manager', 'service'],
        'reservation.list'      => ['guest', 'reception', 'cashier', 'manager'],
        'reservation.cancel'    => ['guest', 'reception', 'manager'],
        'reservation.noshow'    => ['reception', 'manager'],
        'reservation.guarantee' => ['manager'],
        'guarantee.request'     => ['reception', 'manager'],
        'checkout.start'        => ['guest', 'reception', 'manager'],
        'payment.onsite'        => ['reception', 'cashier', 'manager'],
        // stays and rooms
        'stay.checkin'          => ['reception', 'manager'],
        'stay.checkout'         => ['reception', 'manager'],
        'stay.read'             => ['reception', 'manager'],
        'arrivals.read'         => ['reception', 'manager'],
        'room.read'             => ['reception', 'housekeeping', 'maintenance', 'manager'],
        'room.status'           => ['housekeeping', 'manager'],
        'room.inspect'          => ['housekeeping', 'manager'],
        'cleaning.read'         => ['housekeeping', 'manager', 'reception'],
        'maintenance.request'   => ['maintenance', 'housekeeping', 'manager'],
        'maintenance.read'      => ['maintenance', 'housekeeping', 'manager', 'reception'],
        'maintenance.decide'    => ['manager'],
        'override.request'      => ['reception', 'manager'],
        'override.decide'       => ['manager'],
        // money
        'charge.post'           => ['cashier', 'manager'],
        'folio.read'            => ['reception', 'cashier', 'manager', 'service'],
        'folio.settle'          => ['reception', 'cashier', 'manager', 'service'],
        'payment.read'          => ['cashier', 'manager'],
        'payment.reconcile'     => ['cashier', 'manager'],
        'refund.read'           => ['cashier', 'manager'],
        'refund.request'        => ['cashier', 'manager'],
        'refund.approve'        => ['manager'],
        'export.request'        => ['cashier', 'manager'],
        'export.read'           => ['cashier', 'manager'],
        'export.approve'        => ['manager'],
        'export.download'       => ['cashier', 'manager'],
        // inventory and channel
        'roomtype.read'         => ['guest', 'reception', 'housekeeping', 'maintenance', 'cashier', 'manager', 'it', 'steward', 'service'],
        'roomtype.update'       => ['manager'],
        'ota.read'              => ['reception', 'manager'],
        'ota.request'           => ['reception', 'manager'],
        'ota.approve'           => ['manager'],
        'ota.import'            => ['reception', 'manager'],
        'capacity.reserve'      => ['service'],
        // guests and privacy
        'guest.read'            => ['reception', 'manager', 'steward'],
        'guest.create'          => ['reception', 'manager'],
        'guest.merge'           => ['steward'],
        'privacy.subject'       => ['it', 'service'],
        // oversight
        'reports.read'          => ['manager', 'it', 'cashier'],
        'audit.read'            => ['it', 'manager'],
        'ops.read'              => ['it', 'manager'],
        'user.admin'            => ['it'],
        'privacy.handle'        => ['it'],
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

    /** @return list<string> actions a role holds (used by tests and the role admin page) */
    public static function actionsFor(string $role): array
    {
        return array_keys(array_filter(self::ALLOW, fn ($roles) => in_array($role, $roles, true)));
    }
}
