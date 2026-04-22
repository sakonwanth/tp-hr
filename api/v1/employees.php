<?php
/**
 * GET /api/v1/employees
 * GET /api/v1/employees/{id}
 * Scope: employees.read
 */
ApiAuth::require(['employees.read']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    ApiAuth::fail(405, 'Method not allowed');
}

$pdo = getDB();
$segments = $segments ?? [];
$id = isset($segments[1]) ? (int)$segments[1] : 0;

$baseSelect = "
    SELECT u.id, u.employee_code, u.title, u.first_name_th, u.last_name_th,
           u.first_name_en, u.last_name_en, u.email, u.phone,
           u.department, u.position, u.hire_date, u.is_active,
           r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
";

if ($id > 0) {
    $stmt = $pdo->prepare($baseSelect . " AND u.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) ApiAuth::fail(404, 'Employee not found');
    ApiAuth::success(['data' => $row]);
}

// List (paginated)
$page = max(1, (int)($_GET['page'] ?? 1));
$defaultPerPage = defined('DEFAULT_PER_PAGE') ? DEFAULT_PER_PAGE : 25;
$perPage = min(200, max(1, (int)($_GET['per_page'] ?? $defaultPerPage)));
$offset = ($page - 1) * $perPage;

$activeOnly = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== '1';
$where = $activeOnly ? " AND u.is_active = 1" : "";

$cnt = $pdo->query("SELECT COUNT(*) FROM users u WHERE u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")" . $where)->fetchColumn();

$stmt = $pdo->prepare($baseSelect . $where . " ORDER BY u.id ASC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();

ApiAuth::success([
    'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    'meta' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => (int)$cnt,
        'total_pages' => (int)ceil($cnt / $perPage),
    ],
]);
