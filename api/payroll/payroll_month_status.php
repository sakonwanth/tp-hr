<?php
/**
 * Payroll API: สถานะรอบเงินเดือนของเดือนที่กำหนด
 * GET ?month=YYYY-MM
 * ใช้ให้ระบบบันทึกเวลาตรวจว่าเดือนนั้น lock แล้วหรือยัง (ไม่ส่งข้อมูลซ้ำ)
 * สิทธิ์: HR, Admin, Chairman, CEO
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../core/LegacyHrUi.php';

header('Content-Type: application/json; charset=utf-8');
require_api_auth();

$pdo = getDB();
if (!function_exists('crm_user_can_access_payroll_integration_api') || !crm_user_can_access_payroll_integration_api()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง', 'error_code' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$month = trim($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => 'พารามิเตอร์ month ต้องเป็น YYYY-MM', 'error_code' => 'INVALID_MONTH'], JSON_UNESCAPED_UNICODE);
    exit;
}

// เมื่อมีตาราง payroll_runs จะดึง status (draft/calculated/approved/paid) และ locked หรือไม่
$status = null;
$locked = false;
try {
    $stmt = $pdo->prepare("SELECT id, status FROM payroll_runs WHERE payroll_month = ? LIMIT 1");
    $stmt->execute([$month . '-01']);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($run) {
        $status = $run['status'];
        $locked = in_array($status, ['approved', 'paid']);
    }
} catch (Exception $e) {
    // ตาราง payroll_runs ยังไม่มี
}

echo json_encode([
    'success' => true,
    'month' => $month,
    'status' => $status,
    'locked' => $locked,
    'note' => $status === null ? 'ยังไม่มีรอบเงินเดือนในเดือนนี้ (หรือตาราง payroll_runs ยังไม่ได้สร้าง)' : null
], JSON_UNESCAPED_UNICODE);
