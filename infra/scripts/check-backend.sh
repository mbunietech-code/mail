#!/usr/bin/env bash
# MbunieMail backend quality gate (TEST_PLAN §2, §9). Run from anywhere:
#   infra/scripts/check-backend.sh
# Requires the dev dependencies from infra/docker-compose.dev.yml to be running.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/backend"

step() { printf '\n== %s\n' "$1"; }

step "Formatting (Pint, check only)"
./vendor/bin/pint --test

step "Static analysis (PHPStan/Larastan level 6)"
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

step "Test suite"
: > storage/logs/laravel.log
php artisan test

step "SEC-T25: no test canary secrets in logs"
CANARIES='Correct-Horse-Battery-9|hunter2|Secret-Attempt-123|Brand-New-Passphrase-42|Another-Strong-Phrase-81|Welcome-Aboard-2026|Mailbox-Pass-For-Test-77|New-Mailbox-Pass-1|ya29\.test-access|ya29\.refreshed|1//test-refresh|test-client-secret|mbr_[A-Za-z0-9]{20,}|mbm_[A-Za-z0-9]{20,}'
GM_PW="$(grep -E '^GREENMAIL_PASSWORD=' .env 2>/dev/null | cut -d= -f2 || true)"
[ -n "$GM_PW" ] && CANARIES="$CANARIES|$GM_PW"
if grep -rEq "$CANARIES" storage/logs/; then
  echo "FAIL: secret canary found in logs"; grep -rEn "$CANARIES" storage/logs/ | cut -c1-200; exit 1
fi
echo "ok"

step "Dependency audit (composer)"
composer audit --no-interaction

step "Secret scan (gitleaks) over files git would commit (ignored files such as .env excluded)"
SCAN_DIR="$(mktemp -d)"
trap 'rm -rf "$SCAN_DIR"' EXIT
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | xargs -0 -I{} cp --parents {} "$SCAN_DIR"/ 2>/dev/null || true)
if command -v gitleaks >/dev/null; then
  gitleaks dir "$SCAN_DIR" --no-banner --redact
elif command -v docker >/dev/null; then
  docker run --rm -v "$SCAN_DIR:/repo:ro" zricethezav/gitleaks:v8.28.0 dir /repo --no-banner --redact
else
  echo "SKIPPED: gitleaks not available"
fi

step "Secret scan (gitleaks) over the whole git history"
if command -v gitleaks >/dev/null; then
  gitleaks git "$ROOT" --no-banner --redact
elif command -v docker >/dev/null; then
  docker run --rm -v "$ROOT:/repo:ro" zricethezav/gitleaks:v8.28.0 git /repo --no-banner --redact
else
  echo "SKIPPED: gitleaks not available"
fi

printf '\nAll backend checks passed.\n'
