# AUDIT_13_ISSUE_PRIORITY.md

Date: 2026-04-30  
Project: `tp-hr`

## Critical

No confirmed critical data-corruption or permission-bypass issue found in this read-only cycle.

## High

| Issue ID | System area | File path | Related route | Related table | Problem | Impact | Root cause | Fix recommendation | Risk if not fixed | Test checklist |
|---|---|---|---|---|---|---|---|---|---|---|
| HIGH-001 | Deployment security | `webhook.php` | `/webhook.php` | n/a | Public webhook can run deploy shell commands (`git pull`, `chown`) if secret is valid; no anti-replay | Unauthorized deploy trigger if secret leaks; operational compromise | Direct deploy from public endpoint | Move deploy trigger to CI runner + signed short-lived token; add replay nonce/timestamp validation | High operational/security blast radius | Invalid signature/replay/branch filter tests; ensure no shell execution on rejected requests |
| HIGH-002 | DB safety/runtime | `core/Helpers.php` | `/api/attendance.php` code path | `hr_attendances` | Runtime helper can execute `ALTER TABLE` during request handling (`ensurePlannedStartTimeColumns`) | Request-time schema lock/perf risk and privilege overreach | Compatibility patch embedded in runtime | Move schema reconciliation to migration-only path; remove runtime DDL | Potential outage during peak traffic | Regression test attendance actions after removing runtime DDL |

## Medium

| Issue ID | System area | File path | Related route | Related table | Problem | Impact | Root cause | Fix recommendation | Risk if not fixed | Test checklist |
|---|---|---|---|---|---|---|---|---|---|---|
| MED-001 | Auth CSRF hardening | `login.php` | `/login.php` | `users` | Login POST lacks CSRF token | Login CSRF class risk (session confusion) | Form built without token check | Add CSRF hidden field + verify on POST | Account/session confusion risk | Login success/fail with valid/invalid token |
| MED-002 | Upload consistency | `hr/document_templates.php` | `/hr/document_templates.php` | `hr_document_templates`, `users` | Direct `move_uploaded_file` path bypasses shared secure upload helper | Inconsistent validation surface | Legacy/direct upload handling | Refactor to unified `uploadFile()` validation policy | Edge-case file validation gaps | Upload matrix: valid/invalid mime/ext/payload |
| MED-003 | API resiliency | `core/ApiAuth.php` | `/api/v1/*` | `hr_api_keys`, `hr_api_request_logs` | Rate-limit storage fail-open if file backend unavailable | Reduced abuse resistance | defensive fail-open design | Add fallback in-memory/redis or fail-closed threshold mode | Burst abuse risk | Simulate storage permission failure and verify bounded behavior |
| MED-004 | Reporting performance | `hr/reports.php` | `/hr/reports.php` | attendance/leave tables | No explicit max date-range for heavy reports | Slow query risk at scale | Open range design | Add max range + async export for large windows | Performance degradation | Benchmark monthly/quarterly/yearly range query times |
| MED-005 | Integration determinism | `config/app.php`, `core/Helpers.php` | photo/document views | file path refs | `CHECKIN_APP_URL`/`CHECKIN_STORAGE_PATH` unset in prod runtime snapshot (fallback behavior used) | Possible environment drift / broken image retrieval on infra change | Config not explicit | Set explicit production env values and monitor | Intermittent integration failures | Verify image URLs and proxy endpoint after env set |

## Low

| Issue ID | System area | File path | Related route | Related table | Problem | Impact | Root cause | Fix recommendation | Risk if not fixed | Test checklist |
|---|---|---|---|---|---|---|---|---|---|---|
| LOW-001 | Logging observability | multiple | all | logs | No universal request correlation ID | Slower incident triage | fragmented logging | Add request-id and propagate through logs | Operational friction | Verify same request-id across web/API/audit logs |
| LOW-002 | Migration governance | `database/migrations`, `_migrations_run` | deploy process | `_migrations_run` | Some schema present but migration file tracking reconciled by policy (non-linear) | Confusion in future automation | historical/manual reconciliation | Document accepted exceptions and add automated reconciliation report | Process risk only | Run strict preflight + migration drift report in CI |

