<?php
/**
 * Attendance adjustment requests
 *
 *   GET  /api/v1/attendance-adjustments[?status=&from=&to=&user_id=]  scope: adjustments.read (+ adjustments.read_all if key has no service_user_id)
 *   POST /api/v1/attendance-adjustments/{id}/approve                  scope: adjustments.approve
 *        body: { reviewer_id?, remarks? } — reviewer_id ผูกกับผู้ออก API key เมื่อมี created_by (CEO+)
 *        On approve: applies requested times to hr_attendances.
 *   POST /api/v1/attendance-adjustments/{id}/reject                   scope: adjustments.approve
 *        body: { reviewer_id?, remarks }
 */
$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$action = $segments[2] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['adjustments.read']);
    $key = ApiAuth::currentKey();
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'adjustments.read_all',
        'Attendance-adjustment list queries require adjustments.read_all (or *) or a service user bound to the API key'
    );

    $where = [tp_hr_non_system_user_condition_sql('u')];
    $params = [];
    if ($status !== '') {
        if (!in_array($status, ['PENDING','APPROVED','REJECTED','CANCELLED'], true)) ApiAuth::fail(400, 'Invalid status');
        $where[] = "a.status = ?"; $params[] = $status;
    }
    if ($from !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ApiAuth::fail(400, 'Invalid from');
        $where[] = "DATE(att.attendance_date) >= ?"; $params[] = $from;
    }
    if ($to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ApiAuth::fail(400, 'Invalid to');
        $where[] = "DATE(att.attendance_date) <= ?"; $params[] = $to;
    }
    if ($userId > 0) { $where[] = "a.user_id = ?"; $params[] = $userId; }

    $stmt = $pdo->prepare("
        SELECT a.id, a.attendance_id, a.user_id, u.employee_code, u.first_name_th, u.last_name_th,
               att.attendance_date,
               a.adjustment_type,
               a.original_check_in, a.original_check_out,
               a.requested_check_in, a.requested_check_out,
               a.reason, a.document_path, a.status,
               a.reviewed_by, a.reviewed_at, a.review_remarks, a.created_at
        FROM hr_attendance_adjustments a
        JOIN users u ON u.id = a.user_id
        JOIN hr_attendances att ON att.id = a.attendance_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY att.attendance_date DESC, a.id DESC
        LIMIT 1000
    ");
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

ApiAuth::require(['adjustments.approve']);
apiKeyForbidServiceScoped();
if (!in_array($action, ['approve', 'reject'], true)) ApiAuth::fail(404, 'Unknown action');
$body = ApiAuth::input();
$reviewerId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'reviewer_id', CEO_ROLES);
$remarks = trim($body['remarks'] ?? '');

try {
    $service = new AttendanceAdjustmentService($pdo);
    if ($action === 'approve') {
        $service->approve($id, $reviewerId, $remarks);
    } else {
        $service->reject($id, $reviewerId, $remarks);
    }
} catch (AttendanceAdjustmentException $e) {
    ApiAuth::fail($e->httpStatus(), $e->getMessage());
} catch (Throwable $e) {
    tpHrLogException($e, 'api/v1/adjustments');
    ApiAuth::fail(500, 'Internal server error');
}

ApiAuth::success(['data' => ['id' => $id, 'status' => $action === 'approve' ? 'APPROVED' : 'REJECTED']]);
