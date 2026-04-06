<?php
/**
 * Profile API
 * API สำหรับจัดการข้อมูลส่วนตัว
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    // Verify CSRF token for POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }
    
    switch ($action) {
        // Contact info
        case 'update_contact':
            updateContact($pdo, $user);
            break;
            
        // Emergency contacts
        case 'add_emergency':
            addEmergencyContact($pdo, $user);
            break;
        case 'update_emergency':
            updateEmergencyContact($pdo, $user);
            break;
        case 'delete_emergency':
            deleteEmergencyContact($pdo, $user);
            break;
            
        // Family members
        case 'add_family':
            addFamilyMember($pdo, $user);
            break;
        case 'update_family':
            updateFamilyMember($pdo, $user);
            break;
        case 'delete_family':
            deleteFamilyMember($pdo, $user);
            break;
            
        // Education
        case 'add_education':
            addEducation($pdo, $user);
            break;
        case 'update_education':
            updateEducation($pdo, $user);
            break;
        case 'delete_education':
            deleteEducation($pdo, $user);
            break;
            
        // Work history
        case 'add_work':
            addWorkHistory($pdo, $user);
            break;
        case 'update_work':
            updateWorkHistory($pdo, $user);
            break;
        case 'delete_work':
            deleteWorkHistory($pdo, $user);
            break;
            
        // Get data
        case 'get_profile':
            getProfile($pdo, $user);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Profile API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด']);
}

/**
 * Get full profile
 */
function getProfile($pdo, $user) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    
    // Remove sensitive data
    unset($profile['password']);
    
    echo json_encode(['success' => true, 'profile' => $profile]);
}

/**
 * Update contact info
 */
function updateContact($pdo, $user) {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if ($email === false && !empty($_POST['email'])) {
        echo json_encode(['success' => false, 'error' => 'อีเมลไม่ถูกต้อง']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE users SET email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$email ?: null, $phone, $address, $user['id']]);
    
    Auth::log('profile_update', 'users', $user['id']);
    
    echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลสำเร็จ']);
}

/**
 * Add emergency contact
 */
