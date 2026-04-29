# 01_IOS26_COMPONENT_SYSTEM.md — TP-HR authoritative iOS26 system

**PROJECT_TARGET:** `tp-hr`  
**Replaces/overrides narrative in:** `05_IOS26_COMPONENT_SYSTEM.md` (keep file for backwards links; implement against **this** document).  
**CSS source of truth:** `assets/css/native-shell.css` — **`?v=14`** in **`templates/header.php`** + **`login.php`**.

---

## Locked design tokens

**Spacing primitives (CSS):** `--tp-space-4` … `--tp-space-48`  

| Requirement (spec) | Token / rule | Implementation note |
|---------------------|----------------|------------------------|
| Page padding mobile ≥16px | `--tp-page-pad-mobile` | OK |
| Page padding tablet ≥24px | `--tp-page-pad-tablet` | OK |
| Section gap = **24px** | `--tp-section-gap-*` → **OK** · **`native-shell.css` v14** |
| Card gap | `--tp-card-gap-min` ≥16px | OK |
| Card padding mobile **20–24px** | `--tp-card-pad-mobile` etc. | **20px mobile · 24px tablet** (`v14`) |
| Card radius | `--tp-ios-card-radius` · 24px | OK |
| Control radius | `--tp-radius-button` clamp 18–22px | OK |
| Sheet/modal radius | `--tp-sheet-radius` 28px | OK |
| Primary button height **58px** | `--tp-native-btn-min` | **OK** · **v14** |
| Secondary **54px** | `--tp-native-btn-secondary-min` | **OK** · **v14** |
| Input height **56px** min | `--tp-native-input-min` | **OK** · **v14** |
| Textarea ≥120px tall | `--tp-native-textarea-min-h` | **OK** · **v14** |
| Touch target | `--tp-native-touch-min` ≥48px | OK |
| Bottom nav max height | `--tp-bottom-nav-max-h` ≤72px | OK |
| Bottom chrome slot ≥96px safe | `--tp-bottom-nav-slot` | OK |
| Sticky CTA gap above tabs | `--tp-cta-gap-above-tabs` 24px + safe | OK |
| Final scroll buffer **≥160px** | `--tp-scroll-end-buffer` | **OK** · **160px v14** |

**Typography (target sizes — use existing fluid vars until step-align):**

| Role | Target px | CSS var (current) |
|------|-----------|-------------------|
| Page title | **28** | `--tp-font-page-title` (fluid) |
| Section title | **19** | `--tp-font-section` |
| Card title | **17** | `--tp-font-card-title` |
| Body | **15–16** | `--tp-font-body` |
| Caption | **12–13** | `--tp-font-caption` |
| Hero number | **≤48** | `--tp-font-hero-number` |

---

## Component inventory (27) — definition & source

Each row: **Name** · **Purpose** · **Shell / registry hook** · **Status**

| # | Component | Definition | Source |
|---|-----------|--------------|--------|
| 1 | **iOS26 design tokens** | Single `:root` ladder + semantic aliases | `native-shell.css` `:root` |
| 2 | **iOS26 app shell** | `body.tp-native-app` + gradient + page max width | `.tp-native-app`, `.tp-native-page` |
| 3 | **Safe area wrapper** | `main` padding + `env(safe-area-*)` | `.tp-native-page`, `.tp-native-page--home` |
| 4 | **Large title header** | Scroll-collapsing title stack | `.tp-ios-large-title`, `.tp-ios-large-title-kicker` |
| 5 | **Compact header** | Single-row nav / back + title | `.tp-native-header`, `.tp-native-header--compact` |
| 6 | **Floating liquid tab bar** | Glass strip, 4 tabs ESS | `IOSLiquidTabBar` in `component_registry.php` + `.tp-ios-liquid-tab-bar` |
| 7 | **Sticky CTA** | Primary actions above tab slot | `.tp-native-sticky-cta`, home modifier |
| 8 | **Glass button** | Icon/text on glass surface | `.tp-ios-glass-btn` |
| 9 | **Primary button** | High-emphasis CTA | `.tp-ios-primary-btn` |
| 10 | **Secondary button** | Outline/subdued | `.tp-ios-secondary-btn` |
| 11 | **Destructive button** | Danger action | `.tp-ios-destructive-btn` |
| 12 | **Card** | Default content container | `.tp-ios-card`, `.glass-card` (legacy bridge) |
| 13 | **Inset grouped card** | Settings-style grouped rows | `.tp-ios-inset-group`, row classes |
| 14 | **Form group** | Vertical rhythm for fields | `.tp-native-form-group` + gap token |
| 15 | **Input** | Single-line field | `.tp-native-input` |
| 16 | **Textarea** | Multi-line | `.tp-native-textarea` |
| 17 | **Select** | Native select styling | `.tp-native-select` |
| 18 | **List item** | Pressable row | `.tp-ios-settings-row` / list row patterns |
| 19 | **Bottom sheet** | Full-width sheet surface | `.tp-ios-bottom-sheet` (when present) + `--tp-sheet-radius` |
| 20 | **Modal** | Center dialog | `.tp-ios-modal` / overlay patterns in pages |
| 21 | **Toast** | Non-blocking feedback | App toast container (JS/CSS in app bundle) |
| 22 | **Loading state** | Spinner / progress | `.tp-ios-spinner` |
| 23 | **Skeleton state** | Placeholder shimmer | Phase 9 — wire `tp-skeleton` when needed |
| 24 | **Empty state** | Icon + body + CTA | Per-page `empty` blocks — use card + caption tokens |
| 25 | **Error state** | Inline / banner | Destructive text + secondary repair CTA |
| 26 | **Success state** | Confirmation | Subtle success tint + check |
| 27 | **Table→card pattern** | Responsive admin tables | Card list at `md` down; table at `lg` up |

---

## PHP registry

**`templates/native/component_registry.php`** — render helpers for **6–11** and structural chrome. **Do not** fork new button classes in random PHP files.

---

## Non-goals (this file)

- Does not list business rules or API contracts.  
- Does not replace **`01_FULL_UI_INVENTORY.md`** route table — use **`04_FULL_UI_INVENTORY.md`** Phase 4 pointer.

---

## Next

- **`02_MASTER_SCREEN_DESIGN.md`** — first screen: **`index.php`** (ESS dashboard).  
- Token delta closure tracked in **`07_SPACING_QA.md`**.
