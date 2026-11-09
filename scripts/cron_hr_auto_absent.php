<?php
/**
 * Cron: HR auto-mark absent (DB only — ไม่ส่ง LINE)
 *
 * Schedule: จ–ส 11:00 (หลังเวลาเข้างานปกติ ~10:00; บริษัทหยุดอาทิตย์)
 *
 * Crontab:
 *   0 11 * * 1-6 /opt/plesk/php/8.3/bin/php /path/to/scripts/cron_hr_auto_absent.php >> /path/to/logs/cron_hr.log 2>&1
 *   1 11 * * 1-6 /opt/plesk/php/8.3/bin/php /path/to/scripts/cron_hr_daily_summary.php >> /path/to/logs/cron_hr.log 2>&1
 *
 * Policy:
 *   - มาร์คขาดใน DB สำหรับ payroll/รายงาน (ไม่แจ้ง LINE ผู้บริหารหรือพนักงาน)
 *   - แจ้ง LINE เฉพาะเมื่อพนักงานแจ้งล่วงหน้า (planned late, notify_late, leave ฯลฯ)
 *   - ข้ามถ้ามี planned_start_time หรือ late_excused (แจ้งล่วงหน้าแล้ว)
 *
 * Safety: idempotent — รันซ้ำได้ (uk_user_date)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/LegacyHrUi.php';
require_once __DIR__ . '/../modules/hr/notifications.php';

$pdo = getDB();
$today = date('Y-m-d');

echo "[" . date('Y-m-d H:i:s') . "] cron_hr_auto_absent start (db-only, no LINE)\n";

$enabled = function_exists('getSetting') ? getSetting('hr_cron_auto_absent_enabled', '1') : '1';
if ($enabled !== '1') { echo "  DISABLED via hr_cron_auto_absent_enabled\n"; exit(0); }

$users = $pdo->query("
    SELECT id,
           CONCAT(COALESCE(first_name_th,''), ' ', COALESCE(last_name_th,'')) AS full_name,
           employee_code
    FROM users u
    WHERE " . (function_exists('tp_crm_attendance_scope_filter_sql')
        ? tp_crm_attendance_scope_filter_sql('u')
        : "is_active = 1 AND employee_code LIKE 'TPE%' AND account_type = 'PERSON' AND is_employee = 1 AND is_test_account = 0") . "
    ORDER BY employee_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

$marked = 0; $skipped_leave = 0; $skipped_present = 0; $skipped_notified = 0;

$chkLog = $pdo->prepare("
    SELECT id, status, check_in_time, late_excused, planned_start_time, planned_status
    FROM hr_attendances
    WHERE user_id = ? AND attendance_date = ?
    LIMIT 1
");
$chkLeave = $pdo->prepare("
    SELECT id FROM hr_leave_requests
    WHERE user_id = ?
      AND start_date <= ? AND end_date >= ?
      AND status NOT IN ('REJECTED', 'CANCELLED')
    LIMIT 1
");
// Phase 2.1 op #12: route the ABSENT write through HrService (dual-mode; identical CASE upsert).
require_once __DIR__ . '/../core/Services/HrOperationsService.php';
$hrSvc = new HrOperationsService($pdo);

$audit_tag = "[" . date('Y-m-d H:i') . " by system/cron] auto-marked absent (ไม่เช็คอินและไม่ได้แจ้งล่วงหน้า)";

foreach ($users as $u) {
    if (class_exists(\TpCommon\Hr\WorkdayCalculator::class)
        && !\TpCommon\Hr\WorkdayCalculator::isExpectedWorkdayForUser($pdo, (int)$u['id'], $today)) {
        continue;
    }

    $chkLeave->execute([$u['id'], $today, $today]);
    if ($chkLeave->fetchColumn()) { $skipped_leave++; continue; }

    $chkLog->execute([$u['id'], $today]);
    $log = $chkLog->fetch(PDO::FETCH_ASSOC);
    if ($log) {
        if (in_array($log['status'], ['PRESENT','LATE','WFH','HALF_DAY','LEAVE','HOLIDAY'], true) && !empty($log['check_in_time'])) {
            $skipped_present++;
            continue;
        }
        if (in_array($log['status'], ['LEAVE','HOLIDAY','WFH'], true)) { $skipped_present++; continue; }
        if ((int)($log['late_excused'] ?? 0) === 1) { $skipped_notified++; continue; }
        if (($log['planned_status'] ?? null) === 'APPROVED' && !empty($log['planned_start_time'])) { $skipped_notified++; continue; }
    }

    $hrSvc->autoMarkAbsent((int)$u['id'], $today, $audit_tag);
    $marked++;
    echo "  MARKED ABSENT: {$u['full_name']} ({$u['employee_code']})\n";
}

echo "[" . date('Y-m-d H:i:s') . "] done — marked={$marked}, skipped_leave={$skipped_leave}, skipped_present={$skipped_present}, skipped_notified={$skipped_notified}\n";
exit(0);
