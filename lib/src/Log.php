<?php
declare(strict_types=1);

namespace Lib;

final class Log
{
    public static function info(string $message, array $context = []): void
    {
        error_log(json_encode(['msg' => $message] + Pii::scrub($context), JSON_UNESCAPED_SLASHES));
    }
}
