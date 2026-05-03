<?php
/**
 * Health check endpoint — GET /api/health
 */
require_once dirname(__DIR__) . '/bootstrap.php';

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
echo json_encode(['status' => 'ok', 'project' => 'tp-hr', 'tp_common' => false], JSON_UNESCAPED_UNICODE);
