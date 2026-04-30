# 08_VISUAL_QA_AFTER.md — Per-page IOS26 fidelity

Purpose: replicate **`03`** master QA across **every** ESS · HRA · PUB/AUTH route after Waves **6–9** (see **`06_IMPLEMENTATION_PROGRESS.md`**).

## Latest execution snapshot (2026-04-30)

- Static iOS26 shell contract: **PASS** (`verify:static-ui`)
- Route access / auth boundary regression: **PASS** (guest E2E **60/60** across phone + tablet)
- Touch-target minimum uplift (<48 → 48): **applied** on HR/admin action controls
- Authenticated role matrix: **PASS** via Playwright auth runs on production base URL (EMP self-service / HR admin / CEO-only paths, phone + tablet projects).

**Related gates**

- Master dashboard (**`index.php` only**): **`03_MASTER_SCREEN_VISUAL_QA.md`**
- Spacing/token checks: **`07_SPACING_QA.md`**
- Browser width / scroll-end / tab QA: **`10_BROWSER_VIEWPORT_QA.md`**
- Static viewport/CSS rationale (evidence table): **`AUDIT_04_VIEWPORT.md`**

---

Questions (adapted):

1. Feels native (not boxed admin)?  
2. Glass only on chrome—not content overwhelm?  
3. Primary CTA clear?  
4. No horizontal scroll?  
5. Cohesive typography?  
6. HR admin tables degrade to cards on XS?  
7. No orphan borders?  
8. Dark shell contrast OK (WCAG heuristic)?

Answer **PASS**/**FAIL** per question in session notes · or ✅/❌ in the matrices below · or attach an annotated screenshots folder.

---

## Route matrix — ESS (bottom tabs)

Use **Questions 1–8** · plus **`07`** checklist on the **same run** where applicable (**9–11** concern tab chrome + sticky CTA + scroll-end).

| Route | File | `08` 1–8 | `07` | Date |
|-------|------|----------|------|------|
| `/` · `/index.php` | `index.php` | ☐ | ☐ | _(see **`03`** for dashboard gate)_ |
| `/checkin.php` | `checkin.php` | ☐ | ☐ | |
| `/leave.php` | `leave.php` + `modules/employee/leaves/request_form.php` | ☐ | ☐ | |
| `/leave_history.php` | `leave_history.php` | ☐ | ☐ | |
| `/attendance_history.php` | `attendance_history.php` | ☐ | ☐ | |
| `/payslip.php` | `payslip.php` | ☐ | ☐ | |
| `/certificate.php` | `certificate.php` | ☐ | ☐ | |
| `/dayoff_schedule.php` | `dayoff_schedule.php` | ☐ | ☐ | |
| `/profile.php` | `profile.php` | ☐ | ☐ | |

---

## Route matrix — HRA (`/hr/`)

**1–8** as above · **`07`** **1–8** · **14–15** always; **9–11** usually N/A (no T5 tabs).

| Route | File | `08` | `07` | Date |
|-------|------|------|------|------|
| `/hr/index.php` | `hr/index.php` | ☐ | ☐ | |
| `/hr/employees.php` | `hr/employees.php` | ☐ | ☐ | |
| `/hr/employee_form.php` | `hr/employee_form.php` | ☐ | ☐ | |
| `/hr/employee_view.php` | `hr/employee_view.php` | ☐ | ☐ | |
| `/hr/employee_attendance.php` | `hr/employee_attendance.php` | ☐ | ☐ | |
| `/hr/attendance.php` | `hr/attendance.php` | ☐ | ☐ | |
| `/hr/leaves.php` | `hr/leaves.php` | ☐ | ☐ | |
| `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | ☐ | ☐ | |
| `/hr/attendance_adjustments.php` | `hr/attendance_adjustments.php` | ☐ | ☐ | CEO |
| `/hr/documents.php` | `hr/documents.php` | ☐ | ☐ | |
| `/hr/document_templates.php` | `hr/document_templates.php` | ☐ | ☐ | |
| `/hr/reports.php` | `hr/reports.php` | ☐ | ☐ | |
| `/hr/api_keys.php` | `hr/api_keys.php` | ☐ | ☐ | |
| `/hr/settings.php` | `hr/settings.php` | ☐ | ☐ | |

---

## Route matrix — AUTH · PUB · print (Waves 8–9)

| Route | File | `08` | `07` | Notes |
|-------|------|------|------|--------|
| `/login.php` | `login.php` | ☐ | ☐ | AUTH |
| `/verify_document.php` | `verify_document.php` | ☐ | ☐ | PUB |
| *(POST to)* `certificate_print.php` | `certificate_print.php` | ☐ | ☐ | Preview toolbar; A4 `@media print` |

---

## Page checklist template

```
Route: _________ · Date: _____ · Tester: _____ · Device: _____
06 wave: _____
Questions 08 (1–8): [ ] PASS
07 spacing (as applicable): [ ] PASS
Notes:

```
