<?php
/**
 * HR metadata endpoints (read-only)
 * Scope: hr.read
 *
 *   GET /api/v1/departments
 *   GET /api/v1/positions
 *   GET /api/v1/holidays?year=YYYY
 *   GET /api/v1/leave-types
 *   GET /api/v1/employee-schedules[?user_id=]  — คีย์ไม่ผูก user ต้องมี hr.read_all (หรือ *)
 *   GET /api/v1/announcements
 *   GET /api/v1/leave-entitlements?year=YYYY[&user_id=] — เช่นเดียวกัน
 */
ApiAuth::require(['hr.read']);
ApiAuth::requireMethod(['GET']);

$pdo = getDB();
$key = ApiAuth::currentKey();
$resource = $segments[0] ?? '';

switch ($resource) {
    case 'departments': {
        $rows = $pdo->query("
            SELECT id, code, name, name_en, parent_id, manager_id, cost_center,
                   description, is_active, sort_order
            FROM hr_departments
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        ApiAuth::success(['data' => $rows]);
    }
    case 'positions': {
        $rows = $pdo->query("
            SELECT id, code, title, title_en, department_id, level,
                   min_salary, max_salary, is_active, sort_order
            FROM hr_positions
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        ApiAuth::success(['data' => $rows]);
    }
    case 'holidays': {
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        if ($year < 2000 || $year > 2100) ApiAuth::fail(400, 'Invalid year');
        $stmt = $pdo->prepare("
            SELECT id, date, name, name_en, type, description, is_active
            FROM hr_holidays
            WHERE YEAR(date) = ? AND is_active = 1
            ORDER BY date ASC
        ");
        $stmt->execute([$year]);
        ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    case 'leave-types': {
        $rows = $pdo->query("
            SELECT id, code, name, name_en, default_days_per_year, is_paid,
                   is_accumulative, max_accumulative_days, requires_document,
                   min_days_advance, max_consecutive_days, gender_restriction,
                   min_months_employed, is_active, sort_order
            FROM hr_leave_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        ApiAuth::success(['data' => $rows]);
    }
    case 'employee-schedules': {
        apiKeyRequireServiceUserOrReadAllScope(
            $key,
            'hr.read_all',
            'employee-schedules requires hr.read_all (or *) or a service user bound to the API key'
        );
        $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
        $sql = "
            SELECT s.id, s.user_id, u.employee_code, u.first_name_th, u.last_name_th,
                   s.day_off, s.effective_date, s.notes, s.updated_at
            FROM hr_employee_schedules s
            JOIN users u ON u.id = s.user_id
            WHERE u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
        ";
        $params = [];
        if ($userId > 0) { $sql .= " AND s.user_id = ?"; $params[] = $userId; }
        $sql .= " ORDER BY s.user_id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    case 'announcements': {
        $stmt = $pdo->query("
            SELECT id, title, excerpt, type, target_type, publish_date, expire_date,
                   is_pinned, requires_acknowledgement, view_count, is_active, created_at
            FROM hr_announcements
            WHERE is_active = 1
            ORDER BY is_pinned DESC, COALESCE(publish_date, created_at) DESC
            LIMIT 200
        ");
        ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    case 'leave-entitlements': {
        apiKeyRequireServiceUserOrReadAllScope(
            $key,
            'hr.read_all',
            'leave-entitlements requires hr.read_all (or *) or a service user bound to the API key'
        );
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
        $sql = "
            SELECT e.id, e.user_id, u.employee_code, u.first_name_th, u.last_name_th,
                   e.leave_type_id, lt.code AS leave_code, lt.name AS leave_name,
                   e.year, e.entitled_days, e.carried_over_days, e.additional_days,
                   e.used_days, e.pending_days,
                   (e.entitled_days + e.carried_over_days + e.additional_days - e.used_days - e.pending_days) AS remaining_days
            FROM hr_leave_entitlements e
            JOIN users u ON u.id = e.user_id
            JOIN hr_leave_types lt ON lt.id = e.leave_type_id
            WHERE e.year = ? AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
        ";
        $params = [$year];
        if ($userId > 0) { $sql .= " AND e.user_id = ?"; $params[] = $userId; }
        $sql .= " ORDER BY u.employee_code ASC, lt.sort_order ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    default:
        ApiAuth::fail(404, 'Endpoint not found');
}
