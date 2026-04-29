# 06_IMPLEMENTATION_PROGRESS.md — TP-HR IOS26 rollout

Statuses: **`NOT_STARTED` · `IN_PROGRESS` · `REFACTORED` · `COMPLETE` · `REGRESSION_FAIL`**.

| Page / area | Wave | Before | After (2026-04-28) | Status |
|-------------|------|--------|---------------------|--------|
| Global **`native-shell.css`** | Wave 1–2 | v11 gap/chip issues | **v12** tokens + tab stretch; **v13** IOS typography helpers (`.tp-ios-*`) | **COMPLETE** |
| **`templates/header.php` link`** | Wave 1→2 | `?v=11` | **`?v=13`** (extends Wave 2 helpers) | **COMPLETE** |
| **`templates/native/component_registry.php`** | Wave 1 | Native keys only | **IOS*** alias layer | **COMPLETE** |
| `index.php` (dashboard) | Wave 2 | Legacy hero grid | **`tp-dashboard-stack` max-width 960px**, hero **`--tp-font-page-title`**, HR grid **gap-5**, main **gap-8**, radii **`--tp-ios-card-radius`** · header hero summary aligned | **REFACTORED** |
| `checkin.php` | Wave 2 | ESS clock + CTA dominant | **`v=13`** tokens: `.tp-ios-page-title`, hero clock, **`max-w` 960**, section gaps **20/32**, history row **54px**, `aria-live` clock | **REFACTORED** |
| `leave.php` + **`modules/employee/leaves/request_form.php`** | Wave 2 | Flat title + `rounded-[20px]` mix | **`tp-leave-stack`** 960px · **`.tp-ios-*` titles** · grids **gap-5/8** · **token radii** · form **p-5 sm:p-7** · cancel/submit radii | **REFACTORED** |
| **`leave_history.php`** | Wave 2 | Dense header · `rounded-[20px]` mix | **`tp-leave-history-stack`** · **`.tp-ios-page-title` / caption** · summary/filter/results gaps · **token radii** · table + mobile cards + pagination + modal close button aligned | **REFACTORED** |
| **`hr/*.php`** (13 files) | Wave 3 | Mixed titles · `rounded-[20px]` | **`tp-hr-admin-stack`** 960px · **`.tp-ios-*`** page headers · **token radii** · stack closes before **modals** / **`footer`** (IDs & scripts preserved) | **REFACTORED** |
| **ESS remainder** (`dayoff_schedule.php`, **`attendance_history.php`**, **`certificate.php`**, **`payslip.php`**, **`profile.php`**) | Wave 4 | Mixed radii · flat titles | **`tp-*-stack`** 960px · **`.tp-ios-*`** · **`rounded-[var(--tp-ios-card-radius)]`** (**`checkin.php`** radii aligned with token only) | **REFACTORED** |

**Regression:** PHPUnit / API tests untouched. Manual viewport QA: Wave **2–4** routes (**`07`** matrix).
