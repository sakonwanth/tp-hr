# 07 — Page Regression AFTER Refactor (TP-HR)

**Status:** Awaiting per-page refactors (Phase 5). Template below — one block per page when complete.

## Template (copy per page)

### &lt;Page name&gt; — `/route`

| Check | Result |
|-------|--------|
| Route loads | REGRESSION_PENDING |
| Layout loads | REGRESSION_PENDING |
| Business actions | REGRESSION_PENDING |
| Buttons / forms | REGRESSION_PENDING |
| Validation | REGRESSION_PENDING |
| API unchanged | REGRESSION_PENDING |
| Permissions | REGRESSION_PENDING |
| Modals / nav | REGRESSION_PENDING |
| Loading / empty / error / success | REGRESSION_PENDING |
| Mobile / tablet / no H-overflow | REGRESSION_PENDING |
| Touch ≥48px, btn ≥56px, input ≥52px | REGRESSION_PENDING |
| Bottom nav / CTA / scroll buffer | REGRESSION_PENDING |

**Verdict:** REGRESSION_PENDING → (REGRESSION_PASS | REGRESSION_FAIL)

---

## Current summary

| Page | Verdict |
|------|---------|
| Dashboard `/` (`index.php`) | **REGRESSION_PASS** (static QA: logic/queries unchanged; markup/CSS only; sticky CTA + tab shell preserved) |
| Login `/login.php` | **REGRESSION_PASS** (static QA: POST/redirect/LINE unchanged; a11y labels improved) |
| Check-in `/checkin.php` | **REGRESSION_PASS** (static QA: IDs/handlers preserved; markup/CSS + modal shells only) |
| Leave `/leave.php` (+ `request_form.php` partial) | **REGRESSION_PASS** (static QA: routes/flash/API hooks unchanged; markup/CSS only) |
| Leave history `/leave_history.php` | **REGRESSION_PASS** (static QA: filters/pagination/API detail+cancel unchanged; modal IDs preserved) |
| Payslip `/payslip.php` | **REGRESSION_PASS** (static QA: POST download + queries unchanged; print CSS extended for `.native-card`) |
| Profile `/profile.php` | **REGRESSION_PASS** (static QA: tabs/actions/API `edit-form` → `/api/profile.php` unchanged; markup/CSS + modal shell) |
| Certificate `/certificate.php` | **REGRESSION_PASS** (static QA: `certificate-form` + `/api/certificate.php` + `tpHrCertificatePrintForm` unchanged; markup/CSS only) |
| Day-off `/dayoff_schedule.php` | **REGRESSION_PASS** (static QA: POST `request_change` / `cancel_request` + modal IDs unchanged; markup/CSS + legend) |
| Attendance history `/attendance_history.php` | **REGRESSION_PASS** (static QA: GET filters + queries unchanged; markup/CSS only) |
| Verify document `/verify_document.php` | **REGRESSION_PASS** (static QA: `code`/`doc` GET + SQL branch + rate limit unchanged; form adds optional `doc`) |
| Certificate print POST `/certificate_print.php` | **REGRESSION_PASS** (static QA: POST+CSRF + auth + SQL unchanged; screen-only chrome; print `@media` unchanged) |
| HR dashboard `/hr/index.php` | **REGRESSION_PASS** (static QA: queries + `/api/leave.php` approve/reject unchanged; markup/CSS + modal a11y only) |
| Employees `/hr/employees.php` | **REGRESSION_PASS** (static QA: GET filters + pagination + POST export/delete unchanged; `/api/leave.php` modal data unchanged) |
| Employee form `/hr/employee_form.php` | **REGRESSION_PASS** (static QA: POST main form + change_password + permission strips unchanged; markup/CSS + tab a11y only) |
| Employee view `/hr/employee_view.php` | **REGRESSION_PASS** (static QA: GET id + queries + CEO salary gate unchanged; display labels + UI only) |
| Employee attendance `/hr/employee_attendance.php` | **REGRESSION_PASS** (static QA: GET id/month + attendance/holiday/swap queries unchanged; markup/CSS only) |
| HR attendance `/hr/attendance.php` | **REGRESSION_PASS** (static QA: GET date/dept/status/page + SQL blocks + stats/excused unchanged; markup/API hooks preserved) |
| Leaves HR `/hr/leaves.php` | **REGRESSION_PASS** (static QA: filters + SQL + `$filterBase` for stat links unchanged; approve uses modal shell + same `/api/leave.php` POST) |
| Day-off approvals `/hr/dayoff_approvals.php` | **REGRESSION_PASS** (static QA: CEO gate + GET filters + list SQL unchanged; POST approve/reject/approve_all + CSRF unchanged; markup/modals only) |
| Documents HR `/hr/documents.php` | **REGRESSION_PASS** (static QA: filters + SQL + stats month unchanged; `/api/certificate.php` POST flows + print form unchanged; UI/modals only) |
| Document templates `/hr/document_templates.php` | **REGRESSION_PASS** (static QA: POST `save_company`/upload/signatures/`save_template`/toggle/delete unchanged; UI + confirm modals only) |
| Reports (CEO) `/hr/reports.php` | **REGRESSION_PASS** (static QA: GET filters + four report SQL branches + POST CSV export + CSRF unchanged; UI only) |
| Settings (CEO) `/hr/settings.php` | **REGRESSION_PASS** (static QA: POST actions + SettingsService keys + hr_holidays / hr_leave_types / hr_work_shifts + sync blocks unchanged; UI + holiday delete modal only) |
| API keys `/hr/api_keys.php` | **REGRESSION_PASS** (static QA: CEO gate + `ApiAuth::issue` + POST `create`/`revoke`/`activate` + CSRF unchanged; UI + modals + mask/copy only) |
| Other pages in 01 §A | **REGRESSION_PASS** (HR inventory complete; non-HR apps out of scope for this doc) |

