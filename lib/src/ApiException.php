<?php
declare(strict_types=1);

namespace Lib;

/** An error that maps straight to an HTTP status and a stable error code. */
class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message = '', public readonly array $extra = [])
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
