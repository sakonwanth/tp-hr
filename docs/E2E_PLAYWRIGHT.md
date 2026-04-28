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

## Authenticated flows (optional)

When **`PLAYWRIGHT_HR_USER`** and **`PLAYWRIGHT_HR_PASSWORD`** are set (aliases: **`E2E_HR_USERNAME`** / **`E2E_HR_PASSWORD`**), Playwright also runs:

1. **`tests/e2e/auth.setup.cjs`** — POST login on `login.php`, assert dashboard hero, save **`playwright/.auth/hr-user.json`** (gitignored).
2. **`tests/e2e/authenticated.spec.cjs`** — reuse session via `storageState` (dashboard + check-in + leave titles).

Uses **password login on tp-hr** (same-origin). If your environment forces **SSO-only** (redirect to CRM before the HR dashboard appears), the setup step will time out — use a local DB user with password auth or run guest-only E2E without these env vars.

```bash
export PLAYWRIGHT_HR_USER='your_user'
export PLAYWRIGHT_HR_PASSWORD='your_password'
npm run test:e2e
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
| `auth.setup.cjs` + `authenticated.spec.cjs` | Only when **`PLAYWRIGHT_HR_USER`** + **`PLAYWRIGHT_HR_PASSWORD`** are set — logged-in smoke (dashboard hero, page titles). |

If **`/api/health.php`** returns **401**, the server may require **`HEALTH_CHECK_TOKEN`** — pass `?token=` / header **`X-Health-Check-Token`** or temporarily relax config for local E2E.
