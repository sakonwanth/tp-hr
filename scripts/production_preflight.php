#!/usr/bin/env php
<?php
/**
 * Production preflight checks for TP-HR.
 *
 * This script is intentionally read-only. It checks the production schema
 * contract that TP-HR shares with tp-crm and tp-checkin before deploy.
 * Run it against the same database you ship against (often production or a
 * replica): a stale local-only database without HR migrations will show many
 * failures that do not reflect production.
 *
 * Usage:
 *   php scripts/production_preflight.php
 *   php scripts/production_preflight.php --strict
 *
 * Exit codes:
 *   0 = no failures (warnings allowed unless --strict)
 *   1 = one or more failures, or warnings in --strict mode
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only: php scripts/production_preflight.php\n");
    exit(1);
}

$baseDir = is_file(__DIR__ . '/../bootstrap.php') ? dirname(__DIR__) : (getcwd() ?: dirname(__DIR__));
if (!is_file($baseDir . '/bootstrap.php')) {
    fwrite(STDERR, "FAIL Cannot locate bootstrap.php from {$baseDir}\n");
    exit(1);
}
require_once $baseDir . '/bootstrap.php';

$strict = in_array('--strict', $argv, true);

try {
    $pdo = getDB();
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL Cannot connect to DB: {$e->getMessage()}\n");
    exit(1);
}

$failures = [];
$warnings = [];
$oks = [];

function pf_ok(string $message): void
{
    global $oks;
    $oks[] = $message;
}

function pf_warn(string $message): void
{
    global $warnings;
    $warnings[] = $message;
}

function pf_fail(string $message): void
{
    global $failures;
    $failures[] = $message;
}

function pf_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pf_value(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function pf_table_exists(PDO $pdo, string $table): bool
{
    return (int)pf_value(
        $pdo,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
        [$table]
    ) > 0;
}

function pf_columns(PDO $pdo, string $table): array
{
    $rows = pf_rows(
        $pdo,
        "SELECT column_name, column_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?
         ORDER BY ordinal_position",
        [$table]
    );
    $cols = [];
    foreach ($rows as $row) {
        $cols[$row['column_name']] = $row['column_type'];
    }
    return $cols;
}

function pf_indexes(PDO $pdo, string $table): array
{
    $rows = pf_rows(
        $pdo,
        "SELECT index_name, non_unique,
                GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS idx_cols
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ?
         GROUP BY index_name, non_unique",
        [$table]
    );
    $idx = [];
    foreach ($rows as $row) {
        $idx[$row['index_name']] = [
            'unique' => ((int)$row['non_unique'] === 0),
            'cols' => (string)$row['idx_cols'],
        ];
    }
    return $idx;
}

function pf_require_columns(PDO $pdo, string $table, array $columns): void
{
    if (!pf_table_exists($pdo, $table)) {
        pf_fail("Missing required table {$table}");
        return;
    }
    $existing = pf_columns($pdo, $table);
    $missing = [];
    foreach ($columns as $column) {
        if (!array_key_exists($column, $existing)) {
            $missing[] = $column;
        }
    }
    if ($missing) {
        pf_fail("{$table} missing required column(s): " . implode(', ', $missing));
    } else {
        pf_ok("{$table} required columns present");
    }
}

function pf_require_index(PDO $pdo, string $table, string $index, ?string $cols = null, ?bool $unique = null): void
{
    if (!pf_table_exists($pdo, $table)) {
        return;
    }
    $indexes = pf_indexes($pdo, $table);
    if (!isset($indexes[$index])) {
        pf_fail("{$table} missing required index {$index}");
        return;
    }
    if ($cols !== null && $indexes[$index]['cols'] !== $cols) {
        pf_fail("{$table}.{$index} columns mismatch: expected {$cols}, got {$indexes[$index]['cols']}");
        return;
    }
    if ($unique !== null && $indexes[$index]['unique'] !== $unique) {
        pf_fail("{$table}.{$index} uniqueness mismatch");
        return;
    }
    pf_ok("{$table}.{$index} index present");
}

function pf_require_enum_values(PDO $pdo, string $table, string $column, array $values): void
{
    if (!pf_table_exists($pdo, $table)) {
        return;
    }
    $cols = pf_columns($pdo, $table);
    if (!isset($cols[$column])) {
        pf_fail("{$table}.{$column} enum column missing");
        return;
    }
    $type = $cols[$column];
    $missing = [];
    foreach ($values as $value) {
        if (strpos($type, "'" . $value . "'") === false) {
            $missing[] = $value;
        }
    }
    if ($missing) {
        pf_fail("{$table}.{$column} enum missing value(s): " . implode(', ', $missing));
    } else {
        pf_ok("{$table}.{$column} enum contract present");
    }
}

function pf_count(PDO $pdo, string $table): ?int
{
    if (!pf_table_exists($pdo, $table)) {
        return null;
    }
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

echo "TP-HR production preflight\n";
echo "DB: " . (defined('DB_NAME') ? DB_NAME : '<unknown>') . "\n";
echo "Strict: " . ($strict ? 'yes' : 'no') . "\n\n";

$requiredTables = [
    'users', 'roles', 'system_settings', 'hr_settings',
    'hr_work_shifts', 'hr_checkin_locations',
    'hr_attendances', 'hr_attendance_adjustments', 'hr_attendance_outside_requests',
    'hr_employee_schedules', 'hr_dayoff_requests', 'hr_holiday_work_exceptions',
    'hr_leave_types', 'hr_leave_entitlements', 'hr_leave_requests',
    'hr_document_templates', 'hr_document_requests', 'hr_issued_documents',
    'hr_employee_family', 'hr_emergency_contacts', 'hr_employee_education', 'hr_employee_work_history',
    'hr_holidays', 'hr_announcements',
    'hr_api_keys', 'hr_api_request_logs',
    'ot_requests', 'employee_salary_setup', 'payroll_runs', 'payroll_slips', 'payroll_slip_tokens',
    'cross_domain_tokens',
];

foreach ($requiredTables as $table) {
    if (!pf_table_exists($pdo, $table)) {
        pf_fail("Missing required table {$table}");
    } else {
        $count = pf_count($pdo, $table);
        pf_ok("Table {$table} exists" . ($count === null ? '' : " ({$count} rows)"));
    }
}

pf_require_columns($pdo, 'users', [
    'id', 'employee_code', 'username', 'email', 'password', 'role_id',
    'first_name_th', 'last_name_th', 'department', 'position', 'hire_date',
    'employment_type', 'work_mode', 'probation_salary', 'salary',
    'bank_name', 'bank_account', 'is_active', 'last_login', 'last_login_ip',
]);

pf_require_columns($pdo, 'hr_attendances', [
    'id', 'user_id', 'attendance_date', 'shift_id',
    'planned_start_time', 'planned_reason', 'planned_requested_at', 'planned_requested_by',
    'check_in_time', 'check_out_time', 'work_minutes', 'break_minutes',
    'late_minutes', 'late_excused', 'late_excused_reason', 'late_notified_at',
    'early_leave_minutes', 'ot_minutes', 'is_offsite', 'offsite_status',
    'check_in_outside_status', 'check_outside_status',
    'offsite_reason', 'offsite_approved_by', 'offsite_approved_at', 'offsite_remarks',
    'status', 'adjustment_reason', 'approved_by', 'approved_at',
]);

pf_require_columns($pdo, 'hr_attendance_adjustments', [
    'attendance_id', 'user_id', 'adjustment_type',
    'original_check_in', 'original_check_out', 'requested_check_in', 'requested_check_out',
    'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_remarks',
]);

pf_require_columns($pdo, 'hr_attendance_outside_requests', [
    'user_id', 'attendance_id', 'request_type', 'request_date', 'request_time',
    'latitude', 'longitude', 'photo_path', 'reason', 'status',
    'reviewed_by', 'reviewed_at', 'review_remarks',
]);

pf_require_columns($pdo, 'hr_employee_schedules', [
    'user_id', 'day_off', 'effective_date', 'notes', 'updated_by',
]);

pf_require_columns($pdo, 'hr_dayoff_requests', [
    'user_id', 'week_start', 'week_end', 'original_day_off',
    'requested_day_off', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
]);

pf_require_columns($pdo, 'hr_holiday_work_exceptions', [
    'user_id', 'holiday_date', 'comp_date', 'holiday_name', 'reason',
    'status', 'reviewed_by', 'reviewed_at', 'review_note',
]);

pf_require_index($pdo, 'hr_holiday_work_exceptions', 'uk_user_holiday', 'user_id,holiday_date', true);

pf_require_columns($pdo, 'hr_holidays', ['date', 'name', 'type', 'is_active']);

pf_require_columns($pdo, 'hr_leave_requests', [
    'request_number', 'user_id', 'leave_type_id', 'start_date', 'end_date',
    'start_period', 'end_period', 'total_days', 'reason', 'document_path',
    'status', 'final_approved_by', 'final_approved_at', 'cancelled_by', 'cancelled_at',
]);

pf_require_columns($pdo, 'employee_salary_setup', [
    'user_id', 'effective_from', 'effective_to', 'base_salary', 'bonus_fixed',
    'provident_fund', 'social_security', 'group_insurance_total_monthly',
    'group_insurance_employer_pct', 'ss_opt_out', 'additional_tax_withholding',
    'allowance_json', 'income_other_json', 'deduction_other_json', 'notes',
]);

pf_require_columns($pdo, 'payroll_runs', [
    'payroll_month', 'pay_day', 'status', 'total_gross', 'total_tax',
    'total_net', 'employee_count', 'approved_by', 'approved_at', 'created_by',
]);

pf_require_columns($pdo, 'payroll_slips', [
    'payroll_run_id', 'user_id', 'gross_salary', 'bonus', 'allowances',
    'income_other_json', 'total_income', 'tax_withheld', 'provident_fund',
    'social_security', 'group_insurance', 'deduction_other_json',
    'absent_days', 'late_count_30', 'late_count_60',
    'absence_deduction', 'lateness_deduction', 'attendance_detail_json',
    'total_deductions', 'net_salary',
]);

pf_require_index($pdo, 'hr_attendances', 'uk_user_date', 'user_id,attendance_date', true);
pf_require_index($pdo, 'hr_attendances', 'idx_hr_att_planned', 'user_id,attendance_date,planned_start_time', false);
pf_require_index($pdo, 'hr_employee_schedules', 'uk_user', 'user_id', true);
pf_require_index($pdo, 'hr_dayoff_requests', 'uk_user_week', 'user_id,week_start', true);
pf_require_index($pdo, 'hr_api_keys', 'uk_key_hash', 'key_hash', true);
pf_require_index($pdo, 'payroll_runs', 'uk_payroll_month', 'payroll_month', true);
pf_require_index($pdo, 'payroll_slips', 'uk_run_user', 'payroll_run_id,user_id', true);

pf_require_enum_values($pdo, 'hr_attendances', 'status', ['PENDING', 'PRESENT', 'LATE', 'ABSENT', 'LEAVE', 'HOLIDAY', 'HALF_DAY', 'WFH']);
pf_require_enum_values($pdo, 'hr_leave_requests', 'status', ['DRAFT', 'PENDING', 'APPROVED', 'REJECTED', 'CANCELLED']);
pf_require_enum_values($pdo, 'hr_document_requests', 'status', ['PENDING', 'PROCESSING', 'READY', 'DELIVERED', 'CANCELLED', 'REJECTED']);
pf_require_enum_values($pdo, 'payroll_runs', 'status', ['draft', 'calculated', 'approved', 'paid']);
pf_require_enum_values($pdo, 'ot_requests', 'status', ['pending', 'approved', 'rejected', 'cancelled']);

$optionalColumns = [
    'hr_attendances' => ['planned_cancelled_at'],
    'hr_employee_schedules' => ['shift_id', 'is_active'],
    'hr_holidays' => ['holiday_date'],
];
foreach ($optionalColumns as $table => $columns) {
    if (!pf_table_exists($pdo, $table)) {
        continue;
    }
    $existing = pf_columns($pdo, $table);
    foreach ($columns as $column) {
        if (!array_key_exists($column, $existing)) {
            pf_warn("Optional compatibility column {$table}.{$column} is missing; code must guard before using it");
        }
    }
}

$settings = [
    'payroll_attendance_enabled' => 'system_settings',
    'payroll_ss_enabled' => 'system_settings',
    'payroll_absent_rate' => 'system_settings',
    'payroll_late_30_rate' => 'system_settings',
    'payroll_late_60_rate' => 'system_settings',
    'payroll_late_over60_as_absent' => 'system_settings',
    'payroll_leave_advance_days' => 'system_settings',
    'default_work_start' => 'hr_settings',
    'default_work_end' => 'hr_settings',
    'grace_period_minutes' => 'hr_settings',
];
foreach ($settings as $key => $table) {
    if ($table === 'system_settings') {
        if (!pf_table_exists($pdo, 'system_settings')) {
            pf_fail('system_settings table missing; cannot verify payroll/time settings');
            continue;
        }
        $value = pf_value($pdo, "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
    } else {
        if (!pf_table_exists($pdo, 'hr_settings')) {
            continue;
        }
        $value = pf_value($pdo, "SELECT value FROM hr_settings WHERE `key` = ? LIMIT 1", [$key]);
    }
    if ($value === false) {
        pf_fail("Missing required setting {$table}.{$key}");
    } else {
        pf_ok("Setting {$table}.{$key} present");
    }
}

if (pf_table_exists($pdo, 'system_settings')) {
    foreach (['USE_TPHR_PAYROLL', 'hr_late_request_cutoff_hour', 'payroll_planned_grace_minutes'] as $key) {
        $value = pf_value($pdo, "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
        if ($value === false) {
            pf_warn("Optional/feature setting system_settings.{$key} is missing; code must use default/fallback");
        }
    }
} else {
    pf_warn('system_settings missing; skipped optional key checks for USE_TPHR_PAYROLL and related');
}

if (pf_count($pdo, 'hr_api_keys') === 0) {
    pf_warn('hr_api_keys has no rows; authenticated external API smoke tests cannot run until a scoped key exists');
}

$migrationState = [
    '2026_04_21_external_api.sql' => function (PDO $pdo): string {
        return (pf_table_exists($pdo, 'hr_api_keys') && pf_table_exists($pdo, 'hr_api_request_logs'))
            ? 'schema-present: classify as already applied manually; do not run just to satisfy tracking'
            : 'schema-missing: safe to run only after backup';
    },
    '2026_04_21_probation_salary.sql' => function (PDO $pdo): string {
        return array_key_exists('probation_salary', pf_columns($pdo, 'users'))
            ? 'schema-present: classify as already applied manually'
            : 'schema-missing: safe additive migration after backup';
    },
    '2026_04_21_work_mode.sql' => function (PDO $pdo): string {
        $cols = pf_columns($pdo, 'users');
        $idx = pf_indexes($pdo, 'users');
        return (array_key_exists('work_mode', $cols) && isset($idx['idx_work_mode']))
            ? 'schema-present: classify as already applied manually'
            : 'schema-missing: safe additive migration after backup';
    },
    '2026_04_21_unify_phase1b_compat_cols.sql' => function (PDO $pdo): string {
        $cols = pf_columns($pdo, 'hr_attendances');
        return (array_key_exists('late_excused', $cols) && array_key_exists('late_notified_at', $cols))
            ? 'schema-present: classify as already applied manually'
            : 'schema-missing: additive but verify CRM/checkin first';
    },
    '2026_04_21_unify_hr_source_of_truth.sql' => function (PDO $pdo): string {
        if (!pf_table_exists($pdo, 'attendance_logs') && pf_table_exists($pdo, 'attendance_logs_legacy')) {
            return 'obsolete/unsafe: source table archived; do not run';
        }
        return 'review-required: backfills from attendance_logs; validate duplicates and source rows first';
    },
    '2026_04_21_unify_phase2_archive_legacy.sql' => function (PDO $pdo): string {
        $legacy = ['attendance_logs', 'leave_requests', 'leave_types', 'leave_balances', 'attendance_monthly_summary'];
        $active = [];
        foreach ($legacy as $table) {
            if (pf_table_exists($pdo, $table)) {
                $active[] = $table;
            }
        }
        return $active
            ? 'unsafe: would rename active legacy table(s): ' . implode(', ', $active)
            : 'already-archived/obsolete: legacy tables not active; do not run again';
    },
];

echo "\nMigration reconciliation:\n";
foreach ($migrationState as $file => $resolver) {
    $status = $resolver($pdo);
    echo "  - {$file}: {$status}\n";
    $knownSafeObsolete = ($file === '2026_04_21_unify_hr_source_of_truth.sql'
        && $status === 'obsolete/unsafe: source table archived; do not run');
    if (!$knownSafeObsolete && (str_contains($status, 'unsafe') || str_contains($status, 'review-required'))) {
        pf_warn("Migration {$file}: {$status}");
    } else {
        pf_ok("Migration {$file}: {$status}");
    }
}

echo "\nResults:\n";
foreach ($failures as $message) {
    echo "[FAIL] {$message}\n";
}
foreach ($warnings as $message) {
    echo "[WARN] {$message}\n";
}
foreach ($oks as $message) {
    echo "[OK] {$message}\n";
}

echo "\nSummary: " . count($failures) . " failure(s), " . count($warnings) . " warning(s), " . count($oks) . " ok\n";

if ($failures || ($strict && $warnings)) {
    exit(1);
}

exit(0);
