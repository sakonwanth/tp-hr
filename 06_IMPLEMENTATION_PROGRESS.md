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
| **`leave_history.php`** | Wave **6 · v15** | Header spacing · filter gap-4 | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** (`<header>`) · **`gap-6`** · **`tp-native-select`** · **`tp-ios-attendance-panel`** · reset **`min-h-[54px]`** | **REFACTORED** |
| **`attendance_history.php`** | Wave **6 · v15** | Section rhythm drift | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · filters/summary **`gap-6`** · **`tp-native-select`** · list rows **`tp-ios-attendance-panel`** | **REFACTORED** |
| **`profile.php`** | Wave 6 · v15 | Flat stack · **`input-field`** | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · nav/grid **`gap-6`** · section **`space-y-6`** · list rows **`tp-ios-attendance-panel`** · modal fields **`tp-native-*`** | **REFACTORED** |
| **`payslip.php`** | Wave 6 · v15 | List/detail headers | **`tp-ios-master-screen`** · slip header · list **`tp-ios-large-title-block`** · YTD **`gap-6`** · **`tp-native-select`** (ปี) · slip rows **`tp-ios-attendance-panel`** | **REFACTORED** |
| **`certificate.php`** | Wave 6 · v15 | Form/list cards | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · grid **`gap-6`** · form **`tp-native-*`** · hub/history **`tp-ios-attendance-panel`** | **REFACTORED** |
| **`dayoff_schedule.php`** | Wave 6 · v15 | Month filter spacing | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · **`tp-native-select`** · weekly **`space-y-6`** | **REFACTORED** |
| **`login.php`** | Polish | — | Token radii | **REFACTORED** |
| **`verify_document.php`** (public) | Wave **8** | Inline-only styles | **`app.css` + `native-shell.css?v=15`** · **`native-card`** · **`tp-native-*`** controls · captions **`tp-ios-caption-muted`** | **REFACTORED** |
| **`certificate_print.php`** | Wave **9** | Hardcoded toolbar radii · indigo primary | **`native-shell.css?v=15`** (tokens) · screen chrome **`border-radius`/touch โทเค็น** · พิมพ์ปุ่มตรง **`tp-native-btn-primary`** gradient · **`.page`** ล็อกฟอนต์ **Sarabun** | **REFACTORED** |
| **`hr/*.php`** (admin) | Wave **7 · v15** | **`tp-hr-admin-stack`** only | เพิ่ม **`tp-ios-master-screen`** · หัวแต่ละหน้าเป็น **`tp-ios-large-title-block`** (`<header>`) · **`mb-6`** / กริดสรุป **`gap-6`** สอดคล้อง ESS | **REFACTORED** |

**Regression:** PHPUnit / API untouched. Viewport QA: **`03`** · **`07_SPACING_QA.md`** (**route list → `08`**). **`08_VISUAL_QA_AFTER.md`** — per-route **ESS · HRA · PUB/AUTH** check matrices.

**Deploy:** **[`DEPLOY_CHECKLIST.md`](DEPLOY_CHECKLIST.md)** — ก่อน deploy: cache-bust **`native-shell.css`** สม่ำเสมอ · หลัง deploy smoke ESS/HRA/login/verify พร้อม rollback note.

---

### Per-route detail (Phase 9)

Optional deep-dive rows (**Page** · **Route** · **File** · **Components** · **Visual** · **UX** · **Spacing** · **Mobile** · **Tablet** · **Regression**) — add when a release or defect triage needs a paper trail.

**Current step:** human **QA only** — execute **`07_SPACING_QA.md`** and **`08_VISUAL_QA_AFTER.md`**; dashboard-only gate remains **`03_MASTER_SCREEN_VISUAL_QA.md`** for **`index.php`**.
