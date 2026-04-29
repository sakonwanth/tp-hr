# 06_IMPLEMENTATION_PROGRESS.md — TP-HR IOS26 rollout

Statuses: **`NOT_STARTED` · `IN_PROGRESS` · `REFACTORED` · `COMPLETE` · `REGRESSION_FAIL`**.

| Page / area | Wave | Before | After | Status |
|-------------|------|--------|-------|--------|
| Global **`native-shell.css`** | v15 | v14 tokens | **v15** — floating **Liquid Glass** tab pill · gradient scrim · **glass sticky CTA slab** (`tp-ios-sticky-cta-slab`) · typography scale · **`--tp-bottom-nav-slot`** | **COMPLETE** |
| **`templates/header.php` + `login.php`** CSS link | v15 | `?v=14` | **`?v=15`** | **COMPLETE** |
| **`01` · `04` · `05` docs** | Phase 4–5 | Pointer-only · T4 shorthand | **`04`** body + sync rules · **`01`** + **`hr/attendance_adjustments.php`** · **`05`** **T5** + full route table | **COMPLETE** |
| **`index.php`** (dashboard) | MASTER v15 | Wave-2 polish | **`tp-ios-master-screen`** · **glass sticky slab** · **`tp-ios-attendance-panel`** · floating tabs | **REFACTORED** |
| **`checkin.php`** | Wave 6 · align master | **`tp-checkin-stack`** only | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · **เข้า/ออก** wells **`tp-ios-attendance-panel`** · grid **`gap-6`** · column **`space-y-6`** | **REFACTORED** |
| `leave.php` + **`modules/employee/leaves/request_form.php`** | Wave **6 · v15** | Flat header · **`gap-5`** | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · grid **`gap-6`** · balance tiles **`tp-ios-attendance-panel`** · form inputs **`tp-native-*`** · actions **54/58** | **REFACTORED** |
| ESS remainder · **`leave_history.php`** · **`attendance_history.php`** · etc. | Wave 4 | Mixed | Stacks + **`tp-ios-*`** | **REFACTORED** *(QA vs v15)* |
| **`login.php`** | Polish | — | Token radii | **REFACTORED** |
| **`hr/*.php`** (admin) | Wave 3 | Mixed | **`tp-hr-admin-stack`** + titles | **REFACTORED** *(QA chrome)* |

**Regression:** PHPUnit / API untouched. Viewport QA: **`03`** · **`07_SPACING_QA.md`** · **`08_VISUAL_QA_AFTER.md`**.

---

### Per-route detail (Phase 9)

Expand with: **Page** · **Route** · **File** · **Components** · **Visual** · **UX** · **Spacing** · **Mobile** · **Tablet** · **Regression**.
