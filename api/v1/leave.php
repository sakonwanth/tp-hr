<?php
/**
 * Leave requests
 *
 *   GET  /api/v1/leave[?status=&from=&to=&user_id=]   scope: leave.read (+ leave.read_all if key has no service_user_id)
 *   GET  /api/v1/leave/{id}                           scope: เช่นเดียวกันสำหรับคีย์ไม่ผูก user
 *   POST /api/v1/leave                                scope: leave.write (+ leave.write_all if key has no service_user_id)
 *        body: { user_id, leave_type_id, start_date, end_date,
 *                start_period?, end_period?, total_days, reason?, contact_number? }
 *   POST /api/v1/leave/{id}/approve                   scope: leave.approve
 *        body: { approver_level (1|2|3), approver_id?, remarks? }
 *        approver_id: ถ้าคีย์มี created_by ต้องตรงกับผู้ออกคีย์เท่านั้น (หรือไม่ส่ง — ระบบใช้ created_by)
 *   POST /api/v1/leave/{id}/reject                    scope: leave.approve
 *        body: { approver_level (1|2|3), approver_id?, remarks }
 *   POST /api/v1/leave/{id}/cancel                    scope: เช่นเดียวกัน
 *        body: { user_id } (must match request owner)
 */
require_once BASE_PATH . '/core/CrmLineNotifierBridge.php';

$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$action = $segments[2] ?? '';

$selectBase = "
    SELECT lr.id, lr.request_number, lr.user_id, u.employee_code, u.first_name_th, u.last_name_th,
           lt.id AS leave_type_id, lt.code AS leave_code, lt.name AS leave_type,
           lr.start_date, lr.end_date, lr.start_period, lr.end_period,
           lr.total_days, lr.reason, lr.contact_number, lr.status,
           lr.approver_1_id, lr.approver_1_status, lr.approver_1_date, lr.approver_1_remarks,
           lr.approver_2_id, lr.approver_2_status, lr.approver_2_date, lr.approver_2_remarks,
           lr.approver_3_id, lr.approver_3_status, lr.approver_3_date, lr.approver_3_remarks,
           lr.created_at
    FROM hr_leave_requests lr
    JOIN users u ON u.id = lr.user_id
    JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
";

if ($method === 'GET') {
    ApiAuth::require(['leave.read']);
    $key = ApiAuth::currentKey();
    if ($id > 0) {
        $stmt = $pdo->prepare($selectBase . " WHERE lr.id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) ApiAuth::fail(404, 'Leave request not found');
        if (apiKeyServiceUserId($key) !== null) {
            apiKeyAssertResourceOwnerUserId($key, (int) $row['user_id']);
        } elseif (!apiKeyHasReadAllScope($key, 'leave.read_all')) {
            ApiAuth::fail(403, 'Reading leave by id requires leave.read_all (or *) or a service user bound to the API key');
        }
        ApiAuth::success(['data' => $row]);
    }

    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'leave.read_all',
        'Leave list queries require leave.read_all (or *) or a service user bound to the API key'
    );

    $status = strtoupper(trim($_GET['status'] ?? ''));
    $from   = $_GET['from'] ?? '';
    $to     = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

    $validStatus = ['DRAFT', 'PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'];
    $where = [tp_hr_non_system_user_condition_sql('u')];
    $params = [];

    if ($status !== '') {
        if (!in_array($status, $validStatus, true)) ApiAuth::fail(400, 'Invalid status');
        $where[] = "lr.status = ?"; $params[] = $status;
    }
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ApiAuth::fail(400, 'Invalid from');
        $where[] = "lr.end_date >= ?"; $params[] = $from;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ApiAuth::fail(400, 'Invalid to');
        $where[] = "lr.start_date <= ?"; $params[] = $to;
    }
    if ($userId > 0) { $where[] = "lr.user_id = ?"; $params[] = $userId; }

    $sql = $selectBase . " WHERE " . implode(' AND ', $where) . " ORDER BY lr.start_date DESC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// POST
$body = ApiAuth::input();

if ($id <= 0) {
    ApiAuth::require(['leave.write']);
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'leave.write_all',
        'Creating leave for users via API requires leave.write_all (or *) or a service user bound to the API key'
    );
    $userId = apiKeyResolveScopedUserId($key, (int)($body['user_id'] ?? 0));
    $typeId = (int)($body['leave_type_id'] ?? 0);
    $start = trim($body['start_date'] ?? '');
    $end   = trim($body['end_date'] ?? '');
    $startPeriod = strtoupper(trim($body['start_period'] ?? 'FULL'));
    $endPeriod   = strtoupper(trim($body['end_period'] ?? 'FULL'));
    $totalDays = (float)($body['total_days'] ?? 0);
    $reason = trim($body['reason'] ?? '');
    $contact = trim($body['contact_number'] ?? '');

    if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
    if ($typeId <= 0) ApiAuth::fail(400, 'leave_type_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) ApiAuth::fail(400, 'start_date invalid');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) ApiAuth::fail(400, 'end_date invalid');
    if (strtotime($end) < strtotime($start)) ApiAuth::fail(400, 'end_date before start_date');
    if (!in_array($startPeriod, ['FULL','AM','PM'], true)) ApiAuth::fail(400, 'start_period invalid');
    if (!in_array($endPeriod, ['FULL','AM','PM'], true)) ApiAuth::fail(400, 'end_period invalid');

    if (class_exists(\TpCommon\Hr\WorkdayCalculator::class)) {
        $totalDays = \TpCommon\Hr\WorkdayCalculator::countLeaveDays($pdo, $userId, $start, $end, $startPeriod, $endPeriod);
    }
    if ($totalDays <= 0 || $totalDays > 365) ApiAuth::fail(400, 'total_days invalid (no working days in range)');

    // Generate unique request number (retry on collision)
    $reqNum = null;
    $insertStmt = $pdo->prepare("
        INSERT INTO hr_leave_requests
            (request_number, user_id, leave_type_id, start_date, end_date,
             start_period, end_period, total_days, reason, contact_number, status)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'PENDING')
    ");
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'LV' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
        try {
            $insertStmt->execute([$candidate, $userId, $typeId, $start, $end, $startPeriod, $endPeriod, $totalDays, $reason ?: null, $contact ?: null]);
            $reqNum = $candidate;
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // not a dup-key; re-throw
        }
    }
    if ($reqNum === null) ApiAuth::fail(500, 'Failed to generate unique request number');

    $newId = (int)$pdo->lastInsertId();
    crm_line_notify_new_leave($pdo, $newId);

    ApiAuth::success(['data' => ['id' => $newId, 'request_number' => $reqNum]], 201);
}

