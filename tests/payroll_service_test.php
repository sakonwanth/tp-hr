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

$benefitMethod = new ReflectionMethod(PayrollService::class, 'benefitAppliesForMonth');
$benefitMethod->setAccessible(true);
assertSameValue(true, $benefitMethod->invoke($service, null, '2026-05-01', false), 'tax/group — empty start date applies immediately');
assertSameValue(false, $benefitMethod->invoke($service, null, '2026-05-01', true), 'health/SS — empty start date does not apply');
assertSameValue(false, $benefitMethod->invoke($service, '2026-07-01', '2026-05-01', false), 'future start date — no deduction yet');

$pdo->exec("ALTER TABLE users ADD COLUMN tax_withholding_start_date TEXT");
$pdo->exec("ALTER TABLE users ADD COLUMN health_insurance_start_date TEXT");
$pdo->exec("ALTER TABLE users ADD COLUMN group_insurance_start_date TEXT");
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('payroll_tax_enabled', '1')");
$pdo->exec("UPDATE users SET tax_withholding_start_date = '2026-06-01' WHERE id = 1");

$taxBeforeStart = $service->calcTaxForUser(1, 600000, 0, 0, '2026-05-01', false);
assertSameValue(0.0, $taxBeforeStart, 'tax withheld only from configured start month');
$taxAfterStart = $service->calcTaxForUser(1, 600000, 0, 0, '2026-06-01', false);
assertSameValue(true, $taxAfterStart > 0, 'tax applies from start month');

$taxOptOut = $service->calcTaxForUser(1, 600000, 0, 0, '2026-06-01', true);
assertSameValue(0.0, $taxOptOut, 'tax opt-out returns zero');

$setupWithExtra = ['additional_tax_withholding' => 1500.0];
assertSameValue(1500.0, $service->resolveExtraTaxRequest($setupWithExtra, false), 'extra tax applies when not opted out');
assertSameValue(0.0, $service->resolveExtraTaxRequest($setupWithExtra, true), 'extra tax zero when tax opt-out');
$pdo->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'payroll_tax_enabled'");
$serviceTaxOff = new PayrollService($pdo);
assertSameValue(0.0, $serviceTaxOff->resolveExtraTaxRequest($setupWithExtra, false), 'extra tax zero when company tax disabled');
$pdo->exec("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'payroll_tax_enabled'");

$hireMethod = new ReflectionMethod(PayrollService::class, 'hireProrateFactor');
$hireMethod->setAccessible(true);
assertSameValue(0.0, $hireMethod->invoke($service, '2026-04-05', '2026-02-26', '2026-03-25'), 'hire after period end — no pay');
assertSameValue(1.0, $hireMethod->invoke($service, '2026-03-20', '2026-03-26', '2026-04-27'), 'hire before period start — full pay');
$partial = $hireMethod->invoke($service, '2026-04-05', '2026-03-26', '2026-04-27');
assertSameValue(true, $partial > 0 && $partial < 1, 'mid-period hire — prorated pay');

$febBounds = $service->effectivePeriodBounds('2026-02-01', 25, '2026-02-05');
assertSameValue('2026-02-05', $febBounds['start'], 'first hire month starts on hire date');
assertSameValue('2026-02-28', $febBounds['end'], 'first hire month ends on calendar month end');
assertSameValue(true, !empty($febBounds['is_first_hire_month']), 'first hire month flag');

$dayOffCtx = ['day_off' => 0, 'dayoff_requests' => [], 'holidays' => [], 'leave_dates' => []];
assertSameValue(4, $service->countDayOffDaysInCalendarMonth($dayOffCtx, '2026-02'), 'Feb 2026 has 4 Sundays');
assertSameValue(20, $service->inclusiveDayCount('2026-02-05', '2026-02-28') - 4, 'first hire payable days = period days minus month day-offs');

try {
    $pdo->exec('ALTER TABLE users ADD COLUMN hire_date TEXT');
} catch (Throwable $e) {
    /* column exists */
}
$pdo->exec("INSERT INTO users (id, is_active, work_mode, hire_date) VALUES (10, 1, 'OFFICE', '2026-02-05')");
$pdo->exec("INSERT OR REPLACE INTO hr_employee_schedules (user_id, day_off) VALUES (10, 0)");
$serviceFeb = new PayrollService($pdo);
$gross = 12000.0;
$bonus = 1000.0;
$allowances = 0.0;
$incomeOther = 0.0;
$incomeJson = null;
$serviceFeb->applyHireDateIncome(10, '2026-02-01', 25, $gross, $bonus, $allowances, $incomeOther, $incomeJson);
assertSameValue(8000.0, $gross, 'first hire gross = 20 days × (12000/30)');
assertSameValue(1000.0, $bonus, 'first hire bonus paid in full');

