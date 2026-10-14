#!/usr/bin/env bash
# Disaster-recovery drill against the LOCAL DEV containers (DR-T01 / DR-T02).
# Exercises the real backup.sh and restore.sh end to end:
#   seed data → encrypted backup → restore into a fresh database → verify
#   (row counts, audit hash chain, mailbox credential decrypts with the escrowed KEK)
#   plus negative checks (wrong key, tampered file, no secrets in the backup).
# Production drills follow docs/BACKUP_AND_RESTORE.md §5 on a staging host instead.
set -euo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"
set -a
# shellcheck source=/dev/null
. infra/.env
set +a

CONTAINER=mbuniemail-dev-mariadb-1
SRC_DB=mbm_drill
DST_DB=mbm_drill_restored
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"; root -e "DROP DATABASE IF EXISTS ${SRC_DB}; DROP DATABASE IF EXISTS ${DST_DB};" >/dev/null 2>&1 || true' EXIT

root() { docker exec -i -e MYSQL_PWD="$DEV_DB_ROOT_PASSWORD" "$CONTAINER" mariadb -uroot "$@"; }
step() { printf '\n== %s\n' "$1"; }

# Wrapper executables: secrets come from the environment, never from command strings.
cat > "$WORK/mysql" <<'W'
#!/usr/bin/env bash
exec docker exec -i -e MYSQL_PWD="$DEV_DB_ROOT_PASSWORD" mbuniemail-dev-mariadb-1 mariadb -uroot "$@"
W
cat > "$WORK/mysqldump" <<'W'
#!/usr/bin/env bash
exec docker exec -i -e MYSQL_PWD="$DEV_DB_ROOT_PASSWORD" mbuniemail-dev-mariadb-1 mariadb-dump -uroot "$@"
W
cat > "$WORK/age" <<W
#!/usr/bin/env bash
exec docker run --rm -i --user "$(id -u):$(id -g)" -v "$WORK:$WORK" mbm-age age "\$@"
W
chmod 700 "$WORK"/mysql "$WORK"/mysqldump "$WORK"/age
export DEV_DB_ROOT_PASSWORD

step "Preparing tools (age in a throwaway container)"
docker image inspect mbm-age >/dev/null 2>&1 || printf 'FROM alpine:3.20\nRUN apk add --no-cache age\n' | docker build -q -t mbm-age - >/dev/null
docker run --rm --user "$(id -u):$(id -g)" -v "$WORK:$WORK" mbm-age age-keygen -o "$WORK/identity.txt" 2>/dev/null
RECIPIENT=$(grep -o 'age1[0-9a-z]*' "$WORK/identity.txt" | head -1)
docker run --rm --user "$(id -u):$(id -g)" -v "$WORK:$WORK" mbm-age age-keygen -o "$WORK/wrong-identity.txt" 2>/dev/null
echo "ok"

