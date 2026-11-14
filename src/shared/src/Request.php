<?php
declare(strict_types=1);

namespace Lib;

final class Request
{
    /** @param array<string,string> $headers lower-case names */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path === '' ? '/' : $path, $_GET, $headers, (string) file_get_contents('php://input'));
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Body as a JSON object. Empty body is an empty object. */
    public function json(): array
    {
        if (trim($this->body) === '') {
            return [];
        }
        $d = json_decode($this->body, true);
        if (!is_array($d)) {
            throw new ApiException(422, 'INVALID_JSON', 'Request body must be a JSON object');
        }
        return $d;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
