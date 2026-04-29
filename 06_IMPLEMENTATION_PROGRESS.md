# 06_IMPLEMENTATION_PROGRESS.md — TP-HR IOS26 rollout

Statuses: **`NOT_STARTED` · `IN_PROGRESS` · `REFACTORED` · `COMPLETE` · `REGRESSION_FAIL`**.

| Page / area | Wave | Before | After (2026-04-28) | Status |
|-------------|------|--------|---------------------|--------|
| Global **`native-shell.css`** | Wave 1 | v11 gap/chip issues | **v12**: IOS26 `:root`, tab stretch, form gaps, textarea min, buffers | **COMPLETE** |
| **`templates/header.php` link`** | Wave 1 | `?v=11` | `?v=12` | **COMPLETE** |
| **`templates/native/component_registry.php`** | Wave 1 | Native keys only | **IOS*** alias layer | **COMPLETE** |
| `index.php` (dashboard) | Wave 2 | Legacy hero | **NOT_STARTED** visually | NOT_STARTED |
| `checkin.php` | Wave 2 | — | NOT_STARTED |
| Employee leave funnel | Wave 2 | — | NOT_STARTED |
| `hr/*.php` admin shells | Wave 3 | — | NOT_STARTED |

**Regression:** PHPUnit / API tests untouched. Manual viewport QA runs after Wave 2 (**`07`**).
