#!/usr/bin/env bash
# Fails when Tailwind min-h/min-w utility classes below 48px appear in UI PHP templates.
# Scope: app root pages, /hr pages, /templates, /modules.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

PATTERN='min-(h|w)-\[(?:[0-3]?[0-9]|4[0-7])px\]'

bad="$(
  rg -n --pcre2 "$PATTERN" \
    -g '*.php' \
    "$ROOT" \
    "$ROOT/hr" \
    "$ROOT/modules" \
    "$ROOT/templates" \
    2>/dev/null || true
)"

if [[ -n "${bad}" ]]; then
  echo "${bad}" >&2
  echo "FAIL — found min-h/min-w utility classes below 48px. Use >=48px for touch targets." >&2
  exit 1
fi

echo "OK — touch target utility classes are >=48px across UI PHP templates"