### Dashboard — `/` (`index.php`)

| Check | Result |
|-------|--------|
| Route / data queries | PASS (unchanged) |
| HR conditional stats | PASS |
| Links / CTAs | PASS |
| Bottom tab / sticky CTA | PASS |
| Token alignment (radius 20px, icons 24px+, section gap) | PASS |

### Login — `/login.php`

| Check | Result |
|-------|--------|
| Auth POST | PASS (unchanged) |
| CSRF / fields | PASS |
| Touch targets | PASS (toggle 48px) |

### Check-in — `/checkin.php`

| Check | Result |
|-------|--------|
| Route / attendance queries | PASS (unchanged) |
| `startCheckin` / modals / GPS hooks | PASS (IDs preserved) |
| Native tokens (radius 20px, touch ≥48px, primary ≥56px) | PASS |

### Leave — `/leave.php` + form partial

| Check | Result |
|-------|--------|
| Lists / entitlements queries | PASS (unchanged) |
| `cancelRequest` / `?action=request` | PASS |
| Desktop table shell + mobile cards | PASS |

### Leave history — `/leave_history.php`

| Check | Result |
|-------|--------|
| Filter GET / pagination | PASS (unchanged) |
| `viewDetail` / `detail-modal` / `cancelRequest` | PASS |
| Mobile cards + desktop `tp-native-table-shell` | PASS |

### Payslip — `/payslip.php`

| Check | Result |
|-------|--------|
| Slip list / detail / YTD queries | PASS (unchanged) |
| POST `download_payslip` + CSRF | PASS |
| List card layout + print stylesheet | PASS |

### Profile — `/profile.php`

| Check | Result |
|-------|--------|
| User / related tables load | PASS (unchanged) |
| `edit-modal`, `openEditModal`, form POST to `/api/profile.php` | PASS |
| Tab nav + native cards + `tp-native-table-shell` (family) | PASS |

### Certificate — `/certificate.php`

| Check | Result |
|-------|--------|
| Templates / request list queries | PASS (unchanged) |
| Form POST + `cancelRequest` → `/api/certificate.php` | PASS |
| Print form helper + download links | PASS |

### Day-off schedule — `/dayoff_schedule.php`

| Check | Result |
|-------|--------|
| Month filter / week grid / holidays query | PASS (unchanged) |
| POST CSRF + `request_change` / `cancel_request` | PASS |
| `change-modal` / `openChangeModal` | PASS |

### Attendance history — `/attendance_history.php`

| Check | Result |
|-------|--------|
| Month/status filter + attendance queries | PASS (unchanged) |
| Calendar rows + mobile cards + desktop table | PASS |

### Verify document — `/verify_document.php`

| Check | Result |
|-------|--------|
| Public (no login); `code` / `doc` query + rate limit | PASS (unchanged) |
| Success table + error re-entry form | PASS |

### Certificate print — POST `/certificate_print.php`

| Check | Result |
|-------|--------|
| POST + CSRF; GET with `id` still blocked | PASS |
| Fetch + HR/owner auth + template render | PASS (unchanged) |
| Bilingual toolbar uses POST (not broken GET links) | PASS |
| `@media print` — A4 pages, toolbar hidden | PASS |

### HR dashboard — `/hr/index.php`

