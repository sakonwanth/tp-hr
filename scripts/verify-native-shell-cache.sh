#!/usr/bin/env bash
# Verifies every PHP loader of native-shell.css uses the same ?v= bump.
# Usage: from repo root → ./scripts/verify-native-shell-cache.sh
# Override: NATIVE_SHELL_CACHE=16 ./scripts/verify-native-shell-cache.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXPECT="${NATIVE_SHELL_CACHE:-16}"

die() { echo "$*" >&2; exit 1; }

bad=""
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  if [[ ! "$line" =~ native-shell\.css\?v=${EXPECT} ]]; then
    bad+="$line"$'\n'
  fi
done < <(grep -rn --include='*.php' 'native-shell\.css?v=' "$ROOT" 2>/dev/null || true)

if [[ -n "$bad" ]]; then
  echo -n "$bad" >&2
  die "native-shell.css: expected ?v=${EXPECT} on all loaders (see grep output above)."
fi

echo "OK — native-shell.css ?v=${EXPECT} — loader count: $(grep -r --include='*.php' 'native-shell\.css?v=' "$ROOT" 2>/dev/null | wc -l | tr -d ' ')"
