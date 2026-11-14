# Starts the whole site on Windows with PHP's built-in server. Needs PHP with pdo_sqlsrv and a SQL Server on 127.0.0.1:1433.
# Usage: powershell -File scripts/dev-stack.ps1 start|stop
#
# Why six PHP servers and not four: PHP's built-in server on Windows handles ONE request at a time (no workers).
# A test payment goes portal -> billing -> portal (webhook) -> billing. With one copy of each, the portal waits
# for billing while billing waits for the portal, and the guest sees a 503 after 10 seconds.
# So the service-to-service calls use two extra copies (portal-cb on 8104, billing-cb on 8105) that only
# receive those call-backs. Same code, same databases. For development and testing.
param([ValidateSet('start', 'stop')][string]$Action = 'start')
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
$run = Join-Path $env:TEMP 'harbor-run'
New-Item -ItemType Directory -Force $run | Out-Null

function Stop-Stack {
    Get-ChildItem $run -Filter *.pid -ErrorAction SilentlyContinue | ForEach-Object {
        Stop-Process -Id ([int](Get-Content $_.FullName)) -Force -ErrorAction SilentlyContinue
        Remove-Item $_.FullName
    }
}

if ($Action -eq 'stop') { Stop-Stack; return }
Stop-Stack

. (Join-Path $PSScriptRoot 'dev-env.ps1')

php scripts/migrate.php; if ($LASTEXITCODE) { throw 'migrate failed' }
php portal/bin/ensure-admin.php; if ($LASTEXITCODE) { throw 'ensure-admin failed' }
php scripts/seed-demo.php; if ($LASTEXITCODE) { throw 'seed-demo failed' }

function Start-Svc([string]$name, [string]$svc, [int]$port) {
    $env:SERVICE_NAME = $svc
    $p = Start-Process php -ArgumentList '-S', "127.0.0.1:$port", '-t', "$svc/public", "$svc/public/index.php" -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput "$run\$name.log" -RedirectStandardError "$run\$name.err.log"
    $p.Id | Set-Content "$run\$name.pid"
}
Start-Svc 'portal' 'portal' 8100
Start-Svc 'booking' 'booking' 8101
Start-Svc 'roomops' 'roomops' 8102
Start-Svc 'billing' 'billing' 8103
# Call-back copies. The portal copy forwards the payment webhook to the billing copy, not to the busy one.
$env:BILLING_URL = 'http://127.0.0.1:8105'
Start-Svc 'portal-cb' 'portal' 8104
$env:BILLING_URL = 'http://127.0.0.1:8103'
Start-Svc 'billing-cb' 'billing' 8105
Remove-Item env:SERVICE_NAME

$jobs = 'while ($true) { php booking/bin/expire-holds.php; foreach ($s in ''booking'',''roomops'',''billing'',''portal'') { if (Test-Path "$s/bin/dispatch.php") { php "$s/bin/dispatch.php" } }; php portal/bin/send-notifications.php; Start-Sleep 2 }'
$j = Start-Process powershell -ArgumentList '-NoProfile', '-Command', $jobs -WindowStyle Hidden -PassThru -RedirectStandardOutput "$run\jobs.log" -RedirectStandardError "$run\jobs.err.log"
$j.Id | Set-Content "$run\jobs.pid"

foreach ($port in 8100..8105) {
    $ok = $false
    for ($i = 0; $i -lt 50 -and -not $ok; $i++) {
        try { $ok = (Invoke-WebRequest "http://127.0.0.1:$port/healthz" -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200 } catch { Start-Sleep -Milliseconds 200 }
    }
    if (-not $ok) { throw "port $port did not come up, see $run" }
}
Write-Host 'stack up: http://127.0.0.1:8100'