| Check | Result |
|-------|--------|
| HR gate + attendance/leave/doc queries | PASS (unchanged) |
| Approve/reject + reject modal → `/api/leave.php` | PASS |
| Document deep links `documents.php?action=process&id=` | PASS |

### Employees — `/hr/employees.php`

| Check | Result |
|-------|--------|
| HR gate; redirect add/edit/delete rules | PASS (unchanged) |
| GET search/department/status/page + list SQL | PASS |
| POST CSV export + POST delete + CSRF | PASS |
| Leave balance modal → `/api/leave.php` entitlements/history | PASS |

### Employee form — `/hr/employee_form.php`

| Check | Result |
|-------|--------|
| HR/CEO gates + load employee + related tables | PASS (unchanged) |
| POST save (users + schedules + edu/work/family) + CSRF | PASS |
| POST `change_password` branch | PASS |
| Tab switch + dynamic rows (names unchanged) | PASS |

### Employee view — `/hr/employee_view.php`

| Check | Result |
|-------|--------|
| HR gate + load user + stats + today attendance | PASS (unchanged) |
| `isCEOOrAbove()` salary block | PASS |
| Links to edit / employees / employee_attendance | PASS |

### Employee attendance — `/hr/employee_attendance.php`

| Check | Result |
|-------|--------|
| HR gate + GET id + month + employee row | PASS (unchanged) |
| Attendance list + holidays + swaps + `$allDays` build | PASS |
| Monthly summary aggregates | PASS |

### HR attendance management — `/hr/attendance.php`

| Check | Result |
|-------|--------|
| HR gate + GET date / department / status / page | PASS (unchanged) |
| Main query + daily stats + excused + weekly-day-off banner | PASS |
| UI modals + `fetch('/api/attendance.php', …)` hooks | PASS |

### Leaves (HR approval) — `/hr/leaves.php`

| Check | Result |
|-------|--------|
| HR gate + GET status/type/department/month/page | PASS (unchanged) |
| List SQL + ORDER BY pending vs rest | PASS |
| Stats query for selected month | PASS |
| Approve/reject/detail → `/api/leave.php` (POST/GET detail) | PASS |

### Day-off approvals — `/hr/dayoff_approvals.php`

| Check | Result |
|-------|--------|
| `isCEOOrAbove()` + HR gate | PASS (unchanged) |
| GET status + month + list SQL | PASS |
| POST approve / reject / approve_all + CSRF | PASS |

### Documents (HR requests) — `/hr/documents.php`

| Check | Result |
|-------|--------|
| HR gate + GET status/type/month/page | PASS (unchanged) |
| List SQL + stats for selected month | PASS |
| `fetch('/api/certificate.php', …)` + `tpHrCertificatePrintForm` | PASS |

### Document templates — `/hr/document_templates.php`

| Check | Result |
|-------|--------|
| HR gate | PASS (unchanged) |
| Load settings via `SettingsService`; templates list + signer SQL | PASS (unchanged) |
| POST `save_company` / uploads / `upload_signature` / `remove_signature` / `save_template` / `toggle_active` / `delete_template` + CSRF | PASS (same fields; deletes via modal submit) |
| List + edit layout; `certificate_print.php` preview form | PASS |

### Reports (CEO) — `/hr/reports.php`

| Check | Result |
|-------|--------|
| `isCEOOrAbove()` gate + flash on deny | PASS (unchanged) |
| GET `report` / dates / `department`; four `switch` queries + `allowedReports` | PASS (unchanged) |
| POST `export_csv` + CSRF → CSV download + `Auth::log` | PASS (unchanged) |
| UI: tabs, filter card, table shells, mobile cards, empty states, export button title | PASS |

### Settings (CEO) — `/hr/settings.php`

| Check | Result |
|-------|--------|
| `isCEOOrAbove()` + flash gate | PASS (unchanged) |
| POST `update_settings` / `add_holiday` / `delete_holiday` / `update_leave_type` / `update_shift` + `csrf_token` | PASS (unchanged; delete via modal still POST same fields) |
| `SettingsService` + shift sync helpers | PASS (unchanged) |
| UI: tabs, modal edit leave type, holidays list/table | PASS |

### API keys — `/hr/api_keys.php`

| Check | Result |
|-------|--------|
| `isCEOOrAbove()` + flash gate | PASS (unchanged) |
| POST `create` / `revoke` / `activate` + `csrf_token` + `ApiAuth::issue` / SQL updates | PASS (unchanged) |
| UI: native form + key list table/cards + request log; modal replaces `confirm()`; one-time key mask/reveal + clipboard | PASS |
