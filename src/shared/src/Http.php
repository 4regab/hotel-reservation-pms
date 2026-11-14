<?php
declare(strict_types=1);

namespace Lib;

/** Minimal HTTP client on PHP streams (no extension needed). Status 0 means the call could not be made. */
final class Http
{
    /** @param list<string> $headers @return array{0:int,1:string} */
    public static function call(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 5): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body ?? '',
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return [0, ''];
        }
        $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($lines[0]) && preg_match('#\s(\d{3})(\s|$)#', $lines[0], $m) ? (int) $m[1] : 0;
        return [$status, $resp];
    }
}
