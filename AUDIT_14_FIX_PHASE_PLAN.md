# AUDIT_14_FIX_PHASE_PLAN.md

Date: 2026-04-30  
Project: `tp-hr`

Rule: This file is a fix plan only. No fixes executed in this phase document.

## Phase 1 — Critical security and permission issues

Current critical findings: none confirmed.

Actions:
1. Keep continuous permission regression suite in CI (`protected-routes`, API guest auth).
2. Maintain production strict preflight as release gate.

## Phase 2 — Database relationship and data integrity issues

Targets:
- Eliminate runtime DDL from request path (`HIGH-002`).
- Keep reconciliation script as deploy-time-only tooling.

Tasks:
1. Move `ensurePlannedStartTimeColumns` behavior into migration/preflight.
2. Remove/disable request-time `ALTER TABLE` invocation.
3. Add migration drift report to CI.

## Phase 3 — Broken business flows

Targets:
- Login CSRF hardening (`MED-001`).
- Upload path consistency (`MED-002`).

Tasks:
1. Add CSRF token to login form and server-side validation.
2. Refactor template-upload code paths to use shared `uploadFile()` helper.
3. Regression test login + document template uploads.

## Phase 4 — Cross-system integration issues

Targets:
- Deterministic TP-Checkin path configuration (`MED-005`).

Tasks:
1. Set explicit `CHECKIN_APP_URL` and/or `CHECKIN_STORAGE_PATH` in production env.
2. Add startup health check to report integration mode (proxy vs URL fallback).
3. Validate image/document links across HR pages.

## Phase 5 — API/service issues

Targets:
- API rate-limit resilience (`MED-003`).

Tasks:
1. Replace file-only rate-limit with robust backend (Redis/DB bucket) or fail-closed fallback.
2. Add abuse simulation tests.
3. Add endpoint-level timeout and retry policy where external dependencies exist.

## Phase 6 — Reports/exports

Targets:
- Reporting window/performance safeguards (`MED-004`).

Tasks:
1. Add max range constraints or async export queue.
2. Add query-time telemetry and alert threshold.
3. Validate CSV correctness post-change.

## Phase 7 — Logging and monitoring

Targets:
- Unified observability (`LOW-001`) and webhook risk reduction (`HIGH-001`).

Tasks:
1. Add request correlation IDs across web/API/cron.
2. Harden webhook deployment model:
   - move to CI job dispatch
   - replay protection (timestamp/nonce)
   - minimal response body

## Phase 8 — Final regression test

Mandatory gates before release:
1. `production_preflight.php --strict` PASS
2. Static UI guards PASS
3. Guest and authenticated E2E PASS
4. Role/permission matrix PASS
5. Report/export and upload smoke PASS
6. API scope matrix PASS
7. No open High/Critical findings