$pdo->exec("INSERT INTO users (id, is_active, work_mode, hire_date) VALUES (12, 1, 'OFFICE', '2026-03-01')");
$pdo->exec("INSERT OR REPLACE INTO hr_employee_schedules (user_id, day_off) VALUES (12, 0)");
$grossMar1 = 12000.0;
$bonusMar1 = 0.0;
$allowMar1 = 0.0;
$incomeOtherMar1 = 0.0;
$incomeJsonMar1 = null;
$serviceFeb->applyHireDateIncome(12, '2026-03-01', 25, $grossMar1, $bonusMar1, $allowMar1, $incomeOtherMar1, $incomeJsonMar1);
assertSameValue(12000.0, $grossMar1, 'hire on 1st of month pays full gross in first hire month');

try {
    $pdo->exec('ALTER TABLE users ADD COLUMN probation_salary REAL');
} catch (Throwable $e) {
    /* column exists */
}
try {
    $pdo->exec('ALTER TABLE users ADD COLUMN salary REAL');
} catch (Throwable $e) {
    /* column exists */
}
try {
    $pdo->exec('ALTER TABLE users ADD COLUMN probation_passed_date TEXT');
} catch (Throwable $e) {
    /* column exists */
}
$pdo->exec("INSERT INTO users (id, is_active, work_mode, hire_date, probation_salary, salary, probation_passed_date) VALUES (13, 1, 'OFFICE', '2026-06-01', 12000, 14000, NULL)");
$pdo->exec("INSERT OR REPLACE INTO hr_employee_schedules (user_id, day_off) VALUES (13, 0)");
try {
    $pdo->exec('CREATE TABLE employee_salary_setup (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, effective_from TEXT, effective_to TEXT, base_salary REAL, bonus_fixed REAL DEFAULT 0, provident_fund REAL DEFAULT 0, social_security REAL DEFAULT 0)');
} catch (Throwable $e) {
    /* table exists */
}
$pdo->exec("INSERT INTO employee_salary_setup (user_id, effective_from, base_salary) VALUES (13, '2026-06-01', 99999)");
$serviceProb = new PayrollService($pdo);
$slipJun = $serviceProb->calculateSlip(13, '2026-06-01', 25);
assertSameValue(12000.0, $slipJun['gross_salary'], 'profile probation_salary overrides setup base');

$pdo->exec("UPDATE users SET probation_passed_date = '2026-08-15' WHERE id = 13");
$slipAug = $serviceProb->calculateSlip(13, '2026-08-01', 25);
assertSameValue(14000.0, $slipAug['gross_salary'], 'after probation passed uses salary from profile');
$slipJul = $serviceProb->calculateSlip(13, '2026-07-01', 25);
assertSameValue(12000.0, $slipJul['gross_salary'], 'still on probation in July uses probation_salary');

$marBounds = $service->effectivePeriodBounds('2026-03-01', 25, '2026-02-05');
assertSameValue('2026-03-01', $marBounds['start'], 'transition month skips overlap with first hire month');
assertSameValue('2026-03-25', $marBounds['end'], 'transition month keeps standard period end');
assertSameValue(1.0, $service->hireIncomeProrateFactor('2026-02-05', $marBounds), 'transition month pays full income');
assertSameValue(true, $serviceFeb->shouldIncludeEmployeeInRun(10, '2026-02-05', '2026-02-01', 25), 'Feb hire included in first payroll month');
$pdo->exec("INSERT INTO users (id, is_active, work_mode, hire_date) VALUES (11, 1, 'OFFICE', '2026-02-28')");
$pdo->exec("INSERT OR REPLACE INTO hr_employee_schedules (user_id, day_off) VALUES (11, 0)");
$serviceLate = new PayrollService($pdo);
assertSameValue(true, $serviceLate->shouldIncludeEmployeeInRun(11, '2026-02-28', '2026-02-01', 25), 'late-month hire included in first payroll month');

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
