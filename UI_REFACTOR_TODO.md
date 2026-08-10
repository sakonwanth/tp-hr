# UI_REFACTOR_TODO.md — TP-HR Native HR Backlog

**Updated:** 2026-04-28  
**Rules:** Preserve **permissions**, **approval state machines**, **`PayrollService`**, **`AttendanceService`** outcomes, **API JSON** contracts.

---

## Priorities

| Tier | Audience |
|------|----------|
| **P0** | ESS daily: **`index.php`**, **`leave.php`**, **`payslip.php`**, **`profile.php`**, **`checkin.php`** |
| **P1** | HR ops: **`hr/employees.php`**, **`hr/leaves.php`**, **`hr/attendance.php`** |
| **P2** | CEO: **`hr/reports.php`**, **`hr/settings.php`**, **`hr/dayoff_approvals.php`**, **`hr/documents.php`** |

---

## P0 — Self-service excellence

| ID | Task | Acceptance |
|----|------|-------------|
| P0.1 | **`index.php`**: announcement cards — consistent height, truncation, pinned badge | Mobile readability |
| P0.2 | **`profile.php`**: enforce **explicit card shells** per section (personal, emergency, family, edu, …) | No logic change |
| P0.3 | **`leave.php`**: **group labels** visually distinct; primary submit **`min-height`≥56px** TP rule vs program | Accessible |
| P0.4 | **`payslip.php`**: amount blocks **tabular-nums**, section hierarchy, download area **demoted visually** (“export dominance” parity with tp-erp ethos) | User alone sees slips |
| P0.5 | **`checkin.php`**: align with **tp-checkin** thumb-zone patterns where shared mental model exists | Smoke test |

---

## P1 — HR admin surfaces

| ID | Task |
|----|------|
| P1.1 | **`hr/employees.php`**: **card grid** `@max-lg` preserving sort/filter semantics |
| P1.2 | **`hr/employee_form.php`**: accordion by contract/personal/account — **pure layout** |
| P1.3 | **`hr/leaves.php`**: sticky filter bar pending-only + **large approve/reject** |
| P1.4 | **`hr/attendance.php`**: constrain map + table — **pane scroll**, not viewport scroll bleed |

---

## P2 — Executive & governance

| ID | Task |
|----|------|
| P2.1 | **`hr/reports.php`**: top **summary KPI cards** stacking on narrow |
| P2.2 | **`hr/settings.php`**, **`hr/api_keys.php`**: destructive API key rotates — **confirmation modal upgrade** |
| P2.3 | **`hr/dayoff_approvals.php`**: mimic leave approval ergonomics |

---

## Cross-cutting

| ID | Task |
|----|------|
| X.1 | **Empty / loading**: template for HR lists (“ยังไม่มีรายการ…”) |
| X.2 | **Replace `alert()`** (if any) with accessible modal/toast aligned with Tier A |
| X.3 | **`mobileSidebar`**: aria + focus return to **`#mobileMenuBtn`** after close |

---

## Product backlog (outside pure refactor)

| Item | Notes |
|------|-------|
| **Organization chart route** | New feature |
| **Benefits enrollment page** | New feature |
| **Dedicated announcements archive** | New route **`/announcements.php`** consumer of same query |

---

## Regression checklist

Run `php scripts/qa_role_regression.php`. It is read only — GET requests and
file reads, no login, no database. Add `--no-http` to skip the network, or
`--cookie="tp_session=…"` from a browser signed in as ordinary staff to turn
two of the manual steps into real checks.

Every assertion below has been mutation-tested: break the guard and the check
goes red, restore it and the check goes green. A check that has never failed
proves nothing.

Automated:

- [x] HR vs non-HR sidebar visibility parity — A4, A5
- [x] CEO-only routes (`reports`, `settings`, `dayoff_approvals`) still blocked for staff — A3, B4–B6, and C1 with `--cookie`
- [x] Payslip PDF/download path untouched — A7, A8, B8
- [x] LINE login unaffected (`api/line_login.php`) — A6, B7

Still by hand, because no amount of source reading proves what a signed-in
person sees. The script prints these at the end of every run:

- [ ] Leave submit → HR approval row appears — M1
- [ ] Payslip downloads and prints correctly as an employee — M2
- [ ] LINE login round trip completes — M3
- [ ] Sidebar compared side by side, staff vs HR vs CEO — M4
- [ ] Typing a CEO-only URL as staff bounces with a message — M5

---

## Suggested execution order

1. **P0.2 profile** (visible win) → **P0.4 payslip privacy layout** → **P0.3 leave**  
2. **P1 employees + leaves**

---

*Statuses live with PRs — keep one subsystem per merge where possible.*
