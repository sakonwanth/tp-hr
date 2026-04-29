# 02_MASTER_SCREEN_DESIGN.md — ESS Home / Dashboard

**PROJECT_TARGET:** `tp-hr`  
**Route:** `/` · `/index.php`  
**Entry file:** `index.php`  
**Role:** **ESS primary** (+ optional **HR KPI** strip when `$isHR`)  
**Purpose:** Canonical **mobile-first native shell experience** demonstrating **Glass chrome + grounded content**.

> **Difference from checklist-instruction example:** blueprint mentioned “check-in home” (`tp-checkin`). For **tp-hr**, the analogous **tier-0 attendance surface lives on `checkin.php`**, but **`index.php`** is the **overall product home / dashboard** — it is chosen as **master pattern** because it composes hero, KPIs (HR), shortcuts, sticky CTA, and bottom tabs in one coherent screen.

---

## Information architecture (frozen — logic unchanged)

Existing PHP `$stats`, `$myData`, `$announcements` queries remain. This doc reshapes **presentation only**:

1. **Hero** — greeting + contextual lines (date, role/position).  
2. **[HR only]** **Stat ribbons** — 4 KPI tiles (`total_employees`, `today_attendance`, `pending_leaves`, `pending_documents`).  
3. **Main column** — **Quick Actions** grid → **Today’s attendance** card → **Leave balance** stacked rows.  
4. **Aside column** (collapses below on mobile) — **Pending leave chips** → **Announcements feed** → **[HR] approvals shortcut list**.  
5. **Sticky CTA strip** (`home-sticky-cta`) — always routes to **`/checkin.php`**.  
6. **Floating liquid tab bar** — `templates/footer.php` four tabs (**Dashboard · Check-in · Leave · Profile** semantics per `$current_page`).

---

## Wireframe hierarchy (conceptual — target state)

```
┌──────────────────────────────────────┐
│ Safe-area top inset                  │
│ [Compact / Large title header zone] ★ │
├──────────────────────────────────────┤
│ █ Hero card (readable, low glass)    │
│   • Page title (“สวัสดี …”)           │
│   • Context rows (calendar, role)    │
│   • Trailing promoted CTA (? optional) │
├──────────────────────────────────────┤
│ █ [HR KPI] 2×2 or horizontal scroll │
├──────────────────────────────────────┤
│ █ Section: ทางลัดด่วน (2×N grid)     │
├──────────────────────────────────────┤
│ █ Section: การลงเวลาวันนี้ (status) │
├──────────────────────────────────────┤
│ █ Section: วันลาคงเหลือ (progress)  │
│         [aside: pending / news / HR ] │
└──────────────────────────────────────┘
│ █ Sticky CTA (Glass primary only) ↑  │
├══════════════════════════════════════┤
│ █■■ Liquid Glass Tab Bar (ESS) █■■   │
└──────────────────────────────────────┘
```

★ Choose **either** `.tp-ios-large-title` scroll stack **or** compact header — Phase 8 decides per visual QA (`03_MASTER_SCREEN_VISUAL_QA.md`).

---

## Component binding (tokens + registry)

| Region | Locked classes / identifiers | Tokens |
|--------|-------------------------------|--------|
| Page wrapper | `tp-dashboard-stack` · `tp-native-stack--page` | `--tp-max-content-dashboard` |
| Hero | `.dashboard-hero*` + `.tp-ios-page-title` | `--tp-font-page-title`, inset padding |
| HR stats | `.tp-native-summary-card.stat-card` | gap **16–20**, radius **24** |
| Data cards | `.native-card` · `.tp-native-data-card` | `--tp-ios-card-radius`, padded wells |
| Quick actions | `.tp-native-quick-action-card` | 48×48 touch, **no random radii** |
| Sticky CTA | `.home-sticky-cta-inner` · `.tp-native-btn-primary` | `bottom`: `calc(tab + gap)` |
| Tabs | **`IOSLiquidTabBar`** footer | blur **high**, labels contrast |

---

## Spacing checklist (blocking)

| Guideline | Verification |
|-----------|----------------|
| Horizontal page gutters **≥16 mobile** | `main.tp-native-page--home` paddings |
| **Section rhythm** visually **≥24px** between titled blocks | unify `gap-y`/`space-y` to token ladder |
| **Card inner padding ≥20** mobile | replace `p-4` outliers where needed |
| **Final scroll ≥160 target** once token wave lands | QA in `07_SPACING_QA.md` |

---

## Visual differentiation goals (why it won’t feel “legacy web”)

1. Kill **floating mixed radii**: anything `rounded-[20px]` → **`rounded-[var(--tp-ios-card-radius)]`**.  
2. **Single glass plane** for footer + sticky CTA; cards use opaque wells not extra glass.  
3. **Buttons** routed through **`tp-native-btn-primary`** ladder — eliminate duplicate `.btn-primary` visual forks over time **without** renaming PHP actions in this Phase.  

---

## Implementation order (single screen)

1. **CSS token alignment** (.hero / `.tp-dashboard-stack`) — spacing only before PHP churn.  
2. **Sticky CTA** · **Liquid tab overlap** QA at **390×844**.  
3. **HR KPI grid** responsiveness — swipe row on XS if columns overflow horizontally (anti-pattern forbidden).  

**Do not** touch SQL / `Auth::*` branches.

---

## Implementation status (`native-shell.css` **v15**)

| Item | Done |
|------|------|
| Floating **Liquid Glass** tab pill (`--tp-sheet-radius`, blur + saturate, inset horizontal margin) | Yes |
| Bottom **gradient scrim** behind tab (depth, not flat grey slab) | Yes |
| **Glass** sticky CTA container (`.tp-ios-sticky-cta-slab`) above tab slot | Yes |
| **Compact header** glass (mobile `header-glass` blur + saturation bump) | Yes |
| **Attendance** time block = **opaque well** (`.tp-ios-attendance-panel`) — readable, not stacked glass | Yes |
| Time digits use **`tp-ios-hero-number`** + caption tokens | Yes |
| Master screen marker classes: **`tp-ios-master-screen`**, **`tp-ios-large-title-block`** | Yes |
| Mobile card radius **24px** locked (removed 14px override in `header.php` ≤640px) | Yes |
| Primary hero CTA **58px** min-height (`.btn-primary-prominent`) | Yes |
