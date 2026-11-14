<?php
declare(strict_types=1);

// The machine-readable contracts must match the code. Both files fail this check the moment a route or an event changes without them.
require_once dirname(__DIR__, 2) . '/scripts/gen-openapi.php';

use Lib\Routing;

return [
    'docs: openapi.yaml is exactly what scripts/gen-openapi.php builds from the routes and roles' => function (): void {
        $file = dirname(__DIR__, 2) . '/docs/openapi.yaml';
        check((string) file_get_contents($file) === buildOpenApi(), 'docs/openapi.yaml is out of date. Run: php scripts/gen-openapi.php');
    },
    'docs: asyncapi.yaml lists every event type that services publish or subscribe to' => function (): void {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/asyncapi.yaml');
        foreach (Routing::SUBSCRIBERS as $type => $consumers) {
            check(str_contains($doc, "hotel.{$type}.v1:"), "channel for {$type}");
            check(str_contains($doc, "Consumed by " . implode(', ', $consumers) . '.'), "consumers of {$type} match lib/src/Routing.php");
        }
        preg_match_all('/^  hotel\.(\w+)\.v1:/m', $doc, $m);
        $want = array_keys(Routing::SUBSCRIBERS);
        sort($want);
        sort($m[1]);
        same($want, $m[1], 'no extra and no missing channels');
    },
];
