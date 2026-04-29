# 05_PAGE_TO_IOS26_PATTERN_MAP.md — TP-HR

**Source routes:** **`01_FULL_UI_INVENTORY.md`** (Phase 5).  

Columns: **page type**, **chrome**, **target header**, **nav model**, **CTA tier**, **content pattern**.

Legend — **Pg type**: `AUTH` · `ESS` · `HRA` · `PUB` · `ANC`  
**Hdr**: `L` large title · `C` compact · `NV` minimal (login) · `PR` print/minimal  
**Nav**: `T4` four-tab ESS · `CTX` contextual stack (HR) · `ST` standalone  

| Route(s) | File | Pg | Chrome | Hdr | Nav | Primary CTA / zone | Pattern |
|----------|------|-------|--------|-----|-----|---------------------|---------|
| `/login.php` | `login.php` | AUTH | none/body shell | NV | none | SSO / LINE / password | centered glass card |
| `/logout.php` | `logout.php` | ANC | — | — | — | POST redirect only | none |
| `/verify_document.php` | `verify_document.php` | PUB | minimal | NV | none | Verify action | standalone card |
| print helpers | `certificate_print.php` | ESS/PUB | optional | PR | none | Print | minimal print css |
| `/` · `/index.php` | `index.php` | ESS | tabs + sticky | L | T4 | ลงเวลา (sticky) | **MASTER** dash |
| `/checkin.php` | `checkin.php` | ESS | tabs | L | T4 | Stamp in/out | clock + timeline |
| `/leave.php` | `leave.php` | ESS | tabs | L | T4 | ขอลา | hub cards |
| (embed) request | `modules/employee/leaves/request_form.php` | ESS | inherited | L | T4 | submit leave | stacked form groups |
| `/leave_history.php` | `leave_history.php` | ESS | tabs | L | T4 | filter/search | filters + list/table |
| `/attendance_history.php` | `attendance_history.php` | ESS | tabs | L | T4 | (navigate dates) | list/timeline cards |
| `/payslip.php` | `payslip.php` | ESS | tabs | L | T4 | open slip | file list rows |
| `/certificate.php` | `certificate.php` | ESS | tabs | L | T4 | request doc | wizard-ish cards |
| `/dayoff_schedule.php` | `dayoff_schedule.php` | ESS | tabs | L | T4 | (view) | rotating grid/card |
| `/profile.php` | `profile.php` | ESS | tabs | L | T4 | save profile | grouped settings rows |
| `/hr/index.php` | `hr/index.php` | HRA | no tabs | L | CTX | KPI drill links | KPI + feed |
| `/hr/employees.php` | `hr/employees.php` | HRA | no tabs | C | CTX | new employee | table→card responsive |
| `/hr/employee_form.php` | `hr/employee_form.php` | HRA | no tabs | C | CTX | save | dense form wells |
| `/hr/employee_view.php` | `hr/employee_view.php` | HRA | no tabs | C | CTX | edit / tabs | stacked sections |
| `/hr/employee_attendance.php` | `hr/employee_attendance.php` | HRA | no tabs | C | CTX | adjustments | timeline |
| `/hr/attendance.php` | `hr/attendance.php` | HRA | no tabs | C | CTX | approve mass actions | dense admin table |
| `/hr/leaves.php` | `hr/leaves.php` | HRA | no tabs | C | CTX | approve | filter + list rows |
| `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | HRA | no tabs | C | CTX | CEO approve | approvals list |
| `/hr/documents.php` | `hr/documents.php` | HRA | no tabs | C | CTX | fulfil | queue rows |
| `/hr/document_templates.php` | `hr/document_templates.php` | HRA | no tabs | C | CTX | save template | form + list |
| `/hr/reports.php` | `hr/reports.php` | HRA | no tabs | C | CTX | export | chart + table/card |
| `/hr/api_keys.php` | `hr/api_keys.php` | HRA | no tabs | C | CTX |rotate key | list + destructive |
| `/hr/settings.php` | `hr/settings.php` | HRA | no tabs | C | CTX | save settings | toggles wells |

Machine-only (`/api/**`, cron, scripts, tests): **omit** UX mapping.

**Mandatory states:** every **ESS** routed page inherits **toast / spinner** primitives from global bundles; dense **HRA** tables require **loading + empty** rows per registry patterns.
