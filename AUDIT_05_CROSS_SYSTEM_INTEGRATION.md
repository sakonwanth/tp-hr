# AUDIT_05_CROSS_SYSTEM_INTEGRATION.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## Integration matrix

| Source | Target | Data exchanged | Trigger | Table(s) | API/Path | Risk | Fix recommendation |
|---|---|---|---|---|---|---|---|
| `tp-hr` | `tp-common` | env/session/ACL/logging adapters | bootstrap on every request | n/a (library) | `bootstrap.php`, `Auth::acl()` | Medium | Keep dependency version pinned and integration tests on login/ACL. |
| `tp-hr` | `tp-crm` | shared auth redirect URL | unauthenticated access | shared session + users | `Auth::requireLogin()`, `SsoGuard` | High | Add SSO availability monitor and failover message policy. |
| `tp-hr` | `tp-crm` | shared identity and role data | all auth/HR pages | `users`, `roles` | direct DB read/write | High | Treat users/roles schema as shared contract; change via migration governance only. |
| `tp-hr` | `tp-crm` | payroll data ownership | payslip/report/payroll APIs | `payroll_runs`, `payroll_slips`, `employee_salary_setup` | `payslip.php`, `api/v1/payroll*` | High | Add contract tests for payroll statuses and field set before deployment. |
| `tp-hr` | `tp-crm` | LINE notification events | leave/planned-late actions | `hr_leave_requests`, settings | `core/CrmLineNotifierBridge.php` -> CRM modules | High | Add health check endpoint for notifier bridge load and event delivery diagnostics. |
| `tp-crm` | `tp-hr` | cross-domain login token | LINE login callback | `cross_domain_tokens` | `/api/line_login.php` | High | Add token replay telemetry and stricter expiry alerting. |
| `tp-hr` | `tp-checkin` | attendance photo paths / storage conventions | checkin/out and review screens | file path references in DB | `attendancePhotoPublicUrl()`, `/api/checkin_storage_image.php` | Medium | Set explicit `CHECKIN_APP_URL`/`CHECKIN_STORAGE_PATH` in prod for deterministic behavior. |
| `tp-hr` | `tp-checkin` | shared attendance/leave/dayoff semantics | daily operations + cron | `hr_attendances`, `hr_leave_*`, `hr_dayoff_requests` | shared DB | High | Keep preflight strict as release gate for compatibility columns and enums. |
| `tp-hr` | `tp-asset` host stack | vhost/deploy topology | runtime + deploy | shared filesystem layout | `/var/www/vhosts/tp-asset.com/*` | Medium | Document path dependencies and enforce permissions baseline in deploy script. |
| `tp-hr` | GitHub | webhook-triggered deploy | push event (`main`) | deploy logs only | `/webhook.php` | High | Move to CI/CD runner token + signed deploy job; avoid direct `git pull` over public webhook endpoint. |
| External systems | `tp-hr` API v1 | HR/payroll/attendance machine APIs | Bearer API key calls | `hr_api_keys`, `hr_api_request_logs` + domain tables | `/api/v1/*` | High | Add key rotation policy, minimum expiry, and deny-by-default scopes. |
| `tp-hr` | `tp-erp` | direct integration not found in code | n/a | n/a | none | Low | If required, define explicit adapter/API contract first (currently absent). |

## Shared domain consistency checks (from code + DB)

1. Shared users/employees: present, role-based.
2. Shared attendance: present via shared tables and API v1.
3. Shared approvals: leave/dayoff/outside/adjustments status links present.
4. Shared financial data: payroll tables shared and readable by HR APIs.
5. Duplicate data source risk:
   - Attendance policies live in multiple apps (`tp-hr`, `tp-checkin`, CRM cron).
   - Requires strict schema and business-rule versioning.

## Missing sync / conflicting rule risks

- `CHECKIN_STORAGE_PATH`/`CHECKIN_APP_URL` not explicitly set in current production runtime snapshot (fallback logic is used).
- Migration bookkeeping is policy-reconciled (schema exists but some files not tracked in `_migrations_run`), which can confuse future deploy automation if undocumented.
- Deploy webhook bypasses staged CI gates when invoked directly.

## Integration verdict

- Core cross-system integration is operational.
- Main production risks are governance and deployment-control related, not immediate data corruption.

