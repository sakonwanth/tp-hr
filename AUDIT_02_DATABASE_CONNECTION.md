# AUDIT_02_DATABASE_CONNECTION.md

Date: 2026-04-30  
Mode: Read-only audit (production DB not modified)
Project: `tp-hr`

## 1) Configuration source audit

Code source:
- `config/database.php`
- `core/Database.php`
- `bootstrap.php` (env loading + tp-common fallback)

Findings:
- Driver: `mysql` (PDO)
- DB config constants are env-driven (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`)
- Charset init command: `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`
- Error mode: `PDO::ERRMODE_EXCEPTION`
- Prepared statement emulation: disabled (`ATTR_EMULATE_PREPARES=false`)

## 2) Real production connection (masked)

Validated on server (`/opt/plesk/php/8.4/bin/php`, read-only):

- `DB_NAME`: `tp_crm`
- `DB_HOST`: `lo*****st`
- `DB_PORT`: `3306`
- `DB_USER`: `tp******db`
- `DB_PASS`: masked
- `DB_CHARSET`: `utf8mb4`
- DB engine/version: `MariaDB 10.11.16`
- `APP_ENV`: `production`
- `APP_DEBUG`: `false`

## 3) Local vs production mismatch check

Local `.env` (masked):
- `APP_ENV=de***nt`
- `APP_DEBUG=tr***ue`
- `DB_USER=ro***ot`

Production runtime (masked):
- `APP_ENV=production`
- `APP_DEBUG=false`
- `DB_USER=tp******db`

Assessment:
- Environment separation is present and expected.
- Main risk is accidental use of local-only assumptions (debug or root privileges) in production scripts.

## 4) Migration status audit

Migration files in repo (`database/migrations`): `9` files.

Tracked in production `_migrations_run`: table exists, but several `2026_04_21_*` files are not tracked there while schema elements already exist.

Evidence from strict preflight:
- `scripts/production_preflight.php --strict` => PASS (`0 failure, 0 warning, 70 ok`)
- Reconciliation classification marks several migrations as already-applied schema / obsolete and intentionally not re-run.

Assessment:
- Schema is operationally consistent.
- Migration bookkeeping is partially reconciled-by-policy instead of strictly linear file tracking.

## 5) Table/column/index/FK presence

Production metadata snapshot:
- Total tables in shared DB: `201`
- Foreign keys: `217`
- Index definitions: `755`

HR-domain tables verified present (sample):
- `users`, `roles`, `system_settings`, `hr_settings`
- `hr_attendances`, `hr_attendance_adjustments`, `hr_attendance_outside_requests`
- `hr_leave_requests`, `hr_leave_types`, `hr_leave_entitlements`
- `hr_document_requests`, `hr_document_templates`, `hr_issued_documents`
- `hr_api_keys`, `hr_api_request_logs`
- `payroll_runs`, `payroll_slips`, `payroll_slip_tokens`
- `cross_domain_tokens`, `ot_requests`

## 6) Collation/charset

- Tables audited in scope are `utf8mb4_unicode_ci`.
- Connection init also enforces `utf8mb4_unicode_ci`.
- No blocking charset mismatch found in read-only checks.

## 7) Nullable/default/type risks

Read-only metadata review found no blocker-level type mismatch in required columns from preflight contract.

Operational notes:
- Some compatibility behavior is guarded in code for legacy/shared schema (e.g., holiday/date fallback, planned-late columns check).
- These guards reduce breakage risk across `tp-hr`, `tp-crm`, and `tp-checkin`.

## 8) Conclusion

- Real production DB connection is correct and active.
- Critical schema contract for current flows passes strict gate.
- Remaining risk is governance/process (migration tracking consistency), not immediate runtime correctness.

