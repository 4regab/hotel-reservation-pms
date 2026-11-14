#!/usr/bin/env bash
# Runs every end-to-end test against a running stack (scripts/dev-stack.sh start, or the compose stack with the same env).
set -euo pipefail
cd "$(dirname "$0")/../.."
source scripts/dev-env.sh
for t in journey features concurrency payments; do
  echo "== $t"
  $PHP_BIN tests/e2e/$t.php
done
echo "all end-to-end tests passed"
