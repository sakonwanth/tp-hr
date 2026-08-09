# AUDIT_04_FULL_BUSINESS_FLOW.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

Coverage note: All major end-to-end business flows in discovered routes were mapped and risk-reviewed.  
Write paths were audited by code path + DB contract checks (no destructive execution in production).

## Flow 1 — Password login

1. Flow name: Username/password login
2. Start page: `/login.php`
3. User role: Guest -> authenticated user
4. Input: `username`, `password`
5. Validation: non-empty, password verify
6. Controller logic: `Auth::login()`
7. Service logic: `Auth` + DB read
8. Tables: `users`, `roles`
9. API calls: none
10. Integration: shared session / SSO compatibility
11. Expected output: session established + redirect
12. Actual risk: login CSRF token absent on form
13. Failure points: invalid creds, inactive user, DB unavailable
14. Required tests: invalid/valid login, inactive user, session fixation regression

## Flow 2 — LINE token login bridge

1. Flow: CRM/LINE token handoff login
2. Start: `/api/line_login.php?action=login&token=...`
3. Role: Guest -> authenticated
4. Input: token + csrf state
5. Validation: token lookup + expiry + used flag
6. Controller: `api/line_login.php`
7. Service: `Auth` session set
8. Tables: `cross_domain_tokens`, `users`, `roles`, `activity_logs`
9. API calls: none
10. Integration: TP-CRM token issuance
11. Output: login + redirect
12. Risk: token replay/expiry edge handling
13. Failure points: missing/expired token
14. Tests: valid token once-only, expired token, reused token

## Flow 3 — Logout

1. Flow: session logout
2. Start: `/logout.php` or header logout forms
3. Role: authenticated
4. Input: CSRF token (form)
5. Validation: session/token
6. Controller: `logout.php`
7. Service: `Auth::logout()`
8. Tables: `hr_audit_logs` (log)
9. API: none
10. Integration: shared session provider
11. Output: session destroyed
12. Risk: low
13. Failure points: stale session cookie
14. Tests: logout from web + mobile menu

## Flow 4 — Check-in / check-out

1. Flow: attendance check-in/out
2. Start: `/checkin.php`
3. Role: Employee
4. Input: geolocation/photo/reason/actions
5. Validation: CSRF, status guards, duplicate checks
6. Controller: `/api/attendance.php` (`check_in`, `check_out`)
7. Service: `AttendanceService`, helper guards
8. Tables: `hr_attendances`, `hr_checkin_locations`, `hr_work_shifts`
9. API: internal AJAX to `/api/attendance.php`
10. Integration: tp-checkin-compatible photo path semantics
11. Output: attendance row upsert/update
12. Risk: high (core payroll dependency)
13. Failure points: location/photo mismatch, race conditions
14. Tests: office/offsite/checkin-duplicate/checkout-without-checkin

## Flow 5 — Outside attendance request

1. Flow: offsite attendance approval request
2. Start: `/checkin.php` modal -> `/api/attendance.php`
3. Role: Employee + HR reviewer
4. Input: reason, photo, type
5. Validation: CSRF, pending duplicates, ownership
6. Controller: `/api/attendance.php` (`request_*`, review actions)
7. Service: attendance helper + audit
8. Tables: `hr_attendance_outside_requests`, `hr_attendances`
9. API: internal
10. Integration: none external
11. Output: request created/reviewed
12. Risk: high
13. Failure points: duplicate pending entries
14. Tests: submit pending/approve/reject/list

## Flow 6 — Planned late-start request/cancel

1. Flow: planned late start
2. Start: `/checkin.php` -> `/api/attendance.php`
3. Role: Employee + HR/CEO approvers (notification consumers)
4. Input: target date/time/reason
5. Validation: CSRF, date/time rules, cutoffs
6. Controller: `request_late_start`, `cancel_late_start`
7. Service: helper functions + line notifier bridge
8. Tables: `hr_attendances` planned columns, `system_settings`
9. API: internal
10. Integration: TP-CRM LINE events
11. Output: planned fields updated, notifications sent
12. Risk: high
13. Failure points: notifier bridge missing path, schema compatibility
14. Tests: request/cancel + audit log + notification path

