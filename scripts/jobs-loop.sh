#!/usr/bin/env bash
# Scheduled jobs in a loop (development and CI). In production run
# the same commands from cron every minute, or keep this loop under a process supervisor.
cd "$(dirname "$0")/.."
PHP=${PHP_BIN:-php}
while true; do
  $PHP src/booking/bin/expire-holds.php
  for s in booking roomops billing portal; do [ -f "src/$s/bin/dispatch.php" ] && $PHP "src/$s/bin/dispatch.php"; done
  $PHP src/portal/bin/send-notifications.php
  sleep "${JOBS_INTERVAL:-2}"
done
