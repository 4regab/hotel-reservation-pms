# Runs every end-to-end test against the running site (start it first: powershell -File scripts/dev-stack.ps1 start).
# Usage: powershell -File tests/e2e/run-all.ps1
Set-Location (Join-Path $PSScriptRoot '..\..')
. scripts/dev-env.ps1
foreach ($t in 'journey', 'features', 'concurrency', 'payments') {
    Write-Host "== $t"
    php "tests/e2e/$t.php"
    if ($LASTEXITCODE) { Write-Host "FAILED: $t"; exit 1 }
}
Write-Host 'all end-to-end tests passed'