## Flow 7 — Leave request create/cancel

1. Flow: employee leave lifecycle (self)
2. Start: `/leave.php`, `/leave_history.php`
3. Role: Employee
4. Input: type/date/reason/document
5. Validation: CSRF, entitlement, overlaps, required doc
6. Controller: `/api/leave.php` (`create`,`cancel`)
7. Service: DB transactional logic + notifier bridge
8. Tables: `hr_leave_requests`, `hr_leave_entitlements`, `hr_leave_types`
9. API: internal
10. Integration: LINE notify on new/cancel flow path
11. Output: leave request + entitlement adjustments
12. Risk: high
13. Failure points: entitlement race/rollback
14. Tests: create valid/invalid, cancel constraints

## Flow 8 — Leave approve/reject (HR/CEO chain)

1. Flow: managerial approval
2. Start: `/hr/leaves.php` and `/hr/index.php` quick actions
3. Role: HR+ (with role checks)
4. Input: request id + decision + reason
5. Validation: CSRF, permission gate, status transitions
6. Controller: `/api/leave.php` (`approve`,`reject`)
7. Service: bridge functions
8. Tables: `hr_leave_requests`, `hr_leave_entitlements`, `hr_attendances`
9. API: internal
10. Integration: CRM LINE notifier + auto leave attendance sync
11. Output: final status + entitlement/audit updates
12. Risk: high
13. Failure points: double-approval conflicts
14. Tests: approve/reject, idempotency, notification side effects

## Flow 9 — Day-off change request + approval

1. Flow: weekly day-off swap
2. Start: `/dayoff_schedule.php` -> `/hr/dayoff_approvals.php`
3. Role: Employee -> CEO+
4. Input: week range/day/reason
5. Validation: CSRF, ownership, overlap checks
6. Controller: page POST handlers
7. Service: schedule/dayoff logic
8. Tables: `hr_dayoff_requests`, `hr_employee_schedules`
9. API: none (page POST)
10. Integration: attendance interpretation depends on this data
11. Output: approved week-specific day off
12. Risk: high
13. Failure points: duplicate weeks, reject-note requirement
14. Tests: request/cancel/approve/reject/bulk-approve

## Flow 10 — Certificate request + processing

1. Flow: HR document request lifecycle
2. Start: `/certificate.php` -> `/hr/documents.php` -> `/certificate_print.php` / `/verify_document.php`
3. Role: Employee + HR reviewer
4. Input: template/purpose/document uploads/status transitions
5. Validation: CSRF, template active, role gates
6. Controller: `/api/certificate.php` + print endpoint
7. Service: upload helper + verify code generation
8. Tables: `hr_document_requests`, `hr_document_templates`, `hr_issued_documents`, `users`
9. API: internal + public verify route
10. Integration: public verification consumer
11. Output: issued document traceable by verify code
12. Risk: high
13. Failure points: invalid upload/missing signature assets
14. Tests: create/cancel/process/complete/reject/verify

## Flow 11 — Payslip read/download

1. Flow: employee payslip access
2. Start: `/payslip.php` and `/api/payslip.php`
3. Role: Employee
4. Input: slip id, month filters
5. Validation: ownership + status (approved/paid)
6. Controller: page + API action `download`
7. Service: payslip render helper
8. Tables: `payroll_slips`, `payroll_runs`, `users`, `system_settings`
9. API: internal AJAX/form POST
10. Integration: shared payroll data with CRM
11. Output: HTML attachment/print view
12. Risk: high (salary confidentiality)
13. Failure points: IDOR risk if ownership check broken
14. Tests: own slip allowed, foreign slip denied

