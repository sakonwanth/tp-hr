<?php
/**
 * TP-CRM Employee Profile — Thin Controller
 *
 * View/edit: ข้อมูลพนักงานเต็มรูปแบบ (HR/Payroll profile)
 *
 * Access:
 *   - ทุกคน login แล้ว: ดู/แก้ไขโปรไฟล์ตนเอง (จำกัดฟิลด์)
 *   - HR / Admin / Chairman / CEO: ดู/แก้ไขได้ทุกคน รวมฟิลด์ payroll/เงินเดือน
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/./core/LegacyHrUi.php';
Auth::requireLogin();

$pdo = getDB();
$session_user = Auth::user();

require_once HR_OWNER_PATH . '/modules/employee_profile/queries.php';

// โหลดข้อมูลผู้ใช้งานปัจจุบันพร้อม role (ไม่พึ่ง session)
$stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$session_user['id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$current_user) { header('Location: login.php'); exit; }

$is_hr_admin = ep_is_hr_admin($current_user['role_name'] ?? '');

// เลือก target user: ?id=N (ต้องเป็น HR admin) หรือ self
$req_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($req_id > 0 && $req_id !== (int)$current_user['id'] && !$is_hr_admin) {
    header('Location: employee_profile.php');
    exit;
}
$target_id = $req_id > 0 ? $req_id : (int)$current_user['id'];

$target_user = ep_get_employee($pdo, $target_id);
if (!$target_user) {
    $_SESSION['flash_error'] = 'ไม่พบข้อมูลพนักงาน';
    header('Location: employee_profile.php');
    exit;
}
$is_self = ((int)$target_user['id'] === (int)$current_user['id']);

// Page meta
$page_title = 'โปรไฟล์พนักงาน';
$current_page = 'employee_profile';
$message = '';
$error = '';

// Flash
if (!empty($_SESSION['flash_message'])) { $message = $_SESSION['flash_message']; unset($_SESSION['flash_message']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Handle POST
require_once HR_OWNER_PATH . '/modules/employee_profile/actions.php';

// Reload target after potential update (POST handler does PRG redirect on success)
$target_user = ep_get_employee($pdo, $target_id);

// Data for view
$roles = $pdo->query("SELECT id, name, display_name FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$employees = $is_hr_admin ? ep_list_employees($pdo) : null;

require_once __DIR__ . '/templates/header.php';
require_once HR_OWNER_PATH . '/modules/employee_profile/view.php';
require_once __DIR__ . '/templates/footer.php';
