# AUDIT_10_ERROR_LOGGING.md

Date: 2026-04-30  
Mode: Read-only audit  
Project: `tp-hr`

## 1) Error handling patterns

Observed patterns:
- `try/catch` broadly used in APIs, services, and admin write flows.
- Central helper `tpHrLogException()` logs context/file/line and optional stack trace when debug is enabled.
- User-facing responses generally avoid exposing raw SQL/stack traces in production.

## 2) Validation error handling

- Form/API validation errors return:
  - page flow: `flash('error')` + redirect
  - API flow: JSON `{success:false,error:...}` with 4xx status

Status: PASS

## 3) Database/API/file errors

- DB exceptions commonly caught and mapped to generic error messages.
- Upload helper returns structured business-language errors.
- API v1 catches unhandled throwables in router and returns `500 Internal server error`.

Status: PASS

## 4) Permission and auth errors

- Session-auth failures:
  - page requests redirected to login/SSO
  - AJAX/API calls return `401/403`
- Role-deny paths in HR pages return flash errors and redirect.

Status: PASS

## 5) 404/500 behavior

- API v1 unknown endpoint => explicit `404`.
- Internal APIs mostly return `400` for invalid action.
- No custom global 404 page framework; file-based routing handles misses by web server config.

Status: ACCEPTABLE

## 6) Logging and audit trail

- App logs:
  - PHP error logs (`logs` / fallback `storage/logs`)
  - deployment logs (`storage/logs/deploy.log`)
  - cron logs (`storage/logs/*.log`)
- Audit trail:
  - `hr_audit_logs` for user actions
  - `hr_api_request_logs` for API v1 requests

Status: PASS

## 7) Findings

| ID | Finding | Severity | Recommendation |
|---|---|---|---|
| LOG-01 | Logging is distributed across multiple files without unified correlation id | Medium | Add request-id propagation across web/API/cron logs |
| LOG-02 | Webhook deploy output is returned to caller and logged; can leak operational details | Medium | Return minimal response body; keep details server-side only |

## 8) Verdict

- Error handling baseline is mature enough for production use.
- Observability can be improved by correlation IDs and tighter webhook response hygiene.

