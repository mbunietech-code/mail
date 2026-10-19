#!/usr/bin/env bash
# Puts DB_PASSWORD and MAIL_PASSWORD into the backend .env on the Hostinger account.
# The passwords are typed here (not echoed, not in shell history or process lists)
# and sent over SSH on stdin. Leave one empty to keep its current value.
set -euo pipefail

HOST=${HOST:-u125644160@46.202.182.173}
PORT=${PORT:-65002}
KEY=${KEY:-$HOME/.ssh/mbuniemail_hostinger}

read -rsp "Database password (DB_PASSWORD): " db; echo
read -rsp "Mailbox password (MAIL_PASSWORD, Enter to skip): " mail; echo
for v in "$db" "$mail"; do
  if [[ "$v" == *"'"* || "$v" == *$'\n'* ]]; then
    echo "Passwords with ' or line breaks cannot go in .env; choose another." >&2
    exit 1
  fi
done

printf '%s\n%s\n' "$db" "$mail" | ssh -i "$KEY" -p "$PORT" -o IdentitiesOnly=yes "$HOST" \
  '/opt/alt/php84/usr/bin/php -r '\''
    $f = getenv("HOME")."/domains/mbunie.tech/public_html/email/backend/.env";
    $env = file_get_contents($f);
    foreach (["DB_PASSWORD", "MAIL_PASSWORD"] as $key) {
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($value === "") { echo "$key unchanged\n"; continue; }
        $env = preg_replace_callback("/^$key=.*$/m", fn () => "$key=\x27$value\x27", $env, 1);
        echo "$key set\n";
    }
    file_put_contents($f, $env);
    chmod($f, 0600);
  '\'''
