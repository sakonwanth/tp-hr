# 06 — Implementation progress

| Page / area | Route | File | Status | Files modified | Mobile | Tablet | QA |
|-------------|-------|------|--------|----------------|--------|--------|-----|
| Shell + layout | * | `templates/header.php`, `templates/footer.php` | **COMPLETE** | `tp-native-stack--page`; `dashboard`→`tp-native-page--home` | ✓ | ✓ | ✓ |
| Native CSS | * | `assets/css/native-shell.css` v2 | **COMPLETE** | Section title lock; stack gaps | ✓ | ✓ | ✓ |
| Sticky primary (home) | `/` | `index.php` | **COMPLETE** | Mobile `.home-sticky-cta` → ลงเวลา | ✓ | ✓ | ✓ |
| Dashboard | `/` | `index.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Check-in | `/checkin.php` | `checkin.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Leave | `/leave.php` | `leave.php`, `modules/employee/leaves/request_form.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Payslip | `/payslip.php` | `payslip.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Certificate | `/certificate.php` | `certificate.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Day-off schedule | `/dayoff_schedule.php` | `dayoff_schedule.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Profile | `/profile.php` | `profile.php` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Histories | `/attendance_history.php`, `/leave_history.php` | attendance/leave history | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| HR admin | `/hr/*.php` | listed in `01_FULL_UI_INVENTORY.md` | **COMPLETE** | Inherited shell | ✓ | ✓ | ✓ |
| Login | `/login.php` | `login.php` | **COMPLETE** | Explicit | ✓ | ✓ | ✓ |
| Verify | `/verify_document.php` | `verify_document.php` | **COMPLETE** | Explicit | ✓ | ✓ | ✓ |
| Certificate print | POST `/certificate_print.php` | `certificate_print.php` | **UNCHANGED UI** | — | print | print | N/A |

All authenticated routes pick up **main** landmark + **scroll buffer** + **touch targets** without per-file edits where possible.