step "Seeding ${SRC_DB} with realistic data"
root -e "DROP DATABASE IF EXISTS ${SRC_DB}; CREATE DATABASE ${SRC_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${SRC_DB}.* TO 'mbuniemail'@'%';"
CANARY="Drill-Mailbox-Password-$(openssl rand -hex 6)"
(cd backend && DB_DATABASE=$SRC_DB php artisan migrate --force -q && DB_DATABASE=$SRC_DB php artisan db:seed --force -q)
(cd backend && DRILL_CANARY="$CANARY" DB_DATABASE=$SRC_DB php artisan tinker --execute '
    $u = App\Models\User::factory()->create(["email" => "drill.user@mt.co.tz"]);
    $a = new App\Models\EmailAccount; $a->forceFill(["user_id" => $u->id, "provider_profile_id" => App\Models\ProviderProfile::query()->value("id"), "email_address" => "drill.user@mt.co.tz", "status" => "active"])->save();
    app(App\Services\Accounts\AccountService::class)->storeCredential($a, "password", ["password" => getenv("DRILL_CANARY")]);
    foreach (range(1, 25) as $i) { app(App\Services\Audit\AuditLogger::class)->record("drill.event", context: ["n" => $i]); }
    echo "seeded account ".$a->id;' 2>&1 | tail -1)
echo

step "DR backup with infra/scripts/backup.sh"
BACKUP_DIR="$WORK/backups" DB_NAME=$SRC_DB AGE_RECIPIENT="$RECIPIENT" BACKUP_CONFIG=/nonexistent \
  MYSQLDUMP="$WORK/mysqldump" AGE="$WORK/age" infra/scripts/backup.sh
FILE=$(ls "$WORK"/backups/*.sql.gz.age)

step "Backup contains no secrets"
if "$WORK/age" -d -i "$WORK/identity.txt" < "$FILE" | gunzip | grep -q "$CANARY"; then echo "FAIL: plaintext mailbox password in backup"; exit 1; fi
KEK_B64=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d["keys"][str(d["current"])])' "$(grep -E '^CREDENTIAL_KEK_PATH=' backend/.env | cut -d= -f2)")
if "$WORK/age" -d -i "$WORK/identity.txt" < "$FILE" | gunzip | grep -qF "$KEK_B64"; then echo "FAIL: KEK in backup"; exit 1; fi
echo "ok: no plaintext mailbox password and no KEK in the dump"

step "Negative checks"
if "$WORK/age" -d -i "$WORK/wrong-identity.txt" < "$FILE" >/dev/null 2>&1; then echo "FAIL: decrypted with the wrong key"; exit 1; fi
echo "ok: wrong private key cannot decrypt"
cp "$FILE" "$WORK/tampered.sql.gz.age"; cp "${FILE}.sha256" "$WORK/tampered.sql.gz.age.sha256"
sed -i "s#$(basename "$FILE")#tampered.sql.gz.age#" "$WORK/tampered.sql.gz.age.sha256"
printf 'X' | dd of="$WORK/tampered.sql.gz.age" bs=1 seek=200 conv=notrunc status=none
if MYSQL="$WORK/mysql" AGE="$WORK/age" infra/scripts/restore.sh "$WORK/tampered.sql.gz.age" "${DST_DB}_x" "$WORK/identity.txt" >/dev/null 2>&1; then echo "FAIL: tampered backup restored"; exit 1; fi
echo "ok: tampered backup refused"
if MYSQL="$WORK/mysql" AGE="$WORK/age" infra/scripts/restore.sh "$FILE" "$SRC_DB" "$WORK/identity.txt" >/dev/null 2>&1; then echo "FAIL: restore overwrote an existing database"; exit 1; fi
echo "ok: refuses to overwrite an existing database"

step "DR-T01 restore into ${DST_DB} (timed)"
start=$(date +%s)
MYSQL="$WORK/mysql" AGE="$WORK/age" infra/scripts/restore.sh "$FILE" "$DST_DB" "$WORK/identity.txt"
echo "restore took $(( $(date +%s) - start ))s (RTO target 4h)"

step "Verifying the restored data"
for t in users audit_logs encrypted_credentials email_accounts; do
  a=$(root -N -B -e "SELECT COUNT(*) FROM ${SRC_DB}.${t}"); b=$(root -N -B -e "SELECT COUNT(*) FROM ${DST_DB}.${t}")
  [ "$a" = "$b" ] || { echo "FAIL: ${t} ${a} != ${b}"; exit 1; }
  echo "ok: ${t} rows match (${b})"
done
root -e "GRANT ALL ON ${DST_DB}.* TO 'mbuniemail'@'%';"
(cd backend && DB_DATABASE=$DST_DB php artisan mbuniemail:audit-verify)
(cd backend && DRILL_CANARY="$CANARY" DB_DATABASE=$DST_DB php artisan tinker --execute '
    $c = App\Models\EncryptedCredential::query()->firstOrFail();
    $ok = app(App\Security\CredentialVault::class)->open($c)["password"]->reveal() === getenv("DRILL_CANARY");
    echo $ok ? "DR-T02 ok: restored mailbox credential decrypts with the escrowed KEK" : "DR-T02 FAIL";' 2>&1 | tail -1)
echo

step "Original database untouched"
root -N -B -e "SELECT CONCAT('ok: ', COUNT(*), ' audit rows still in ${SRC_DB}') FROM ${SRC_DB}.audit_logs"

printf '\nDR drill passed.\n'
