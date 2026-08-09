#!/usr/bin/env bash
# Verifies every PHP loader of native-shell.css uses the same ?v= bump.
# Usage: from repo root → ./scripts/verify-native-shell-cache.sh
# Override: NATIVE_SHELL_CACHE=16 ./scripts/verify-native-shell-cache.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXPECT="${NATIVE_SHELL_CACHE:-29}"

die() { echo "$*" >&2; exit 1; }

bad=""
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  if [[ ! "$line" =~ native-shell\.css\?v=${EXPECT} ]]; then
    bad+="$line"$'\n'
  fi
done < <(grep -rn --include='*.php' --exclude-dir=.claude --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=scripts --exclude-dir=tests 'native-shell\.css?v=' "$ROOT" 2>/dev/null || true)

if [[ -n "$bad" ]]; then
  echo -n "$bad" >&2
  die "native-shell.css: expected ?v=${EXPECT} on all loaders (see grep output above)."
fi

# app.css must carry the SAME version. It shipped without a cache-buster at
# all, so the service worker — which keys its cache on the full URL — kept
# serving a stale copy after each deploy while native-shell.css, having a
# ?v=, was refetched. A new shell paired with an old Tailwind build is what
# broke the desktop layout on 2026-08-09. Tying both files to one number
# means they can never drift apart again.
bad_app=""
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  if [[ ! "$line" =~ app\.css\?v=${EXPECT} ]]; then
    bad_app+="$line"$'\n'
  fi
done < <(grep -rn --include='*.php' --exclude-dir=.claude --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=scripts --exclude-dir=tests 'assets/css/app\.css' "$ROOT" 2>/dev/null || true)

if [[ -n "$bad_app" ]]; then
  echo -n "$bad_app" >&2
  die "app.css: expected ?v=${EXPECT} on all loaders — it must match native-shell.css."
fi

echo "OK — app.css + native-shell.css both ?v=${EXPECT} — loaders: $(grep -r --include='*.php' --exclude-dir=.claude --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=scripts --exclude-dir=tests -E 'assets/css/(app|native-shell)\.css\?v=' "$ROOT" 2>/dev/null | wc -l | tr -d ' ')"
