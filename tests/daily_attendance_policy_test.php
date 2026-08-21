<?php

require_once dirname(__DIR__) . '/core/Services/DailyAttendancePolicy.php';

function assertDailyState(string $expected, array $row, string $date, string $today, string $label): void
{
    $actual = DailyAttendancePolicy::classify($row, $date, $today)['code'];
    if ($actual !== $expected) {
        fwrite(STDERR, "[FAIL] {$label}: expected {$expected}, got {$actual}" . PHP_EOL);
        exit(1);
    }
    echo "[OK] {$label}" . PHP_EOL;
}

assertDailyState('NOT_YET', [], '2026-08-21', '2026-08-21', 'today is not absent before day close');
assertDailyState('ABSENT', [], '2026-08-20', '2026-08-21', 'closed missing workday is absent');
assertDailyState('PRESENT', ['check_in_time' => '2026-08-21 08:30:00', 'status' => 'PRESENT'], '2026-08-21', '2026-08-21', 'actual check-in is present');
assertDailyState('LATE', ['check_in_time' => '2026-08-21 10:00:00', 'status' => 'PRESENT', 'late_minutes' => 20], '2026-08-21', '2026-08-21', 'late minutes override present label');
assertDailyState('NOT_YET', ['status' => 'PRESENT'], '2026-08-21', '2026-08-21', 'empty placeholder row is not checked in');
assertDailyState('NOT_YET', ['status' => 'ABSENT'], '2026-08-21', '2026-08-21', 'today explicit absent waits for the shared close boundary');
assertDailyState('ABSENT', ['status' => 'ABSENT'], '2026-08-20', '2026-08-21', 'closed explicit absent remains absent');
assertDailyState('LEAVE', ['status' => 'ABSENT', 'approved_leave_name' => 'ลาป่วย'], '2026-08-20', '2026-08-21', 'approved leave overrides absent for reporting');
assertDailyState('COMP_DAY', ['is_comp_day' => 1], '2026-08-20', '2026-08-21', 'approved compensation day is excused');
assertDailyState('HOLIDAY', ['is_holiday' => 1], '2026-08-20', '2026-08-21', 'company holiday is excused');
assertDailyState('DAY_OFF', ['is_scheduled_off' => 1], '2026-08-20', '2026-08-21', 'effective weekly day off is excused');
assertDailyState('PRESENT', ['check_in_time' => '2026-08-20 08:30:00', 'is_holiday' => 1], '2026-08-20', '2026-08-21', 'actual holiday work remains working');

$summarySource = file_get_contents(dirname(__DIR__) . '/core/Services/EmployeeSummaryService.php');
if ($summarySource === false || str_contains($summarySource, 'reconcileAbsentOverlappingApprovedLeave')) {
    fwrite(STDERR, '[FAIL] monthly summary must not reconcile or write attendance during GET' . PHP_EOL);
    exit(1);
}
echo '[OK] monthly summary has no write-on-read reconciliation' . PHP_EOL;

$summaryPage = file_get_contents(dirname(__DIR__) . '/hr/employee_summaries.php');
if ($summaryPage === false || !str_contains($summaryPage, "getOrgMonthlyKpi(\$month, \$department")) {
    fwrite(STDERR, '[FAIL] monthly KPI must receive the department filter' . PHP_EOL);
    exit(1);
}
echo '[OK] monthly KPI receives department filter' . PHP_EOL;

echo 'Daily attendance reporting policy regression fixtures passed.' . PHP_EOL;
