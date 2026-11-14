<?php
declare(strict_types=1);

/**
 * Writes docs/openapi.yaml from the code, so the document cannot drift from the routes.
 * The route list and the roles come from Portal\Gateway::ROUTES and Lib\Rbac. Only the wording below is hand-written.
 * Usage: php scripts/gen-openapi.php          rewrite docs/openapi.yaml
 *        php scripts/gen-openapi.php --check  exit 1 when the file is out of date (a logic check does the same)
 */
require dirname(__DIR__) . '/autoload.php';

function buildOpenApi(): string
{
    $routes = (new ReflectionClassConstant(Portal\Gateway::class, 'ROUTES'))->getValue();
    $allow = (new ReflectionClassConstant(Lib\Rbac::class, 'ALLOW'))->getValue();

    // Routes served by the portal itself (not forwarded): [method, path, summary, who, writes]
    $own = [
        ['POST', '/v1/register', 'Create a guest account', 'anyone (rate limited)'],
        ['POST', '/v1/login', 'Sign in. Staff also send the 6-digit code in "otp". Starts the session and returns the CSRF token', 'anyone (rate limited, account locks after 5 failures)'],
        ['POST', '/v1/logout', 'Sign out', 'signed in'],
        ['GET', '/v1/me', 'Who am I, plus the CSRF token for this session', 'signed in'],
        ['POST', '/v1/password-reset', 'Ask for a reset link by email. Always answers 202, so it cannot be used to find out which emails have accounts', 'anyone (rate limited)'],
        ['POST', '/v1/password-reset/confirm', 'Set a new password with the one-time token from the email (valid 1 hour, works once)', 'anyone (rate limited)'],
        ['GET', '/v1/reports/summary', 'Daily counters kept from events (a reporting copy, never used to sell rooms)', 'manager, it, cashier'],
    ];
    $text = [
        'GET /v1/availability' => 'Search availability. Returns one option per room type with a signed price quote. Nothing is reserved',
        'POST /v1/reservations' => 'Create a 10-minute priced hold. Needs the quote from the search, an Idempotency-Key and the CSRF token',
        'GET /v1/reservations/{id}' => 'Read one reservation (by id; staff may use the confirmation code). A guest only sees their own: others get 404',
        'POST /v1/reservations/{id}/checkout' => 'Start the hosted payment page for the saved price of an unexpired hold. Needs an Idempotency-Key',
        'POST /v1/reservations/{id}/cancel' => 'Cancel using the terms saved with the booking. Free until 48 hours before arrival, else the first night is kept (a manager may waive it with waivePenalty)',
        'POST /v1/reservations/{id}/approve-guarantee' => 'Manager confirms a held reservation as pay-at-hotel',
        'POST /v1/webhooks/paymongo' => 'Payment provider callback. The signature of the raw body is checked; replays and late payments are handled safely',
        'POST /v1/stays/check-in' => 'Check a guest in: confirmed booking, identity verified, a ready room of the booked type, payment or guarantee, and the arrival date (earlier needs a manager override)',
        'POST /v1/stays/check-out' => 'Check a guest out: the balance must be settled; the room becomes DIRTY and a cleaning task is created',
        'PATCH /v1/rooms/{id}/status' => 'Change a room status by hand (DIRTY to CLEANING, out of service, back in service). READY is reached only by inspection. Rejects stale versions',
        'GET /v1/rooms' => 'List rooms with floor, view, bed type and readiness',
        'GET /v1/arrivals' => 'Expected arrivals (from confirmation events)',
        'GET /v1/cleaning-tasks' => 'Cleaning tasks, open by default',
        'POST /v1/maintenance-blocks' => 'Block a room for dates. A guest in the way, or no spare room of the type, returns 409 and goes to the manager',
        'GET /v1/folios/{id}' => 'The guest bill for a reservation: total, paid, refunded, balance',
        'POST /v1/folios/{id}/charges' => 'Add a charge to an open bill',
        'POST /v1/refunds' => 'Ask for a refund of a captured payment. One request per payment',
        'POST /v1/refunds/{id}/approve' => 'Approve and send a refund. Never by the person who asked for it',
        'GET /v1/room-types' => 'Room types with rates, sizes, beds and amenities',
        'PATCH /v1/room-types/{id}' => 'Manager changes a rate, wording or whether the type is for sale. Existing bookings keep their price',
        'GET /v1/reservations' => 'List reservations. Guests get their own. Filters: q, status, checkIn, checkOut, from, to, source, guaranteeRequested, ids, limit',
        'POST /v1/reservations/{id}/no-show' => 'Record a no-show (from 18:00 Manila time on the arrival date). Keeps the first night, refunds the rest, frees unused nights',
        'POST /v1/reservations/{id}/request-guarantee' => 'Ask the manager to approve pay-at-hotel for a live hold',
        'POST /v1/reservations/{id}/onsite-payment' => 'Record payment taken at the desk for a live hold. Confirms through the normal payment event',
        'GET /v1/guests' => 'Search guests (q)',
        'POST /v1/guests' => 'Create a guest without an account (walk-in). Reuses a guest with the same email',
        'GET /v1/guests/duplicates' => 'Groups of possible duplicate guests (same email, phone digits or name words). Counts only, no booking details',
        'POST /v1/guests/merge' => 'Merge one guest record into another and move their bookings',
        'GET /v1/ota/partners' => 'Travel partners that send bookings by CSV',
        'GET /v1/ota/allotments' => 'Rooms set aside for partners and how much is used',
        'POST /v1/ota/allotments' => 'Ask for rooms to be set aside for a partner for dates. A manager\'s own request is approved at once',
        'POST /v1/ota/allotments/{id}/approve' => 'Approve: moves stock from direct sales into the partner allotment',
        'POST /v1/ota/allotments/{id}/reject' => 'Decline a pending allotment request',
        'POST /v1/ota/allotments/{id}/return' => 'Stop selling: unused rooms go back to direct sales',
        'POST /v1/ota/import' => 'Import a partner CSV (csv as text). Each row either books a reserved room or is rejected with a reason. Re-importing is safe',
        'GET /v1/maintenance-blocks' => 'Maintenance blocks',
        'POST /v1/maintenance-blocks/{id}/reject' => 'Manager declines a conflicted block',
        'POST /v1/maintenance-blocks/{id}/retry' => 'Manager retries a conflicted block after the situation changed',
        'POST /v1/cleaning-tasks/{id}/clean' => 'The cleaner reports the room clean. It waits for inspection',
        'POST /v1/cleaning-tasks/{id}/inspect' => 'A second person inspects. Sets the room READY. Not allowed for the person who cleaned it, unless a manager',
        'POST /v1/arrival-overrides' => 'Reception asks for check-in before the arrival date',
        'GET /v1/arrival-overrides' => 'Early-arrival requests waiting for the manager',
        'POST /v1/arrival-overrides/{id}/approve' => 'Manager approves an early arrival ({id} is the reservation id)',
        'POST /v1/arrival-overrides/{id}/reject' => 'Manager declines an early arrival',
        'GET /v1/payments' => 'Payments in a date range (from, to, reconciled=0|1)',
        'POST /v1/payments/{id}/reconcile' => 'Mark a payment matched with the provider statement. Use "batch" as id with a date to match everything up to it',
        'GET /v1/refunds' => 'Refund requests and their state',
        'POST /v1/refunds/{id}/reject' => 'Manager declines a refund with a note. Never the person who asked',
        'GET /v1/exports' => 'Accounting exports and their approval state',
        'POST /v1/exports' => 'Prepare an accounting export for closed days (from, to). Needs approval before download',
        'POST /v1/exports/{id}/approve' => 'Approve an export. Never the person who requested it',
        'POST /v1/exports/{id}/reject' => 'Reject an export',
        'GET /v1/exports/{id}/download' => 'Download an approved export as CSV (returned as JSON with filename, checksum and csv)',
        'GET /v1/reports/occupancy' => 'Occupancy, ADR, RevPAR and booking KPIs for a date range (from, to)',
        'GET /v1/reports/revenue' => 'Cash in and out by day and method, and payment matching timeliness',
        'GET /v1/reports/operations' => 'Median queue-to-key and checkout-to-ready times, rooms by status',
        'GET /v1/mock/paymongo/sessions/{id}' => 'Demo mode only: the pending checkout session shown on the test checkout page',
        'POST /v1/mock/paymongo/sessions/{id}/attempts' => 'Demo mode only: try a PayMongo test card or a test e-wallet answer; the outcome is sent as a signed webhook',
    ];
    $q = fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    $items = [];
    foreach ($routes as $r) {
        [$method, $path, , , $action, $mode] = $r;
        $roles = $action === null ? [] : $allow[$action];
        $who = $mode === 'public' ? 'anyone (rate limited)' : ($mode === 'webhook' ? 'the payment provider (signature checked)' : implode(', ', $roles));
        $items[$path][$method] = [$text["{$method} {$path}"] ?? throw new RuntimeException("no summary for {$method} {$path}"), $who, $mode, $action];
    }
    foreach ($own as [$method, $path, $summary, $who]) {
        $items[$path][$method] = [$summary, $who, in_array($path, ['/v1/register', '/v1/login', '/v1/password-reset', '/v1/password-reset/confirm'], true) ? 'public' : 'session', null];
    }
    ksort($items);

    $y = <<<'YAML'
openapi: 3.0.3
info:
  title: MA Hotel - Portal API
  version: 2.0.0
  description: |
    The public JSON API of the portal. The website uses the same services; this API is for integrations and tests.
    GENERATED by scripts/gen-openapi.php from the code (routes and roles). Do not edit by hand.

    - Money is integer centavos (PHP 2,500.00 = 250000), currency PHP. Stay dates are Asia/Manila calendar dates (YYYY-MM-DD). Times are UTC.
    - A hold reserves every night from check-in up to, but excluding, check-out, or none of them, for 10 minutes. A booking is confirmed only
      after a verified payment or a manager-approved pay-at-hotel guarantee. A browser "success" page never confirms a booking.
    - Writes need the session cookie and an X-CSRF-Token header. Creating a hold and starting a payment also need an Idempotency-Key.
    - Staff sign in with a second factor. Every role is limited to the actions listed under each operation. Guests only reach their own records (others return 404).
    - Errors always look like {"error":{"code":"...","message":"..."}}.
servers:
  - url: http://localhost:8080
tags:
  - { name: Booking, description: Search, holds, cancellation, guests, room types }
  - { name: Front desk, description: Check-in and out, rooms, arrivals, no-shows }
  - { name: Housekeeping, description: Cleaning, inspection, maintenance }
  - { name: Finance, description: Payments, refunds, exports, bills }
  - { name: Channel, description: Travel partner allotments and CSV import }
  - { name: Reports, description: Occupancy, revenue, operations }
  - { name: Account, description: Sign-in, registration, password reset }
paths:

YAML;
    $tag = function (string $p): string {
        return match (true) {
            str_contains($p, '/folios') || str_contains($p, '/refunds') || str_contains($p, '/payments') || str_contains($p, '/exports') || str_contains($p, 'webhooks') || str_contains($p, '/mock/') => 'Finance',
            str_contains($p, '/ota/') => 'Channel',
            str_contains($p, '/reports') => 'Reports',
            str_contains($p, '/cleaning') || str_contains($p, '/maintenance') => 'Housekeeping',
            str_contains($p, '/stays') || str_contains($p, '/rooms') && !str_contains($p, 'room-types') || str_contains($p, '/arrivals') || str_contains($p, '/arrival-overrides') || str_contains($p, 'no-show') => 'Front desk',
            in_array($p, ['/v1/register', '/v1/login', '/v1/logout', '/v1/me', '/v1/password-reset', '/v1/password-reset/confirm'], true) => 'Account',
            default => 'Booking',
        };
    };
    foreach ($items as $path => $ops) {
        $y .= "  {$path}:\n";
        $params = [];
        preg_match_all('/\{(\w+)\}/', $path, $m);
        foreach ($m[1] as $p) {
            $params[] = "        - { name: {$p}, in: path, required: true, schema: { type: string } }";
        }
        foreach ($ops as $method => [$summary, $who, $mode, $action]) {
            $lower = strtolower($method);
            $opId = $lower . implode('', array_map('ucfirst', preg_split('/[^A-Za-z0-9]+/', str_replace('/v1/', '', $path), -1, PREG_SPLIT_NO_EMPTY)));
            $write = $method !== 'GET';
            $y .= "    {$lower}:\n      operationId: {$opId}\n      tags: [" . $tag($path) . "]\n      summary: " . $q($summary) . "\n";
            $y .= '      description: ' . $q('Who: ' . $who . ($write && $mode === 'session' ? '. Needs X-CSRF-Token.' : '.')) . "\n";
            $y .= $mode === 'session' ? "      security:\n        - sessionCookie: []\n" : "      security: []\n";
            $hdr = [];
            foreach ($params as $p) {
                $hdr[] = $p;
            }
            if ($write && $mode === 'session') {
                $hdr[] = '        - { name: X-CSRF-Token, in: header, required: true, schema: { type: string } }';
            }
            if (in_array("{$method} {$path}", ['POST /v1/reservations', 'POST /v1/reservations/{id}/checkout'], true)) {
                $hdr[] = '        - { name: Idempotency-Key, in: header, required: true, schema: { type: string, maxLength: 128 } }';
            }
            if ($method === 'GET' && in_array($path, ['/v1/availability'], true)) {
                $hdr[] = '        - { name: checkIn, in: query, required: true, schema: { type: string, format: date } }';
                $hdr[] = '        - { name: checkOut, in: query, required: true, schema: { type: string, format: date } }';
                $hdr[] = '        - { name: quantity, in: query, schema: { type: integer, minimum: 1, maximum: 5, default: 1 } }';
                $hdr[] = '        - { name: adults, in: query, schema: { type: integer, minimum: 1, default: 1 } }';
            }
            if ($hdr) {
                $y .= "      parameters:\n" . implode("\n", $hdr) . "\n";
            }
            if ($write) {
                $body = match ("{$method} {$path}") {
                    'POST /v1/reservations' => '#/components/schemas/CreateReservationRequest',
                    default => null,
                };
                $y .= $body ? "      requestBody:\n        required: true\n        content:\n          application/json:\n            schema: { \$ref: '{$body}' }\n" : "      requestBody:\n        content:\n          application/json:\n            schema: { type: object }\n";
            }
            $ok = match ("{$method} {$path}") {
                'GET /v1/availability' => "'200': { description: One option per room type; quoteId is null when it is sold out or too small, content: { application/json: { schema: { \$ref: '#/components/schemas/AvailabilityResult' } } } }",
                'POST /v1/reservations' => "'201': { description: Hold created (status HELD). A replay of the same request returns the same body with Idempotent-Replayed true, content: { application/json: { schema: { \$ref: '#/components/schemas/ReservationHold' } } } }",
                'POST /v1/register' => "'201': { description: Account created }",
                'POST /v1/password-reset' => "'202': { description: Accepted whether or not the email has an account }",
                'POST /v1/webhooks/paymongo' => "'200': { description: Acknowledged after the payment is saved. result is captured, duplicate, review or ignored }",
                'POST /v1/stays/check-in', 'POST /v1/refunds', 'POST /v1/guests', 'POST /v1/ota/allotments', 'POST /v1/arrival-overrides', 'POST /v1/exports', 'POST /v1/folios/{id}/charges', 'POST /v1/reservations/{id}/checkout', 'POST /v1/reservations/{id}/onsite-payment' => "'201': { description: Created }",
                default => "'200': { description: OK }",
            };
            $y .= "      responses:\n        {$ok}\n";
            $y .= "        '401': { \$ref: '#/components/responses/Error' }\n        '403': { \$ref: '#/components/responses/Error' }\n";
            $y .= "        '409': { \$ref: '#/components/responses/Error' }\n        '422': { \$ref: '#/components/responses/Error' }\n";
            $y .= "        '429': { \$ref: '#/components/responses/Error' }\n";
        }
    }
    $y .= <<<'YAML'
components:
  securitySchemes:
    sessionCookie:
      type: apiKey
      in: cookie
      name: HLSESSID
      description: Secure, HttpOnly, SameSite session cookie from POST /v1/login.
  responses:
    Error:
      description: "Error. 401 not signed in, 403 role or CSRF refused, 404 not found or not yours, 409 conflict or illegal state, 422 invalid input, 429 too many requests, 503 a service is unavailable."
      content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } }
  schemas:
    Error:
      type: object
      required: [error]
      properties:
        error:
          type: object
          required: [code, message]
          properties:
            code: { type: string, example: INVENTORY_CONFLICT }
            message: { type: string }
            details: { type: object }
    AvailabilityResult:
      type: object
      properties:
        checkIn: { type: string, format: date }
        checkOut: { type: string, format: date }
        nights: { type: integer }
        quantity: { type: integer }
        adults: { type: integer }
        options:
          type: array
          items:
            type: object
            properties:
              roomTypeId: { type: string, format: uuid }
              name: { type: string, example: Standard }
              slug: { type: string, example: standard }
              available: { type: integer }
              fits: { type: boolean, description: Whether the guests fit in the requested rooms. }
              nightlyMinor: { type: integer, example: 380000 }
              totalMinor: { type: integer, example: 760000 }
              currency: { type: string, example: PHP }
              maxGuests: { type: integer }
              amenities: { type: array, items: { type: string } }
              quoteId: { type: string, nullable: true, description: Signed quote, valid 15 minutes. }
    CreateReservationRequest:
      type: object
      required: [quoteId, roomTypeId, checkIn, checkOut, quantity]
      description: roomTypeId, checkIn, checkOut and quantity must equal what the quote was issued for. Guests book for themselves (their account supplies the contact details).
      properties:
        quoteId: { type: string }
        roomTypeId: { type: string, format: uuid }
        checkIn: { type: string, format: date, example: '2026-12-01' }
        checkOut: { type: string, format: date, example: '2026-12-03' }
        quantity: { type: integer, minimum: 1, maximum: 5 }
        adults: { type: integer, minimum: 1 }
        specialRequests: { type: string, maxLength: 300 }
        onBehalfOfGuestId: { type: string, format: uuid, description: Reception only (walk-in). }
    ReservationHold:
      type: object
      required: [reservationId, status, holdExpiresAt, amountMinor, currency]
      properties:
        reservationId: { type: string, format: uuid }
        code: { type: string, example: HL7K3Q9X }
        status: { type: string, enum: [HELD] }
        holdExpiresAt: { type: string, format: date-time, description: UTC. }
        amountMinor: { type: integer, example: 760000 }
        currency: { type: string, enum: [PHP] }
        roomTypeId: { type: string, format: uuid }
        checkIn: { type: string, format: date }
        checkOut: { type: string, format: date }
        quantity: { type: integer }

YAML;
    return $y;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $file = dirname(__DIR__) . '/docs/openapi.yaml';
    $new = buildOpenApi();
    if (in_array('--check', $argv, true)) {
        if ((string) @file_get_contents($file) !== $new) {
            fwrite(STDERR, "docs/openapi.yaml is out of date. Run: php scripts/gen-openapi.php\n");
            exit(1);
        }
        echo "docs/openapi.yaml is up to date\n";
        exit(0);
    }
    file_put_contents($file, $new);
    echo 'wrote docs/openapi.yaml (' . substr_count($new, "\n") . " lines)\n";
}
