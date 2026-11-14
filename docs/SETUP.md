# Setup, settings and running MA Hotel

How to run the system with Docker or without it, the settings it reads, and notes for running it for real.
For a quick try, the [README](../README.md) has the short version.

## Run it with Docker

You need Docker with Compose.

```bash
cp .env.example .env     # then change every value that says change-me
docker compose up --build
```

Open <http://localhost:8080>. The first start creates the four databases and their limited accounts, applies the migrations,
creates a first IT account, and (when `SEED_DEMO=1`, the default in `.env.example`) loads about 135 days of invented bookings.
If you left `ADMIN_PASSWORD` empty, the generated first-admin password and authenticator secret are printed once:

```bash
docker compose logs migrate
```

For a real hotel set `SEED_DEMO=0`, `COOKIE_SECURE=1`, a real `PUBLIC_BASE_URL`, and strong secrets (`openssl rand -hex 24`).

### Demo accounts (demo data only)

With `SEED_DEMO=1` every account below uses the password `ma-hotel-demo` (`DEMO_PASSWORD`). Staff also need a 6-digit code.
The demo authenticator secret is `JBSWY3DPEHPK3PXP` (`DEMO_TOTP_SECRET`). Add it to an authenticator app, or print the current code:

```bash
docker compose exec portal php -r 'require "autoload.php"; echo Portal\Totp::code(getenv("DEMO_TOTP_SECRET") ?: "JBSWY3DPEHPK3PXP", time()), "\n";'
```

| Email | Role | Try this |
| --- | --- | --- |
| `guest@example.test` | guest | My bookings, privacy page |
| `reception@example.test`, `reception2@example.test` | reception | Front desk, check-in, walk-in |
| `housekeeping@example.test`, `housekeeping2@example.test` | housekeeping | Task board (one cleans, the other inspects) |
| `maintenance@example.test` | maintenance | Block a room |
| `cashier@example.test` | cashier | Payments, refund request, export |
| `manager@example.test`, `manager2@example.test` | manager | Approvals queue (the second manager can approve what the first asked for) |
| `steward@example.test` | data steward | Possible duplicate guests |
| `it@example.test` | IT / privacy lead | Users, audit log, health, privacy requests |

Sixteen sample guests also have accounts (for example `maribel.dizon@example.com`) with the same demo password. All names, emails and phone numbers are invented.
Never use these passwords or this authenticator secret anywhere real.


## Run locally (without Docker)

This runs the portal and the three services with PHP's built-in web server against a SQL Server on your machine.
It is for development and testing only. It is the same setup the CI end-to-end job uses.

### 1. What you need

| Piece | Version | Why |
| --- | --- | --- |
| PHP (command line) | 8.3 or newer | The code uses PHP 8.3 features. The Docker image uses PHP 8.3. |
| PHP extension `pdo_sqlsrv` | a release that supports your PHP version (the Docker image uses 5.13.3) | Every service talks to SQL Server through it. |
| PHP extension `mbstring` | bundled with PHP | Used for names and passwords. Without it, two logic checks fail with `Call to undefined function mb_strlen()`. |
| PHP extension `curl` | bundled with PHP | Only for the concurrency test, which sends 20 requests at once. |
| Microsoft ODBC Driver 18 for SQL Server | 18.x | `pdo_sqlsrv` cannot connect without it. |
| SQL Server | **2025** (Express or Developer edition is fine) | The tables use SQL Server 2025's native `JSON` column type, so older versions cannot run the migrations. |
| `bash` and `curl` | any | `scripts/dev-stack.sh` is a bash script and uses `curl` to wait for each service to answer. On Windows use Git Bash. |
| Node.js | 22, **only to change styles** | The compiled CSS (`portal/public/assets/app.css`) is committed, so you do not need Node to run the site. |

The other extensions the code uses (`json`, `openssl`, `pdo`, `session`) are part of every normal PHP build.

### 2. Install

**Ubuntu or Debian**

