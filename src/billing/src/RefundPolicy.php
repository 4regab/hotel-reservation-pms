<?php
declare(strict_types=1);

namespace Billing;

use Lib\ApiException;

final class RefundPolicy
{
    /** Only a manager approves, and never a refund they requested themselves (cashiers cannot approve their own). */
    public static function assertCanApprove(string $role, string $approverAccountId, string $requestedBy): void
    {
        if ($role !== 'manager') {
            throw new ApiException(403, 'FORBIDDEN', 'Only a manager can approve refunds');
        }
        if ($approverAccountId === $requestedBy) {
            throw new ApiException(403, 'SELF_APPROVAL', 'A refund cannot be approved by the person who requested it');
        }
    }
}
