# SCREEN_MAP.md — TP-HR (Native HR Self-Service & Management)

**Product:** `/Applications/XAMPP/xamppfiles/htdocs/tp-hr`  
**Updated:** 2026-04-28  
**Purpose:** Route + screen inventory for **Tier A** HR shell (glass / IBM Plex / purple per `UI_UX_STANDARD.md` §0.2 — see `templates/header.php`).

**Bootstrap:** `bootstrap.php` + `Auth::requireLogin()` on protected pages.  
**Chrome:** `templates/header.php` + `templates/footer.php`; **mobile menu overlay** + **sidebar** for desktop.

**Base URL:** Assumed at site root paths like `/`, `/login.php`, `/hr/employees.php` (adjust for deployment prefix).

---

## A. Checklist → routes (product terms → files)

| # | Checklist | Primary route / file | Notes |
|---|-----------|------------------------|--------|
| 1 | Login | `login.php` | May include LINE login via `api/line_login.php` |
| 2 | HR dashboard | **`index.php`** (all users — personal + HR stats + **announcements**) | Not only `hr/index.php` |
| 2b | HR admin dashboard | **`hr/index.php`** | Visible in nav when `hr_can_access_hr_dashboard()` |
| 3 | Employee list | **`hr/employees.php`** | Admin/HR |
| 4 | Employee profile | **`hr/employee_view.php?id=`** (admin view); self-service **`profile.php`** | Two contexts |
| 5 | Add employee | **`hr/employee_form.php`** (create) | |
| 6 | Edit employee | **`hr/employee_form.php`** (edit with id) | |
| 7 | Leave request | **`leave.php`** (+ `modules/employee/leaves/request_form.php` if included) | Self-service |
| 8 | Leave approval | **`hr/leaves.php`** | Label in nav: **อนุมัติการลา** |
| 9 | Attendance summary | **`attendance_history.php`** (self); **`hr/attendance.php`**, **`hr/employee_attendance.php`** (admin) | **`checkin.php`** shortcut for clock |
| 10 | Payroll page | **`payslip.php`** | Slip list / view / download |
| 11 | Salary detail | **Inside `payslip.php`** (breakdown, YTD helpers) | No separate **`/salary.php`** in tree |
| 12 | Benefit page | **ไม่พบไฟล์เฉพาะ “benefits”** | อาจขยายภายหลังหรืออยู่ในโปรไฟล์ — **N/A ใน repo ปัจจุบัน** |
| 13 | Document page | **`hr/documents.php`** (admin); self flows via certificate/verify | Templates: **`hr/document_templates.php`** |
| 14 | Announcement page | **ไม่มี URL แยก** — **`index.php`** loads `hr_announcements` block | API: `api/v1/hr_meta.php` / **`announcements`** segment |
| 15 | Approval workflow | **กระจาย** — **`hr/leaves.php`**, **`hr/dayoff_approvals.php`**, เอกสารใน **`hr/documents.php`** | ไม่มีหน้าชื่อ **`workflow.php`** |
| 16 | Organization chart | **ไม่พบใน codebase** | Feature gap / out of scope until built |
| 17 | Report page | **`hr/reports.php`** | `isCEOOrAbove()` gate in sidebar |
| 18 | Admin page | **HR Admin block** in nav: `/hr/index.php`, employees, attendance, leaves, docs, … | Settings/reports CEO-only |
| 19 | Settings page | **`hr/settings.php`** (+ **`hr/api_keys.php`** if linked from settings) | CEO gate for settings in nav |
| 20 | Modals | Across views + mobile menu sheet (`#mobileSidebar`) | |
| 21 | Empty states | Per list views | Mixed maturity |
| 22 | Loading states | Partial — fetch-heavy areas | |
| 23 | Error pages | **`flash()` redirects**, inline errors; no dedicated **`error.php`** found | |

---

## B. Self-service (employee) — main mobile grid

From `templates/header.php` **mobile menu grid**:

| Label | Path |
|-------|------|
| หน้าแรก | `/` (`index.php`) |
| ลงเวลาเข้า-ออก | `/checkin.php` |
| การลา | `/leave.php` |
| สลิปเงินเดือน | `/payslip.php` |
| ขอใบรับรอง | `/certificate.php` |
| วันหยุดประจำสัปดาห์ | `/dayoff_schedule.php` |
| ข้อมูลส่วนตัว | `/profile.php` |

**Also:** `/leave_history.php`, `/attendance_history.php`, `/verify_document.php`, `/certificate_print.php` (support flows).

---

## C. HR admin (sidebar + mobile HR section)

| Path | Purpose |
|------|---------|
| `hr/index.php` | HR dashboard |
| `hr/employees.php` | Employee directory CRUD entry |
| `hr/employee_form.php` | Create/edit employee |
| `hr/employee_view.php` | Employee detail |
| `hr/attendance.php` | Attendance mgmt (+ maps in file) |
| `hr/employee_attendance.php` | Per-employee drill |
| `hr/leaves.php` | Approve leave |
| `hr/dayoff_approvals.php` | CEO day-off swaps |
| `hr/documents.php` | Document requests admin |
| `hr/document_templates.php` | Certificate templates |
| `hr/reports.php` | Reports (CEO nav) |
| `hr/settings.php` | HR system settings (CEO nav) |

---

## D. API (not full pages)

| Area | Examples |
|------|----------|
| REST v1 | `api/v1/index.php` — leave, attendance, payroll, announcements meta, … |
| Legacy AJAX | `api/leave.php`, `api/profile.php`, `api/payslip.php`, `api/attendance.php`, … |

**Refactor constraint:** Preserve JSON contracts — UI-only changes preferred.

---

## E. Payroll & payslip internals

- **`payslip.php`** — slips for **logged-in user** (`payroll_slips` + approved/paid runs).  
- Printing: **`modules/employee/payslip/print_template.php`**.

---

## F. Scripts / cron (no UI)

`cron/*.php`, `scripts/*.php` — out of UI refactor scope.

---

## G. Tests

`tests/*.php` — regression targets if selectors/dom change.

---

*Update when adding routes (e.g. org chart, benefits module).*
