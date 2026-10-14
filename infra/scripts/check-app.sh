#!/usr/bin/env bash
# MbunieMail Flutter app quality gate: formatting, static analysis, tests, and a
# check that no token storage bypasses the secure-storage abstraction (SEC-T28).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT/app"
export PATH="${FLUTTER_HOME:-$HOME/development/flutter}/bin:$PATH"

step() { printf '\n== %s\n' "$1"; }

step "Dependencies"
flutter pub get >/dev/null && echo ok

step "Format check"
dart format --output=none --set-exit-if-changed lib test

step "Static analysis"
flutter analyze

step "Tests"
flutter test

step "SEC-T28: tokens only through SecureTokenStore (no SharedPreferences / plain files)"
if grep -rnE "SharedPreferences|shared_preferences|path_provider|File\(.*(token|session)" lib; then
  echo "FAIL: possible insecure token storage"; exit 1
fi
echo ok

step "Release config refuses plain HTTP"
grep -q "kReleaseMode && uri.scheme != 'https'" lib/core/config.dart && grep -q 'usesCleartextTraffic="false"' android/app/src/main/AndroidManifest.xml && echo ok

printf '\nAll app checks passed.\n'
