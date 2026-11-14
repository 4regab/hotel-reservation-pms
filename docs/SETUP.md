# Setup and settings

How to run MA Hotel on your Windows computer, the settings it reads, and notes for running it for real.
For the short version, see the [README](../README.md).

## 1. What you need

| Piece | Version | Why |
| --- | --- | --- |
| Git | any | To download the project. |
| PHP (command line) | 8.3 or newer, 64-bit | The code uses PHP 8.3 features. |
| PHP extension `pdo_sqlsrv` | a release that supports your PHP version | Every service talks to SQL Server through it. |
| PHP extensions `mbstring`, `curl` | bundled with PHP | `mbstring` is used for names and passwords. `curl` is used by the concurrency test, which sends 20 requests at once. |
| Microsoft ODBC Driver 18 for SQL Server | 18.x | `pdo_sqlsrv` cannot connect without it. |
| SQL Server | **2025** (Express or Developer is fine) | The tables use SQL Server 2025's native `JSON` column type, so older versions cannot run the migrations. |
| Node.js | 22, **only to change styles** | The compiled CSS (`src/portal/public/assets/app.css`) is committed, so you do not need Node to run the site. |

The other extensions the code uses (`json`, `openssl`, `pdo`, `session`) are part of every normal PHP build.

## 2. Install, once

1. Install PHP 8.3 or newer (64-bit) from windows.php.net and add it to `PATH`.
2. Install **Microsoft ODBC Driver 18 for SQL Server**.
3. Download **Microsoft Drivers for PHP for SQL Server**. Copy the `php_pdo_sqlsrv_<version>_nts_x64.dll` that matches your PHP into PHP's `ext` folder. Then add these lines to `php.ini`:
   ```ini
   extension=pdo_sqlsrv_<version>_nts_x64
   extension=mbstring
   extension=curl
   ```
   `php -m` should then list `pdo_sqlsrv`, `mbstring` and `curl`.
4. Install SQL Server 2025. In **SQL Server Configuration Manager**, turn on **TCP/IP** and make sure it listens on port 1433, then restart the SQL Server service.
5. In SQL Server Management Studio, set the server to **SQL Server and Windows Authentication mode** (Server properties, Security) and restart it. The services sign in with their own SQL logins, so Windows-only mode refuses them.

## 3. Start, open and stop

```powershell
powershell -File scripts/dev-stack.ps1 start
```

This one command:
1. runs `scripts/migrate.php`: creates the four databases (`portal_db`, `booking_db`, `roomops_db`, `billing_db`) and one limited login per service if missing, then applies every new migration once;
2. runs `src/portal/bin/ensure-admin.php`: creates the first IT account (`it@example.test`) if none exists;
3. runs `scripts/seed-demo.php`: loads the demo staff, guests and about 135 days of bookings (only if there are no bookings yet);
4. starts the site and the job loop (expires holds, delivers events, sends emails), then waits until every part answers `/healthz`.

The first step signs in to SQL Server with your **Windows account**. To use `sa` instead, set `$env:DB_ADMIN_USER = 'sa'` and `$env:DB_ADMIN_PASS = '...'` first. If SQL Server listens on another port, set `$env:DB_ADMIN_PORT`.

| Part | Address |
| --- | --- |
| Portal (the website, open this) | <http://127.0.0.1:8100> |
| Booking | `http://127.0.0.1:8101` |
| Room Operations | `http://127.0.0.1:8102` |
| Billing | `http://127.0.0.1:8103` |
| Portal and Billing copies that only receive payment call-backs | `8104`, `8105` |

Why six servers and not four: PHP's built-in server on Windows handles one request at a time. A test payment goes portal, billing, portal, billing. With one copy of each, the portal waits for billing while billing waits for the portal, and the guest sees a 503 after 10 seconds. The two extra copies take the call-backs, so the loop never waits on itself.

```powershell
powershell -File scripts/dev-stack.ps1 stop
```

Logs go to `%TEMP%\harbor-run` (`portal.err.log`, `booking.err.log`, `jobs.log` and so on).
Running `start` again is safe. It stops the old processes first, and the migrations and the seed only add what is missing.
`scripts/dev-env.ps1` holds every setting the local run uses: ports, throw-away secrets, `PAYMONGO_MODE=mock`, the demo password and the demo code key.

## 4. Sign in

