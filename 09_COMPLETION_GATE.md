# 09 — Completion gate (TP-HR native UI program)

**Program:** Full-system native-like UI for **tp-hr** (UI/UX only; business logic protected).  
**UI UX Pro Max alignment:** single font stack, tokenized spacing, touch ≥48px, primary CTA ≥56px, inputs ≥52px, scroll buffer ≥120px, bottom slot ≥88px when tab bar present.

---

## Tier A — Operational gate (release baseline) — **PASS**

| Criterion | Required | Actual |
|-----------|----------|--------|
| Full UI inventory exists (`01`) | Yes | **Yes** — 27 routes + partials; discovery documented |
| Component lock map complete (`02`) — no unnamed Native* leftovers | Yes | **Yes** — 35 components mapped → CSS/markup (**v5** registry) |
| Page audit snapshot (`03`) | Yes | **Yes** — shell + representative pages |
| Page refactor tasks (`04`) tracked | Yes | **Yes** — shell/tasks closed; backlog items marked ✅ |
| Native system documented (`05`) | Yes | **Yes** — tokens + components |
| Implementation progress (`06`) | Yes | **Yes** — routes marked **COMPLETE** at shell/token level |
| Regression matrix (`07`) | Yes | **Yes** — no auth/route/API breakage from UI work |
| Final audit synthesis (`08`) | Yes | **Yes** |
| `native-shell.css` version | Latest | **`?v=6`** in `header.php` + `login.php` |
| Skipped **functional** routes | 0 | **0** |
| Blocking layout defects (overlap / zero padding on `main`) | 0 | **0** (per `UI_CASCADE_BUGFIX.md` discipline) |

**Verdict Tier A:** **PASS** — production TP-HR can ship with this native shell + token system.

---

## Tier B — Automation tranche (Playwright + a11y patterns) — **COMPLETE**

This is the **recommended, automatable slice** of Tier B: guest + auth smoke on **phone and tablet**, optional HR route assert, opt-in **visual baselines** for login + dashboard hero, and **loading-state** patterns on key HR modals. Documented in **`docs/E2E_PLAYWRIGHT.md`**.

| Deliverable | Status |
|-------------|--------|
| Guest E2E (API, login shell, guest redirects) on **chromium** + **tablet** | **Done** — `tests/e2e/*.spec.cjs`; **`PLAYWRIGHT_SKIP_TABLET=1`** skips tablet |
| Authenticated E2E on **chromium-auth** + **tablet-auth** (`storageState` after `auth.setup`) | **Done** |
| Optional HR admin title check | **Done** — **`PLAYWRIGHT_HR_EXPECT_ADMIN=1`** |
| Opt-in visual snapshots (login card + dashboard hero) | **Done** — **`PLAYWRIGHT_VISUAL=1`** + `visual-login` / `visual-dashboard` |
| CI-friendly script | **Done** — **`npm run test:e2e:ci`** (tablet skipped) |
| Async modal loading UX (HR) | **Done** — `tpHrNativeLoadingHtml()` + `.tp-visually-hidden` (prior tranche) |

**Verdict (automation tranche):** **COMPLETE** for the scope above.

---

## Tier B — Strict exhaustive gate (every state × every route) — **OPEN**

Strict interpretation — *every* loading / empty / error / skeleton on *every* route independently verified, plus full manual device matrix — is **not** claimed **PASS** from repository-only automation. Use release sign-off + spot manual QA where needed.

| Criterion | Status |
|-----------|--------|
| Per-route / per-state automated coverage | **Open** — extend Playwright and visuals incrementally |
| Full physical device matrix | **Open** — outside repo |

**Backlog (non-blocking for Tier A / automation tranche):** additional snapshot baselines; more routes under auth; dedicated HR-only credentials in CI secrets if desired.

---

## Delta (E2E follow-up)

| Item |
|------|
| CEO E2E: **`PLAYWRIGHT_HR_EXPECT_CEO`** + guest **`hr/reports`**, **`hr/settings`**, **`hr/dayoff_approvals`**. CI unchanged. |

---

## Delta (E2E completion tranche)

| Item |
|------|
| **`tablet-auth`** project — **`authenticated.spec.cjs`** on **iPad Mini** (honours **`PLAYWRIGHT_SKIP_TABLET`**) |
| **`visual-dashboard.spec.cjs`** + **`visual-auth`** / **`visual-auth-tablet`** (deps on **`setup`**, opt-in **`PLAYWRIGHT_VISUAL`**) |
| **`npm run test:e2e:ci`**, **`npm run test:e2e:visual`** |
| **`docs/E2E_PLAYWRIGHT.md`** — “recommended scope complete” + tables |
| **`.login-card` / `.dashboard-hero`** snapshot workflow |

---

## Deliverables checklist

| File | Status |
|------|--------|
| `01_FULL_UI_INVENTORY.md` | Present |
| `02_COMPONENT_LOCK_MAP.md` | Present |
| `03_PAGE_AUDIT_BEFORE.md` | Present |
| `04_PAGE_REFACTOR_TODO.md` | Present |
| `05_NATIVE_COMPONENT_SYSTEM.md` | Present |
| `06_IMPLEMENTATION_PROGRESS.md` | Present |
| `07_PAGE_REGRESSION_AFTER.md` | Present |
| `08_FINAL_FULL_UI_AUDIT.md` | Present |
| `09_COMPLETION_GATE.md` | **This file** |
| `docs/E2E_PLAYWRIGHT.md` | Playwright + completion scope |
