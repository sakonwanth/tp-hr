# 02 — Component lock map (TP-HR)

Mapping from **legacy / existing** UI patterns in `templates/header.php` embedded styles + Tailwind `app.css` to **locked native** semantics (class names; implemented primarily via **`assets/css/native-shell.css`** v5 + Tailwind helpers).

Extended registry — **every** named component from migration spec maps to CSS / existing markup (**unmapped technical count = 0**):

| Locked component | Implementation (TP-HR) |
|------------------|-------------------------|
| **AppShell** | `body.tp-native-app` + `aside.app-shell-desktop*` + `#tp-native-stack--page` |
| **SafeAreaHeader** | `.mobile-app-header` + `.header-glass` + `env(safe-area-inset-top)` |
| **PageHeader** | Desktop `.header-main` stack; HR page titles `.section-title` / `page_title` rows |
| **BottomTabNavigation** | `templates/footer.php` → `nav.tp-native-bottom-tab-nav.app-shell-mobile-only` |
| **StickyPrimaryAction** | Dashboard mobile `.home-sticky-cta.tp-sticky-primary-action` (`index.php`) |
| **NativeCard** | `.glass-card` + `--tp-radius-card: 20px` |
| **NativeSummaryCard** | `.native-card.stat-card`, glass stat tiles |
| **NativeDataCard** | `.tp-native-data-card` + bordered glass blocks |
| **NativeListItem** | `.tp-native-list-row` / `.tp-native-list-item` |
| **NativeFormGroup** | `.tp-native-form-group` |
| **NativeInput** | `.input-field`, `.tp-native-input` |
| **NativeSelect** | `select.input-field`, `.tp-native-select` |
| **NativeTextarea** | `textarea` + `.tp-native-textarea` |
| **NativeButtonPrimary** | `.btn-primary`, `.tp-native-btn-primary` (≥56px) |
| **NativeButtonSecondary** | `.btn-secondary`, `.tp-native-btn-secondary` |
| **NativeIconButton** | `.tp-native-icon-btn`; icon-only links must satisfy min 48px |
| **NativeStatusBadge** | `.badge`, `.badge-*`, pill spans in lists |
| **NativeSectionTitle** | `.tp-native-section-title`, `body.tp-native-app .section-title` |
| **NativeFilterBar** | `.tp-native-filter-bar` (HR/search strips) |
| **NativeSearchBar** | `.tp-native-search-bar`; search inputs wrapped in HR filters |
| **NativeDatePickerTrigger** | `input[type="date"]`, time pickers via `uiInitIOSTimePickerFallback` |
| **NativeModal** | `fixed inset-0` + `.glass-card` sheets + `uiOpenModal` / `uiCloseModal` |
| **NativeBottomSheet** | `#mobileSidebar.mobile-menu-sheet`, modals sliding from bottom semantics |
| **NativeToast** | `#toast`, `showToast()` in `footer.php` |
| **NativeLoadingState** | `.tp-native-loading-state`, spinner blocks (e.g. modals fetching) |
| **NativeEmptyState** | `.tp-native-empty-state` patterns + glass empty cards per page |
| **NativeErrorState** | Flash red + `.tp-native-error-state` |
| **NativeSuccessState** | Flash green + inline success |
| **NativeConfirmationDialog** | `.tp-native-confirmation-dialog` z-index alias; destructive `confirm()` where used |
| **NativeTableToCardPattern** | **Mobile:** `md:hidden` card stacks; **Desktop:** `hidden md:block` + **`.tp-native-table-shell`** overflow table |
| **NativeProgressStep** | `.tp-native-progress-step` (future multi-step; alias ready) |
| **NativeActionSheet** | `.tp-native-action-sheet` z-index hook; menu overlay uses `#mobileSidebar` |
| **NativeQuickActionCard** | `.tp-native-quick-action-card` + dashboard quick grid |
| **NativeInfoBlock** | `.tp-native-info-block` |
| **NativeWarningBlock** | `.tp-native-warning-block` |

| Legacy / existing | Locked native component |
|-------------------|-------------------------|
| `<body>` app surface | **AppShell** → `body.tp-native-app` |
| Mobile header + safe area | **SafeAreaHeader** / **PageHeader** → `.mobile-app-header.header-glass`, `.mobile-app-header-bar` |
| Sidebar (≥1280px) | **AppShell** desktop rail → `aside.app-sidebar-desktop` |
| `#mobileSidebar` full-screen menu | **NativeBottomSheet** / overlay menu → `.mobile-menu-overlay` |
| `.content-area` main column | **AppShell** content → `main.content-area.tp-native-page` (`#tp-hr-main`) |
| Footer bottom bar (employee) | **BottomTabNavigation** → `nav.tp-native-bottom-tab-nav` |
| `#toast` | **NativeToast** → existing toast + `showToast()` |
| `.glass-card`, `.stat-card` | **NativeCard** / **NativeSummaryCard** → `.glass-card` + radius token |
| `.btn-primary`, `.btn-primary-prominent` | **NativeButtonPrimary** → `min-height: 56px` |
| `.btn-secondary` | **NativeButtonSecondary** → `min-height: 48px` |
| `.input-field` | **NativeInput** → `min-height: 52px` |
| `.section-title` | **NativeSectionTitle** |
| `.data-table` / wide tables | **NativeTableToCardPattern** → card stack + **`.tp-native-table-shell`** on desktop |
| Modal helpers `uiOpenModal` / `uiCloseModal` | **NativeModal** → fixed overlays + JS in `footer.php` |
| Inline flash banners | **NativeSuccessState** / **NativeErrorState** → semantic blocks |
| Login `.login-card`, `.input-field`, `.btn-login` | **NativeCard** / **NativeInput** / **NativeButtonPrimary** sizing |

Unmapped ad-hoc utilities remain valid when composed only from **spacing scale 4–32** and tokens in `:root`.
