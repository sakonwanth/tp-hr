#!/usr/bin/env bash
# Immutable scoped release. No migrations, repairs, payroll or notification jobs.
set -euo pipefail
DEPLOY_PATH=${1:?deployment path required}
TARGET=${2:?immutable target required}
APP=${3:?crm or hr required}
[[ "$TARGET" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ "$APP" == crm || "$APP" == hr ]] || exit 1
cd "$DEPLOY_PATH"
git() { command git -c core.filemode=false "$@"; }
exec 9>"$(dirname "$PWD")/.tp-shared-db-deploy.lock"
flock -w 600 9
PREVIOUS=$(git rev-parse HEAD)
git merge-base --is-ancestor "$PREVIOUS" "$TARGET"
git diff --cached --quiet || { echo 'Staged runtime changes block release'; exit 1; }
CHANGED=$(git diff --name-only "$PREVIOUS" "$TARGET")
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  case "$file" in
    scripts/deploy_employee_finance_readonly.sh|scripts/audit_employee_finance_release.php|scripts/qa_employee_finance_release.php|.github/workflows/employee-finance-release.yml) continue ;;
  esac
  case "$APP:$file" in
    crm:expense_request_form.php|crm:api/tp_expense_form.php|crm:api/expense_flex_push.php|crm:core/Services/LineExpenseFormService.php|crm:core/Services/LineExpenseRequest.php|crm:core/Services/LineExpenseErpActionBridge.php|crm:assets/js/employee-loan-preview.js|crm:assets/js/expense-saved-banks.js|crm:scripts/qa_employee_loan_preview.cjs|crm:scripts/ci-stubs/tp-common/src/Hr/EmployeeFinancePolicy.php|crm:tests/Unit/LineExpenseFormServiceTest.php|crm:tests/Unit/LineExpenseRequestParseTest.php|crm:.github/workflows/deploy.yml) ;;
    hr:core/Services/EmployeeFinanceManagementService.php|hr:scripts/qa_employee_finance_contract.php|hr:scripts/qa_employee_finance_management.php) ;;
    *) echo "Outside employee-finance code-only scope: $file"; exit 1 ;;
  esac
done <<< "$CHANGED"
while IFS= read -r dirty; do
  if printf '%s\n' "$CHANGED" | grep -Fxq "$dirty"; then echo "Overlapping runtime change: $dirty"; exit 1; fi
  echo "Preserving unrelated runtime change: $dirty"
done < <(git diff --name-only)
PHP_BIN=${PHP_BIN:-$(command -v php)}
"$PHP_BIN" -r 'require "vendor/autoload.php";$c="TpCommon\\Hr\\EmployeeFinancePolicy";if(!class_exists($c)||$c::MAX_TERM_MONTHS!==12||$c::MIN_MONTHLY_INSTALLMENT!==2000.0||!method_exists($c,"rebuildExistingSchedule")){fwrite(STDERR,"Deploy the verified shared employee-finance policy first\\n");exit(1);}'
AUDIT=$(mktemp)
trap 'rm -f "$AUDIT"' EXIT
git show "$TARGET:scripts/audit_employee_finance_release.php" > "$AUDIT"
BEFORE=$("$PHP_BIN" "$AUDIT")
[[ "$BEFORE" =~ ^[0-9a-f]{64}$ ]] || { echo 'Invalid protected-state evidence'; exit 1; }
STATE="$(dirname "$PWD")/.tp-release-safety/tp-$APP-employee-finance"
mkdir -p "$STATE"
printf '%s\n' "$PREVIOUS" > "$STATE/previous_sha"
printf '%s\n' "$TARGET" > "$STATE/target_sha"
printf '%s\n' deploying > "$STATE/status"
rollback() {
  code=$?; trap - EXIT
  if [[ $code != 0 ]]; then
    git checkout --detach "$PREVIOUS"
    printf '%s\n' rolled_back > "$STATE/status"
  fi
  rm -f "$AUDIT"
  exit "$code"
}
trap rollback EXIT
git checkout --detach "$TARGET"
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  test "$(git hash-object "$file")" = "$(git rev-parse "$TARGET:$file")"
  if [[ "$file" == *.php ]]; then "$PHP_BIN" -l "$file"; fi
done <<< "$CHANGED"
AFTER=$("$PHP_BIN" "$AUDIT")
test "$BEFORE" = "$AFTER" || { echo 'Protected data changed; code rollback only, no financial-data restoration'; exit 1; }
curl --fail --silent --show-error --head --max-time 20 "https://$APP.tp-asset.com/login.php" >/dev/null
printf '%s\n' "$TARGET" > "$STATE/deployed_sha"
printf '%s\n' "$AFTER" > "$STATE/protected_state_sha256"
printf '%s\n' deployed > "$STATE/status"
trap - EXIT
rm -f "$AUDIT"
echo "Verified $APP employee-finance code-only release $TARGET"
echo "Protected request/approval/item/evidence state unchanged: $AFTER"
echo 'No migrations, data repair, payroll, approvals, payments or LINE dispatch executed.'
