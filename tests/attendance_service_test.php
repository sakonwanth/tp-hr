<?php

require_once dirname(__DIR__) . '/core/Services/AttendanceService.php';

if (!function_exists('getShiftDefaults')) {
    function getShiftDefaults(?array $shift = null): array {
        return [
            'grace_period_minutes' => (int)($shift['grace_period_minutes'] ?? 15),
            'break_minutes' => (int)($shift['break_minutes'] ?? 60),
            'work_hours_per_day' => (float)($shift['work_hours_per_day'] ?? 8),
        ];
    }
}

$pdo = new PDO('sqlite::memory:');
$service = new AttendanceService($pdo);

$shift = [
    'id' => 1,
    'start_time' => '08:30:00',
    'end_time' => '17:30:00',
    'grace_period_minutes' => 15,
    'break_minutes' => 60,
    'work_hours_per_day' => 8,
];

function assertSameValue($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "[OK] {$label}" . PHP_EOL;
}

$onTime = $service->determineCheckIn(['work_mode' => 'OFFICE'], $shift, '2026-04-24 08:45:00');
assertSameValue('PRESENT', $onTime['status'], 'check-in at grace boundary is present');
assertSameValue(0, $onTime['late_minutes'], 'check-in at grace boundary has no late minutes');

$late = $service->determineCheckIn(['work_mode' => 'OFFICE'], $shift, '2026-04-24 08:46:00');
assertSameValue('LATE', $late['status'], 'check-in after grace is late');
assertSameValue(16, $late['late_minutes'], 'late minutes count from shift start');

$planned = $service->determineCheckIn(['work_mode' => 'OFFICE'], $shift, '2026-04-24 10:31:00', '10:00:00');
assertSameValue('LATE', $planned['status'], 'planned late start after planned grace is late');
assertSameValue(31, $planned['late_minutes'], 'planned late minutes count from planned start');
assertSameValue(30, $planned['grace_minutes'], 'planned late start uses 30 minute grace');

$wfh = $service->determineCheckIn(['work_mode' => 'WFH'], $shift, '2026-04-24 12:00:00');
assertSameValue('WFH', $wfh['status'], 'WFH check-in status');
assertSameValue(0, $wfh['late_minutes'], 'WFH never accrues late minutes');

$work = $service->summarizeWork('2026-04-24 08:30:00', '2026-04-24 18:45:00', $shift, '2026-04-24');
assertSameValue(555, $work['work_minutes'], 'work minutes subtract break');
assertSameValue(75, $work['ot_minutes'], 'OT minutes above expected work day');
assertSameValue(0, $work['early_leave_minutes'], 'late checkout has no early leave');

$early = $service->summarizeWork('2026-04-24 08:30:00', '2026-04-24 16:30:00', $shift, '2026-04-24');
assertSameValue(420, $early['work_minutes'], 'early checkout work minutes subtract break');
assertSameValue(60, $early['early_leave_minutes'], 'early leave minutes before shift end');

$summary = $service->summarizeAttendance(
    ['work_mode' => 'OFFICE'],
    $shift,
    '2026-04-24',
    '08:46',
    '18:45',
    null,
    null
);
assertSameValue('2026-04-24 08:46:00', $summary['check_in_at'], 'short check-in time normalizes');
assertSameValue('2026-04-24 18:45:00', $summary['check_out_at'], 'short checkout time normalizes');
assertSameValue('LATE', $summary['status'], 'summary carries late status');
assertSameValue(539, $summary['work_minutes'], 'summary work minutes');

echo "AttendanceService regression fixtures passed." . PHP_EOL;
