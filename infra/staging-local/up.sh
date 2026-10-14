#!/usr/bin/env bash
# Builds the production-like local stack. Secrets are copied from the dev env files into
# infra/staging-local/generated/ (gitignored) and never printed.
set -euo pipefail
cd "$(dirname "$0")"
umask 077
mkdir -p generated/tls
ROOT=../..
# Production settings on top of the dev values (DB/Redis reached over the docker network).
sed -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
    -e 's#^APP_URL=.*#APP_URL=https://localhost:8443#' -e 's/^DB_HOST=.*/DB_HOST=mariadb/' -e 's/^DB_PORT=.*/DB_PORT=3306/' \
    -e 's/^REDIS_HOST=.*/REDIS_HOST=redis/' -e 's/^REDIS_PORT=.*/REDIS_PORT=6379/' -e 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/' \
    -e 's/^TRUSTED_PROXIES=.*/TRUSTED_PROXIES=127.0.0.1/' -e 's/^LOG_LEVEL=.*/LOG_LEVEL=info/' \
    -e '/^MBUNIEMAIL_TEST_CA_FILE=/d' -e '/^MBUNIEMAIL_ALLOW_LOOPBACK_PROVIDERS=/d' -e '/^GREENMAIL_PASSWORD=/d' -e '/^CREDENTIAL_KEK_PATH=/d' \
    "$ROOT/backend/.env" > generated/.env
echo "CREDENTIAL_KEK_PATH=/etc/mbuniemail/keys/kek.json" >> generated/.env
cp "$(grep -E '^CREDENTIAL_KEK_PATH=' "$ROOT/backend/.env" | cut -d= -f2)" generated/kek.json
cp "$ROOT/infra/dev/tls/server.crt" generated/tls/fullchain.pem
cat "$ROOT/infra/dev/tls/ca.crt" >> generated/tls/fullchain.pem
cp "$ROOT/infra/dev/tls/server.key" generated/tls/privkey.pem
sed 's/API_HOST/localhost/g' "$ROOT/infra/nginx/mbuniemail.conf" > generated/site.conf
chmod 644 generated/tls/* generated/site.conf; chmod 600 generated/kek.json generated/.env
docker compose up -d --build 2>&1 | tail -3
