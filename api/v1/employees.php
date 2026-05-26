<?php
/**
 * GET /api/v1/employees
 * GET /api/v1/employees/{id}
 * Scope: employees.read; list or GET by id without service_user_id needs employees.read_all (or *)
 */
ApiAuth::require(['employees.read']);
$key = ApiAuth::currentKey();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    ApiAuth::fail(405, 'Method not allowed');
}

$pdo = getDB();
$segments = $segments ?? [];
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$svc = apiKeyServiceUserId($key);

$baseSelect = "
    SELECT u.id, u.employee_code, u.title, u.first_name_th, u.last_name_th,
           u.first_name_en, u.last_name_en, u.email, u.phone,
           u.department, u.position, u.hire_date, u.is_active,
           r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE " . tp_hr_non_system_user_condition_sql('u') . "
";

if ($id > 0) {
    $stmt = $pdo->prepare($baseSelect . " AND u.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) ApiAuth::fail(404, 'Employee not found');
    if (apiKeyServiceUserId($key) !== null) {
        apiKeyAssertResourceOwnerUserId($key, (int) $row['id']);
    } elseif (!apiKeyMayListAllEmployees($key)) {
        ApiAuth::fail(403, 'Reading employees by id requires employees.read_all (or *) or a service user bound to the API key');
    }
    ApiAuth::success(['data' => $row]);
}

if ($svc !== null) {
    $stmt = $pdo->prepare($baseSelect . " AND u.id = ? LIMIT 1");
    $stmt->execute([$svc]);
    $one = $stmt->fetch(PDO::FETCH_ASSOC);
    ApiAuth::success([
        'data' => $one ? [$one] : [],
        'meta' => [
            'page' => 1,
            'per_page' => 1,
            'total' => $one ? 1 : 0,
            'total_pages' => 1,
        ],
    ]);
}

if (!apiKeyMayListAllEmployees($key)) {
    ApiAuth::fail(403, 'Listing employees requires employees.read_all (or *) or a service user bound to the API key');
}

// List (paginated)
$page = max(1, (int)($_GET['page'] ?? 1));
$defaultPerPage = defined('DEFAULT_PER_PAGE') ? DEFAULT_PER_PAGE : 25;
$perPage = min(200, max(1, (int)($_GET['per_page'] ?? $defaultPerPage)));
$offset = ($page - 1) * $perPage;

$activeOnly = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== '1';
$where = $activeOnly ? " AND u.is_active = 1" : "";

$cnt = $pdo->query("SELECT COUNT(*) FROM users u WHERE " . tp_hr_non_system_user_condition_sql('u') . $where)->fetchColumn();

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
