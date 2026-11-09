<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Employee Profile — POST handlers
 * Variables available: $pdo, $current_user, $target_user, $is_self, $is_hr_admin
 * Sets: $message, $error
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    log_security_event('csrf_failed', ['page' => 'employee_profile.php', 'user_id' => $current_user['id']]);
    return;
}

$post_action = $_POST['action'] ?? '';

if ($post_action !== 'save_profile') return;

$target_id = (int)($_POST['id'] ?? 0);
if ($target_id !== (int)($target_user['id'] ?? 0)) { http_response_code(403); $error='พนักงานที่เลือกไม่ตรงกับรายการที่เปิด'; return; }
if ($target_id <= 0) { $error = 'ไม่พบข้อมูลพนักงาน'; return; }

// สิทธิ์: เจ้าของโปรไฟล์ หรือ HR admin เท่านั้น
if (!$is_self && !$is_hr_admin) { $error = 'คุณไม่มีสิทธิ์แก้ไขข้อมูลนี้'; return; }

// ===== ฟิลด์ที่เจ้าตัวแก้ได้ =====
$self_fields = [
    'title'               => trim($_POST['title'] ?? '') ?: null,
    'first_name_th'       => trim($_POST['first_name_th'] ?? ''),
    'last_name_th'        => trim($_POST['last_name_th'] ?? '') ?: null,
    'first_name_en'       => trim($_POST['first_name_en'] ?? '') ?: null,
    'last_name_en'        => trim($_POST['last_name_en'] ?? '') ?: null,
    'nickname'            => trim($_POST['nickname'] ?? '') ?: null,
    'email'               => trim($_POST['email'] ?? '') ?: null,
    'phone'               => trim($_POST['phone'] ?? '') ?: null,
    'birth_date'          => trim($_POST['birth_date'] ?? '') ?: null,
    'gender'              => in_array($_POST['gender'] ?? '', ['M','F'], true) ? $_POST['gender'] : null,
    'marital_status'      => in_array($_POST['marital_status'] ?? '', ['SINGLE','MARRIED','WIDOWED','DIVORCED','SEPARATED'], true) ? $_POST['marital_status'] : null,
    'blood_type'          => trim($_POST['blood_type'] ?? '') ?: null,
    'religion'            => trim($_POST['religion'] ?? '') ?: null,
    'military_status'     => in_array($_POST['military_status'] ?? '', ['NONE','EXEMPTED','COMPLETED','NOT_APPLICABLE'], true) ? $_POST['military_status'] : null,
    'id_card'             => trim($_POST['id_card'] ?? '') ?: null,
    'id_card_expiry'      => trim($_POST['id_card_expiry'] ?? '') ?: null,
    'nationality'         => trim($_POST['nationality'] ?? '') ?: null,
    'address'             => trim($_POST['address'] ?? '') ?: null,
    'registered_address'  => trim($_POST['registered_address'] ?? '') ?: null,
    'emergency_contact_name'     => trim($_POST['emergency_contact_name'] ?? '') ?: null,
    'emergency_contact_phone'    => trim($_POST['emergency_contact_phone'] ?? '') ?: null,
    'emergency_contact_relation' => trim($_POST['emergency_contact_relation'] ?? '') ?: null,
];

if ($self_fields['first_name_th'] === '') { $error = 'กรุณากรอกชื่อ'; return; }
// validate id_card: 13 digits (ถ้ากรอก)
if (!empty($self_fields['id_card']) && !preg_match('/^\d{13}$/', $self_fields['id_card'])) {
    $error = 'เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก'; return;
}

// ===== ฟิลด์ที่เฉพาะ HR admin แก้ได้ (employment + payroll) =====
$admin_fields = [];
if ($is_hr_admin) {
    $emp_type = trim($_POST['employment_type'] ?? '');
    $work_mode = trim($_POST['work_mode'] ?? 'OFFICE');
    $admin_fields = [
        'employee_code'               => trim($_POST['employee_code'] ?? '') ?: null,
        'role_id'                     => (int)($_POST['role_id'] ?? ($target_user['role_id'] ?? 1)),
        'department'                  => trim($_POST['department'] ?? '') ?: null,
        'position'                    => trim($_POST['position'] ?? '') ?: null,
        'hire_date'                   => trim($_POST['hire_date'] ?? '') ?: null,
        'employment_type'             => in_array($emp_type, ['PROBATION','PERMANENT','CONTRACT','PARTTIME'], true) ? $emp_type : null,
        'work_mode'                   => in_array($work_mode, ['OFFICE','WFH'], true) ? $work_mode : 'OFFICE',
        'probation_days'              => ($_POST['probation_days'] ?? '') !== '' ? (int)$_POST['probation_days'] : null,
        'probation_end_date'          => trim($_POST['probation_end_date'] ?? '') ?: null,
        'probation_passed_date'       => trim($_POST['probation_passed_date'] ?? '') ?: null,
        'salary'                      => ($_POST['salary'] ?? '') !== '' ? (float)$_POST['salary'] : null,
        'probation_salary'            => ($_POST['probation_salary'] ?? '') !== '' ? (float)$_POST['probation_salary'] : null,
        'social_security_id'          => trim($_POST['social_security_id'] ?? '') ?: null,
        'social_security_start_date'  => trim($_POST['social_security_start_date'] ?? '') ?: null,
        'social_security_hospital'    => trim($_POST['social_security_hospital'] ?? '') ?: null,
        'bank_name'                   => trim($_POST['bank_name'] ?? '') ?: null,
        'bank_account'                => trim($_POST['bank_account'] ?? '') ?: null,
        'has_other_employer_income'   => isset($_POST['has_other_employer_income']) ? 1 : 0,
        'is_active'                   => isset($_POST['is_active']) ? 1 : 0,
    ];
}

$fields = array_merge($self_fields, $admin_fields);

try {
    // กัน employee_code ซ้ำ (กรณี HR เปลี่ยน)
    if ($is_hr_admin && !empty($admin_fields['employee_code'])) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE employee_code = ? AND id <> ?");
        $chk->execute([$admin_fields['employee_code'], $target_id]);
        if ($chk->fetch()) { $error = 'รหัสพนักงานนี้มีอยู่แล้ว'; return; }
    }
    // กัน email ซ้ำ (ถ้ากรอก)
    if (!empty($self_fields['email'])) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $chk->execute([$self_fields['email'], $target_id]);
        if ($chk->fetch()) { $error = 'อีเมลนี้มีผู้ใช้งานแล้ว'; return; }
    }

    $set_parts = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $set_parts[] = "`{$col}` = ?";
        $params[] = $val;
    }
    $params[] = $target_id;

    $sql = "UPDATE users SET " . implode(', ', $set_parts) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $_SESSION['flash_message'] = 'บันทึกข้อมูลโปรไฟล์เรียบร้อย';
    $redirect_id = $target_id;
    header('Location: employee_profile.php' . ($is_self && $redirect_id === (int)$current_user['id'] ? '' : '?id=' . $redirect_id));
    exit;
} catch (Exception $e) {
    $error = tpCrmUserErrorFromException($e, 'modules/employee_profile/actions.php');
}
