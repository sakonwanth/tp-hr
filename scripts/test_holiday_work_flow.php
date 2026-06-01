#!/usr/bin/env php
<?php
/**
 * End-to-end smoke test: holiday work exception flow + WorkdayCalculator + summary KPI.
 *
 * Usage:
 *   php scripts/test_holiday_work_flow.php [--user-id=N] [--holiday=YYYY-MM-DD] [--comp=YYYY-MM-DD] [--cleanup]
 *
 * Safe on production: uses a dedicated test marker in reason field and --cleanup removes rows.
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use TpCommon\Hr\WorkdayCalculator;

if (!class_exists(WorkdayCalculator::class)) {
    fwrite(STDERR, "FAIL: tp-common WorkdayCalculator not loaded — run composer update tpasset/tp-common\n");
    exit(1);
}

$marker = '[AUTO_TEST_HOLIDAY_WORK]';
$userId = 0;
$holidayDate = '';
$compDate = '';
$cleanup = in_array('--cleanup', $argv, true);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user-id=')) {
        $userId = (int) substr($arg, 10);
    } elseif (str_starts_with($arg, '--holiday=')) {
        $holidayDate = substr($arg, 10);
    } elseif (str_starts_with($arg, '--comp=')) {
        $compDate = substr($arg, 7);
    }
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL DB: ' . $e->getMessage() . "\n");
    exit(1);
}

$failures = [];
$oks = [];

function tw_ok(string $msg): void
{
    global $oks;
    $oks[] = $msg;
    echo "OK  {$msg}\n";
}

function tw_fail(string $msg): void
{
    global $failures;
    $failures[] = $msg;
    echo "FAIL {$msg}\n";
}

function tw_assert(bool $cond, string $okMsg, string $failMsg): void
{
    $cond ? tw_ok($okMsg) : tw_fail($failMsg);
}

echo "=== Holiday work flow test ===\n";
echo 'Environment: ' . (defined('APP_ENV') ? APP_ENV : '?') . "\n";
echo 'Database: ' . (defined('DB_NAME') ? DB_NAME : '?') . '@' . (defined('DB_HOST') ? DB_HOST : '?') . "\n\n";

if ($cleanup) {
    $stmt = $pdo->prepare("DELETE FROM hr_holiday_work_exceptions WHERE reason LIKE ?");
    $stmt->execute(['%' . $marker . '%']);
    $n = $stmt->rowCount();
    echo "Cleanup: removed {$n} test row(s)\n";
    exit($failures ? 1 : 0);
}

// Resolve test employee (prefer non-system user with employee_code)
if ($userId <= 0) {
    $stmt = $pdo->query("
        SELECT u.id FROM users u
        WHERE u.is_active = 1 AND u.id > 1 AND u.employee_code IS NOT NULL AND u.employee_code != ''
        ORDER BY u.id ASC LIMIT 1
    ");
    $userId = (int) ($stmt->fetchColumn() ?: 0);
}
if ($userId <= 0) {
    tw_fail('No suitable test employee');
    exit(1);
}

// Ensure schedule row exists (default Sunday off) for realistic workday rules
$pdo->prepare('
    INSERT IGNORE INTO hr_employee_schedules (user_id, day_off, effective_date, notes)
    VALUES (?, 0, CURDATE(), ?)
')->execute([$userId, $marker . ' test schedule']);

$emp = $pdo->prepare('SELECT id, employee_code, first_name_th, last_name_th FROM users WHERE id = ? LIMIT 1');
$emp->execute([$userId]);
$empRow = $emp->fetch(PDO::FETCH_ASSOC);
echo 'Employee: #' . $userId . ' ' . ($empRow['employee_code'] ?? '') . ' ' . trim(($empRow['first_name_th'] ?? '') . ' ' . ($empRow['last_name_th'] ?? '')) . "\n";

// Resolve CEO reviewer
$reviewerId = (int) $pdo->query("
    SELECT u.id FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.is_active = 1 AND r.name IN ('CEO','Chairman','Admin')
    ORDER BY u.id LIMIT 1
")->fetchColumn();
if ($reviewerId <= 0) {
    $reviewerId = 1;
}

if ($holidayDate === '') {
    // Prefer strictly past holidays so summary KPI scan window includes the date (today's holiday is often outside scan_end).
    $holidayDate = (string) $pdo->query("
        SELECT date FROM hr_holidays
        WHERE is_active = 1 AND date < CURDATE()
        ORDER BY date DESC LIMIT 1
    ")->fetchColumn();
    if ($holidayDate === '') {
        $holidayDate = (string) $pdo->query("
            SELECT date FROM hr_holidays
            WHERE is_active = 1 AND date >= CURDATE()
            ORDER BY date ASC LIMIT 1
        ")->fetchColumn();
    }
}
if ($holidayDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
    tw_fail('No upcoming company holiday found');
    exit(1);
}

$hol = $pdo->prepare('SELECT name FROM hr_holidays WHERE date = ? AND is_active = 1 LIMIT 1');
$hol->execute([$holidayDate]);
$holidayName = (string) ($hol->fetchColumn() ?: 'วันหยุด');

if ($compDate === '') {
    $compDate = tw_pick_comp_date($pdo, $userId, $holidayDate);
}

function tw_pick_comp_date(PDO $pdo, int $userId, string $holidayDate): string
{
    for ($i = 1; $i <= 21; $i++) {
        $candidate = date('Y-m-d', strtotime($holidayDate . " +{$i} days"));
        if (WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $candidate)) {
            return $candidate;
        }
    }
    for ($i = 1; $i <= 21; $i++) {
        $candidate = date('Y-m-d', strtotime($holidayDate . " -{$i} days"));
        if (WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $candidate)) {
            return $candidate;
        }
    }
    return date('Y-m-d', strtotime($holidayDate . ' +1 day'));
}

echo "Holiday work date: {$holidayDate} ({$holidayName})\n";
echo "Comp date: {$compDate}\n\n";

// Clean prior test rows for this user+holiday
$pdo->prepare('DELETE FROM hr_holiday_work_exceptions WHERE user_id = ? AND holiday_date = ? AND reason LIKE ?')
    ->execute([$userId, $holidayDate, '%' . $marker . '%']);

$beforeHolidayWork = WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $holidayDate);
$beforeComp = WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $compDate);
tw_assert(!$beforeHolidayWork, "Before request: {$holidayDate} is NOT workday (holiday)", "Before request: {$holidayDate} should not be workday");
tw_assert($beforeComp, "Before request: {$compDate} IS workday (no comp yet)", "Before request: {$compDate} should be workday");

$pdo->prepare("
    INSERT INTO hr_holiday_work_exceptions
        (user_id, holiday_date, comp_date, holiday_name, reason, status)
    VALUES (?, ?, ?, ?, ?, 'PENDING')
")->execute([
    $userId,
    $holidayDate,
    $compDate,
    $holidayName,
    $marker . ' automated flow test ' . date('c'),
]);
$requestId = (int) $pdo->lastInsertId();
tw_assert($requestId > 0, "Created PENDING request #{$requestId}", 'Failed to insert test request');

$pendingHolidayWork = WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $holidayDate);
tw_assert(!$pendingHolidayWork, 'While PENDING: holiday still not workday', 'While PENDING: holiday must not be workday yet');

$pdo->prepare("
    UPDATE hr_holiday_work_exceptions
    SET status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
    WHERE id = ? AND status = 'PENDING'
")->execute([$reviewerId, $marker . ' approved', $requestId]);

$afterHolidayWork = WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $holidayDate);
$afterComp = WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $compDate);
tw_assert($afterHolidayWork, "After APPROVE: {$holidayDate} IS workday", "After APPROVE: {$holidayDate} must be workday");
tw_assert(!$afterComp, "After APPROVE: {$compDate} is NOT workday (comp)", "After APPROVE: {$compDate} must be comp day off");

$month = substr($holidayDate, 0, 7);
if (class_exists('EmployeeSummaryService')) {
    $svc = new EmployeeSummaryService($pdo);
    $summary = $svc->getMonthlySummary($userId, $month);
    $scanEnd = (string) ($summary['summary_scan_end'] ?? date('Y-m-d'));
    $hwCount = count($summary['holiday_work_exceptions'] ?? []);
    $hwDays = (int) ($summary['counts']['holiday_work_days'] ?? 0);
    $compDays = (int) ($summary['counts']['comp_days'] ?? 0);
    if ($holidayDate <= $scanEnd) {
        tw_assert($hwCount >= 1, "Summary month {$month}: holiday_work_exceptions >= 1 (got {$hwCount})", 'Summary missing holiday_work_exceptions');
    } else {
        tw_ok("Summary skip holiday_work_exceptions (holiday {$holidayDate} after scan_end {$scanEnd})");
    }
    if ($holidayDate <= $scanEnd) {
        tw_assert($hwDays >= 1, "Summary month {$month}: holiday_work_days >= 1 (got {$hwDays}, scan_end {$scanEnd})", 'Summary missing holiday_work_days count');
    } else {
        tw_ok("Summary skip holiday_work_days (holiday {$holidayDate} after scan_end {$scanEnd})");
    }
    if ($compDate !== '' && str_starts_with($compDate, $month) && $compDate <= $scanEnd) {
        tw_assert($compDays >= 1, "Summary month {$month}: comp_days >= 1 (got {$compDays})", 'Summary missing comp_days count');
    } elseif ($compDate !== '' && str_starts_with($compDate, $month)) {
        tw_ok("Summary skip comp_days (comp {$compDate} after scan_end {$scanEnd})");
    }
}

echo "\n--- Result ---\n";
echo 'Passed: ' . count($oks) . ' | Failed: ' . count($failures) . "\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nRun with --cleanup to remove test row #{$requestId}\n";
    exit(1);
}

echo "Test request #{$requestId} left APPROVED for manual UI check.\n";
echo "Remove with: php scripts/test_holiday_work_flow.php --cleanup\n";
exit(0);
