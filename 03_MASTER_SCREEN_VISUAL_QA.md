# 03_MASTER_SCREEN_VISUAL_QA.md — Master screen acceptance gate (`index.php`)

**Screen:** ESS Dashboard — **`index.php`**  
**Statuses:** ✅ Pass · ❌ Fail (block Phase 8 wave expansion)

| # | Question | ✅ / ❌ | Notes |
|---|----------|---------|-------|
| 1 | Looks like a polished **native-feel mobile app** (dense web admin metaphor gone)? | | |
| 2 | Reads **iOS 26-inspired** depth (glass + layered opaque cards), not Bootstrap-table soup? | | |
| 3 | **Liquid Glass** clearly visible **only** where rules allow (tabs, sticky CTA, headers)? | | |
| 4 | **Spacing calm**: consistent ladders (16/24/section gaps)—no cramped blocks? | | |
| 5 | Primary action **instantly obvious** (ลงเวลา)—both hero cue + sticky CTA? | | |
| 6 | **Bottom chrome never obscures scroll-end** (`--tp-scroll-end-buffer`/`--tp-bottom-nav-slot`) | | |
| 7 | **Visual balance**: hero weight vs KPI cards vs grids feels intentional? | | |
| 8 | **Cards align** vertically (consistent radii/wells)? | | |
| 9 | **Typography lock** respects scale (titles/body/caption)? | | |
|10 | Clearly **better hierarchy** than Wave-0 screenshots (side-by-side if needed)? | | |

## Device matrix (minimum captures)

| Device / profile | Checked |
|------------------|---------|
| iPhone 14 class — **390×844** | |
| Compact — **375×667** | |
| Tablet portrait — optional **834×1194** | |

## Blocking defects (examples)

Horizontal scroll • sticky CTA under tab strip • blurred content text • clipped last card • mismatched arbitrary radii vs tokens.

**Rule:** any ❌ ⇒ **fix `index.php` CSS/markup footprint** (`assets/css/native-shell.css` bumps w/ **`?v`** discipline) **before** mass Wave replicates.
