#!/usr/bin/env bash
# Encrypted MariaDB backup (docs/BACKUP_AND_RESTORE.md). Runs nightly as the
# `mbuniemail-backup` system user via mbuniemail-backup.timer.
#
# - The dump is streamed: mariadb-dump | gzip | age  — plaintext never touches disk.
# - Encrypted to an age PUBLIC key; the private key is kept offline (two custodians).
# - The credential KEK (/etc/mbuniemail/keys/kek.json) is deliberately NOT included: a stolen
#   backup must not contain the key that decrypts the mailbox credentials inside it.
#
# Config: /etc/mbuniemail/backup.env (or environment), e.g.
#   DB_NAME=mbuniemail
#   BACKUP_DIR=/var/backups/mbuniemail
#   AGE_RECIPIENT=age1...            # public key
#   RETENTION_DAYS=30
#   OFFSITE_CMD='rclone copy "$1" offsite:mbuniemail-backups/'   # optional
# DB credentials come from the backup user's ~/.my.cnf ([client] user/password, 0600).
set -euo pipefail
umask 077

CONFIG="${BACKUP_CONFIG:-/etc/mbuniemail/backup.env}"
# shellcheck source=/dev/null
[ -f "$CONFIG" ] && . "$CONFIG"

DB_NAME="${DB_NAME:-mbuniemail}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mbuniemail}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
DUMP="${MYSQLDUMP:-mariadb-dump}"
AGE="${AGE:-age}"
: "${AGE_RECIPIENT:?AGE_RECIPIENT (age public key) is required}"

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "invalid DB_NAME" >&2; exit 1; }
[[ "$AGE_RECIPIENT" =~ ^age1[0-9a-z]+$ ]] || { echo "AGE_RECIPIENT is not an age public key" >&2; exit 1; }

mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="${BACKUP_DIR}/${DB_NAME}-${stamp}.sql.gz.age"
tmp="${out}.partial"
trap 'rm -f "$tmp"' EXIT

# shellcheck disable=SC2086  # $DUMP / $AGE may intentionally contain arguments (wrappers)
$DUMP --single-transaction --quick --routines --triggers --events --hex-blob \
      --default-character-set=utf8mb4 --databases "$DB_NAME" \
  | gzip -9 \
  | $AGE -r "$AGE_RECIPIENT" > "$tmp"

# An encrypted, compressed, non-trivial file — not an error message.
size=$(stat -c %s "$tmp")
[ "$size" -gt 1024 ] || { echo "backup suspiciously small (${size} bytes)" >&2; exit 1; }
head -c 22 "$tmp" | grep -q "age-encryption.org/v1" || { echo "output is not age-encrypted" >&2; exit 1; }

mv "$tmp" "$out"
sha256sum "$out" > "${out}.sha256"
trap - EXIT

if [ -n "${OFFSITE_CMD:-}" ]; then
  bash -c "$OFFSITE_CMD" _ "$out"
  bash -c "$OFFSITE_CMD" _ "${out}.sha256"
fi

find "$BACKUP_DIR" -maxdepth 1 -name "${DB_NAME}-*.sql.gz.age*" -type f -mtime "+${RETENTION_DAYS}" -delete

echo "backup ok: $(basename "$out") (${size} bytes)"
