# 01_FULL_UI_INVENTORY.md — TP-HR (`PROJECT_TARGET`)

**Generated:** 2026-04-30 · **Baseline shell:** `/assets/css/native-shell.css?v=15` · **Discovery:** codebase scan (`templates/header.php`, `login.php`, `hr/*.php`, public routes).

Purpose: exhaustive **user-visible** UI inventory (excluding pure API handlers that return JSON only). Paths are relative to `tp-hr/`.

Legend: **UI type** ≈ Employee self-service (`ESS`), HR admin (`HRA`), public (`PUB`), auth (`AUTH`), ancillary (`*`).

---

## A. Auth & public (no `templates/header.php` chrome)

| # | Screen | Route(s) | File | Layout | Role | Business purpose | UI type | Priority | Refactor later |
|---|--------|----------|------|--------|------|-------------------|---------|----------|----------------|
| 1 | Login | `/login.php` | `login.php` | Inline HTML + Tailwind CDN / brand | Guests | SSO/credentials LINE | AUTH | P0 | yes |
| 2 | Logout | POST `/logout.php` | `logout.php` | Redirect only | All | Session end | * | — | no UI |
| 3 | Document verify | `/verify_document.php` | `verify_document.php` | Standalone centered card | Public | Verify certificate authenticity | PUB | P1 | yes |
| 4 | Certificate print helper | varies | `certificate_print.php` | Print-oriented | Conditional | Printable output | ESS/PUB | P2 | yes |

---

## B. Employee self-service — `templates/header.php` + `templates/footer.php` (mobile bottom tab strip when `$current_page` not `hr-*`)

| # | Screen | Typical URL | File | `current_page` | Role | Purpose | Priority | Refactor later |
|---|--------|----------------|------|-----------------|------|---------|----------|----------------|
| 5 | Dashboard | `/`, `/index.php` | `index.php` | `dashboard` | ESS | Home / quick actions | P0 | yes |
| 6 | Check-in/out | `/checkin.php` | `checkin.php` | `checkin` | ESS | Attendance stamping | P0 | yes |
| 7 | Leave hub | `/leave.php` | `leave.php` | `leave` | ESS | Navigate to requests | P0 | yes |
| 8 | Leave request form | via `leave.php` + module | `modules/employee/leaves/request_form.php` | (parent `leave`) | ESS | Submit leave | P0 | yes |
| 9 | Leave history | `/leave_history.php` | `leave_history.php` | varies | ESS | Filters / cancel | P0 | yes |
| 10 | Attendance history | `/attendance_history.php` | `attendance_history.php` | varies | ESS | Timeline | P1 | yes |
| 11 | Payslip list | `/payslip.php` | `payslip.php` | `payslip` | ESS | Payroll slip download | P0 | yes |
| 12 | Certificate requests | `/certificate.php` | `certificate.php` | `certificate` | ESS | Request HR letters | P1 | yes |
| 13 | Day-off weekly schedule | `/dayoff_schedule.php` | `dayoff_schedule.php` | `dayoff` | ESS | Rotating weekly off-days | P1 | yes |
| 14 | Profile | `/profile.php` | `profile.php` | `profile` | ESS | Employee profile | P0 | yes |

---

## C. HR administrator — `$current_page` prefixed `hr-` · **no bottom tab bar** (`footer.php` logic)

*(Permission-gated routes use `Auth` + helpers like `hr_can_access_hr_dashboard()`, CEO-only sections flagged in markup.)*

| # | Screen | URL | File | `current_page` | Purpose | Priority | Refactor later |
|---|--------|-----|------|----------------|---------|----------|----------------|
| 15 | HR Dashboard | `/hr/index.php` | `hr/index.php` | `hr-dashboard` | HR KPI tiles | P0 | yes |
| 16 | Employees directory | `/hr/employees.php` | `hr/employees.php` | `hr-employees` | Manage roster | P0 | yes |
| 17 | Employee create/edit | `/hr/employee_form.php` | `hr/employee_form.php` | `hr-employee-edit` etc. | Forms | P0 | yes |
| 18 | Employee view | `/hr/employee_view.php` | `hr/employee_view.php` | `hr-employee` | Detail | P1 | yes |
| 19 | Employee attendance | `/hr/employee_attendance.php` | `hr/employee_attendance.php` | varies | Drill-down | P1 | yes |
| 20 | HR attendance admin | `/hr/attendance.php` | `hr/attendance.php` | `hr-attendance` | Audit adjustments | P0 | yes |
| 21 | Leave approvals | `/hr/leaves.php` | `hr/leaves.php` | `hr-leaves` | Pending list | P0 | yes |
| 22 | Day-off change approvals | `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | `hr-dayoff` | CEO approvals | P1 | yes |
| 22a | Attendance adjustments (CEO) | `/hr/attendance_adjustments.php` | `hr/attendance_adjustments.php` | `hr-attendance-adjustments` | Approve check-in/out edits | P1 | yes |
| 23 | Documents queue | `/hr/documents.php` | `hr/documents.php` | `hr-documents` | Document ops | P1 | yes |
| 24 | Document templates | `/hr/document_templates.php` | `hr/document_templates.php` | `hr-document-templates` | Template CRUD | P2 | yes |
| 25 | Reports | `/hr/reports.php` | `hr/reports.php` | `hr-reports` | Charts / CSV | P1 | yes |
| 26 | API keys | `/hr/api_keys.php` | `hr/api_keys.php` | `hr-api-keys` | External API | P2 | yes |
| 27 | Settings | `/hr/settings.php` | `hr/settings.php` | `hr-settings` | Tenant toggles | P1 | yes |

---

## D. Machine / API · **not full UI routes** (no row required for UX refactor; QA contract-only)

`/api/**/*.php`, `webhook.php`, `cron/**/*.php`, `scripts/**/*.php`, `tests/**/*.php`.

---

## E. Supporting partials / assets (referenced by shells)

| Component area | Paths |
|----------------|-------|
| App chrome | `templates/header.php`, `templates/footer.php` |
| Locked registry | `templates/native/component_registry.php` |
| Global styles | `/assets/css/app.css`, `/assets/css/native-shell.css?v=15` |

---

### Count summary (browser UI routes in B+C+A)

- **Standalone / auth screens:** ~4 login/verify/print helpers  
- **ESS + HR templated shells:** ~24 primary controller files (+ embedded `modules/` partials)

**IOS26 implementation:** waves **MASTER + 6–9** are marked **REFACTORED** or **COMPLETE** in **`06_IMPLEMENTATION_PROGRESS.md`** (shell, ESS, HRA, `verify_document`, `certificate_print`). The **`Refactor later`** column above is the Phase 4 inventory schema (historical **yes** flags) — **authoritative status is `06`**, not this column alone.

**Next gate (human):** run **`07_SPACING_QA.md`** + **`08_VISUAL_QA_AFTER.md`** route matrices; keep **`03_MASTER_SCREEN_VISUAL_QA.md`** for **`index.php`** dashboard acceptance.
