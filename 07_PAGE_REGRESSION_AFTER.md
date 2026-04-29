# 07_PAGE_REGRESSION_AFTER.md — TP-HR (live log)

Execute after **each completed page**:

1. Reload route (**200** desktop + mobile emulation)
2. Form POST still hits same endpoint + CSRF untouched
3. HR permissions gates unchanged (**CEO**/HR scopes)
4. Visual: **no horizontal bleed**, **sticky CTA** clears tab bar (**`var(--tp-bottom-nav-slot)`**)
5. **Touch ≥48**, **buttons ≥ specs** (**56/52** px)

## Global regressions prevented (Wave 1)

| Area | Verification |
|------|----------------|
| Mobile bottom tabs uniform active frame | Stretch grid (**native-shell v12+**) · label `nowrap` |
| Bottom buffer | **`--tp-scroll-end-buffer`** = **144px** |

## Per-route matrix

Populate when Waves 2–3 land. Placeholder:** all routes **PENDING**.

| Route/file | Tester | Device | PASS date |
|------------|--------|--------|-----------|
| `login.php` | Automated | Pending | Pending human |
| `checkin.php` | Automated | Chromium mobile W | Pending human |
| `index.php` | Automated | Pending | Pending human |
| `leave.php` / `request_form.php` | Automated | Pending | Pending human |
| **`leave_history.php`** | Automated | Pending | Pending human |
| **`hr/*.php`** (admin) | Automated | Pending | Pending human |
| **`dayoff_schedule.php`**, **`attendance_history.php`**, **`certificate.php`**, **`payslip.php`**, **`profile.php`** | Automated | Pending | Pending human |