## Flow 12 — Profile self-service CRUD

1. Flow: employee profile updates
2. Start: `/profile.php` -> `/api/profile.php`
3. Role: Employee
4. Input: contact + emergency/family/education/work forms
5. Validation: CSRF + ownership checks
6. Controller: action switch in `api/profile.php`
7. Service: direct PDO + audit logging
8. Tables: `users`, `hr_emergency_contacts`, `hr_employee_family`, `hr_employee_education`, `hr_employee_work_history`
9. API: internal
10. Integration: shared user profile
11. Output: CRUD persisted for own records
12. Risk: high
13. Failure points: missing ownership conditions
14. Tests: create/update/delete per section + ownership isolation

## Flow 13 — HR employee master management

1. Flow: employee add/edit/deactivate/reset password
2. Start: `/hr/employees.php`, `/hr/employee_form.php`
3. Role: HR+ (CEO stricter for some actions)
4. Input: identity/work/schedule/password
5. Validation: CSRF, role gating, uniqueness checks
6. Controller: page POST handlers
7. Service: helper + auth logging
8. Tables: `users`, `roles`, `hr_employee_schedules`
9. API: none (page handler)
10. Integration: shared users with CRM/checkin
11. Output: user lifecycle changes
12. Risk: high
13. Failure points: duplicate username/email/employee code
14. Tests: add/edit/deactivate/reactivation impacts

## Flow 14 — Attendance admin adjust/delete

1. Flow: HR attendance correction
2. Start: `/hr/attendance.php`
3. Role: HR+
4. Input: user/date/time/status/note
5. Validation: CSRF, permission, note required
6. Controller: `/api/attendance.php` (`adjust`,`delete`)
7. Service: attendance + audit log linkage
8. Tables: `hr_attendances`, `hr_attendance_*`, `hr_audit_logs`
9. API: internal
10. Integration: payroll downstream impact
11. Output: corrected/deleted rows
12. Risk: high
13. Failure points: deletion cascade side-effects
14. Tests: adjust/delete/history audit trace

## Flow 15 — Attendance adjustment approval workflow (CEO)

1. Flow: formal adjustment approval
2. Start: `/hr/attendance_adjustments.php`
3. Role: CEO+
4. Input: approve/reject/all with remarks
5. Validation: CSRF, status transitions, reviewer tracking
6. Controller: page POST + `AttendanceAdjustmentService`
7. Service: transactional updates with row lock
8. Tables: `hr_attendance_adjustments`, `hr_attendances`
9. API: external v1 also supports approve/reject
10. Integration: payroll accuracy dependency
11. Output: reviewed status + attendance update
12. Risk: high
13. Failure points: stale states/race
14. Tests: single/bulk decisions, reject-reason required

## Flow 16 — HR settings and master data

1. Flow: update HR/system settings + holidays + leave types + shifts
2. Start: `/hr/settings.php`
3. Role: CEO+
4. Input: multiple forms
5. Validation: CSRF + business constraints
6. Controller: page POST action switch
7. Service: `SettingsService`
8. Tables: `hr_settings`, `system_settings`, `hr_holidays`, `hr_leave_types`, `hr_work_shifts`
9. API: none
10. Integration: all attendance/leave/payroll behavior
11. Output: config updates
12. Risk: high
13. Failure points: invalid values causing downstream policy errors
14. Tests: each tab update + rollback scenario

## Flow 17 — External API key lifecycle

1. Flow: create/revoke/activate API keys
2. Start: `/hr/api_keys.php`
3. Role: CEO+
4. Input: scopes, ip/origin, expiry, service user
5. Validation: CSRF + user checks + range checks
6. Controller: page POST action
7. Service: `ApiAuth::issue()`
8. Tables: `hr_api_keys`, `hr_api_request_logs`, `users`
9. API: none
10. Integration: external system access boundary
11. Output: key issued once-only, key state changes
12. Risk: high
13. Failure points: over-broad scopes, no expiry policy
14. Tests: scope enforcement, revoke immediate effect

