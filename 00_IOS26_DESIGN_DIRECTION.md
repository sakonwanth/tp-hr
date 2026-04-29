# 00_IOS26_DESIGN_DIRECTION.md — TP-HR  

**PROJECT_TARGET:** `tp-hr`  
**Purpose:** Locked **visual foundation** before any page-wide refactor (Phase 6).  
**Status:** Approved direction — implementation must align **`native-shell.css`** + this document.  

References: **`../tp-common/ui-ux-pro-max/.claude/skills/design-system/references/token-architecture.md`** (three-layer tokens), **`assets/css/native-shell.css`** (ground truth until token bump waves).

---

## 1. Visual style direction

- **Look:** Polished **iOS 26–inspired** mobile shell — layered depth, calm rhythm, readable content-first typography; **not** a generic Tailwind-admin “web dashboard.”  
- **Feel:** Native-like **thumb zones**, predictable navigation, optimistic spacing; scrolling content **never fights** bottom chrome.  
- **Skin:** Dark brand gradient shell (`--tp-bg-app`) with **glass-heavy chrome** + **muted solid content cards** (`--tp-surface-*`) — glass on chrome only, not across dense grids.  
- **Motion/spacing ethos:** Enough air that every section owns its story; cards align to a vertical grid (`max-width` dashboards/forms per tokens).

---

## 2. Liquid Glass usage rules (strict)

Apply **backdrop blur + hairline luminance separation** primarily to:

1. Bottom **floating / liquid tab strip** (`IOSLiquidTabBar` semantics in registry).  
2. **Sticky CTA strips** sitting above tabs.  
3. **Compact header** toolbars — search, segmented filters, trailing actions.  
4. **Primary/secondary chrome buttons** (` tp-ios-*-btn ` classes) — not arbitrary `div`s.  
5. **Modal / sheet headers** (`--tp-sheet-radius`) and **bottom sheets** framing actions.  

**Do not** stack glass-on-glass inside **data-dense lists** or **long forms** — use **opaque / low-blur wells** (`--tp-surface-well-*`) so text stays AAA-ish on OLED.

---

## 3. Spacing rules

**Primitives (only these steps):** `4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 · 48` px  

| Role | Minimum / value |
|------|----------------|
| Mobile page horizontal inset | **16px** (`--tp-page-pad-mobile`) |
| Tablet page horizontal inset | **24px** (`--tp-page-pad-tablet`) |
| **Section gap (target)** | **24px** — align CSS **`--tp-section-gap-*`** to this on next token wave; interim: **never &lt; 20px** between titled sections |
| Between stacked cards | **16–20px** (`--tp-card-gap-min`) |
| Form group spacing | **≥ 18px** (`--tp-form-group-gap-min`) |
| Sticky CTA above tab strip | **`--tp-cta-gap-above-tabs` = 24px** + safe area |
| **Final scroll buffer** — target | **≥ 160px** (current shell **144px** — **raise on wave** so last line clears tab + FAB) |
| Bottom safe stack | Tab slot **`≥ 96px`** (`--tp-bottom-nav-slot`; includes safe-area) |

Random “12px here, 28px there” spacing is **disallowed** unless it maps onto the ladder via tokens.

---

## 4. Component rules

- One **`component_registry.php`** facade for repeated patterns (`IOS*` entries) — avoid one-off gradients in PHP pages.  
- **Buttons:** three semantic variants only on mobile paths — Primary / Secondary / Destructive; heights locked (see §6).  
- **Cards:** grouped content uses **rounded 24px** outer radius (`--tp-ios-card-radius`); inset grouped lists use nested radius rules consistent with registry.  
- **Lists:** Prefer **mobile card-rows** (`tp-ios-settings-row`-style), not bordered HTML `<table>` on phone — tables map to **pattern** “table→card” (Phase 9 / `01_IOS26_COMPONENT_SYSTEM.md` §27).

---

## 5. Navigation rules

