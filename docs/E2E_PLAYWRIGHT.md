# Playwright E2E (tp-hr)

Smoke tests live in `tests/e2e/`. They assume the app is served locally (e.g. XAMPP).

## Prerequisite

- Apache/PHP serving **`tp-hr`** (typical base URL: `http://127.0.0.1/tp-hr` or `http://localhost/tp-hr`).

## Setup

```bash
cd /path/to/tp-hr
npm install
npx playwright install chromium
```

## Run

```bash
# default base URL: http://127.0.0.1/tp-hr
npm run test:e2e
```

Override base URL if your vhost path differs:

```bash
PLAYWRIGHT_BASE_URL=http://localhost/tp-hr/ npm run test:e2e
```

Use a **trailing slash** so paths like `login.php` resolve under the project folder, not the web root.

## What is covered

| Test | Coverage |
|------|----------|
| `health.spec.cjs` | `GET /api/health.php` — JSON shape (`status`, `project` when 200). |
| `login.spec.cjs` | `login.php` — page visible + primary submit button present. |
| `protected-routes.spec.cjs` | `index.php`, `checkin.php`, `leave.php`, `hr/index.php` — unauthenticated session ends on a URL containing **`login.php`** (local HR login or CRM SSO). |

Auth-protected flows (HR dashboard, POST APIs) require storage state / credentials — not in smoke scope yet.

If **`/api/health.php`** returns **401**, the server may require **`HEALTH_CHECK_TOKEN`** — pass `?token=` / header **`X-Health-Check-Token`** or temporarily relax config for local E2E.
