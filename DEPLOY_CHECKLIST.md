# DEPLOY_CHECKLIST.md — TP-HR

**Purpose:** repeatable checks before / after deploying **`tp-hr`** (especially after **`native-shell.css`** token or IOS26 markup changes).

**Rolling status:** Reset `[ ]` when you cut a new release tag or deploy bundle; stamp dates when PASS.

---

## Pre-deploy (repo)

- [ ] **Shell cache bust:** `/assets/css/native-shell.css` uses **`?v=15`** in —  
  `templates/header.php`, `login.php`, `verify_document.php`, `certificate_print.php`.  
  If you bump the file for real, bump **`?v=` everywhere** together.
- [ ] **`app.css`** still linked ahead of **`native-shell.css`** where both load (`header.php`, login, verify).
- [ ] **Regression scope:** confirm PHP-only deploy (no unintended `database/migrations/*` in the same commit unless that release includes DB work).

**Authoritative IOS26 state:** [`06_IMPLEMENTATION_PROGRESS.md`](06_IMPLEMENTATION_PROGRESS.md) · **Manual QA:** [`07_SPACING_QA.md`](07_SPACING_QA.md) · [`08_VISUAL_QA_AFTER.md`](08_VISUAL_QA_AFTER.md) · master dashboard: [`03_MASTER_SCREEN_VISUAL_QA.md`](03_MASTER_SCREEN_VISUAL_QA.md) · **completion narrative:** [`09_COMPLETION_GATE.md`](09_COMPLETION_GATE.md).

---

## Post-deploy smoke (~15 min)

**ESS (logged-in employee, mobile width):**

- [ ] `/` dashboard — tabs + sticky CTA feel ok (see **`03`** if anything regresses).
- [ ] `/checkin.php` · `/leave.php` · `/profile.php` — one path each.
- [ ] `/payslip.php` list or empty state.

**HRA (HR user):**

- [ ] `/hr/index.php`
- [ ] One transactional page: e.g. `/hr/leaves.php` or `/hr/documents.php`

**Public / edge:**

- [ ] `/login.php` (unauth)
- [ ] `/verify_document.php` empty form (no leak of internal errors)

**Print (optional):** open certificate print preview from HR or ESS once; confirm toolbar + print dialog; A4 body still **Sarabun** on paper.

---

## Rollback

Revert the deploy commit that introduced CSS/markup regression; if only **`native-shell.css`** changed, restore previous file + matching **`?v=`** bump pattern.
