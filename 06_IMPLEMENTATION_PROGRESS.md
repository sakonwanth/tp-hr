# 06_IMPLEMENTATION_PROGRESS.md — TP-HR IOS26 rollout

Statuses: **`NOT_STARTED` · `IN_PROGRESS` · `REFACTORED` · `COMPLETE` · `REGRESSION_FAIL`**.

| Page / area | Wave | Before | After (2026-04-28) | Status |
|-------------|------|--------|---------------------|--------|
| Global **`native-shell.css`** | Wave 1–2 | v11 gap/chip issues | **v12** tokens + tab stretch; **v13** IOS typography helpers (`.tp-ios-*`) | **COMPLETE** |
| **`templates/header.php` link`** | Wave 1→2 | `?v=11` | **`?v=13`** (extends Wave 2 helpers) | **COMPLETE** |
| **`templates/native/component_registry.php`** | Wave 1 | Native keys only | **IOS*** alias layer | **COMPLETE** |
| `index.php` (dashboard) | Wave 2 | Legacy hero grid | **`tp-dashboard-stack` max-width 960px**, hero **`--tp-font-page-title`**, HR grid **gap-5**, main **gap-8**, radii **`--tp-ios-card-radius`** · header hero summary aligned | **REFACTORED** |
| `checkin.php` | Wave 2 | ESS clock + CTA dominant | **`v=13`** tokens: `.tp-ios-page-title`, hero clock, **`max-w` 960**, section gaps **20/32**, history row **54px**, `aria-live` clock | **REFACTORED** |
| Employee leave funnel | Wave 2 | — | NOT_STARTED |
| `hr/*.php` admin shells | Wave 3 | — | NOT_STARTED |

**Regression:** PHPUnit / API tests untouched. Manual viewport QA runs after Wave 2 (**`07`**).
