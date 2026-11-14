#!/usr/bin/env bash
# Restores a backup made by scripts/backup.sh. This REPLACES the data in all four databases with the backup's data.
# Usage:  scripts/restore.sh backups/harborlight-YYYYmmdd-HHMMSS.sql.gz[.enc] --yes
# Docker: uses `docker compose exec mysql mysql`.   Plain MySQL: MYSQL="mysql -h HOST -uUSER -pPASS" scripts/restore.sh FILE --yes
# After a restore: privacy deletions made since the backup are lost. Re-apply them (the privacy request list shows what was completed),
# then run the migrations once (docker compose run --rm migrate) in case the backup is older than the code.
set -euo pipefail
cd "$(dirname "$0")/.."
FILE=${1:-}
[ -f "$FILE" ] || { echo "usage: $0 BACKUP_FILE --yes" >&2; exit 2; }
[ "${2:-}" = "--yes" ] || { echo "This replaces the current data in all four databases. Re-run with --yes to confirm." >&2; exit 2; }
[ -f .env ] && set -a && . ./.env && set +a

if [ -n "${MYSQL:-}" ]; then
  CLIENT="$MYSQL"
else
  CLIENT='docker compose exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\""'
fi
case "$FILE" in
  *.enc) : "${BACKUP_PASSPHRASE:?set BACKUP_PASSPHRASE to open an encrypted backup}"
         openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE -in "$FILE" | gunzip | eval "$CLIENT" ;;
  *.gz)  gunzip -c "$FILE" | eval "$CLIENT" ;;
  *)     eval "$CLIENT" < "$FILE" ;;
esac
echo "restore finished from $FILE"
