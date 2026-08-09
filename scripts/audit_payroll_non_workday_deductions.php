<?php
/**
 * Audit payroll deductions on non-workdays (day off / holiday / leave).
 *
 * Usage:
 *   php scripts/audit_payroll_non_workday_deductions.php
 *   php scripts/audit_payroll_non_workday_deductions.php --run-id=5
 *   php scripts/audit_payroll_non_workday_deductions.php --run-id=3,5
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Services/PayrollService.php';

$pdo = Database::getInstance()->getConnection();
$payroll = new PayrollService($pdo);

$runIds = [];
foreach ($argv as $arg) {
    if (preg_match('/^--run-id=(.+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $runIds[] = $id;
            }
        }
    }
}

$runSql = 'SELECT id, payroll_month, pay_day, status, employee_count, total_net FROM payroll_runs';
if ($runIds) {
    $runSql .= ' WHERE id IN (' . implode(',', array_map('intval', $runIds)) . ')';
}
$runSql .= ' ORDER BY payroll_month, id';
$runs = $pdo->query($runSql)->fetchAll(PDO::FETCH_ASSOC);

function nonWorkdayReason(PayrollService $payroll, array $ctx, string $date): ?string
{
    if ($payroll->isPayrollWorkday($ctx, $date)) {
        return null;
    }
    if (!empty($ctx['holidays'][$date])) {
        return 'holiday';
    }
    if (!empty($ctx['leave_dates'][$date])) {
        return 'leave';
    }
    $dow = (int) date('w', strtotime($date));
    $effectiveDayOff = (int) ($ctx['day_off'] ?? 0);
    foreach ($ctx['dayoff_requests'] ?? [] as $request) {
        if ($date >= $request['week_start'] && $date <= $request['week_end']) {
            $effectiveDayOff = (int) $request['requested_day_off'];
            break;
        }
    }
    if ($dow === $effectiveDayOff) {
        return 'scheduled_day_off';
    }
    return 'non_workday';
}

function dayLabel(string $date): string
{
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $w = (int) date('w', strtotime($date));
    return $names[$w] ?? '?';
}

echo "=== Audit: payroll deductions on non-workdays ===\n\n";

$issueCount = 0;
$slipCount = 0;

foreach ($runs as $run) {
    $runId = (int) $run['id'];
    $monthFirst = date('Y-m-01', strtotime($run['payroll_month']));
    $payDay = max(1, min(31, (int) ($run['pay_day'] ?? 25)));
    $period = $payroll->attendancePeriodBounds($monthFirst, $payDay);
    $periodStart = $period['start'];
    $periodEnd = $period['end'];

    echo "Run #{$runId}  month={$monthFirst}  pay_day={$payDay}  status={$run['status']}\n";
    echo "  Period: {$periodStart} .. {$periodEnd}\n";

    $slips = $pdo->prepare("
        SELECT s.*, u.employee_code, u.first_name_th, u.last_name_th
        FROM payroll_slips s
        JOIN users u ON u.id = s.user_id
        WHERE s.payroll_run_id = ?
        ORDER BY u.employee_code
    ");
    $slips->execute([$runId]);
    $rows = $slips->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "  (no slips)\n\n";
        continue;
    }

    $runIssues = 0;
    foreach ($rows as $slip) {
        $slipCount++;
        $userId = (int) $slip['user_id'];
        $code = (string) ($slip['employee_code'] ?? '-');
        $ctx = $payroll->buildWorkdayContext($userId, $periodStart, $periodEnd);
        $fresh = $payroll->computeAttendanceDeductions($userId, $monthFirst, $payDay);

        $storedAbsent = (float) ($slip['absent_days'] ?? 0);
        $storedAbsDed = (float) ($slip['absence_deduction'] ?? 0);
        $storedLateDed = (float) ($slip['lateness_deduction'] ?? 0);
        $freshAbsent = (float) ($fresh['absent_days'] ?? 0);
        $freshAbsDed = (float) ($fresh['absence_deduction'] ?? 0);
        $freshLateDed = (float) ($fresh['lateness_deduction'] ?? 0);

        $storedDetail = !empty($slip['attendance_detail_json'])
            ? json_decode($slip['attendance_detail_json'], true)
            : null;
        $storedBreakdown = is_array($storedDetail['breakdown'] ?? null) ? $storedDetail['breakdown'] : [];

        $badStored = [];
        foreach ($storedBreakdown as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $reason = nonWorkdayReason($payroll, $ctx, $date);
            if ($reason !== null) {
                $badStored[] = [
                    'source' => 'stored_breakdown',
                    'date' => $date,
                    'day' => dayLabel($date),
                    'kind' => $row['kind'] ?? '',
                    'amount' => (float) ($row['amount'] ?? 0),
                    'note' => $row['note'] ?? '',
                    'reason' => $reason,
                ];
            }
        }

        $badFresh = [];
        foreach ($fresh['breakdown'] ?? [] as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $reason = nonWorkdayReason($payroll, $ctx, $date);
            if ($reason !== null) {
                $badFresh[] = [
                    'source' => 'fresh_calc',
                    'date' => $date,
                    'day' => dayLabel($date),
                    'kind' => $row['kind'] ?? '',
                    'amount' => (float) ($row['amount'] ?? 0),
                    'note' => $row['note'] ?? '',
                    'reason' => $reason,
                ];
            }
        }

        $badAttendance = [];
        $att = $pdo->prepare("
            SELECT attendance_date, status, late_minutes, late_excused, remarks
            FROM hr_attendances
            WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
              AND (status IN ('ABSENT','LATE') OR late_minutes > 0)
            ORDER BY attendance_date
        ");
        $att->execute([$userId, $periodStart, $periodEnd]);
        foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $date = (string) $r['attendance_date'];
            $reason = nonWorkdayReason($payroll, $ctx, $date);
            if ($reason === null) {
                continue;
            }
            $badAttendance[] = [
                'date' => $date,
                'day' => dayLabel($date),
                'status' => $r['status'],
                'late_minutes' => (int) ($r['late_minutes'] ?? 0),
                'reason' => $reason,
                'remarks' => $r['remarks'] ?? '',
            ];
        }

        $stale = abs($storedAbsDed - $freshAbsDed) > 0.001
            || abs($storedLateDed - $freshLateDed) > 0.001
            || abs($storedAbsent - $freshAbsent) > 0.001;

        if (!$badStored && !$badFresh && !$badAttendance && !$stale) {
            continue;
        }

        $runIssues++;
        $issueCount++;
        $name = trim(($slip['first_name_th'] ?? '') . ' ' . ($slip['last_name_th'] ?? ''));
        echo "\n  [{$code}] {$name}  slip #{$slip['id']}\n";
        echo "    stored: absent={$storedAbsent} abs_ded={$storedAbsDed} late_ded={$storedLateDed}\n";
        echo "    fresh:  absent={$freshAbsent} abs_ded={$freshAbsDed} late_ded={$freshLateDed}\n";
        if ($stale) {
            echo "    ⚠ STORED vs FRESH mismatch — recalculate required\n";
        }
        foreach ($badStored as $b) {
            echo "    ✗ stored {$b['date']} ({$b['day']}) {$b['kind']} {$b['amount']} [{$b['reason']}] {$b['note']}\n";
        }
        foreach ($badFresh as $b) {
            echo "    ✗ fresh  {$b['date']} ({$b['day']}) {$b['kind']} {$b['amount']} [{$b['reason']}] {$b['note']}\n";
        }
        foreach ($badAttendance as $b) {
            echo "    ~ attendance {$b['date']} ({$b['day']}) {$b['status']} late={$b['late_minutes']}m [{$b['reason']}]";
            if ($b['remarks'] !== '') {
                echo " — {$b['remarks']}";
            }
            echo "\n";
        }
    }

    if ($runIssues === 0) {
        echo "  ✓ No non-workday deduction issues (" . count($rows) . " slip(s) checked)\n";
    } else {
        echo "  Found {$runIssues} employee(s) with issues in this run\n";
    }
    echo "\n";
}

echo "=== Summary ===\n";
echo "Runs checked: " . count($runs) . "\n";
echo "Slips checked: {$slipCount}\n";
echo "Employees with issues: {$issueCount}\n";
if ($issueCount === 0) {
    echo "All clear — no deductions on holidays/day-off/leave detected.\n";
} else {
    echo "Action: recalculate affected runs; fix attendance records on non-workdays if needed.\n";
}
