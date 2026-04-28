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
| Other pages in 01 §A | **REGRESSION_PENDING** |

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
