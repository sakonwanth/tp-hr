# AUDIT_03_DATABASE_RELATIONSHIP.md

Date: 2026-04-30  
Mode: Read-only audit
Project: `tp-hr`

## 1) Relationship scope

Primary entities audited:
- Identity: `users`, `roles`, `cross_domain_tokens`
- Attendance: `hr_attendances`, `hr_attendance_adjustments`, `hr_attendance_outside_requests`, `hr_work_shifts`, `hr_checkin_locations`
- Leave/dayoff/OT: `hr_leave_requests`, `hr_leave_types`, `hr_leave_entitlements`, `hr_dayoff_requests`, `ot_requests`
- Documents: `hr_document_requests`, `hr_document_templates`, `hr_issued_documents`
- Profile master/detail: `hr_employee_schedules`, `hr_emergency_contacts`, `hr_employee_family`, `hr_employee_education`, `hr_employee_work_history`
- Payroll: `payroll_runs`, `payroll_slips`, `employee_salary_setup`
- Security/API: `hr_api_keys`, `hr_api_request_logs`, `hr_audit_logs`

## 2) PK/FK consistency

Production metadata:
- FK relationships in shared DB: `217`
- HR-domain FKs include expected links (`users`, `roles`, `hr_leave_types`, `payroll_runs`, etc.)

Examples verified:
- `users.role_id -> roles.id`
- `hr_attendances.user_id -> users.id`
- `hr_attendance_adjustments.attendance_id -> hr_attendances.id`
- `hr_leave_requests.leave_type_id -> hr_leave_types.id`
- `hr_document_requests.template_id -> hr_document_templates.id`
- `payroll_slips.payroll_run_id -> payroll_runs.id`

## 3) Orphan and duplicate checks (read-only SQL)

All audited critical checks returned `0`:

- orphan users-role
- orphan cross-domain-token user
- orphan attendance user/shift
- orphan leave user/type
- orphan document user/template
- orphan payroll slip run/user
- duplicate attendance (`user_id + attendance_date`)
- duplicate leave entitlement (`user_id + leave_type_id + year`)
- duplicate dayoff request (`user_id + week_start + week_end`)
- missing active employee schedule rows
- invalid date ranges (`leave`, `dayoff`)
- negative payroll net values

## 4) Status-field integrity snapshot

- `hr_document_requests`: `PROCESSING=2`
- `hr_attendance_adjustments`: `APPROVED=9`
- `hr_attendance_outside_requests`: `APPROVED=2`
- `payroll_runs`: `paid=4`
- `hr_leave_requests`, `hr_dayoff_requests`, `ot_requests`: currently no rows in production snapshot

Assessment:
- Status sets in active data look internally consistent for current row volume.

## 5) Soft delete / ownership / approval linkage

- `users.is_active` used as active flag (not hard delete policy for users).
- Ownership checks are enforced in API handlers for self-service resources (`user_id` constraints).
- Approval linkage fields are present and used:
  - leave: `approver_*`, `final_approved_by`
  - dayoff/outside/adjustments: `reviewed_by`, `reviewed_at`, status transitions
  - documents: `processed_by`, issuance tracking tables

## 6) Data dependency risk notes

- Shared database with CRM/Checkin means schema changes have cross-system blast radius.
- Runtime compatibility guards in code indicate historical schema drift risk; contract script mitigates this.

## 7) Conclusion

- No blocker-level relational corruption detected in sampled production checks.
- FK graph and ownership/approval links are structurally healthy for current flows.

