# 05 — Native component system (TP-HR)

## Source of truth

- **CSS:** `/assets/css/native-shell.css` (cache `?v=4`) — design tokens + layout QA (aligned with tp-checkin v5); see `UI_CASCADE_BUGFIX.md` if layout looks edge-to-edge.
- **Shell:** `templates/header.php` → `body.tp-native-app` (+ `tp-with-tab-nav` for non-`hr-*` routes).  
- **Main:** `main#tp-hr-main.content-area.tp-native-page` — **full width** (`max-width` override for dashboards).
- **Page stack:** inner wrapper `<div class="tp-native-stack--page min-w-0">` (header/footer) — vertical section gaps 16px / 24px tablet.
- **Tabs:** `templates/footer.php` → `nav.tp-native-bottom-tab-nav` (employee-only).

## Locked tokens

| Token | Value |
|-------|-------|
| Page padding (mobile / tablet) | 16px / 24px (`--tp-page-pad-*`) |
| Section gap | 16px / 24px |
| Card radius | 20px (`--tp-radius-card`) |
| Primary button min height | 56px |
| Input min height | 52px |
| Touch target | 48px (`--tp-native-touch-min`) |
| Bottom nav max visual height | 72px |
| Bottom slot (tabs + safe area) | `max(88px, 72px + env(safe-area-inset-bottom))` |
| Scroll-end buffer | 120px |
| CTA gap above tabs | 24px |

## Implemented components (class ↔ role)

| Component | Implementation |
|-----------|----------------|
| AppShell | `body.tp-native-app` + sidebar / mobile header |
| SafeAreaHeader | Existing `.mobile-app-header-bar` padding includes `env(safe-area-inset-top)` |
| BottomTabNavigation | Footer `nav.tp-native-bottom-tab-nav` |
| NativeCard | `.glass-card` + `native-shell` radius |
| NativeButtonPrimary | `.btn-primary` ≥56px |
| NativeInput | `.input-field` ≥52px |
| NativeToast | `#toast` |
| StickyPrimaryAction (dashboard) | `.home-sticky-cta` + `tp-native-btn-primary` (`index.php`, mobile-only) |
| NativeModal | Fixed overlays + `uiOpenModal` / `uiCloseModal` |

## Home dashboard

- **`main`** may include class `tp-native-page--home` when `current_page === 'dashboard'` — extra bottom padding for **sticky primary CTA** + bottom tabs + scroll buffer.

## Typography

Single stack: **IBM Plex Sans Thai** (Google Fonts) — loaded in `header.php` and `login.php`.
