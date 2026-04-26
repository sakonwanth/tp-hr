<?php
/**
 * Day-off requests
 *
 *   GET  /api/v1/dayoff-requests[?status=&from=&to=&user_id=]   scope: dayoff.read (+ dayoff.read_all if key has no service_user_id)
 *   POST /api/v1/dayoff-requests                                scope: dayoff.write
 *        body: { user_id, week_start, week_end, original_day_off, requested_day_off, reason }
 *   POST /api/v1/dayoff-requests/{id}/approve                   scope: dayoff.approve
 *        body: { reviewer_id?, note? } — reviewer_id ผูกกับผู้ออก API key เมื่อมี created_by
 *   POST /api/v1/dayoff-requests/{id}/reject                    scope: dayoff.approve
 *        body: { reviewer_id?, note }
 */
$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$action = $segments[2] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['dayoff.read']);
    $key = ApiAuth::currentKey();
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

    $where = ["u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")"];
    $params = [];
    if ($status !== '') {
        if (!in_array($status, ['PENDING','APPROVED','REJECTED','CANCELLED'], true)) ApiAuth::fail(400, 'Invalid status');
        $where[] = "d.status = ?"; $params[] = $status;
    }
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ApiAuth::fail(400, 'Invalid from');
        $where[] = "d.week_end >= ?"; $params[] = $from;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ApiAuth::fail(400, 'Invalid to');
        $where[] = "d.week_start <= ?"; $params[] = $to;
    }
    if ($userId > 0) { $where[] = "d.user_id = ?"; $params[] = $userId; }

    $stmt = $pdo->prepare("
        SELECT d.id, d.user_id, u.employee_code, u.first_name_th, u.last_name_th,
               d.week_start, d.week_end, d.original_day_off, d.requested_day_off,
               d.reason, d.status, d.reviewed_by, d.reviewed_at, d.review_note, d.created_at
        FROM hr_dayoff_requests d
        JOIN users u ON u.id = d.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.week_start DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// POST
$body = ApiAuth::input();

if ($id <= 0) {
    // Create new dayoff request
    ApiAuth::require(['dayoff.write']);
    $userId = apiKeyResolveScopedUserId(ApiAuth::currentKey(), (int)($body['user_id'] ?? 0));
    $wStart = trim($body['week_start'] ?? '');
    $wEnd   = trim($body['week_end'] ?? '');
    $orig   = (int)($body['original_day_off'] ?? 0);
    $req    = (int)($body['requested_day_off'] ?? -1);
    $reason = trim($body['reason'] ?? '');

    if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wStart)) ApiAuth::fail(400, 'week_start invalid');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wEnd)) ApiAuth::fail(400, 'week_end invalid');
    if ($req < 0 || $req > 6) ApiAuth::fail(400, 'requested_day_off must be 0-6');
    if ($reason === '') ApiAuth::fail(400, 'reason required');

    $ok = $pdo->prepare("
        INSERT INTO hr_dayoff_requests
            (user_id, week_start, week_end, original_day_off, requested_day_off, reason, status)
        VALUES (?,?,?,?,?,?, 'PENDING')
    ")->execute([$userId, $wStart, $wEnd, $orig, $req, $reason]);

    if (!$ok) ApiAuth::fail(500, 'Failed to create');
    ApiAuth::success(['data' => ['id' => (int)$pdo->lastInsertId()]], 201);
}

// Actions: approve/reject
ApiAuth::require(['dayoff.approve']);
apiKeyForbidServiceScoped();
if (!in_array($action, ['approve', 'reject'], true)) ApiAuth::fail(404, 'Unknown action');

$reviewerId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'reviewer_id', MANAGER_ROLES);
$note = trim($body['note'] ?? '');

$row = $pdo->prepare("SELECT id, status FROM hr_dayoff_requests WHERE id = ? LIMIT 1");
$row->execute([$id]);
$cur = $row->fetch(PDO::FETCH_ASSOC);
if (!$cur) ApiAuth::fail(404, 'Not found');
if ($cur['status'] !== 'PENDING') ApiAuth::fail(409, 'Already processed');

$newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
if ($newStatus === 'REJECTED' && $note === '') ApiAuth::fail(400, 'note required for reject');

$pdo->prepare("
    UPDATE hr_dayoff_requests
    SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
    WHERE id = ?
")->execute([$newStatus, $reviewerId, $note ?: null, $id]);

ApiAuth::success(['data' => ['id' => $id, 'status' => $newStatus]]);
