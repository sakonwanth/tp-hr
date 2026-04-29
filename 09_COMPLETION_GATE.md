# 09_COMPLETION_GATE.md — TP-HR IOS26 mission readiness

## Gate overview

| Layer | Status | Notes |
|-------|--------|-------|
| **Wave 1** — Shell, tokens, `native-shell.css?v=13`, component registry | **COMPLETE** | See `06_IMPLEMENTATION_PROGRESS.md` |
| **Wave 2** — ESS: `index.php`, `checkin.php`, `leave.php`, `modules/.../request_form.php`, `leave_history.php` | **REFACTORED** (markup/tokens) | Manual QA: `07_PAGE_REGRESSION_AFTER.md` |
| **Wave 3** — `hr/*.php` (13 admin pages) | **REFACTORED** (markup/tokens) | Manual QA: `07` |
| **Wave 4** — Remaining ESS (`dayoff_schedule`, `attendance_history`, `certificate`, `payslip`, `profile`; `checkin` parity sweep) | **REFACTORED** (markup/tokens) | Manual QA: **`07`** Wave 4 rows |
| **Full product / device gate** — viewport polish, overflow, nav vs CTA | **NOT PASSED** | Requires human QA on target breakpoints |

**Refactor discipline:** No intentional changes to auth, API contracts, form field names, or modal/JS hook IDs in Waves **2–4**.

---

## Metrics snapshot (qualitative)

| Metric | Value |
|--------|--------|
| Waves **1–3** shipped (per `06`) | Shell + ESS hot paths + **`hr/*`** stacks |
| Automated browser QA in CI | Not in scope for this rollout |
| Device viewport matrix | **Pending** (see `07`) |

---

## Next actions (in order)

1. Execute **`07_PAGE_REGRESSION_AFTER.md`** on Wave **2–4** routes (ESS + **`hr/*`**) — at least **375px** and one desktop width.
2. Log findings; fix blockers or ticket non-blocking polish.
3. Update **`08_FINAL_FULL_UI_AUDIT.md`** when the device/overflow/CTA matrix is actually run (closes the “audit gate” section there).

## Sign-off shorthand

| Claim | Allowed? |
|-------|------------|
| Merge/deploy UI refactors as in `06` | Yes, per normal release |
| “All `04` / `06` tasks for Waves **1–4** done” | Yes, if **`04`** / **`06`** say so |
| “Zero UI issues on all devices” | **No** until device QA is done or explicitly waived |

---

*Aligned with `06_IMPLEMENTATION_PROGRESS.md`: 2026-04-28 — Waves **1–4** refactor rows marked **REFACTORED** / **COMPLETE** (human device gate in `07` still **PENDING**).*
