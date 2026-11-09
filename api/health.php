<?php
/**
 * Health check endpoint — GET /api/health
 */
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/Services/ClockHealth.php';

if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE && class_exists('TpCommon\HealthCheck')) {
    $pdo = null;
    try {
        $pdo = getDB();
    } catch (Throwable $e) {
    }

    $integration = static function (): array {
        $prod = defined('APP_ENV') && strtolower((string) APP_ENV) === 'production';
        $hasUrl = defined('CHECKIN_APP_URL') && CHECKIN_APP_URL !== '';
        $hasStoragePath = defined('CHECKIN_STORAGE_PATH') && CHECKIN_STORAGE_PATH !== '';
        $storageReadable = !$hasStoragePath
            || (is_dir(CHECKIN_STORAGE_PATH) && is_readable(CHECKIN_STORAGE_PATH));

        $status = 'ok';
        if ($hasStoragePath && !$storageReadable) {
            $status = 'error';
        } elseif ($prod && !$hasUrl) {
            $status = 'warning';
        }

        // Resolve mode for attendance photos (operator hint).
        $mode = 'derived_or_hr_app';
        if ($hasStoragePath && $storageReadable) {
            $mode = 'same_origin_proxy';
        } elseif ($hasUrl) {
            $mode = 'checkin_base_url';
        }

        return [
            'clock' => ClockHealth::healthCheck(),
            'checkin' => array_filter([
                'status' => $status,
                'checkin_app_url_configured' => $hasUrl,
                'checkin_storage_path_configured' => $hasStoragePath,
                'checkin_storage_readable' => $hasStoragePath ? $storageReadable : null,
                'attendance_photo_resolve' => $mode,
            ], static fn ($v) => $v !== null),
        ];
    };

    (new \TpCommon\HealthCheck('tp-hr', $pdo, $integration))->run();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$clock = ClockHealth::healthCheck();
http_response_code($clock['status'] === 'error' ? 503 : 200);
echo json_encode(['status' => $clock['status'] === 'error' ? 'degraded' : 'ok', 'project' => 'tp-hr', 'tp_common' => false, 'checks' => ['clock' => ['status' => $clock['status']]]], JSON_UNESCAPED_UNICODE);
