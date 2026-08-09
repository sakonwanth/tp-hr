# AUDIT_12_REAL_DB_TEST_PLAN.md

Date: 2026-04-30  
Mode: Plan only (no destructive execution)  
Project: `tp-hr`

## Goal

Verify full-flow correctness against real production DB with minimal risk.

## 1) Read-only verification queries (safe)

Run via read-only account/session where possible:

1. Connection/schema
   - `SELECT DATABASE(), VERSION();`
   - `SHOW TABLES LIKE 'hr_%';`
2. Referential integrity spot checks
   - orphan joins for leave/document/attendance/payroll (same as `AUDIT_03`)
3. Duplicate and status integrity
   - duplicates on attendance/date, entitlement key, dayoff week
   - status distribution grouped by table
4. Permission-critical records
   - role distribution in `users` + `roles`
   - active API keys count and latest API log timestamps
5. Runtime contract
   - `/opt/plesk/php/8.4/bin/php scripts/production_preflight.php --strict`

## 2) Safe sample test data requirement (for write-flow validation)

If write verification is required later:
- Use dedicated test users (clearly tagged), separate from real employees.
- Restrict to isolated test date ranges (e.g., future dates not used for payroll).
- Do not reuse real document numbers or payroll months used in accounting close.

## 3) Backup requirement (before any write test)

Minimum:
1. Logical backup of affected tables only.
2. Timestamped backup artifact + checksum.
3. Recovery dry-run on non-production target before write testing.

## 4) Rollback plan

For each tested flow, predefine rollback SQL by test entity key:
- leave requests, dayoff requests, document requests, attendance adjustments, API keys (test-only)
- rollback must be key-targeted (no wide delete/update)

## 5) Test account requirements

Prepare at least:
- 1 employee test account
- 1 HR test account
- 1 CEO test account
- 1 API key test account (scoped + non-scoped variants)

All accounts must be tagged in notes and removable after tests.

## 6) Test org-context requirements

If needed for business flows:
- dedicated test department/position
- test leave entitlement rows
- test shift/dayoff setup

Avoid touching real payroll-approved months.

## 7) Data cleanup plan

Post-test cleanup order:
1. Revoke test API keys
2. Revert/deactivate test users
3. Delete or archive test requests by explicit IDs
4. Validate no residual pending approvals
5. Re-run read-only integrity checks

## 8) What must never be tested directly on production

1. Mass updates/deletes without strict entity whitelist.
2. Truncate/reset operations.
3. DDL schema changes during peak hours.
4. Payroll run generation/approval on live accounting month without formal change window.
5. Webhook/deploy security experiments on live endpoint.

## 9) Recommended execution sequence

1. Read-only baseline capture.
2. Approval on test account/data plan.
3. Limited write tests in smallest-risk flow first.
4. Immediate cleanup + integrity recheck.
5. Sign-off with evidence bundle.

