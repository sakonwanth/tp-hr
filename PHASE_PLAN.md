# PHASE_PLAN.md — TP-HR Native HR UI Conversion

**Updated:** 2026-04-28  
**Status:** Planning artifacts ready — **`SCREEN_MAP.md`**, **`UI_NATIVE_APP_AUDIT.md`**, **`UI_REFACTOR_TODO.md`**. **No code changes executed in this documentation step.**

---

## Guiding constraints

| Source | Requirement |
|--------|--------------|
| `UI_RULES.md` / `UI_UX_STANDARD.md` | Touch 48+, inputs 52+, Tier A Plex shell |
| Integrity | Roles, approvals, payroll math untouched in UI-led phase |
| PWA hooks | **`manifest.webmanifest`**, icons — sanity check after layout shifts |

---

## Phase 1 — Inventory [complete]

Document routes + checklist mapping + gaps (**org chart**, **benefits**, standalone **announcements**).

---

## Phase 2 — Design tokens & ESS baseline

Establish shared **`.native-card`** / section heading pattern for **`profile.php`**, **`leave.php`**, **`payslip.php`** sections without touching controllers’ SQL.

Deliverable: Figma/sketch optional; code spike branch OK.

---

## Phase 3 — Home & ESS dashboard (`index.php`)

Announcements carousel/stack; reduce vertical noise for mobile KPI.

---

## Phase 4 — Leave self-service (`leave.php`)

Grouped fields + clearer balance summary + pending list.

---

## Phase 5 — Payslip confidentiality UX (`payslip.php`)

Layout emphasizing **private grouping** — no server query edits.

---

## Phase 6 — HR directory & forms (`employees`, `employee_form`, `employee_view`)

Table → responsive cards breakpoint strategy.

---

## Phase 7 — Approvals consoles (`hr/leaves.php`, optional `documents` pending UX)

Approve/reject button ergonomics.

---

## Phase 8 — Attendance admin heavy pages

Map/table scroll containment (Leaflet retained).

---

## Phase 9 — Reports & CEO settings

Cards + guarded destructive actions.

---

## Phase 10 — Full regression & accessibility

VoiceOver / Keyboard where feasible; **`prefers-reduced-motion`**.

---

## Rollback

Feature-flag not required — prefer **scoped CSS + DOM additions** reversible by git revert **per subsystem PR**.

---

## Post-ship docs

Mirror critical route updates in **`tp-common/UI_SCREEN_MAP.md`** § tp-hr table.

---

*Await stakeholder approval to execute Phase 2.*
