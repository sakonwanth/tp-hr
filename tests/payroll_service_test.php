<?php

require_once dirname(__DIR__) . '/core/Services/SettingsService.php';
require_once dirname(__DIR__) . '/core/Services/PayrollService.php';

function assertSameValue($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "[OK] {$label}" . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        is_active INTEGER NOT NULL DEFAULT 1,
        work_mode TEXT DEFAULT 'OFFICE'
    );
    CREATE TABLE hr_employee_schedules (
        user_id INTEGER PRIMARY KEY,
        day_off INTEGER NOT NULL
    );
    CREATE TABLE hr_holidays (
        date TEXT PRIMARY KEY,
        is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE hr_leave_requests (
        user_id INTEGER,
        start_date TEXT,
        end_date TEXT,
        status TEXT
    );
    CREATE TABLE hr_dayoff_requests (
        user_id INTEGER,
        week_start TEXT,
        week_end TEXT,
        requested_day_off INTEGER,
        status TEXT
    );
    CREATE TABLE system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
");
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('payroll_ss_enabled', '1')");

$pdo->exec("INSERT INTO users (id, is_active, work_mode) VALUES (1, 1, 'OFFICE'), (2, 1, 'WFH'), (3, 0, 'OFFICE'), (5, 1, 'OFFICE')");
$pdo->exec("INSERT INTO hr_employee_schedules (user_id, day_off) VALUES (1, 0), (2, 0), (3, 0), (5, 0)");
$pdo->exec("INSERT INTO hr_holidays (date, is_active) VALUES ('2026-04-22', 1)");
$pdo->exec("INSERT INTO hr_leave_requests (user_id, start_date, end_date, status) VALUES (1, '2026-04-23', '2026-04-23', 'PENDING')");
$pdo->exec("INSERT INTO hr_dayoff_requests (user_id, week_start, week_end, requested_day_off, status) VALUES (1, '2026-04-20', '2026-04-26', 5, 'APPROVED')");

$service = new PayrollService($pdo);
$method = new ReflectionMethod(PayrollService::class, 'findMissingAbsentDates');
$method->setAccessible(true);

$loggedDates = [
    '2026-04-20' => true,
    '2026-04-21' => true,
];

$missing = $method->invoke($service, 1, '2026-04-20', '2026-04-26', $loggedDates);
assertSameValue(['2026-04-25', '2026-04-26'], $missing, 'missing workdays skip logged, holiday, leave, and swapped day off');

$wfhMissing = $method->invoke($service, 2, '2026-04-20', '2026-04-26', []);
assertSameValue([], $wfhMissing, 'WFH user has no missing absent dates');

$inactiveMissing = $method->invoke($service, 3, '2026-04-20', '2026-04-26', []);
assertSameValue([], $inactiveMissing, 'inactive user has no missing absent dates');

$wageMethod = new ReflectionMethod(PayrollService::class, 'socialSecurityWageBase');
$wageMethod->setAccessible(true);
$wageBase = $wageMethod->invoke($service, [
    'base_salary' => 12000,
    'bonus_fixed' => 0,
    'income_other_json' => json_encode([
        ['label' => 'ค่าตำแหน่ง', 'amount' => 3000],
        ['label' => 'ค่าเดินทาง', 'amount' => 1000],
    ]),
]);
assertSameValue(16000.0, $wageBase, 'SS wage base includes base salary and recurring monthly income');

$ss16000 = $service->calcSocialSecurity(16000, false, '2026-05-01');
assertSameValue(800.0, $ss16000, 'SS 5% of 16000 monthly wage base');

$appliesMethod = new ReflectionMethod(PayrollService::class, 'ssAppliesForMonth');
$appliesMethod->setAccessible(true);
assertSameValue(false, $appliesMethod->invoke($service, null, '2026-05-01'), 'no SS start date — no deduction');
assertSameValue(false, $appliesMethod->invoke($service, '2026-06-01', '2026-05-01'), 'payroll month before SS start — no deduction');
assertSameValue(true, $appliesMethod->invoke($service, '2026-03-15', '2026-03-01'), 'SS start in March — March payroll deducts');
assertSameValue(true, $appliesMethod->invoke($service, '2026-03-01', '2026-04-01'), 'SS start March — April payroll deducts');

$hireMethod = new ReflectionMethod(PayrollService::class, 'hireProrateFactor');
$hireMethod->setAccessible(true);
assertSameValue(0.0, $hireMethod->invoke($service, '2026-04-05', '2026-02-26', '2026-03-25'), 'hire after period end — no pay');
assertSameValue(1.0, $hireMethod->invoke($service, '2026-03-20', '2026-03-26', '2026-04-27'), 'hire before period start — full pay');
$partial = $hireMethod->invoke($service, '2026-04-05', '2026-03-26', '2026-04-27');
assertSameValue(true, $partial > 0 && $partial < 1, 'mid-period hire — prorated pay');

assertSameValue('2026-04-26', $service->attendancePeriodBounds('2026-05-01', 25)['start'], 'May payroll starts Apr 26');
assertSameValue('2026-05-25', $service->attendancePeriodBounds('2026-05-01', 25)['end'], 'May payroll ends May 25');
assertSameValue('2026-05-25', $service->attendanceClosedScanEnd('2026-04-26', '2026-05-25', '2026-05-26'), 'scan full May period on May 26');
assertSameValue('', $service->attendanceClosedScanEnd('2026-05-26', '2026-06-25', '2026-05-26'), 'June period on May 26 — no false absent');
assertSameValue('2026-05', $service->suggestPayrollMonth(25, '2026-05-26'), 'suggest May after cutover');
assertSameValue('2026-04', $service->suggestPayrollMonth(25, '2026-05-20'), 'suggest April before cutover');

$ctx = $service->buildWorkdayContext(1, '2026-05-01', '2026-05-25');
assertSameValue(false, $service->isPayrollWorkday($ctx, '2026-05-10'), 'Sunday day_off=0 is not a payroll workday');
assertSameValue(true, $service->isPayrollWorkday($ctx, '2026-05-11'), 'Monday is a payroll workday');

assertSameValue(null, $service->classifyLateMinutes(19), '19 minutes late — no payroll tier');
assertSameValue('late_30', $service->classifyLateMinutes(20), '20 minutes late — tier 150');
assertSameValue('late_30', $service->classifyLateMinutes(23), '23 minutes late — tier 150');

$pdo->exec("
    CREATE TABLE hr_attendances (
        user_id INTEGER,
        attendance_date TEXT,
        status TEXT,
        late_minutes INTEGER,
        late_excused INTEGER,
        late_notified_at TEXT,
        remarks TEXT
    );
    CREATE TABLE user_profiles (
        user_id INTEGER PRIMARY KEY,
        hire_date TEXT
    );
");
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('payroll_attendance_enabled', '1'),
    ('payroll_absent_rate', '600'),
    ('payroll_late_30_rate', '150'),
    ('payroll_late_60_rate', '300'),
    ('payroll_late_over60_as_absent', '1')
");
$pdo->exec("INSERT INTO user_profiles (user_id, hire_date) VALUES (5, '2026-01-01')");
for ($ts = strtotime('2026-04-26'); $ts <= strtotime('2026-05-25'); $ts += 86400) {
    $d = date('Y-m-d', $ts);
    if ((int)date('w', $ts) === 0) {
        continue;
    }
    $pdo->exec("INSERT INTO hr_attendances (user_id, attendance_date, status, late_minutes, late_excused)
        VALUES (5, '{$d}', 'PRESENT', 0, 0)");
}
$pdo->exec("INSERT INTO hr_attendances (user_id, attendance_date, status, late_minutes, late_excused)
    VALUES (5, '2026-05-10', 'LATE', 605, 0)");

$deductions = $service->computeAttendanceDeductions(5, '2026-05-01', 25);
assertSameValue(0.0, $deductions['absent_days'], 'Sunday LATE 605 min must not count as absent');
assertSameValue(0.0, $deductions['total_deduction'], 'Sunday check-in must not deduct payroll');

echo "PayrollService regression fixtures passed." . PHP_EOL;
