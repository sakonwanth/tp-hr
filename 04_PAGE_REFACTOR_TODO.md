# 04_PAGE_REFACTOR_TODO.md — TP-HR phased execution queue

Priorities derive from **`01_FULL_UI_INVENTORY.md`**. Tasks are **non-breaking UI** unless noted.

## Wave 1 — Shell & tokens (DONE in this sprint)

| ID | Problem | Files | IOS target |
|----|-----------|-------|-------------|
| T0-1 | Global tokens misaligned vs IOS26 ladder | `assets/css/native-shell.css?v=12` | `:root` spacing + radii |
| T0-2 | Bottom-tab chip widths uneven | `.tp-native-bottom-tab-link` stretch | IOSLiquidTabBar |
| T0-3 | Component registry lacked IOS aliases | `templates/native/component_registry.php` | IOS* map |

## Wave 2 — Employee hot paths

| ID | Page | Exact issues | Approach |
|----|------|----------------|----------|
| T2-1 | `checkin.php` | Sticky modal / GPS legibility · fab collision | ✅ Visual pass (**native-shell v13**): IOS title/hero typography, gutters, **`aria-live`** on clock · **Logic/scripts unchanged** |
| T2-2 | `leave.php` + **`request_form.php`** | Dense form · inconsistent radii | ✅ **REFACTORED**: **`tp-leave-stack`**, **`.tp-ios-*`**, **gap-5/8**, **`--tp-ios-card-radius`**, form padding lift |
| T2-3 | `leave_history.php` | Filter sheet affordance | ✅ **REFACTORED**: **`tp-leave-history-stack`** · **`max-w-[min(960px,100%)]`** · **`.tp-ios-*`** header · grids/filters **`gap-5`/md‑`gap-8`** · **`rounded-[var(--tp-ios-card-radius)]`** · **`detail-modal`** / `viewDetail` / **`cancelRequest`** untouched |

## Wave 3 — HR admin (`hr/*.php`)

| ID | Scope | Notes |
|----|--------|--------|
| T3-1 | **`hr/index.php`**, **`hr/employees.php`**, **`hr/leaves.php`**, **`hr/attendance.php`**, **`hr/documents.php`**, **`hr/dayoff_approvals.php`**, **`hr/settings.php`**, **`hr/reports.php`**, **`hr/api_keys.php`**, **`hr/document_templates.php`**, **`hr/employee_form.php`**, **`hr/employee_view.php`**, **`hr/employee_attendance.php`** | ✅ **`tp-hr-admin-stack`** · **`max-w-[min(960px,100%)]`** · **`.tp-ios-page-title` / `tp-ios-caption-muted`** headers · **`rounded-[var(--tp-ios-card-radius)]`** sweep · modals **`#id`** / JS **unchanged** |

## Wave 4 — Remaining ESS templates

| ID | Scope | Notes |
|----|--------|--------|
| T4-1 | **`dayoff_schedule.php`**, **`attendance_history.php`**, **`certificate.php`**, **`payslip.php`**, **`profile.php`** + **`checkin.php`** radius alignment | ✅ **`tp-*-stack`** / **`.tp-ios-*`** where listed · **`checkin.php`** token radii only |

*(Optional later: denser table→card patterns on specific admin tables — only if product asks.)*

**Risk:** **Low** for CSS-only/visual; **Medium** where JS listens to DOM selectors — grep `getElementById` before renaming nodes.
