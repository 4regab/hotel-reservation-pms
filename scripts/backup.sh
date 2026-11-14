#!/usr/bin/env bash
# Backs up all four databases into one compressed file (and encrypts it when BACKUP_PASSPHRASE is set).
# SQL Server writes each backup (.bak) on its own disk; this script then copies the four files out and packs them.
# Docker:            scripts/backup.sh                    (uses the mssql container and its sa password from .env)
# Other SQL Server:  SQLCMD="sqlcmd -S HOST,1433 -U sa -P PASS -C" SERVER_DIR=/path scripts/backup.sh
#                    SERVER_DIR is a folder the SQL Server service can write to and this script can read (same machine or a share).
# Output:            backups/harborlight-YYYYmmdd-HHMMSS.tar.gz[.enc]    (set BACKUP_DIR to change the folder)
# Keep a copy OFF this machine (object storage, another host). A backup on the same disk is not a backup.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && set -a && . ./.env && set +a
DIR=${BACKUP_DIR:-backups}
mkdir -p "$DIR"
STAMP=$(date -u +%Y%m%d-%H%M%S)
OUT="$DIR/harborlight-$STAMP.tar.gz"
DBS="${BOOKING_DB_NAME:-booking_db} ${ROOMOPS_DB_NAME:-roomops_db} ${BILLING_DB_NAME:-billing_db} ${PORTAL_DB_NAME:-portal_db}"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

if [ -n "${SQLCMD:-}" ]; then
  : "${SERVER_DIR:?set SERVER_DIR to a folder SQL Server writes its backups to}"
  SQL() { $SQLCMD -b -Q "$1"; }
  TARGET="$SERVER_DIR"
  FETCH() { mv "$SERVER_DIR/$1" "$WORK/$1"; }
else
  SQL() { docker compose exec -T mssql sh -c '/opt/mssql-tools18/bin/sqlcmd -S 127.0.0.1 -U sa -P "$MSSQL_SA_PASSWORD" -C -b -Q "$1"' sh "$1"; }
  docker compose exec -T mssql mkdir -p /var/opt/mssql/backup
  TARGET=/var/opt/mssql/backup
  FETCH() { docker compose cp "mssql:$TARGET/$1" "$WORK/$1" && docker compose exec -T mssql rm -f "$TARGET/$1"; }
fi
# COPY_ONLY: does not disturb any other backup schedule. CHECKSUM: the server verifies every page as it reads it.
for db in $DBS; do
  SQL "BACKUP DATABASE [$db] TO DISK = N'$TARGET/$db.bak' WITH COPY_ONLY, INIT, CHECKSUM"
  FETCH "$db.bak"
done
tar -czf "$OUT" -C "$WORK" .

if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
  openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_PASSPHRASE -in "$OUT" -out "$OUT.enc"
  rm -f "$OUT"
  OUT="$OUT.enc"
fi
# prune copies older than BACKUP_KEEP_DAYS (default 14)
find "$DIR" -name 'harborlight-*.tar.gz*' -mtime +"${BACKUP_KEEP_DAYS:-14}" -delete
echo "backup written: $OUT ($(du -h "$OUT" | cut -f1))"
