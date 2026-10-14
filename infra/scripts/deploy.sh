#!/usr/bin/env bash
# Zero-downtime release on the VPS (docs/DEPLOYMENT.md §8). Run as the `deploy` user:
#   infra/scripts/deploy.sh <git-ref>
# Layout: /srv/mbuniemail/{repo.git,releases/<ts>,shared/{.env,storage},current -> releases/<ts>}
set -euo pipefail
umask 027

REF="${1:?git ref (tag or commit)}"
BASE="${BASE:-/srv/mbuniemail}"
KEEP="${KEEP_RELEASES:-5}"
[[ "$REF" =~ ^[A-Za-z0-9._/-]+$ ]] || { echo "invalid ref" >&2; exit 1; }

release="$BASE/releases/$(date -u +%Y%m%d%H%M%S)"
echo "→ preparing $release from $REF"
git --git-dir="$BASE/repo.git" fetch --tags --prune origin
mkdir -p "$release"
git --git-dir="$BASE/repo.git" archive "$REF" | tar -x -C "$release"

cd "$release/backend"
ln -sfn "$BASE/shared/.env" .env
rm -rf storage && ln -sfn "$BASE/shared/storage" storage

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-progress
composer audit --no-dev --no-interaction

# Production safety checks before switching traffic.
php artisan env | grep -q '\[production\]' || { echo "APP_ENV is not production" >&2; exit 1; }
grep -qE '^APP_DEBUG=false' .env || { echo "APP_DEBUG must be false" >&2; exit 1; }
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "→ backup before migrating"
if [ -e "$BASE/current" ]; then
  sudo -u mbuniemail-backup "$release/infra/scripts/backup.sh" || { echo "pre-deploy backup failed; aborting" >&2; exit 1; }
else
  echo "  first deploy: nothing to back up yet"
fi

echo "→ migrating (migrator account)"
DB_USERNAME="$(cat /etc/mbuniemail/migrate.user)" DB_PASSWORD="$(cat /etc/mbuniemail/migrate.pass)" php artisan migrate --force --no-interaction
sudo DB_NAME=mbuniemail MYSQL="mariadb" "$release/infra/scripts/db-grants.sh"

echo "→ switching"
ln -sfn "$release" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
sudo systemctl reload php8.4-fpm
php "$BASE/current/backend/artisan" queue:restart   # workers finish their job, then restart on the new code

echo "→ smoke test"
curl -fsS --max-time 10 "https://$(grep -E '^APP_URL=' .env | cut -d/ -f3)/up" >/dev/null && echo "health ok"

# Keep the last $KEEP releases for rollback.
find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | tail -n +$((KEEP + 1)) | cut -d' ' -f2- | xargs -r rm -rf --
echo "deployed $REF → $release"
