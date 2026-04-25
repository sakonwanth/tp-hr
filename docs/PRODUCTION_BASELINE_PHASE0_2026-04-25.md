# TP-HR Production Baseline - Phase 0

Date: 2026-04-25 00:10 ICT  
Scope: production read-only audit for `https://hr.tp-asset.com` before Phase 1 changes/deploy.

## Runtime

| Item | Value |
|---|---|
| HR app path | `/var/www/vhosts/tp-asset.com/hr.tp-asset.com` |
| HR URL | `https://hr.tp-asset.com` |
| HR git HEAD | `d3c560a` |
| HR git status | `main...origin/main`, modified `push-deploy.sh` mode only |
| Web/CLI PHP | PHP 8.4.20 |
| Database | `tp_crm` |
| API ping | `GET /api/v1/ping` returns 200 with `{"success":true,"message":"pong"}` |

Related production apps:

| App | Path | Git HEAD | Status |
|---|---|---:|---|
| `tp-crm` | `/var/www/vhosts/tp-asset.com/crm.tp-asset.com` | `484a219` | dirty: composer files plus multiple debug/upload/untracked files |
| `tp-checkin` | `/var/www/vhosts/tp-asset.com/checkin.tp-asset.com` | `5f5275d` | clean |
| `tp-common` | `/var/www/vhosts/tp-asset.com/tp-common` | `f8b5688` | clean |

## Integration Map

`tp-hr` is deployed as a separate app but shares the production `tp_crm` database. The main integration contracts are:

- `tp-common`: shared Composer dependency for env loading, shared session/SSO, logging, audit and HTTP clients.
- `tp-crm`: shared `users`, `roles`, `system_settings`, payroll tables, payslip token delivery, LINE notification services and central login.
- `tp-checkin`: reads/writes HR attendance, leave, day-off, outside-attendance and planned-late records through shared HR tables, with an API migration boundary available.
- External consumers: `/api/v1/*` through `hr_api_keys` bearer-token authentication.

## Production Table Counts

| Table | Rows |
|---|---:|
| `users` | 6 |
| `roles` | 6 |
| `system_settings` | 83 |
| `hr_settings` | 30 |
| `hr_departments` | 0 |
| `hr_positions` | 0 |
| `hr_work_shifts` | 4 |
| `hr_checkin_locations` | 2 |
| `hr_attendances` | 71 |
| `hr_attendance_adjustments` | 4 |
| `hr_attendance_outside_requests` | 2 |
| `hr_employee_schedules` | 4 |
| `hr_dayoff_requests` | 0 |
| `hr_leave_types` | 10 |
| `hr_leave_entitlements` | 50 |
| `hr_leave_requests` | 0 |
| `hr_document_templates` | 6 |
| `hr_document_requests` | 1 |
| `hr_issued_documents` | 0 |
| `hr_employee_family` | 2 |
| `hr_emergency_contacts` | 1 |
| `hr_employee_education` | 2 |
| `hr_employee_work_history` | 4 |
| `hr_holidays` | 19 |
| `hr_announcements` | 0 |
| `hr_api_keys` | 0 |
| `hr_api_request_logs` | 12 |
| `ot_requests` | 0 |
| `employee_salary_setup` | 18 |
| `payroll_runs` | 4 |
| `payroll_slips` | 17 |
| `payroll_slip_tokens` | 9 |
| `cross_domain_tokens` | 3 |

## Status Distribution

| Table | Distribution |
|---|---|
| `hr_attendances` | `PRESENT=47`, `LATE=4`, `ABSENT=2`, `WFH=18` |
| `hr_attendance_adjustments` | `PENDING=2`, `APPROVED=2` |
| `hr_attendance_outside_requests` | `APPROVED=2` |
| `hr_dayoff_requests` | empty |
| `hr_leave_requests` | empty |
| `hr_document_requests` | `PROCESSING=1` |
| `ot_requests` | empty |
| `payroll_runs` | `approved=1`, `paid=3` |

## Schema Contracts To Preserve

### Shared Identity

`users` is the shared employee identity table. Production has the HR columns required by `tp-hr`, including:

- Identity/login: `employee_code`, `username`, `email`, `password`, `role_id`, `line_user_id`.
- Profile: Thai/English names, `nickname`, `birth_date`, `gender`, `id_card`, address fields.
- Employment: `department`, `position`, `hire_date`, `employment_type`, `work_mode`, probation fields, `is_active`.
- Payroll/profile sensitive fields: `salary`, `probation_salary`, bank and social security fields.
- Integration: `last_login`, Google token fields, `signature_image`.

Do not alter `users` columns or enum values without checking `tp-crm`, `tp-checkin`, and payroll screens.

### Attendance

`hr_attendances` is shared by TP-HR, TP-CRM and TP-Checkin. Production includes:

- Unique contract: `uk_user_date (user_id, attendance_date)`.
- Planned late-start columns: `planned_start_time`, `planned_reason`, `planned_requested_at`, `planned_requested_by`.
- Late/payroll columns: `late_minutes`, `late_excused`, `late_excused_reason`, `late_notified_at`.
- Outside attendance columns: `is_offsite`, `offsite_reason`, `offsite_status`, approval fields.
- Status enum: `PENDING`, `PRESENT`, `LATE`, `ABSENT`, `LEAVE`, `HOLIDAY`, `HALF_DAY`, `WFH`.

Known difference: production does not have `planned_cancelled_at`; current TP-HR code does not require it directly.

### Schedules And Calendars

