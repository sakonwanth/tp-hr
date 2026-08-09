<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Services/PayrollService.php';

$pdo = Database::getInstance()->getConnection();
$payroll = new PayrollService($pdo);

echo "=== Stored vs Fresh (all slips) ===\n";
$slips = $pdo->query("
  SELECT s.id, s.user_id, u.employee_code, r.id run_id, r.payroll_month, r.pay_day, r.status,
         s.absent_days, s.absence_deduction, s.lateness_deduction
  FROM payroll_slips s
  JOIN users u ON u.id = s.user_id
  JOIN payroll_runs r ON r.id = s.payroll_run_id
  ORDER BY r.payroll_month, u.employee_code
")->fetchAll(PDO::FETCH_ASSOC);

$mismatch = 0;
foreach ($slips as $s) {
    $mf = date('Y-m-01', strtotime($s['payroll_month']));
    $pd = (int) $s['pay_day'];
    $fresh = $payroll->computeAttendanceDeductions((int) $s['user_id'], $mf, $pd);
    $dAbs = round((float) $fresh['absence_deduction'] - (float) $s['absence_deduction'], 2);
    $dLate = round((float) $fresh['lateness_deduction'] - (float) $s['lateness_deduction'], 2);
    $dDays = round((float) $fresh['absent_days'] - (float) $s['absent_days'], 2);
    if (abs($dAbs) > 0.001 || abs($dLate) > 0.001 || abs($dDays) > 0.001) {
        $mismatch++;
        echo "MISMATCH slip={$s['id']} {$s['employee_code']} run={$s['run_id']} {$s['status']}\n";
        echo "  stored absent={$s['absent_days']} abs={$s['absence_deduction']} late={$s['lateness_deduction']}\n";
        echo "  fresh  absent={$fresh['absent_days']} abs={$fresh['absence_deduction']} late={$fresh['lateness_deduction']}\n";
    }
}
if ($mismatch === 0) {
    echo 'All ' . count($slips) . " slips match fresh calculation.\n";
}

echo "\n=== Attendance LATE/ABSENT on non-workdays (TPE*, 2026) ===\n";
$users = $pdo->query("SELECT id, employee_code FROM users WHERE employee_code LIKE 'TPE%' AND is_active = 1 ORDER BY employee_code")
    ->fetchAll(PDO::FETCH_ASSOC);
$found = 0;
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
foreach ($users as $u) {
    $uid = (int) $u['id'];
    $ctx = $payroll->buildWorkdayContext($uid, '2026-01-01', '2026-12-31');
    $att = $pdo->prepare("
        SELECT attendance_date, status, late_minutes, remarks
        FROM hr_attendances
        WHERE user_id = ? AND attendance_date BETWEEN '2026-01-01' AND '2026-12-31'
          AND (status IN ('ABSENT','LATE') OR late_minutes > 0)
        ORDER BY attendance_date
    ");
    $att->execute([$uid]);
    foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($payroll->isPayrollWorkday($ctx, $r['attendance_date'])) {
            continue;
        }
        $found++;
        $w = $dayNames[(int) date('w', strtotime($r['attendance_date']))] ?? '?';
        echo "{$u['employee_code']} {$r['attendance_date']} ({$w}) {$r['status']} late={$r['late_minutes']}m";
        if (!empty($r['remarks'])) {
            echo " — {$r['remarks']}";
        }
        echo "\n";
    }
}
if ($found === 0) {
    echo "(none — payroll-safe; no misleading LATE/ABSENT on off days)\n";
}

echo "\n=== ABSENT/LATE + approved leave same day (TPE*, 2026) ===\n";
$conf = $pdo->query("
  SELECT u.employee_code, a.attendance_date, a.status, lt.code leave_code
  FROM hr_attendances a
  JOIN users u ON u.id = a.user_id
  JOIN hr_leave_requests lr ON lr.user_id = a.user_id AND lr.status = 'APPROVED'
    AND a.attendance_date BETWEEN lr.start_date AND lr.end_date
  JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
  WHERE u.employee_code LIKE 'TPE%'
    AND a.attendance_date BETWEEN '2026-01-01' AND '2026-12-31'
    AND a.status IN ('ABSENT','LATE')
  ORDER BY a.attendance_date, u.employee_code
")->fetchAll(PDO::FETCH_ASSOC);
if (!$conf) {
    echo "(none — good)\n";
}
foreach ($conf as $c) {
    echo "{$c['employee_code']} {$c['attendance_date']} {$c['status']} but leave {$c['leave_code']} approved\n";
}

echo "\n=== Slips with any deduction (current stored) ===\n";
$ded = $pdo->query("
  SELECT s.id, u.employee_code, r.id run_id, r.payroll_month, r.status,
         s.absent_days, s.absence_deduction, s.lateness_deduction
  FROM payroll_slips s
  JOIN users u ON u.id = s.user_id
  JOIN payroll_runs r ON r.id = s.payroll_run_id
  WHERE s.absent_days > 0 OR s.absence_deduction > 0 OR s.lateness_deduction > 0
  ORDER BY r.payroll_month, u.employee_code
")->fetchAll(PDO::FETCH_ASSOC);
if (!$ded) {
    echo "(none — no employee has absence/late deduction on any slip)\n";
}
foreach ($ded as $d) {
    echo "slip={$d['id']} {$d['employee_code']} run={$d['run_id']} month={$d['payroll_month']} {$d['status']} absent={$d['absent_days']} abs={$d['absence_deduction']} late={$d['lateness_deduction']}\n";
}

echo "\nDone.\n";
