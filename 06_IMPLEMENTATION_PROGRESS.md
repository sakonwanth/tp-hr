# 06_IMPLEMENTATION_PROGRESS.md — TP-HR IOS26 rollout

Statuses: **`NOT_STARTED` · `IN_PROGRESS` · `REFACTORED` · `COMPLETE` · `REGRESSION_FAIL`**.

| Page / area | Wave | Before | After (2026-04-28) | Status |
|-------------|------|--------|---------------------|--------|
| Global **`native-shell.css`** | Wave 1–2–3 tokens | v11 gap/chip issues | **v14** aligns **`01_IOS26`** (section gap **24**, btn **58**, buffer **160**, input **56**, textarea **120**) | **COMPLETE** |
| **`templates/header.php` + `login.php` link`** | Wave 3 | mixed `?v` | **`?v=14`** unified | **COMPLETE** |
| **`templates/native/component_registry.php`** | Wave 1 | Native keys only | **IOS*** alias layer | **COMPLETE** |
| `index.php` (dashboard) | Wave 2 | Legacy hero grid | **`tp-dashboard-stack` max-width 960px**, hero **`--tp-font-page-title`**, HR grid **gap-5**, main **gap-8**, radii **`--tp-ios-card-radius`** · header hero summary aligned | **REFACTORED** |
| `checkin.php` | Wave 2 | ESS clock + CTA dominant | **Shell `?v=14`** · `.tp-ios-page-title`, hero clock, **`max-w` 960**, section spacing via tokens + history rows, `aria-live` clock | **REFACTORED** |
| `leave.php` + **`modules/employee/leaves/request_form.php`** | Wave 2 | Flat title + `rounded-[20px]` mix | **`tp-leave-stack`** 960px · **`.tp-ios-*` titles** · grids **gap-5/8** · **token radii** · form **p-5 sm:p-7** · cancel/submit radii | **REFACTORED** |
| **`leave_history.php`** | Wave 2 | Dense header · `rounded-[20px]` mix | **`tp-leave-history-stack`** · **`.tp-ios-page-title` / caption** · summary/filter/results gaps · **token radii** · table + mobile cards + pagination + modal close button aligned | **REFACTORED** |
| **`hr/*.php`** (13 files) | Wave 3 | Mixed titles · `rounded-[20px]` | **`tp-hr-admin-stack`** 960px · **`.tp-ios-*`** page headers · **token radii** · stack closes before **modals** / **`footer`** (IDs & scripts preserved) | **REFACTORED** |
| **ESS remainder** (`dayoff_schedule.php`, **`attendance_history.php`**, **`certificate.php`**, **`payslip.php`**, **`profile.php`**) | Wave 4 | Mixed radii · flat titles | **`tp-*-stack`** 960px · **`.tp-ios-*`** · **`rounded-[var(--tp-ios-card-radius)]`** (**`checkin.php`** already had **`tp-checkin-stack`** + **`.tp-ios-*`** from Wave **2**; Wave **4** added token radii sweep) | **REFACTORED** |
| **`login.php`** | Polish | Success/error banners **`rounded-[20px]`** | **`rounded-[var(--tp-ios-card-radius)]`** (markup-only) | **REFACTORED** |

**Regression:** PHPUnit / API tests untouched. Manual viewport QA: Wave **2–4** routes (**`07`** matrix).

---

### Phase 6 — optional per-route detail tracking

For deep audits, duplicate each row above with granular columns (**components replaced · visual deltas · UX · spacing/mobile/tablet · regression**) in a spreadsheet or appendix table—**`06_IMPLEMENTATION_PROGRESS.md`** remains the condensed source of truth.
