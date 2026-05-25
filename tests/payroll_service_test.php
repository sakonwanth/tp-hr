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

$pdo->exec("INSERT INTO users (id, is_active, work_mode) VALUES (1, 1, 'OFFICE'), (2, 1, 'WFH'), (3, 0, 'OFFICE')");
$pdo->exec("INSERT INTO hr_employee_schedules (user_id, day_off) VALUES (1, 0), (2, 0), (3, 0)");
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

echo "PayrollService regression fixtures passed." . PHP_EOL;
