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

// POST checkin/checkout
ApiAuth::require(['attendance.write']);
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
