# 02_COMPONENT_LOCK_MAP.md — TP-HR IOS26 ↔ Existing classes

Maps **requested IOS26 logical components** → **current CSS/HTML classes** in `assets/css/native-shell.css`, Tailwind markup, and **`templates/header.php`** inline helpers.

| IOS26 logical | Implementation (primary) |
|---------------|----------------------------|
| IOSAppShell | `body.tp-native-app.tp-app-shell` · main `#tp-hr-main` |
| IOSSafeAreaHeader | `.mobile-app-header.header-glass` + `calc(env(safe-area-inset-top))` offsets in markup |
| IOSLargeTitleHeader | `.dashboard-hero-title` · `.section-title` |
| IOSCompactHeader | Sidebar brand + sticky mobile bar |
| IOSLiquidTabBar | `#tpHrMobileBottomTab` · `.tp-native-bottom-tab-strip` |
| IOSFloatingActionBar | Sticky FAB patterns (mostly home; extend per screen) |
| IOSStickyPrimaryCTA | `.home-sticky-cta.tp-sticky-primary-action` |
| IOSGlassButton / GlassControl | Prefer **tint+satin** wells (`.glass-card` sparingly — glass mainly on chrome) |
| IOSPrimaryButton | `.btn-primary` · `.btn-primary-prominent` · `.tp-native-btn-primary` |
| IOSSecondaryButton | `.btn-secondary` · `.tp-native-btn-secondary` |
| IOSDestructiveButton | Sidebar logout `.nav-item` · mobile `.mobile-menu-logout-btn` |
| IOSIconButton | `.tp-native-icon-btn` · menu close buttons (`min-[48px]`) |
| IOSCard · IOSInsetGroupedCard | `.native-card.tp-native-card` · nested `.tp-native-well` |
| IOSSummaryCard | `.stat-card.tp-native-summary-card` |
| IOSDataCard | `.glass-card.tp-native-data-card` |
| IOSListItem | `.tp-native-list-item` rows in tables/cards migration |
| IOSInsetListGroup | `.tp-native-stack-cards` wrappers |
| IOSFormGroup / IOSInput / IOSSelect / IOSTextarea | `.tp-native-form-group` + `.input-field.tp-native-*` |
| IOSDatePickerTrigger | `.input-date-shell` + native `<input type="date">` |
| IOSSegmentedControl | `.tp-native-filter-bar` (+ future segmented markup) |
| IOSSearchBar | `.tp-native-search-bar` |
| IOSFilterSheet | `.tp-native-bottom-sheet` overlays |
| IOSModal | `.fixed.inset-0.backdrop*` modals (escape closes — `footer.php`) |
| IOSBottomSheet | Same pattern + native-stack scroll |
| IOSToast | `#toast` + `.toast-panel` |
| IOSLoadingState / Skeleton | `.tp-native-loading-state` · `.tp-native-app-loading` |
| IOSEmptyState / IOSErrorState / IOSSuccessState | `.tp-native-empty-state` … (apply per route) |
| IOSConfirmationDialog | `.tp-native-confirmation-dialog` |
| IOSStatusBadge | `.badge` + variants `.badge-*` |
| IOSInfoBlock · IOSWarningBlock | `.tp-native-info-block` / `.tp-native-warning-block` |
| IOSTableToCardPattern | `.tp-native-table-shell` horizontal scroll desktop; stacked cards mobile |
| IOSQuickActionCard | `.quick-action.tp-native-quick-action-card` |
| IOSProgressStep | `.tp-native-progress-step` (forms with steps — expand where used) |
| IOSSectionHeader | `.section-title.tp-native-section-title` |
| IOSScrollContainer | `.tp-native-stack--page` |
| IOSKeyboardSafeForm | Form groups + `footer.php` ESC + sticky buffer tokens |

**Unmapped legacy:** ad-hoc page `<style>` blocks (reduce by migrating tokens to `native-shell.css` only).

**Registry file:** duplicate keys **`IOS*`** added in **`templates/native/component_registry.php`**.