Every demo account uses the password `ma-hotel-demo`. Staff also need a 6-digit code from the demo code key `JBSWY3DPEHPK3PXP`. The full list of accounts is in the [README](../README.md#test-accounts).
Add the key to an authenticator app, or print the current code (codes change every 30 seconds):

```powershell
php -r 'require "autoload.php"; echo Portal\Totp::code("JBSWY3DPEHPK3PXP", time()), "\n";'
```

Sixteen sample guests also have accounts (for example `maribel.dizon@example.com`) with the same password. All names, emails and phone numbers are invented.
Never use these passwords or this code key anywhere real.

Payments run in **mock mode** (see [Payment modes](#payment-modes)), so no PayMongo keys are needed.
No email is sent unless you set `$env:RESEND_API_KEY` before you start. Each email is saved in the `email_log` table of `portal_db` instead. Sign in as `it@example.test` and open **System health** to read them.

## 5. Run the tests

```powershell
php tests/unit/run.php                         # logic checks, no database needed
php scripts/check-boundaries.php                # no service uses another service's code
powershell -File scripts/dev-stack.ps1 start   # the end-to-end tests need the running site
powershell -File tests/e2e/run-all.ps1         # journey, features, concurrency, payments
```

The journey and features tests check real demo guests in and out, which uses up the ready rooms. If they fail with `NO_READY_ROOM`, finish some housekeeping tasks in the staff area, or reset the demo data (`$env:ALLOW_SEED_RESET = '1'; php scripts/seed-demo.php --reset`).
To rebuild the CSS after changing styles: `npm install` then `npm run build:css`.

## 6. Troubleshooting

| What you see | Cause and fix |
| --- | --- |
| `SQLSTATE[IMSSP]: This extension requires the Microsoft ODBC Driver for SQL Server` | `pdo_sqlsrv` is installed but ODBC Driver 18 is not. Install it (step 2). |
| `could not find driver` | The `pdo_sqlsrv` extension is not loaded. Check `php -m` and your `php.ini` (step 2). |
| `Login failed for user 'booking_app'` (or `portal_app`, `roomops_app`, `billing_app`) | SQL Server allows Windows sign-in only. Turn on **SQL Server and Windows Authentication mode** and restart SQL Server. Then run `start` again: the migrations reset the passwords of these logins. |
| `Login failed for user 'sa'` or a timeout on the first step | Wrong `DB_ADMIN_USER` / `DB_ADMIN_PASS`, SQL Server is not running, or TCP/IP is off or not on port 1433 (set `DB_ADMIN_PORT`). |
| A service does not answer, or `Address already in use` in a log | Another program is using a port from 8100 to 8105. Run `powershell -File scripts/dev-stack.ps1 stop`. Then find the program with `netstat -ano \| findstr :8100` and stop it. |
| A demo login says `Wrong email, password or code` | Staff need the 6-digit code (step 4). If the database was seeded under an older password, reset the demo data (see the tests section). |
| `Call to undefined function mb_strlen()` | The `mbstring` extension is missing (step 2). |
## Payment modes

Set `PAYMONGO_MODE` to choose how guests pay.

**`mock` (the default): a test checkout on the hotel site.**
No PayMongo account or keys are needed, and no money ever moves. The pay button opens a checkout page with a "Test mode" badge. It shows the booking, the amount, and three ways to pay:

- **Card.** A normal card form. It only accepts PayMongo's published test cards and declines any other number. Use any future expiry date and any 3-digit code.

  | Card number | What happens |
  | --- | --- |
  | `4343 4343 4343 4345`, `4571 7360 0000 0075`, `5123 0000 0000 0002`, `5123 0000 0000 0001` | Payment succeeds |
  | `4120 0000 0000 0007` | Asks you to authenticate, then you choose **Approve** or **Fail** |
  | `4200 0000 0000 0018` | Declined: expired card |
  | `4300 0000 0000 0017` | Declined: wrong security code |
  | `5100 0000 0000 0198` | Declined: insufficient funds |
  | `4111 1111 1111 1111` | Declined by the bank |

  Source: [PayMongo testing documentation](https://docs.paymongo.com/docs/payment-acceptance-testing). The same list is in a "Test cards" box on the checkout page.
- **GCash or Maya.** Enter any Philippine mobile number, then choose **Authorize test payment** or **Fail test payment**.

After a short "processing" screen, a successful payment goes to the confirmation page. A failed or cancelled one goes back to the payment page with a message, and the 10-minute hold keeps counting down.
Behind the scenes, the test checkout sends a signed PayMongo-style notice (`checkout_session.payment.paid` or `payment.failed`) to the site's real webhook address, `/v1/webhooks/paymongo`. So the booking is confirmed the same way as with real PayMongo: only when a correctly signed notice arrives.
Card numbers, expiry dates and security codes are never saved or written to a log. Only the last four digits travel on, inside the notice.

**`sandbox`: PayMongo's real test checkout.**
Set your PayMongo **test** keys before you start the site (`$env:PAYMONGO_SECRET_KEY`, which starts with `sk_test_`, and `$env:PAYMONGO_WEBHOOK_SECRET`) and `$env:PAYMONGO_MODE = 'sandbox'`. Guests are then sent to PayMongo's own hosted checkout page, where the same test cards work. In PayMongo's dashboard, point the webhook at `<PUBLIC_BASE_URL>/v1/webhooks/paymongo` for the `checkout_session.payment.paid` event. PayMongo must be able to reach that address, so this mode needs a public URL.

## Settings

The local run reads these from the environment. `scripts/dev-env.ps1` sets safe demo values. Set a variable in PowerShell before `start` to change it.

| Variable | Meaning |
| --- | --- |
| `DB_ADMIN_USER`, `DB_ADMIN_PASS`, `DB_ADMIN_PORT` | The one SQL Server login that creates the databases. Empty user means your Windows account. |
| `*_DB_PASS`, `*_DB_NAME`, `*_DB_USER` | One limited login per service (select, insert, update, delete; the audit table is append-only). |
| `TOKEN_<FROM>_TO_<TO>` | Twelve service-to-service secrets, one per caller and callee pair. |
| `QUOTE_SECRET` | Signs price quotes (Booking only). |
| `PUBLIC_BASE_URL`, `COOKIE_SECURE` | Public address, and whether the session cookie is HTTPS-only. |
| `PAYMONGO_MODE` | `mock` (the test checkout on this site) or `sandbox` (PayMongo's real test checkout). See [Payment modes](#payment-modes). |
| `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET` | From your PayMongo dashboard. Point the webhook at `<PUBLIC_BASE_URL>/v1/webhooks/paymongo` for the `checkout_session.payment.paid` event. |
| `RESEND_API_KEY`, `MAIL_FROM` | Email through Resend. With no key, emails are written to the log table instead (visible under System health). |
| `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD`, `ADMIN_TOTP_SECRET` | First IT account, created only if none exists. Empty password or secret means generated and printed once. |
| `DEMO_PASSWORD`, `DEMO_TOTP_SECRET` | The demo accounts' shared password and code key. |

## Running it for real

This project is set up for development and demos. PHP's built-in server is not meant for real visitors. For a real hotel:

- Run each service (`portal`, `booking`, `roomops`, `billing`) behind a real web server with PHP-FPM or FastCGI, over HTTPS. Set `COOKIE_SECURE=1` and `PUBLIC_BASE_URL=https://...`.
- Use strong, unique values for every secret and password, and skip the demo seed.
- Keep the service ports private. Only the portal faces the internet, and `/internal/` must not be reachable from outside.
- Run the job commands every minute (Windows Task Scheduler works): `php src/booking/bin/expire-holds.php`, each service's `bin/dispatch.php`, and `php src/portal/bin/send-notifications.php`.
- Payments: use `sandbox` first, then live keys. PayMongo shows card forms on its own page, so card numbers never touch this system.
- Run `php scripts/migrate.php` after every upgrade. It applies new migrations once and is safe to repeat.

### Backup and restore

Back up each database with SQL Server's own backup, from PowerShell (change the folder to one SQL Server can write to):

```powershell
foreach ($db in 'booking_db','roomops_db','billing_db','portal_db') {
  sqlcmd -S 127.0.0.1,1433 -E -C -b -Q "BACKUP DATABASE [$db] TO DISK = N'C:\backups\$db.bak' WITH COPY_ONLY, INIT, CHECKSUM"
}
```

To restore, close the site, then run `RESTORE DATABASE [name] FROM DISK = N'C:\backups\name.bak' WITH REPLACE, CHECKSUM` for each database, and run `php scripts/migrate.php` once so each database user is reconnected to its login. Privacy deletions made since the backup are lost: re-apply them from the privacy request list. Copy backups **off the machine**.