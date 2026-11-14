#!/usr/bin/env bash
# Scheduled jobs in a loop (development, CI, and the compose "jobs" container). In production run
# the same commands from cron every minute, or keep this loop under a process supervisor.
cd "$(dirname "$0")/.."
PHP=${PHP_BIN:-php}
while true; do
  $PHP booking/bin/expire-holds.php
  for s in booking roomops billing portal; do [ -f "$s/bin/dispatch.php" ] && $PHP "$s/bin/dispatch.php"; done
  $PHP portal/bin/send-notifications.php
  sleep "${JOBS_INTERVAL:-2}"
done