## Flow 18 — External API v1 resource operations

1. Flow: machine-to-machine integration
2. Start: `/api/v1/*`
3. Role: API client
4. Input: Bearer key + scoped payload
5. Validation: key active, scope, IP, method, body
6. Controller: `api/v1/index.php` + resource files
7. Service: `ApiAuth` + domain services (`PayrollService`, `AttendanceAdjustmentService`)
8. Tables: broad HR/payroll tables by resource
9. API: public integration boundary
10. Integration: tp-crm/tp-checkin/tp-asset ecosystem consumers
11. Output: JSON success/fail + log row
12. Risk: high
13. Failure points: scope misconfiguration, rate-limit storage issues
14. Tests: authn/authz matrix by scope + negative tests

## Flow 19 — Reporting and CSV export

1. Flow: CEO analytics + export
2. Start: `/hr/reports.php`
3. Role: CEO+
4. Input: report type/date range/department
5. Validation: CSRF (POST export only), range and report allowlist
6. Controller: page GET/POST
7. Service: direct SQL aggregations
8. Tables: `hr_attendances`, `hr_leave_requests`, `hr_leave_types`, `users`, `hr_holidays`
9. API: none
10. Integration: management analytics
11. Output: on-screen summaries + CSV file
12. Risk: medium-high (data accuracy and performance)
13. Failure points: long range heavy query
14. Tests: each report mode + export correctness

## Flow 20 — Public document verification

1. Flow: third-party verify issued document
2. Start: `/verify_document.php`
3. Role: Public
4. Input: verify code / doc number
5. Validation: sanitized query
6. Controller: page GET
7. Service: direct SQL
8. Tables: `hr_document_requests`, `hr_document_templates`, `users`
9. API: none
10. Integration: external trust/check process
11. Output: valid/invalid document status
12. Risk: medium
13. Failure points: brute-force query abuse
14. Tests: valid/invalid/disabled docs

## Flow 21 — WFH auto-stamp cron

1. Flow: scheduled attendance generation for WFH users
2. Start: cron `stamp_wfh.php`
3. Role: system job
4. Input: date argument/default today
5. Validation: date format + idempotency checks
6. Controller: cron script
7. Service: `WfhStamp`
8. Tables: `users`, `hr_attendances`, `hr_holidays`, `hr_dayoff_requests`, `hr_leave_requests`
9. API: none
10. Integration: payroll/timekeeping baseline
11. Output: WFH attendance rows
12. Risk: high
13. Failure points: schedule mismatch or duplicate protection bypass
14. Tests: rerun idempotency and holiday/dayoff skips

## Flow 22 — Absence backfill cron

1. Flow: nightly absence correction/backfill
2. Start: cron `backfill_absences.php`
3. Role: system job
4. Input: date range (defaults)
5. Validation: skips holidays/leaves/dayoff/WFH/system users
6. Controller: cron script
7. Service: direct SQL + `WfhStamp`
8. Tables: `users`, `hr_attendances`, `hr_holidays`, `hr_leave_requests`, `hr_dayoff_requests`
9. API: none
10. Integration: payroll and HR attendance completeness
11. Output: ABSENT/WFH rows inserted
12. Risk: high
13. Failure points: incorrect range or policy drift
14. Tests: dry-range verification before run

## Flow 23 — GitHub auto-deploy webhook

1. Flow: signed push event -> deploy pull
2. Start: `/webhook.php`
3. Role: GitHub webhook caller
4. Input: payload + HMAC signature
5. Validation: secret + event + branch
6. Controller: webhook script
7. Service: shell `git pull`
8. Tables: none
9. API: GitHub webhook
10. Integration: deployment pipeline
11. Output: code pull + deploy log
12. Risk: high
13. Failure points: secret leakage/replay
14. Tests: signature fail/pass and branch filter

