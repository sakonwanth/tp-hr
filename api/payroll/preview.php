<?php
/**
 * Payroll slip preview API — uses canonical payroll_calculate_slip_for_user().
 * POST: user_id, month (YYYY-MM), optional draft salary fields (same as save_salary_setup).
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../core/LegacyHrUi.php';

header('Content-Type: application/json; charset=utf-8');
require_api_auth();

$pdo = getDB();
if (!function_exists('crm_user_can_access_payroll_integration_api') || !crm_user_can_access_payroll_integration_api()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
$month = trim((string)($_POST['month'] ?? $_POST['effective_from'] ?? ''));
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ไม่ระบุพนักงาน'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'เดือนไม่ถูกต้อง (YYYY-MM)'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once HR_OWNER_PATH . '/modules/payroll/queries.php';
require_once HR_OWNER_PATH . '/core/Services/PayrollUiService.php';

$override = payroll_build_setup_override_from_input($_POST);
$payDay = payroll_get_default_pay_day();
if (isset($_POST['pay_day'])) {
    $pd = (int)$_POST['pay_day'];
    if ($pd >= 1 && $pd <= 31) {
        $payDay = $pd;
    }
} elseif (!empty($_POST['run_id'])) {
    $runId = (int)$_POST['run_id'];
    if ($runId > 0) {
        $runStmt = $pdo->prepare('SELECT pay_day FROM payroll_runs WHERE id = ? LIMIT 1');
        $runStmt->execute([$runId]);
        $runPayDay = $runStmt->fetchColumn();
        if ($runPayDay !== false && $runPayDay !== null) {
            $payDay = (int)$runPayDay;
        }
    }
}

try {
    $preview = payroll_service()->calculatePreview($userId, $month, $override, $payDay);
    echo json_encode([
        'success' => true,
        'data' => [
            'total_income' => (float)($preview['total_income'] ?? 0),
            'tax_base' => (float)($preview['tax_base'] ?? 0),
            'extra_tax' => (float)($preview['extra_tax'] ?? 0),
            'tax_withheld' => (float)($preview['tax_withheld'] ?? 0),
            'social_security' => (float)($preview['social_security'] ?? 0),
            'provident_fund' => (float)($preview['provident_fund'] ?? 0),
            'group_insurance' => (float)($preview['group_insurance'] ?? 0),
            'health_insurance' => (float)($preview['health_insurance'] ?? 0),
            'deduction_other' => (float)($preview['deduction_other'] ?? 0),
            'absence_deduction' => (float)($preview['absence_deduction'] ?? 0),
            'lateness_deduction' => (float)($preview['lateness_deduction'] ?? 0),
            'total_deductions' => (float)($preview['total_deductions'] ?? 0),
            'net_salary' => (float)($preview['net_salary'] ?? 0),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'คำนวณตัวอย่างไม่สำเร็จ',
    ], JSON_UNESCAPED_UNICODE);
}
