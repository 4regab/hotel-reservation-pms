# HarborLight Hotels: Digital Reservation & Property Management System

A proof of concept for a fictional 45-room hotel in Metro Manila, **HarborLight Hotels & Resorts**.
It replaces a desktop booking program and a spreadsheet with a small set of **plain PHP microservices**
(no framework, no Composer packages). Each service owns its own MySQL database and talks to the others
over private REST and durable events.

It is a proof of concept, not production software. See [What is simplified](#what-is-simplified).

## What it does

One journey works from end to end:

1. A guest **searches availability** and gets a signed price quote (no stock is used yet).
2. The guest **creates a 10-minute hold**. Stock for every night from check-in up to, but excluding, check-out is saved together or not at all.
3. The guest starts a **PayMongo checkout** (sandbox, or a built-in mock that needs no network or keys).
4. PayMongo calls the **signed webhook**. Billing verifies the signature, records the payment once, and queues `PaymentCaptured`.
5. Booking **confirms** only when stock is secured *and* the payment is verified (or a manager approves a pay-at-hotel guarantee). A browser "success" page can never confirm a booking.
6. Reception **checks the guest in**: confirmed reservation, verified identity, a ready room of the booked type, and payment or guarantee.
7. Reception **checks the guest out**: balance settled, bill closed, room marked dirty.
8. Housekeeping gets a **cleaning task**, cleans the room, and sets it **ready** again.

## Architecture

```
Browser ──HTTPS──> Portal (PHP gateway + pages)
                    │  sessions, CSRF, roles, rate limits, signed user context
        ┌───────────┼─────────────┬───────────────┐
        ▼           ▼             ▼               ▼
     Booking   Room Operations  Billing         Portal DB
     (own DB)     (own DB)      (own DB)     accounts, sessions,
                                              notification jobs,
                                              reporting copies

 Each service: outbox ──scheduled dispatcher──HTTPS──> consumer inbox (dedupe by eventId)
 Billing ──> PayMongo (hosted checkout)   PayMongo ──signed webhook──> Portal ──> Billing
```

| Part | Owns | Notes |
| --- | --- | --- |
| **Portal** (`portal/`) | Accounts, sessions, notification jobs, read-only reports | Renders pages, checks session, role and CSRF token, forwards to private services. Cannot sell a room or change a payment. |
| **Booking** (`booking/`) | Guest, RoomType, Reservation, InventoryNight, Allocation, OtaBooking | Dated room-night stock. One transaction saves reservation, allocations, counters and outbox event. |
| **Room Operations** (`roomops/`) | Room, Stay, RoomAssignment, CleaningTask, MaintenanceBlock | Locks the room row before saving a stay. Rejects overlapping assignments. |
| **Billing** (`billing/`) | Folio, Charge, Payment, Refund | Verifies the PayMongo signature, deduplicates by provider ids, never stores card data. |
| **Shared library** (`lib/`) | Nothing | Small helpers: router, PDO connection, outbox, inbox, idempotency, roles, stock arithmetic. |

How the rules are enforced:

- **Stock.** Counters per room type per night: `capacity`, `blocked`, `held`, `confirmed`, `ota_remaining`. The pure function `Lib\Inventory::reserve()` checks every night first and changes nothing if one night is short. The database also has `CHECK` constraints, so stock cannot go negative or above capacity. Booking locks the night rows in date order (`SELECT ... FOR UPDATE`) inside one PDO transaction.
- **Events.** A service writes the event to its `outbox` table in the same transaction as the data change. A scheduled PHP CLI job delivers it over authenticated HTTP to each consumer's `/internal/events`. The consumer writes the `eventId` to its `inbox` in the same transaction as its own update, then answers 2xx. Failed deliveries retry with growing delays and are flagged `REVIEW` after 5 attempts (never deleted).
- **No lock during a network call.** Calls to PayMongo and to other services happen outside database transactions.
- **Late payments.** Paid after the hold expired: try a fresh allocation, else keep it unconfirmed and file one refund request. Paid after cancellation: stay cancelled and file one refund request.
- **Privacy.** Events and logs carry no contact or card data (enforced by `Lib\Pii`). Only payment references are stored. Guests see only their own bookings (others return 404). Identity checks store the document *type* only.
- **Roles.** `guest`, `reception`, `housekeeping`, `maintenance`, `cashier`, `manager`, `it`, `steward`. The portal checks the role, and each service checks it again from a signed user context. Staff sign in with a second factor (6-digit TOTP code). A refund is approved by a manager who did not request it.
- **Money and time.** Integer centavos (PHP 2,500.00 is `250000`), currency `PHP`, UUID ids, event times in UTC, stay dates in `Asia/Manila`. Check-in 14:00, check-out 12:00.

Directory map:

```
portal/ booking/ roomops/ billing/   public/index.php (routes), src/, bin/ (scheduled jobs), migrations/ (SQL)
lib/src/                             shared helpers
tests/                               logic checks (php tests/run.php)
docs/openapi.yaml, docs/asyncapi.yaml  machine-readable contracts
docker-compose.yml, docker/          runs everything
```

## Run it

You need Docker with Compose.

```bash
cp .env.example .env        # optional: compose has the same demo defaults built in
docker compose up --build
```

Open <http://localhost:8080>. The first start creates the four databases, one limited MySQL user each
(data changes only), applies every service's migrations, seeds the **45 rooms** (20 Standard, 15 Deluxe, 10 Suite)
and the room types, and creates demo accounts.

Demo accounts (all use `DEMO_PASSWORD`, default `harborlight-demo`; demo use only):

| Account | Role | Second factor |
| --- | --- | --- |
| `guest@example.test` | guest | none |
| `reception@example.test` | reception | 6-digit code |
| `housekeeping@example.test` | housekeeping | 6-digit code |
| `maintenance@example.test` | maintenance | 6-digit code |
| `cashier@example.test` | cashier | 6-digit code |
| `manager@example.test` | manager | 6-digit code |
| `it@example.test`, `steward@example.test` | it, data steward | 6-digit code |

Get the staff code (secret `DEMO_TOTP_SECRET`, default `JBSWY3DPEHPK3PXP`; you can also add it to an authenticator app):

```bash
docker compose exec portal php -r 'require "autoload.php"; echo Portal\Totp::code(getenv("DEMO_TOTP_SECRET"), time()), "\n";'
```

### Walk through the core journey

1. Sign in as the guest. On **Find a room**, pick dates and press **Hold for 10 minutes**.
2. On the reservation page press **Pay with PayMongo (sandbox)**, then **Pay now (mock)**. The page shows `CONFIRMED` within a few seconds (the job runner delivers events every 5 seconds).
3. Sign in as `reception@example.test` with the code. On **Staff**, press **Arrivals**, copy the reservation id, and **Check in** with "identity verified" ticked.
4. Wait a few seconds (Billing opens the bill from the `StayCheckedIn` event), then **Check out**. If a balance is owed, tick "take balance now".
5. Sign in as `housekeeping@example.test`. Press **Cleaning tasks** and **Rooms**. Set the room to `CLEANING`, then `READY`, using the room's current `version`.

Pay-at-hotel path: as `manager@example.test`, use **Approve pay-at-hotel** on a `HELD` reservation. Check-in then works without a payment.

### Real PayMongo sandbox

Set in `.env`: `PAYMONGO_MODE=sandbox`, `PAYMONGO_SECRET_KEY` (a `sk_test_...` key), `PAYMONGO_WEBHOOK_SECRET` (the signing secret of a webhook you create in the PayMongo dashboard for the `checkout_session.payment.paid` event), and `PUBLIC_BASE_URL` (an address PayMongo can reach, such as a tunnel). Point the webhook at `<PUBLIC_BASE_URL>/v1/webhooks/paymongo`. Card entry happens on PayMongo's hosted page, so card numbers and CVV never touch this system.

### Scheduled jobs

| Job | What it does |
| --- | --- |
| `booking/bin/expire-holds.php` | Releases stock for holds older than 10 minutes, once. |
| `booking/bin/dispatch.php`, `roomops/bin/dispatch.php`, `billing/bin/dispatch.php` | Deliver committed outbox events and retry failures (review flag after 5 attempts). |
| `portal/bin/send-notifications.php` | Sends queued emails (a log line in this demo). A failed email never changes a reservation. |

Compose runs them in a loop every 5 seconds. In production run them from cron.

## API contracts

Public API (portal, `/v1`). Writes need a session cookie and an `X-CSRF-Token` header. `POST /v1/reservations` and checkout also need an `Idempotency-Key`.

| Operation | Who | Result |
| --- | --- | --- |
| `POST /v1/login`, `POST /v1/logout`, `GET /v1/me` | anyone / signed in | Session and CSRF token. |
| `GET /v1/availability` | public, rate limited | Options with a signed quote. Not reserved stock. |
| `POST /v1/reservations` | guest, reception | 201 `HELD`, 409 conflict, 422 invalid. |
| `GET /v1/reservations/{id}` | owner or staff | Others get 404. |
| `POST /v1/reservations/{id}/checkout` | owner or staff | 201 with a PayMongo checkout URL for the saved price. |
| `POST /v1/webhooks/paymongo` | PayMongo | Billing verifies the signature, commits, then acknowledges. |
| `POST /v1/reservations/{id}/cancel` | owner or staff | Follows the saved terms (free 48 hours before arrival, else first night is lost; a manager can waive it). |
| `POST /v1/reservations/{id}/approve-guarantee` | manager | Confirms a held reservation as pay-at-hotel. |
| `POST /v1/stays/check-in`, `POST /v1/stays/check-out` | reception | Rules above. |
| `PATCH /v1/rooms/{id}/status` | housekeeping, manager | Rejects stale versions and invalid transitions. |
| `GET /v1/arrivals`, `/v1/cleaning-tasks`, `/v1/rooms`, `/v1/reports/summary` | by role | Read views. |
| `POST /v1/maintenance-blocks` | maintenance, manager | Asks Booking for capacity first; conflicts return 409 for manager review. |
| `GET /v1/folios/{id}`, `POST /v1/folios/{id}/charges` | cashier, manager | Bill and extra charges. |
| `POST /v1/refunds`, `POST /v1/refunds/{id}/approve` | cashier requests, manager approves | Never your own request. |

Errors use one shape, `{"error":{"code":"...","message":"..."}}`, with status 401 (not signed in), 403 (role or CSRF), 404, 409, 422, 429 or 503.

Contract files:

- [`docs/openapi.yaml`](docs/openapi.yaml): OpenAPI 3.0.3 for the reservation hold API.
- [`docs/asyncapi.yaml`](docs/asyncapi.yaml): AsyncAPI 2.6.0 for the `ReservationConfirmed` event on channel `hotel.reservations.v1`.

Events (envelope: `eventId`, `schemaVersion`, `eventType`, `occurredAt`, `aggregateId`, `aggregateVersion`, `correlationId`, `data`):

| Event | From | To |
| --- | --- | --- |
| `ReservationConfirmed`, `ReservationCancelled` | Booking | Room Operations, Portal |
| `PaymentCaptured`, `RefundCompleted` | Billing | Booking, Portal |
| `RefundRequested` | Booking | Billing |
| `StayCheckedIn`, `StayCheckedOut` | Room Operations | Booking, Billing, Portal |
| `MaintenanceBlockApproved` | Room Operations | Portal |

## Quality targets

These come from the architecture brief. They are **test targets, not measured results**.

| Attribute | Target |
| --- | --- |
| Correctness | No negative room stock and no repeated payment effects. A competing request may be rejected rather than oversold. |
| Speed | At 20 simultaneous sessions and 2 booking commands per second, 95% of searches finish within 2 seconds and holds within 3 seconds. |
| Availability | 99% monthly booking API availability. Restore within 24 hours with at most 24 hours of data loss. |
| Privacy | Guests see only their own bookings. Staff roles are limited. Encrypted transport and backups. Contacts and card data stay out of logs and events. |
| Business | Oversold bookings under 0.2% of confirmed. Paid direct bookings confirmed within 2 minutes for 95%. Checkout-to-ready median 45 minutes or less. |

## Checks

```bash
bash scripts/lint.sh     # php -l on every PHP file, fails loudly
php tests/run.php        # logic checks, no database or network, exit code 1 on failure
```

The logic checks cover: all-or-nothing stock across nights and never exceeding capacity, idempotency-key replay and 409 on reuse,
reservation state transitions (legal and illegal), roles and refund approval, retry schedule, PayMongo signature and event parsing,
quote signing, cancellation terms, late-payment decisions, the staff TOTP code, and service-call forgery checks.
The same two commands run in GitHub Actions (`.github/workflows/ci.yml`).

The checks run without a database on purpose: the stock, state and idempotency rules are pure functions that the PDO code calls.
The SQL, the transactions and the container setup are **not** covered by these checks.

## What is simplified

Marked with `ponytail:` or `shortcut` comments in the code.

- One host and the PHP built-in server. Put nginx and php-fpm in front for real traffic.
- All containers receive all four service credentials. Give each service only the ones it needs.
- Two requests that use a brand-new Idempotency-Key at the same instant are separated by the table's primary key; one of them gets an error and retries.
- No-show marking, the OTA import screen (the tables and constraints exist), accounting export, privacy-request tooling and data-steward duplicate merge are not built.
- A maintenance block does not change a room's status; it blocks bookable capacity only.
- Emails are log lines. The demo mail job shows where SMTP goes.
- Check-in does not enforce the arrival date, so the demo can be run on any day.
- PayMongo webhook timestamps are not age-checked; replays are harmless because provider event and payment ids are unique.

## License

MIT. See [LICENSE](LICENSE).
