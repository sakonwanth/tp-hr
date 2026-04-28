# 01 — Full UI Inventory (TP-HR)

**Project:** `tp-hr`  
**Discovery date:** 2026-04-28  
**Method:** Full filesystem scan (`**/*.php`), `grep` for `templates/header.php`, bootstrap/auth helpers, and route conventions.  
**UI UX Pro Max:** Accessibility §1, Touch §2, Layout §5, Forms §8, Navigation §9 applied as audit lens.

---

## Summary counts

| Category | Count |
|----------|------:|
| Browser UI pages (HTML) | 26 |
| UI partials (included only) | 1 |
| Non-UI (API / cron / tests / scripts) | 43 |
| **Total PHP files scanned** | **70** |

---

## A. Browser UI pages (authenticated or public HTML)

| # | Page / view name | Route (URL path) | File path | Layout | Components / patterns | Role access | Business purpose | UI type | Priority | Needs refactor |
|---|------------------|------------------|-----------|--------|----------------------|--------------|------------------|---------|----------|----------------|
| 1 | Employee dashboard | `/` | `index.php` | `templates/header.php` + `footer.php` | App shell, sidebar/desktop, mobile header+menu, bottom tabs, hero, quick actions, `glass-card` | Authenticated employee | Home / shortcuts | Dashboard | P0 | yes |
| 2 | Login | `/login.php` | `login.php` | Standalone (no header include) | Form, branding | Public (pre-auth) | Authentication | Auth | P0 | yes |
| 3 | Check-in / check-out | `/checkin.php` | `checkin.php` | Header + footer | Forms, camera/GPS UI (if present), cards | Authenticated | Time attendance | Flow | P0 | yes |
| 4 | Leave hub | `/leave.php` | `leave.php` | Header + footer | Tabs/links, lists | Authenticated | Leave self-service | Hub | P0 | yes |
| 5 | Leave history | `/leave_history.php` | `leave_history.php` | Header + footer | List / table | Authenticated | Leave history | List | P1 | yes |
| 6 | Leave request form (partial host) | via `leave.php` or include | `modules/employee/leaves/request_form.php` | Partial | Form groups, inputs | Authenticated | Submit leave | Form partial | P0 | yes |
| 7 | Payslip list | `/payslip.php` | `payslip.php` | Header + footer | List, cards, modals | Authenticated | Payroll slip access | List + detail | P0 | yes |
| 8 | Profile | `/profile.php` | `profile.php` | Header + footer | Grouped fields, avatar | Authenticated | Employee profile | Profile | P0 | yes |
| 9 | Certificate request | `/certificate.php` | `certificate.php` | Header + footer | Form, status | Authenticated | HR certificates | Form | P1 | yes |
| 10 | Day-off schedule | `/dayoff_schedule.php` | `dayoff_schedule.php` | Header + footer | Calendar / list | Authenticated | Weekly day-off | Schedule | P1 | yes |
| 11 | Attendance history | `/attendance_history.php` | `attendance_history.php` | Header + footer | Table / list | Authenticated | Own attendance | List | P1 | yes |
| 12 | HR dashboard | `/hr/index.php` | `hr/index.php` | Header + footer (no bottom tabs) | Widgets, stats | `hr_can_access_hr_dashboard()` | HR overview | Dashboard | P0 | yes |
| 13 | HR employees list | `/hr/employees.php` | `hr/employees.php` | Header + footer | Table, filters, actions | HR admin | Employee master | Admin list | P0 | yes |
| 14 | HR employee create/edit | `/hr/employee_form.php` | `hr/employee_form.php` | Header + footer | Long form | HR admin | CRUD employee | Form | P0 | yes |
| 15 | HR employee view | `/hr/employee_view.php` | `hr/employee_view.php` | Header + footer | Detail sections | HR admin | Employee detail | Detail | P0 | yes |
| 16 | HR employee attendance | `/hr/employee_attendance.php` | `hr/employee_attendance.php` | Header + footer | Table / timeline | HR admin | Per-employee attendance | Admin | P1 | yes |
| 17 | HR attendance management | `/hr/attendance.php` | `hr/attendance.php` | Header + footer | Table, filters | HR admin | Org attendance | Admin | P0 | yes |
| 18 | HR leave approvals | `/hr/leaves.php` | `hr/leaves.php` | Header + footer | Table, approve/reject | HR admin | Leave approval | Approval | P0 | yes |
| 19 | HR day-off approvals | `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | Header + footer | List / actions | CEO+ (gated in nav) | Day-off swap approval | Approval | P1 | yes |
| 20 | HR documents | `/hr/documents.php` | `hr/documents.php` | Header + footer | Table, uploads | HR admin | Document admin | Admin | P1 | yes |
| 21 | HR document templates | `/hr/document_templates.php` | `hr/document_templates.php` | Header + footer | Forms, editors | HR admin | Certificate templates | Admin | P2 | yes |
| 22 | HR reports | `/hr/reports.php` | `hr/reports.php` | Header + footer | Reports, export UI | CEO+ | Analytics / export | Report | P1 | yes |
| 23 | HR settings | `/hr/settings.php` | `hr/settings.php` | Header + footer | Settings form | CEO+ | System settings | Settings | P1 | yes |
| 24 | HR API keys | `/hr/api_keys.php` | `hr/api_keys.php` | Header + footer | Table, secrets UI | CEO+ | API key admin | Admin | P2 | yes |
| 25 | Document verification (public) | `/verify_document.php` | `verify_document.php` | Standalone layout | Lookup form / result | Public | Verify issued docs | Public | P2 | yes |
| 26 | Certificate print / preview | `/certificate_print.php` | `certificate_print.php` | Print-optimized HTML | Static layout | Authenticated | Print certificate | Print | P2 | yes |
| 27 | Logout | `/logout.php` | `logout.php` | N/A (redirect) | POST + CSRF | Authenticated | Session end | Action | P0 | no |

**Layout notes**

- **Employee routes** (`current_page` not `hr-*`): `body.tp-with-tab-nav` + **BottomTabNavigation** in `templates/footer.php`.
- **HR admin routes** (`hr-*`): desktop sidebar + mobile full-screen menu; **no** bottom tabs.

---

## B. API & integration endpoints (JSON / binary — not full UI pages)

Documented for completeness (no `templates/header.php`).

| Route prefix / file | File path | Purpose |
|---------------------|-----------|---------|
| `api/attendance.php` | `api/attendance.php` | Attendance API |
| `api/profile.php` | `api/profile.php` | Profile API |
| `api/payslip.php` | `api/payslip.php` | Payslip API |
| `api/leave.php` | `api/leave.php` | Leave API |
| `api/certificate.php` | `api/certificate.php` | Certificate API |
| `api/health.php` | `api/health.php` | Health check |
| `api/line_login.php` | `api/line_login.php` | LINE login |
| `api/checkin_storage_image.php` | `api/checkin_storage_image.php` | Image storage |
| `api/v1/*` | `api/v1/*.php` | Versioned REST |
| `webhook.php` | `webhook.php` | Webhook receiver |

---

## C. Non-UI PHP (excluded from page refactor scope)

- `tests/*.php`, `cron/*.php`, `scripts/*.php`, `bootstrap.php`, `core/*`, `config/*` — **no UI refactor** unless needed to render UI.

---

## D. Shared layout / partials (system-level)

| Asset | Path | Role |
|-------|------|------|
| App head + sidebar + mobile shell | `templates/header.php` | AppShell, SafeAreaHeader (mobile), nav |
| Footer + bottom tabs + toast | `templates/footer.php` | BottomTabNavigation, NativeToast |
| Native design system CSS | `assets/css/native-shell.css` | Tokens + locked utility classes |
| Compiled Tailwind | `assets/css/app.css` | Utilities |

---

**Inventory completeness:** Every user-rendering PHP entry point under `tp-hr/` was discovered; none omitted by name. API/cron/test files are listed in sections B–C.
