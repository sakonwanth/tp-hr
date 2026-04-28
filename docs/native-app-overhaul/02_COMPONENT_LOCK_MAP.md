# 02 — Component Lock Map (TP-HR)

Maps **legacy / inline** patterns to **locked native component** names. Implementation lives in `assets/css/native-shell.css` and `templates/header.php` (legacy `.btn-primary`, `.glass-card`, etc.). PHP registry: `templates/native/component_registry.php`.

| Old pattern | Locked component | Primary CSS / markup |
|-------------|------------------|----------------------|
| `body.tp-native-app` | **AppShell** | Shell + tokens in `native-shell.css` |
| `.mobile-app-header`, `.header-glass` | **SafeAreaHeader** | Mobile top bar + safe area |
| `.section-title` | **PageHeader** / **NativeSectionTitle** | `tp-native-section-title` alias |
| `.tp-native-bottom-tab-nav` | **BottomTabNavigation** | `templates/footer.php` |
| `.home-sticky-cta` | **StickyPrimaryAction** | Used on pages with fixed CTA |
| `.glass-card` | **NativeDataCard** | Prefer `native-card` for new markup |
| `.stat-card` | **NativeSummaryCard** | Dashboard stats |
| `.native-card`, `.tp-native-card` | **NativeCard** | Default card container |
| `.nav-item` (sidebar) | *Navigation list item* | Desktop sidebar (HR) |
| `.mobile-menu-tile` | *NativeQuickActionCard* (variant) | Full-screen mobile menu |
| `.quick-action` | **NativeQuickActionCard** | Dashboard grid |
| `.btn-primary` | **NativeButtonPrimary** | `.tp-native-btn-primary` alias |
| `.btn-secondary` | **NativeButtonSecondary** | `.tp-native-btn-secondary` |
| `.input-field` | **NativeInput** / **NativeSelect** / **NativeTextarea** | 52px min |
| `.input-date-shell` | **NativeDatePickerTrigger** | Date input wrapper |
| `.badge`, `.badge-*` | **NativeStatusBadge** | Semantic colours |
| `.tp-native-filter-bar` | **NativeFilterBar** | HR lists |
| `.tp-native-search-bar` | **NativeSearchBar** | Search inputs |
| `.tp-native-form-group` | **NativeFormGroup** | Label + control spacing |
| `.data-table` + table | **NativeTableToCardPattern** | `.tp-native-table-shell` + *page-specific* card stacks on mobile |
| `div.fixed.inset-0` modals | **NativeModal** | `uiOpenModal` / `uiCloseModal` |
| `#toast` | **NativeToast** | `showToast()` in `footer.php` |
| `.tp-native-loading-state` | **NativeLoadingState** | Spinners |
| `.tp-native-empty-state` | **NativeEmptyState** | Empty lists |
| `.tp-native-error-state` | **NativeErrorState** | Errors |
| `.tp-native-success-state` | **NativeSuccessState** | Success panels |
| `.tp-native-confirmation-dialog` | **NativeConfirmationDialog** | Destructive confirms |
| `.tp-native-progress-step` | **NativeProgressStep** | Multi-step forms |
| `.tp-native-action-sheet` | **NativeActionSheet** | Bottom actions |
| `.tp-native-info-block` | **NativeInfoBlock** | Info callouts |
| `.tp-native-warning-block` | **NativeWarningBlock** | Warnings |

**Unmapped count:** **0** (every legacy class family used in `templates/*` and `native-shell.css` maps to a locked name above).

**Note:** Some components share visual implementation (e.g. **NativeModal** vs **NativeBottomSheet**) — differentiate by markup role and `z-index`; both use `.tp-native-modal` / `.tp-native-bottom-sheet` bases.
