<?php
/**
 * Attendance adjustment requests
 *
 *   GET  /api/v1/attendance-adjustments[?status=&from=&to=&user_id=]  scope: adjustments.read
 *   POST /api/v1/attendance-adjustments/{id}/approve                  scope: adjustments.approve
 *        body: { reviewer_id?, remarks? } — reviewer_id ผูกกับผู้ออก API key เมื่อมี created_by
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
$reviewerId = apiKeyResolveActorForApi($pdo, ApiAuth::currentKey(), $body, 'reviewer_id', MANAGER_ROLES);
$remarks = trim($body['remarks'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM hr_attendance_adjustments WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$cur = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cur) ApiAuth::fail(404, 'Not found');
if ($cur['status'] !== 'PENDING') ApiAuth::fail(409, 'Already processed');

try {
    $pdo->beginTransaction();
    if ($action === 'approve') {
        $attStmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE id = ? LIMIT 1 FOR UPDATE");
        $attStmt->execute([(int)$cur['attendance_id']]);
        $attendance = $attStmt->fetch(PDO::FETCH_ASSOC);
        if (!$attendance) {
            $pdo->rollBack();
            ApiAuth::fail(404, 'Parent attendance not found');
        }

        $attendanceService = new AttendanceService($pdo);
        $targetUser = $attendanceService->getUserForAttendance((int)$cur['user_id']);
        if (!$targetUser) {
            $pdo->rollBack();
            ApiAuth::fail(404, 'User not found or inactive');
        }

        $date = (string)$attendance['attendance_date'];
        $checkIn = !empty($cur['requested_check_in'])
            ? AttendanceService::normalizeDateTime($date, (string)$cur['requested_check_in'])
            : ($attendance['check_in_time'] ?? null);
        $checkOut = !empty($cur['requested_check_out'])
            ? AttendanceService::normalizeDateTime($date, (string)$cur['requested_check_out'])
            : ($attendance['check_out_time'] ?? null);
        $shift = $attendanceService->getShiftById((int)($attendance['shift_id'] ?? 0));
        $summary = $attendanceService->summarizeAttendance(
            $targetUser,
            $shift,
            $date,
            $checkIn,
            $checkOut,
            $attendance['planned_start_time'] ?? null,
            $attendance['status'] ?? null
        );

        $pdo->prepare("
            UPDATE hr_attendances
            SET check_in_time = ?,
                check_out_time = ?,
                check_in_type = CASE WHEN ? = 1 THEN 'MANUAL' ELSE check_in_type END,
                check_out_type = CASE WHEN ? = 1 THEN 'MANUAL' ELSE check_out_type END,
                work_minutes = ?,
                break_minutes = ?,
                late_minutes = ?,
                early_leave_minutes = ?,
                ot_minutes = ?,
                status = ?,
                adjusted_by = ?,
                adjusted_at = NOW(),
                adjustment_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $checkIn,
            $checkOut,
            !empty($cur['requested_check_in']) ? 1 : 0,
            !empty($cur['requested_check_out']) ? 1 : 0,
            (int)$summary['work_minutes'],
            (int)$summary['break_minutes'],
            (int)$summary['late_minutes'],
            (int)$summary['early_leave_minutes'],
            (int)$summary['ot_minutes'],
            (string)$summary['status'],
            $reviewerId,
            $cur['reason'],
            (int)$cur['attendance_id'],
        ]);

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
    tpHrLogException($e, 'api/v1/adjustments');
    ApiAuth::fail(500, 'Internal server error');
}

ApiAuth::success(['data' => ['id' => $id, 'status' => $action === 'approve' ? 'APPROVED' : 'REJECTED']]);
