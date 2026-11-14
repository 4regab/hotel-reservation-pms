# Dot-source this to set the demo settings for a local run on Windows:  . scripts/dev-env.ps1
# Points at a SQL Server on 127.0.0.1 (port 1433 unless DB_ADMIN_PORT is set). Demo values only.
# An empty DB_ADMIN_USER signs in to SQL Server with your Windows account. To use `sa`, set DB_ADMIN_USER and DB_ADMIN_PASS first.
$env:DB_ADMIN_HOST = '127.0.0.1'
if (-not $env:DB_ADMIN_PORT) { $env:DB_ADMIN_PORT = '1433' }
if (-not $env:DB_ADMIN_USER) { $env:DB_ADMIN_USER = ''; $env:DB_ADMIN_PASS = '' }
foreach ($s in 'BOOKING', 'ROOMOPS', 'BILLING', 'PORTAL') {
    $l = $s.ToLower()
    Set-Item "env:${s}_DB_HOST" '127.0.0.1'; Set-Item "env:${s}_DB_PORT" $env:DB_ADMIN_PORT; Set-Item "env:${s}_DB_NAME" "${l}_db"
    Set-Item "env:${s}_DB_USER" "${l}_app"; Set-Item "env:${s}_DB_PASS" "dev-$l-pass"
}
# One secret per caller -> callee pair.
foreach ($f in 'PORTAL', 'BOOKING', 'ROOMOPS', 'BILLING') { foreach ($t in 'PORTAL', 'BOOKING', 'ROOMOPS', 'BILLING') {
    if ($f -ne $t) { Set-Item "env:TOKEN_${f}_TO_${t}" ("dev-pair-$f-$t".ToLower()) }
} }
$env:QUOTE_SECRET = 'dev-quote-secret'; $env:PAYMONGO_WEBHOOK_SECRET = 'dev-webhook-secret'
if (-not $env:PAYMONGO_MODE) { $env:PAYMONGO_MODE = 'mock' }
$env:PUBLIC_BASE_URL = 'http://127.0.0.1:8100'; $env:DEMO_PASSWORD = 'ma-hotel-demo'; $env:DEMO_TOTP_SECRET = 'JBSWY3DPEHPK3PXP'
$env:COOKIE_SECURE = '0'; $env:ADMIN_EMAIL = 'it@example.test'
# Service addresses. Portal 8100, Booking 8101, Room Operations 8102, Billing 8103.
# 8104 is a second Portal that only receives call-backs from Billing (see scripts/dev-stack.ps1 for why).
$env:BOOKING_URL = 'http://127.0.0.1:8101'; $env:ROOMOPS_URL = 'http://127.0.0.1:8102'; $env:BILLING_URL = 'http://127.0.0.1:8103'; $env:PORTAL_URL = 'http://127.0.0.1:8104'
