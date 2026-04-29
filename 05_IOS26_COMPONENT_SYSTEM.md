# 05_IOS26_COMPONENT_SYSTEM.md — TP-HR authoritative spec

> **⚠️ Superseded by `01_IOS26_COMPONENT_SYSTEM.md` (expanded 27-component taxonomy + spacing targets · 2026-04-30).** Keep this file for historical backlinks only.

Aligned with **`assets/css/native-shell.css`** (v12) + UI UX Pro Max skill (**touch/accessibility/layout** tiers).

---

## Token ladder (canonical)

Spacing: **4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 · 48** px — CSS vars `--tp-space-*`.

| Token | Meaning |
|-------|---------|
| Page padding mobile | **`--tp-page-pad-mobile` = 16px** · tablet **24px** |
| Section gap mobile | **`--tp-section-gap-mobile` = 20px** |
| Card gap minimum | **`--tp-card-gap-min` / stack spacing** · use **≥16px** |
| Form group spacing | **`--tp-form-group-gap-min` = 18px** |
| Inputs | **`--tp-native-input-min` = 54px** tall · **14×16 px** inner padding baked into `.tp-native-input` chain |
| Textarea min | **`--tp-native-textarea-min-h` = 112px** |
| Primary / secondary CTAs | **56px / 52px** via `--tp-native-btn-min` · `--tp-native-btn-secondary-min` |
| Touch | **`--tp-native-touch-min` = 48×48** px hit targets |

Radii:

- Cards / surfaces **`--tp-ios-card-radius = 24px`**
- Small controls **`--tp-radius-small-control = 14px`**
- Primary buttons **`--tp-radius-button` clamp(18px–22px)**

Scroll / chrome:

- **Final scroll buffer** **`--tp-scroll-end-buffer` = 144px**
- **Bottom chrome slot** **`--tp-bottom-nav-slot` ≥ 96px**

Max width:

- **Forms** **`--tp-max-content-form`** = `min(680px,100%)`
- **Dashboard-ish** **`--tp-max-content-dashboard`** = `min(960px,100%)`

Typography (fluid):

- Vars **`--tp-font-page-title`** … **`--tp-font-hero-number`** clamp large numerals · avoid >48px heroes except dashboards.

Glass / materials:

Follow mission rule: glass **mostly** on **`IOSLiquidTabBar`**, **headers/toolbars/modals/mobile menu**, not saturated over dense content tables.

Usage in code:

- Prefer **`templates/native/component_registry.php`** `$C['IOSPrimaryButton']` pattern when authoring new JSX-like PHP fragments.

---

## Component sources of truth

1. **`/assets/css/native-shell.css`** — tokens + structural primitives · **single bump `?v=12`** in `templates/header.php`
2. **`templates/header.php`** — legacy overlaps (being reduced); keep **IBM Plex Sans Thai**

---

Next doc: **`06_IMPLEMENTATION_PROGRESS.md`**.