1. PHP and build tools for the driver (Ubuntu 24.04 ships PHP 8.3):
   ```bash
   sudo apt-get install -y php-cli php-mbstring php-curl php-dev php-pear unixodbc-dev curl
   ```
2. Microsoft ODBC Driver 18. These are the same steps the CI workflow runs:
   ```bash
   curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | sudo gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
   curl -fsSL "https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list" | sudo tee /etc/apt/sources.list.d/mssql-release.list
   sudo apt-get update
   sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18
   ```
   On Debian, use `config/debian/<version>/prod.list` instead. The Docker image does this for Debian 12.
3. The PHP driver, then turn it on for the command line:
   ```bash
   sudo pecl install pdo_sqlsrv
   echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/cli/conf.d/30-pdo_sqlsrv.ini
   php -m | grep -i sqlsrv        # should print pdo_sqlsrv
   ```
4. SQL Server 2025. Either install SQL Server 2025 for Linux by following Microsoft's guide, or run only the database in a container with the same image CI uses:
   ```bash
   docker run -d --name mssql -p 1433:1433 -e ACCEPT_EULA=Y -e MSSQL_PID=Express -e 'MSSQL_SA_PASSWORD=Choose-a-Strong-Passw0rd' mcr.microsoft.com/mssql/server:2025-latest
   ```

**Windows**

1. Install PHP 8.3 or newer (64-bit) from windows.php.net and add it to `PATH`.
2. Install **Microsoft ODBC Driver 18 for SQL Server**.
3. Download **Microsoft Drivers for PHP for SQL Server**. Copy the `php_pdo_sqlsrv_<version>_nts_x64.dll` that matches your PHP into PHP's `ext` folder. Then add these lines to `php.ini`:
   ```ini
   extension=pdo_sqlsrv_<version>_nts_x64
   extension=mbstring
   extension=curl
   ```
   `php -m` should then list `pdo_sqlsrv`, `mbstring` and `curl`.
4. Install SQL Server 2025 (Express or Developer). In **SQL Server Configuration Manager**, turn on **TCP/IP** and make sure it listens on port 1433, then restart the SQL Server service.
5. In SQL Server Management Studio, set the server to **SQL Server and Windows Authentication mode** (Server properties, Security) and restart it. The services sign in with their own SQL logins, so Windows-only mode refuses them.
6. Install **Git for Windows** and run every command below in **Git Bash**.

**macOS**

SQL Server has no macOS version. You have two options:
- Run only the database in Docker with the `docker run` command from the Ubuntu step 4. The rest runs natively.
- Use a SQL Server 2025 on another machine or in the cloud. `scripts/dev-env.sh` always connects to `127.0.0.1`, so forward port 1433 from that server to your Mac (for example with an SSH tunnel), or change the host values in your copy of `dev-env.sh`.

For PHP on a Mac, install PHP with Homebrew, `msodbcsql18` from Microsoft's Homebrew tap, and `pdo_sqlsrv` with `pecl`.

### 3. Database login and settings

You need one SQL Server login that can create databases and logins: `sa`, another admin login, or your own Windows account. The scripts use it once to create the four databases (`portal_db`, `booking_db`, `roomops_db`, `billing_db`) and one limited login per service (`portal_app` and so on).

```bash
export DB_ADMIN_USER=sa DB_ADMIN_PASS='Choose-a-Strong-Passw0rd'   # on Windows, leave both unset to sign in with your Windows account
export DB_ADMIN_PORT=1433                                          # only if SQL Server listens on another port
```

Everything else comes from `scripts/dev-env.sh`, which `dev-stack.sh` loads for you. It sets the service ports 8100 to 8103, throw-away secrets, `PAYMONGO_MODE=mock`, the demo password and the demo authenticator secret.
The local scripts do **not** read `.env`. `.env.example` and `.env` are for Docker Compose (and the backup scripts), so you do not need to copy them to run locally.

### 4. Start, open and stop

```bash
bash scripts/dev-stack.sh start
```

