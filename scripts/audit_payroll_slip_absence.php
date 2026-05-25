<?php
/**
 * Audit payroll absence for a specific slip — CLI diagnostic
 *
 * Usage:
 *   php scripts/audit_payroll_slip_absence.php --slip-id=251
 *   php scripts/audit_payroll_slip_absence.php --user-id=123 --month=2026-05
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Services/PayrollService.php';
require_once __DIR__ . '/../core/Services/EmployeeSummaryService.php';

$pdo = Database::getInstance()->getConnection();

$slipId = (int)(getopt('', ['slip-id:'])['slip-id'] ?? 0);
$userId = (int)(getopt('', ['user-id:'])['user-id'] ?? 0);
$month = (string)(getopt('', ['month:'])['month'] ?? '');

if ($slipId > 0) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.employee_code, u.first_name_th, u.last_name_th, u.work_mode,
               r.payroll_month, r.status AS run_status, r.id AS run_id
        FROM payroll_slips s
        JOIN users u ON u.id = s.user_id
        JOIN payroll_runs r ON r.id = s.payroll_run_id
        WHERE s.id = ?
    ");
    $stmt->execute([$slipId]);
    $slip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slip) {
        fwrite(STDERR, "Slip #{$slipId} not found\n");
        exit(1);
    }
    $userId = (int)$slip['user_id'];
    $month = date('Y-m', strtotime($slip['payroll_month']));
} elseif ($userId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    fwrite(STDERR, "Usage: --slip-id=N  OR  --user-id=N --month=YYYY-MM\n");
    exit(1);
} else {
    $stmt = $pdo->prepare("SELECT employee_code, first_name_th, last_name_th, work_mode FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $slip = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $slip['user_id'] = $userId;
    $slip['payroll_month'] = $month . '-01';
}

$monthFirst = date('Y-m-01', strtotime($slip['payroll_month']));
$monthEnd = date('Y-m-t', strtotime($monthFirst));
$name = trim(($slip['first_name_th'] ?? '') . ' ' . ($slip['last_name_th'] ?? ''));
$code = $slip['employee_code'] ?? '-';

echo "=== Payroll Absence Audit ===\n";
echo "Employee: {$code} {$name} (user_id={$userId})\n";
echo "Month: {$monthFirst} .. {$monthEnd}\n";
if ($slipId > 0) {
    echo "Slip ID: {$slipId}\n";
    echo "Run status: " . ($slip['run_status'] ?? '-') . " (run_id=" . ($slip['run_id'] ?? '-') . ")\n";
    echo "Stored: absent_days=" . ($slip['absent_days'] ?? 0)
        . " absence_deduction=" . ($slip['absence_deduction'] ?? 0)
        . " late_deduction=" . ($slip['lateness_deduction'] ?? 0) . "\n";
    if (!empty($slip['attendance_detail_json'])) {
        $storedDetail = json_decode($slip['attendance_detail_json'], true);
        if (is_array($storedDetail) && !empty($storedDetail['breakdown'])) {
            echo "\n--- Stored breakdown (frozen at calculate time) ---\n";
            foreach ($storedDetail['breakdown'] as $row) {
                if (!in_array($row['kind'] ?? '', ['absent', 'sick_no_cert', 'missing_attendance_absent', 'late_over60_absent', 'late_30', 'late_60'], true)) {
                    continue;
                }
                echo sprintf(
                    "  %s  %-24s  %8.2f  %s\n",
                    $row['date'] ?? '-',
                    $row['kind'] ?? '',
                    (float)($row['amount'] ?? 0),
                    $row['note'] ?? ''
                );
            }
        } else {
            echo "Stored attendance_detail_json present (unparsed)\n";
        }
    } else {
        echo "WARNING: no attendance_detail_json — slip calculated before attendance columns or fallback schema\n";
    }
    if (in_array($slip['run_status'] ?? '', ['approved', 'paid'], true)) {
        echo "\nNOTE: Run is {$slip['run_status']} — payroll_print shows STORED values until cancel_approval + recalculate\n";
    }
}

$payroll = new PayrollService($pdo);
$fresh = $payroll->computeAttendanceDeductions($userId, $monthFirst);
echo "\n--- Fresh PayrollService calculation (tp-hr canonical) ---\n";
echo "absent_days: {$fresh['absent_days']}\n";
echo "absence_deduction: {$fresh['absence_deduction']}\n";
echo "lateness_deduction: {$fresh['lateness_deduction']}\n";
if (!empty($fresh['breakdown'])) {
    echo "\nBreakdown:\n";
    foreach ($fresh['breakdown'] as $row) {
        echo sprintf(
            "  %s  %-22s  %8.2f  %s\n",
            $row['date'] ?? '-',
            $row['kind'] ?? '',
            (float)($row['amount'] ?? 0),
            $row['note'] ?? ''
        );
    }
}
if (!empty($fresh['warnings'])) {
    echo "\nWarnings:\n";
    foreach ($fresh['warnings'] as $w) {
        echo '  - ' . ($w['note'] ?? json_encode($w, JSON_UNESCAPED_UNICODE)) . "\n";
    }
}

if ($slipId > 0) {
    $storedAbsent = (float)($slip['absent_days'] ?? 0);
    $storedAbsDed = (float)($slip['absence_deduction'] ?? 0);
    $freshAbsent = (float)($fresh['absent_days'] ?? 0);
    $freshAbsDed = (float)($fresh['absence_deduction'] ?? 0);
    echo "\n--- Stored vs Fresh (tp-hr PayrollService) ---\n";
    echo "absent_days:      stored={$storedAbsent}  fresh={$freshAbsent}  delta=" . ($freshAbsent - $storedAbsent) . "\n";
    echo "absence_deduction: stored={$storedAbsDed}  fresh={$freshAbsDed}  delta=" . ($freshAbsDed - $storedAbsDed) . "\n";
    if (abs($freshAbsDed - $storedAbsDed) > 0.001) {
        echo "ACTION: recalculate slip required (cancel approval if approved, then create_run or payroll_recalculate_employee_slip)\n";
    } else {
        echo "Stored matches fresh — absence on slip reflects current attendance/leave data.\n";
    }
}

$summary = (new EmployeeSummaryService($pdo))->getMonthlySummary($userId, $month);
echo "\n--- EmployeeSummaryService (HR dashboard) ---\n";
echo "absent_days: " . ($summary['counts']['absent_days'] ?? 0) . "\n";
echo "leave_days (attendance LEAVE): " . ($summary['counts']['leave_days'] ?? 0) . "\n";
echo "approved_leave_days: " . ($summary['approved_leave_days'] ?? 0) . "\n";

echo "\n--- hr_attendances in month ---\n";
$att = $pdo->prepare("
    SELECT attendance_date, status, late_minutes, late_excused, adjustment_reason
    FROM hr_attendances
    WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
    ORDER BY attendance_date
");
$att->execute([$userId, $monthFirst, $monthEnd]);
$rows = $att->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "  (no records)\n";
} else {
    foreach ($rows as $r) {
        $line = "  {$r['attendance_date']}  {$r['status']}";
        if ((int)($r['late_minutes'] ?? 0) > 0) {
            $line .= "  late={$r['late_minutes']}m";
        }
        echo $line . "\n";
    }
}

echo "\n--- Approved leave requests overlapping month ---\n";
$lv = $pdo->prepare("
    SELECT lr.id, lr.start_date, lr.end_date, lr.total_days, lr.status,
           lt.code, lt.name,
           CASE WHEN lr.document_path IS NOT NULL THEN 1 ELSE 0 END AS has_cert
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.user_id = ? AND lr.status = 'APPROVED'
      AND lr.start_date <= ? AND lr.end_date >= ?
    ORDER BY lr.start_date
");
$lv->execute([$userId, $monthEnd, $monthFirst]);
$leaves = $lv->fetchAll(PDO::FETCH_ASSOC);
if (!$leaves) {
    echo "  (none)\n";
} else {
    foreach ($leaves as $l) {
        $cert = (int)$l['has_cert'] === 1 ? 'has cert' : 'NO cert';
        echo "  #{$l['id']} {$l['code']} {$l['name']}  {$l['start_date']}..{$l['end_date']}  {$l['total_days']}d  [{$cert}]\n";
    }
}

echo "\n--- Conflicts: ABSENT attendance + approved leave same day ---\n";
$conf = $pdo->prepare("
    SELECT a.attendance_date, a.status, lt.code, lt.name
    FROM hr_attendances a
    JOIN hr_leave_requests lr ON lr.user_id = a.user_id AND lr.status = 'APPROVED'
      AND a.attendance_date BETWEEN lr.start_date AND lr.end_date
    JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
    WHERE a.user_id = ? AND a.attendance_date BETWEEN ? AND ? AND a.status = 'ABSENT'
    ORDER BY a.attendance_date
");
$conf->execute([$userId, $monthFirst, $monthEnd]);
$conflicts = $conf->fetchAll(PDO::FETCH_ASSOC);
if (!$conflicts) {
    echo "  (none — good)\n";
} else {
    foreach ($conflicts as $c) {
        echo "  {$c['attendance_date']}  ABSENT but {$c['code']} approved → should be LEAVE\n";
    }
}

echo "\nDone.\n";
