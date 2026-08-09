<?php
/**
 * HR Employee Form - Add/Edit Employee
 * จัดการข้อมูลพนักงาน - ใช้ร่วมกับ TP-CRM (ฐานข้อมูลเดียวกัน)
 */

require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

// Must be HR
if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

// Permission flags
$canEditSensitive = canManageUsers(); // CEO+: salary, role, is_active, login ids, national id, SSO, bank (edit)

// Only CEO+ can add new employees
if (!$canEditSensitive && ($_GET['action'] ?? 'add') === 'add') {
    flash('error', 'เฉพาะระดับ CEO ขึ้นไปเท่านั้นที่สามารถเพิ่มพนักงานใหม่ได้');
    redirect('/hr/employees.php', 302);
}

$pdo = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? 'add';
$id = (int)($_GET['id'] ?? 0);
$employee = null;
$errors = [];
$success = '';

// Get roles for dropdown
$roles = $pdo->query("SELECT id, name, display_name FROM roles ORDER BY id")->fetchAll();

// Get departments for dropdown
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND " . tp_hr_non_system_user_condition_sql('') . " ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// Load employee data if editing
if ($action === 'edit' && $id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        flash('error', 'ไม่พบข้อมูลพนักงาน');
        redirect('/hr/employees.php', 302);
    }
    
    // Load day-off schedule
    $stmtSched = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
    $stmtSched->execute([$id]);
    $employeeSchedule = $stmtSched->fetch();
    
    // Load education history
    $stmtEdu = $pdo->prepare("SELECT * FROM hr_employee_education WHERE user_id = ? ORDER BY graduation_year DESC");
    $stmtEdu->execute([$id]);
    $educationRecords = $stmtEdu->fetchAll();
    
    // Load work history
    $stmtWork = $pdo->prepare("SELECT * FROM hr_employee_work_history WHERE user_id = ? ORDER BY start_date DESC");
    $stmtWork->execute([$id]);
    $workHistoryRecords = $stmtWork->fetchAll();
    
    // Load family info
    $stmtFamily = $pdo->prepare("SELECT * FROM hr_employee_family WHERE user_id = ? ORDER BY relationship");
    $stmtFamily->execute([$id]);
    $familyRecords = $stmtFamily->fetchAll();
    
    $page_title = 'แก้ไขข้อมูลพนักงาน';
} else {
    $action = 'add';
    $page_title = 'เพิ่มพนักงานใหม่';
    $employeeSchedule = null;
    $educationRecords = [];
    $workHistoryRecords = [];
    $familyRecords = [];
}

