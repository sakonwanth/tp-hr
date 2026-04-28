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

## Tier B — Mission-strict exhaustive gate (every state on every route) — **OPEN**

Strict interpretation of “every loading / empty / error / skeleton on every route independently verified”: requires **automated E2E** + manual device matrix. Not claimed **PASS** from repository-only QA.

| Criterion | Status |
|-----------|--------|
| Per-route automated visual regression | **Outstanding** |
| Skeleton loaders on every async block | **Partial** — `tpHrNativeLoadingHtml()` + `.tp-visually-hidden` on HR async modals (`employees`, `leaves`, `attendance` history) |
| Independent tablet viewport verification (listed heights × every page) | **Outstanding** |
| Smoke E2E (Playwright) | **Started** — `tests/e2e/` (health, login, guest redirect on `index.php` / `checkin.php` / `leave.php` / `hr/index.php`); see **`docs/E2E_PLAYWRIGHT.md`** |

**Backlog:** auth’d flows (storage state), visual regression pipeline, tablet viewport matrix.

---

## Delta this session (2026-04-28)

| Item |
|------|
| `native-shell.css` **v6**: **`.tp-visually-hidden`**; HR async modals use **`tpHrNativeLoadingHtml()`** + `role="status"` / `aria-busy` |
| **`@playwright/test`** + **`playwright.config.cjs`** + **`tests/e2e/`** (health + login smoke); **`docs/E2E_PLAYWRIGHT.md`** |
| `templates/header.php` + `login.php`: **`?v=6`** |
| Earlier: **v5** shell (`02` 35 components; **`.tp-native-table-shell`**; **`hr/employees.php`** pagination / table shell) |
| **`tests/e2e/protected-routes.spec.cjs`**: guest redirect smoke for dashboard / check-in / leave / HR index |

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
