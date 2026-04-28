# 06 — Implementation Progress (TP-HR)

**Last updated:** 2026-04-28 (Phase 5 close: HR-UI-026 + 08/09 sync)

## Global shell

| Item | Files | Status before | Status after | Notes |
|------|-------|---------------|--------------|-------|
| Component registry | `templates/native/component_registry.php` | N/A | **COMPLETE** | New |
| Docs Phases 0–5 | `docs/native-app-overhaul/01–05` | N/A | **COMPLETE** | Discovery + lock map |
| Bottom tab bar | `templates/footer.php` | Partial | **REFACTORED** | 72px row, icons `text-2xl` (24px), labels `text-sm` (14px), hit ≥48px |
| Native CSS | `assets/css/native-shell.css` | v6 | **UPDATED** | Changelog v8; cache `?v=8` in header |

## Per-page (from 04_PAGE_REFACTOR_TODO)

| Task ID | Status | Notes |
|---------|--------|-------|
| HR-UI-001 Dashboard | **COMPLETE** | `index.php` — NativeCard, solid stat icons, quick actions, empty states, spacing 16/24 |
| HR-UI-002 Login | **COMPLETE** | `login.php` — solid canvas, native card shell, form labels/for, success/error blocks, 48px toggle |
| HR-UI-003 Check-in | **COMPLETE** | `checkin.php` — native cards, banners, modals `tp-native-modal`, solid CTAs, radius 20px |
| HR-UI-004 Leave hub | **COMPLETE** | `leave.php` — native cards, alerts, `tp-native-table-shell`, quick links, empty states |
| HR-UI-006 Leave form | **COMPLETE** | `request_form.php` — `tp-native-form-group`, native card form, upload/actions |
| HR-UI-005 Leave history | **COMPLETE** | `leave_history.php` — mobile card stack, `tp-native-table-shell`, modal shell, pagination |
| HR-UI-007 Payslip | **COMPLETE** | `payslip.php` — native cards, list rows, print-safe `.native-card` |
| HR-UI-008 Profile | **COMPLETE** | `profile.php` — native cards, tabs radius 20px, list rows, modal shell, table shell |
| HR-UI-009 Certificate | **COMPLETE** | `certificate.php` — tp-native-form-group, native cards, history card stack, print/download buttons |
| HR-UI-010 Day-off schedule | **COMPLETE** | `dayoff_schedule.php` — native cards, alerts, week grid radius 20px, legend, tp-native-modal |
| HR-UI-011 Attendance history | **COMPLETE** | `attendance_history.php` — native cards, mobile stack, `tp-native-table-shell`, empty states |
| HR-UI-012 Verify document | **COMPLETE** | `verify_document.php` — dark native-style public card, labels, error panel, optional `doc` field |
| HR-UI-013 Certificate print | **COMPLETE** | `certificate_print.php` — screen shell + pages stack; toolbar POST lang switch; print layout unchanged; `$isHrDash` doc-number gate |
| HR-UI-014 HR dashboard | **COMPLETE** | `hr/index.php` — `tp-native-summary-card` stats, native data cards, quick-action grid, table shell, reject modal |
| HR-UI-015 Employees list | **COMPLETE** | `hr/employees.php` — native filter card, stat links, table shell + 44px actions, leave modal, empty state |
| HR-UI-016 Employee form | **COMPLETE** | `hr/employee_form.php` — native section cards, tabs a11y, tp-native inputs, sticky actions, password card |
| HR-UI-017 Employee view | **COMPLETE** | `hr/employee_view.php` — native header + stat cards + data sections; attendance link; gender/marital labels + status colors |
| HR-UI-018 Employee attendance | **COMPLETE** | `hr/employee_attendance.php` — native filters, stat cards, mobile cards 20px, tp-native-table-shell desktop |
| HR-UI-019 HR attendance mgmt | **COMPLETE** | `hr/attendance.php` — stat cards + filter card + native table/list; dept preserved on stats/nav; modals tp-native-modal |
| HR-UI-020 Leaves approval | **COMPLETE** | `hr/leaves.php` — stat/filter native cards; table shell + large approve/reject; approve confirm modal replaces `confirm()` |
| HR-UI-021 Day-off approvals (CEO) | **COMPLETE** | `hr/dayoff_approvals.php` — stat cards + filters; modals แทน `confirm`; POST approve/reject unchanged |
| HR-UI-022 Documents requests | **COMPLETE** | `hr/documents.php` — stat/filter native cards; table shell + action buttons; stub detail modal แทน `alert()` |
| HR-UI-023 Doc templates | **COMPLETE** | `hr/document_templates.php` — `native-card`/`tp-native-card`, filters unchanged; table shell + empty state; modals replace `confirm()` for template delete & signature remove; `tp-native-input`/`select`/`textarea` |
| HR-UI-024 Reports (CEO) | **COMPLETE** | `hr/reports.php` — native cards, filters `tp-native-input`/`select`, tabs/touch targets; `tp-native-table-shell` desktop; mobile cards + `tp-native-empty-state`; flash + Export CSV hint; queries/POST CSV export unchanged |
| HR-UI-025 Settings (CEO) | **COMPLETE** | `hr/settings.php` — native cards/tabs/forms; `tp-native-table-shell` + empty holidays; modal ลบวันหยุด แทน `confirm()`; leave-type modal `tp-native-modal`; POST/CSRF/actions unchanged |
| HR-UI-026 API keys | **COMPLETE** | `hr/api_keys.php` — native cards + `tp-native-table-shell`; revoke/activate confirm `tp-native-modal` + POST `action`/`id`/`csrf_token` unchanged; one-time key mask/reveal + copy + toast; request log shell + empty state |
| HR-UI-SHELL-01 | **REFACTORED** | — |
| HR-UI-SHELL-02 | **COMPLETE** | — |
| HR-UI-SHELL-03 | **COMPLETE** | — |

**Allowed status:** NOT_STARTED | IN_PROGRESS | REFACTORED | REGRESSION_FAIL | FIXED | COMPLETE  

**Policy:** No page marked COMPLETE until `07_PAGE_REGRESSION_AFTER.md` shows **REGRESSION_PASS** for that page.
