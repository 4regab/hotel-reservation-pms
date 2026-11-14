<?php
declare(strict_types=1);

// Makes sure one IT / privacy lead account exists, so a fresh install can sign in and create everyone else.
// Does nothing when an IT account already exists. Run by the migrate job on every start; safe to repeat.
//
// Env: ADMIN_EMAIL (required to create), ADMIN_NAME, ADMIN_PASSWORD (omit to generate one), ADMIN_TOTP_SECRET (omit to generate one).
// A generated password or secret is printed ONCE to the job log. Copy it, sign in, then change the password.
require dirname(__DIR__, 3) . '/autoload.php';

$name = getenv('PORTAL_DB_NAME');
// The admin sign-in is used whenever an admin connection is configured: DB_ADMIN_USER set, or DB_ADMIN_HOST set with an
// empty user (local development, where an empty user means Windows sign-in). Otherwise the portal's own login is used.
$admin = (string) getenv('DB_ADMIN_USER') !== '' || (string) getenv('DB_ADMIN_HOST') !== '';
$pdo = new PDO(Lib\Db::dsn(getenv('DB_ADMIN_HOST') ?: getenv('PORTAL_DB_HOST') ?: '127.0.0.1', getenv('DB_ADMIN_PORT') ?: getenv('PORTAL_DB_PORT') ?: '1433', (string) $name),
    (string) getenv($admin ? 'DB_ADMIN_USER' : 'PORTAL_DB_USER'), (string) getenv($admin ? 'DB_ADMIN_PASS' : 'PORTAL_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if ((int) $pdo->query("SELECT COUNT(*) FROM account WHERE role = 'it' AND active = 1")->fetchColumn() > 0) {
    echo "an IT account already exists\n";
    exit(0);
}
$email = strtolower(trim((string) getenv('ADMIN_EMAIL')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "no IT account yet, and ADMIN_EMAIL is not set: set ADMIN_EMAIL (and optionally ADMIN_NAME) and run again\n";
    exit(0);
}
$generated = [];
$password = (string) getenv('ADMIN_PASSWORD');
if ($password === '') {
    $password = Portal\Accounts::randomPassword();
    $generated[] = "password: {$password}";
}
$secret = (string) getenv('ADMIN_TOTP_SECRET');
if ($secret === '') {
    $secret = Portal\Accounts::randomBase32();
    $generated[] = "authenticator secret: {$secret}";
}
$id = Lib\Uuid::v4();
$pdo->prepare("INSERT INTO account (account_id, email, password_hash, role, full_name, phone, totp_secret, created_at) VALUES (?, ?, ?, 'it', ?, '', ?, DATETRUNC(SECOND, SYSUTCDATETIME()))")
    ->execute([$id, $email, password_hash($password, PASSWORD_DEFAULT), (string) (getenv('ADMIN_NAME') ?: 'Site administrator'), $secret]);
$pdo->prepare("INSERT INTO audit_log (at, actor_id, actor_role, action, target_type, target_id, detail, ip) VALUES (SYSUTCDATETIME(), 'system', 'it', 'user.created', 'account', ?, ?, NULL)")->execute([$id, json_encode(['role' => 'it', 'by' => 'ensure-admin'])]);
echo "created IT account {$email}\n";
foreach ($generated as $line) {
    echo "  {$line}   (shown once)\n";
}
if ($generated) {
    echo '  setup link: ' . Portal\Accounts::otpauth($email, $secret) . "\n";
}
