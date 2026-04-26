<?php
/**
 * Overtime (OT) requests
 *
 *   GET  /api/v1/overtime[?status=&from=&to=&user_id=]   scope: overtime.read
 *   POST /api/v1/overtime                                scope: overtime.write
 *        body: { user_id, work_date, planned_start, planned_end, ot_type?, rate_multiplier?, reason? }
 *   POST /api/v1/overtime/{id}/approve                   scope: overtime.approve
 *        body: { approver_id?, actual_hours? } — approver_id ผูกกับผู้ออก API key เมื่อมี created_by
 *   POST /api/v1/overtime/{id}/reject                    scope: overtime.approve
 *        body: { approver_id?, reason }
 */
$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$action = $segments[2] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['overtime.read']);
    $key = ApiAuth::currentKey();
    $status = strtolower(trim($_GET['status'] ?? ''));
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

    $where = ["u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")"];
    $params = [];
    if ($status !== '') {
        if (!in_array($status, ['pending','approved','rejected','cancelled'], true)) ApiAuth::fail(400, 'Invalid status');
        $where[] = "o.status = ?"; $params[] = $status;
    }
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ApiAuth::fail(400, 'Invalid from');
        $where[] = "o.work_date >= ?"; $params[] = $from;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ApiAuth::fail(400, 'Invalid to');
        $where[] = "o.work_date <= ?"; $params[] = $to;
    }
    if ($userId > 0) { $where[] = "o.user_id = ?"; $params[] = $userId; }

    $stmt = $pdo->prepare("
        SELECT o.id, o.user_id, u.employee_code, u.first_name_th, u.last_name_th,
               o.work_date, o.planned_start, o.planned_end, o.actual_hours,
               o.ot_type, o.rate_multiplier, o.reason, o.status,
               o.approved_by, o.approved_at, o.reject_reason, o.created_at
        FROM ot_requests o
        JOIN users u ON u.id = o.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.work_date DESC, o.id DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

$body = ApiAuth::input();

if ($id <= 0) {
    ApiAuth::require(['overtime.write']);
    $userId = apiKeyResolveScopedUserId(ApiAuth::currentKey(), (int)($body['user_id'] ?? 0));
    $date = trim($body['work_date'] ?? '');
    $ps = trim($body['planned_start'] ?? '');
    $pe = trim($body['planned_end'] ?? '');
    $type = strtolower(trim($body['ot_type'] ?? 'normal'));
    $rate = (float)($body['rate_multiplier'] ?? 1.5);
    $reason = trim($body['reason'] ?? '');

    if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ApiAuth::fail(400, 'work_date invalid');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $ps)) ApiAuth::fail(400, 'planned_start invalid (HH:MM)');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $pe)) ApiAuth::fail(400, 'planned_end invalid (HH:MM)');
    if (!in_array($type, ['normal','holiday','day_off'], true)) ApiAuth::fail(400, 'ot_type invalid');
    if ($rate < 1 || $rate > 5) ApiAuth::fail(400, 'rate_multiplier out of range');

    $pdo->prepare("
        INSERT INTO ot_requests (user_id, work_date, planned_start, planned_end, ot_type, rate_multiplier, reason, status)
        VALUES (?,?,?,?,?,?,?, 'pending')
    ")->execute([$userId, $date, $ps, $pe, $type, $rate, $reason ?: null]);

    ApiAuth::success(['data' => ['id' => (int)$pdo->lastInsertId()]], 201);
}

ApiAuth::require(['overtime.approve']);
apiKeyForbidServiceScoped();
if (!in_array($action, ['approve', 'reject'], true)) ApiAuth::fail(404, 'Unknown action');

$approverId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'approver_id', MANAGER_ROLES);

$stmt = $pdo->prepare("SELECT id, status FROM ot_requests WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$cur = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cur) ApiAuth::fail(404, 'Not found');
if ($cur['status'] !== 'pending') ApiAuth::fail(409, 'Already processed');

if ($action === 'approve') {
    $actual = isset($body['actual_hours']) ? (float)$body['actual_hours'] : null;
    $pdo->prepare("
        UPDATE ot_requests
        SET status='approved', approved_by=?, approved_at=NOW(), actual_hours = COALESCE(?, actual_hours)
        WHERE id=?
    ")->execute([$approverId, $actual, $id]);
    ApiAuth::success(['data' => ['id' => $id, 'status' => 'approved']]);
} else {
    $reason = trim($body['reason'] ?? '');
    if ($reason === '') ApiAuth::fail(400, 'reason required');
    $pdo->prepare("
        UPDATE ot_requests
        SET status='rejected', approved_by=?, approved_at=NOW(), reject_reason=?
        WHERE id=?
    ")->execute([$approverId, $reason, $id]);
    ApiAuth::success(['data' => ['id' => $id, 'status' => 'rejected']]);
}
