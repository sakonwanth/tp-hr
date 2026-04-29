# 04_PAGE_REFACTOR_TODO.md — TP-HR phased execution queue

Priorities derive from **`01_FULL_UI_INVENTORY.md`**. Tasks are **non-breaking UI** unless noted.

## Wave 1 — Shell & tokens (DONE in this sprint)

| ID | Problem | Files | IOS target |
|----|-----------|-------|-------------|
| T0-1 | Global tokens misaligned vs IOS26 ladder | `assets/css/native-shell.css?v=12` | `:root` spacing + radii |
| T0-2 | Bottom-tab chip widths uneven | `.tp-native-bottom-tab-link` stretch | IOSLiquidTabBar |
| T0-3 | Component registry lacked IOS aliases | `templates/native/component_registry.php` | IOS* map |

## Wave 2 — Employee hot paths

| ID | Page | Exact issues | Approach |
|----|------|----------------|----------|
| T2-1 | `checkin.php` | Sticky modal / GPS legibility · fab collision | ✅ Visual pass (**native-shell v13**): IOS title/hero typography, gutters, **`aria-live`** on clock · **Logic/scripts unchanged** |
| T2-2 | `leave` flow | Dense form · confirm buttons | IOSInsetGroupedCard + IOSFormGroup rhythm |
| T2-3 | `leave_history.php` | Filter sheet affordance | IOSFilterSheet + IOSSearchBar |

*(Continue listing each HR admin screen in Waves 3–4 mirroring **`hr/*.php`** with table-shell → card refactor.)*

**Risk:** **Low** for CSS-only/visual; **Medium** where JS listens to DOM selectors — grep `getElementById` before renaming nodes.
