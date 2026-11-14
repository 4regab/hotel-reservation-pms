<?php
declare(strict_types=1);

namespace Lib;

final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    /** Pattern like /internal/reservations/{id}. Handler: fn(Request, array $params): array{0:int,1:mixed,2?:array} */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    /** @return array{0:int,1:mixed,2:array} */
    public function dispatch(Request $r): array
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $r->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $r->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $res = $handler($r, $params);
            return [$res[0], $res[1], $res[2] ?? []];
        }
        throw $pathMatched ? new ApiException(405, 'METHOD_NOT_ALLOWED') : new ApiException(404, 'NOT_FOUND');
    }
}