// Handle password change (separate form, exit early)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!verifyCsrf()) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    } elseif (!canManageUsers()) {
        $errors[] = 'เฉพาะผู้บริหารระดับ CEO ขึ้นไปเท่านั้นที่สามารถเปลี่ยนรหัสผ่านพนักงานคนอื่นได้';
    } else {
        $pwUserId = (int)($_POST['employee_id'] ?? 0);
        if ($pwUserId <= 0 || $pwUserId !== $id || $action !== 'edit') {
            $errors[] = 'ข้อมูลผู้ใช้ไม่ถูกต้อง';
        } elseif (in_array($pwUserId, SYSTEM_USER_IDS, true)) {
            $errors[] = 'ไม่สามารถเปลี่ยนรหัสผ่านบัญชีระบบได้';
        } else {
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
                $errors[] = 'รหัสผ่านต้องมีอย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'รหัสผ่านไม่ตรงกัน';
            } else {
                try {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? AND " . tp_hr_non_system_user_condition_sql(''));
                    $stmt->execute([$hashedPassword, $pwUserId]);
                    if ($stmt->rowCount() < 1) {
                        $errors[] = 'ไม่พบพนักงาน หรือไม่สามารถเปลี่ยนรหัสผ่านได้';
                    } else {
                        Auth::log('employee_password_change', 'users', $pwUserId);
                        $success = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
                    }
                } catch (Throwable $e) {
                    tpHrLogException($e, 'hr/employee_form change_password');
                    $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
                }
            }
        }
    }
    // Skip main form handler — this was a password-only POST
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    } else {
        // Collect form data
        $formData = [
            'employee_code' => trim($_POST['employee_code'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'title' => $_POST['title'] ?? 'นาย',
            'first_name_th' => trim($_POST['first_name_th'] ?? ''),
            'last_name_th' => trim($_POST['last_name_th'] ?? ''),
            'first_name_en' => trim($_POST['first_name_en'] ?? ''),
            'last_name_en' => trim($_POST['last_name_en'] ?? ''),
            'nickname' => trim($_POST['nickname'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'birth_date' => $_POST['birth_date'] ?? null,
            'gender' => $_POST['gender'] ?? null,
            'id_card' => trim($_POST['id_card'] ?? ''),
            'id_card_expiry' => $_POST['id_card_expiry'] ?? null,
            'nationality' => trim($_POST['nationality'] ?? 'ไทย'),
            'marital_status' => $_POST['marital_status'] ?? null,
            'blood_type' => trim($_POST['blood_type'] ?? ''),
            'religion' => trim($_POST['religion'] ?? ''),
            'military_status' => $_POST['military_status'] ?? null,
            'address' => trim($_POST['address'] ?? ''),
            'registered_address' => trim($_POST['registered_address'] ?? ''),
            'department' => trim($_POST['department'] ?? ''),
            'position' => trim($_POST['position'] ?? ''),
            'role_id' => (int)($_POST['role_id'] ?? 5),
            'hire_date' => $_POST['hire_date'] ?? null,
            'termination_date' => trim($_POST['termination_date'] ?? '') ?: null,
            'employment_type' => $_POST['employment_type'] ?? 'PROBATION',
            'work_mode' => in_array($_POST['work_mode'] ?? '', ['OFFICE','WFH'], true) ? $_POST['work_mode'] : 'OFFICE',
            'probation_days' => (int)($_POST['probation_days'] ?? (int)getSetting('default_probation_days', 120)),
            'probation_end_date' => $_POST['probation_end_date'] ?? null,
            'probation_passed_date' => $_POST['probation_passed_date'] ?? null,
            'social_security_id' => trim($_POST['social_security_id'] ?? ''),
            'social_security_start_date' => $_POST['social_security_start_date'] ?? null,
            'tax_withholding_start_date' => $_POST['tax_withholding_start_date'] ?? null,
            'health_insurance_start_date' => $_POST['health_insurance_start_date'] ?? null,
            'group_insurance_start_date' => $_POST['group_insurance_start_date'] ?? null,
            'social_security_hospital' => trim($_POST['social_security_hospital'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'bank_account' => trim($_POST['bank_account'] ?? ''),
            'salary' => $_POST['salary'] ?? null,
            'probation_salary' => $_POST['probation_salary'] ?? null,
            'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
            'emergency_contact_phone' => trim($_POST['emergency_contact_phone'] ?? ''),
            'emergency_contact_relation' => trim($_POST['emergency_contact_relation'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        
        // Enforce backend permission: HR cannot change salary, role, is_active
        if (!$canEditSensitive && $action === 'edit' && $employee) {
            $formData['employee_code'] = (string)($employee['employee_code'] ?? '');
            $formData['username'] = (string)($employee['username'] ?? '');
            $formData['email'] = (string)($employee['email'] ?? '');
            $formData['id_card'] = trim((string)($employee['id_card'] ?? ''));
            $formData['id_card_expiry'] = $employee['id_card_expiry'] ?? null;
            $formData['social_security_id'] = trim((string)($employee['social_security_id'] ?? ''));
            $formData['social_security_start_date'] = $employee['social_security_start_date'] ?? null;
            $formData['tax_withholding_start_date'] = $employee['tax_withholding_start_date'] ?? null;
            $formData['health_insurance_start_date'] = $employee['health_insurance_start_date'] ?? null;
            $formData['group_insurance_start_date'] = $employee['group_insurance_start_date'] ?? null;
            $formData['social_security_hospital'] = trim((string)($employee['social_security_hospital'] ?? ''));
            $formData['bank_name'] = trim((string)($employee['bank_name'] ?? ''));
            $formData['bank_account'] = trim((string)($employee['bank_account'] ?? ''));
            $formData['salary'] = $employee['salary'];
            $formData['probation_salary'] = $employee['probation_salary'] ?? null;
            $formData['role_id'] = (int)$employee['role_id'];
            $formData['is_active'] = (int)$employee['is_active'];
        }
        
        // Auto-calculate probation_end_date from hire_date + probation_days
        if (!empty($formData['hire_date']) && $formData['probation_days'] > 0 && empty($formData['probation_end_date'])) {
            $formData['probation_end_date'] = date('Y-m-d', strtotime($formData['hire_date'] . ' + ' . $formData['probation_days'] . ' days'));
        }
        
        // Validation
        if (empty($formData['employee_code'])) {
            $errors[] = 'กรุณากรอกรหัสพนักงาน';
        }
        if (empty($formData['username'])) {
            $errors[] = 'กรุณากรอก Username';
        }
        if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'กรุณากรอกอีเมลที่ถูกต้อง';
        }
        if (empty($formData['first_name_th'])) {
            $errors[] = 'กรุณากรอกชื่อ (ภาษาไทย)';
        }
        if (empty($formData['last_name_th'])) {
            $errors[] = 'กรุณากรอกนามสกุล (ภาษาไทย)';
        }

        if ($formData['termination_date'] !== null) {
            if (!tp_hr_is_month_end_date($formData['termination_date'])) {
                $errors[] = 'วันลาออกต้องเป็นวันสิ้นเดือนปฏิทินเท่านั้น (เช่น 31 พ.ค.) — ระบบคำนวณเงินเดือนรอบสุดท้ายเป็น รอบ 26→25 + วัน 26 ถึงสิ้นเดือน';
            } elseif (!empty($formData['hire_date']) && $formData['termination_date'] < $formData['hire_date']) {
                $errors[] = 'วันลาออกต้องไม่ก่อนวันเริ่มงาน';
            }
        }
        
        // Validate password confirmation for new employee
        if ($action === 'add') {
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';
            if (!empty($passwordConfirm) && $password !== $passwordConfirm) {
                $errors[] = 'รหัสผ่านไม่ตรงกัน';
            }
        }
        
        // Check for duplicate employee_code, username, email
        if (empty($errors)) {
            $checkSql = "SELECT id FROM users WHERE (employee_code = ? OR username = ? OR email = ?)";
            $checkParams = [$formData['employee_code'], $formData['username'], $formData['email']];
            
            if ($action === 'edit') {
                $checkSql .= " AND id != ?";
                $checkParams[] = $id;
            }
            
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute($checkParams);
            if ($checkStmt->fetch()) {
                $errors[] = 'รหัสพนักงาน, Username หรืออีเมล ซ้ำกับพนักงานอื่น';
            }
        }
        
        // Save data
        if (empty($errors)) {
            try {
                if ($action === 'edit') {
                    // Update existing employee
                    $updateSql = "UPDATE users SET 
                        employee_code = ?, username = ?, email = ?,
                        title = ?, first_name_th = ?, last_name_th = ?,
                        first_name_en = ?, last_name_en = ?, nickname = ?,
                        phone = ?, birth_date = ?, gender = ?, 
                        marital_status = ?, blood_type = ?, religion = ?, military_status = ?,
                        id_card = ?, id_card_expiry = ?,
                        nationality = ?, address = ?, registered_address = ?,
                        department = ?, position = ?, role_id = ?,
                        hire_date = ?, termination_date = ?, employment_type = ?,
                        work_mode = ?,
                        probation_days = ?, probation_end_date = ?, probation_passed_date = ?,
                        social_security_id = ?, social_security_start_date = ?, tax_withholding_start_date = ?, health_insurance_start_date = ?, group_insurance_start_date = ?, social_security_hospital = ?,
                        bank_name = ?, bank_account = ?, salary = ?, probation_salary = ?,
                        emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_relation = ?,
                        is_active = ?, updated_at = NOW()
                        WHERE id = ?";
                    
                    $stmt = $pdo->prepare($updateSql);
                    $stmt->execute([
                        $formData['employee_code'], $formData['username'], $formData['email'],
                        $formData['title'], $formData['first_name_th'], $formData['last_name_th'],
                        $formData['first_name_en'], $formData['last_name_en'], $formData['nickname'] ?: null,
                        $formData['phone'] ?: null, $formData['birth_date'] ?: null, $formData['gender'] ?: null,
                        $formData['marital_status'] ?: null, $formData['blood_type'] ?: null, $formData['religion'] ?: null, $formData['military_status'] ?: null,
                        $formData['id_card'] ?: null, $formData['id_card_expiry'] ?: null,
                        $formData['nationality'] ?: null, $formData['address'] ?: null, $formData['registered_address'] ?: null,
                        $formData['department'] ?: null, $formData['position'] ?: null, $formData['role_id'],
                        $formData['hire_date'] ?: null, $formData['termination_date'],
                        $formData['employment_type'],
                        $formData['work_mode'],
                        $formData['probation_days'], $formData['probation_end_date'] ?: null, $formData['probation_passed_date'] ?: null,
                        $formData['social_security_id'] ?: null, $formData['social_security_start_date'] ?: null, $formData['tax_withholding_start_date'] ?: null, $formData['health_insurance_start_date'] ?: null, $formData['group_insurance_start_date'] ?: null, $formData['social_security_hospital'] ?: null,
                        $formData['bank_name'] ?: null, $formData['bank_account'] ?: null, $formData['salary'] ?: null, $formData['probation_salary'] !== null && $formData['probation_salary'] !== '' ? $formData['probation_salary'] : null,
                        $formData['emergency_contact_name'] ?: null, $formData['emergency_contact_phone'] ?: null, $formData['emergency_contact_relation'] ?: null,
                        $formData['is_active'], $id
                    ]);

                    $success = 'บันทึกข้อมูลพนักงานเรียบร้อยแล้ว';
                    
                    $pdo->beginTransaction();
                    try {
                    // Save day-off schedule
                    $dayOff = $_POST['day_off'] ?? '0';
                    $stmtSched = $pdo->prepare("
                        INSERT INTO hr_employee_schedules (user_id, day_off, updated_by)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE day_off = VALUES(day_off), updated_by = VALUES(updated_by)
                    ");
                    $stmtSched->execute([$id, (int)$dayOff, $user['id']]);
                    
                    // Save education records
                    $pdo->prepare("DELETE FROM hr_employee_education WHERE user_id = ?")->execute([$id]);
                    if (!empty($_POST['edu_level'])) {
                        $eduStmt = $pdo->prepare("INSERT INTO hr_employee_education (user_id, level, institution, faculty, major, graduation_year, gpa) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        foreach ($_POST['edu_level'] as $i => $level) {
                            if (empty($level) && empty($_POST['edu_institution'][$i] ?? '')) continue;
                            $eduStmt->execute([
                                $id, $level ?: 'OTHER',
                                trim($_POST['edu_institution'][$i] ?? ''),
                                trim($_POST['edu_faculty'][$i] ?? '') ?: null,
                                trim($_POST['edu_major'][$i] ?? '') ?: null,
                                (int)($_POST['edu_year'][$i] ?? 0) ?: null,
                                ($_POST['edu_gpa'][$i] ?? '') !== '' ? (float)$_POST['edu_gpa'][$i] : null
                            ]);
                        }
                    }
                    
                    // Save work history records
                    $pdo->prepare("DELETE FROM hr_employee_work_history WHERE user_id = ?")->execute([$id]);
                    if (!empty($_POST['wh_company'])) {
                        $whStmt = $pdo->prepare("INSERT INTO hr_employee_work_history (user_id, company_name, position, start_date, end_date, last_salary, responsibilities, reason_for_leaving) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        foreach ($_POST['wh_company'] as $i => $company) {
                            if (empty(trim($company))) continue;
                            $whStmt->execute([
                                $id, trim($company),
                                trim($_POST['wh_position'][$i] ?? '') ?: null,
                                $_POST['wh_start'][$i] ?? null ?: null,
                                $_POST['wh_end'][$i] ?? null ?: null,
                                ($_POST['wh_salary'][$i] ?? '') !== '' ? (float)$_POST['wh_salary'][$i] : null,
                                trim($_POST['wh_responsibilities'][$i] ?? '') ?: null,
                                trim($_POST['wh_reason'][$i] ?? '') ?: null
                            ]);
                        }
                    }
                    
                    // Save family records
                    $pdo->prepare("DELETE FROM hr_employee_family WHERE user_id = ?")->execute([$id]);
                    if (!empty($_POST['fam_name'])) {
                        $famStmt = $pdo->prepare("INSERT INTO hr_employee_family (user_id, relationship, name, id_card_number, birth_date, occupation, phone, is_dependent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        foreach ($_POST['fam_name'] as $i => $name) {
                            if (empty(trim($name))) continue;
                            $famStmt->execute([
                                $id, $_POST['fam_relationship'][$i] ?? 'OTHER',
                                trim($name),
                                trim($_POST['fam_id_card'][$i] ?? '') ?: null,
                                $_POST['fam_birth_date'][$i] ?? null ?: null,
                                trim($_POST['fam_occupation'][$i] ?? '') ?: null,
                                trim($_POST['fam_phone'][$i] ?? '') ?: null,
                                isset($_POST['fam_dependent'][$i]) ? 1 : 0
                            ]);
                        }
                    }
                    
                    $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                    
                    // Reload employee data
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    $employee = $stmt->fetch();
                    
                    // Reload multi-row data
                    $stmtSched = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
                    $stmtSched->execute([$id]);
                    $employeeSchedule = $stmtSched->fetch();
                    $educationRecords = $pdo->prepare("SELECT * FROM hr_employee_education WHERE user_id = ? ORDER BY graduation_year DESC");
                    $educationRecords->execute([$id]);
                    $educationRecords = $educationRecords->fetchAll();
                    $workHistoryRecords = $pdo->prepare("SELECT * FROM hr_employee_work_history WHERE user_id = ? ORDER BY start_date DESC");
                    $workHistoryRecords->execute([$id]);
                    $workHistoryRecords = $workHistoryRecords->fetchAll();
                    $familyRecords = $pdo->prepare("SELECT * FROM hr_employee_family WHERE user_id = ? ORDER BY relationship");
                    $familyRecords->execute([$id]);
                    $familyRecords = $familyRecords->fetchAll();
                    
                } else {
                    // Insert new employee
                    $password = $_POST['password'] ?? '';
                    if (empty($password) || strlen($password) < MIN_PASSWORD_LENGTH) {
                        $errors[] = 'กรุณากรอกรหัสผ่าน (อย่างน้อย ' . MIN_PASSWORD_LENGTH . ' ตัวอักษร)';
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        $insertSql = "INSERT INTO users 
                            (employee_code, username, email, password,
                             title, first_name_th, last_name_th, first_name_en, last_name_en, nickname,
                             phone, birth_date, gender, marital_status, blood_type, religion, military_status,
                             id_card, id_card_expiry, nationality, address, registered_address,
                             department, position, role_id,
                             hire_date, termination_date, employment_type, probation_days, probation_end_date, probation_passed_date,
                             work_mode,
                             social_security_id, social_security_start_date, tax_withholding_start_date, health_insurance_start_date, group_insurance_start_date, social_security_hospital,
                             bank_name, bank_account, salary, probation_salary,
                             emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                             is_active, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        
                        $stmt = $pdo->prepare($insertSql);
                        $stmt->execute([
                            $formData['employee_code'], $formData['username'], $formData['email'], $hashedPassword,
                            $formData['title'], $formData['first_name_th'], $formData['last_name_th'],
                            $formData['first_name_en'], $formData['last_name_en'], $formData['nickname'] ?: null,
                            $formData['phone'] ?: null, $formData['birth_date'] ?: null, $formData['gender'] ?: null,
                            $formData['marital_status'] ?: null, $formData['blood_type'] ?: null, $formData['religion'] ?: null, $formData['military_status'] ?: null,
                            $formData['id_card'] ?: null, $formData['id_card_expiry'] ?: null, $formData['nationality'] ?: null, $formData['address'] ?: null, $formData['registered_address'] ?: null,
                            $formData['department'] ?: null, $formData['position'] ?: null, $formData['role_id'],
                            $formData['hire_date'] ?: null, $formData['termination_date'],
                            $formData['employment_type'],
                            $formData['probation_days'], $formData['probation_end_date'] ?: null, $formData['probation_passed_date'] ?: null,
                            $formData['work_mode'],
                            $formData['social_security_id'] ?: null, $formData['social_security_start_date'] ?: null, $formData['tax_withholding_start_date'] ?: null, $formData['health_insurance_start_date'] ?: null, $formData['group_insurance_start_date'] ?: null, $formData['social_security_hospital'] ?: null,
                            $formData['bank_name'] ?: null, $formData['bank_account'] ?: null, $formData['salary'] ?: null, $formData['probation_salary'] !== null && $formData['probation_salary'] !== '' ? $formData['probation_salary'] : null,
                            $formData['emergency_contact_name'] ?: null, $formData['emergency_contact_phone'] ?: null, $formData['emergency_contact_relation'] ?: null,
                            $formData['is_active']
                        ]);
                        
                        $newId = $pdo->lastInsertId();
                        
                        // Save day-off schedule for new employee
                        $dayOff = $_POST['day_off'] ?? '0';
                        $stmtSched = $pdo->prepare("
                            INSERT INTO hr_employee_schedules (user_id, day_off, updated_by)
                            VALUES (?, ?, ?)
                        ");
                        $stmtSched->execute([$newId, (int)$dayOff, $user['id']]);
                        
                        flash('success', 'เพิ่มพนักงานใหม่เรียบร้อยแล้ว');
                        redirect("/hr/employee_form.php?action=edit&id={$newId}", 302);
                    }
                }
            } catch (Throwable $e) {
                tpHrLogException($e, 'hr/employee_form save');
                $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
            }
        }
        
        // If errors, keep form data
        if (!empty($errors) && $action === 'add') {
            $employee = $formData;
        }
    }
}

$formSubtitle = '';
if ($action === 'edit' && is_array($employee)) {
    $formSubtitle = trim(($employee['title'] ?? '') . ' ' . ($employee['first_name_th'] ?? '') . ' ' . ($employee['last_name_th'] ?? ''));
}

$current_page = 'hr-employees';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/employees.php" class="hover:text-white touch-manipulation">จัดการพนักงาน</a>
        <span class="mx-2">/</span>
        <span class="text-white"><?php echo $action === 'edit' ? 'แก้ไข' : 'เพิ่ม'; ?></span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between gap-y-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php if ($formSubtitle !== ''): ?>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]"><?php echo htmlspecialchars($formSubtitle); ?></p>
            <?php endif; ?>
        </div>
        <a href="/hr/employees.php" class="w-full sm:w-auto shrink-0 inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-medium touch-manipulation">
            <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i>กลับ
        </a>
    </div>
</header>

<!-- Info Box: Shared with CRM -->
<div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0 border-l-4 border-blue-500/80 rounded-[var(--tp-ios-card-radius)]" role="status">
    <div class="flex items-start gap-3">
        <i class="fas fa-info-circle text-blue-400 mt-0.5 shrink-0" aria-hidden="true"></i>
        <div class="min-w-0">
            <p class="text-white font-medium">ระบบเชื่อมต่อกับ TP-CRM</p>
            <p class="text-white/60 text-sm leading-relaxed">ข้อมูลพนักงานใช้ฐานข้อมูลเดียวกับระบบ CRM การแก้ไขที่นี่จะมีผลกับระบบ CRM ด้วย</p>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0 border-l-4 border-red-500/80 rounded-[var(--tp-ios-card-radius)]" role="alert">
    <div class="flex items-start gap-3">
        <i class="fas fa-exclamation-circle text-red-400 mt-0.5 shrink-0" aria-hidden="true"></i>
        <div class="min-w-0">
            <p class="text-white font-medium">เกิดข้อผิดพลาด</p>
            <ul class="text-red-300 text-sm mt-1 list-disc list-inside space-y-0.5">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="native-card tp-native-card tp-native-data-card p-5 mb-6 min-w-0 border-l-4 border-emerald-500/80 rounded-[var(--tp-ios-card-radius)]" role="status">
    <div class="flex items-start gap-3">
        <i class="fas fa-check-circle text-emerald-400 mt-0.5 shrink-0" aria-hidden="true"></i>
        <p class="text-emerald-200"><?php echo htmlspecialchars($success); ?></p>
    </div>
</div>
<?php endif; ?>

<form method="POST" id="employee-main-form" class="space-y-6 min-w-0 max-w-full">
    <?php echo csrfField(); ?>
    
    <!-- Tab Navigation -->
    <div class="native-card tp-native-card tp-native-data-card p-1.5 min-w-0 flex flex-nowrap overflow-x-auto gap-1 sticky top-3 z-30 [-webkit-overflow-scrolling:touch] overscroll-x-contain rounded-[var(--tp-ios-card-radius)]" role="tablist" aria-label="ส่วนของแบบฟอร์มพนักงาน">
        <button type="button" role="tab" aria-selected="true" aria-controls="tab-personal" onclick="switchTab('tab-personal')" id="btn-tab-personal" class="tab-btn active shrink-0 min-h-[48px] px-4 py-2.5 rounded-[var(--tp-ios-card-radius)] text-sm font-medium transition-all flex items-center gap-2 touch-manipulation">
            <i class="fas fa-user" aria-hidden="true"></i>
            <span class="hidden sm:inline">ข้อมูลส่วนตัว</span>
            <span class="sm:hidden">ส่วนตัว</span>
        </button>
        <button type="button" role="tab" aria-selected="false" aria-controls="tab-work" onclick="switchTab('tab-work')" id="btn-tab-work" class="tab-btn shrink-0 min-h-[48px] px-4 py-2.5 rounded-[var(--tp-ios-card-radius)] text-sm font-medium transition-all flex items-center gap-2 touch-manipulation">
            <i class="fas fa-briefcase" aria-hidden="true"></i>
            <span class="hidden sm:inline">ข้อมูลการทำงาน</span>
            <span class="sm:hidden">การทำงาน</span>
        </button>
        <button type="button" role="tab" aria-selected="false" aria-controls="tab-welfare" onclick="switchTab('tab-welfare')" id="btn-tab-welfare" class="tab-btn shrink-0 min-h-[48px] px-4 py-2.5 rounded-[var(--tp-ios-card-radius)] text-sm font-medium transition-all flex items-center gap-2 touch-manipulation">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            <span class="hidden sm:inline">สวัสดิการ & การเงิน</span>
            <span class="sm:hidden">สวัสดิการ</span>
        </button>
        <?php if ($action === 'edit' && $employee): ?>
        <button type="button" role="tab" aria-selected="false" aria-controls="tab-history" onclick="switchTab('tab-history')" id="btn-tab-history" class="tab-btn shrink-0 min-h-[48px] px-4 py-2.5 rounded-[var(--tp-ios-card-radius)] text-sm font-medium transition-all flex items-center gap-2 touch-manipulation">
            <i class="fas fa-history" aria-hidden="true"></i>
            <span class="hidden sm:inline">ประวัติ & ครอบครัว</span>
            <span class="sm:hidden">ประวัติ</span>
        </button>
        <button type="button" role="tab" aria-selected="false" aria-controls="tab-system" onclick="switchTab('tab-system')" id="btn-tab-system" class="tab-btn shrink-0 min-h-[48px] px-4 py-2.5 rounded-[var(--tp-ios-card-radius)] text-sm font-medium transition-all flex items-center gap-2 touch-manipulation">
            <i class="fas fa-cog" aria-hidden="true"></i>
            <span class="hidden sm:inline">ระบบ</span>
        </button>
        <?php endif; ?>
    </div>
    
    <!-- Tab 1: ข้อมูลส่วนตัว -->
    <div id="tab-personal" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="btn-tab-personal">
    
    <!-- Basic Info -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-user text-violet-400 mr-2"></i>
            ข้อมูลพื้นฐาน
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">รหัสพนักงาน <span class="text-red-400">*</span></label>
                <?php if ($action === 'edit' && !$canEditSensitive): ?>
                <input type="hidden" name="employee_code" value="<?php echo htmlspecialchars($employee['employee_code'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['employee_code'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly required>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php else: ?>
                <input type="text" name="employee_code"
                       value="<?php echo htmlspecialchars($employee['employee_code'] ?? ''); ?>"
                       class="input-field tp-native-input" required placeholder="เช่น TPE01001">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">Username <span class="text-red-400">*</span></label>
                <?php if ($action === 'edit' && !$canEditSensitive): ?>
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($employee['username'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['username'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly required>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php else: ?>
                <input type="text" name="username"
                       value="<?php echo htmlspecialchars($employee['username'] ?? ''); ?>"
                       class="input-field tp-native-input" required placeholder="ใช้สำหรับล็อกอิน">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">อีเมล <span class="text-red-400">*</span></label>
                <?php if ($action === 'edit' && !$canEditSensitive): ?>
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>">
                <input type="email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly required>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php else: ?>
                <input type="email" name="email"
                       value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>"
                       class="input-field tp-native-input" required placeholder="email@tp-asset.com">
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($action === 'add'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">รหัสผ่าน <span class="text-red-400">*</span></label>
                <input type="password" name="password" class="input-field tp-native-input" required minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" placeholder="อย่างน้อย <?php echo MIN_PASSWORD_LENGTH; ?> ตัวอักษร">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ยืนยันรหัสผ่าน</label>
                <input type="password" name="password_confirm" class="input-field tp-native-input" placeholder="กรอกรหัสผ่านอีกครั้ง">
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Personal Info -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-id-card text-violet-400 mr-2"></i>
            ข้อมูลส่วนตัว
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">คำนำหน้า</label>
                <select name="title" class="input-field tp-native-select">
                    <option value="นาย" <?php echo ($employee['title'] ?? '') === 'นาย' ? 'selected' : ''; ?>>นาย</option>
                    <option value="นาง" <?php echo ($employee['title'] ?? '') === 'นาง' ? 'selected' : ''; ?>>นาง</option>
                    <option value="นางสาว" <?php echo ($employee['title'] ?? '') === 'นางสาว' ? 'selected' : ''; ?>>นางสาว</option>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ชื่อ (ไทย) <span class="text-red-400">*</span></label>
                <input type="text" name="first_name_th" 
                       value="<?php echo htmlspecialchars($employee['first_name_th'] ?? ''); ?>"
                       class="input-field tp-native-input" required>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">นามสกุล (ไทย) <span class="text-red-400">*</span></label>
                <input type="text" name="last_name_th" 
                       value="<?php echo htmlspecialchars($employee['last_name_th'] ?? ''); ?>"
                       class="input-field tp-native-input" required>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ชื่อเล่น</label>
                <input type="text" name="nickname" 
                       value="<?php echo htmlspecialchars($employee['nickname'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="เช่น นุ่น">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">เพศ</label>
                <select name="gender" class="input-field tp-native-select">
                    <option value="">-- เลือก --</option>
                    <option value="M" <?php echo ($employee['gender'] ?? '') === 'M' ? 'selected' : ''; ?>>ชาย</option>
                    <option value="F" <?php echo ($employee['gender'] ?? '') === 'F' ? 'selected' : ''; ?>>หญิง</option>
                </select>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">First Name (English)</label>
                <input type="text" name="first_name_en" 
                       value="<?php echo htmlspecialchars($employee['first_name_en'] ?? ''); ?>"
                       class="input-field tp-native-input">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">Last Name (English)</label>
                <input type="text" name="last_name_en" 
                       value="<?php echo htmlspecialchars($employee['last_name_en'] ?? ''); ?>"
                       class="input-field tp-native-input">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">สัญชาติ</label>
                <input type="text" name="nationality" 
                       value="<?php echo htmlspecialchars($employee['nationality'] ?? 'ไทย'); ?>"
                       class="input-field tp-native-input">
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">เลขบัตรประชาชน</label>
                <?php if ($action === 'edit' && !$canEditSensitive): ?>
                <input type="hidden" name="id_card" value="<?php echo htmlspecialchars($employee['id_card'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['id_card'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly maxlength="13">
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php else: ?>
                <input type="text" name="id_card" maxlength="13"
                       value="<?php echo htmlspecialchars($employee['id_card'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="1234567890123">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันบัตรหมดอายุ</label>
                <?php if ($action === 'edit' && !$canEditSensitive): ?>
                <input type="hidden" name="id_card_expiry" value="<?php echo htmlspecialchars($employee['id_card_expiry'] ?? ''); ?>">
                <input type="date" value="<?php echo htmlspecialchars($employee['id_card_expiry'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>CEO+</p>
                <?php else: ?>
                <input type="date" name="id_card_expiry"
                       value="<?php echo htmlspecialchars($employee['id_card_expiry'] ?? ''); ?>"
                       class="input-field tp-native-input">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันเกิด</label>
                <input type="date" name="birth_date" id="birth_date_input"
                       value="<?php echo htmlspecialchars($employee['birth_date'] ?? ''); ?>"
                       class="input-field tp-native-input" onchange="calcAge()">
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" 
                       value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="081-234-5678">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">สถานะสมรส</label>
                <select name="marital_status" class="input-field tp-native-select">
                    <option value="">-- เลือก --</option>
                    <?php 
                    $maritalOptions = ['SINGLE' => 'โสด', 'MARRIED' => 'สมรส', 'WIDOWED' => 'หม้าย', 'DIVORCED' => 'หย่า', 'SEPARATED' => 'แยกกันอยู่'];
                    foreach ($maritalOptions as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo ($employee['marital_status'] ?? '') === $val ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">กรุ๊ปเลือด</label>
                <select name="blood_type" class="input-field tp-native-select">
                    <option value="">-- เลือก --</option>
                    <?php foreach (['A', 'B', 'AB', 'O'] as $bt): ?>
                    <option value="<?php echo $bt; ?>" <?php echo ($employee['blood_type'] ?? '') === $bt ? 'selected' : ''; ?>>
                        <?php echo $bt; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ศาสนา</label>
                <select name="religion" class="input-field tp-native-select">
                    <option value="">-- เลือก --</option>
                    <?php 
                    $religions = ['พุทธ', 'อิสลาม', 'คริสต์', 'ฮินดู', 'ซิกข์', 'อื่นๆ'];
                    foreach ($religions as $r): ?>
                    <option value="<?php echo $r; ?>" <?php echo ($employee['religion'] ?? '') === $r ? 'selected' : ''; ?>>
                        <?php echo $r; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <?php if (($employee['gender'] ?? '') === 'M'): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">สถานะทางทหาร</label>
                <select name="military_status" class="input-field tp-native-select">
                    <option value="">-- เลือก --</option>
                    <?php 
                    $militaryOptions = ['COMPLETED' => 'ผ่านการเกณฑ์ (สด.43)', 'EXEMPTED' => 'ได้รับการยกเว้น (สด.8/สด.9)', 'NONE' => 'ยังไม่ได้เกณฑ์', 'NOT_APPLICABLE' => 'ไม่เกี่ยวข้อง'];
                    foreach ($militaryOptions as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo ($employee['military_status'] ?? '') === $val ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        
        <?php
        // Calculate age
        $ageDisplay = '';
        if (!empty($employee['birth_date'])) {
            $birthDate = new DateTime($employee['birth_date']);
            $now = new DateTime();
            $age = $now->diff($birthDate);
            $ageDisplay = $age->y . ' ปี ' . $age->m . ' เดือน';
        }
        ?>
        <?php if ($ageDisplay): ?>
        <div class="mt-4 flex items-center gap-4">
            <span class="inline-flex items-center px-3 py-1 rounded-full bg-violet-500/20 text-violet-300 text-sm">
                <i class="fas fa-birthday-cake mr-2"></i>อายุ: <?php echo $ageDisplay; ?>
            </span>
        </div>
        <?php endif; ?>
        
        <div class="mt-4">
            <label class="block text-white/70 text-sm mb-1">ที่อยู่ปัจจุบัน</label>
            <textarea name="address" rows="2" class="input-field tp-native-textarea" placeholder="บ้านเลขที่ ซอย ถนน แขวง/ตำบล เขต/อำเภอ จังหวัด"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
        </div>
        
        <div class="mt-4">
            <label class="block text-white/70 text-sm mb-1">ที่อยู่ตามทะเบียนบ้าน</label>
            <textarea name="registered_address" rows="2" class="input-field tp-native-textarea" placeholder="หากเหมือนที่อยู่ปัจจุบัน ไม่ต้องกรอก"><?php echo htmlspecialchars($employee['registered_address'] ?? ''); ?></textarea>
        </div>
    </div>
    
    </div><!-- /tab-personal -->
    
    <!-- Tab 2: ข้อมูลการทำงาน -->
    <div id="tab-work" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="btn-tab-work" style="display:none">
    
    <!-- Work Info -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-briefcase text-violet-400 mr-2"></i>
            ข้อมูลการทำงาน
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">แผนก</label>
                <input type="text" name="department" list="department-list"
                       value="<?php echo htmlspecialchars($employee['department'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="เลือกหรือพิมพ์แผนก">
                <datalist id="department-list">
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ตำแหน่ง</label>
                <input type="text" name="position" 
                       value="<?php echo htmlspecialchars($employee['position'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="เช่น โปรแกรมเมอร์">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">บทบาท (Role)</label>
                <?php if ($canEditSensitive): ?>
                <select name="role_id" class="input-field tp-native-select">
                    <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>" 
                            <?php echo ($employee['role_id'] ?? 5) == $role['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($role['display_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="hidden" name="role_id" value="<?php echo (int)($employee['role_id'] ?? 5); ?>">
                <input type="text" value="<?php 
                    $roleName = '-';
                    foreach ($roles as $r) { if ($r['id'] == ($employee['role_id'] ?? 5)) { $roleName = $r['display_name']; break; } }
                    echo htmlspecialchars($roleName);
                ?>" class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">วันที่เริ่มงาน</label>
                <input type="date" name="hire_date" id="hire_date"
                       value="<?php echo htmlspecialchars($employee['hire_date'] ?? ''); ?>"
                       class="input-field tp-native-input" onchange="calcProbationEnd()">
                <?php
                // Calculate work tenure
                if (!empty($employee['hire_date'])) {
                    $hireDate = new DateTime($employee['hire_date']);
                    $now = new DateTime();
                    $tenure = $now->diff($hireDate);
                    $tenureText = $tenure->y . ' ปี ' . $tenure->m . ' เดือน ' . $tenure->d . ' วัน';
                    echo '<p class="mt-1"><span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-xs"><i class="fas fa-business-time mr-1"></i>อายุงาน: ' . $tenureText . '</span></p>';
                }
                ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ประเภทการจ้าง</label>
                <select name="employment_type" id="employment_type" class="input-field tp-native-select" onchange="toggleProbation()">
                    <option value="PROBATION" <?php echo ($employee['employment_type'] ?? 'PROBATION') === 'PROBATION' ? 'selected' : ''; ?>>ทดลองงาน (Probation)</option>
                    <option value="PERMANENT" <?php echo ($employee['employment_type'] ?? '') === 'PERMANENT' ? 'selected' : ''; ?>>พนักงานประจำ</option>
                    <option value="CONTRACT" <?php echo ($employee['employment_type'] ?? '') === 'CONTRACT' ? 'selected' : ''; ?>>สัญญาจ้าง</option>
                    <option value="PARTTIME" <?php echo ($employee['employment_type'] ?? '') === 'PARTTIME' ? 'selected' : ''; ?>>พาร์ทไทม์</option>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">
                    <i class="fas fa-laptop-house text-blue-400 mr-1"></i>รูปแบบการทำงาน
                </label>
                <select name="work_mode" class="input-field tp-native-select">
                    <option value="OFFICE" <?php echo (($employee['work_mode'] ?? 'OFFICE') === 'OFFICE') ? 'selected' : ''; ?>>ทำงานที่ออฟฟิศ (Office)</option>
                    <option value="WFH" <?php echo (($employee['work_mode'] ?? '') === 'WFH') ? 'selected' : ''; ?>>ทำงานที่บ้าน (WFH) — ระบบแสตมป์อัตโนมัติ</option>
                </select>
                <p class="text-white/40 text-xs mt-1">พนักงาน WFH ไม่ต้องลงเวลา ระบบจะแสตมป์สถานะ WFH ให้อัตโนมัติในวันทำงาน</p>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">
                    <i class="fas fa-door-open text-rose-400 mr-1"></i>วันลาออก (สิ้นเดือน)
                </label>
                <input type="date" name="termination_date" id="termination_date"
                       value="<?php echo htmlspecialchars($employee['termination_date'] ?? ''); ?>"
                       class="input-field tp-native-input" onchange="validateTerminationDate()" onblur="validateTerminationDate()">
                <p id="termination_date_hint" class="text-white/40 text-xs mt-1">ต้องเป็นวันสิ้นเดือนปฏิทินเท่านั้น — ใช้คำนวณเงินเดือนรอบสุดท้าย (รอบ 26→25 + วัน 26 ถึงสิ้นเดือน)</p>
                <p id="termination_date_error" class="text-rose-400 text-xs mt-1 hidden"></p>
            </div>
            <div class="flex items-center pt-6">
                <?php if ($canEditSensitive): ?>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" 
                           <?php echo ($employee['is_active'] ?? 1) ? 'checked' : ''; ?>
                           class="w-5 h-5 rounded border-white/20 bg-white/10 text-violet-500 focus:ring-violet-500">
                    <span class="ml-3 text-white">สถานะทำงาน (Active)</span>
                </label>
                <?php else: ?>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm <?php echo ($employee['is_active'] ?? 1) ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                        <i class="fas fa-circle text-xs mr-2"></i>
                        <?php echo ($employee['is_active'] ?? 1) ? 'Active' : 'Inactive'; ?>
                    </span>
                    <span class="text-white/40 text-xs"><i class="fas fa-lock mr-1"></i>CEO+</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Day Off Schedule -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <?php 
            $dayOptions = [
                0 => 'วันอาทิตย์', 1 => 'วันจันทร์', 2 => 'วันอังคาร', 
                3 => 'วันพุธ', 4 => 'วันพฤหัสบดี', 5 => 'วันศุกร์', 6 => 'วันเสาร์'
            ];
            $schedDayOff = $employeeSchedule['day_off'] ?? 0;
            ?>
            <div>
                <label class="block text-white/70 text-sm mb-1">
                    <i class="fas fa-calendar-minus text-blue-400 mr-1"></i>วันหยุดประจำสัปดาห์ (ค่าเริ่มต้น)
                </label>
                <select name="day_off" class="input-field tp-native-select">
                    <?php foreach ($dayOptions as $val => $label): ?>
                    <option value="<?php echo $val; ?>" <?php echo (int)$schedDayOff === $val ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-white/40 text-xs mt-1">พนักงานสามารถขอเปลี่ยนวันหยุดรายสัปดาห์ได้ผ่านหน้าวันหยุดประจำสัปดาห์</p>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">
                    <i class="fas fa-money-bill-wave text-green-400 mr-1"></i>เงินเดือน หลังผ่านโปร (บาท)
                </label>
                <?php if ($canEditSensitive): ?>
                <input type="number" name="salary" step="0.01" min="0"
                       value="<?php echo htmlspecialchars($employee['salary'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="0.00">
                <p class="text-white/40 text-xs mt-1">ใช้ตั้งแต่ "วันผ่านโปร (ยืนยันจริง)" เป็นต้นไป</p>
                <?php else: ?>
                <input type="text" value="<?php echo ($employee['salary'] ?? '') ? number_format((float)$employee['salary'], 2) : '-'; ?>" 
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">
                    <i class="fas fa-hourglass-half text-yellow-400 mr-1"></i>เงินเดือน ช่วงทดลองงาน (บาท)
                </label>
                <?php if ($canEditSensitive): ?>
                <input type="number" name="probation_salary" step="0.01" min="0"
                       value="<?php echo htmlspecialchars($employee['probation_salary'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="เว้นว่าง = ใช้เงินเดือนหลักช่วงทดลองงานด้วย">
                <p class="text-white/40 text-xs mt-1">ระบบจะใช้อัตรานี้ตราบที่ยังไม่มี "วันผ่านโปร (ยืนยันจริง)"</p>
                <?php else: ?>
                <input type="text" value="<?php echo ($employee['probation_salary'] ?? '') ? number_format((float)$employee['probation_salary'], 2) : '-'; ?>" 
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>เฉพาะ CEO+ เท่านั้นที่แก้ไขได้</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Probation Section -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0" id="probation-section">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-user-clock text-amber-400 mr-2"></i>
            ระยะทดลองงาน (Probation)
        </h3>
        
        <?php
        $probationDays = (int)($employee['probation_days'] ?? (int)getSetting('default_probation_days', 120));
        $probationEndDate = $employee['probation_end_date'] ?? '';
        $probationPassedDate = $employee['probation_passed_date'] ?? '';
        $isProbation = ($employee['employment_type'] ?? 'PROBATION') === 'PROBATION';
        
        // Calculate status
        $probationStatus = '';
        $probationStatusClass = '';
        if ($probationPassedDate) {
            $probationStatus = 'ผ่านโปร: ' . formatDateThai($probationPassedDate);
            $probationStatusClass = 'bg-green-500/20 text-green-400 border-green-500/30';
        } elseif ($probationEndDate && $isProbation) {
            $remainDays = (int)((strtotime($probationEndDate) - time()) / 86400);
            if ($remainDays < 0) {
                $probationStatus = 'ครบกำหนดโปรแล้ว (' . abs($remainDays) . ' วันที่แล้ว) — รอยืนยัน';
                $probationStatusClass = 'bg-red-500/20 text-red-400 border-red-500/30';
            } else {
                $probationStatus = 'เหลืออีก ' . $remainDays . ' วัน (ครบ ' . formatDateThai($probationEndDate) . ')';
                $probationStatusClass = 'bg-amber-500/20 text-amber-400 border-amber-500/30';
            }
        }
        ?>
        
        <?php if ($probationStatus): ?>
        <div class="rounded-[var(--tp-ios-card-radius)] p-3 mb-4 border <?php echo $probationStatusClass; ?>">
            <i class="fas fa-info-circle mr-2"></i><?php echo $probationStatus; ?>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">ระยะทดลองงาน (วัน)</label>
                <input type="number" name="probation_days" id="probation_days" min="0" max="365"
                       value="<?php echo $probationDays; ?>"
                       class="input-field tp-native-input" onchange="calcProbationEnd()">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันครบกำหนดโปร</label>
                <input type="date" name="probation_end_date" id="probation_end_date"
                       value="<?php echo htmlspecialchars($probationEndDate); ?>"
                       class="input-field tp-native-input">
                <p class="text-white/40 text-xs mt-1">คำนวณอัตโนมัติจากวันเริ่มงาน + จำนวนวัน</p>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันที่ผ่านโปร (ยืนยันจริง)</label>
                <input type="date" name="probation_passed_date" id="probation_passed_date"
                       value="<?php echo htmlspecialchars($probationPassedDate); ?>"
                       class="input-field tp-native-input" onchange="onProbationPassed()">
                <p class="text-white/40 text-xs mt-1">เมื่อกรอกวันนี้ = ยืนยันผ่านโปร → เปลี่ยนเป็นพนักงานประจำ</p>
            </div>
        </div>
        
        <div class="rounded-[var(--tp-ios-card-radius)] p-3 mt-4 bg-blue-500/10 border border-blue-500/25">
            <p class="text-blue-300 text-sm leading-relaxed">
                <i class="fas fa-lightbulb mr-2 text-amber-300" aria-hidden="true"></i>
                วันเริ่มหักประกันสังคมกำหนดแยกจากวันผ่านโปร — HR ระบุเองว่าจะเริ่มหักเดือนใด
            </p>
        </div>
    </div>
    
    </div><!-- /tab-work -->
    
    <!-- Tab 3: สวัสดิการ & การเงิน -->
    <div id="tab-welfare" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="btn-tab-welfare" style="display:none">
    
    <!-- Social Security & Banking -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-shield-alt text-cyan-400 mr-2"></i>
            ประกันสังคม & บัญชีธนาคาร
        </h3>
        
        <?php
        $banks = ['กสิกรไทย', 'ไทยพาณิชย์', 'กรุงเทพ', 'กรุงไทย', 'ทหารไทยธนชาต', 'กรุงศรีอยุธยา', 'ออมสิน', 'ธ.ก.ส.', 'ซีไอเอ็มบี', 'ยูโอบี', 'แลนด์ แอนด์ เฮ้าส์', 'เกียรตินาคินภัทร', 'ทิสโก้', 'อื่นๆ'];
        $lockWelfare = ($action === 'edit' && !$canEditSensitive);
        ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">เลขทะเบียนประกันสังคม</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="social_security_id" value="<?php echo htmlspecialchars($employee['social_security_id'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['social_security_id'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>CEO+</p>
                <?php else: ?>
                <input type="text" name="social_security_id"
                       value="<?php echo htmlspecialchars($employee['social_security_id'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="เลขที่ประกันสังคม">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันเริ่มหักประกันสังคม</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="social_security_start_date" value="<?php echo htmlspecialchars($employee['social_security_start_date'] ?? ''); ?>">
                <input type="date" value="<?php echo htmlspecialchars($employee['social_security_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1">CEO+</p>
                <?php else: ?>
                <input type="date" name="social_security_start_date"
                       value="<?php echo htmlspecialchars($employee['social_security_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input">
                <?php endif; ?>
                <?php if (!$lockWelfare): ?>
                <p class="text-white/40 text-xs mt-1">ปกติจะเริ่มหักหลังผ่านโปร — กำหนดวันเอง (ไม่ผูกอัตโนมัติจากวันผ่านโปร)</p>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันเริ่มหักภาษี ณ ที่จ่าย</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="tax_withholding_start_date" value="<?php echo htmlspecialchars($employee['tax_withholding_start_date'] ?? ''); ?>">
                <input type="date" value="<?php echo htmlspecialchars($employee['tax_withholding_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <?php else: ?>
                <input type="date" name="tax_withholding_start_date"
                       value="<?php echo htmlspecialchars($employee['tax_withholding_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input">
                <?php endif; ?>
                <?php if (!$lockWelfare): ?>
                <p class="text-white/40 text-xs mt-1">ว่างไว้ = หักทันทีเมื่อเปิดใช้ภาษีที่บริษัท</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">วันเริ่มหักประกันกลุ่ม</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="group_insurance_start_date" value="<?php echo htmlspecialchars($employee['group_insurance_start_date'] ?? ''); ?>">
                <input type="date" value="<?php echo htmlspecialchars($employee['group_insurance_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <?php else: ?>
                <input type="date" name="group_insurance_start_date"
                       value="<?php echo htmlspecialchars($employee['group_insurance_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input">
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">วันเริ่มหักประกันสุขภาพ</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="health_insurance_start_date" value="<?php echo htmlspecialchars($employee['health_insurance_start_date'] ?? ''); ?>">
                <input type="date" value="<?php echo htmlspecialchars($employee['health_insurance_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <?php else: ?>
                <input type="date" name="health_insurance_start_date"
                       value="<?php echo htmlspecialchars($employee['health_insurance_start_date'] ?? ''); ?>"
                       class="input-field tp-native-input">
                <?php endif; ?>
                <?php if (!$lockWelfare): ?>
                <p class="text-white/40 text-xs mt-1">ว่างไว้ = ยังไม่หักจนกว่าจะระบุ</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">โรงพยาบาลประกันสังคม</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="social_security_hospital" value="<?php echo htmlspecialchars($employee['social_security_hospital'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['social_security_hospital'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>CEO+</p>
                <?php else: ?>
                <input type="text" name="social_security_hospital"
                       value="<?php echo htmlspecialchars($employee['social_security_hospital'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="ชื่อโรงพยาบาล">
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">ธนาคาร</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="bank_name" value="<?php echo htmlspecialchars($employee['bank_name'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['bank_name'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>CEO+</p>
                <?php else: ?>
                <select name="bank_name" class="input-field tp-native-select">
                    <option value="">-- เลือกธนาคาร --</option>
                    <?php foreach ($banks as $bank): ?>
                    <option value="<?php echo $bank; ?>" <?php echo ($employee['bank_name'] ?? '') === $bank ? 'selected' : ''; ?>>
                        <?php echo $bank; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">เลขบัญชีธนาคาร</label>
                <?php if ($lockWelfare): ?>
                <input type="hidden" name="bank_account" value="<?php echo htmlspecialchars($employee['bank_account'] ?? ''); ?>">
                <input type="text" value="<?php echo htmlspecialchars($employee['bank_account'] ?? ''); ?>"
                       class="input-field tp-native-input bg-white/5 cursor-not-allowed" readonly>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-lock mr-1"></i>CEO+</p>
                <?php else: ?>
                <input type="text" name="bank_account"
                       value="<?php echo htmlspecialchars($employee['bank_account'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="xxx-x-xxxxx-x">
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Emergency Contact -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-phone-alt text-rose-400 mr-2"></i>
            ผู้ติดต่อกรณีฉุกเฉิน
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">ชื่อ-นามสกุล</label>
                <input type="text" name="emergency_contact_name"
                       value="<?php echo htmlspecialchars($employee['emergency_contact_name'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="ชื่อผู้ติดต่อ">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">เบอร์โทรศัพท์</label>
                <input type="tel" name="emergency_contact_phone"
                       value="<?php echo htmlspecialchars($employee['emergency_contact_phone'] ?? ''); ?>"
                       class="input-field tp-native-input" placeholder="081-234-5678">
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ความสัมพันธ์</label>
                <select name="emergency_contact_relation" class="input-field tp-native-select">
                    <option value="">-- เลือก --</option>
                    <?php 
                    $relations = ['บิดา', 'มารดา', 'คู่สมรส', 'บุตร', 'พี่น้อง', 'ญาติ', 'เพื่อน', 'อื่นๆ'];
                    foreach ($relations as $rel): ?>
                    <option value="<?php echo $rel; ?>" <?php echo ($employee['emergency_contact_relation'] ?? '') === $rel ? 'selected' : ''; ?>>
                        <?php echo $rel; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    
    </div><!-- /tab-welfare -->
    
    <?php if ($action === 'edit' && $employee): ?>
    <!-- Tab 4: ประวัติ & ครอบครัว -->
    <div id="tab-history" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="btn-tab-history" style="display:none">
    
    <!-- Education History -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center justify-between text-white text-base sm:text-lg gap-3 flex-wrap">
            <span class="flex items-center">
                <i class="fas fa-graduation-cap text-indigo-400 mr-2"></i>
                ประวัติการศึกษา
            </span>
            <button type="button" onclick="addEducationRow()" class="min-h-[48px] px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold shrink-0">
                <i class="fas fa-plus mr-1" aria-hidden="true"></i>เพิ่ม
            </button>
        </h3>
        
        <div id="education-rows">
            <?php if (!empty($educationRecords)): ?>
                <?php foreach ($educationRecords as $i => $edu): ?>
                <div class="education-row border border-white/10 rounded-[var(--tp-ios-card-radius)] p-5 mb-3" data-index="<?php echo $i; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <span class="text-white/50 text-sm">ลำดับที่ <?php echo $i + 1; ?></span>
                        <button type="button" onclick="this.closest('.education-row').remove()" class="text-red-400 hover:text-red-300 text-sm min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation">
                            <i class="fas fa-trash mr-1"></i>ลบ
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-white/70 text-xs mb-1">ระดับการศึกษา</label>
                            <select name="edu_level[]" class="input-field tp-native-select text-sm">
                                <?php 
                                $eduLevels = ['PRIMARY' => 'ประถมศึกษา', 'SECONDARY' => 'มัธยมต้น', 'HIGH_SCHOOL' => 'มัธยมปลาย/ปวช.', 'DIPLOMA' => 'ปวส./อนุปริญญา', 'BACHELOR' => 'ปริญญาตรี', 'MASTER' => 'ปริญญาโท', 'DOCTORATE' => 'ปริญญาเอก', 'OTHER' => 'อื่นๆ'];
                                foreach ($eduLevels as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($edu['level'] ?? '') === $val ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">สถาบัน</label>
                            <input type="text" name="edu_institution[]" value="<?php echo htmlspecialchars($edu['institution'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="ชื่อสถาบัน">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">คณะ</label>
                            <input type="text" name="edu_faculty[]" value="<?php echo htmlspecialchars($edu['faculty'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="คณะ">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">สาขาวิชา</label>
                            <input type="text" name="edu_major[]" value="<?php echo htmlspecialchars($edu['major'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="สาขา">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">ปีที่จบ (พ.ศ.)</label>
                            <input type="number" name="edu_year[]" value="<?php echo $edu['graduation_year'] ?? ''; ?>" class="input-field tp-native-input text-sm" placeholder="2567">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">เกรดเฉลี่ย</label>
                            <input type="number" name="edu_gpa[]" step="0.01" min="0" max="4" value="<?php echo $edu['gpa'] ?? ''; ?>" class="input-field tp-native-input text-sm" placeholder="3.50">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (empty($educationRecords)): ?>
        <p class="text-white/40 text-sm text-center py-4" id="edu-empty-msg">
            <i class="fas fa-info-circle mr-1"></i>ยังไม่มีข้อมูลการศึกษา — กดปุ่ม "เพิ่ม" เพื่อเพิ่มประวัติ
        </p>
        <?php endif; ?>
    </div>
    
    <!-- Work History -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center justify-between text-white text-base sm:text-lg gap-3 flex-wrap">
            <span class="flex items-center">
                <i class="fas fa-history text-orange-400 mr-2"></i>
                ประวัติการทำงาน (ก่อนเข้าบริษัท)
            </span>
            <button type="button" onclick="addWorkHistoryRow()" class="min-h-[48px] px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold shrink-0">
                <i class="fas fa-plus mr-1" aria-hidden="true"></i>เพิ่ม
            </button>
        </h3>
        
        <div id="work-history-rows">
            <?php if (!empty($workHistoryRecords)): ?>
                <?php foreach ($workHistoryRecords as $i => $wh): ?>
                <div class="wh-row border border-white/10 rounded-[var(--tp-ios-card-radius)] p-5 mb-3" data-index="<?php echo $i; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <span class="text-white/50 text-sm">ลำดับที่ <?php echo $i + 1; ?></span>
                        <button type="button" onclick="this.closest('.wh-row').remove()" class="text-red-400 hover:text-red-300 text-sm min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation">
                            <i class="fas fa-trash mr-1"></i>ลบ
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-white/70 text-xs mb-1">ชื่อบริษัท</label>
                            <input type="text" name="wh_company[]" value="<?php echo htmlspecialchars($wh['company_name'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="ชื่อบริษัท">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">ตำแหน่ง</label>
                            <input type="text" name="wh_position[]" value="<?php echo htmlspecialchars($wh['position'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="ตำแหน่ง">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">เงินเดือนสุดท้าย</label>
                            <input type="number" name="wh_salary[]" step="0.01" min="0" value="<?php echo $wh['last_salary'] ?? ''; ?>" class="input-field tp-native-input text-sm" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">วันที่เริ่ม</label>
                            <input type="date" name="wh_start[]" value="<?php echo $wh['start_date'] ?? ''; ?>" class="input-field tp-native-input text-sm">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">วันที่สิ้นสุด</label>
                            <input type="date" name="wh_end[]" value="<?php echo $wh['end_date'] ?? ''; ?>" class="input-field tp-native-input text-sm">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">สาเหตุที่ออก</label>
                            <input type="text" name="wh_reason[]" value="<?php echo htmlspecialchars($wh['reason_for_leaving'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="สาเหตุ">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="block text-white/70 text-xs mb-1">ลักษณะงาน/หน้าที่</label>
                        <input type="text" name="wh_responsibilities[]" value="<?php echo htmlspecialchars($wh['responsibilities'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="อธิบายลักษณะงาน">
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (empty($workHistoryRecords)): ?>
        <p class="text-white/40 text-sm text-center py-4" id="wh-empty-msg">
            <i class="fas fa-info-circle mr-1"></i>ยังไม่มีประวัติการทำงาน — กดปุ่ม "เพิ่ม" เพื่อเพิ่มประวัติ
        </p>
        <?php endif; ?>
    </div>
    
    <!-- Family Info -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center justify-between text-white text-base sm:text-lg gap-3 flex-wrap">
            <span class="flex items-center">
                <i class="fas fa-users text-pink-400 mr-2"></i>
                ข้อมูลครอบครัว
            </span>
            <button type="button" onclick="addFamilyRow()" class="min-h-[48px] px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold shrink-0">
                <i class="fas fa-plus mr-1" aria-hidden="true"></i>เพิ่ม
            </button>
        </h3>
        
        <div id="family-rows">
            <?php if (!empty($familyRecords)): ?>
                <?php foreach ($familyRecords as $i => $fam): ?>
                <div class="fam-row border border-white/10 rounded-[var(--tp-ios-card-radius)] p-5 mb-3" data-index="<?php echo $i; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <span class="text-white/50 text-sm">ลำดับที่ <?php echo $i + 1; ?></span>
                        <button type="button" onclick="this.closest('.fam-row').remove()" class="text-red-400 hover:text-red-300 text-sm min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation">
                            <i class="fas fa-trash mr-1"></i>ลบ
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-white/70 text-xs mb-1">ความสัมพันธ์</label>
                            <select name="fam_relationship[]" class="input-field tp-native-select text-sm">
                                <?php 
                                $famRels = ['FATHER' => 'บิดา', 'MOTHER' => 'มารดา', 'SPOUSE' => 'คู่สมรส', 'CHILD' => 'บุตร', 'SIBLING' => 'พี่น้อง', 'OTHER' => 'อื่นๆ'];
                                foreach ($famRels as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($fam['relationship'] ?? '') === $val ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">ชื่อ-นามสกุล</label>
                            <input type="text" name="fam_name[]" value="<?php echo htmlspecialchars($fam['name'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="ชื่อ-นามสกุล">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">อาชีพ</label>
                            <input type="text" name="fam_occupation[]" value="<?php echo htmlspecialchars($fam['occupation'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="อาชีพ">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">เบอร์โทร</label>
                            <input type="tel" name="fam_phone[]" value="<?php echo htmlspecialchars($fam['phone'] ?? ''); ?>" class="input-field tp-native-input text-sm" placeholder="081-234-5678">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">เลขบัตรประชาชน</label>
                            <input type="text" name="fam_id_card[]" maxlength="13" value="<?php echo htmlspecialchars($fam['id_card_number'] ?? ''); ?>" class="input-field tp-native-input text-sm">
                        </div>
                        <div>
                            <label class="block text-white/70 text-xs mb-1">วันเกิด</label>
                            <input type="date" name="fam_birth_date[]" value="<?php echo $fam['birth_date'] ?? ''; ?>" class="input-field tp-native-input text-sm">
                        </div>
                        <div class="flex items-end pb-1">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="fam_dependent[<?php echo $i; ?>]" value="1"
                                       <?php echo ($fam['is_dependent'] ?? 0) ? 'checked' : ''; ?>
                                       class="w-4 h-4 rounded border-white/20 bg-white/10 text-violet-500">
                                <span class="ml-2 text-white text-sm">ผู้อยู่ในอุปการะ (ลดหย่อนภาษี)</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (empty($familyRecords)): ?>
        <p class="text-white/40 text-sm text-center py-4" id="fam-empty-msg">
            <i class="fas fa-info-circle mr-1"></i>ยังไม่มีข้อมูลครอบครัว — กดปุ่ม "เพิ่ม" เพื่อเพิ่มข้อมูล
        </p>
        <?php endif; ?>
    </div>
    
    </div><!-- /tab-history -->
    
    <!-- Tab 5: ระบบ -->
    <div id="tab-system" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="btn-tab-system" style="display:none">
    
    <!-- LINE Info (Read-only) -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fab fa-line text-green-400 mr-2"></i>
            ข้อมูล LINE (เชื่อมต่อจาก CRM)
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">LINE User ID</label>
                <input type="text" value="<?php echo htmlspecialchars($employee['line_user_id'] ?? '-'); ?>" 
                       class="input-field tp-native-input bg-white/5" readonly>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">LINE Display Name</label>
                <div class="flex items-center gap-3">
                    <?php if (!empty($employee['line_picture_url'])): ?>
                    <img src="<?php echo htmlspecialchars($employee['line_picture_url']); ?>" 
                         class="w-10 h-10 rounded-full object-cover">
                    <?php endif; ?>
                    <input type="text" value="<?php echo htmlspecialchars($employee['line_display_name'] ?? '-'); ?>" 
                           class="input-field tp-native-input bg-white/5 flex-1" readonly>
                </div>
            </div>
        </div>
        <p class="text-white/40 text-xs mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            การเชื่อมต่อ LINE ต้องทำผ่านระบบ CRM ที่ Settings > LINE Finance
        </p>
    </div>
    
    <!-- System Info -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0">
        <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
            <i class="fas fa-server text-violet-400 mr-2"></i>
            ข้อมูลระบบ
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <p class="text-white/50">ID</p>
                <p class="text-white"><?php echo $employee['id']; ?></p>
            </div>
            <div>
                <p class="text-white/50">สร้างเมื่อ</p>
                <p class="text-white"><?php echo formatDateThai($employee['created_at'], true); ?></p>
            </div>
            <div>
                <p class="text-white/50">แก้ไขล่าสุด</p>
                <p class="text-white"><?php echo formatDateThai($employee['updated_at'], true); ?></p>
            </div>
            <div>
                <p class="text-white/50">เข้าสู่ระบบล่าสุด</p>
                <p class="text-white"><?php echo $employee['last_login'] ? formatDateThai($employee['last_login'], true) : '-'; ?></p>
            </div>
            <div>
                <p class="text-white/50">IP ล่าสุด</p>
                <p class="text-white"><?php echo htmlspecialchars($employee['last_login_ip'] ?? '-'); ?></p>
            </div>
        </div>
    </div>
    
    </div><!-- /tab-system -->
    <?php endif; ?>
    
    <!-- Submit Buttons -->
    <div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 min-w-0 sticky bottom-[calc(72px+env(safe-area-inset-bottom))] md:bottom-4 z-30 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3 rounded-[var(--tp-ios-card-radius)]">
        <a href="/hr/employees.php" class="w-full sm:w-auto min-h-[48px] px-6 py-3 inline-flex items-center justify-center bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-medium touch-manipulation">
            ยกเลิก
        </a>
        <button type="submit" class="w-full sm:w-auto min-h-[56px] px-6 py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-semibold touch-manipulation">
            <i class="fas fa-save mr-2" aria-hidden="true"></i>
            <?php echo $action === 'edit' ? 'บันทึกการแก้ไข' : 'เพิ่มพนักงาน'; ?>
        </button>
    </div>
</form>

<?php if ($action === 'edit' && $employee && $canEditSensitive): ?>
<!-- Change Password Section (CEO+ only — same gate as server-side handler) -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mt-6 min-w-0">
    <h3 class="section-title mb-4 flex items-center text-white text-base sm:text-lg">
        <i class="fas fa-key text-amber-400 mr-2 text-xl" aria-hidden="true"></i>
        เปลี่ยนรหัสผ่าน
    </h3>
    
    <form method="POST" action="/hr/employee_form.php?action=edit&amp;id=<?php echo (int)$id; ?>" class="space-y-4">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="employee_id" value="<?php echo (int)$id; ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="tp-native-form-group mb-0">
                <label for="emp-new-password" class="text-white/70 text-sm font-medium">รหัสผ่านใหม่</label>
                <input type="password" id="emp-new-password" name="new_password" class="input-field tp-native-input w-full" required minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" placeholder="อย่างน้อย <?php echo MIN_PASSWORD_LENGTH; ?> ตัวอักษร" autocomplete="new-password">
            </div>
            <div class="tp-native-form-group mb-0">
                <label for="emp-confirm-password" class="text-white/70 text-sm font-medium">ยืนยันรหัสผ่านใหม่</label>
                <input type="password" id="emp-confirm-password" name="confirm_password" class="input-field tp-native-input w-full" required placeholder="กรอกรหัสผ่านอีกครั้ง" autocomplete="new-password">
            </div>
        </div>
        
        <button type="submit" class="w-full sm:w-auto min-h-[56px] px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-semibold touch-manipulation">
            <i class="fas fa-key mr-2" aria-hidden="true"></i>เปลี่ยนรหัสผ่าน
        </button>
    </form>
</div>
<?php endif; ?>

<style>
.tab-btn { color: rgba(255,255,255,0.55); background: transparent; border-radius: 20px; }
.tab-btn:hover { color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.06); }
.tab-btn.active { color: white; background: rgba(183, 145, 104,0.35); box-shadow: 0 0 0 1px rgba(216, 196, 173,0.45); }
</style>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-panel').forEach(p => { p.style.display = 'none'; });
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    const panel = document.getElementById(tabId);
    const btn = document.getElementById('btn-' + tabId);
    if (panel) panel.style.display = '';
    if (btn) {
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
    }
    sessionStorage.setItem('emp_active_tab', tabId);
}

// Restore tab on page load
document.addEventListener('DOMContentLoaded', function() {
    const saved = sessionStorage.getItem('emp_active_tab');
    if (saved && document.getElementById(saved)) {
        switchTab(saved);
    }
});

function calcProbationEnd() {
    const hireDate = document.getElementById('hire_date').value;
    const days = parseInt(document.getElementById('probation_days').value) || 0;
    if (hireDate && days > 0) {
        const d = new Date(hireDate);
        d.setDate(d.getDate() + days);
        document.getElementById('probation_end_date').value = d.toISOString().split('T')[0];
    }
}

function validateTerminationDate() {
    const el = document.getElementById('termination_date');
    const err = document.getElementById('termination_date_error');
    if (!el || !err) return true;
    const value = (el.value || '').trim();
    if (!value) {
        err.classList.add('hidden');
        err.textContent = '';
        el.classList.remove('ring-2', 'ring-rose-500');
        return true;
    }
    const parts = value.split('-');
    if (parts.length !== 3) return true;
    const year = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10) - 1;
    const day = parseInt(parts[2], 10);
    const lastDay = new Date(year, month + 1, 0).getDate();
    if (day !== lastDay) {
        err.textContent = 'วันลาออกต้องเป็นสิ้นเดือน (เช่น ' + lastDay + '/' + (month + 1) + '/' + year + ')';
        err.classList.remove('hidden');
        el.classList.add('ring-2', 'ring-rose-500');
        return false;
    }
    err.classList.add('hidden');
    err.textContent = '';
    el.classList.remove('ring-2', 'ring-rose-500');
    return true;
}

document.getElementById('employee-main-form')?.addEventListener('submit', function(e) {
    if (!validateTerminationDate()) {
        e.preventDefault();
        document.getElementById('termination_date')?.focus();
    }
});

function onProbationPassed() {
    const passedDate = document.getElementById('probation_passed_date').value;
    if (passedDate) {
        document.getElementById('employment_type').value = 'PERMANENT';
    }
}

function toggleProbation() {
    const type = document.getElementById('employment_type').value;
    const section = document.getElementById('probation-section');
    if (section) {
        section.style.opacity = type === 'PROBATION' ? '1' : '0.5';
    }
}

function calcAge() {
    const bd = document.getElementById('birth_date_input');
    if (!bd || !bd.value) return;
    const birth = new Date(bd.value);
    const now = new Date();
    let years = now.getFullYear() - birth.getFullYear();
    let months = now.getMonth() - birth.getMonth();
    if (months < 0) { years--; months += 12; }
    if (now.getDate() < birth.getDate()) { months--; if (months < 0) { years--; months += 12; } }
    // Update or create age badge
    let badge = document.getElementById('age-badge');
    if (!badge) {
        badge = document.createElement('span');
        badge.id = 'age-badge';
        badge.className = 'inline-flex items-center px-3 py-1 rounded-full bg-violet-500/20 text-violet-300 text-sm mt-2';
        bd.parentElement.appendChild(badge);
    }
    badge.innerHTML = '<i class="fas fa-birthday-cake mr-2"></i>อายุ: ' + years + ' ปี ' + months + ' เดือน';
}

let eduIndex = <?php echo isset($educationRecords) ? count($educationRecords) : 0; ?>;
function addEducationRow() {
    const emptyMsg = document.getElementById('edu-empty-msg');
    if (emptyMsg) emptyMsg.remove();
    const container = document.getElementById('education-rows');
    const idx = eduIndex++;
    const html = `
    <div class="education-row border border-white/10 rounded-[var(--tp-ios-card-radius)] p-5 mb-3">
        <div class="flex justify-between items-start mb-3">
            <span class="text-white/50 text-sm">ใหม่</span>
            <button type="button" onclick="this.closest('.education-row').remove()" class="text-red-400 hover:text-red-300 text-sm min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation">
                <i class="fas fa-trash mr-1"></i>ลบ
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
                <label class="block text-white/70 text-xs mb-1">ระดับการศึกษา</label>
                <select name="edu_level[]" class="input-field tp-native-select text-sm">
                    <option value="PRIMARY">ประถมศึกษา</option>
                    <option value="SECONDARY">มัธยมต้น</option>
                    <option value="HIGH_SCHOOL">มัธยมปลาย/ปวช.</option>
                    <option value="DIPLOMA">ปวส./อนุปริญญา</option>
                    <option value="BACHELOR" selected>ปริญญาตรี</option>
                    <option value="MASTER">ปริญญาโท</option>
                    <option value="DOCTORATE">ปริญญาเอก</option>
                    <option value="OTHER">อื่นๆ</option>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">สถาบัน</label>
                <input type="text" name="edu_institution[]" class="input-field tp-native-input text-sm" placeholder="ชื่อสถาบัน">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">คณะ</label>
                <input type="text" name="edu_faculty[]" class="input-field tp-native-input text-sm" placeholder="คณะ">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">สาขาวิชา</label>
                <input type="text" name="edu_major[]" class="input-field tp-native-input text-sm" placeholder="สาขา">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ปีที่จบ (พ.ศ.)</label>
                <input type="number" name="edu_year[]" class="input-field tp-native-input text-sm" placeholder="2567">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เกรดเฉลี่ย</label>
                <input type="number" name="edu_gpa[]" step="0.01" min="0" max="4" class="input-field tp-native-input text-sm" placeholder="3.50">
            </div>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
}

let whIndex = <?php echo isset($workHistoryRecords) ? count($workHistoryRecords) : 0; ?>;
function addWorkHistoryRow() {
    const emptyMsg = document.getElementById('wh-empty-msg');
    if (emptyMsg) emptyMsg.remove();
    const container = document.getElementById('work-history-rows');
    const html = `
    <div class="wh-row border border-white/10 rounded-[var(--tp-ios-card-radius)] p-5 mb-3">
        <div class="flex justify-between items-start mb-3">
            <span class="text-white/50 text-sm">ใหม่</span>
            <button type="button" onclick="this.closest('.wh-row').remove()" class="text-red-400 hover:text-red-300 text-sm min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation">
                <i class="fas fa-trash mr-1"></i>ลบ
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
                <label class="block text-white/70 text-xs mb-1">ชื่อบริษัท</label>
                <input type="text" name="wh_company[]" class="input-field tp-native-input text-sm" placeholder="ชื่อบริษัท">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ตำแหน่ง</label>
                <input type="text" name="wh_position[]" class="input-field tp-native-input text-sm" placeholder="ตำแหน่ง">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เงินเดือนสุดท้าย</label>
                <input type="number" name="wh_salary[]" step="0.01" min="0" class="input-field tp-native-input text-sm" placeholder="0.00">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">วันที่เริ่ม</label>
                <input type="date" name="wh_start[]" class="input-field tp-native-input text-sm">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">วันที่สิ้นสุด</label>
                <input type="date" name="wh_end[]" class="input-field tp-native-input text-sm">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">สาเหตุที่ออก</label>
                <input type="text" name="wh_reason[]" class="input-field tp-native-input text-sm" placeholder="สาเหตุ">
            </div>
        </div>
        <div class="mt-3">
            <label class="block text-white/70 text-xs mb-1">ลักษณะงาน/หน้าที่</label>
            <input type="text" name="wh_responsibilities[]" class="input-field tp-native-input text-sm" placeholder="อธิบายลักษณะงาน">
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
}

let famIndex = <?php echo isset($familyRecords) ? count($familyRecords) : 0; ?>;
function addFamilyRow() {
    const emptyMsg = document.getElementById('fam-empty-msg');
    if (emptyMsg) emptyMsg.remove();
    const container = document.getElementById('family-rows');
    const idx = famIndex++;
    const html = `
    <div class="fam-row border border-white/10 rounded-[var(--tp-ios-card-radius)] p-5 mb-3">
        <div class="flex justify-between items-start mb-3">
            <span class="text-white/50 text-sm">ใหม่</span>
            <button type="button" onclick="this.closest('.fam-row').remove()" class="text-red-400 hover:text-red-300 text-sm min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation">
                <i class="fas fa-trash mr-1"></i>ลบ
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-white/70 text-xs mb-1">ความสัมพันธ์</label>
                <select name="fam_relationship[]" class="input-field tp-native-select text-sm">
                    <option value="FATHER">บิดา</option>
                    <option value="MOTHER">มารดา</option>
                    <option value="SPOUSE">คู่สมรส</option>
                    <option value="CHILD">บุตร</option>
                    <option value="SIBLING">พี่น้อง</option>
                    <option value="OTHER">อื่นๆ</option>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ชื่อ-นามสกุล</label>
                <input type="text" name="fam_name[]" class="input-field tp-native-input text-sm" placeholder="ชื่อ-นามสกุล">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">อาชีพ</label>
                <input type="text" name="fam_occupation[]" class="input-field tp-native-input text-sm" placeholder="อาชีพ">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เบอร์โทร</label>
                <input type="tel" name="fam_phone[]" class="input-field tp-native-input text-sm" placeholder="081-234-5678">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เลขบัตรประชาชน</label>
                <input type="text" name="fam_id_card[]" maxlength="13" class="input-field tp-native-input text-sm">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">วันเกิด</label>
                <input type="date" name="fam_birth_date[]" class="input-field tp-native-input text-sm">
            </div>
            <div class="flex items-end pb-1">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="fam_dependent[${idx}]" value="1"
                           class="w-4 h-4 rounded border-white/20 bg-white/10 text-violet-500">
                    <span class="ml-2 text-white text-sm">ผู้อยู่ในอุปการะ (ลดหย่อนภาษี)</span>
                </label>
            </div>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
}

document.addEventListener('DOMContentLoaded', toggleProbation);
</script>

</div>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
