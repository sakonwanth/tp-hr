<?php
/**
 * Holiday work exception requests
 *
 *   GET  /api/v1/holiday-work-requests[?status=&from=&to=&user_id=]
 *   POST /api/v1/holiday-work-requests
 *        body: { user_id, holiday_date, comp_date?, reason }
 *   POST /api/v1/holiday-work-requests/{id}/approve
 *   POST /api/v1/holiday-work-requests/{id}/reject
 */
require_once dirname(__DIR__, 2) . '/core/CrmLineNotifierBridge.php';

$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int) $segments[1] : 0;
$action = $segments[2] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['holiday_work.read']);
    $key = ApiAuth::currentKey();
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0);

    $where = [tp_hr_non_system_user_condition_sql('u')];
    $params = [];
    if ($status !== '') {
        if (!in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
            ApiAuth::fail(400, 'Invalid status');
        }
        $where[] = 'h.status = ?';
        $params[] = $status;
    }
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            ApiAuth::fail(400, 'Invalid from');
        }
        $where[] = 'h.holiday_date >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            ApiAuth::fail(400, 'Invalid to');
        }
        $where[] = 'h.holiday_date <= ?';
        $params[] = $to;
    }
    if ($userId > 0) {
        $where[] = 'h.user_id = ?';
        $params[] = $userId;
    }

    $stmt = $pdo->prepare('
        SELECT h.id, h.user_id, u.employee_code, u.first_name_th, u.last_name_th,
               h.holiday_date, h.comp_date, h.holiday_name, h.reason, h.status,
               h.reviewed_by, h.reviewed_at, h.review_note, h.created_at
        FROM hr_holiday_work_exceptions h
        JOIN users u ON u.id = h.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY h.holiday_date DESC
        LIMIT 1000
    ');
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

$body = ApiAuth::input();

if ($id <= 0) {
    ApiAuth::require(['holiday_work.write']);
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'holiday_work.write_all',
        'Creating holiday-work requests via API requires holiday_work.write_all (or *) or a service user bound to the API key'
    );
    $userId = apiKeyResolveScopedUserId($key, (int) ($body['user_id'] ?? 0));
    $holidayDate = trim($body['holiday_date'] ?? '');
    $compDate = trim($body['comp_date'] ?? '');
    $reason = trim($body['reason'] ?? '');

    if ($userId <= 0) {
        ApiAuth::fail(400, 'user_id required');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
        ApiAuth::fail(400, 'holiday_date invalid');
    }
    if ($compDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $compDate)) {
        ApiAuth::fail(400, 'comp_date invalid');
    }
    if ($compDate !== '' && $compDate === $holidayDate) {
        ApiAuth::fail(400, 'comp_date must differ from holiday_date');
    }
    if ($reason === '') {
        ApiAuth::fail(400, 'reason required');
    }

    $holStmt = $pdo->prepare('SELECT name FROM hr_holidays WHERE date = ? AND is_active = 1 LIMIT 1');
    $holStmt->execute([$holidayDate]);
    $holiday = $holStmt->fetch(PDO::FETCH_ASSOC);
    if (!$holiday) {
        ApiAuth::fail(400, 'holiday_date is not an active company holiday');
    }

    $ok = $pdo->prepare('
        INSERT INTO hr_holiday_work_exceptions
            (user_id, holiday_date, comp_date, holiday_name, reason, status)
        VALUES (?,?,?,?,?, \'PENDING\')
        ON DUPLICATE KEY UPDATE
            comp_date = VALUES(comp_date),
            holiday_name = VALUES(holiday_name),
            reason = VALUES(reason),
            status = \'PENDING\',
            reviewed_by = NULL,
            reviewed_at = NULL,
            review_note = NULL
    ')->execute([
        $userId,
        $holidayDate,
        $compDate !== '' ? $compDate : null,
        $holiday['name'] ?? null,
        $reason,
    ]);

    if (!$ok) {
        ApiAuth::fail(500, 'Failed to create');
    }
    $newId = (int) $pdo->lastInsertId();
    if ($newId <= 0) {
        $idStmt = $pdo->prepare('SELECT id FROM hr_holiday_work_exceptions WHERE user_id = ? AND holiday_date = ? LIMIT 1');
        $idStmt->execute([$userId, $holidayDate]);
        $newId = (int) $idStmt->fetchColumn();
    }
    if ($newId > 0 && function_exists('crm_line_notify_holiday_work_requested')) {
        crm_line_notify_holiday_work_requested($pdo, $newId);
    }
    ApiAuth::success(['data' => ['id' => $newId]], 201);
}

ApiAuth::require(['holiday_work.approve']);
apiKeyForbidServiceScoped();
if (!in_array($action, ['approve', 'reject'], true)) {
    ApiAuth::fail(404, 'Unknown action');
}

$reviewerId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'reviewer_id', MANAGER_ROLES);
$note = trim($body['note'] ?? '');

$row = $pdo->prepare('SELECT id, status FROM hr_holiday_work_exceptions WHERE id = ? LIMIT 1');
$row->execute([$id]);
$cur = $row->fetch(PDO::FETCH_ASSOC);
if (!$cur) {
    ApiAuth::fail(404, 'Not found');
}
if ($cur['status'] !== 'PENDING') {
    ApiAuth::fail(409, 'Already processed');
}

$newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
if ($newStatus === 'REJECTED' && $note === '') {
    ApiAuth::fail(400, 'note required for reject');
}

$pdo->prepare('
    UPDATE hr_holiday_work_exceptions
    SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
    WHERE id = ?
')->execute([$newStatus, $reviewerId, $note ?: null, $id]);

if (function_exists('crm_line_notify_holiday_work_decision')) {
    crm_line_notify_holiday_work_decision($pdo, $id, $newStatus, $note);
}

ApiAuth::success(['data' => ['id' => $id, 'status' => $newStatus]]);
