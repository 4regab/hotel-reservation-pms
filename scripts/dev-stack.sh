#!/usr/bin/env bash
# Run the four services and the job loop with the PHP built-in server (Linux CI only; on Windows use scripts/dev-stack.ps1). Needs a SQL Server reachable per scripts/dev-env.sh.
# Usage: scripts/dev-stack.sh start|stop
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
source scripts/dev-env.sh
RUN=${RUN_DIR:-${KIROCREW_SCRATCH:-/tmp}/harbor-run}
mkdir -p "$RUN"

stop() {
  for f in "$RUN"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
}

case "${1:-start}" in
  stop) stop ;;
  start)
    stop
    export PHP_CLI_SERVER_WORKERS=8
    $PHP_BIN scripts/migrate.php
    $PHP_BIN portal/bin/ensure-admin.php
    $PHP_BIN scripts/seed-demo.php
    for pair in portal:8100 booking:8101 roomops:8102 billing:8103; do
      svc=${pair%%:*}; port=${pair##*:}
      SERVICE_NAME=$svc nohup $PHP_BIN -S 127.0.0.1:"$port" -t "$svc/public" "$svc/public/index.php" > "$RUN/$svc.log" 2>&1 &
      echo $! > "$RUN/$svc.pid"
    done
    nohup bash scripts/jobs-loop.sh > "$RUN/jobs.log" 2>&1 &
    echo $! > "$RUN/jobs.pid"
    for port in 8100 8101 8102 8103; do
      for _ in $(seq 1 50); do curl -fs "http://127.0.0.1:$port/healthz" > /dev/null && break; sleep 0.2; done
    done
    echo "stack up: portal http://127.0.0.1:8100"
    ;;
esac
