# AUDIT_09_ROLE_AUDIT.md

Date: 2026-04-30
Project: `tp-hr`

## Role model re-audit

Source of truth:
- Guard functions in `bootstrap.php`
  - `hr_can_access_hr_dashboard()`
  - `isCEOOrAbove()`
- Auth gates in `core/Auth.php`
  - `Auth::requireLogin()`
  - `Auth::requireHR()`
- Route-level guards across root + `hr/*.php`

## Role/page matrix

### Guest (unauthenticated)
- Expected: no access to protected ESS/HR pages.
- Evidence:
  - `PLAYWRIGHT_SKIP_TABLET=1 npm run -s test:e2e` PASS `30/30`
  - Protected-route suite confirms redirect to login for ESS + HR pages.
  - API guest suite confirms `401` JSON for protected API endpoints.
- Verdict: PASS

### Employee (authenticated non-HR)
- Expected:
  - Access ESS pages only.
  - No access to HR dashboard/routes.
- Static guard evidence:
  - ESS pages use `Auth::requireLogin()`.
  - HR pages enforce `hr_can_access_hr_dashboard()` or `Auth::requireHR()`.
- Verdict: PASS by code guard contract

### Manager/HR (HR-level)
- Expected:
  - Access HR operational pages via `hr_can_access_hr_dashboard()`.
  - CEO-only pages remain restricted.
- Static guard evidence:
  - `hr/index.php`, `hr/employees.php`, `hr/leaves.php`, `hr/attendance.php`, `hr/documents.php`, `hr/document_templates.php` guarded by HR gate.
  - `hr/reports.php`, `hr/settings.php`, `hr/dayoff_approvals.php`, `hr/api_keys.php` require stronger CEO gate.
- Verdict: PASS by code guard contract

### CEO (or above)
- Expected: full access to HR pages including sensitive/approval/config routes.
- Static guard evidence:
  - CEO-only pages explicitly require `isCEOOrAbove()`.
- Verdict: PASS by code guard contract

## Approval-route focus (high risk)

- `hr/attendance_adjustments.php`:
  - Requires HR dashboard access and `isCEOOrAbove()`.
  - Deny path sets flash error and redirects.
- `hr/dayoff_approvals.php`:
  - Requires HR dashboard access and `isCEOOrAbove()`.
- Verdict: PASS (policy-aligned for executive approval flows)

## Role audit conclusion

- UI by role: PASS
- Access by role: PASS
- Action authorization by role: PASS
- No blocker regression found in role/permission contracts.
