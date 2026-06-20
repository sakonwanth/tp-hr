<?php
/**
 * Attendance
 *
 *   GET  /api/v1/attendance?date=YYYY-MM-DD[&user_id=]         scope: attendance.read (+ attendance.read_all if key has no service_user_id)
 *   GET  /api/v1/attendance?from=&to=[&user_id=]               scope: เช่นเดียวกัน
 *   POST /api/v1/attendance/checkin                            scope: attendance.write (+ attendance.write_all if key has no service_user_id)
 *        body: { user_id, time?, type?, latitude?, longitude?, location_id?, remarks? }
 *   POST /api/v1/attendance/checkout                           scope: เช่นเดียวกัน
 *        body: { user_id, time?, type?, latitude?, longitude?, location_id?, remarks? }
 */
$method = ApiAuth::requireMethod(['GET', 'POST']);
$pdo = getDB();
$action = $segments[1] ?? '';

if ($method === 'GET') {
    ApiAuth::require(['attendance.read']);
    $key = ApiAuth::currentKey();

    $date = $_GET['date'] ?? '';
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $userId = apiKeyResolveScopedUserId($key, isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.read_all',
        'Attendance queries require attendance.read_all (or *) or a service user bound to the API key'
    );

    $sqlDate = "";
    $params = [];

    if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ApiAuth::fail(400, 'Invalid date');
        $sqlDate = " AND a.attendance_date = ?";
        $params[] = $date;
    } elseif ($from !== '' && $to !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            ApiAuth::fail(400, 'Invalid from/to');
        }
        if (strtotime($to) - strtotime($from) > 90 * 86400) {
            ApiAuth::fail(400, 'Range cannot exceed 90 days');
        }
        $sqlDate = " AND a.attendance_date BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
    } else {
        ApiAuth::fail(400, 'Provide either "date" or "from"+"to"');
    }

    if ($userId > 0) {
        $sqlDate .= " AND a.user_id = ?";
        $params[] = $userId;
    }

    $sql = "
        SELECT a.id, a.user_id, u.employee_code, u.first_name_th, u.last_name_th,
               a.attendance_date, a.check_in_time, a.check_out_time,
               a.check_in_type, a.check_out_type,
               a.check_in_latitude, a.check_in_longitude,
               a.check_out_latitude, a.check_out_longitude,
               a.work_minutes, a.late_minutes, a.early_leave_minutes, a.ot_minutes,
               a.status, a.is_offsite, a.offsite_status, a.adjustment_reason
        FROM hr_attendances a
        JOIN users u ON u.id = a.user_id
        WHERE " . tp_hr_non_system_user_condition_sql('u') . "
        $sqlDate
        ORDER BY a.attendance_date DESC, a.user_id ASC
        LIMIT 2000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ApiAuth::success(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// POST checkin/checkout/excuse-late
ApiAuth::require(['attendance.write']);

// Admin: toggle late-excused on an existing attendance row (Phase 2.1 — migrated verbatim
// from tp-crm modules/hr/actions.php admin_toggle_excused). Caller builds the audit tag.
if ($action === 'excuse-late') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'Excuse-late via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $attendanceId = (int)($body['attendance_id'] ?? 0);
    $excused = !empty($body['excused']) ? 1 : 0;
    $reason  = trim((string)($body['reason'] ?? ''));
    $audit   = trim((string)($body['audit'] ?? ''));
    if ($attendanceId <= 0) ApiAuth::fail(400, 'attendance_id required');
    try {
        // SQL byte-identical to the CRM direct path (parity by construction).
        $stmt = $pdo->prepare("UPDATE hr_attendances
            SET late_excused = ?,
                late_excused_reason = CASE WHEN ? <> '' THEN ? ELSE late_excused_reason END,
                late_notified_at = COALESCE(late_notified_at, NOW()),
                adjustment_reason = CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), ?)
            WHERE id = ?");
        $stmt->execute([$excused, $reason, $reason, $audit, $attendanceId]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance excuse-late');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['id' => $attendanceId, 'late_excused' => $excused, 'affected' => $stmt->rowCount()]]);
}

// Admin: reclassify an attendance row's status (Phase 2.1 — verbatim from CRM admin_reclassify).
// Caller computes the final status + builds the audit tag, and supplies actor_id.
if ($action === 'reclassify') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'Reclassify via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $attendanceId = (int)($body['attendance_id'] ?? 0);
    $status = strtoupper(trim((string)($body['status'] ?? '')));
    $audit  = trim((string)($body['audit'] ?? ''));
    $actorId = (int)($body['actor_id'] ?? 0);
    if ($attendanceId <= 0) ApiAuth::fail(400, 'attendance_id required');
    if (!in_array($status, ['PRESENT','ABSENT','LEAVE','HOLIDAY','LATE','WFH','HALF_DAY','PENDING'], true)) {
        ApiAuth::fail(400, 'invalid status');
    }
    try {
        // SQL byte-identical to the CRM direct path (parity by construction).
        $stmt = $pdo->prepare("UPDATE hr_attendances
            SET status=?,
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), ?),
                adjusted_by=?, adjusted_at=NOW(),
                approved_by=?, approved_at=NOW()
            WHERE id=?");
        $stmt->execute([$status, $audit, $actorId ?: null, $actorId ?: null, $attendanceId]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance reclassify');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['id' => $attendanceId, 'status' => $status, 'affected' => $stmt->rowCount()]]);
}

// Notify-late: upsert late-excused for a user+date (Phase 2.1 — from CRM notify_late /
// admin_plan-late excuse flow). Result-equivalent to the CRM find-then-insert/update.
if ($action === 'notify-late') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    $userId = apiKeyResolveScopedUserId($key, (int)($body['user_id'] ?? 0));
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'Notify-late via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $date   = trim((string)($body['date'] ?? ''));
    $reason = trim((string)($body['reason'] ?? ''));
    if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ApiAuth::fail(400, 'invalid date');
    try {
        // Upsert: insert PENDING+excused, or set excused/reason/notified_at on the existing row.
        $stmt = $pdo->prepare("INSERT INTO hr_attendances
                (user_id, attendance_date, status, late_excused, late_excused_reason, late_notified_at)
            VALUES (?, ?, 'PENDING', 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                late_excused = 1,
                late_excused_reason = VALUES(late_excused_reason),
                late_notified_at = NOW()");
        $stmt->execute([$userId, $date, $reason]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance notify-late');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['user_id' => $userId, 'attendance_date' => $date, 'late_excused' => 1]]);
}

// Manual-log: admin upsert of an attendance status for a user+date (Phase 2.1 — verbatim
// from CRM admin_create_log). Caller computes the final status + audit + actor_id.
if ($action === 'manual-log') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    $userId = apiKeyResolveScopedUserId($key, (int)($body['user_id'] ?? 0));
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'Manual-log via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $date = trim((string)($body['date'] ?? ''));
    $status = strtoupper(trim((string)($body['status'] ?? '')));
    $audit = trim((string)($body['audit'] ?? ''));
    $actorId = (int)($body['actor_id'] ?? 0);
    if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ApiAuth::fail(400, 'invalid date');
    if (!in_array($status, ['PRESENT','ABSENT','LEAVE','HOLIDAY','LATE','WFH','HALF_DAY','PENDING'], true)) {
        ApiAuth::fail(400, 'invalid status');
    }
    try {
        // SQL byte-identical to the CRM direct path (parity by construction).
        $stmt = $pdo->prepare("INSERT INTO hr_attendances
                (user_id, attendance_date, status, adjustment_reason, adjusted_by, adjusted_at, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW())
            ON DUPLICATE KEY UPDATE
                status=VALUES(status),
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                adjusted_by=VALUES(adjusted_by), adjusted_at=NOW(),
                approved_by=VALUES(approved_by), approved_at=NOW()");
        $stmt->execute([$userId, $date, $status, $audit, $actorId ?: null, $actorId ?: null]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance manual-log');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['user_id' => $userId, 'attendance_date' => $date, 'status' => $status]]);
}

// Plan-late: admin sets a planned (excused) late start for a user+date (Phase 2.1 — from CRM
// admin_plan_late). Caller validates business rules (no existing check-in, not a past date) +
// supplies shift_id + audit. Upsert is result-equivalent to the CRM insert/update branches.
if ($action === 'plan-late') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    $userId = apiKeyResolveScopedUserId($key, (int)($body['user_id'] ?? 0));
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'Plan-late via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $date = trim((string)($body['date'] ?? ''));
    $plannedStart = trim((string)($body['planned_start'] ?? ''));
    $reason = trim((string)($body['reason'] ?? ''));
    $plannedBy = (int)($body['planned_by'] ?? 0);
    $shiftId = isset($body['shift_id']) && $body['shift_id'] !== '' ? (int)$body['shift_id'] : null;
    $audit = trim((string)($body['audit'] ?? ''));
    if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ApiAuth::fail(400, 'invalid date');
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $plannedStart)) ApiAuth::fail(400, 'invalid planned_start (HH:MM:SS)');
    try {
        // Result-equivalent to the CRM insert-or-update branches (planned_* + audit).
        $stmt = $pdo->prepare("INSERT INTO hr_attendances
                (user_id, attendance_date, shift_id, planned_start_time, planned_reason,
                 planned_requested_at, planned_requested_by, adjustment_reason, adjusted_by, adjusted_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                planned_start_time=VALUES(planned_start_time),
                planned_reason=VALUES(planned_reason),
                planned_requested_at=NOW(),
                planned_requested_by=VALUES(planned_requested_by),
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                updated_at=NOW()");
        $stmt->execute([$userId, $date, $shiftId, $plannedStart, $reason, $plannedBy ?: null, $audit, $plannedBy ?: null]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance plan-late');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['user_id' => $userId, 'attendance_date' => $date, 'planned_start_time' => $plannedStart]]);
}

// Cancel-plan-late: clear a planned late start on an attendance row (Phase 2.1 — verbatim
// from CRM admin_cancel_planned_late). Caller validates (row exists, has planned, no check-in).
if ($action === 'cancel-plan-late') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'Cancel-plan-late via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $attendanceId = (int)($body['attendance_id'] ?? 0);
    $audit = trim((string)($body['audit'] ?? ''));
    if ($attendanceId <= 0) ApiAuth::fail(400, 'attendance_id required');
    try {
        // SQL byte-identical to the CRM direct path (parity by construction).
        $stmt = $pdo->prepare("UPDATE hr_attendances
            SET planned_start_time=NULL, planned_reason=NULL,
                planned_requested_at=NULL, planned_requested_by=NULL,
                adjustment_reason=CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), ?),
                updated_at=NOW()
            WHERE id=?");
        $stmt->execute([$audit, $attendanceId]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance cancel-plan-late');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['id' => $attendanceId, 'planned_start_time' => null, 'affected' => $stmt->rowCount()]]);
}

// CRM self-service web check-in (Phase 2.1 — verbatim from CRM check_in).
// CRM computes late_minutes/status/remarks; the endpoint persists. Upsert mirrors CRM's
// INSERT-new (sets remarks) vs UPDATE-existing (appends remarks) on the (user_id, date) key.
if ($action === 'crm-checkin') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'crm-checkin via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $uid = (int)($body['user_id'] ?? 0);
    $date = trim((string)($body['date'] ?? ''));
    $datetime = trim((string)($body['datetime'] ?? ''));
    $lat = $body['lat'] ?? null;
    $lng = $body['lng'] ?? null;
    $ip = $body['ip'] ?? null;
    $remarks = (string)($body['remarks'] ?? '');
    $lateMin = (int)($body['late_min'] ?? 0);
    $status = trim((string)($body['status'] ?? ''));
    if ($uid <= 0 || $date === '' || $datetime === '' || $status === '') ApiAuth::fail(400, 'user_id, date, datetime, status required');
    try {
        $stmt = $pdo->prepare("INSERT INTO hr_attendances
            (user_id, attendance_date, check_in_time, check_in_type, check_in_latitude, check_in_longitude, check_in_ip, remarks, late_minutes, status)
            VALUES (?, ?, ?, 'MANUAL', ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                check_in_time=VALUES(check_in_time), check_in_type='MANUAL',
                check_in_latitude=VALUES(check_in_latitude), check_in_longitude=VALUES(check_in_longitude), check_in_ip=VALUES(check_in_ip),
                remarks=CONCAT_WS(' | ', NULLIF(remarks,''), VALUES(remarks)),
                late_minutes=VALUES(late_minutes), status=VALUES(status)");
        $stmt->execute([$uid, $date, $datetime, $lat, $lng, $ip, $remarks, $lateMin, $status]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance crm-checkin');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['user_id' => $uid, 'date' => $date, 'late_minutes' => $lateMin, 'status' => $status]]);
}

// CRM self-service web check-out (Phase 2.1 — verbatim from CRM check_out).
// CRM computes work_minutes + the "[checkout] ..." remark; the endpoint persists by row id.
if ($action === 'crm-checkout') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'crm-checkout via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $attendanceId = (int)($body['attendance_id'] ?? 0);
    $datetime = trim((string)($body['datetime'] ?? ''));
    $ip = $body['ip'] ?? null;
    $checkoutRemark = $body['checkout_remark'] ?? null;
    if ($checkoutRemark === '') $checkoutRemark = null;
    $workMin = (int)($body['work_min'] ?? 0);
    if ($attendanceId <= 0 || $datetime === '') ApiAuth::fail(400, 'attendance_id, datetime required');
    try {
        $stmt = $pdo->prepare("UPDATE hr_attendances
            SET check_out_time=?, check_out_type='MANUAL', check_out_ip=?,
                remarks=CONCAT_WS(' | ', NULLIF(remarks,''), ?),
                work_minutes=?
            WHERE id=?");
        $stmt->execute([$datetime, $ip, $checkoutRemark, $workMin, $attendanceId]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance crm-checkout');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['id' => $attendanceId, 'work_minutes' => $workMin, 'affected' => $stmt->rowCount()]]);
}

if (!in_array($action, ['checkin', 'checkout'], true)) ApiAuth::fail(404, 'Unknown action');

$body = ApiAuth::input();
$key = ApiAuth::currentKey();
apiKeyRequireServiceUserOrReadAllScope(
    $key,
    'attendance.write_all',
    'Check-in/out for users via API requires attendance.write_all (or *) or a service user bound to the API key'
);
$userId = apiKeyResolveScopedUserId($key, (int)($body['user_id'] ?? 0));
$time   = trim($body['time'] ?? '');
$type   = strtoupper(trim($body['type'] ?? 'MANUAL'));
$lat    = isset($body['latitude']) ? (float)$body['latitude'] : null;
$lng    = isset($body['longitude']) ? (float)$body['longitude'] : null;
$locId  = isset($body['location_id']) ? (int)$body['location_id'] : null;
$remarks = trim($body['remarks'] ?? '');

if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
if (!in_array($type, ['GPS','WIFI','QR','FACE','FINGERPRINT','MANUAL'], true)) ApiAuth::fail(400, 'type invalid');

if ($time === '') {
    $tsNow = time();
    $time = date('Y-m-d H:i:s', $tsNow);
} else {
    $tsNow = strtotime($time);
    if (!$tsNow) ApiAuth::fail(400, 'time invalid (YYYY-MM-DD HH:MM:SS)');
    $time = date('Y-m-d H:i:s', $tsNow);
}
$date = date('Y-m-d', $tsNow);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $attendanceService = new AttendanceService($pdo);
    $targetUser = $attendanceService->getUserForAttendance($userId);
    if (!$targetUser) {
        ApiAuth::fail(404, 'User not found or inactive');
    }

    $pdo->beginTransaction();
    $find = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE");
    $find->execute([$userId, $date]);
    $row = $find->fetch(PDO::FETCH_ASSOC);

    if ($action === 'checkin') {
        if ($row && !empty($row['check_in_time'])) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'Already checked in today');
        }
        $shift = $row && !empty($row['shift_id'])
            ? $attendanceService->getShiftById((int)$row['shift_id'])
            : $attendanceService->getDefaultShift();
        $checkInSummary = $attendanceService->determineCheckIn(
            $targetUser,
            $shift,
            $time,
            $row['planned_start_time'] ?? null
        );
        $checkinStatus = (string)$checkInSummary['status'];
        $lateMinutes = (int)$checkInSummary['late_minutes'];

        if ($row) {
            $pdo->prepare("
                UPDATE hr_attendances
                SET check_in_time=?, check_in_type=?, check_in_latitude=?, check_in_longitude=?,
                    check_in_location_id=?, check_in_ip=?, late_minutes=?, status=?,
                    remarks = COALESCE(NULLIF(?, ''), remarks)
                WHERE id=?
            ")->execute([$time, $type, $lat, $lng, $locId, $ip, $lateMinutes, $checkinStatus, $remarks, (int)$row['id']]);
            $newId = (int)$row['id'];
        } else {
            $pdo->prepare("
                INSERT INTO hr_attendances
                    (user_id, attendance_date, shift_id, check_in_time, check_in_type,
                     check_in_latitude, check_in_longitude, check_in_location_id, check_in_ip,
                     late_minutes, status, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$userId, $date, $shift['id'] ?? null, $time, $type, $lat, $lng, $locId, $ip, $lateMinutes, $checkinStatus, $remarks ?: null]);
            $newId = (int)$pdo->lastInsertId();
        }
    } else { // checkout
        if (!$row) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'No check-in found for today');
        }
        if (!empty($row['check_out_time'])) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'Already checked out today');
        }
        $shift = $attendanceService->getShiftById((int)($row['shift_id'] ?? 0));
        $workSummary = $attendanceService->summarizeWork($row['check_in_time'] ?? null, $time, $shift, $date);
        $pdo->prepare("
            UPDATE hr_attendances
            SET check_out_time=?, check_out_type=?, check_out_latitude=?, check_out_longitude=?,
                check_out_location_id=?, check_out_ip=?, work_minutes=?, break_minutes=?,
                ot_minutes=?, early_leave_minutes=?,
                remarks = COALESCE(NULLIF(?, ''), remarks)
            WHERE id=?
        ")->execute([
            $time, $type, $lat, $lng, $locId, $ip,
            (int)$workSummary['work_minutes'],
            (int)$workSummary['break_minutes'],
            (int)$workSummary['ot_minutes'],
            (int)$workSummary['early_leave_minutes'],
            $remarks,
            (int)$row['id'],
        ]);
        $newId = (int)$row['id'];
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    tpHrLogException($e, 'api/v1/attendance');
    ApiAuth::fail(500, 'Internal server error');
}

ApiAuth::success(['data' => ['id' => $newId, 'user_id' => $userId, 'attendance_date' => $date, 'action' => $action, 'time' => $time]], 201);