- `hr_employee_schedules` production columns are `user_id`, `day_off`, `effective_date`, `notes`, `updated_by`, timestamps. It does not have `shift_id` or `is_active`.
- `hr_holidays` uses `date`, not `holiday_date`.
- Code that must support `tp-checkin` fallback should avoid assuming `shift_id`, `is_active`, or `holiday_date` unless it first checks schema.

### Leave

`hr_leave_types`, `hr_leave_entitlements`, and `hr_leave_requests` exist and are the leave source of truth. Current production has no leave requests yet, but has configured leave types and entitlements. Approval/status enum values must remain:

- Leave request status: `DRAFT`, `PENDING`, `APPROVED`, `REJECTED`, `CANCELLED`.
- Leave period fields: `start_period`, `end_period` use `FULL`, `AM`, `PM`.

### Payroll

Production payroll tables exist and are active:

- `employee_salary_setup` includes allowances, other income/deductions, group insurance, `ss_opt_out`, `additional_tax_withholding`.
- `payroll_runs` has 4 rows, statuses `approved` and `paid`.
- `payroll_slips` includes attendance deduction fields and `attendance_detail_json`.
- `payroll_slip_tokens` exists for CRM LINE/public payslip delivery.

`USE_TPHR_PAYROLL` is missing in `system_settings`, so CRM payroll API mode is not explicitly enabled from production settings.

### External API

`hr_api_keys` exists but has 0 rows. External API request logging exists in `hr_api_request_logs`. Any API rollout requires creating scoped keys in production and validating rate limit/CORS settings.

## Settings Baseline

| Key | `system_settings` | `hr_settings` |
|---|---|---|
| `USE_TPHR_PAYROLL` | missing | missing |
| `payroll_attendance_enabled` | `1` | missing |
| `payroll_ss_enabled` | `0` | missing |
| `payroll_absent_rate` | `600` | missing |
| `payroll_late_30_rate` | `150` | missing |
| `payroll_late_60_rate` | `300` | missing |
| `payroll_late_over60_as_absent` | `1` | missing |
| `payroll_leave_advance_days` | `7` | missing |
| `hr_line_notifications_enabled` | `1` | missing |
| `hr_late_request_cutoff_hour` | missing | missing |
| `payroll_planned_grace_minutes` | missing | missing |
| `default_work_start` | missing | `10:00` |
| `default_work_end` | missing | `19:00` |
| `grace_period_minutes` | missing | `15` |

## Migration State

Production reports 6 pending TP-HR migrations:

- `2026_04_21_external_api.sql`
- `2026_04_21_probation_salary.sql`
- `2026_04_21_unify_hr_source_of_truth.sql`
- `2026_04_21_unify_phase1b_compat_cols.sql`
- `2026_04_21_unify_phase2_archive_legacy.sql`
- `2026_04_21_work_mode.sql`

Important: production schema already contains most objects/columns from these migrations. Do not run pending migrations blindly. Phase 1 must reconcile migration state against actual schema and explicitly classify each file as:

- already applied manually,
- safe idempotent migration to run,
- unsafe legacy migration requiring review,
- obsolete migration to supersede.

The highest-risk file is `2026_04_21_unify_phase2_archive_legacy.sql` because it can rename/archive legacy tables used by shared systems.

## Verification Run On Production

Passed:

- `GET https://hr.tp-asset.com/api/v1/ping`
- PHP syntax check across TP-HR with `/opt/plesk/php/8.4/bin/php -l`
- PHP syntax check across TP-HR with `/opt/plesk/php/8.3/bin/php -l`
- `tests/attendance_service_test.php`
- `tests/payroll_service_test.php`
- `tests/settings_service_test.php`

Not yet covered:

- Authenticated browser visual QA.
- Real employee check-in mutation.
- Real leave request mutation.
- Payroll recalculation against a production payroll run.
- API authenticated smoke tests, because production currently has no `hr_api_keys`.

## Deploy And Rollback Checklist

Before deploy:

1. Confirm local branch is clean except intentional changes.
2. Build Tailwind locally.
3. Run PHP syntax check locally with XAMPP PHP.
4. Run unit/regression fixtures locally.
5. Run production read-only preflight against this baseline.
6. Confirm production dirty files will not be overwritten unexpectedly. Current known HR production dirty item is `push-deploy.sh` file mode.
7. Do not run pending migrations until Phase 1 migration reconciliation is complete.

Deploy:

1. Use `./push-deploy.sh "message"` only after the above checks pass.
2. Do not include `.env`, uploads, storage documents, logs, vendor or node modules in rsync deploy.
3. Confirm deployed URL and app path match this baseline.

After deploy:

1. `GET /api/v1/ping`.
2. Check PHP error logs and deploy log.
3. Smoke test login/session via browser.
4. Smoke test employee dashboard, check-in screen, leave screen, payslip screen, profile screen.
5. Smoke test HR dashboard, attendance, employee list, documents.
6. For payroll-related changes, calculate a preview only before touching real payroll runs.

Rollback:

1. Re-deploy previous git commit to `/var/www/vhosts/tp-asset.com/hr.tp-asset.com`.
2. If a DB migration was involved, rollback must be planned per migration before running it. No destructive migration should be run without a restore point.
3. Keep a DB backup/restore timestamp for any Phase 1+ schema change.

## Phase 0 Result

Production is not missing the HR/payroll base schema. The local DB gap must not be used as the production assessment. The immediate Phase 1 priority is migration-state reconciliation and a production preflight script, not creating base HR tables.
