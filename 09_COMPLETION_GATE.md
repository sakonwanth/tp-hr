# 09_COMPLETION_GATE.md — TP-HR IOS26 mission readiness

## Gate overview

| Layer | Status | Notes |
|-------|--------|-------|
| **Shell + cache** — `native-shell.css` **v15**, **`?v=15`** everywhere shell loads | **COMPLETE** | `templates/header.php` · `login.php` · `verify_document.php` · `certificate_print.php` — authoritative table · **`06_IMPLEMENTATION_PROGRESS.md`** |
| **Waves 6–9** — ESS + HRA + public **`verify_document`** + **`certificate_print`** screen chrome | **REFACTORED** | Per **`06`**; auth, APIs, contracts unchanged intentionally |
| **Full product gate** — spacing, overflow, nav vs body, typography, tables→cards XS | **NOT PASSED** | **Human QA** · **`07_SPACING_QA.md`** + **`08_VISUAL_QA_AFTER.md`** + **`10_BROWSER_VIEWPORT_QA.md`** · dashboard **`03_MASTER_SCREEN_VISUAL_QA.md`** |

Older wave labels (**1–4**) and **`07_PAGE_REGRESSION_AFTER.md`** remain as historical breadcrumbs; **`07`** + **`08`** supersede regression-only runs for IOS26 fidelity.

---

## Metrics snapshot

| Metric | Value |
|--------|-------|
| Refactor waves per **`06`** | Shell **v15** + Waves **6–9** shipped |
| Automated browser/UI proof in CI | API / route smoke (`tests/e2e`) optional; **no substitute** for device matrix |
| Device viewport matrix | **Pending** — fill **`08`** matrices + **`10_BROWSER_VIEWPORT_QA.md`** log + **`03`** (`/`) |

---

## Next actions (in order)

1. Run **`08_VISUAL_QA_AFTER.md`** route matrices (**ESS**, **HRA**, **AUTH/PUB/print**) together with **`07_SPACING_QA.md`** global criteria · same sessions use **`10_BROWSER_VIEWPORT_QA.md`** breakpoints (375 / 390–430 / tablet for HRA).
2. Run **`03_MASTER_SCREEN_VISUAL_QA.md`** for **`index.php`** (**`/`**) when validating the master dashboard shell.
3. Log defects; fix **`native-shell`** / tokens before page‑one hacks; bump **`?v=`** if CSS changes ship.
4. Use **`DEPLOY_CHECKLIST.md`** before/after each deploy touching shell or broad markup.

**(Optional)** When QA is exercised end-to-end, add a dated note under **`06`** or annotate **`08_FINAL_FULL_UI_AUDIT.md`** if that audit file is still tracked for releases.

---

## Sign-off shorthand

| Claim | Allowed? |
|-------|----------|
| Merge/deploy per **`06`** + normal review | Yes |
| “IOS26 refactor waves in **`06`** are done” | Yes, matching **`06`** statuses |
| “Zero UI issues on all devices/viewports” | **No** until **`07`** + **`08`** + **`10`** (+ **`03`** for **`/`**) PASS or waived in writing |

---

*Aligned with `06_IMPLEMENTATION_PROGRESS.md` (**2026-04-30**): engineering waves **COMPLETE**/`REFACTORED`; product gate remains **human QA** via **`07`** / **`08`** / **`10`** / **`03`***
