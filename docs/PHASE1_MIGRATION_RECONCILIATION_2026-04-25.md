# Phase 1 - Migration Reconciliation And Preflight

Date: 2026-04-25  
Baseline: `docs/PRODUCTION_BASELINE_PHASE0_2026-04-25.md`

## Objective

Phase 1 protects production deploys from schema drift and unsafe migration execution. The production database already contains the HR/payroll base schema, but `_migrations_run` does not reflect several TP-HR migration files. The correct response is reconciliation, not blindly running pending migrations.

## Deliverable

Added a read-only deploy gate:

```bash
php scripts/production_preflight.php
php scripts/production_preflight.php --strict
```

What it checks:

- Required production tables exist.
- Required columns for shared HR/payroll contracts exist.
- Required indexes exist.
- Important status enum values exist.
- Required payroll/attendance/worktime settings exist.
- Known optional compatibility gaps are reported as warnings.
- Pending TP-HR migrations are classified against the real production schema.

Exit behavior:

- Default mode exits `0` when there are no failures. Warnings are allowed.
- `--strict` exits `1` if there are warnings or failures.

## Production Preflight Result

Executed against production by piping the local script over SSH; no production files were changed.

Result:

- Failures: `0`
- Warnings: `9`
- OK checks: `69`

Current warnings:

| Warning | Action |
|---|---|
| `hr_attendances.planned_cancelled_at` missing | Keep guarded; current TP-HR code does not require it directly. |
| `hr_employee_schedules.shift_id` missing | Do not assume per-user shift in this table unless schema is extended intentionally. |
| `hr_employee_schedules.is_active` missing | Treat schedules as active by existence unless schema is extended intentionally. |
| `hr_holidays.holiday_date` missing | Use `date`; only fallback code should probe `holiday_date`. |
| `system_settings.USE_TPHR_PAYROLL` missing | CRM payroll API mode is not explicitly enabled. Keep fallback/default behavior. |
| `system_settings.hr_late_request_cutoff_hour` missing | Code must use default/fallback. |
| `system_settings.payroll_planned_grace_minutes` missing | Code must use default/fallback. |
| `hr_api_keys` empty | Authenticated external API smoke tests require creating a scoped production key. |
| `2026_04_21_unify_hr_source_of_truth.sql` obsolete/unsafe | Do not run; source table is already archived. |

## Migration Classification

`scripts/run_migrations.php --pending` reports 6 pending TP-HR migrations on production. Reconcile them as follows:

| Migration | Production reality | Classification | Action |
|---|---|---|---|
| `2026_04_21_external_api.sql` | `hr_api_keys` and `hr_api_request_logs` exist with required columns/indexes. | Already applied manually/schema-present | Do not run. After approval, mark as applied in `_migrations_run`. |
| `2026_04_21_probation_salary.sql` | `users.probation_salary` exists. | Already applied manually/schema-present | Do not run. After approval, mark as applied. |
| `2026_04_21_work_mode.sql` | `users.work_mode` and `idx_work_mode` exist. | Already applied manually/schema-present | Do not run. After approval, mark as applied. |
| `2026_04_21_unify_phase1b_compat_cols.sql` | `hr_attendances.late_excused`, `late_excused_reason`, `late_notified_at` exist. | Already applied manually/schema-present | Do not run. After approval, mark as applied. |
| `2026_04_21_unify_hr_source_of_truth.sql` | `attendance_logs` is gone; `attendance_logs_legacy` exists. | Obsolete/unsafe | Do not run. Supersede or mark with explicit reconciliation note only after approval. |
| `2026_04_21_unify_phase2_archive_legacy.sql` | Legacy tables are already archived: `attendance_logs_legacy`, `leave_requests_legacy`, `leave_types_legacy`, `leave_balances_legacy`, `attendance_monthly_summary_legacy`. | Already archived/obsolete | Do not run again. Supersede or mark with explicit reconciliation note only after approval. |

## Why Blind Migration Execution Is Unsafe

The migration runner only checks filenames in `_migrations_run`. Production schema has been changed outside this TP-HR migration folder, so pending filenames do not mean pending schema.

Risks:

- Duplicate-column failure on additive migrations that are already applied manually.
- Backfill migration failure because `attendance_logs` has already been renamed.
- Legacy archive migration can rename active shared tables in a different environment.
- `_migrations_run` is shared with other project migrations and contains CRM migration names, so it is not a clean TP-HR-only history.

## Recommended Reconciliation Procedure

Do not execute this procedure until the team approves a DB metadata write.

1. Take a DB backup or confirm a recent restore point.
2. Run:

```bash
php scripts/production_preflight.php
```

3. Confirm there are `0` failures.
4. Record reconciliation as metadata only. Do not execute the SQL bodies of the six pending migrations.
5. Use `INSERT IGNORE` into `_migrations_run` only for approved filenames, with a deployment note outside the DB explaining that the schema was already present.
6. Re-run:

```bash
php scripts/run_migrations.php --pending
php scripts/production_preflight.php --strict
```

7. If strict still warns only about accepted optional gaps, document those exceptions before deploy.

Suggested metadata-only SQL after approval:

```sql
INSERT IGNORE INTO _migrations_run (filename) VALUES
('2026_04_21_external_api.sql'),
('2026_04_21_probation_salary.sql'),
('2026_04_21_work_mode.sql'),
('2026_04_21_unify_phase1b_compat_cols.sql'),
('2026_04_21_unify_hr_source_of_truth.sql'),
('2026_04_21_unify_phase2_archive_legacy.sql');
```

This should be treated as reconciliation metadata, not as proof that the SQL files were executed by the runner.

## Deploy Gate For Future Work

Before deploying any code change:

```bash
/Applications/XAMPP/xamppfiles/bin/php -l scripts/production_preflight.php
ssh root@crm.tp-asset.com 'cd /var/www/vhosts/tp-asset.com/hr.tp-asset.com && /opt/plesk/php/8.4/bin/php scripts/production_preflight.php'
```

For pre-deploy checks before the script itself has been deployed, pipe it over SSH:

```bash
ssh root@crm.tp-asset.com 'cd /var/www/vhosts/tp-asset.com/hr.tp-asset.com && /opt/plesk/php/8.4/bin/php' < scripts/production_preflight.php
```

Use strict mode when a change is expected to remove all known warnings:

```bash
ssh root@crm.tp-asset.com 'cd /var/www/vhosts/tp-asset.com/hr.tp-asset.com && /opt/plesk/php/8.4/bin/php scripts/production_preflight.php --strict'
```

## Phase 1 Next Actions

1. Decide whether to mark the six migration files as applied metadata-only.
2. Decide whether to add optional compatibility columns:
   - `hr_attendances.planned_cancelled_at`
   - `hr_employee_schedules.shift_id`
   - `hr_employee_schedules.is_active`
   - `hr_holidays.holiday_date`
3. Decide whether to create a scoped production `hr_api_keys` record for authenticated API smoke tests.
4. Keep payroll API mode disabled until CRM payroll switching is tested with a non-mutating preview.
