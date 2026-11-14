#!/usr/bin/env bash
# Run `php -l` on every PHP file. Exits non-zero (loudly) if any file has a syntax error.
set -u
cd "$(dirname "$0")/.."
fail=0
count=0
while IFS= read -r -d '' f; do
  count=$((count + 1))
  if ! out=$(php -l "$f" 2>&1); then
    echo "SYNTAX ERROR in $f"
    echo "$out"
    fail=1
  fi
done < <(find . -name '*.php' -not -path './.git/*' -print0)
echo "$count PHP files checked"
if [ "$fail" -ne 0 ]; then
  echo "LINT FAILED"
  exit 1
fi
echo "LINT OK"
