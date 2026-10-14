#!/usr/bin/env bash
# Restore an encrypted MbunieMail backup into a database (docs/BACKUP_AND_RESTORE.md).
#
#   restore.sh <backup.sql.gz.age> <target_db> <age_identity_file> [--allow-existing]
#
# Restores into a NEW database by default (refuses an existing one) so a drill or a
# mistaken command can never overwrite production. The age identity (private key) is
# brought from offline storage only for the restore and should be removed afterwards.
set -euo pipefail
umask 077

file="${1:?backup file}"; target="${2:?target database}"; identity="${3:?age identity file}"; flag="${4:-}"
MYSQL="${MYSQL:-mariadb}"
AGE="${AGE:-age}"

[[ "$target" =~ ^[A-Za-z0-9_]+$ ]] || { echo "invalid target database name" >&2; exit 1; }
[ -r "$file" ] && [ -r "$identity" ] || { echo "backup or identity not readable" >&2; exit 1; }

if [ -f "${file}.sha256" ]; then
  (cd "$(dirname "$file")" && sha256sum -c "$(basename "$file").sha256" >/dev/null) || { echo "CHECKSUM MISMATCH — do not restore" >&2; exit 1; }
  echo "checksum ok"
else
  echo "warning: no .sha256 next to the backup; integrity relies on age's authentication" >&2
fi

# shellcheck disable=SC2086
exists=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${target}'")
if [ "$exists" != "0" ] && [ "$flag" != "--allow-existing" ]; then
  echo "database ${target} exists; refusing (pass --allow-existing only for a deliberate overwrite)" >&2; exit 1
fi

# The dump contains `CREATE DATABASE <original>; USE <original>;` — rewrite to the target
# so restores never touch the original database.
# shellcheck disable=SC2086
$AGE -d -i "$identity" < "$file" \
  | gunzip \
  | sed -E "s/^(CREATE DATABASE( \/\*!32312 IF NOT EXISTS\*\/)?|USE) \`[A-Za-z0-9_]+\`/\1 \`${target}\`/" \
  | $MYSQL

# shellcheck disable=SC2086
tables=$($MYSQL -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${target}'")
# shellcheck disable=SC2086
users=$($MYSQL -N -B -e "SELECT COUNT(*) FROM \`${target}\`.users")
echo "restore ok: ${tables} tables, ${users} users in ${target}"
echo "next: verify the audit chain (mbuniemail:audit-verify) and decrypt a credential with the escrowed KEK (DR-T02)."
