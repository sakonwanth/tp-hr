# 06_IMPLEMENTATION_PROGRESS.md — TP-HR IOS26 rollout

Statuses: **`NOT_STARTED` · `IN_PROGRESS` · `COMPLETE` · `REGRESSION_FAIL`**.

| Page / area | Wave | Before | After | Status |
|-------------|------|--------|-------|--------|
| Global **`native-shell.css`** | v15 | v14 tokens | **v15** — floating **Liquid Glass** tab pill · gradient scrim · **glass sticky CTA slab** (`tp-ios-sticky-cta-slab`) · typography scale · **`--tp-bottom-nav-slot`** | **COMPLETE** |
| **`templates/header.php` + `login.php`** shell baseline | v15 | `?v=14` + legacy control mins/radii | **`?v=15`** + tokenized mins/radii (nav touch 48, primary 58, secondary 54, card 24) | **COMPLETE** |
| **Touch target sweep (`hr/*.php` + shell controls)** | v15 QA | residual `min-h/min-w` at **40/44/46px** | uplifted to **48px minimum** on action/icon/button affordances | **COMPLETE** |
| **Role-auth QA (EMP/HR/CEO) on production host** | QA closeout | guest-only auth baseline | authenticated Playwright runs passed by role matrix (EMP self-service, HR admin, CEO-only) | **COMPLETE** |
| **`01` · `04` · `05` docs** | Phase 4–5 | Pointer-only · T4 shorthand | **`04`** body + sync rules · **`01`** + **`hr/attendance_adjustments.php`** · **`05`** **T5** + full route table | **COMPLETE** |
| **`index.php`** (dashboard) | MASTER v15 | Wave-2 polish | **`tp-ios-master-screen`** · **glass sticky slab** · **`tp-ios-attendance-panel`** · floating tabs | **COMPLETE** |
| **`checkin.php`** | Wave 6 · align master | **`tp-checkin-stack`** only | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · **เข้า/ออก** **`tp-ios-attendance-panel`** · grids **`gap-6`** · side stacks **`space-y-6`** · wells **`p-5`** · late-start **`space-y-5`** · sticky late CTA **`p-5`** · monthly summary **`space-y-4`** | **COMPLETE** |
| `leave.php` + **`modules/employee/leaves/request_form.php`** | Wave **6 · v15** | Flat header · **`gap-5`** | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · header **`gap-6`** · entitlements **`gap-5 md:gap-6`** · balance + mobile history **`p-5`** · form **`tp-native-*`** · actions **54/58** | **COMPLETE** |
| **`leave_history.php`** | Wave **6 · v15** | Header spacing · filter gap-4 | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** (`<header>`) · **`gap-6`** · **`tp-native-select`** · **`tp-ios-attendance-panel`** · reset **`min-h-[54px]`** | **COMPLETE** |
| **`attendance_history.php`** | Wave **6 · v15** | Section rhythm drift | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · filters/summary **`gap-6`** · **`tp-native-select`** · list rows **`tp-ios-attendance-panel`** | **COMPLETE** |
| **`profile.php`** | Wave 6 · v15 | Flat stack · **`input-field`** | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · nav/grid **`gap-6`** · section **`space-y-6`** · list rows **`tp-ios-attendance-panel`** · modal fields **`tp-native-*`** | **COMPLETE** |
| **`payslip.php`** | Wave 6 · v15 | List/detail headers | **`tp-ios-master-screen`** · slip header · list **`tp-ios-large-title-block`** · YTD **`gap-6`** · **`tp-native-select`** (ปี) · slip rows **`tp-ios-attendance-panel`** | **COMPLETE** |
| **`certificate.php`** | Wave 6 · v15 | Form/list cards | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · grid **`gap-6`** · form **`tp-native-*`** · hub/history **`tp-ios-attendance-panel`** | **COMPLETE** |
| **`dayoff_schedule.php`** | Wave 6 · v15 | Month filter spacing | **`tp-ios-master-screen`** · **`tp-ios-large-title-block`** · **`tp-native-select`** · weekly **`space-y-6`** | **COMPLETE** |
| **`login.php`** | Polish | — | Token radii | **COMPLETE** |
| **`verify_document.php`** (public) | Wave **8** | Inline-only styles | **`app.css` + `native-shell.css?v=15`** · **`native-card`** · **`tp-native-*`** controls · captions **`tp-ios-caption-muted`** | **COMPLETE** |
| **`certificate_print.php`** | Wave **9** | Hardcoded toolbar radii · indigo primary | **`native-shell.css?v=15`** (tokens) · screen chrome **`border-radius`/touch โทเค็น** · พิมพ์ปุ่มตรง **`tp-native-btn-primary`** gradient · **`.page`** ล็อกฟอนต์ **Sarabun** | **COMPLETE** |
| **`hr/*.php`** (admin) | Wave **7 · v15** | **`tp-hr-admin-stack`** only | เพิ่ม **`tp-ios-master-screen`** · หัวแต่ละหน้าเป็น **`tp-ios-large-title-block`** (`<header>`) · **`mb-6`** / กริดสรุป **`gap-6`** สอดคล้อง ESS | **COMPLETE** |

