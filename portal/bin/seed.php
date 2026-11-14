<?php
declare(strict_types=1);

// Creates the DEMO accounts (one per role) if they are missing. Safe to run on every start.
// Demo credentials only: set DEMO_PASSWORD and DEMO_TOTP_SECRET in .env, and never reuse them anywhere real.
require dirname(__DIR__, 2) . '/autoload.php';

$pdo = Lib\Db::connect('PORTAL');
$password = getenv('DEMO_PASSWORD') ?: 'harborlight-demo';
$totp = getenv('DEMO_TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP';
$accounts = [
    ['guest@example.test', 'guest', 'Demo Guest'],
    ['reception@example.test', 'reception', 'Demo Reception'],
    ['housekeeping@example.test', 'housekeeping', 'Demo Housekeeping'],
    ['maintenance@example.test', 'maintenance', 'Demo Maintenance'],
    ['cashier@example.test', 'cashier', 'Demo Cashier'],
    ['manager@example.test', 'manager', 'Demo Manager'],
    ['it@example.test', 'it', 'Demo IT Lead'],
    ['steward@example.test', 'steward', 'Demo Data Steward'],
];
$ins = $pdo->prepare('INSERT IGNORE INTO account (account_id, email, password_hash, role, full_name, phone, totp_secret, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
foreach ($accounts as [$email, $role, $name]) {
    $ins->execute([Lib\Uuid::v4(), $email, password_hash($password, PASSWORD_DEFAULT), $role, $name, '+63 900 000 0000', $role === 'guest' ? null : $totp]);
}
echo "demo accounts ready\n";
