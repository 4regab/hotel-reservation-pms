<?php
declare(strict_types=1);

namespace Lib;

final class App
{
    /** Run a router for the current request: JSON in, JSON out, one error shape for everything. */
    public static function run(Router $router, ?Request $request = null): void
    {
        $request ??= Request::fromGlobals();
        try {
            [$status, $body, $headers] = $router->dispatch($request);
        } catch (ApiException $e) {
            [$status, $body, $headers] = [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + ($e->extra ? ['details' => $e->extra] : [])], []];
        } catch (\PDOException $e) {
            Log::info('database error', ['code' => (string) $e->getCode(), 'message' => $e->getMessage()]);
            [$status, $body, $headers] = [503, ['error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Temporarily unavailable']], ['Retry-After' => '5']];
        } catch (\Throwable $e) {
            Log::info('unhandled error', ['type' => $e::class, 'message' => $e->getMessage()]);
            [$status, $body, $headers] = [500, ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Unexpected error']], []];
        }
        http_response_code($status);
        foreach ($headers as $k => $v) {
            header("{$k}: {$v}");
        }
        if (is_string($body)) {
            echo $body;
            return;
        }
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}
