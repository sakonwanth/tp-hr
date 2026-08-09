# AUDIT_11_SECURITY.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## 1) CSRF

- Strong coverage on state-changing forms/APIs (`verifyCsrfToken` / `verifyCsrf`).
- Gap: login form (`/login.php`) has no CSRF token.

Result: **Medium finding** (login CSRF class risk).

## 2) XSS

- Most rendered user/content fields are escaped with `htmlspecialchars`.
- JSON-embedded tokens/values commonly encoded with `json_encode(...JSON_HEX_*)`.

Result: No blocker-level reflected XSS found in sampled high-risk routes.

## 3) SQL injection

- Primary query style uses prepared statements.
- Dynamic SQL in helper (`generateRunningNumber`) validates table/column names with strict regex before interpolation.

Result: No direct SQLi blocker detected in audited paths.

## 4) Mass assignment

- Procedural explicit field mapping is used; no ORM mass-assignment pattern present.

Result: PASS.

## 5) File upload security

- Secure upload helper performs MIME/signature/extension checks.
- Some code paths use direct `move_uploaded_file()` in template admin flow.

Result: **Medium finding** (consistency hardening required).

## 6) Direct object reference (IDOR)

- Self-service APIs check ownership (`...WHERE id=? AND user_id=?`).
- Payslip download enforces current user ownership and allowed payroll status.

Result: PASS in audited critical paths.

## 7) Role bypass

- HR and CEO gates are explicit on admin routes.
- Guest protected-route/API tests pass.

Result: PASS (no blocker bypass found).

## 8) Session security

- Session cookie sets `httponly`, `samesite=Lax`, `secure` when HTTPS.
- Session ID regeneration on successful login.
- Idle-timeout handling in fallback session mode.

Result: PASS.

## 9) Sensitive data exposure

- Production runs `APP_DEBUG=false`.
- API/server errors are mostly sanitized.
- `.env` exists in app root (expected), must rely on webserver deny rules.

Result: Low-medium operational risk; keep hard webserver deny on dotfiles.

## 10) Debug mode / env exposure

- Production runtime confirmed `APP_ENV=production`, `APP_DEBUG=false`.

Result: PASS.

## 11) Public storage/admin route exposure

- Admin routes protected.
- Checkin storage image proxy restricts role + path allowlist + MIME blocklist.

Result: PASS.

## 12) Additional high-risk surface

| ID | Finding | Severity |
|---|---|---|
| SEC-01 | `webhook.php` executes deploy (`git pull`, `chown`) from public endpoint protected only by shared secret | High |
| SEC-02 | Runtime schema auto-alter helper (`ensurePlannedStartTimeColumns`) can issue `ALTER TABLE` during request handling | High |
| SEC-03 | Login form missing CSRF token | Medium |
| SEC-04 | Upload handling inconsistency (direct move in one admin flow) | Medium |

## 13) Security verdict

- Core authz/authn and data-path protections are solid.
- Must harden deployment endpoint and remove runtime DDL in request path before high-assurance deployment posture.

