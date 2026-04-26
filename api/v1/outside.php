<?php
/**
 * Outside attendance requests (check-in/out ที่ขอจากนอกสถานที่)
 *
 *   GET  /api/v1/outside-attendance[?status=&from=&to=&user_id=]  scope: outside.read (+ outside.read_all if key has no service_user_id)
 *   POST /api/v1/outside-attendance/{id}/approve                  scope: outside.approve
 *        body: { reviewer_id?, remarks? } — reviewer_id ผูกกับผู้ออก API key เมื่อมี created_by
 *   POST /api/v1/outside-attendance/{id}/reject                   scope: outside.approve
 *        body: { reviewer_id?, remarks }
 */
$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$action = $segments[2] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['outside.read']);
    $key = ApiAuth::currentKey();
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'outside.read_all',
        'Outside-attendance list queries require outside.read_all (or *) or a service user bound to the API key'
    );

    $where = ["u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")"];
    $params = [];
    if ($status !== '') {
        if (!in_array($status, ['PENDING','APPROVED','REJECTED','CANCELLED'], true)) ApiAuth::fail(400, 'Invalid status');
        $where[] = "o.status = ?"; $params[] = $status;
    }
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ApiAuth::fail(400, 'Invalid from');
        $where[] = "o.request_date >= ?"; $params[] = $from;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ApiAuth::fail(400, 'Invalid to');
        $where[] = "o.request_date <= ?"; $params[] = $to;
    }
    if ($userId > 0) { $where[] = "o.user_id = ?"; $params[] = $userId; }

    $stmt = $pdo->prepare("
        SELECT o.id, o.user_id, u.employee_code, u.first_name_th, u.last_name_th,
               o.attendance_id, o.request_type, o.request_date, o.request_time,
               o.latitude, o.longitude, o.photo_path, o.reason, o.status,
               o.reviewed_by, o.reviewed_at, o.review_remarks, o.created_at
        FROM hr_attendance_outside_requests o
        JOIN users u ON u.id = o.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.request_date DESC, o.id DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

ApiAuth::require(['outside.approve']);
apiKeyForbidServiceScoped();
if (!in_array($action, ['approve', 'reject'], true)) ApiAuth::fail(404, 'Unknown action');
$body = ApiAuth::input();
$reviewerId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'reviewer_id', MANAGER_ROLES);
$remarks = trim($body['remarks'] ?? '');

$stmt = $pdo->prepare("SELECT id, status FROM hr_attendance_outside_requests WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$cur = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cur) ApiAuth::fail(404, 'Not found');
if ($cur['status'] !== 'PENDING') ApiAuth::fail(409, 'Already processed');

$newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
if ($newStatus === 'REJECTED' && $remarks === '') ApiAuth::fail(400, 'remarks required for reject');

$pdo->prepare("
    UPDATE hr_attendance_outside_requests
    SET status=?, reviewed_by=?, reviewed_at=NOW(), review_remarks=?
    WHERE id=?
")->execute([$newStatus, $reviewerId, $remarks ?: null, $id]);

ApiAuth::success(['data' => ['id' => $id, 'status' => $newStatus]]);