// Actions (require at least read scope to probe; real scope enforced per-action below)
ApiAuth::require(['leave.read']);
if (!in_array($action, ['approve', 'reject', 'cancel', 'set-document'], true)) ApiAuth::fail(404, 'Unknown action');

$stmt = $pdo->prepare("SELECT * FROM hr_leave_requests WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$cur = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cur) ApiAuth::fail(404, 'Not found');

// Toggle the medical-cert document marker (Phase 2.1 — verbatim from CRM admin_set_medical_cert).
if ($action === 'set-document') {
    ApiAuth::require(['leave.write']);
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'leave.write_all',
        'Set-document via API requires leave.write_all (or *) or a service user bound to the API key'
    );
    $has = !empty($body['has']);
    if ($has) {
        $pdo->prepare("UPDATE hr_leave_requests SET document_path=COALESCE(document_path,'crm-admin-confirmed') WHERE id=?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE hr_leave_requests SET document_path=NULL WHERE id=?")->execute([$id]);
    }
    ApiAuth::success(['data' => ['id' => $id, 'has_document' => $has ? 1 : 0]]);
}

if ($action === 'cancel') {
    ApiAuth::require(['leave.write']);
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'leave.write_all',
        'Cancelling leave via API requires leave.write_all (or *) or a service user bound to the API key'
    );
    $userId = apiKeyResolveScopedUserId($key, (int)($body['user_id'] ?? 0));
    if ($userId <= 0 || (int)$cur['user_id'] !== $userId) ApiAuth::fail(403, 'user_id mismatch');
    if (!in_array($cur['status'], ['PENDING','DRAFT'], true)) ApiAuth::fail(409, 'Cannot cancel in status ' . $cur['status']);
    $pdo->prepare("UPDATE hr_leave_requests SET status='CANCELLED' WHERE id=?")->execute([$id]);
    ApiAuth::success(['data' => ['id' => $id, 'status' => 'CANCELLED']]);
}

ApiAuth::require(['leave.approve']);
apiKeyForbidServiceScoped();
$approverId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'approver_id', MANAGER_ROLES);

$level = (int)($body['approver_level'] ?? 1);
$remarks = trim($body['remarks'] ?? '');

if (!in_array($level, [1, 2, 3], true)) ApiAuth::fail(400, 'approver_level must be 1/2/3');
if ($cur['status'] !== 'PENDING') ApiAuth::fail(409, 'Not in PENDING status');
if ($action === 'reject' && $remarks === '') ApiAuth::fail(400, 'remarks required for reject');

$col = "approver_{$level}";
$newStepStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';

$pdo->prepare("
    UPDATE hr_leave_requests
    SET {$col}_id = ?, {$col}_status = ?, {$col}_date = NOW(), {$col}_remarks = ?
    WHERE id = ?
")->execute([$approverId, $newStepStatus, $remarks ?: null, $id]);

// Recalculate overall status
$row = $pdo->prepare("SELECT approver_1_status, approver_2_status, approver_3_status FROM hr_leave_requests WHERE id=?");
$row->execute([$id]);
$st = $row->fetch(PDO::FETCH_ASSOC);
$overall = $cur['status'];
if (in_array('REJECTED', [$st['approver_1_status'], $st['approver_2_status'], $st['approver_3_status']], true)) {
    $overall = 'REJECTED';
} else {
    $set = array_filter([$st['approver_1_status'], $st['approver_2_status'], $st['approver_3_status']], fn($v) => $v !== null);
    if ($set && !in_array('PENDING', $set, true) && count(array_unique($set)) === 1 && reset($set) === 'APPROVED') {
        $overall = 'APPROVED';
    }
}
if ($overall !== $cur['status']) {
    $pdo->prepare("UPDATE hr_leave_requests SET status=? WHERE id=?")->execute([$overall, $id]);
    if ($overall === 'APPROVED') {
        crm_line_sync_approved_leave_attendance($pdo, $id, $approverId, 'api-v1');
        crm_line_notify_leave_decision($pdo, $id, 'APPROVED', $remarks);
    } elseif ($overall === 'REJECTED') {
        crm_line_notify_leave_decision($pdo, $id, 'REJECTED', $remarks);
    }
}

ApiAuth::success(['data' => ['id' => $id, 'level' => $level, 'step_status' => $newStepStatus, 'overall_status' => $overall]]);
