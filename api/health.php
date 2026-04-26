<?php
/**
 * Health check endpoint — GET /api/health
 */
require_once dirname(__DIR__) . '/bootstrap.php';

if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE && class_exists('TpCommon\HealthCheck')) {
    $pdo = null;
    try { $pdo = getDB(); } catch (Throwable $e) {}
    (new \TpCommon\HealthCheck('tp-hr', $pdo))->run();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo json_encode(['status' => 'ok', 'project' => 'tp-hr', 'tp_common' => false]);
