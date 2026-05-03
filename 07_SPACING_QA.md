# 07_SPACING_QA.md — TP-HR IOS26 spacing gate

Apply **per page** after refactor in **`06_IMPLEMENTATION_PROGRESS.md`**.

**Which pages?** Full route list (ESS · HRA · PUB) is in **`08_VISUAL_QA_AFTER.md`** row matrices — run **`07`** alongside **`08`** questions on the same pass when possible. For breakpoints and scroll/tab behaviour use **`10_BROWSER_VIEWPORT_QA.md`**.

Target tokens: **`00_IOS26_DESIGN_DIRECTION.md`** + **`01_IOS26_COMPONENT_SYSTEM.md`**.

### Global PASS criteria (blocking any page)

| # | Check | Method |
|---|-------|--------|
| 1 | Page L/R inset **≥16px** mobile (**24px tablet**) | Inspect `main.tp-native-page*` |
| 2 | Header-to-first section **≥16px** | DevTools ruler |
| 3 | Between titled sections visually **≥24px** | screenshot |
| 4 | Card-to-card **≥16px** | markup gap tokens |
| 5 | Card inner pad **≥20px** mobile | replace `p-4` outliers |
| 6 | Field group spacing **≥18px** | `.tp-native-form-group` |
| 7 | Inputs **≥56px** tall | ruler |
| 8 | Primary CTAs **≥58px** | ruler |
| 9 | CTA above tab **`24px`** + **`--tp-bottom-nav-slot`** clearance | screenshot |
|10 | **`--tp-scroll-end-buffer`** so last line unobstructed — **≥160** — **FAIL if overlap** |
|11 | Tabs never collide with body | scroll end |
|12 | Last element readable | scroll full down |
|13 | Inputs not hidden behind keyboard (**forms only**) — test iOS Simulator / device |
|14 | No **cramped stacking** unrelated tokens | heuristic |
|15 | Columns align vertically on grid breakpoints | breakpoint QA |

Any **FAIL:** fix **`native-shell`** token first; then page-level classes.

### Latest automated checkpoint (2026-04-28)

- Static shell gate: **PASS** (`npm run -s verify:static-ui`)
- Touch-target sweep (`min-h/min-w` under 48 on interactive controls): **PASS after remediation**
- ESS **`tp-native-modal`** outer padding **`p-5`** (aligned with **`hr/*.php`**): **`checkin.php`** · **`leave_history.php`** · **`profile.php`** · **`dayoff_schedule.php`**
- **`certificate.php` / `payslip.php` / `hr/index.php` / `hr/api_keys.php` / leave `request_form`** + **`templates/header.php` sidebar** + **`footer` toast**: padding lifted to **`p-5`/`p-5 md:p-6`/`p-5 sm:p-6`** where cards/list gutters were **`p-4`/`p-3`** outliers
- Guest E2E (`PLAYWRIGHT_SKIP_TABLET=1`, `PLAYWRIGHT_BASE_URL=http://127.0.0.1/tp-hr/ npm run -s test:e2e:ci`): **31 passed** (2026-04-28, after padding sweep)

---

### Page checklist (duplicate template per route)

```
Route: _________ Date: _____ Tester: _____ Device: _____
Criteria 01–15: [ ] PASS
Notes:

```
