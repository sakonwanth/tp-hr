#!/usr/bin/env php
<?php
/**
 * Post-deploy verification for holiday work feature (production-safe, read-mostly).
 *
 * Usage:
 *   php scripts/verify_holiday_work_production.php [--cleanup] [--base-url=https://hr.tp-asset.com]
 *
 * Checks: test-data cleanup, schema, LINE events, deployed PHP files, public HTTP routes.
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use TpCommon\Hr\WorkdayCalculator;

$marker = '[AUTO_TEST_HOLIDAY_WORK]';
$cleanup = in_array('--cleanup', $argv, true);
$baseUrl = rtrim(getenv('HR_PUBLIC_URL') ?: 'https://hr.tp-asset.com', '/');

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
    }
}

$failures = [];
$oks = [];

function vw_ok(string $msg): void
{
    global $oks;
    $oks[] = $msg;
    echo "OK  {$msg}\n";
}

function vw_fail(string $msg): void
{
    global $failures;
    $failures[] = $msg;
    echo "FAIL {$msg}\n";
}

function vw_assert(bool $cond, string $ok, string $fail): void
{
    $cond ? vw_ok($ok) : vw_fail($fail);
}

echo "=== Holiday work production verify ===\n";
echo 'Environment: ' . (defined('APP_ENV') ? APP_ENV : '?') . "\n";
echo 'Database: ' . (defined('DB_NAME') ? DB_NAME : '?') . '@' . (defined('DB_HOST') ? DB_HOST : '?') . "\n";
echo "Base URL: {$baseUrl}\n\n";

try {
    $pdo = getDB();
} catch (Throwable $e) {
    vw_fail('DB connect: ' . $e->getMessage());
    exit(1);
}

if ($cleanup) {
    $stmt = $pdo->prepare('DELETE FROM hr_holiday_work_exceptions WHERE reason LIKE ?');
    $stmt->execute(['%' . $marker . '%']);
    vw_ok('Cleanup removed ' . $stmt->rowCount() . ' AUTO_TEST row(s)');
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM hr_holiday_work_exceptions WHERE reason LIKE ?');
$stmt->execute(['%' . $marker . '%']);
$remaining = (int) $stmt->fetchColumn();
vw_assert($remaining === 0, 'No AUTO_TEST holiday work rows remain', "Found {$remaining} AUTO_TEST row(s) — run with --cleanup");

$tableExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'hr_holiday_work_exceptions'"
)->fetchColumn() > 0;
vw_assert($tableExists, 'Table hr_holiday_work_exceptions exists', 'Missing table hr_holiday_work_exceptions');

if ($tableExists) {
    $idx = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'hr_holiday_work_exceptions' AND index_name = 'uk_user_holiday'"
    )->fetchColumn();
    vw_assert($idx > 0, 'Unique index uk_user_holiday present', 'Missing uk_user_holiday index');
}

vw_assert(class_exists(WorkdayCalculator::class), 'WorkdayCalculator loaded from tp-common', 'WorkdayCalculator missing — composer update tpasset/tp-common');

$lineEvents = ['hr.holiday_work_requested', 'hr.holiday_work_approved', 'hr.holiday_work_rejected'];
try {
    $placeholders = implode(',', array_fill(0, count($lineEvents), '?'));
    $stmt = $pdo->prepare("SELECT event_key FROM line_notification_events WHERE event_key IN ({$placeholders})");
    $stmt->execute($lineEvents);
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $isProd = defined('APP_ENV') && APP_ENV === 'production';
    foreach ($lineEvents as $key) {
        if (in_array($key, $found, true)) {
            vw_ok("LINE event {$key} seeded");
        } elseif (!$isProd) {
            vw_ok("LINE event {$key} skip (non-production DB)");
        } else {
            vw_fail("Missing LINE event {$key}");
        }
    }
} catch (Throwable $e) {
    vw_fail('LINE events check skipped: ' . $e->getMessage());
}

$requiredFiles = [
    'holiday_work_request.php',
    'hr/holiday_work_approvals.php',
    'api/v1/holiday_work.php',
    'scripts/test_holiday_work_flow.php',
    'scripts/ensure_holiday_work_schema.php',
];
$root = dirname(__DIR__);
foreach ($requiredFiles as $rel) {
    $path = $root . '/' . $rel;
    vw_assert(is_file($path), "Deployed file {$rel} exists", "Missing file {$rel}");
}

$routes = [
    'holiday_work_request.php' => 'login',
    'hr/holiday_work_approvals.php' => 'login',
    'holidays.php' => 'login',
    'hr/employee_summaries.php' => 'login',
    'api/health.php' => 'json',
    'api/v1/holiday-work-requests' => '401',
];

$checkinBase = rtrim(getenv('CHECKIN_PUBLIC_URL') ?: 'https://checkin.tp-asset.com', '/');

echo "\nHTTP smoke ({$baseUrl}):\n";
foreach ($routes as $path => $expect) {
    $url = $baseUrl . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        vw_fail("HTTP {$path}: curl error — {$err}");
        continue;
    }

    if ($expect === 'login') {
        vw_assert(in_array($code, [200, 302, 303], true), "HTTP {$path} reachable ({$code})", "HTTP {$path} unexpected status {$code}");
    } elseif ($expect === 'json') {
        vw_assert($code === 200, "HTTP {$path} returns 200", "HTTP {$path} status {$code}");
        if ($code === 200) {
            $j = json_decode((string) $body, true);
            vw_assert(is_array($j) && isset($j['status']), "HTTP {$path} valid JSON health", "HTTP {$path} invalid JSON");
        }
    } elseif ($expect === '401') {
        vw_assert($code === 401, "HTTP {$path} requires auth (401)", "HTTP {$path} expected 401, got {$code}");
    }
}

$checkinUrl = $checkinBase . '/history.php';
$ch = curl_init($checkinUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
curl_exec($ch);
$checkinCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$checkinErr = curl_error($ch);
curl_close($ch);
if ($checkinErr !== '') {
    vw_fail("HTTP checkin/history.php: curl error — {$checkinErr}");
} else {
    vw_assert(in_array($checkinCode, [200, 302, 303], true), "HTTP checkin/history.php reachable ({$checkinCode})", "HTTP checkin/history.php status {$checkinCode}");
}

$checkinHelpers = dirname(__DIR__, 2) . '/tp-checkin/core/Helpers.php';
if (is_file($checkinHelpers)) {
    $helpersSrc = (string) file_get_contents($checkinHelpers);
    vw_assert(
        str_contains($helpersSrc, 'hr_holiday_work_exceptions') && str_contains($helpersSrc, 'หยุดชดเชย'),
        'tp-checkin Helpers.php has holiday work comp labels',
        'tp-checkin Helpers.php missing holiday work labels'
    );
} else {
    vw_ok('tp-checkin Helpers.php skip (not co-deployed on this host)');
}

$holidaysPath = $root . '/holidays.php';
$holidaysSrc = is_file($holidaysPath) ? (string) file_get_contents($holidaysPath) : '';
vw_assert(
    str_contains($holidaysSrc, 'holiday_work_request.php'),
    'holidays.php template links to holiday_work_request.php',
    'holidays.php missing link to holiday work request'
);

echo "\n--- Result ---\n";
echo 'Passed: ' . count($oks) . ' | Failed: ' . count($failures) . "\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "Holiday work feature verified on production.\n";
exit(0);
