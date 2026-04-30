# 04_FULL_UI_INVENTORY.md — Phase 4 (canonical pointer)

**PROJECT_TARGET:** `tp-hr`  
**Last sync:** **2026-04-30** · Shell **`/assets/css/native-shell.css?v=15`**

---

## Single source of truth

Exhaustive **route → file → purpose** table lives in:

**→ [`01_FULL_UI_INVENTORY.md`](01_FULL_UI_INVENTORY.md)**

That file lists:

- **§A** — Auth & public (login, verify, print helpers)  
- **§B** — Employee self-service (`ESS`) — `templates/header.php` + bottom **tab** shell when not `hr-*`  
- **§C** — HR admin (`HRA`) — no bottom tabs, contextual nav  
- **§D** — API / machine (out of UX refactor scope)  
- **§E** — Shell assets (`header`, `footer`, `component_registry`, CSS)

---

## Coverage rule (Phase 4)

| Layer | Must include |
|-------|----------------|
| Route | Every **browser** PHP entry that renders HTML for a user |
| Partial | Every **`modules/**`** form included by a parent route (named in **§B**) |
| HR | Every **`hr/*.php`** screen (incl. **CEO-only** e.g. `attendance_adjustments`) |
| Skip | `/api/**` JSON-only, `webhook.php`, `cron/**`, `tests/**`, `scripts/**` (contract-only QA) |

---

## Delta vs earlier exports

- **2026-04-30:** HR route **`hr/attendance_adjustments.php`** in **`01`** · shell **`?v=15`** · IOS26 waves **6–9** complete per **`06`** · **`08_VISUAL_QA_AFTER.md`** added for per-route **`07`/`08`** QA (next human gate).

Phase **5** mapping: **[`05_PAGE_TO_IOS26_PATTERN_MAP.md`](05_PAGE_TO_IOS26_PATTERN_MAP.md)** · progress: **[`06_IMPLEMENTATION_PROGRESS.md`](06_IMPLEMENTATION_PROGRESS.md)**.