- **ESS routes** (`$current_page` not `hr-*`): **four-tab bottom chrome** via `templates/footer.php` — **stretch-width** strip (`IOSLiquidTabBar`), **`aria-current="page"`**.  
- **HR admin routes** (`hr-*`): typically **no** bottom tabs — use **compact header + contextual back/stack** documented per screen (`05_PAGE_TO_IOS26_PATTERN_MAP.md`).  
- **Depth:** Drill-down pushes content; FAB/sticky attendance actions stay above tab slot on ESS only.

---

## 6. Button rules

| Variant | Height target | Radius | Notes |
|---------|---------------|--------|--------|
| Primary | **58px target** (`--tp-native-btn-min` may be 56px until bump — **Phase 7 measures**) | `clamp(18px,3vw,22px)` | One primary per viewport when possible |
| Secondary | **54px** (`--tp-native-btn-secondary-min`) | Same | Outline / subdued fill |
| Destructive | Same as secondary | Same | Hue from semantic danger token |

**No wrapped label** on CTAs — short copy or two-line clamp with designer sign-off.

---

## 7. Form rules

- Input min height **56px** target (implementation **54px min** until token wave).  
- Tap targets **≥ 48×48**.  
- `textarea` min height **120px** target (currently **112px** — reconcile in QA).  
- One column on mobile unless **tablet breakpoint** expands to `--tp-max-content-form`.

---

## 8. Card rules

- **Outer radius:** 24px.  
- **Padding:** mobile **20px** target (**current 18–20px OK** — converge to 20 locked).  
- **Tablet:** 24px padding.  
- **Separation:** Prefer **hairline divider** + gap over double borders — no gratuitous boxed frames.

---

## 9. List rules

- **Dense admin:** zebra **only** inside opaque wells — outer container stays calm.  
- **Mobile:** swipe row affordances optional Phase 9; minimum is **readable title + subtitle + trailing meta** stacked with 16px gutters.

---

## 10. Bottom tab rules (`ESS`)

- Strip height **`≤ 72px`** component (`--tp-bottom-nav-max-h`); slot reserves **≥ 96px** with Home Indicator.  
- **Floating** glass strip — blurred background, pinned `padding-bottom` on `main` via **`--tp-bottom-nav-slot`**.  
- **Icons + labels:** active fill **muted tint**, never pure white slabs.

---

## 11. Safe area rules

- **Top:** Headers respect **`env(safe-area-inset-top)`** (`padding-top` on toolbar / large title stack).  
- **Bottom:** All scrollable **`main`** use **`calc(env(safe-area-inset-bottom)`** through **`--tp-bottom-nav-slot`** and **`--tp-scroll-end-buffer`** — **no FAB under home indicator.**  
- **Landscape:** Respect side insets (`env(safe-area-inset-left/right)`).

---

## 12. Screenshot QA criteria (blocking before wave rollout)

Blocking **FAIL** if any captured on **390×844** (and **375×667** spot):

| # | Check |
|---|--------|
| 1 | Horizontal scroll/no clipped page body (`overflow-x: clip`). |
| 2 | Last content line **fully above** tab strip + respects **scroll buffer**. |
| 3 | Bottom tab readable; active tab obvious. |
| 4 | Glass visible on tab strip + sticky CTAs/modals — **not** washed flat grey. |
| 5 | No label truncated on primary CTAs (Thai truncation reviewed). |
| 6 | No mixed border radii inside one card hierarchy. |

---

## Delta note (CSS vs specification)

**`native-shell.css` v15 (2026-04-30):** floating **Liquid Glass** bottom tab **pill** (blur + saturation + inset radius `--tp-sheet-radius`), bottom **gradient scrim**, **glass sticky CTA** slab (`.tp-ios-sticky-cta-slab`), **typography** caps aligned to §12 (page title max **28px**, section **~19px**). Reserved chrome: `--tp-bottom-nav-slot` includes strip + padding + safe area. Spacing QA: **`07_SPACING_QA.md`** per route.
