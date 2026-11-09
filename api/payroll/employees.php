<?php
/**
 * Payroll API: รายชื่อพนักงาน
 * สำหรับให้ระบบบันทึกเวลาภายนอกใช้แมป user_id / employee_code
 * GET ?search=... (optional)
 * สิทธิ์: HR, Admin, Chairman, CEO เท่านั้น
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

$search = trim($_GET['search'] ?? '');
$limit = min((int)($_GET['limit'] ?? 500), 1000);

$sql = "SELECT u.id, u.employee_code, u.first_name_th, u.last_name_th, u.department, u.position 
        FROM users u 
        WHERE u.is_active = 1";
$params = [];
if ($search !== '') {
    $sql .= " AND (u.employee_code LIKE ? OR u.first_name_th LIKE ? OR u.last_name_th LIKE ?)";
    $q = "%{$search}%";
    $params = [$q, $q, $q];
}
$sql .= " ORDER BY u.employee_code ASC LIMIT ?";
$params[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'data' => $rows,
    'count' => count($rows)
], JSON_UNESCAPED_UNICODE);
