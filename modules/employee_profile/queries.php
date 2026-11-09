<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * Employee Profile — queries
 * Variables available: $pdo
 */

/**
 * ดึงข้อมูลพนักงานพร้อมบทบาท
 */
function ep_get_employee(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role_name, r.display_name AS role_display_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** รายการพนักงาน active ทั้งหมด (สำหรับ HR/Admin เลือกดู profile) */
function ep_list_employees(PDO $pdo): array {
    $sql = "
        SELECT u.id, u.employee_code,
               CONCAT(COALESCE(u.first_name_th,''), ' ', COALESCE(u.last_name_th,'')) AS full_name,
               u.department, u.position, u.is_active,
               r.display_name AS role_display_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.employee_code IS NULL OR u.employee_code NOT LIKE 'CR%'
        ORDER BY u.is_active DESC, u.employee_code ASC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** role ที่ดูแลข้อมูลเต็ม (แก้ไขได้ทุกฟิลด์ รวม payroll) — เทียบ HR module / payroll web */
function ep_is_hr_admin(?string $role_name): bool {
    if (function_exists('crm_user_can_access_payroll_web') && crm_user_can_access_payroll_web()) {
        return true;
    }
    return in_array((string)$role_name, ['HR', 'Admin', 'Chairman', 'CEO'], true);
}
