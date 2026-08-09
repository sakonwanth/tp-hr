# AUDIT_01_FULL_RESCAN.md

Date: 2026-04-30  
Project: `tp-hr`

## Scope Re-Scan (from zero)

User-facing root routes (15 files scanned, 11 interactive pages + 4 utility/public):
- `index.php`, `checkin.php`, `leave.php`, `leave_history.php`, `attendance_history.php`, `payslip.php`, `certificate.php`, `certificate_print.php`, `dayoff_schedule.php`, `profile.php`
- `login.php`, `logout.php`, `verify_document.php`, `webhook.php`, `bootstrap.php` (bootstrap not a route page)

HR routes (14 pages):
- `hr/index.php`, `hr/employees.php`, `hr/employee_form.php`, `hr/employee_view.php`, `hr/employee_attendance.php`
- `hr/attendance.php`, `hr/leaves.php`, `hr/dayoff_approvals.php`, `hr/attendance_adjustments.php`
- `hr/documents.php`, `hr/document_templates.php`, `hr/reports.php`, `hr/settings.php`, `hr/api_keys.php`

Partials/components/layouts:
- Layout shell: `templates/header.php`, `templates/footer.php`
- Component map: `templates/native/component_registry.php`
- Employee partials: `modules/employee/leaves/request_form.php`, `modules/employee/payslip/print_template.php`
- Global style system: `assets/css/native-shell.css`

API/system endpoints (regression scope):
- `api/*.php`, `api/v1/*.php`, cron/scripts/config/core files

## Coverage verdict

- Routes: PASS (no missing browser pages found in project tree scan)
- Role-based pages: PASS (HR/CEO guarded pages identified)
- Hidden pages: PASS (identified `certificate_print.php`, `verify_document.php`, `webhook.php` utility/public paths)

