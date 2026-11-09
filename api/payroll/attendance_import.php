<?php
/**
 * Payroll API: นำเข้าข้อมูลสรุปการทำงานจากระบบบันทึกเวลา
 * POST JSON: { "payroll_month": "YYYY-MM", "records": [ { "user_id": 1 หรือ "employee_code": "E001", "work_days": 22, "absent_days": 0, "leave_days": 0, "ot_hours": 5.5, "remark": "" } ] }
 * สิทธิ์: HR, Admin, Chairman, CEO
 * อนาคต: เมื่อมีตาราง attendance / payroll_slips จะเขียนข้อมูลลง DB และใช้คำนวณเงินเดือน
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../core/LegacyHrUi.php';

header('Content-Type: application/json; charset=utf-8');
require_api_auth(); // session + CSRF on POST (SEC-006)

$pdo = getDB();
if (!function_exists('crm_user_can_access_payroll_integration_api') || !crm_user_can_access_payroll_integration_api()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง', 'error_code' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะ POST', 'error_code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'รูปแบบ JSON ไม่ถูกต้อง', 'error_code' => 'INVALID_JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payroll_month = isset($body['payroll_month']) ? trim($body['payroll_month']) : '';
if (!preg_match('/^\d{4}-\d{2}$/', $payroll_month)) {
    echo json_encode(['success' => false, 'message' => 'payroll_month ต้องเป็น YYYY-MM', 'error_code' => 'INVALID_MONTH'], JSON_UNESCAPED_UNICODE);
    exit;
}

$records = $body['records'] ?? [];
if (!is_array($records)) {
    echo json_encode(['success' => false, 'message' => 'records ต้องเป็น array', 'error_code' => 'INVALID_RECORDS'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ตรวจรูปแบบแต่ละ record (user_id หรือ employee_code, work_days, absent_days, leave_days, ot_hours, remark)
$validated = [];
foreach ($records as $i => $row) {
    $user_id_key = isset($row['user_id']) ? 'user_id' : (isset($row['employee_code']) ? 'employee_code' : null);
    if (!$user_id_key) {
        echo json_encode(['success' => false, 'message' => "record[$i] ต้องมี user_id หรือ employee_code", 'error_code' => 'MISSING_USER'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $validated[] = [
        'user_id' => (int)($row['user_id'] ?? 0),
        'employee_code' => trim($row['employee_code'] ?? ''),
        'work_days' => (float)($row['work_days'] ?? 0),
        'absent_days' => (float)($row['absent_days'] ?? 0),
        'leave_days' => (float)($row['leave_days'] ?? 0),
        'ot_hours' => (float)($row['ot_hours'] ?? 0),
        'remark' => trim($row['remark'] ?? ''),
    ];
}

// แก้ user_id จาก employee_code ถ้ามีแค่รหัสพนักงาน
$user_ids_by_code = [];
if (!empty($validated)) {
    $codes = array_unique(array_filter(array_column($validated, 'employee_code')));
    if (!empty($codes)) {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare("SELECT id, employee_code FROM users WHERE employee_code IN ($placeholders) AND is_active = 1");
        $stmt->execute(array_values($codes));
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_ids_by_code[$r['employee_code']] = (int)$r['id'];
        }
    }
}

$month_first = $payroll_month . '-01';
$stmt = $pdo->prepare("SELECT id FROM payroll_runs WHERE payroll_month = ?");
$stmt->execute([$month_first]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);
$run_id = $run ? (int)$run['id'] : 0;

$updated = 0;
$skipped = 0;
if ($run_id > 0) {
    $upd = $pdo->prepare("UPDATE payroll_slips SET attendance_summary_json = ? WHERE payroll_run_id = ? AND user_id = ?");
    foreach ($validated as $rec) {
        $uid = $rec['user_id'];
        if ($uid <= 0 && $rec['employee_code'] !== '') {
            $uid = $user_ids_by_code[$rec['employee_code']] ?? 0;
        }
        if ($uid <= 0) {
            $skipped++;
            continue;
        }
        $summary = [
            'work_days' => $rec['work_days'],
            'absent_days' => $rec['absent_days'],
            'leave_days' => $rec['leave_days'],
            'ot_hours' => $rec['ot_hours'],
            'remark' => $rec['remark'],
        ];
        $upd->execute([json_encode($summary, JSON_UNESCAPED_UNICODE), $run_id, $uid]);
        if ($upd->rowCount()) {
            $updated++;
        }
    }
}

$accepted = count($validated);
echo json_encode([
    'success' => true,
    'message' => $run_id > 0 ? 'รับข้อมูลและอัปเดตสรุปการทำงานลงสลิปแล้ว' : 'รับข้อมูลแล้ว (ยังไม่มีรอบเงินเดือนของเดือนนี้ จึงยังไม่เขียนลงสลิป)',
    'payroll_month' => $payroll_month,
    'records_accepted' => $accepted,
    'records_updated' => $updated,
    'records_skipped' => $skipped,
    'payroll_run_id' => $run_id ?: null,
], JSON_UNESCAPED_UNICODE);
