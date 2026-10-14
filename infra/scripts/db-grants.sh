#!/usr/bin/env bash
# Least-privilege MariaDB accounts for MbunieMail (risk R-22, R-24). Run as a DB admin
# after migrations, and again after any migration that adds tables:
#
#   DB_NAME=mbuniemail MYSQL="mariadb -uroot" infra/scripts/db-grants.sh
#
# Accounts (create them first with strong passwords; see docs/DEPLOYMENT.md §4):
#   mbuniemail_app      runtime (PHP-FPM, workers): DML on all tables, but only
#                       INSERT/SELECT on audit_logs (append-only at the DB level)
#   mbuniemail_migrate  schema changes during deploys only
#   mbuniemail_backup   read-only consistent dumps
#   mbuniemail_maint    audit retention purge (DELETE on audit_logs only)
set -euo pipefail

DB_NAME="${DB_NAME:-mbuniemail}"
MYSQL="${MYSQL:-mariadb}"
HOST="${DB_GRANT_HOST:-localhost}"

if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$HOST" =~ ^[A-Za-z0-9_.%-]+$ ]]; then
  echo "invalid DB_NAME or DB_GRANT_HOST" >&2; exit 1
fi

tables=$($MYSQL -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '${DB_NAME}' AND table_type = 'BASE TABLE' ORDER BY table_name")
[ -n "$tables" ] || { echo "no tables in ${DB_NAME}; run migrations first" >&2; exit 1; }

{
  echo "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'mbuniemail_app'@'${HOST}';"
  while IFS= read -r t; do
    case "$t" in
      audit_logs) echo "GRANT SELECT, INSERT ON \`${DB_NAME}\`.\`${t}\` TO 'mbuniemail_app'@'${HOST}';" ;;
      *)          echo "GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.\`${t}\` TO 'mbuniemail_app'@'${HOST}';" ;;
    esac
  done <<< "$tables"

  echo "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO 'mbuniemail_migrate'@'${HOST}';"
  echo "GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON \`${DB_NAME}\`.* TO 'mbuniemail_backup'@'${HOST}';"
  echo "GRANT SELECT, DELETE ON \`${DB_NAME}\`.\`audit_logs\` TO 'mbuniemail_maint'@'${HOST}';"
  echo "GRANT SELECT ON \`${DB_NAME}\`.\`audit_chain_head\` TO 'mbuniemail_maint'@'${HOST}';"
  echo "FLUSH PRIVILEGES;"
} | $MYSQL

echo "Grants applied for $(wc -l <<< "$tables") tables in ${DB_NAME}."
