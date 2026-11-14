# Source this for local runs without Docker. Points at a MySQL on 127.0.0.1 (default port 3307).
# Demo values only. Usage: source scripts/dev-env.sh
export DB_ADMIN_HOST=127.0.0.1 DB_ADMIN_PORT=${DB_ADMIN_PORT:-3307} DB_ADMIN_USER=root DB_ADMIN_PASS=
export DB_APP_HOST_PATTERN=127.0.0.1
for s in BOOKING ROOMOPS BILLING PORTAL; do
  l=$(echo "$s" | tr 'A-Z' 'a-z')
  export ${s}_DB_HOST=127.0.0.1 ${s}_DB_PORT=$DB_ADMIN_PORT ${s}_DB_NAME=${l}_db ${s}_DB_USER=${l}_app ${s}_DB_PASS=dev-${l}-pass
done
export BOOKING_URL=http://127.0.0.1:8101 ROOMOPS_URL=http://127.0.0.1:8102 BILLING_URL=http://127.0.0.1:8103 PORTAL_URL=http://127.0.0.1:8100
# one secret per caller->callee pair (demo values)
for from in PORTAL BOOKING ROOMOPS BILLING; do for to in PORTAL BOOKING ROOMOPS BILLING; do
  [ "$from" != "$to" ] && export TOKEN_${from}_TO_${to}=dev-pair-$(echo "$from-$to" | tr 'A-Z' 'a-z')
done; done
export QUOTE_SECRET=dev-quote-secret PAYMONGO_MODE=mock PAYMONGO_WEBHOOK_SECRET=dev-webhook-secret
export PUBLIC_BASE_URL=http://127.0.0.1:8100 DEMO_PASSWORD=harborlight-demo DEMO_TOTP_SECRET=JBSWY3DPEHPK3PXP COOKIE_SECURE=0
export PHP_BIN=${PHP_BIN:-php}
