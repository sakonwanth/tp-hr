<?php
/**
 * Attendance adjustment requests
 *
 *   GET  /api/v1/attendance-adjustments[?status=&from=&to=&user_id=]  scope: adjustments.read
 *   POST /api/v1/attendance-adjustments/{id}/approve                  scope: adjustments.approve
 *        body: { reviewer_id, remarks? }
 *        On approve: applies requested times to hr_attendances.
 *   POST /api/v1/attendance-adjustments/{id}/reject                   scope: adjustments.approve
 *        body: { reviewer_id, remarks }
 */
$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$id = isset($segments[1]) ? (int)$segments[1] : 0;
$action = $segments[2] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['adjustments.read']);
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    $where = ["u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")"];
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
if (!in_array($action, ['approve', 'reject'], true)) ApiAuth::fail(404, 'Unknown action');
$body = ApiAuth::input();
$reviewerId = (int)($body['reviewer_id'] ?? 0);
$remarks = trim($body['remarks'] ?? '');
if ($reviewerId <= 0) ApiAuth::fail(400, 'reviewer_id required');

$stmt = $pdo->prepare("SELECT * FROM hr_attendance_adjustments WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$cur = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cur) ApiAuth::fail(404, 'Not found');
if ($cur['status'] !== 'PENDING') ApiAuth::fail(409, 'Already processed');

try {
    $pdo->beginTransaction();
    if ($action === 'approve') {
        // Apply requested times to parent attendance
        $sets = [];
        $params = [];
        if (!empty($cur['requested_check_in'])) {
            $sets[] = "check_in_time = ?"; $params[] = $cur['requested_check_in'];
        }
        if (!empty($cur['requested_check_out'])) {
            $sets[] = "check_out_time = ?"; $params[] = $cur['requested_check_out'];
        }
        if ($sets) {
            $sets[] = "adjusted_by = ?"; $params[] = $reviewerId;
            $sets[] = "adjusted_at = NOW()";
            $sets[] = "adjustment_reason = ?"; $params[] = $cur['reason'];
            $params[] = (int)$cur['attendance_id'];
            $pdo->prepare("UPDATE hr_attendances SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
        $pdo->prepare("
            UPDATE hr_attendance_adjustments
            SET status='APPROVED', reviewed_by=?, reviewed_at=NOW(), review_remarks=?
            WHERE id=?
        ")->execute([$reviewerId, $remarks ?: null, $id]);
    } else {
        if ($remarks === '') { $pdo->rollBack(); ApiAuth::fail(400, 'remarks required for reject'); }
        $pdo->prepare("
            UPDATE hr_attendance_adjustments
            SET status='REJECTED', reviewed_by=?, reviewed_at=NOW(), review_remarks=?
            WHERE id=?
        ")->execute([$reviewerId, $remarks, $id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ApiAuth::fail(500, 'Failed to process: ' . $e->getMessage());
}

ApiAuth::success(['data' => ['id' => $id, 'status' => $action === 'approve' ? 'APPROVED' : 'REJECTED']]);
