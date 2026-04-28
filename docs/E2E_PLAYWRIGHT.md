# Playwright E2E (tp-hr)

Smoke tests live in `tests/e2e/`. They assume the app is served locally (e.g. XAMPP).

## Recommended automation scope (complete)

The following **Tier B “automation tranche”** is implemented in-repo (see also **`09_COMPLETION_GATE.md`**):

| Area | Implementation |
|------|------------------|
| Guest API + UI smoke | `health`, `login`, `protected-routes` on **chromium** + **tablet** (`iPad Mini`) unless skipped |
| Authenticated smoke | **`chromium-auth`** + **`tablet-auth`** (reuse `storageState` after `auth.setup`) |
| Optional HR dashboard assert | **`PLAYWRIGHT_HR_EXPECT_ADMIN=1`** on `authenticated.spec.cjs` (both auth projects) |
| Opt-in snapshots (guest login + auth dashboard) | **`PLAYWRIGHT_VISUAL=1`** — **`visual-login.spec.cjs`** (`.login-card`) + **`visual-dashboard.spec.cjs`** (`.dashboard-hero`) |

What remains **outside** this tranche—by definition—is **manual** device QA on every interaction state (“every loading/error on every route”) and discretionary visual breadth beyond login + dashboard. That is tracked as **Tier B strict** in the gate doc, not blocking the automation deliverable above.

## Prerequisite

- Apache/PHP serving **`tp-hr`** (typical base URL: `http://127.0.0.1/tp-hr` or `http://localhost/tp-hr`).

## Setup

```bash
cd /path/to/tp-hr
npm install
npx playwright install chromium
```

## npm scripts

| Script | Behaviour |
|--------|-----------|
| `npm run test:e2e` | Full suite (guest phone + tablet; auth + visuals if env vars enabled). |
| `npm run test:e2e:ci` | Guest + auth **without** tablet projects (`PLAYWRIGHT_SKIP_TABLET=1`) — quicker for CI. |
| `npm run test:e2e:visual` | Same as **`PLAYWRIGHT_VISUAL=1 npm run test:e2e`**. |

## CI (GitHub Actions)

**`.github/workflows/ci.yml`** runs **`npm ci`**, **`npx playwright install chromium`**, and **`npx playwright test --list`** so a broken config or bad imports fail the build **without** a running PHP server. Full browser E2E against XAMPP remains a local (or integration host) step with **`PLAYWRIGHT_BASE_URL`** and optional auth env.

## Authenticated flows (optional env)

When **`PLAYWRIGHT_HR_USER`** and **`PLAYWRIGHT_HR_PASSWORD`** are set (aliases: **`E2E_HR_USERNAME`** / **`E2E_HR_PASSWORD`**):

1. **`tests/e2e/auth.setup.cjs`** — POST login on **`login.php`**, assert **`h1.dashboard-hero-title`**, save **`playwright/.auth/hr-user.json`** (gitignored).
2. **`tests/e2e/authenticated.spec.cjs`** — **`chromium-auth`** + **`tablet-auth`** (unless **`PLAYWRIGHT_SKIP_TABLET=1`**) use that session — dashboard hero plus employee-route title checks (**`checkin`**, **`leave`**, **`profile`**, **`payslip`**, **`attendance_history`**, **`leave_history`**, **`certificate`**, **`dayoff_schedule`**), optional **`hr/index.php`** when **`PLAYWRIGHT_HR_EXPECT_ADMIN=1`**.

Uses **password login on tp-hr** (same-origin). If SSO forces CRM before dashboard, setup times out — use a DB user that can finish login on **`tp-hr`** or omit auth env for guest-only runs.

```bash
export PLAYWRIGHT_HR_USER='your_user'
export PLAYWRIGHT_HR_PASSWORD='your_password'
npm run test:e2e
```

### HR admin dashboard (same user)

```bash
export PLAYWRIGHT_HR_EXPECT_ADMIN=1
```

## Tablet vs phone

- **`chromium`** / **`tablet`**: guest specs (Pixel 5 vs iPad Mini).
- **`chromium-auth`** / **`tablet-auth`**: **`authenticated.spec.cjs`** twice for layout parity after login.

Disable all tablet projects (guest + **`tablet-auth`** + **`visual-auth-tablet`**):

```bash
PLAYWRIGHT_SKIP_TABLET=1 npm run test:e2e
```

## Visual regression (opt-in, `PLAYWRIGHT_VISUAL=1`)

Snapshots are **off** unless **`PLAYWRIGHT_VISUAL=1`** so pipelines without PNG baselines do not fail.

**Guest login card**

```bash
PLAYWRIGHT_VISUAL=1 npx playwright test tests/e2e/visual-login.spec.cjs --update-snapshots
```

**Authenticated dashboard hero** (requires auth env + successful setup)

```bash
PLAYWRIGHT_VISUAL=1 npx playwright test tests/e2e/visual-dashboard.spec.cjs --update-snapshots
```

Commit **`tests/e2e/*.spec.cjs-snapshots/`** (per project subfolders such as **`chromium/`**, **`tablet/`**, **`visual-auth/`**, **`visual-auth-tablet/`**).

```bash
PLAYWRIGHT_VISUAL=1 npm run test:e2e
```

If **`/api/health.php`** returns **401**, the server may require **`HEALTH_CHECK_TOKEN`** — see health check docs or relax locally for E2E.

## Run defaults

```bash
npm run test:e2e
```

Base URL override (keep a **trailing slash**):

```bash
PLAYWRIGHT_BASE_URL=http://localhost/tp-hr/ npm run test:e2e
```

## What is covered

| Test / project | Coverage |
|----------------|----------|
| `health.spec.cjs` | `GET api/health.php` JSON (`status`, `project` when HTTP 200). |
| `login.spec.cjs` | `login.php` — submit control visible. |
| `protected-routes.spec.cjs` | Guest redirect to **`login.php`** for `index`, `checkin`, `leave`, `hr/index`. |
| `authenticated.spec.cjs` | Logged-in **`index`** hero + **`checkin`**, **`leave`**, **`profile`**, **`payslip`**, **`attendance_history`**, **`leave_history`**, **`certificate`**, **`dayoff_schedule`** titles; HR index when **`PLAYWRIGHT_HR_EXPECT_ADMIN=1`**. Projects: **`chromium-auth`**, **`tablet-auth`**. |
| `visual-login.spec.cjs` | **`.login-card`** on login (guest **chromium** + **tablet** when visual on). |
| `visual-dashboard.spec.cjs` | **`.dashboard-hero`** after login — **`visual-auth`**, **`visual-auth-tablet`**. |
