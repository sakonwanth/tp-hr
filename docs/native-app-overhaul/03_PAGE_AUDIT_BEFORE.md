# 03 — Page Audit BEFORE Refactor (TP-HR)

**Method:** Static review of layout contract (`header.php` / `footer.php` / `native-shell.css`) + known page patterns (forms, tables, modals).  
**Scale per page:** 30 checks → summary **PASS** | **NEEDS_REFACTOR** | **BLOCKED**.

**Legend**

- **NEEDS_REFACTOR** = does not yet fully meet locked tokens (§2), table→card on mobile, UX completeness (§4), or component-only styling.
- **PASS** = already aligned (rare for full spec).
- **BLOCKED** = dependency / security issue preventing UI work (none found).

---

## Employee shell (bottom tabs)

| Page | Route | Verdict | Top findings (abbrev.) |
|------|-------|---------|-------------------------|
| Dashboard | `/` | NEEDS_REFACTOR | Hero/quick actions mix legacy gradients; ensure NativeCard-only; sticky CTA pattern if added |
| Check-in | `/checkin.php` | NEEDS_REFACTOR | Camera/GPS/forms — verify touch 48px, scroll buffer, no overflow |
| Leave | `/leave.php` | NEEDS_REFACTOR | Hub + partial form — step UX, validation feedback |
| Leave history | `/leave_history.php` | NEEDS_REFACTOR | Table/list → card on mobile |
| Payslip | `/payslip.php` | NEEDS_REFACTOR | Lists/modals — loading/empty states |
| Profile | `/profile.php` | NEEDS_REFACTOR | Grouped NativeCard sections |
| Certificate | `/certificate.php` | NEEDS_REFACTOR | Form + status |
| Day-off schedule | `/dayoff_schedule.php` | NEEDS_REFACTOR | Calendar/table responsive |
| Attendance history | `/attendance_history.php` | NEEDS_REFACTOR | Table → card |
| Login | `/login.php` | NEEDS_REFACTOR | Standalone — align AppShell tokens, no desktop-compressed field |
| Verify document | `/verify_document.php` | NEEDS_REFACTOR | Public — readable hierarchy |
| Certificate print | `/certificate_print.php` | NEEDS_REFACTOR | Print — keep; optional mobile preview |

## HR admin shell (sidebar / no bottom tabs)

| Page | Route | Verdict | Top findings |
|------|-------|---------|--------------|
| HR dashboard | `/hr/index.php` | NEEDS_REFACTOR | Widgets → consistent cards |
| Employees | `/hr/employees.php` | NEEDS_REFACTOR | Table shell + mobile cards |
| Employee form | `/hr/employee_form.php` | NEEDS_REFACTOR | Long form — groups / steps |
| Employee view | `/hr/employee_view.php` | NEEDS_REFACTOR | Detail sections |
| Employee attendance | `/hr/employee_attendance.php` | NEEDS_REFACTOR | Tables |
| Attendance mgmt | `/hr/attendance.php` | NEEDS_REFACTOR | Filters + table/card |
| Leaves approval | `/hr/leaves.php` | NEEDS_REFACTOR | Approval UX, confirm dialogs |
| Day-off approvals | `/hr/dayoff_approvals.php` | NEEDS_REFACTOR | CEO flow |
| Documents | `/hr/documents.php` | NEEDS_REFACTOR | Upload/list |
| Document templates | `/hr/document_templates.php` | NEEDS_REFACTOR | Complex form |
| Reports | `/hr/reports.php` | NEEDS_REFACTOR | Export + readability |
| Settings | `/hr/settings.php` | NEEDS_REFACTOR | Grouped settings |
| API keys | `/hr/api_keys.php` | NEEDS_REFACTOR | Sensitive actions — confirm |

## Partials

| File | Verdict |
|------|---------|
| `modules/employee/leaves/request_form.php` | NEEDS_REFACTOR |

## Non-UI

| File | Verdict |
|------|---------|
| `logout.php` | PASS (no HTML layout) |

---

**Totals:** PASS 1 · NEEDS_REFACTOR 26 · BLOCKED 0.
