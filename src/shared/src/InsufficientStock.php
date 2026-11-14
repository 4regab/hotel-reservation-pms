<?php
declare(strict_types=1);

namespace Lib;

final class InsufficientStock extends ApiException
{
    public function __construct(public readonly string $night)
    {
        parent::__construct(409, 'INVENTORY_CONFLICT', "No capacity left on {$night}");
    }
}
