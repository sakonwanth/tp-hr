# AUDIT_08_API_SERVICE.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## 1) API inventory

Internal APIs (`/api/*.php`):
- `attendance.php`
- `leave.php`
- `certificate.php`
- `payslip.php`
- `profile.php`
- `line_login.php`
- `checkin_storage_image.php`
- `health.php`

External APIs (`/api/v1/*` via router):
- `employees`, `attendance`, `leave`, `dayoff-requests`, `overtime`,
  `outside-attendance`, `attendance-adjustments`,
  `payroll-runs`, `payslips`, `salary-setup`,
  `departments`, `positions`, `holidays`, `leave-types`,
  `employee-schedules`, `announcements`, `leave-entitlements`,
  plus `/ping`.

## 2) Contract checks

### Endpoint existence and method
- `/api/v1/index.php` dispatch and method gates present.
- `ApiAuth::requireMethod()` used in major v1 resource files.
- Invalid methods return `405` via API auth helpers.

### Payload and response shape
- Internal APIs: mostly JSON `{success, ...}` responses.
- v1 APIs: consistent `ApiAuth::success()` / `ApiAuth::fail()` envelopes.
- Error responses include status code and message, with controlled server-error wording.

### Auth and permission
- Internal APIs: session auth + role gates + CSRF on write.
- v1 APIs: Bearer key + scope checks + optional service-user scoping + IP/origin guards.

### Database impact
- Write-heavy endpoints:
  - `/api/attendance.php` (attendance + adjustments + deletes)
  - `/api/leave.php` (requests, approvals, entitlement updates)
  - `/api/certificate.php` (request lifecycle)
  - `/api/profile.php` (self CRUD)
  - `/api/v1/payroll_write.php` (run/slip/salary setup actions)

### Timeout/retry handling
- No centralized retry layer for DB/API operations; failures generally surface as immediate error.
- v1 rate-limit exists but is file-based and fail-open on FS issues.

## 3) External dependency audit

- LINE notification path depends on CRM filesystem bridge (`TP_CRM_PATH`).
- Production runtime confirms bridge path exists (`TP_CRM_AVAILABLE=true`).
- Check-in storage proxy endpoint depends on `CHECKIN_STORAGE_PATH`; currently unset in prod runtime snapshot (fallback URL mode used).

## 4) API risk findings

| ID | Finding | Severity |
|---|---|---|
| API-01 | File-based rate-limit in `ApiAuth` fails open if storage FS unavailable | Medium |
| API-02 | Webhook deploy path is outside ApiAuth and uses separate security model; high blast radius | High |
| API-03 | Internal API and page controllers mix business logic (limited service abstraction), increasing regression risk | Medium |

## 5) Regression evidence

- Guest protected-route/API E2E: PASS (`30/30` on production base URL run)
- Static guards:
  - `verify-native-shell-cache.sh` PASS
  - `verify-ios26-master-screen.sh` PASS
  - `verify-touch-targets.sh` PASS
- Production preflight strict: PASS (`0 failure, 0 warning`)

## 6) Verdict

- API surface is functional with strong baseline auth and scope control.
- Highest remaining concerns are operational hardening (webhook deployment model, rate-limit backend robustness).

