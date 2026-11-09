<?php
/**
 * Cron: สรุปการเข้างานรายวัน → LINE ผู้บริหาร (Chairman/CEO)
 *
 * Schedule: จ–ส 11:01 (หลังเวลาเข้างานปกติ ~10:00 — ให้เห็นมาสาย/ขาด/ลาได้ถูกต้อง; บริษัทหยุดอาทิตย์)
 *
 * Crontab:
 *   1 11 * * 1-6 /opt/plesk/php/8.3/bin/php /path/to/scripts/cron_hr_daily_summary.php >> /path/to/logs/cron_hr.log 2>&1
 *
 * หมายเหตุ: ไม่ส่ง LINE เมื่อระบบมาร์คขาดเอง — แจ้งเฉพาะ event ที่พนักงาน/HR trigger
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/ClockHealth.php';
ClockHealth::assertSafe();
require_once __DIR__ . '/../core/LegacyHrUi.php';
require_once __DIR__ . '/../config/line.php';
require_once __DIR__ . '/../modules/hr/notifications.php';
require_once __DIR__ . '/../core/Services/HrOperationsService.php';
require_once __DIR__ . '/../core/Services/LineNotifier.php';

$pdo = getDB();
$today = date('Y-m-d');

echo "[" . date('Y-m-d H:i:s') . "] cron_hr_daily_summary start\n";

if (function_exists('getSetting') && getSetting('hr_cron_daily_summary_enabled', '1') !== '1') {
    echo "  DISABLED via hr_cron_daily_summary_enabled\n";
    exit(0);
}

if (hr_is_holiday($pdo, $today)) {
    echo "  HOLIDAY/WEEKEND skip\n";
    exit(0);
}

$hrService = new HrOperationsService($pdo);
$summary = $hrService->getTodayAttendanceSummary();
$flex = hr_flex_daily_summary(
    $today,
    $summary['absent'],
    $summary['onLeave'],
    $summary['present'],
    $summary['late'] ?? 0,
    $summary['total'] ?? null
);

$sent = LineNotifier::sendEvent($pdo, 'hr.daily_summary', $flex);

echo "  summary: total=" . ($summary['total'] ?? '?') . " present={$summary['present']} late=" . ($summary['late'] ?? 0)
    . " leave={$summary['onLeave']} absent={$summary['absent']} line_sent={$sent}\n";
echo "[" . date('Y-m-d H:i:s') . "] cron_hr_daily_summary done\n";
exit(0);
