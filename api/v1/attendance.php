<?php
/**
 * Attendance
 *
 *   GET  /api/v1/attendance?date=YYYY-MM-DD[&user_id=]         scope: attendance.read (+ attendance.read_all if key has no service_user_id)
 *   GET  /api/v1/attendance?from=&to=[&user_id=]               scope: เช่นเดียวกัน
 *   POST /api/v1/attendance/checkin                            scope: attendance.write (+ attendance.write_all if key has no service_user_id)
 *        body: { user_id, latitude, longitude, gps_captured_at, accuracy?, location_id, photo_path, device_info?, remarks? }; time is server-owned
 *   POST /api/v1/attendance/checkout                           scope: เช่นเดียวกัน
 *        body: { user_id, latitude, longitude, gps_captured_at, accuracy?, location_id, photo_path, device_info?, remarks? }; time is server-owned
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
               a.status, a.is_offsite, a.offsite_status,
               a.check_in_outside_status, a.check_outside_status,
               a.adjustment_reason
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

// Retired unused legacy auto-approval and unscoped cancellation routes.
if ($action === 'plan-late') ApiAuth::fail(410, 'Legacy planned-late writes are retired; use the HR request and approval workflow');

if ($action === 'cancel-plan-late') ApiAuth::fail(410, 'Legacy planned-late writes are retired; use the HR request and approval workflow');

// Retired unchecked CRM capture routes. Check-in owns employee GPS/photo/approval capture.
if (in_array($action, ['crm-checkin', 'crm-checkout'], true)) {
    ApiAuth::fail(410, 'Legacy attendance capture retired; use https://checkin.tp-asset.com/');
}

// Auto-absent cron mark (Phase 2.1 op #12 — verbatim from CRM cron_hr_auto_absent).
// CASE upsert preserves already-set statuses / excused / planned-late rows; only blanks → ABSENT.
if ($action === 'auto-absent') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'auto-absent via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $uid = (int)($body['user_id'] ?? 0);
    $date = trim((string)($body['date'] ?? ''));
    $audit = trim((string)($body['audit'] ?? ''));
    if ($uid <= 0 || $date === '') ApiAuth::fail(400, 'user_id, date required');
    if (tp_hr_user_is_attendance_exempt($pdo, $uid)) ApiAuth::fail(403, 'User is exempt from attendance');
    try {
        $stmt = $pdo->prepare("INSERT INTO hr_attendances (user_id, attendance_date, status, adjustment_reason, adjusted_by, adjusted_at)
            VALUES (?, ?, 'ABSENT', ?, NULL, NOW())
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN status IN ('PRESENT','LATE','WFH','HALF_DAY','LEAVE','HOLIDAY') THEN status
                    WHEN late_excused = 1 THEN status
                    WHEN planned_status = 'APPROVED' AND planned_start_time IS NOT NULL AND planned_start_time <> '' THEN status
                    ELSE 'ABSENT' END,
                adjustment_reason = CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
                adjusted_at = NOW()");
        $stmt->execute([$uid, $date, $audit]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance auto-absent');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['user_id' => $uid, 'date' => $date, 'affected' => $stmt->rowCount()]]);
}

// Recompute-late backfill (Phase 2.1 op #13 — verbatim from CRM audit_attendance_late_minutes).
// Sets late_minutes + flips PRESENT/WFH/HALF_DAY↔LATE per the recomputed value. Caller computes.
if ($action === 'recompute-late') {
    $body = ApiAuth::input();
    $key = ApiAuth::currentKey();
    apiKeyRequireServiceUserOrReadAllScope(
        $key,
        'attendance.write_all',
        'recompute-late via API requires attendance.write_all (or *) or a service user bound to the API key'
    );
    $attendanceId = (int)($body['attendance_id'] ?? 0);
    $lateMinutes = (int)($body['late_minutes'] ?? 0);
    if ($attendanceId <= 0) ApiAuth::fail(400, 'attendance_id required');
    try {
        $stmt = $pdo->prepare("UPDATE hr_attendances SET late_minutes = ?, status = CASE
            WHEN ? > 0 AND status IN ('PRESENT','WFH','HALF_DAY') THEN 'LATE'
            WHEN ? = 0 AND status = 'LATE' THEN 'PRESENT'
            ELSE status END
          WHERE id = ?");
        $stmt->execute([$lateMinutes, $lateMinutes, $lateMinutes, $attendanceId]);
    } catch (Throwable $e) {
        tpHrLogException($e, 'api/v1/attendance recompute-late');
        ApiAuth::fail(500, 'Internal server error');
    }
    ApiAuth::success(['data' => ['id' => $attendanceId, 'late_minutes' => $lateMinutes, 'affected' => $stmt->rowCount()]]);
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
require_once dirname(__DIR__, 2) . '/core/Services/AttendanceCapturePolicy.php';
require_once dirname(__DIR__, 2) . '/core/Services/ClockHealth.php';
try {
    ClockHealth::assertSafe();
    $gps = AttendanceCapturePolicy::validateGps($body);
    $time = AttendanceCapturePolicy::normalCaptureTime($body);
    $photoPath = AttendanceCapturePolicy::validatePhotoPath((string)($body['photo_path'] ?? ''), $userId, $action === 'checkin' ? 'in' : 'out');
} catch (DomainException $e) {
    ApiAuth::fail($e->getCode() ?: 422, $e->getMessage());
}
$tsNow = strtotime($time);
$type = 'GPS';
$lat = $gps['latitude']; $lng = $gps['longitude'];
$locId = !empty($body['location_id']) ? (int)$body['location_id'] : null;
$deviceInfo = is_string($body['device_info'] ?? null) ? $body['device_info'] : '';
$remarks = trim((string)($body['remarks'] ?? ''));
if ($userId <= 0) ApiAuth::fail(400, 'user_id required');
$date = date('Y-m-d', $tsNow);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $attendanceService = new AttendanceService($pdo);
    $targetUser = $attendanceService->getUserForAttendance($userId);
    if (!$targetUser) {
        ApiAuth::fail(404, 'User not found or inactive');
    }
    if (tp_hr_is_attendance_exempt($targetUser)) {
        ApiAuth::fail(403, 'User is exempt from attendance');
    }

    $pdo->beginTransaction();

    $enforceLocation = filter_var((new SettingsService($pdo))->get('enforce_location_checkin', true), FILTER_VALIDATE_BOOLEAN);
    AttendanceCapturePolicy::assertLocation($pdo, $gps, $locId, $targetUser, $enforceLocation);
    // A location lock wait must not turn a formerly fresh GPS fix into accepted stale evidence.
    $gps = AttendanceCapturePolicy::validateGps($body);
    $find = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE");
    $find->execute([$userId, $date]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    ClockHealth::assertSafe();
    $gps = AttendanceCapturePolicy::validateGps($body);

    if ($action === 'checkin') {
        if ($row && !empty($row['check_in_time'])) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'Already checked in today');
        }
        if (!empty($row['check_out_time'])) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'Checkout already recorded; request an HR attendance correction');
        }
        $shift = $row && !empty($row['shift_id'])
            ? $attendanceService->getShiftById((int)$row['shift_id'])
            : $attendanceService->getDefaultShift();
        $checkInSummary = $attendanceService->determineCheckIn(
            $targetUser,
            $shift,
            $time,
            (($row['planned_status'] ?? null) === 'APPROVED') ? ($row['planned_start_time'] ?? null) : null
        );
        $checkinStatus = (string)$checkInSummary['status'];
        $lateMinutes = (int)$checkInSummary['late_minutes'];

        if ($row) {
            $pdo->prepare("
                UPDATE hr_attendances
                SET check_in_time=?, check_in_type=?, check_in_latitude=?, check_in_longitude=?,
                    check_in_location_id=?, check_in_ip=?, late_minutes=?, status=?,
                    offsite_status=check_outside_status, check_in_outside_status=NULL,
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
        if (!$row || empty($row['check_in_time'])) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'No check-in found for today');
        }
        if (!empty($row['check_out_time'])) {
            $pdo->rollBack();
            ApiAuth::fail(409, 'Already checked out today');
        }
        $shift = $attendanceService->getShiftById((int)($row['shift_id'] ?? 0));
        $workSummary = $attendanceService->summarizeRecordedWork(array_replace($row, ['check_out_time' => $time, 'check_outside_status' => null]), $shift);
        $pdo->prepare("
            UPDATE hr_attendances
            SET check_out_time=?, check_out_type=?, check_out_latitude=?, check_out_longitude=?,
                check_out_location_id=?, check_out_ip=?, work_minutes=?, break_minutes=?,
                ot_minutes=?, early_leave_minutes=?,
                offsite_status=CASE WHEN check_in_outside_status IS NOT NULL THEN check_in_outside_status WHEN check_outside_status='REJECTED' THEN NULL ELSE offsite_status END,
                check_outside_status=NULL,
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
    AttendanceCapturePolicy::persistMetadata($pdo, $newId, $action === 'checkin' ? 'in' : 'out', $gps, $photoPath, $deviceInfo, $time);
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ApiAuth::fail($e->getCode() ?: 422, $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    tpHrLogException($e, 'api/v1/attendance');
    ApiAuth::fail(500, 'Internal server error');
}

ApiAuth::success(['data' => ['id' => $newId, 'user_id' => $userId, 'attendance_date' => $date, 'action' => $action, 'time' => $time]], 201);
