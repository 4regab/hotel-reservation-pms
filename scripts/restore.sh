#!/usr/bin/env bash
# Restores a backup made by scripts/backup.sh. This REPLACES the data in all four databases with the backup's data.
# Usage:  scripts/restore.sh backups/harborlight-YYYYmmdd-HHMMSS.tar.gz[.enc] --yes
# Docker: uses the mssql container.   Other SQL Server: SQLCMD="sqlcmd -S HOST,1433 -U sa -P PASS -C" SERVER_DIR=/path scripts/restore.sh FILE --yes
# After a restore: privacy deletions made since the backup are lost. Re-apply them (the privacy request list shows what was completed),
# then run the migrations once (docker compose run --rm migrate). That also reconnects each database user to its login.
set -euo pipefail
cd "$(dirname "$0")/.."
FILE=${1:-}
[ -f "$FILE" ] || { echo "usage: $0 BACKUP_FILE --yes" >&2; exit 2; }
[ "${2:-}" = "--yes" ] || { echo "This replaces the current data in all four databases. Re-run with --yes to confirm." >&2; exit 2; }
[ -f .env ] && set -a && . ./.env && set +a
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

case "$FILE" in
  *.enc) : "${BACKUP_PASSPHRASE:?set BACKUP_PASSPHRASE to open an encrypted backup}"
         openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE -in "$FILE" | tar -xzf - -C "$WORK" ;;
  *)     tar -xzf "$FILE" -C "$WORK" ;;
esac

if [ -n "${SQLCMD:-}" ]; then
  : "${SERVER_DIR:?set SERVER_DIR to a folder SQL Server can read}"
  SQL() { $SQLCMD -b -Q "$1"; }
  TARGET="$SERVER_DIR"
  PUT() { cp "$WORK/$1" "$SERVER_DIR/$1"; }
  DONE() { rm -f "$SERVER_DIR/$1"; }
else
  SQL() { docker compose exec -T mssql sh -c '/opt/mssql-tools18/bin/sqlcmd -S 127.0.0.1 -U sa -P "$MSSQL_SA_PASSWORD" -C -b -Q "$1"' sh "$1"; }
  docker compose exec -T mssql mkdir -p /var/opt/mssql/backup
  TARGET=/var/opt/mssql/backup
  PUT() { docker compose cp "$WORK/$1" "mssql:$TARGET/$1"; }
  DONE() { docker compose exec -T mssql rm -f "$TARGET/$1"; }
fi
for bak in "$WORK"/*.bak; do
  f=$(basename "$bak"); db=${f%.bak}
  PUT "$f"
  # Disconnect the site from this database, replace it, then let it back in.
  SQL "IF DB_ID(N'$db') IS NOT NULL ALTER DATABASE [$db] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
       RESTORE DATABASE [$db] FROM DISK = N'$TARGET/$f' WITH REPLACE, CHECKSUM;
       ALTER DATABASE [$db] SET MULTI_USER;"
  DONE "$f"
done
echo "restore finished from $FILE"
