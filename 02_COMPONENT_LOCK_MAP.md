# 02 — Component lock map (TP-HR)

Mapping from **legacy / existing** UI patterns in `templates/header.php` embedded styles + Tailwind `app.css` to **locked native** semantics (class names from migration spec; implemented primarily via `native-shell.css` + existing BEM-style classes).

| Legacy / existing | Locked native component |
|-------------------|-------------------------|
| `<body>` app surface | **AppShell** → `body.tp-native-app` |
| Mobile header + safe area | **SafeAreaHeader** / **PageHeader** → `.mobile-app-header.header-glass`, `.mobile-app-header-bar` (min height + `env(safe-area-inset-top)` in existing CSS) |
| Sidebar (≥1280px) | **AppShell** desktop rail → `aside.app-sidebar-desktop` (unchanged structure) |
| `#mobileSidebar` full-screen menu | **NativeBottomSheet** / overlay menu → `.mobile-menu-overlay` |
| `.content-area` main column | **AppShell** content → `main.content-area.tp-native-page` (`#tp-hr-main`) |
| Footer bottom bar (employee) | **BottomTabNavigation** → `nav.tp-native-bottom-tab-nav` (+ existing grid links) |
| `#toast` | **NativeToast** → existing toast + `showToast()` |
| `.glass-card`, `.stat-card` | **NativeCard** / **NativeSummaryCard** → `.glass-card` + `border-radius` 20px via `.glass-card` rule in `native-shell.css` |
| `.btn-primary`, `.btn-primary-prominent` | **NativeButtonPrimary** → `min-height: 56px` |
| `.btn-secondary` | **NativeButtonSecondary** → `min-height: 48px` |
| `.input-field` | **NativeInput** → `min-height: 52px` |
| `.section-title` | **NativeSectionTitle** → existing flex row + optional `tp-native-section-title` |
| `.badge`, `.badge-*` | **NativeStatusBadge** → unchanged class names |
| `.data-table` | **NativeTableToCardPattern** → keep table on desktop; mobile uses overflow-x + cards where already present |
| Modal helpers `uiOpenModal` / `uiCloseModal` | **NativeModal** → existing fixed overlays + JS in `footer.php` |
| Inline flash banners (green/red) | **NativeSuccessState** / **NativeErrorState** → semantic blocks (existing markup) |
| Login `.login-card`, `.input-field`, `.btn-login` | **NativeCard** / **NativeInput** / **NativeButtonPrimary** sizing |
| `modules/employee/leaves/request_form.php` form rows | **NativeFormGroup** / **NativeInput** / **NativeButtonPrimary** alignment via global input/button rules |

Unmapped ad-hoc utilities remain valid when composed only from **spacing scale 4–32** and shared components above.
