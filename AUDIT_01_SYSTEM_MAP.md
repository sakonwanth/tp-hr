# AUDIT_01_SYSTEM_MAP.md

Date: 2026-04-30  
Mode: Read-only audit (no production data mutation)
Project: `tp-hr`

## 1) Discovery summary

- Root web routes discovered: `14`
- HR web routes discovered: `14`
- Internal API routes discovered (`/api/*.php`): `8`
- External API v1 files discovered (`/api/v1/*.php`): `11` (routed by `api/v1/index.php`)
- Cron scripts discovered: `2`
- Core services discovered: `4` (`AttendanceService`, `AttendanceAdjustmentService`, `PayrollService`, `SettingsService`)
- Middleware/guards discovered:
  - Session + SSO bootstrap (`bootstrap.php`)
  - `Auth::requireLogin()`, `Auth::requireHR()`
  - role helpers: `hr_can_access_hr_dashboard()`, `isCEOOrAbove()`
  - API key auth: `ApiAuth::require()`, scopes, IP allowlist, rate-limit
  - CSRF: `verifyCsrfToken()` / `verifyCsrf()`

## 2) Architecture map (controller/model/service/middleware)

- Controller pattern: file-based procedural controllers (no MVC framework router for web pages).
- Models: direct PDO SQL queries in route/API files + service classes.
- Services:
  - `core/Services/AttendanceService.php`
  - `core/Services/AttendanceAdjustmentService.php`
  - `core/Services/PayrollService.php`
  - `core/Services/SettingsService.php`
- Middleware-equivalent:
  - `core/Auth.php` (session auth, ACL, role checks)
  - `core/ApiAuth.php` (Bearer key auth + scope)
  - CSRF helpers in `bootstrap.php`
- Shared dependencies:
  - `tp-common` via Composer (`tpasset/tp-common`)
  - shared DB (`tp_crm`)
  - TP-CRM SSO + LINE notifier bridge
  - TP-Checkin storage/URL helpers

## 3) Route map — Web (root)

| Route | Controller | Method(s) | View | Main tables | Role | Action type | Integration | Risk |
|---|---|---|---|---|---|---|---|---|
| `/` (`index.php`) | `index.php` | GET | dashboard | `hr_attendances`, `hr_leave_*`, `hr_document_requests`, `hr_announcements`, `users` | Employee/HR | Read | shared DB | Medium |
| `/checkin.php` | `checkin.php` | GET | check-in screen | `hr_attendances`, `hr_work_shifts`, `hr_holidays`, `hr_attendance_outside_requests` | Employee | Read/UI + API trigger | tp-checkin photo path conventions | High |
| `/leave.php` | `leave.php` | GET | leave request | `hr_leave_types`, `hr_leave_entitlements`, `hr_leave_requests` | Employee | Read/UI + API trigger | LINE notify path via API | High |
| `/leave_history.php` | `leave_history.php` | GET | leave history | `hr_leave_requests`, `hr_leave_types` | Employee | Read | shared DB | Medium |
| `/attendance_history.php` | `attendance_history.php` | GET | attendance history | `hr_attendances`, `hr_holidays`, `hr_dayoff_requests`, `hr_employee_schedules` | Employee | Read | shared DB | Medium |
| `/dayoff_schedule.php` | `dayoff_schedule.php` | GET/POST | day-off schedule | `hr_employee_schedules`, `hr_dayoff_requests` | Employee | Read/Write | approval consumed by HR flow | High |
| `/certificate.php` | `certificate.php` | GET | certificate request | `hr_document_templates`, `hr_document_requests`, `hr_issued_documents` | Employee | Read/UI + API trigger | verify path | High |
| `/certificate_print.php` | `certificate_print.php` | POST | printable doc | `hr_document_requests`, `users`, `system_settings` | Employee/HR | Read/Update issuance metadata | PDF/print + verify QR | High |
| `/payslip.php` | `payslip.php` | GET/POST | payslip list/print | `payroll_runs`, `payroll_slips`, `users`, `system_settings` | Employee | Read/Export | shared payroll ownership | High |
| `/profile.php` | `profile.php` | GET/POST (logout form) | profile | `users`, `hr_emergency_contacts`, `hr_employee_family`, `hr_employee_education`, `hr_employee_work_history` | Employee | Read/UI + API trigger | shared users | High |
| `/login.php` | `login.php` | GET/POST | auth page | `users`, `roles` | Guest | Auth | CRM SSO/LINE login bridge | High |
| `/logout.php` | `logout.php` | POST | n/a | session | Authenticated | Logout | shared session | Medium |
| `/verify_document.php` | `verify_document.php` | GET | public verification | `hr_document_requests`, `hr_document_templates`, `users`, `system_settings` | Public | Read | external/public verification | High |
| `/webhook.php` | `webhook.php` | POST | JSON/text | git deployment side-effect | Public (signed) | Deploy trigger | GitHub webhook | High |