**Spacing parity sweep — ESS เหลือ (wells **`p-5`** / **`p-5 md:p-6`**, grids **`gap-5 md:gap-6`):** **`certificate.php`**, **`payslip.php`**, **`profile.php`** (**`gap-6`** บนการ์ดแถวหลัก), **`leave_history.php`**, **`attendance_history.php`**, **`modules/employee/leaves/request_form.php`**.

**HR admin · spacing parity (wave):** **`hr/document_templates.php`** inner cards/section headers/mobile stack/modals **`p-5`** · **`hr/*.php`** summary **`tp-native-summary-card`** **`p-5`** · inset **`bg-white/5 … p-5`** · รายการมือถือ **`p-5 space-y-4`** (dayoff / adjustments) · **`employee_form.php`** แถว education/wh/fam **`p-5`** · **`outside_attendance`** การ์ดคำขอ **`p-5 sm:p-6`** · **`attendance.php`** แบนเนอร์วันหยุด + บล็อกใน modal **`p-5`** · modal gutter ทั่ว **`hr/*.php`** **`p-5`** (สอดคล้องเอกสารเทมเพลต).

**ESS modals · viewport gutter:** **`checkin.php`**, **`leave_history.php`**, **`profile.php`**, **`dayoff_schedule.php`** — ชั้นนอก **`tp-native-modal`** **`p-5`** (เทียบ **`hr/*.php`**).

**Regression:** PHPUnit / API untouched. Viewport QA: **`03`** · **`07_SPACING_QA.md`** (**route list → `08`**). **`08_VISUAL_QA_AFTER.md`** — per-route **ESS · HRA · PUB/AUTH** check matrices · breakpoint / scroll QA **`10_BROWSER_VIEWPORT_QA.md`**. Static padding/overflow rationale: **`AUDIT_04_VIEWPORT.md`**. **CI:** **`npm run verify:static-ui`** on push/PR/deploy validate — **`native-shell.css`** **`?v=`** consistency + **`tp-ios-master-screen`** on listed full pages (see **`scripts/verify-ios26-master-screen.sh`**).

**Deploy:** **[`DEPLOY_CHECKLIST.md`](DEPLOY_CHECKLIST.md)** — ก่อน deploy: cache-bust **`native-shell.css`** สม่ำเสมอ · หลัง deploy smoke ESS/HRA/login/verify พร้อม rollback note.

---

### Per-route detail (Phase 9)

Optional deep-dive rows (**Page** · **Route** · **File** · **Components** · **Visual** · **UX** · **Spacing** · **Mobile** · **Tablet** · **Regression**) — add when a release or defect triage needs a paper trail.

**Current step:** human **QA only** — execute **`07_SPACING_QA.md`** + **`08_VISUAL_QA_AFTER.md`** + **`10_BROWSER_VIEWPORT_QA.md`**; dashboard **`03_MASTER_SCREEN_VISUAL_QA.md`** (**`/`** / **`index.php`**). Narrative readiness → **`09_COMPLETION_GATE.md`**.
