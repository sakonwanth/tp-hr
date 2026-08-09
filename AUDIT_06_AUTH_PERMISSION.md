# AUDIT_06_AUTH_PERMISSION.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## 1) Authentication audit

Components:
- Session auth: `core/Auth.php`
- Shared SSO: `TpCommon\Auth\SsoGuard` (when available)
- Shared session: `TpCommon\Session\SharedSession`
- API key auth: `core/ApiAuth.php` for `/api/v1/*`

Observed production runtime:
- `APP_ENV=production`, `APP_DEBUG=false`
- `TP_COMMON_AVAILABLE=true`
- SSO path enabled

## 2) Role model discovered

- Roles from DB: `Admin`, `Chairman`, `CEO`, `Manager`, `Staff`, `HR`
- HR dashboard gate: `hr_can_access_hr_dashboard()`
- CEO-only gate: `isCEOOrAbove()`
- API scopes for machine access in `hr_api_keys.scopes`

## 3) Route permission checks

### Web pages
- Root employee pages: `Auth::requireLogin()` enforced.
- HR pages: all `hr/*.php` enforce login and HR/CEO gates as needed.
- CEO-only pages confirmed:
  - `/hr/reports.php`
  - `/hr/settings.php`
  - `/hr/dayoff_approvals.php`
  - `/hr/attendance_adjustments.php`
  - `/hr/api_keys.php`

### APIs
- `/api/*.php`: mostly session-authenticated (`Auth::requireLogin` or `Auth::check`) + CSRF for write actions.
- `/api/v1/*`: Bearer key + scope + method enforcement via `ApiAuth`.

## 4) Direct URL access audit

Evidence:
- Guest protected-route E2E suite passed (`30/30`) on production base URL.
- Unauthenticated API guest tests return expected `401` on protected endpoints.

Result:
- No blocker mis-exposed protected HR/employee route found in audited set.

## 5) Access anomalies / flags

| Area | Finding | Severity | Note |
|---|---|---|---|
| `/webhook.php` | Public endpoint by design, protected only by HMAC secret | High | Functional but high operational risk if secret leaked/replayed |
| `/api/health.php` | Public no-auth endpoint | Low | Expected health behavior |
| `/login.php` POST | No CSRF token on login form | Medium | Login CSRF class risk (lower impact than authenticated CSRF) |

## 6) API authorization posture

- Scope model is granular and enforced (`read`, `read_all`, `write`, `approve`, `*`).
- Additional guard for employee-scoped keys (`service_user_id`) is present.
- Request logging to `hr_api_request_logs` is active.

## 7) Verdict

- Core authentication and route permission controls are in place and functioning.
- No critical role bypass detected in audited routes.
- Hardening recommended for webhook exposure and login CSRF posture.

