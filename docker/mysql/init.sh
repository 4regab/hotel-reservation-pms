#!/bin/bash
# Runs once, on the first start of an empty MySQL volume.
# Creates the four databases, one limited user per database (data changes only, no schema changes), and applies each service's migrations.
set -euo pipefail
root() { mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"; }
for svc in BOOKING ROOMOPS BILLING PORTAL; do
  name=$(printenv "${svc}_DB_NAME"); user=$(printenv "${svc}_DB_USER"); pass=$(printenv "${svc}_DB_PASS")
  root -e "CREATE DATABASE \`${name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
           CREATE USER '${user}'@'%' IDENTIFIED BY '${pass}';
           GRANT SELECT, INSERT, UPDATE, DELETE ON \`${name}\`.* TO '${user}'@'%';"
  for f in /migrations/$(echo "$svc" | tr 'A-Z' 'a-z')/*.sql; do
    echo "applying $f to $name"
    root "$name" < "$f"
  done
done