This one command:
1. runs `scripts/migrate.php`: creates the databases and logins if missing, then applies every new migration once;
2. runs `portal/bin/ensure-admin.php`: creates the first IT account (`it@example.test`) if none exists;
3. runs `scripts/seed-demo.php`: loads the demo staff, guests and about 135 days of bookings (only if there are no bookings yet);
4. starts the four services and the job loop (`scripts/jobs-loop.sh`, which expires holds, delivers events and sends emails), then waits until each answers `/healthz`.

| Part | Address |
| --- | --- |
| Portal (the website, open this) | <http://127.0.0.1:8100> |
| Booking | `http://127.0.0.1:8101` |
| Room Operations | `http://127.0.0.1:8102` |
| Billing | `http://127.0.0.1:8103` |

```bash
bash scripts/dev-stack.sh stop
```

Logs and process files go to the folder in `RUN_DIR`, by default `/tmp/harbor-run` (`portal.log`, `booking.log`, `roomops.log`, `billing.log`, `jobs.log`).
Running `start` again is safe. It stops the old processes first, and the migrations and the seed only add what is missing.

### 5. Sign in

Every demo account uses the password `ma-hotel-demo`. The seed also sets it on `it@example.test`, so ignore the generated password that `ensure-admin.php` prints on a first start. The accounts are listed in [Demo accounts](#demo-accounts-demo-data-only).
Staff also need the 6-digit code from the demo authenticator secret `JBSWY3DPEHPK3PXP`. Add it to an authenticator app, or print the current code:

```bash
php -r 'require "autoload.php"; echo Portal\Totp::code("JBSWY3DPEHPK3PXP", time()), "\n";'
```

Payments run in **mock mode** (see [Payment modes](#payment-modes)), so no PayMongo keys are needed.
No email is sent unless you export `RESEND_API_KEY`. Each email is saved in the `email_log` table of `portal_db` instead. Sign in as `it@example.test` and open **System health** to read them.

### 6. Run the tests

```bash
php tests/run.php                  # logic checks, no database needed
bash scripts/lint.sh               # php -l on every file
bash scripts/dev-stack.sh start    # the end-to-end tests need the running stack
bash tests/e2e/run-all.sh          # journey, features, concurrency, payments
```

To run one suite: `bash -c 'source scripts/dev-env.sh && php tests/e2e/journey.php'`.
To rebuild the CSS after changing styles: `npm install && npm run build:css`.

### 7. Troubleshooting

| What you see | Cause and fix |
| --- | --- |
| `SQLSTATE[IMSSP]: This extension requires the Microsoft ODBC Driver for SQL Server` | `pdo_sqlsrv` is installed but ODBC Driver 18 is not. Install `msodbcsql18` (step 2). `odbcinst -q -d` should list "ODBC Driver 18 for SQL Server". |
| `could not find driver` | The `pdo_sqlsrv` extension is not loaded. Check `php -m` and your `php.ini` (step 2). |
| `Login failed for user 'booking_app'` (or `portal_app`, `roomops_app`, `billing_app`) | SQL Server allows Windows sign-in only. Turn on **SQL Server and Windows Authentication mode** and restart SQL Server. Then run `start` again: the migrations reset the passwords of these logins. |
| `Login failed for user 'sa'` or a timeout on the first step | Wrong `DB_ADMIN_USER` / `DB_ADMIN_PASS`, SQL Server is not running, or TCP/IP is off or not on port 1433 (set `DB_ADMIN_PORT`). |
| A service does not answer, or `Address already in use` in a log in `/tmp/harbor-run` | Another program is using a port from 8100 to 8103. Run `bash scripts/dev-stack.sh stop`. Then find the program with `ss -ltnp \| grep 810` (Linux), `lsof -i :8100` (macOS) or `netstat -ano \| findstr :8100` (Windows) and stop it. |
| `Call to undefined function mb_strlen()` | The `mbstring` extension is missing (step 1). |


## Payment modes

Set `PAYMONGO_MODE` to choose how guests pay.

**`mock` (the default): a test checkout on the hotel site.**
No PayMongo account or keys are needed, and no money ever moves. The pay button opens a checkout page with a yellow "TEST MODE" banner. It shows the booking, the amount, and three ways to pay:

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
Put your PayMongo **test** keys in `.env` (`PAYMONGO_SECRET_KEY`, which starts with `sk_test_`, and `PAYMONGO_WEBHOOK_SECRET`). Guests are then sent to PayMongo's own hosted checkout page, where the same test cards work. In PayMongo's dashboard, point the webhook at `<PUBLIC_BASE_URL>/v1/webhooks/paymongo` for the `checkout_session.payment.paid` event. PayMongo must be able to reach that address, so this mode needs a public URL.

## Settings (`.env`, used by Docker Compose)

| Variable | Meaning |
| --- | --- |
| `MSSQL_SA_PASSWORD` | SQL Server `sa` password. Only the one-off `migrate` job and SQL Server itself get it. It needs 8+ characters with three of: upper case, lower case, digits, symbols. |
| `*_DB_PASS`, `*_DB_NAME`, `*_DB_USER` | One limited login per service (select, insert, update, delete; the audit table is append-only). |
| `TOKEN_<FROM>_TO_<TO>` | Twelve service-to-service secrets, one per caller and callee pair. |
| `QUOTE_SECRET` | Signs price quotes (Booking only). |
| `PUBLIC_BASE_URL`, `HTTP_PORT`, `COOKIE_SECURE` | Public address, published port, and whether the session cookie is HTTPS-only. |
| `PAYMONGO_MODE` | `mock` (the test checkout on this site) or `sandbox` (PayMongo's real test checkout). See [Payment modes](#payment-modes). |
| `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET` | From your PayMongo dashboard. Point the webhook at `<PUBLIC_BASE_URL>/v1/webhooks/paymongo` for the `checkout_session.payment.paid` event. |
| `RESEND_API_KEY`, `MAIL_FROM` | Email through Resend. With no key, emails are written to the log table instead (visible under System health). |
| `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD`, `ADMIN_TOTP_SECRET` | First IT account, created only if none exists. Empty password or secret means generated and printed once. |
| `SEED_DEMO`, `DEMO_PASSWORD`, `DEMO_TOTP_SECRET` | Demo data switch and the demo accounts' shared secrets. |


## Deployment notes

- Put a TLS terminator or load balancer in front of the `web` container and set `COOKIE_SECURE=1` and `PUBLIC_BASE_URL=https://...`. nginx trusts `X-Forwarded-For` from private address ranges so rate limits count real visitors.
- Only publish the `web` container's port 8080. The service ports 8001 to 8004 exist only on the compose network (`/internal/` is also blocked on the public port).
- Containers run as an unprivileged user with a read-only file system and no extra capabilities. The job containers (one small loop per service) can be replaced by cron every minute running the same commands.
- Payments: use `sandbox` first, then live keys. PayMongo shows card forms on its own page, so card numbers never touch this system.
- The compose file uses one SQL Server 2025 Express container, which needs at least 2 GB of memory. For production you can use a managed SQL Server instead and point `*_DB_HOST` at it (add the variables to the service environments). Express is licensed for production; the Developer and Evaluation editions are not.
- Run `docker compose run --rm migrate` after every upgrade. It applies new migrations once and is safe to repeat.

### Backup and restore

```bash
BACKUP_PASSPHRASE=choose-a-long-passphrase scripts/backup.sh          # backups/harborlight-YYYYmmdd-HHMMSS.tar.gz.enc
BACKUP_PASSPHRASE=choose-a-long-passphrase scripts/restore.sh backups/FILE.tar.gz.enc --yes
```

Backups are SQL Server backup files of all four databases (no site downtime, every page checked), packed, compressed and, with a passphrase, encrypted. Copy them **off the machine**. A restore replaces the data in all four
databases, so it needs `--yes`. After a restore, privacy deletions made since the backup are lost: re-apply them from the privacy request list. The scripts also accept
`SQLCMD=` and `SERVER_DIR=` to point at a SQL Server outside Docker (see the script headers). A backup and restore round trip was tested against SQL Server 2025 on Windows.

