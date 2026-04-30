#!/usr/bin/env bash
# Fails if full-page ESS or HRA PHP entrypoints drop tp-ios-master-screen (IOS26 Waves 6–7 contract).
# Exempt: login, verify_document, certificate_print (different layouts); fragments (e.g. modules/*).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0

check_rel() {
  local rel="$1"
  local path="$ROOT/$rel"
  if [[ ! -f "$path" ]]; then
    echo "MISSING FILE: $rel" >&2
    FAIL=1
    return
  fi
  if ! grep -Fq 'tp-ios-master-screen' "$path"; then
    echo "MISSING tp-ios-master-screen: $rel" >&2
    FAIL=1
  fi
}

for page in index.php checkin.php leave.php leave_history.php attendance_history.php payslip.php certificate.php dayoff_schedule.php profile.php; do
  check_rel "$page"
done

if [[ -d "$ROOT/hr" ]]; then
  while IFS= read -r -d '' hp; do
    if [[ -f "$hp" ]] && ! grep -Fq 'tp-ios-master-screen' "$hp"; then
      echo "MISSING tp-ios-master-screen: ${hp#$ROOT/}" >&2
      FAIL=1
    fi
  done < <(find "$ROOT/hr" -maxdepth 1 -type f -name '*.php' -print0)
fi

if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
echo "OK — tp-ios-master-screen on ESS shells + hr/*.php"
