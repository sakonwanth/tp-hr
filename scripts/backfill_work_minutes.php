<?php
/**
 * Backfill hr_attendances.work_minutes จาก check_in + check_out ที่มีอยู่แต่ work_minutes = 0
 * Usage: php scripts/backfill_work_minutes.php [--dry-run]
 */
require_once dirname(__DIR__) . '/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = Database::getInstance()->getConnection();
$svc = new AttendanceService($pdo);

$stmt = $pdo->query("
    SELECT id, user_id, attendance_date, shift_id, check_in_time, check_out_time, work_minutes, status
    FROM hr_attendances
    WHERE check_in_time IS NOT NULL
      AND check_out_time IS NOT NULL
      AND COALESCE(work_minutes, 0) = 0
      AND status IN ('PRESENT', 'LATE', 'WFH', 'HALF_DAY')
    ORDER BY attendance_date ASC, id ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($rows as $row) {
    $shift = $svc->getShiftById((int)($row['shift_id'] ?? 0)) ?: $svc->getDefaultShift();
    $summary = $svc->summarizeWork(
        $row['check_in_time'],
        $row['check_out_time'],
        $shift,
        $row['attendance_date']
    );
    $mins = (int)$summary['work_minutes'];
    if ($mins <= 0) {
        continue;
    }
    if (!$dryRun) {
        $upd = $pdo->prepare("
            UPDATE hr_attendances
            SET work_minutes = ?, break_minutes = ?, ot_minutes = ?, early_leave_minutes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([
            $mins,
            (int)$summary['break_minutes'],
            (int)$summary['ot_minutes'],
            (int)$summary['early_leave_minutes'],
            $row['id'],
        ]);
    }
    $updated++;
    echo ($dryRun ? '[dry-run] ' : '') . "user {$row['user_id']} {$row['attendance_date']} => {$mins} min\n";
}

echo "Done. " . ($dryRun ? 'Would update' : 'Updated') . " {$updated} row(s).\n";
