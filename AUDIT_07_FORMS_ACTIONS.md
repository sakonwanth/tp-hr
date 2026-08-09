# AUDIT_07_FORMS_ACTIONS.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## Scope

Audited all discovered form/action surfaces:
- Root pages: login, leave, dayoff, certificate, payslip download, profile sections
- HR pages: employees, employee_form, attendance, adjustments, leaves, dayoff approvals, documents, templates, settings, reports, api_keys
- Internal APIs receiving form/AJAX actions: `/api/attendance.php`, `/api/leave.php`, `/api/certificate.php`, `/api/payslip.php`, `/api/profile.php`

## 1) Validation/CSRF matrix

| Area | Required fields | CSRF | Permission enforcement | DB write | Result |
|---|---|---|---|---|---|
| Login (`login.php`) | yes | not present on login form | n/a | session/login logs | Medium risk |
| Leave request/cancel/approve/reject | yes | yes | self/HR gates | yes | PASS |
| Attendance checkin/checkout/adjust/delete | yes | yes (POST) | self + HR gates | yes | PASS |
| Dayoff request + approvals | yes | yes | employee + CEO gates | yes | PASS |
| Certificate request + HR processing | yes | yes | self + HR gates | yes | PASS |
| Payslip download action | yes | yes (POST) | owner checks | read/export | PASS |
| Profile CRUD (all sections) | yes | yes (`verifyCsrf`) | self ownership checks | yes | PASS |
| HR employee CRUD/schedule/password | yes | yes | HR/CEO gates | yes | PASS |
| HR settings/holidays/leave types/shifts | yes | yes | CEO gates | yes | PASS |
| HR API key management | yes | yes | CEO gates | yes | PASS |
| Reports export CSV | yes | yes (POST export) | CEO gates | read/export | PASS |

## 2) Duplicate submission protection

- Partial protections found:
  - State checks before update/approve/reject in key flows.
  - Unique constraints in DB (e.g., attendance/date, dayoff week uniqueness).
- Not all forms have client-side one-click lock; reliance is mostly server-side state checks.

Assessment: acceptable baseline, improve UX-level anti-double-submit on high-value actions.

## 3) Redirect and success/error handling

- Most page-form actions use `flash('success'/'error')` + redirect.
- APIs return structured JSON with success/error and proper HTTP status codes.
- Error message hygiene generally avoids raw DB messages in production-facing responses.

## 4) File upload behavior

- Central secure helper exists: `uploadFile()` in `core/Helpers.php`
  - MIME + signature checks
  - extension allowlist
  - size checks
  - image/pdf/office structural validation
- One exception path:
  - `hr/document_templates.php` contains direct `move_uploaded_file()` for signature asset path handling.

Assessment:
- Overall upload security is good.
- Recommend converging all upload paths to shared helper for consistency and auditability.

## 5) Data ownership and audit trail

- Ownership checks present in self-service APIs (`WHERE id=? AND user_id=?` patterns).
- Administrative writes generally logged via `Auth::log()` and/or `hr_audit_logs`.
- API v1 traffic logged in `hr_api_request_logs`.

## 6) Key findings

1. Login form lacks CSRF token (medium).
2. Direct upload path in `hr/document_templates.php` bypasses common upload helper (medium).
3. Some actions rely on optimistic UI without explicit submit-lock (low).

## 7) Verdict

- Core form/action safety is strong for permission + server-side validation.
- No blocker-level broken action flow detected in current audit.
- Hardening items tracked in issue list.

