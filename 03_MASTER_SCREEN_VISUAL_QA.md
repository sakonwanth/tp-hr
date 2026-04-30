# 03_MASTER_SCREEN_VISUAL_QA.md — Master screen acceptance gate (`index.php`)

**Screen:** ESS Dashboard — **`index.php`**  
**Shell:** `assets/css/native-shell.css` **v15** (`templates/header.php` / `login.php`)  
**Statuses:** ✅ Pass · ❌ Fail (block Phase 6 wave until fixed)

After full IOS26 rollout, also record the **`/`** row in **`08_VISUAL_QA_AFTER.md`** so dashboard regressions stay traceable next to other ESS routes.

| # | Question | ✅ / ❌ | Notes |
|---|----------|---------|-------|
| 1 | Looks like a polished **native-feel mobile app** (not a generic web admin table)? | | |
| 2 | Reads **iOS 26–inspired**: layered depth, **glass on chrome** + **opaque content wells**? | | |
| 3 | **Liquid Glass** clearly visible on **floating tab pill**, **sticky CTA slab**, and **mobile header** — not heavy blur on body copy cards? | | |
| 4 | **Spacing calm**: section gaps **24px**, gutters **≥16** mobile / **≥24** tablet — no random tight stacks? | | |
| 5 | Primary action **ลงเวลา** obvious (hero + **glass** sticky CTA + tab “ลงเวลา”)? | | |
| 6 | **Bottom chrome** never clips scroll-end (`--tp-scroll-end-buffer` **160px**, `--tp-bottom-nav-slot` **v15**)? | | |
| 7 | **Visual balance**: hero vs KPI vs grids feels intentional? | | |
| 8 | **Cards align** — **24px** radius on mobile (no leftover **14px** mismatch)? | | |
| 9 | **Typography lock**: page title **≤28px**, section **~19px**, time row uses **hero number** scale? | | |
|10 | Clearly **better** than pre–v15 web dashboard metaphor? | | |

## Device matrix (minimum captures)

| Device / profile | Checked |
|------------------|---------|
| iPhone 14 class — **390×844** | |
| Compact — **375×667** | |
| Tablet portrait — **768+** (optional) | |

## Blocking defects (examples)

Horizontal scroll · sticky CTA hidden under tab pill · **unreadable** blurred paragraph text · last card clipped · mixed radii (14 vs 24) · **wrapped** primary CTA label.

**Rule:** any ❌ ⇒ fix **`index.php` + `native-shell.css` + `header.php` inline tokens** (bump **`?v=`** when CSS changes) **before** rolling the pattern to other routes.

---

## Engineering pass snapshot (automated)

**2026-04-30 · v15**

- **Floating tab bar:** `.tp-native-bottom-tab-nav` = transparent + **gradient scrim** `::before`; `.tp-native-bottom-tab-strip` = **28px** radius glass **pill**, `saturate(200%)` + `blur(28px)`.
- **Sticky CTA:** `.tp-ios-sticky-cta-slab` frosted container around `.tp-native-btn-primary`.
- **Attendance block:** `.tp-ios-attendance-panel` = **well** surface (no stacked glass on copy).
- **Time display:** `.tp-ios-hero-number` + `.tp-ios-caption-muted` (hero number **color** from utilities — base rule does not force white).
- **Chrome slot:** `--tp-bottom-nav-slot` = `max(100px, calc(72px + 12px + safe-area))`.
- **Header (mobile):** `.mobile-app-header.header-glass` stronger blur + saturation (`header.php` inline).

**Human:** fill ✅ / ❌ in the matrix above before closing Phase 3 and starting Phase 6 page-by-page work.
