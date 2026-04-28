# 01 — Full UI inventory (TP-HR)

**Generated / rescanned:** 2026-04-28 — `PROJECT_TARGET = tp-hr`  
**Evidence:** filesystem glob `**/*.php` (application tree), `grep` for `templates/header.php` entry points, **`PAGE_AUDIT_ORDERED_TP_HR.md`**.

**Stack:** IBM Plex Sans Thai + **`assets/css/native-shell.css?v=5`** + shell classes on `templates/header.php` / `templates/footer.php`.

## Summary

ลำดับ audit ครบและจำนวนล่าสุดใน **`PAGE_AUDIT_ORDERED_TP_HR.md`** — **27** route/view UI ที่มี HTML สำหรับผู้ใช้ + พาร์เชียล (`modules/employee/leaves/request_form.php`, `modules/employee/payslip/print_template.php`)

| Area | Count (โดยประมาณ) |
|------|------:|
| Authenticated pages using `templates/header.php` | 23 |
| Standalone / special UI | 3 |
| Auth UI | 1 |

---

## Employee / general (bottom tab nav on mobile when `current_page` ≠ `hr-*`)

| # | Page name | Route | File | Layout | `current_page` | Role | Priority |
|---|-----------|-------|------|--------|----------------|------|----------|
| 1 | Dashboard | `/` | `index.php` | header + main + bottom nav | `dashboard` | logged-in | P0 |
| 2 | Check-in/out | `/checkin.php` | `checkin.php` | header + main + bottom nav | `checkin` | logged-in | P0 |
| 3 | Leave hub | `/leave.php` | `leave.php` | header + main + bottom nav | `leave` | logged-in | P0 |
| 4 | Leave request | `/leave.php?action=request` | `leave.php` + `modules/employee/leaves/request_form.php` | nested partial | `leave` | logged-in | P0 |
| 5 | Payslip | `/payslip.php` | `payslip.php` | header + main + bottom nav | `payslip` | logged-in | P1 |
| 6 | Certificate request | `/certificate.php` | `certificate.php` | header + main + bottom nav | `certificate` | logged-in | P1 |
| 7 | Day-off schedule | `/dayoff_schedule.php` | `dayoff_schedule.php` | header + main + bottom nav | `dayoff` | logged-in | P2 |
| 8 | Profile | `/profile.php` | `profile.php` | header + main + bottom nav | `profile` | logged-in | P0 |
| 9 | Attendance history | `/attendance_history.php` | `attendance_history.php` | header + main + bottom nav | *(set in file)* | logged-in | P2 |
|10 | Leave history | `/leave_history.php` | `leave_history.php` | header + main + bottom nav | *(set in file)* | logged-in | P2 |

---

## HR admin (`/hr/*`, `current_page` = `hr-*`; no bottom tab bar)

| # | Page name | Route | File | `current_page` |
|---|-----------|-------|------|------------------|
| 11 | HR dashboard | `/hr/index.php` | `hr/index.php` | `hr-dashboard` |
| 12 | Employees list | `/hr/employees.php` | `hr/employees.php` | `hr-employees` |
| 13 | Employee view | `/hr/employee_view.php` | `hr/employee_view.php` | *(see file)* |
| 14 | Employee create/edit | `/hr/employee_form.php` | `hr/employee_form.php` | *(see file)* |
| 15 | Employee attendance | `/hr/employee_attendance.php` | `hr/employee_attendance.php` | *(see file)* |
| 16 | Leave approvals | `/hr/leaves.php` | `hr/leaves.php` | `hr-leaves` |
| 17 | HR attendance mgmt | `/hr/attendance.php` | `hr/attendance.php` | `hr-attendance` |
| 18 | Documents | `/hr/documents.php` | `hr/documents.php` | `hr-documents` |
| 19 | Document templates | `/hr/document_templates.php` | `hr/document_templates.php` | `hr-document-templates` |
| 20 | API keys | `/hr/api_keys.php` | `hr/api_keys.php` | *(see file)* |
| 21 | Reports | `/hr/reports.php` | `hr/reports.php` | `hr-reports` |
| 22 | Settings | `/hr/settings.php` | `hr/settings.php` | `hr-settings` |
| 23 | Day-off approvals | `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | `hr-dayoff` |

---

## Standalone / non-shell

| # | Page name | Route | File | Notes |
|---|-----------|-------|------|------|
| 24 | Login | `/login.php` | `login.php` | `body.login-page-root.tp-native-app`, `native-shell.css`, 56px CTAs, 52px inputs |
| 25 | Verify document (public) | `/verify_document.php` | `verify_document.php` | Light card UI; IBM Plex + 20px radius + touch targets aligned |
| 26 | Certificate print preview | POST `/certificate_print.php` | `certificate_print.php` | Print-focused HTML; logic unchanged |

---

## API / tests / cron (excluded from UI inventory)

`api/*`, `tests/*`, `cron/*`, `scripts/*`, `logout.php` (redirect), `webhook.php`, `modules/*/print_template.php` (embed).

---

## Refactor flag

All shell-backed pages: **refactored** with unified **AppShell** semantics via `body.tp-native-app`, conditional `body.tp-with-tab-nav` (employee routes), **`main#tp-hr-main.content-area.tp-native-page`**, **BottomTabNavigation** (`tp-native-bottom-tab-nav`), and **design tokens** in `assets/css/native-shell.css`.