function addEmergencyContact($pdo, $user) {
    $name = trim($_POST['name'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $isPrimary = isset($_POST['is_primary']) && $_POST['is_primary'] == '1';
    
    if (!$name || !$relationship || !$phone) {
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // If setting as primary, unset other primaries
    if ($isPrimary) {
        $stmt = $pdo->prepare("UPDATE hr_emergency_contacts SET is_primary = 0 WHERE user_id = ?");
        $stmt->execute([$user['id']]);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO hr_emergency_contacts (user_id, name, relationship, phone, is_primary, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $name, $relationship, $phone, $isPrimary ? 1 : 0]);
    
    Auth::log('emergency_contact_add', 'hr_emergency_contacts', $pdo->lastInsertId());
    
    echo json_encode(['success' => true, 'message' => 'เพิ่มผู้ติดต่อฉุกเฉินสำเร็จ']);
}

/**
 * Update emergency contact
 */
function updateEmergencyContact($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $relationship = trim($_POST['relationship'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $isPrimary = isset($_POST['is_primary']) && $_POST['is_primary'] == '1';
    
    // Verify ownership
    $stmt = $pdo->prepare("SELECT id FROM hr_emergency_contacts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    if ($isPrimary) {
        $stmt = $pdo->prepare("UPDATE hr_emergency_contacts SET is_primary = 0 WHERE user_id = ? AND id != ?");
        $stmt->execute([$user['id'], $id]);
    }
    
    $stmt = $pdo->prepare("UPDATE hr_emergency_contacts SET name = ?, relationship = ?, phone = ?, is_primary = ? WHERE id = ?");
    $stmt->execute([$name, $relationship, $phone, $isPrimary ? 1 : 0, $id]);
    
    echo json_encode(['success' => true, 'message' => 'อัปเดตข้อมูลสำเร็จ']);
}

/**
 * Delete emergency contact
 */
function deleteEmergencyContact($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    
    $stmt = $pdo->prepare("DELETE FROM hr_emergency_contacts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    
    if ($stmt->rowCount() > 0) {
        Auth::log('emergency_contact_delete', 'hr_emergency_contacts', $id);
        echo json_encode(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
    }
}

/**
 * Add family member
 */
function addFamilyMember($pdo, $user) {
    $relationship = trim($_POST['relationship'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $birthDate = trim($_POST['birth_date'] ?? '') ?: null;
    $occupation = trim($_POST['occupation'] ?? '');
    
    if (!$relationship || !$name) {
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO hr_employee_family (user_id, relationship, name, birth_date, occupation, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $relationship, $name, $birthDate, $occupation]);
    
    Auth::log('family_add', 'hr_family_members', $pdo->lastInsertId());
    
    echo json_encode(['success' => true, 'message' => 'เพิ่มข้อมูลครอบครัวสำเร็จ']);
}

/**
 * Update family member
 */
function updateFamilyMember($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    $relationship = trim($_POST['relationship'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $birthDate = trim($_POST['birth_date'] ?? '') ?: null;
    $occupation = trim($_POST['occupation'] ?? '');
    
    $stmt = $pdo->prepare("SELECT id FROM hr_employee_family WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE hr_employee_family SET relationship = ?, name = ?, birth_date = ?, occupation = ? WHERE id = ?");
    $stmt->execute([$relationship, $name, $birthDate, $occupation, $id]);
    
    echo json_encode(['success' => true, 'message' => 'อัปเดตข้อมูลสำเร็จ']);
}

/**
 * Delete family member
 */
function deleteFamilyMember($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    
    $stmt = $pdo->prepare("DELETE FROM hr_employee_family WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    
    if ($stmt->rowCount() > 0) {
        Auth::log('family_delete', 'hr_family_members', $id);
        echo json_encode(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
    }
}

/**
 * Add education
 */
function addEducation($pdo, $user) {
    $degree = trim($_POST['degree'] ?? '');
    $institution = trim($_POST['institution'] ?? '');
    $fieldOfStudy = trim($_POST['field_of_study'] ?? '');
    $graduationYear = (int)($_POST['graduation_year'] ?? 0) ?: null;
    $gpa = floatval($_POST['gpa'] ?? 0) ?: null;
    
    // Convert Thai year to AD if > 2400
    if ($graduationYear && $graduationYear > 2400) {
        $graduationYear -= 543;
    }
    
    if (!$degree || !$institution) {
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO hr_employee_education (user_id, degree, institution, field_of_study, graduation_year, gpa, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $degree, $institution, $fieldOfStudy, $graduationYear, $gpa]);
    
    Auth::log('education_add', 'hr_education_history', $pdo->lastInsertId());
    
    echo json_encode(['success' => true, 'message' => 'เพิ่มประวัติการศึกษาสำเร็จ']);
}

/**
 * Update education
 */
function updateEducation($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    $degree = trim($_POST['degree'] ?? '');
    $institution = trim($_POST['institution'] ?? '');
    $fieldOfStudy = trim($_POST['field_of_study'] ?? '');
    $graduationYear = (int)($_POST['graduation_year'] ?? 0) ?: null;
    $gpa = floatval($_POST['gpa'] ?? 0) ?: null;
    
    // Convert Thai year to AD if > 2400
    if ($graduationYear && $graduationYear > 2400) {
        $graduationYear -= 543;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM hr_employee_education WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE hr_employee_education SET degree = ?, institution = ?, field_of_study = ?, graduation_year = ?, gpa = ? WHERE id = ?");
    $stmt->execute([$degree, $institution, $fieldOfStudy, $graduationYear, $gpa, $id]);
    
    echo json_encode(['success' => true, 'message' => 'อัปเดตข้อมูลสำเร็จ']);
}

/**
 * Delete education
 */
function deleteEducation($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    
    $stmt = $pdo->prepare("DELETE FROM hr_employee_education WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    
    if ($stmt->rowCount() > 0) {
        Auth::log('education_delete', 'hr_education_history', $id);
        echo json_encode(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
    }
}

/**
 * Add work history
 */
function addWorkHistory($pdo, $user) {
    $companyName = trim($_POST['company_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '') ?: null;
    $responsibilities = trim($_POST['responsibilities'] ?? '');
    $leavingReason = trim($_POST['leaving_reason'] ?? '');
    
    if (!$companyName || !$position || !$startDate) {
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO hr_employee_work_history (user_id, company_name, position, start_date, end_date, responsibilities, leaving_reason, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $companyName, $position, $startDate, $endDate, $responsibilities, $leavingReason]);
    
    Auth::log('work_history_add', 'hr_work_history', $pdo->lastInsertId());
    
    echo json_encode(['success' => true, 'message' => 'เพิ่มประวัติการทำงานสำเร็จ']);
}

/**
 * Update work history
 */
function updateWorkHistory($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    $companyName = trim($_POST['company_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '') ?: null;
    $responsibilities = trim($_POST['responsibilities'] ?? '');
    $leavingReason = trim($_POST['leaving_reason'] ?? '');
    
    $stmt = $pdo->prepare("SELECT id FROM hr_employee_work_history WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE hr_employee_work_history SET company_name = ?, position = ?, start_date = ?, end_date = ?, responsibilities = ?, leaving_reason = ? WHERE id = ?");
    $stmt->execute([$companyName, $position, $startDate, $endDate, $responsibilities, $leavingReason, $id]);
    
    echo json_encode(['success' => true, 'message' => 'อัปเดตข้อมูลสำเร็จ']);
}

/**
 * Delete work history
 */
function deleteWorkHistory($pdo, $user) {
    $id = (int)($_POST['id'] ?? 0);
    
    $stmt = $pdo->prepare("DELETE FROM hr_employee_work_history WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    
    if ($stmt->rowCount() > 0) {
        Auth::log('work_history_delete', 'hr_work_history', $id);
        echo json_encode(['success' => true, 'message' => 'ลบข้อมูลสำเร็จ']);
    } else {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
    }
}
