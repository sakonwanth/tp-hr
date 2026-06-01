#!/usr/bin/env php
<?php
/**
 * Holiday work LINE notification smoke test (dry-run by default).
 *
 * Usage:
 *   php scripts/test_holiday_work_line.php              # dry-run: bridge, flex, config, recipients
 *   php scripts/test_holiday_work_line.php --send         # push real LINE + verify line_notification_log
 *   php scripts/test_holiday_work_line.php --send --cleanup
 *
 * Safe on production: uses [AUTO_TEST_HOLIDAY_WORK_LINE] marker and removes test rows with --cleanup.
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/CrmLineNotifierBridge.php';

$marker = '[AUTO_TEST_HOLIDAY_WORK_LINE]';
$send = in_array('--send', $argv, true);
$cleanup = in_array('--cleanup', $argv, true);
$userId = 0;
$holidayDate = '';
$compDate = '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user-id=')) {
        $userId = (int) substr($arg, 10);
    } elseif (str_starts_with($arg, '--holiday=')) {
        $holidayDate = substr($arg, 10);
    } elseif (str_starts_with($arg, '--comp=')) {
        $compDate = substr($arg, 7);
    }
}

$failures = [];
$oks = [];

function tl_ok(string $msg): void
{
    global $oks;
    $oks[] = $msg;
    echo "OK  {$msg}\n";
}

function tl_fail(string $msg): void
{
    global $failures;
    $failures[] = $msg;
    echo "FAIL {$msg}\n";
}

function tl_assert(bool $cond, string $ok, string $fail): void
{
    $cond ? tl_ok($ok) : tl_fail($fail);
}

function tl_latest_log_id(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM line_notification_log')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function tl_logs_since(PDO $pdo, int $sinceId, string $eventSuffix): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT id, module, event, target_type, target_user_id, status, error_message, created_at
            FROM line_notification_log
            WHERE id > ? AND event = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$sinceId, $eventSuffix]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function tl_count_role_line_recipients(PDO $pdo, array $roles): int
{
    if ($roles === []) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.is_active = 1
          AND u.line_user_id IS NOT NULL AND u.line_user_id <> ''
          AND r.name IN ({$ph})
    ");
    $stmt->execute($roles);
    return (int) $stmt->fetchColumn();
}

function tl_setting(PDO $pdo, string $key): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

echo "=== Holiday work LINE test ===\n";
echo 'Environment: ' . (defined('APP_ENV') ? APP_ENV : '?') . "\n";
echo 'Mode: ' . ($send ? 'SEND (real LINE)' : 'dry-run') . "\n\n";

try {
    $pdo = getDB();
} catch (Throwable $e) {
    tl_fail('DB connect: ' . $e->getMessage());
    exit(1);
}

if ($cleanup) {
    $stmt = $pdo->prepare('DELETE FROM hr_holiday_work_exceptions WHERE reason LIKE ?');
    $stmt->execute(['%' . $marker . '%']);
    echo 'Cleanup: removed ' . $stmt->rowCount() . " test row(s)\n";
    exit($failures ? 1 : 0);
}

$crmPath = crm_line_bridge_path();
tl_assert($crmPath !== null, 'TP-CRM path resolved: ' . ($crmPath ?? 'n/a'), 'tp-crm path not found — set TP_CRM_PATH in .env');
tl_assert(crm_line_bridge_load(), 'CRM LINE bridge loaded', 'CRM LINE bridge failed to load');

$crmEnvLoaded = crm_line_bridge_ingest_crm_env((string) crm_line_bridge_path());
if ($crmEnvLoaded) {
    tl_ok('Ingested LINE keys from tp-crm/.env');
}

if (!class_exists('LineNotifier')) {
    tl_fail('LineNotifier class missing after bridge load');
    exit(1);
}

tl_assert(
    method_exists('LineNotifier', 'enabled'),
    'LineNotifier available',
    'LineNotifier incomplete'
);

$lineEnabled = LineNotifier::enabled();
$tokenDefined = defined('LINE_CHANNEL_ACCESS_TOKEN') && (string) LINE_CHANNEL_ACCESS_TOKEN !== '';
$lineNotif = tl_setting($pdo, 'line_notifications_enabled');
$hrLine = tl_setting($pdo, 'hr_line_notifications_enabled');
tl_ok('LineNotifier::enabled() = ' . ($lineEnabled ? 'true' : 'false'));
if (!$lineEnabled) {
    $parts = [];
    if (!$tokenDefined) {
        $parts[] = 'LINE_CHANNEL_ACCESS_TOKEN missing';
    }
    if ($lineNotif === '0' || $hrLine === '0') {
        $parts[] = 'master switch off';
    }
    if ($parts === []) {
        $parts[] = 'check tp-crm/config/line.php on server';
    }
    tl_ok('LINE disabled: ' . implode('; ', $parts) . " (line_notifications_enabled={$lineNotif}, hr_line_notifications_enabled={$hrLine})");
}

$events = [
    'hr.holiday_work_requested' => ['flex_fn' => 'hr_flex_holiday_work_requested', 'expect_mode' => 'roles'],
    'hr.holiday_work_approved' => ['flex_fn' => 'hr_flex_holiday_work_approved', 'expect_mode' => 'triggering_user'],
    'hr.holiday_work_rejected' => ['flex_fn' => 'hr_flex_holiday_work_rejected', 'expect_mode' => 'triggering_user'],
];

foreach ($events as $eventKey => $meta) {
    $cfg = LineNotifier::loadEventConfig($pdo, $eventKey);
    $isProd = defined('APP_ENV') && APP_ENV === 'production';
    if ($cfg === null) {
        if ($isProd) {
            tl_fail("Missing event config {$eventKey}");
        } else {
            tl_ok("Event config {$eventKey} skip (non-production DB)");
        }
        continue;
    }
    tl_assert(!empty($cfg['enabled']), "Event {$eventKey} enabled", "Event {$eventKey} disabled in settings");
    tl_ok("Event {$eventKey} recipient_mode={$cfg['recipient_mode']}");
    if ($meta['expect_mode'] === 'roles' && !empty($cfg['recipient_roles'])) {
        $n = tl_count_role_line_recipients($pdo, $cfg['recipient_roles']);
        if ($isProd) {
            tl_assert($n > 0, "Event {$eventKey}: {$n} role recipient(s) with LINE linked", "Event {$eventKey}: no active users with line_user_id in roles " . implode(',', $cfg['recipient_roles']));
        } else {
            tl_ok("Event {$eventKey}: {$n} role recipient(s) with LINE (non-prod)");
        }
    }

    $fn = $meta['flex_fn'];
    if (function_exists($fn)) {
        $sample = $fn === 'hr_flex_holiday_work_requested'
            ? $fn('ทดสอบ พนักงาน', '2026-05-04', '2026-05-05', 'วันฉัตรมงคล', $marker)
            : ($fn === 'hr_flex_holiday_work_approved'
                ? $fn('2026-05-04', '2026-05-05', $marker)
                : $fn('2026-05-04', $marker));
        tl_assert(is_array($sample) && isset($sample['flex'], $sample['alt']), "Flex builder {$fn} OK", "Flex builder {$fn} invalid output");
    } else {
        tl_fail("Missing flex builder {$fn}");
    }
}

if (!$send) {
    echo "\n--- Result (dry-run) ---\n";
    echo 'Passed: ' . count($oks) . ' | Failed: ' . count($failures) . "\n";
    if ($failures) {
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
        exit(1);
    }
    echo "Dry-run complete. Use --send to push real LINE messages.\n";
    exit(0);
}

// --- Live send path ---
use TpCommon\Hr\WorkdayCalculator;

if ($userId <= 0) {
    $userId = (int) ($pdo->query("
        SELECT u.id FROM users u
        WHERE u.is_active = 1 AND u.id > 1 AND u.employee_code IS NOT NULL AND u.employee_code != ''
          AND u.line_user_id IS NOT NULL AND u.line_user_id <> ''
        ORDER BY u.id ASC LIMIT 1
    ")->fetchColumn() ?: 0);
    if ($userId <= 0) {
        $userId = (int) ($pdo->query("
            SELECT u.id FROM users u
            WHERE u.is_active = 1 AND u.id > 1 AND u.employee_code IS NOT NULL AND u.employee_code != ''
            ORDER BY u.id ASC LIMIT 1
        ")->fetchColumn() ?: 0);
    }
}
tl_assert($userId > 0, "Test employee user_id={$userId}", 'No suitable test employee');

if ($holidayDate === '') {
    $holidayDate = (string) $pdo->query("
        SELECT date FROM hr_holidays WHERE is_active = 1 AND date < CURDATE()
        ORDER BY date DESC LIMIT 1
    ")->fetchColumn();
}
if ($holidayDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
    tl_fail('No past company holiday for test');
    exit(1);
}

if ($compDate === '' && class_exists(WorkdayCalculator::class)) {
    for ($i = 1; $i <= 21; $i++) {
        $candidate = date('Y-m-d', strtotime($holidayDate . " +{$i} days"));
        if (WorkdayCalculator::isExpectedWorkdayForUser($pdo, $userId, $candidate)) {
            $compDate = $candidate;
            break;
        }
    }
}
if ($compDate === '') {
    $compDate = date('Y-m-d', strtotime($holidayDate . ' +1 day'));
}

$hol = $pdo->prepare('SELECT name FROM hr_holidays WHERE date = ? AND is_active = 1 LIMIT 1');
$hol->execute([$holidayDate]);
$holidayName = (string) ($hol->fetchColumn() ?: 'วันหยุด');

$pdo->prepare('DELETE FROM hr_holiday_work_exceptions WHERE user_id = ? AND holiday_date = ? AND reason LIKE ?')
    ->execute([$userId, $holidayDate, '%' . $marker . '%']);

$logBefore = tl_latest_log_id($pdo);
$logTable = (int) $pdo->query("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'line_notification_log'
")->fetchColumn();
if ($logTable <= 0) {
    tl_fail('Missing table line_notification_log — run scripts/ensure_line_notification_log.php');
} else {
    tl_ok('Table line_notification_log exists');
}

$pdo->prepare("
    INSERT INTO hr_holiday_work_exceptions
        (user_id, holiday_date, comp_date, holiday_name, reason, status)
    VALUES (?, ?, ?, ?, ?, 'PENDING')
")->execute([
    $userId,
    $holidayDate,
    $compDate,
    $holidayName,
    $marker . ' LINE smoke ' . date('c'),
]);
$requestId = (int) $pdo->lastInsertId();
tl_assert($requestId > 0, "Created PENDING request #{$requestId}", 'Failed to insert test request');

crm_line_notify_holiday_work_requested($pdo, $requestId);
$reqLogs = tl_logs_since($pdo, $logBefore, 'holiday_work_requested');
if ($lineEnabled && $logTable > 0) {
    tl_assert($reqLogs !== [], 'line_notification_log: holiday_work_requested entry created', 'No log entry for holiday_work_requested');
} else {
    tl_ok('LINE off — skipped log assertion for holiday_work_requested (enable LINE to test live push)');
}

$sentReq = 0;
foreach ($reqLogs as $row) {
    echo "LOG requested #{$row['id']} target={$row['target_user_id']} status={$row['status']}" . ($row['error_message'] ? " err={$row['error_message']}" : '') . "\n";
    if ($row['status'] === 'sent') {
        $sentReq++;
    }
}
if ($lineEnabled) {
    tl_assert($sentReq >= 1, "LINE sent to CEO/Chairman ({$sentReq} sent)", 'LINE not sent — check line_user_id on executives or channel token');
} else {
    tl_ok('LINE channel disabled — log-only verification OK');
}

$logMid = tl_latest_log_id($pdo);
$pdo->prepare("
    UPDATE hr_holiday_work_exceptions
    SET status = 'APPROVED', reviewed_by = 1, reviewed_at = NOW(), review_note = ?
    WHERE id = ?
")->execute([$marker . ' approved', $requestId]);

crm_line_notify_holiday_work_decision($pdo, $requestId, 'APPROVED', $marker . ' approved');
$apprLogs = tl_logs_since($pdo, $logMid, 'holiday_work_approved');
if ($lineEnabled && $logTable > 0) {
    tl_assert($apprLogs !== [], 'line_notification_log: holiday_work_approved entry created', 'No log entry for holiday_work_approved');
} else {
    tl_ok('LINE off — skipped log assertion for holiday_work_approved');
}

$sentAppr = 0;
foreach ($apprLogs as $row) {
    echo "LOG approved #{$row['id']} target={$row['target_user_id']} status={$row['status']}" . ($row['error_message'] ? " err={$row['error_message']}" : '') . "\n";
    if ($row['status'] === 'sent') {
        $sentAppr++;
    }
}
if ($lineEnabled) {
    $stmt = $pdo->prepare('SELECT line_user_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $hasLine = (string) ($stmt->fetchColumn() ?: '') !== '';
    if ($hasLine) {
        tl_assert($sentAppr >= 1, "LINE sent to employee ({$sentAppr} sent)", 'LINE approve notification not sent to employee');
    } else {
        tl_ok('Employee has no line_user_id — approve notification skipped as expected');
    }
} else {
    tl_ok('LINE channel disabled — approve log-only verification OK');
}

$pdo->prepare('DELETE FROM hr_holiday_work_exceptions WHERE id = ?')->execute([$requestId]);
tl_ok("Removed test request #{$requestId}");

echo "\n--- Result (send) ---\n";
echo 'Passed: ' . count($oks) . ' | Failed: ' . count($failures) . "\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\nCleanup: php scripts/test_holiday_work_line.php --cleanup\n";
    exit(1);
}

echo "LINE smoke test complete.";
if ($lineEnabled) {
    echo " Check LINE app on CEO + employee devices.\n";
} else {
    echo " Enable LINE in CRM settings (line_notifications_enabled) to deliver messages.\n";
}
exit(0);
