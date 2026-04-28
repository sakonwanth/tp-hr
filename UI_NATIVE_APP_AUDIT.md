# UI_NATIVE_APP_AUDIT.md — TP-HR Native HR App

**Updated:** 2026-04-28  
**Scope:** `tp-hr` — **Tier A** self-service + HR admin. **Planning only.**

**Integrity rules (§10–13 user brief):** Do not alter RBAC helpers (`hr_can_access_hr_dashboard`, `isCEOOrAbove`, etc.), approval **SQL transitions**, **`PayrollService`** math, **`users`/HR table shapes** — unless approved backend milestone.

---

## 1. Product goals vs codebase

| Goal | Observation |
|------|---------------|
| **Feels native on phone** | Strong foundation: **`header-glass`**, **`mobileSidebar`** grid, **`touch-manipulation`**, Plex font. Tables still dominate some **HR admin** screens. |
| **Self-service simplicity** | **7-tile grid** covers core ESS — good IA. Profile is long-form — audit **card grouping**. |
| **Single “approval inbox”** | **Split**: leave approvals (`hr/leaves.php`), day-off (`dayoff_approvals.php`), documents — unify **presentation** optionally (navigation copy + pending badges), not duplicate logic. |
| **Dedicated announcements / org chart / benefits** | **Announcements** = **home feed** (`index.php`), **not** standalone route. **Org chart** & **benefits page** → **missing** in repo (**document as gaps**). |

---

## 2. Checklist (23 surfaces) — condensed audit

| Area | UX strengths | Gaps / native refactor hooks |
|------|--------------|-------------------------------|
| **Login** | Mobile meta, Tier A aesthetics | Larger primary CTA if &lt;56px anywhere |
| **Dashboard (`index.php`)** | KPI + announcements + announcements query | Skeleton when slow; ESS vs HR duplication clarity |
| **Employee list (`hr/employees.php`)** | — | Prefer **cards / responsive rows @sm** (**rule §7**) |
| **Profile (`profile.php`)** | Rich data | **Grouped cards** per user rule §2 |
| **Add/Edit employee (`employee_form.php`)** | — | Segment into **accordion / steps**, avoid long-scroll single column wall |
| **Leave request (`leave.php`)** | Entitlements visible | Formal **step/group** semantics (**rule §3**) |
| **Leave approval (`hr/leaves.php`)** | Pending queues | Highlight **pending-first** (**rule §4**) |
| **Attendance summary** | Multiple entry points | Unify typography for summaries |
| **Payslip / salary (`payslip.php`)** | Privacy-sensitive downloads | Strong **grouping** + avoid screenshot leaks in UI tests (**rule §5**) |
| **Benefits** | N/A route | Track as product backlog |
| **Documents** | Admin + certify flows | Card rows mobile |
| **Announcements** | Home block only | Optional **full-screen list page** (`/announcements`) — product call |
| **Approval workflow** | Distributed | Combined **badge count on HR home tile** UX |
| **Org chart** | Missing | Skip or roadmap |
| **Reports (`hr/reports.php`)** | CEO | **Summary cards on mobile** (**rule §6**) |
| **Admin / Settings** | CEO-gated sidebar | Sensitive — confirm dialogs (**rule §9**) |

### Modals / empty / loading / error (20–23)

| Type | Coverage |
|------|----------|
| **Modals** | Mobile sheet menu = modal-ish; audit **focus trap**, Escape (partially wired in header) |
| **Empty states** | Inconsistent lists |
| **Loading** | Spinner pattern not universal |
| **Error** | `flash()` + redirects — harmonize **toast vs banner** |

---

## 3. Native App Rules (user §1–13) × compliance snapshot

| # | Requirement | Meaning for tp-hr |
|---|--------------|-------------------|
| 1 | ESS **simple/native** | Preserve **7 tiles** readability; minimize nested tables on phone |
| 2 | Profile **grouped cards** | **profile.php** sections |
| 3 | Leave form **steps/groups** | **leave.php** + module partial refactor |
| 4 | Approval **pending clear** | Sort + chips + badges |
| 5 | Payroll **private/grouped** | No extra PII leakage in DOM; masking |
| 6 | HR reports **cards mobile** | `hr/reports.php` stat strip |
| 7 | Employee list **cards/rows** | `hr/employees.php` |
| 8 | **Long tables** don’t break mobile | Overflow-x **inside** bounded region |
| 9 | Critical actions **confirmation** | Approve/reject/pay/export |
| 10–13 | **No break** RBAC / approvals / payroll / schema | **Freeze** server rules |

---

## 4. Security & privacy notes

- **CSRF**: `csrfField()`/`verifyCsrfToken` on posts — preserve fields when refactoring forms.
- **Payslip download**: Ownership checks already in **`payslip.php`** queries — UI must still post correct tokens.

---

## 5. Risks after UI refactor

| Risk | Mitigation |
|------|------------|
| Mobile menu **`closeMobileMenu`** on navigation | Regression test every HR link |
| **Payroll DOM** exposes rows | Review after markup change |
| **HR admin tables** | Snapshot tests for pagination |

---

*Use with **`UI_REFACTOR_TODO.md`**.*
