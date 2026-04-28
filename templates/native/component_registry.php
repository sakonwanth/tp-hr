<?php
/**
 * TP-HR — Locked native component class registry (PHP).
 * Maps logical component names → CSS classes (native-shell.css + header inline legacy).
 *
 * Usage: $C = require __DIR__ . '/component_registry.php'; echo $C['NativeCard'];
 *
 * @return array<string, string>
 */
return [
    'AppShell' => 'tp-native-app tp-app-shell',
    'SafeAreaHeader' => 'mobile-app-header header-glass tp-safe-area-header',
    'PageHeader' => 'tp-native-section-title section-title',
    'BottomTabNavigation' => 'tp-native-bottom-tab-nav app-shell-mobile-only',
    'StickyPrimaryAction' => 'home-sticky-cta tp-sticky-primary-action',
    'NativeCard' => 'native-card tp-native-card',
    'NativeSummaryCard' => 'stat-card tp-native-summary-card',
    'NativeDataCard' => 'glass-card tp-native-data-card',
    'NativeListItem' => 'tp-native-list-item tp-native-list-row',
    'NativeFormGroup' => 'tp-native-form-group',
    'NativeInput' => 'input-field tp-native-input',
    'NativeSelect' => 'input-field tp-native-select',
    'NativeTextarea' => 'input-field tp-native-textarea',
    'NativeButtonPrimary' => 'btn-primary tp-native-btn-primary',
    'NativeButtonSecondary' => 'btn-secondary tp-native-btn-secondary',
    'NativeIconButton' => 'tp-native-icon-btn',
    'NativeStatusBadge' => 'badge',
    'NativeSectionTitle' => 'section-title tp-native-section-title',
    'NativeFilterBar' => 'tp-native-filter-bar',
    'NativeSearchBar' => 'tp-native-search-bar',
    'NativeDatePickerTrigger' => 'input-date-shell',
    'NativeModal' => 'tp-native-modal fixed inset-0',
    'NativeBottomSheet' => 'tp-native-bottom-sheet',
    'NativeToast' => 'toast-panel',
    'NativeLoadingState' => 'tp-native-loading-state',
    'NativeEmptyState' => 'tp-native-empty-state',
    'NativeErrorState' => 'tp-native-error-state',
    'NativeSuccessState' => 'tp-native-success-state',
    'NativeConfirmationDialog' => 'tp-native-confirmation-dialog',
    'NativeTableToCardPattern' => 'tp-native-table-shell',
    'NativeProgressStep' => 'tp-native-progress-step',
    'NativeActionSheet' => 'tp-native-action-sheet',
    'NativeQuickActionCard' => 'quick-action tp-native-quick-action-card',
    'NativeInfoBlock' => 'tp-native-info-block',
    'NativeWarningBlock' => 'tp-native-warning-block',
];
