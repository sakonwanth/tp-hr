# 04 — Page Refactor TODO (TP-HR)

Each task: specific, actionable. Complete in order P0 → P2 unless dependencies block.

| ID | Page | Route | File path | Exact issue | Exact solution | Component replacement | UX to add | Native rule | Risk | Testing checklist | Done |
|----|------|-------|-----------|-------------|--------------|----------------------|-----------|-------------|------|-----------------|------|
| HR-UI-001 | Dashboard | `/` | `index.php` | Mixed legacy cards/gradients; hierarchy varies | Rebuild sections with `NativeCard` + `NativeQuickActionCard`; single section gap 16/24 | glass/stat/quick → Native* | Empty state for quick actions if no data | §2 tokens, §4 hierarchy | Low | Load /, 375px, tab overlap | **Done 2026-04-28** |
| HR-UI-002 | Login | `/login.php` | `login.php` | Standalone layout off shell tokens | Wrap with same typography/spacing tokens; `NativeInput`, `NativeButtonPrimary` | custom → Native* | Error state inline, focus order | §8 forms | Med | Login fail/success | **Done 2026-04-28** |
| HR-UI-003 | Check-in | `/checkin.php` | `checkin.php` | Flow density | Card sections; sticky CTA if primary action exists | ad-hoc → NativeCard + StickyPrimaryAction | Loading/success | Touch 48px | High | Submit + GPS | **Done 2026-04-28** |
| HR-UI-004 | Leave hub | `/leave.php` | `leave.php` | Hub clutter | NativeSectionTitle + list; bottom buffer | mixed → Native* | Back/context | §9 nav | Med | Tab bar | **Done 2026-04-28** |
| HR-UI-005 | Leave history | `/leave_history.php` | `leave_history.php` | Table on mobile | **NativeTableToCardPattern** card list <768px | table only → + cards | Empty state | §2 no H-scroll | Med | Narrow viewport | **Done 2026-04-28** |
| HR-UI-006 | Leave form partial | — | `modules/employee/leaves/request_form.php` | Field grouping | `NativeFormGroup` + helper text | mixed | Required indicators | §8 | Med | Validation | **Done 2026-04-28** |
| HR-UI-007 | Payslip | `/payslip.php` | `payslip.php` | List/modal density | NativeListItem + modal shell | mixed | Loading skeleton | §4 states | Med | Open slip | **Done 2026-04-28** |
| HR-UI-008 | Profile | `/profile.php` | `profile.php` | Long vertical form | Grouped `NativeCard` | sections | Save feedback | §8 | Low | POST profile | **Done 2026-04-28** |
| HR-UI-009 | Certificate | `/certificate.php` | `certificate.php` | Form UX | NativeFormGroup | mixed | Success state | §8 | Low | Submit | **Done 2026-04-28** |
| HR-UI-010 | Day-off schedule | `/dayoff_schedule.php` | `dayoff_schedule.php` | Grid overflow | Responsive grid + card cells | mixed | Legend | §5 layout | Med | 375px | **Done 2026-04-28** |
| HR-UI-011 | Attendance history | `/attendance_history.php` | `attendance_history.php` | Table | Card list mobile | table | Empty | §2 | Med | Scroll | **Done 2026-04-28** |
| HR-UI-012 | Verify doc | `/verify_document.php` | `verify_document.php` | Public clarity | NativeInfoBlock + form | plain | Error empty | §1 a11y | Low | Invalid code | **Done 2026-04-28** |
| HR-UI-013 | Cert print | `/certificate_print.php` | `certificate_print.php` | Screen preview | Optional `@media screen` card wrapper | print CSS | — | Print safe | Low | Print dialog | **Done 2026-04-28** |
| HR-UI-014 | HR dashboard | `/hr/index.php` | `hr/index.php` | Widget inconsistency | NativeSummaryCard grid | mixed | — | §2 | Low | HR login | **Done 2026-04-28** |
| HR-UI-015 | Employees | `/hr/employees.php` | `hr/employees.php` | Table | Shell + mobile cards | data-table | Search/filter touch | §2 | Med | Filter | **Done 2026-04-28** |
| HR-UI-016 | Employee form | `/hr/employee_form.php` | `hr/employee_form.php` | Long form | **NativeProgressStep** or grouped cards | long form | Section save hints | §8 | High | Edit employee | **Done 2026-04-28** |
| HR-UI-017 | Employee view | `/hr/employee_view.php` | `hr/employee_view.php` | Detail wall | NativeDataCard sections | mixed | Quick actions | §4 | Med | Links | **Done 2026-04-28** |
| HR-UI-018 | Emp attendance | `/hr/employee_attendance.php` | `hr/employee_attendance.php` | Table | Card mobile | table | — | §2 | Med | | **Done 2026-04-28** |
| HR-UI-019 | Attendance mgmt | `/hr/attendance.php` | `hr/attendance.php` | Filters + table | NativeFilterBar + cards | mixed | Loading | §2 | Med | | |
| HR-UI-020 | Leaves approval | `/hr/leaves.php` | `hr/leaves.php` | Approve buttons | Large `NativeButtonPrimary`; **NativeConfirmationDialog** | small links | Pending count | §4 approval | High | Approve/reject | |
| HR-UI-021 | Day-off approvals | `/hr/dayoff_approvals.php` | `hr/dayoff_approvals.php` | Same | Same | mixed | Confirm | High | CEO | |
| HR-UI-022 | Documents | `/hr/documents.php` | `hr/documents.php` | Upload list | List cards | table | Empty | Med | | |
| HR-UI-023 | Doc templates | `/hr/document_templates.php` | `hr/document_templates.php` | Complex | Section cards | mixed | Warn on delete | High | | |
| HR-UI-024 | Reports | `/hr/reports.php` | `hr/reports.php` | Wide tables | Card summary + scroll shell | table | Export feedback | Med | | |
| HR-UI-025 | Settings | `/hr/settings.php` | `hr/settings.php` | Many fields | NativeFormGroup blocks | mixed | Save toast | Med | | |
| HR-UI-026 | API keys | `/hr/api_keys.php` | `hr/api_keys.php` | Secrets | Warning blocks + confirm | mixed | Mask/reveal UX | High | | |

**Global shell (applies to all header/footer pages)**

| ID | Scope | File(s) | Issue | Solution |
|----|-------|---------|-------|----------|
| HR-UI-SHELL-01 | Bottom tab | `templates/footer.php` | Icon/height vs lock | Enforce max 72px bar, 24px icons, labels 14px, 48px hit |
| HR-UI-SHELL-02 | Tokens | `assets/css/native-shell.css` | Drift | Keep single source; bump cache param in header |
| HR-UI-SHELL-03 | Registry | `templates/native/component_registry.php` | Discoverability | Require in new pages optional |