## 4) Route map — HR web

| Route | Controller | Method(s) | View | Main tables | Role | Action type | Integration | Risk |
|---|---|---|---|---|---|---|---|---|
| `/hr/index.php` | `hr/index.php` | GET | HR dashboard | `hr_*`, `users` | HR+ | Read + quick approvals via API | leave/doc APIs | High |
| `/hr/employees.php` | `hr/employees.php` | GET/POST | employee list | `users`, `roles`, `hr_employee_schedules` | HR+/CEO for critical actions | Read/Update/Deactivate | shared users with CRM/checkin | High |
| `/hr/employee_form.php` | `hr/employee_form.php` | GET/POST | employee create/edit | `users`, `hr_employee_schedules`, `roles` | HR/CEO (create stricter) | Create/Update | shared identity | High |
| `/hr/employee_view.php` | `hr/employee_view.php` | GET | employee detail | `users`, `hr_attendances`, `hr_leave_requests` | HR+ | Read | shared DB | Medium |
| `/hr/employee_attendance.php` | `hr/employee_attendance.php` | GET | employee attendance | `hr_attendances`, `hr_employee_schedules` | HR+ | Read | shared DB | Medium |
| `/hr/attendance.php` | `hr/attendance.php` | GET | attendance management | `hr_attendances`, `hr_holidays`, `hr_dayoff_requests` | HR+ | Read + API write/delete | adjustment and audit logs | High |
| `/hr/attendance_adjustments.php` | `hr/attendance_adjustments.php` | GET/POST | adjustment approvals | `hr_attendance_adjustments`, `hr_attendances`, `users` | CEO+ | Approve/Reject/Bulk | payroll impact | High |
| `/hr/leaves.php` | `hr/leaves.php` | GET | leave management | `hr_leave_requests`, `hr_leave_types`, `users` | HR+ | Read + API approve/reject | LINE notify + attendance sync | High |
| `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | GET/POST | dayoff approvals | `hr_dayoff_requests`, `users` | CEO+ | Approve/Reject/Bulk | schedule effect | High |
| `/hr/documents.php` | `hr/documents.php` | GET | document approvals | `hr_document_requests`, `hr_document_templates`, `users` | HR+ | Process/Complete/Reject via API | issue/verify document | High |
| `/hr/document_templates.php` | `hr/document_templates.php` | GET/POST | template settings | `hr_document_templates`, `system_settings`, `users` | HR+ | Create/Update/Delete + uploads | signature assets | High |
| `/hr/reports.php` | `hr/reports.php` | GET/POST | reports/export | `hr_attendances`, `hr_leave_requests`, `users`, `hr_holidays` | CEO+ | Read/Export CSV | analytics/management | High |
| `/hr/settings.php` | `hr/settings.php` | GET/POST | HR settings | `hr_settings`, `system_settings`, `hr_holidays`, `hr_leave_types`, `hr_work_shifts` | CEO+ | Update config/master data | cross-system behavior toggles | High |
| `/hr/api_keys.php` | `hr/api_keys.php` | GET/POST | external API keys | `hr_api_keys`, `hr_api_request_logs`, `users` | CEO+ | Create/Revoke/Activate | external integrations | High |

## 5) Route map — Internal API (`/api/*.php`)

| Route | Controller | Method(s) | Guard | Action map | Main tables | Role | Risk |
|---|---|---|---|---|---|---|---|
| `/api/attendance.php` | `api/attendance.php` | GET/POST | `Auth::check` + CSRF (POST) + per-action HR checks | `check_in`, `check_out`, `adjust`, `delete`, `request_late_start`, `cancel_late_start`, `today`, `history`, `monthly`, `adjustment_history` | `hr_attendances`, `hr_attendance_*`, `hr_audit_logs`, `hr_work_shifts` | Employee/HR | High |
| `/api/leave.php` | `api/leave.php` | GET/POST | `Auth::requireLogin` + CSRF (POST) | GET: `entitlements`,`history`,`detail`,`pending`,`calendar`; POST: `create`,`cancel`,`approve`,`reject` | `hr_leave_*`, `users`, `hr_holidays` | Employee/HR | High |
| `/api/certificate.php` | `api/certificate.php` | GET/POST | `Auth::requireLogin` + CSRF | GET: `templates`,`requests`,`detail`; POST: `create`,`cancel`,`process`,`update_status`,`complete`,`reject` | `hr_document_*`, `hr_issued_documents`, `users` | Employee/HR | High |
| `/api/payslip.php` | `api/payslip.php` | GET/POST | `Auth::requireLogin`; CSRF on download POST | `download`,`list`,`detail`,`ytd` | `payroll_*`, `users`, `system_settings` | Employee | High |
| `/api/profile.php` | `api/profile.php` | GET/POST | `Auth::requireLogin` + `verifyCsrf()` on POST | `update_contact`, emergency/family/education/work CRUD, `get_profile` | `users`, `hr_emergency_contacts`, `hr_employee_*` | Employee | High |
| `/api/line_login.php` | `api/line_login.php` | GET | token/csrf param flow | LINE SSO handoff (`cross_domain_tokens`) | `cross_domain_tokens`, `users`, `roles`, `activity_logs` | Guest | High |
| `/api/checkin_storage_image.php` | `api/checkin_storage_image.php` | GET | auth + HR gate | stream checkin files | file system only | HR+ | High |
| `/api/health.php` | `api/health.php` | GET | none | DB health ping | DB connect check | Public | Low |

## 6) Route map — External API v1 (`/api/v1/*`)

Router: `api/v1/index.php` dispatches to resource files.

| Endpoint pattern | Methods | Handler file | Required scopes | Main tables |
|---|---|---|---|---|
| `/api/v1/ping` | GET | `api/v1/index.php` | none | n/a |
| `/api/v1/employees` | GET | `employees.php` | `employees.read` (+`employees.read_all` for broad list) | `users`, `roles` |
| `/api/v1/employees/{id}` | GET | `employees.php` | `employees.read` | `users`, `roles` |
| `/api/v1/attendance` | GET | `attendance.php` | `attendance.read` | `hr_attendances`, `hr_work_shifts` |
| `/api/v1/attendance/checkin` | POST | `attendance.php` | `attendance.write` | `hr_attendances` |
| `/api/v1/attendance/checkout` | POST | `attendance.php` | `attendance.write` | `hr_attendances` |
| `/api/v1/leave` | GET/POST | `leave.php` | `leave.read` / `leave.write` | `hr_leave_requests`, `hr_leave_types`, `users` |
| `/api/v1/leave/{id}` | GET | `leave.php` | `leave.read` | `hr_leave_requests` |
| `/api/v1/leave/{id}/cancel` | POST | `leave.php` | `leave.write` | `hr_leave_requests` |
| `/api/v1/leave/{id}/approve` | POST | `leave.php` | `leave.approve` | `hr_leave_requests` |
| `/api/v1/leave/{id}/reject` | POST | `leave.php` | `leave.approve` | `hr_leave_requests` |
| `/api/v1/dayoff-requests` | GET/POST | `dayoff.php` | `dayoff.read` / `dayoff.write` | `hr_dayoff_requests`, `users` |
| `/api/v1/dayoff-requests/{id}/approve` | POST | `dayoff.php` | `dayoff.approve` | `hr_dayoff_requests` |
| `/api/v1/dayoff-requests/{id}/reject` | POST | `dayoff.php` | `dayoff.approve` | `hr_dayoff_requests` |
| `/api/v1/overtime` | GET/POST | `overtime.php` | `overtime.read` / `overtime.write` | `ot_requests`, `users` |
| `/api/v1/overtime/{id}/approve` | POST | `overtime.php` | `overtime.approve` | `ot_requests` |
| `/api/v1/overtime/{id}/reject` | POST | `overtime.php` | `overtime.approve` | `ot_requests` |
| `/api/v1/outside-attendance` | GET | `outside.php` | `outside.read` | `hr_attendance_outside_requests`, `users` |
| `/api/v1/outside-attendance/{id}/approve` | POST | `outside.php` | `outside.approve` | `hr_attendance_outside_requests` |
| `/api/v1/outside-attendance/{id}/reject` | POST | `outside.php` | `outside.approve` | `hr_attendance_outside_requests` |
| `/api/v1/attendance-adjustments` | GET | `adjustments.php` | `adjustments.read` | `hr_attendance_adjustments`, `users` |
| `/api/v1/attendance-adjustments/{id}/approve` | POST | `adjustments.php` | `adjustments.approve` | `hr_attendance_adjustments` |
| `/api/v1/attendance-adjustments/{id}/reject` | POST | `adjustments.php` | `adjustments.approve` | `hr_attendance_adjustments` |
| `/api/v1/payroll-runs` | GET/POST | `payroll.php` / `payroll_write.php` | `payroll.read` / `payroll.write` | `payroll_runs`, `payroll_slips` |
| `/api/v1/payroll-runs/{id}` | GET | `payroll.php` | `payroll.read` | `payroll_runs` |
| `/api/v1/payroll-runs/{id}/approve` | POST | `payroll_write.php` | `payroll.approve` | `payroll_runs` |
| `/api/v1/payroll-runs/{id}/mark-paid` | POST | `payroll_write.php` | `payroll.approve` | `payroll_runs` |
| `/api/v1/payroll-runs/{id}/slips` | GET | `payroll.php` | `payroll.read` | `payroll_slips` |
| `/api/v1/payslips` | GET | `payroll.php` | `payroll.read` | `payroll_slips` |
| `/api/v1/payslips/{id}` | GET | `payroll.php` | `payroll.read` | `payroll_slips` |
| `/api/v1/salary-setup` | GET/POST | `payroll_write.php` | `payroll.read` / `payroll.write` | `employee_salary_setup` |
| `/api/v1/departments` | GET | `hr_meta.php` | `hr.read` | `hr_departments` |
| `/api/v1/positions` | GET | `hr_meta.php` | `hr.read` | `hr_positions` |
| `/api/v1/holidays` | GET | `hr_meta.php` | `hr.read` | `hr_holidays` |
| `/api/v1/leave-types` | GET | `hr_meta.php` | `hr.read` | `hr_leave_types` |
| `/api/v1/employee-schedules` | GET | `hr_meta.php` | `hr.read` (+`hr.read_all` for broad) | `hr_employee_schedules` |
| `/api/v1/announcements` | GET | `hr_meta.php` | `hr.read` | `hr_announcements` |
| `/api/v1/leave-entitlements` | GET | `hr_meta.php` | `hr.read` (+`hr.read_all`) | `hr_leave_entitlements` |

## 7) Scheduled jobs / background

| Job | Trigger | Action | Tables | Risk |
|---|---|---|---|---|
| `cron/stamp_wfh.php` | daily `00:05` | auto-create WFH attendance rows | `users`, `hr_attendances`, `hr_holidays`, `hr_dayoff_requests`, `hr_leave_requests` | High |
| `cron/backfill_absences.php` | daily `23:10` | backfill ABSENT/WFH for missing days | `users`, `hr_attendances`, `hr_holidays`, `hr_leave_requests`, `hr_dayoff_requests` | High |
| CRM-side job dependency | daily `20:01` (in server crontab) | `crm.tp-asset.com/scripts/cron_hr_auto_absent.php` | shared HR tables | High |

## 8) Export/report/upload/notification map

- Export/report:
  - `hr/reports.php` CSV export
  - `payslip.php` + `api/payslip.php` payslip download
  - `certificate_print.php` print/PDF
- Upload:
  - leave document (`api/leave.php`)
  - certificate document (`api/certificate.php`)
  - HR document completion upload (`hr/documents.php`)
  - template/signature/logo/seal upload (`hr/document_templates.php`)
- Notifications:
  - CRM LINE bridge (`core/CrmLineNotifierBridge.php`)
  - leave new/approved/rejected
  - planned late-start requested/cancelled/confirmed

## 9) Integration dependencies (discovered)

1. `tp-common` (shared session/SSO/logging/env/db adapter)
2. `tp-crm` (shared users/roles/settings/payroll, LINE notifier, SSO base, cross-domain token flow)
3. `tp-checkin` (attendance photo path conventions + shared attendance semantics)
4. GitHub webhook deployment path (`webhook.php`)
5. Same production server namespace (`tp-asset.com` vhost layout)

