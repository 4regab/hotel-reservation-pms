#!/usr/bin/env bash
# Backs up all four databases into one compressed file (and encrypts it when BACKUP_PASSPHRASE is set).
# Docker:        scripts/backup.sh                      (uses `docker compose exec mysql mysqldump`)
# Plain MySQL:   MYSQLDUMP="mysqldump -h HOST -uUSER -pPASS" scripts/backup.sh
# Output:        backups/harborlight-YYYYmmdd-HHMMSS.sql.gz[.enc]    (set BACKUP_DIR to change the folder)
# Keep a copy OFF this machine (object storage, another host). A backup on the same disk is not a backup.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && set -a && . ./.env && set +a
DIR=${BACKUP_DIR:-backups}
mkdir -p "$DIR"
STAMP=$(date -u +%Y%m%d-%H%M%S)
OUT="$DIR/harborlight-$STAMP.sql.gz"
DBS="${BOOKING_DB_NAME:-booking_db} ${ROOMOPS_DB_NAME:-roomops_db} ${BILLING_DB_NAME:-billing_db} ${PORTAL_DB_NAME:-portal_db}"

if [ -n "${MYSQLDUMP:-}" ]; then
  DUMP="$MYSQLDUMP"
else
  DUMP='docker compose exec -T mysql sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\""'
fi
# --single-transaction: a consistent copy without locking the site. --routines: keep any stored code. --databases: include CREATE DATABASE.
eval "$DUMP --single-transaction --routines --set-gtid-purged=OFF --databases $DBS" | gzip -9 > "$OUT"

if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
  openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_PASSPHRASE -in "$OUT" -out "$OUT.enc"
  rm -f "$OUT"
  OUT="$OUT.enc"
fi
# prune copies older than BACKUP_KEEP_DAYS (default 14)
find "$DIR" -name 'harborlight-*.sql.gz*' -mtime +"${BACKUP_KEEP_DAYS:-14}" -delete
echo "backup written: $OUT ($(du -h "$OUT" | cut -f1))"
