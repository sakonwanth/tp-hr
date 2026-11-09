<?php
/**
 * Payroll API: จัดการรายการ preset (รายได้อื่น / รายการหัก / เบี้ยเลี้ยง)
 * GET              = ดึงรายการทั้งหมด (?category=income|deduction|allowance)
 * POST action=add  = เพิ่มรายการใหม่
 * POST action=edit = แก้ไขรายการ
 * POST action=delete = ลบรายการ (soft: is_active=0)
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../core/LegacyHrUi.php';

header('Content-Type: application/json; charset=utf-8');
require_api_auth(); // session + CSRF on POST/PUT/PATCH/DELETE (SEC-006)

$pdo = getDB();
if (!function_exists('crm_user_can_access_payroll_integration_api') || !crm_user_can_access_payroll_integration_api()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cat = $_GET['category'] ?? '';
    $sql = "SELECT id, category, label, default_amount, sort_order FROM payroll_item_presets WHERE is_active = 1";
    $params = [];
    if (in_array($cat, ['income', 'deduction', 'allowance'])) {
        $sql .= " AND category = ?";
        $params[] = $cat;
    }
    $sql .= " ORDER BY category, sort_order, label";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$act = $input['action'] ?? '';

if ($act === 'add') {
    $category = $input['category'] ?? '';
    $label = trim($input['label'] ?? '');
    $default_amount = isset($input['default_amount']) && $input['default_amount'] !== '' ? (float)$input['default_amount'] : null;
    if (!in_array($category, ['income', 'deduction', 'allowance']) || $label === '') {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุประเภทและชื่อรายการ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM payroll_item_presets WHERE category = ? AND label = ?");
    $stmt->execute([$category, $label]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE payroll_item_presets SET is_active = 1, default_amount = ? WHERE category = ? AND label = ?")->execute([$default_amount, $category, $label]);
    } else {
        $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM payroll_item_presets WHERE category = ?");
        $max->execute([$category]);
        $sort = (int)$max->fetchColumn();
        $pdo->prepare("INSERT INTO payroll_item_presets (category, label, default_amount, sort_order) VALUES (?,?,?,?)")->execute([$category, $label, $default_amount, $sort]);
    }
    echo json_encode(['success' => true, 'message' => 'เพิ่มรายการสำเร็จ'], JSON_UNESCAPED_UNICODE);
} elseif ($act === 'edit') {
    $id = (int)($input['id'] ?? 0);
    $label = trim($input['label'] ?? '');
    $default_amount = isset($input['default_amount']) && $input['default_amount'] !== '' ? (float)$input['default_amount'] : null;
    if ($id <= 0 || $label === '') {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->prepare("UPDATE payroll_item_presets SET label = ?, default_amount = ? WHERE id = ?")->execute([$label, $default_amount, $id]);
    echo json_encode(['success' => true, 'message' => 'แก้ไขสำเร็จ'], JSON_UNESCAPED_UNICODE);
} elseif ($act === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ไม่ระบุ id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->prepare("UPDATE payroll_item_presets SET is_active = 0 WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'ลบสำเร็จ'], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'action ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
}
