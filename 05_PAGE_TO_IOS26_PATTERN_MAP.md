# 05_PAGE_TO_IOS26_PATTERN_MAP.md — TP-HR

**Source inventory:** [`01_FULL_UI_INVENTORY.md`](01_FULL_UI_INVENTORY.md) · **Master reference:** [`02_MASTER_SCREEN_DESIGN.md`](02_MASTER_SCREEN_DESIGN.md) · **Shell:** **`native-shell.css?v=15`**

---

## Legend

| Key | Meaning |
|-----|---------|
| **Pg** | `AUTH` · `ESS` · `HRA` · `PUB` · `ANC` |
| **Hdr** | `L` large title block · `C` compact/contextual · `NV` minimal · `PR` print |
| **Nav** | **`T5`** five-item bottom tab (ESS) · **`CTX`** HR stack / sidebar · **`ST`** standalone · **`none`** |

**Spacing (all ESS/HRA content):** page pad mobile **≥16** · tablet **≥24** · section gap **24** · card gap **16–20** · scroll end buffer **≥160** · primary CTA **≥58** · tab chrome slot **`--tp-bottom-nav-slot`**.

---

## Route → pattern (summary)

| Route(s) | File | Pg | Hdr | Nav | Primary CTA / zone | Target layout |
|----------|------|----|----|-----|-------------------|----------------|
| `/login.php` | `login.php` | AUTH | NV | none | Sign in / LINE | Centered card · minimal chrome |
| `/logout.php` | `logout.php` | ANC | — | none | — | None |
| `/verify_document.php` | `verify_document.php` | PUB | NV | none | Verify | Single card |
| `certificate_print.php` | `certificate_print.php` | ESS/PUB | PR | none | Print | Print CSS |
| `/` · `index.php` | `index.php` | ESS | L | **T5** | ลงเวลา (hero + sticky + tab) | **MASTER** dashboard |
| `/checkin.php` | `checkin.php` | ESS | L | **T5** | ลงเวลาเข้า/ออก (main disk) | Clock card + status wells + history |
| `/leave.php` | `leave.php` | ESS | L | **T5** | ขอลา | Hub cards |
| (embed) | `modules/employee/leaves/request_form.php` | ESS | L | **T5** | Submit | Stacked **`tp-native-form-group`** |
| `/leave_history.php` | `leave_history.php` | ESS | L | **T5** | Filter | Filters + table/card |
| `/attendance_history.php` | `attendance_history.php` | ESS | L | **T5** | — | Timeline / list cards |
| `/payslip.php` | `payslip.php` | ESS | L | **T5** | Open slip | List rows |
| `/certificate.php` | `certificate.php` | ESS | L | **T5** | Request | Wizard cards |
| `/dayoff_schedule.php` | `dayoff_schedule.php` | ESS | L | **T5** | — | Schedule grid/card |
| `/profile.php` | `profile.php` | ESS | L | **T5** | Save | Inset grouped rows |
| `/hr/index.php` | `hr/index.php` | HRA | L | CTX | Drill links | KPI + lists |
| `/hr/employees.php` | `hr/employees.php` | HRA | C | CTX | New employee | **Table → card** |
| `/hr/employee_form.php` | `hr/employee_form.php` | HRA | C | CTX | Save | Long form wells |
| `/hr/employee_view.php` | `hr/employee_view.php` | HRA | C | CTX | Edit | Sections / tabs |
| `/hr/employee_attendance.php` | `hr/employee_attendance.php` | HRA | C | CTX | Adjust | Timeline rows |
| `/hr/attendance.php` | `hr/attendance.php` | HRA | C | CTX | Bulk actions | Admin table + filters |
| `/hr/leaves.php` | `hr/leaves.php` | HRA | C | CTX | Approve | Filter + rows |
| `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | HRA | C | CTX | CEO approve | Approval list |
| `/hr/attendance_adjustments.php` | `hr/attendance_adjustments.php` | HRA | C | CTX | Approve edits | Approval queue + detail |
| `/hr/documents.php` | `hr/documents.php` | HRA | C | CTX | Fulfil | Queue rows |
| `/hr/document_templates.php` | `hr/document_templates.php` | HRA | C | CTX | Save | Form + list |
| `/hr/reports.php` | `hr/reports.php` | HRA | C | CTX | Export | Chart + table |
| `/hr/api_keys.php` | `hr/api_keys.php` | HRA | C | CTX | Rotate | List + destructive |
| `/hr/settings.php` | `hr/settings.php` | HRA | C | CTX | Save | Toggle groups |

`/api/**`, `webhook.php`, `cron/**`, `scripts/**`, `tests/**`: **no** UX row — contract QA only.

---

## Per-page checklist (minimum)

For **every** row above, implementation must satisfy:

1. **Header type** per **Hdr** (`tp-ios-page-title` + `tp-ios-large-title-block` or compact HR title).  
2. **Navigation** per **Nav** (ESS never shows HR grid; HR never shows **`T5`**).  
3. **Liquid Glass** only on **chrome** (tabs, sticky CTA where present, modal/sheet chrome) — content cards **`native-card` / wells**, not stacked heavy blur on dense text.  
4. **States:** loading / empty / error where the screen fetches or lists data (`07`/`08` QA).  

---

## ESS “locked” components (apply on every ESS page)

`IOSAppShell` body · **`T5`** footer · **`tp-ios-page-title`** · **`native-card`** / **`tp-ios-attendance-panel`** (time blocks) · **`tp-native-btn-primary`** · toast from **`footer.php`**.
