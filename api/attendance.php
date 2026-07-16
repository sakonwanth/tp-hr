<?php
/**
 * TP-HR Attendance API
 * API สำหรับลงเวลาเข้า-ออกงาน
 */

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    apiError('Unauthorized', 401);
}

$pdo = getDB();
$user = Auth::user();
$method = $_SERVER['REQUEST_METHOD'];

// Handle requests
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        $input = [];
    }
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }

    $csrfVal = trim((string)($input['_token'] ?? $input['csrf_token'] ?? ''));
    if ($csrfVal === '') {
        $csrfVal = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    }
    if (!verifyCsrfToken($csrfVal !== '' ? $csrfVal : null)) {
        apiError('Invalid token', 403);
    }

    $action = $input['action'] ?? '';

    if (in_array($action, ['check_in', 'check_out', 'request_late_start'], true) && tp_hr_is_attendance_exempt($user)) {
        apiError('ตำแหน่งของคุณได้รับการยกเว้น ไม่จำเป็นต้องลงเวลาเข้า-ออกงาน', 403);
    }

    switch ($action) {
        case 'check_in':
            handleCheckIn($pdo, $user, $input);
            break;
            
        case 'check_out':
            handleCheckOut($pdo, $user, $input);
            break;
            
        case 'adjust':
            handleAdjust($pdo, $user, $input);
            break;

        case 'bulk_adjust':
            handleBulkAdjust($pdo, $user, $input);
            break;
            
        case 'delete':
            handleDelete($pdo, $user, $input);
            break;

        case 'clear_times':
            handleClearTimes($pdo, $user, $input);
            break;

        case 'request_late_start':
            handleLateStartRequest($pdo, $user, $input);
            break;

        case 'cancel_late_start':
            cancelLateStartRequest($pdo, $user, $input);
            break;

        default:
            apiError('Invalid action');
    }
} elseif ($method === 'GET') {
    $action = $_GET['action'] ?? 'today';
    
    switch ($action) {
        case 'today':
            getTodayAttendance($pdo, $user);
            break;
            
        case 'history':
            getAttendanceHistory($pdo, $user);
            break;
            
        case 'monthly':
            getMonthlyReport($pdo, $user);
            break;
            
        case 'adjustment_history':
            getAdjustmentHistory($pdo, $user);
            break;
            
        default:
            apiError('Invalid action');
    }
} else {
    apiError('Method not allowed', 405);
}

/**
 * Handle Check-in
 *
 * Mirror of tp-checkin/api/attendance.php::handleCheckIn — รองรับ:
 * - Planned late-start (S4): ถ้ามี planned_start_time ใช้เป็น baseline แทน shift.start_time
 * - WFH: force status='WFH', skip location enforcement
 * - Off-site workflow: outside_reason → hr_attendance_outside_requests (PENDING)
 */
function handleCheckIn(PDO $pdo, array $user, array $input): void {
    $latitude      = $input['latitude']  ?? null;
    $longitude     = $input['longitude'] ?? null;
    $photo         = $input['photo']     ?? null;
    $outsideReason = trim((string)($input['outside_reason'] ?? $input['offsite_reason'] ?? ''));

    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
    $stmt->execute([$user['id']]);
    $existing = $stmt->fetch();

    if ($existing && $existing['check_in_time']) {
        apiError('คุณได้ลงเวลาเข้างานวันนี้แล้ว');
    }

    $attendanceService = new AttendanceService($pdo);
    $shift = $attendanceService->getDefaultShift();
    $checkInAt = date('Y-m-d H:i:s');
    $checkInSummary = $attendanceService->determineCheckIn($user, $shift, $checkInAt, $existing['planned_start_time'] ?? null);
    $lateMinutes = (int)$checkInSummary['late_minutes'];
    $status = (string)$checkInSummary['status'];

    // Location enforcement (HR settings)
    $enforceLocation = getHrBoolSetting($pdo, 'enforce_location_checkin', true);
    $outsideNeedsApproval = getHrBoolSetting($pdo, 'outside_location_requires_approval', true);
    if (($user['work_mode'] ?? 'OFFICE') === 'WFH') {
        $enforceLocation = false;
    }

    $locationId = null;
    if ($enforceLocation) {
        if (empty($latitude) || empty($longitude)) {
            apiError('กรุณาเปิด GPS เพื่อระบุตำแหน่ง');
        }
        $locationId = validateLocation($pdo, (float)$latitude, (float)$longitude);
        if ($locationId === null) {
            if ($outsideNeedsApproval) {
                if (mb_strlen($outsideReason) < 5) {
                    header('Content-Type: application/json');
                    http_response_code(200);
                    echo json_encode([
                        'success' => false,
                        'error'   => 'คุณไม่ได้อยู่ในพื้นที่ที่อนุญาตให้ลงเวลา กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร',
                        'data'    => ['requires_outside_reason' => true, 'request_type' => 'CHECK_IN'],
                    ]);
                    exit;
                }
                $pending = createOutsideLocationRequest($pdo, $user, 'CHECK_IN', null, (float)$latitude, (float)$longitude, $photo, $outsideReason);
                apiSuccess([
                    'pending_approval' => true,
                    'request_id'       => $pending['request_id'],
                    'attendance_id'    => $pending['attendance_id'],
                    'check_in_time'    => date('H:i:s'),
                ], 'ส่งคำขอลงเวลาเข้างานนอกสถานที่เรียบร้อยแล้ว รอผู้อนุมัติ');
            }
            apiError('คุณไม่ได้อยู่ในพื้นที่ที่อนุญาตให้ลงเวลา กรุณาตรวจสอบตำแหน่งของคุณ');
        }
    } elseif ($latitude && $longitude) {
        $locationId = validateLocation($pdo, (float)$latitude, (float)$longitude);
    }

    $photoPath = null;
    if ($photo) {
        $photoPath = savePhoto($photo, $user['id'], 'checkin');
    }

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE hr_attendances SET
                check_in_time = ?,
                check_in_type = 'GPS',
                check_in_latitude = ?,
                check_in_longitude = ?,
                check_in_location_id = ?,
                check_in_photo = ?,
                check_in_ip = ?,
                late_minutes = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$checkInAt, $latitude, $longitude, $locationId, $photoPath, $_SERVER['REMOTE_ADDR'] ?? '', $lateMinutes, $status, $existing['id']]);
        $attId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO hr_attendances (
                user_id, attendance_date, shift_id,
                check_in_time, check_in_type, check_in_latitude, check_in_longitude,
                check_in_location_id, check_in_photo, check_in_ip,
                late_minutes, status
            ) VALUES (?, CURDATE(), ?, ?, 'GPS', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $shift['id'] ?? null, $checkInAt, $latitude, $longitude, $locationId, $photoPath, $_SERVER['REMOTE_ADDR'] ?? '', $lateMinutes, $status]);
        $attId = (int)$pdo->lastInsertId();
    }

    Auth::log('CHECK_IN', 'hr_attendances', $attId);

    apiSuccess([
        'check_in_time' => date('H:i:s', strtotime($checkInAt)),
        'late_minutes'  => $lateMinutes,
        'status'        => $status,
        'attendance_id' => $attId,
    ], 'ลงเวลาเข้างานสำเร็จ');
}

/**
 * Read boolean setting through the shared settings compatibility layer.
 */
function getHrBoolSetting(PDO $pdo, string $key, bool $default = true): bool {
    $value = (new SettingsService($pdo))->get($key, $default);
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Create pending outside-location request (mirror ของ tp-checkin)
 */
function createOutsideLocationRequest(
    PDO $pdo,
    array $user,
    string $requestType,
    ?int $attendanceId,
    ?float $latitude,
    ?float $longitude,
    ?string $photo,
    string $reason
): array {
    $requestDate = date('Y-m-d');
    $requestTime = date('Y-m-d H:i:s');

    if ($requestType === 'CHECK_OUT' && $attendanceId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hr_attendance_outside_requests WHERE attendance_id = ? AND request_type = ? AND status = 'PENDING'");
        $stmt->execute([$attendanceId, $requestType]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hr_attendance_outside_requests WHERE user_id = ? AND request_type = ? AND request_date = ? AND status = 'PENDING'");
        $stmt->execute([$user['id'], $requestType, $requestDate]);
    }
    if ((int)$stmt->fetchColumn() > 0) {
        apiError('มีคำขอที่รออนุมัติอยู่แล้วสำหรับรายการนี้');
    }

    $photoPath = null;
    if ($photo) {
        $photoPath = savePhoto($photo, $user['id'], strtolower($requestType) . '_outside');
    }

    $pdo->beginTransaction();
    try {
    $stmt = $pdo->prepare("
        INSERT INTO hr_attendance_outside_requests (
            user_id, attendance_id, request_type,
            request_date, request_time,
            latitude, longitude,
            photo_path, reason,
            status, request_ip
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)
    ");
    $stmt->execute([
        $user['id'], $attendanceId, $requestType,
        $requestDate, $requestTime,
        $latitude, $longitude,
        $photoPath, $reason,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $requestId = (int)$pdo->lastInsertId();
    $attendanceId = stampPendingOutsideAttendance(
        $pdo, $user, $requestType, $attendanceId, $requestDate, $requestTime,
        $latitude, $longitude, $photoPath, $reason
    );
    $pdo->prepare("UPDATE hr_attendance_outside_requests SET attendance_id = ? WHERE id = ?")
        ->execute([$attendanceId, $requestId]);
    $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            apiError('มีคำขอที่รออนุมัติอยู่แล้วสำหรับรายการนี้', 409);
        }
        throw $e;
    }
    Auth::log('OUTSIDE_LOCATION_REQUEST', 'hr_attendance_outside_requests', $requestId, [
        'request_type'  => $requestType,
        'request_date'  => $requestDate,
        'attendance_id' => $attendanceId,
    ]);

    if (function_exists('crm_line_notify_outside_attendance_requested')) {
        crm_line_notify_outside_attendance_requested($pdo, $requestId);
    }

    return ['request_id' => $requestId, 'attendance_id' => $attendanceId, 'photo_path' => $photoPath];
}

/** Stamp the captured request time immediately; approval only validates that stamp later. */
function stampPendingOutsideAttendance(
    PDO $pdo, array $user, string $requestType, ?int $attendanceId,
    string $requestDate, string $requestTime, ?float $latitude, ?float $longitude,
    ?string $photoPath, string $reason
): int {
    $requestType = strtoupper($requestType);
    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([(int)$user['id'], $requestDate]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($requestType === 'CHECK_IN') {
        if ($attendance && !empty($attendance['check_in_time'])) apiError('คุณได้ลงเวลาเข้างานวันนี้แล้ว', 409);
        if ($attendance) {
            $pdo->prepare("UPDATE hr_attendances SET check_in_time=?, check_in_type='GPS', check_in_latitude=?, check_in_longitude=?, check_in_location_id=NULL, check_in_photo=?, check_in_ip=?, is_offsite=1, offsite_status='PENDING', check_in_outside_status='PENDING', offsite_reason=?, status='PENDING', updated_at=NOW() WHERE id=?")
                ->execute([$requestTime, $latitude, $longitude, $photoPath, $_SERVER['REMOTE_ADDR'] ?? '', $reason, (int)$attendance['id']]);
            return (int)$attendance['id'];
        }
        $shift = $pdo->query("SELECT id FROM hr_work_shifts WHERE is_default=1 AND is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO hr_attendances (user_id,attendance_date,shift_id,check_in_time,check_in_type,check_in_latitude,check_in_longitude,check_in_photo,check_in_ip,is_offsite,offsite_status,check_in_outside_status,offsite_reason,status) VALUES (?,?,?,?,'GPS',?,?,?,?,1,'PENDING','PENDING',?,'PENDING')")
            ->execute([(int)$user['id'], $requestDate, $shift['id'] ?? null, $requestTime, $latitude, $longitude, $photoPath, $_SERVER['REMOTE_ADDR'] ?? '', $reason]);
        return (int)$pdo->lastInsertId();
    }

    if (!$attendance || empty($attendance['check_in_time'])) apiError('คุณยังไม่ได้ลงเวลาเข้างานวันนี้', 409);
    if (!empty($attendance['check_out_time'])) apiError('คุณได้ลงเวลาออกงานวันนี้แล้ว', 409);
    $pdo->prepare("UPDATE hr_attendances SET check_out_time=?, check_out_type='GPS', check_out_latitude=?, check_out_longitude=?, check_out_location_id=NULL, check_out_photo=?, check_out_ip=?, is_offsite=1, offsite_status='PENDING', check_outside_status='PENDING', offsite_reason=?, updated_at=NOW() WHERE id=?")
        ->execute([$requestTime, $latitude, $longitude, $photoPath, $_SERVER['REMOTE_ADDR'] ?? '', $reason, (int)$attendance['id']]);
    return (int)$attendance['id'];
}

/**
 * Handle Check-out
 */
function handleCheckOut(PDO $pdo, array $user, array $input): void {
    $latitude      = $input['latitude']  ?? null;
    $longitude     = $input['longitude'] ?? null;
    $photo         = $input['photo']     ?? null;
    $outsideReason = trim((string)($input['outside_reason'] ?? $input['offsite_reason'] ?? ''));

    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
    $stmt->execute([$user['id']]);
    $attendance = $stmt->fetch();

    if (!$attendance || !$attendance['check_in_time']) {
        apiError('คุณยังไม่ได้ลงเวลาเข้างานวันนี้');
    }
    $pendingCheckIn = $pdo->prepare("SELECT 1 FROM hr_attendance_outside_requests WHERE user_id=? AND request_date=CURDATE() AND request_type='CHECK_IN' AND status='PENDING' LIMIT 1");
    $pendingCheckIn->execute([(int)$user['id']]);
    if ($pendingCheckIn->fetchColumn()) {
        apiError('คำขอลงเวลาเข้างานนอกสถานที่ยังรออนุมัติ จึงยังลงเวลาออกไม่ได้', 409);
    }
    if ($attendance['check_out_time']) {
        apiError('คุณได้ลงเวลาออกงานวันนี้แล้ว');
    }

    $attendanceService = new AttendanceService($pdo);
    $shift = $attendanceService->getShiftById((int)($attendance['shift_id'] ?? 0));
    $checkOutAt = date('Y-m-d H:i:s');
    $workSummary = $attendanceService->summarizeWork(
        $attendance['check_in_time'],
        $checkOutAt,
        $shift,
        (string)$attendance['attendance_date']
    );
    $workMinutes = (int)$workSummary['work_minutes'];
    $breakMinutes = (int)$workSummary['break_minutes'];
    $otMinutes = (int)$workSummary['ot_minutes'];
    $earlyLeaveMinutes = (int)$workSummary['early_leave_minutes'];

    // Location enforcement — mirror ของ handleCheckIn
    $enforceLocation      = getHrBoolSetting($pdo, 'enforce_location_checkin', true);
    $outsideNeedsApproval = getHrBoolSetting($pdo, 'outside_location_requires_approval', true);
    if (($user['work_mode'] ?? 'OFFICE') === 'WFH') {
        $enforceLocation = false;
    }

    $locationId = null;
    if ($enforceLocation) {
        if (empty($latitude) || empty($longitude)) {
            apiError('กรุณาเปิด GPS เพื่อระบุตำแหน่ง');
        }
        $locationId = validateLocation($pdo, (float)$latitude, (float)$longitude);
        if ($locationId === null) {
            if ($outsideNeedsApproval) {
                if (mb_strlen($outsideReason) < 5) {
                    header('Content-Type: application/json');
                    http_response_code(200);
                    echo json_encode([
                        'success' => false,
                        'error'   => 'คุณไม่ได้อยู่ในพื้นที่ที่อนุญาตให้ลงเวลา กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร',
                        'data'    => ['requires_outside_reason' => true, 'request_type' => 'CHECK_OUT'],
                    ]);
                    exit;
                }
                $pending = createOutsideLocationRequest($pdo, $user, 'CHECK_OUT', (int)$attendance['id'], (float)$latitude, (float)$longitude, $photo, $outsideReason);
                apiSuccess([
                    'pending_approval' => true,
                    'request_id'       => $pending['request_id'],
                    'attendance_id'    => $pending['attendance_id'],
                    'check_out_time'   => date('H:i:s'),
                ], 'ส่งคำขอลงเวลาออกงานนอกสถานที่เรียบร้อยแล้ว รอผู้อนุมัติ');
            }
            apiError('คุณไม่ได้อยู่ในพื้นที่ที่อนุญาตให้ลงเวลา กรุณาตรวจสอบตำแหน่งของคุณ');
        }
    } elseif ($latitude && $longitude) {
        $locationId = validateLocation($pdo, (float)$latitude, (float)$longitude);
    }

    $photoPath = null;
    if ($photo) {
        $photoPath = savePhoto($photo, $user['id'], 'checkout');
    }
    
    // Update attendance record
    $stmt = $pdo->prepare("
        UPDATE hr_attendances SET
            check_out_time = ?,
            check_out_type = 'GPS',
            check_out_latitude = ?,
            check_out_longitude = ?,
            check_out_location_id = ?,
            check_out_photo = ?,
            check_out_ip = ?,
            work_minutes = ?,
            break_minutes = ?,
            ot_minutes = ?,
            early_leave_minutes = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $checkOutAt,
        $latitude,
        $longitude,
        $locationId,
        $photoPath,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $workMinutes,
        $breakMinutes,
        $otMinutes,
        $earlyLeaveMinutes,
        $attendance['id']
    ]);
    
    // Log action
    Auth::log('CHECK_OUT', 'hr_attendances', $attendance['id']);
    
    apiSuccess([
        'check_out_time' => date('H:i:s', strtotime($checkOutAt)),
        'work_minutes' => $workMinutes,
        'ot_minutes' => $otMinutes
    ], 'ลงเวลาออกงานสำเร็จ');
}

/**
 * Validate check-in location
 */
function validateLocation(PDO $pdo, float $latitude, float $longitude): ?int {
    $stmt = $pdo->query("SELECT * FROM hr_checkin_locations WHERE is_active = 1 AND latitude IS NOT NULL");
    $locations = $stmt->fetchAll();
    
    foreach ($locations as $loc) {
        $distance = calculateDistance($latitude, $longitude, $loc['latitude'], $loc['longitude']);
        if ($distance <= $loc['radius_meters']) {
            return $loc['id'];
        }
    }
    
    return null;
}

/**
 * Save photo to storage (ต้องเป็นภาพที่ GD อ่านได้ — ไม่บันทึก raw ที่ไม่ใช่รูป)
 */
function savePhoto(string $base64Data, int $userId, string $type): string {
    if (preg_match('/^data:image\/(\w+);base64,/i', $base64Data, $matches)) {
        $declared = strtolower($matches[1]);
        if (!in_array($declared, ['jpeg', 'jpg', 'png', 'gif', 'webp'], true)) {
            return '';
        }
        $base64Data = preg_replace('/^data:image\/\w+;base64,/i', '', $base64Data, 1);
    }

    $data = base64_decode($base64Data, true);
    if ($data === false || $data === '') {
        return '';
    }
    if (strlen($data) > MAX_UPLOAD_SIZE) {
        return '';
    }

    $image = @imagecreatefromstring($data);
    if ($image === false) {
        return '';
    }

    $dir = STORAGE_PATH . '/uploads/attendance/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'checkin';
    $filename = sprintf('%s_%d_%s_%s.jpg', $type, $userId, date('Ymd_His'), bin2hex(random_bytes(3)));
    $path = $dir . '/' . $filename;

    $maxWidth = 800;
    $quality = 80;
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int) ($height * ($maxWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        imagejpeg($resized, $path, $quality);
        imagedestroy($resized);
    } else {
        imagejpeg($image, $path, $quality);
        imagedestroy($image);
    }
    @chmod($path, 0644);

    return 'uploads/attendance/' . date('Y/m') . '/' . $filename;
}

/**
 * Get today's attendance
 */
function getTodayAttendance(PDO $pdo, array $user): void {
    $stmt = $pdo->prepare("
        SELECT a.*, s.name as shift_name, s.start_time as shift_start, s.end_time as shift_end
        FROM hr_attendances a
        LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
        WHERE a.user_id = ? AND a.attendance_date = CURDATE()
    ");
    $stmt->execute([$user['id']]);
    $attendance = $stmt->fetch();

    if ($attendance && function_exists('shift_display_label')) {
        $attendance['shift_label'] = shift_display_label($attendance);
    }

    apiSuccess(['attendance' => $attendance]);
}

/**
 * Get attendance history
 */
function getAttendanceHistory(PDO $pdo, array $user): void {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? DEFAULT_PER_PAGE);
    $offset = ($page - 1) * $limit;
    
    $month = $_GET['month'] ?? date('Y-m');
    
    $stmt = $pdo->prepare("
        SELECT a.*, s.name as shift_name, s.start_time as shift_start, s.end_time as shift_end
        FROM hr_attendances a
        LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
        WHERE a.user_id = ? AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
        ORDER BY a.attendance_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user['id'], $month, $limit, $offset]);
    $attendances = $stmt->fetchAll();

    if (function_exists('shift_display_label')) {
        foreach ($attendances as &$att) {
            $att['shift_label'] = shift_display_label($att);
        }
        unset($att);
    }

    // Get total count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM hr_attendances 
        WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
    ");
    $stmt->execute([$user['id'], $month]);
    $total = $stmt->fetchColumn();
    
    apiSuccess([
        'attendances' => $attendances,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

/**
 * Get monthly report
 */
function getMonthlyReport(PDO $pdo, array $user): void {
    $month = $_GET['month'] ?? date('Y-m');
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status IN ('PRESENT', 'LATE') THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'LATE' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'ABSENT' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status = 'LEAVE' THEN 1 ELSE 0 END) as leave_days,
            SUM(COALESCE(work_minutes, 0)) as total_work_minutes,
            SUM(COALESCE(late_minutes, 0)) as total_late_minutes,
            SUM(COALESCE(ot_minutes, 0)) as total_ot_minutes,
            SUM(COALESCE(early_leave_minutes, 0)) as total_early_leave_minutes
        FROM hr_attendances
        WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
    ");
    $stmt->execute([$user['id'], $month]);
    $summary = $stmt->fetch();
    
    apiSuccess(['summary' => $summary, 'month' => $month]);
}

/**
 * Apply a single attendance adjustment (shared by adjust + bulk_adjust).
 *
 * @return array{ok:bool,record_id?:int,error?:string}
 */
function applyAttendanceAdjust(
    PDO $pdo,
    array $actor,
    int $userId,
    string $date,
    string $checkInTime,
    string $checkOutTime,
    string $note
): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง: ' . $date];
    }
    if ($checkInTime === '' && $checkOutTime === '') {
        return ['ok' => false, 'error' => 'กรุณาระบุเวลาเข้าหรือเวลาออกอย่างน้อยหนึ่งช่อง'];
    }

    $attendanceService = new AttendanceService($pdo);
    $targetUser = $attendanceService->getUserForAttendance($userId);
    if (!$targetUser) {
        return ['ok' => false, 'error' => 'ไม่พบพนักงาน'];
    }

    $stmt = $pdo->prepare('SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?');
    $stmt->execute([$userId, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $shift = $existing && !empty($existing['shift_id'])
        ? $attendanceService->getShiftById((int)$existing['shift_id'])
        : $attendanceService->getDefaultShift();

    $checkInFull = $checkInTime !== ''
        ? AttendanceService::normalizeDateTime($date, $checkInTime)
        : ($existing['check_in_time'] ?? null);
    $checkOutFull = $checkOutTime !== ''
        ? AttendanceService::normalizeDateTime($date, $checkOutTime)
        : ($existing['check_out_time'] ?? null);
    $checkInChanged = ($checkInTime !== '');
    $checkOutChanged = ($checkOutTime !== '');

    $summary = $attendanceService->summarizeAttendance(
        $targetUser,
        $shift,
        $date,
        $checkInFull,
        $checkOutFull,
        $existing ? ($existing['planned_start_time'] ?? null) : null,
        $existing ? ($existing['status'] ?? null) : null
    );
    $workMinutes = (int)$summary['work_minutes'];
    $breakMinutes = (int)$summary['break_minutes'];
    $lateMinutes = (int)$summary['late_minutes'];
    $earlyLeaveMinutes = (int)$summary['early_leave_minutes'];
    $otMinutes = (int)$summary['ot_minutes'];
    $status = (string)$summary['status'];

    try {
        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE hr_attendances SET
                    check_in_time = ?,
                    check_out_time = ?,
                    check_in_type = CASE WHEN ? = 1 THEN 'MANUAL' ELSE check_in_type END,
                    check_out_type = CASE WHEN ? = 1 THEN 'MANUAL' ELSE check_out_type END,
                    work_minutes = ?,
                    break_minutes = ?,
                    late_minutes = ?,
                    early_leave_minutes = ?,
                    ot_minutes = ?,
                    status = ?,
                    adjustment_reason = ?,
                    adjusted_by = ?,
                    adjusted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $checkInFull,
                $checkOutFull,
                $checkInChanged ? 1 : 0,
                $checkOutChanged ? 1 : 0,
                $workMinutes,
                $breakMinutes,
                $lateMinutes,
                $earlyLeaveMinutes,
                $otMinutes,
                $status,
                $note,
                $actor['id'],
                $existing['id'],
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO hr_attendances (
                    user_id, attendance_date, shift_id,
                    check_in_time, check_in_type,
                    check_out_time, check_out_type,
                    work_minutes, break_minutes, late_minutes, early_leave_minutes, ot_minutes, status, adjustment_reason,
                    adjusted_by, adjusted_at
                ) VALUES (?, ?, ?, ?, 'MANUAL', ?, 'MANUAL', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $date,
                $shift['id'] ?? null,
                $checkInFull,
                $checkOutFull,
                $workMinutes,
                $breakMinutes,
                $lateMinutes,
                $earlyLeaveMinutes,
                $otMinutes,
                $status,
                $note,
                $actor['id'],
            ]);
        }

        $recordId = (int)($existing['id'] ?? $pdo->lastInsertId());
        $oldValues = $existing ? [
            'target_user_id' => $userId,
            'attendance_date' => $date,
            'check_in_time' => $existing['check_in_time'] ?? null,
            'check_out_time' => $existing['check_out_time'] ?? null,
            'status' => $existing['status'] ?? null,
            'adjustment_reason' => $existing['adjustment_reason'] ?? null,
        ] : [
            'target_user_id' => $userId,
            'attendance_date' => $date,
            '_note' => 'no previous record',
        ];
        $newValues = [
            'target_user_id' => $userId,
            'attendance_date' => $date,
            'check_in_time' => $checkInFull,
            'check_out_time' => $checkOutFull,
            'status' => $status,
            'adjustment_reason' => $note,
            'adjusted_by' => (int)$actor['id'],
            'bulk' => false,
        ];
        Auth::log('ATTENDANCE_ADJUST', 'hr_attendances', $recordId, $oldValues, $newValues);

        return ['ok' => true, 'record_id' => $recordId];
    } catch (Exception $e) {
        tpHrLogException($e, 'api/attendance applyAttendanceAdjust');
        return ['ok' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $date];
    }
}

/**
 * Handle attendance adjustment (HR only)
 */
function handleAdjust(PDO $pdo, array $user, array $input): void {
    if (!hr_can_manage_attendance()) {
        apiError('ไม่มีสิทธิ์ดำเนินการ — เฉพาะ HR/ผู้มีสิทธิ์จัดการเวลาเข้างาน', 403);
    }

    $userId = (int)($input['user_id'] ?? 0);
    $date = $input['attendance_date'] ?? '';
    $checkInTime = trim((string)($input['check_in_time'] ?? ''));
    $checkOutTime = trim((string)($input['check_out_time'] ?? ''));
    $note = trim($input['note'] ?? '');

    if (!$userId || !$date) {
        apiError('ข้อมูลไม่ครบถ้วน');
    }
    if ($note === '') {
        apiError('กรุณาระบุเหตุผลการแก้ไขเวลา');
    }

    $result = applyAttendanceAdjust($pdo, $user, $userId, $date, $checkInTime, $checkOutTime, $note);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'แก้ไขไม่สำเร็จ');
    }
    apiSuccess([], 'บันทึกข้อมูลสำเร็จ');
}

/**
 * Bulk attendance adjustment — same check-in/out applied to multiple dates (HR only).
 */
function handleBulkAdjust(PDO $pdo, array $user, array $input): void {
    if (!hr_can_manage_attendance()) {
        apiError('ไม่มีสิทธิ์ดำเนินการ — เฉพาะ HR/ผู้มีสิทธิ์จัดการเวลาเข้างาน', 403);
    }

    $userId = (int)($input['user_id'] ?? 0);
    $note = trim($input['note'] ?? '');
    $checkInTime = trim((string)($input['check_in_time'] ?? ''));
    $checkOutTime = trim((string)($input['check_out_time'] ?? ''));
    $datesRaw = $input['dates'] ?? [];

    if (!$userId) {
        apiError('ข้อมูลไม่ครบถ้วน');
    }
    if ($note === '') {
        apiError('กรุณาระบุเหตุผลการแก้ไขเวลา');
    }
    if ($checkInTime === '' && $checkOutTime === '') {
        apiError('กรุณาระบุเวลาเข้าหรือเวลาออกอย่างน้อยหนึ่งช่อง');
    }
    if (!is_array($datesRaw) || $datesRaw === []) {
        apiError('กรุณาเลือกอย่างน้อย 1 วัน');
    }

    $dates = [];
    foreach ($datesRaw as $d) {
        $d = trim((string)$d);
        if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $dates[$d] = true;
        }
    }
    $dates = array_keys($dates);
    sort($dates);

    if ($dates === []) {
        apiError('รูปแบบวันที่ไม่ถูกต้อง');
    }
    if (count($dates) > 31) {
        apiError('แก้ไขได้สูงสุด 31 วันต่อครั้ง');
    }

    $attendanceService = new AttendanceService($pdo);
    if (!$attendanceService->getUserForAttendance($userId)) {
        apiError('ไม่พบพนักงานที่ต้องการแก้ไข');
    }

    $ok = 0;
    $failed = [];
    try {
        $pdo->beginTransaction();
        foreach ($dates as $date) {
            $result = applyAttendanceAdjust($pdo, $user, $userId, $date, $checkInTime, $checkOutTime, $note);
            if ($result['ok']) {
                $ok++;
            } else {
                $failed[] = ['date' => $date, 'error' => $result['error'] ?? 'ล้มเหลว'];
            }
        }
        if ($ok === 0) {
            $pdo->rollBack();
            apiError('ไม่สามารถบันทึกได้ — ' . ($failed[0]['error'] ?? 'ลองใหม่อีกครั้ง'));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tpHrLogException($e, 'api/attendance handleBulkAdjust');
        apiError('เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่', 500);
    }

    Auth::log('ATTENDANCE_BULK_ADJUST', 'hr_attendances', $userId, [
        'target_user_id' => $userId,
        'dates' => $dates,
    ], [
        'success_count' => $ok,
        'failed' => $failed,
        'check_in_time' => $checkInTime,
        'check_out_time' => $checkOutTime,
        'note' => $note,
    ]);

    $msg = 'บันทึกสำเร็จ ' . $ok . ' วัน';
    if ($failed !== []) {
        $msg .= ' (ล้มเหลว ' . count($failed) . ' วัน)';
    }
    apiSuccess(['success_count' => $ok, 'failed' => $failed], $msg);
}

/**
 * Get adjustment history for a specific attendance (HR dashboard only — same gate as hr/attendance.php, handleAdjust, handleDelete).
 * Broad MANAGER_ROLES without hr.dashboard must not read cross-employee audit payloads (IP, before/after times).
 * GET params: user_id, date  (identify the attendance row)
 */
function getAdjustmentHistory(PDO $pdo, array $user): void {
    if (!hr_can_access_hr_dashboard()) {
        apiError('ไม่มีสิทธิ์ดำเนินการ', 403);
    }

    $userId = (int)($_GET['user_id'] ?? 0);
    $date   = $_GET['date'] ?? '';

    if (!$userId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        apiError('ข้อมูลไม่ครบถ้วน');
    }

    // Find attendance record id (if still exists)
    $stmt = $pdo->prepare("SELECT id FROM hr_attendances WHERE user_id = ? AND attendance_date = ?");
    $stmt->execute([$userId, $date]);
    $row = $stmt->fetch();
    $recordId = $row ? (int)$row['id'] : 0;

    // Query audit logs for both ADJUST and DELETE actions on this user+date.
    // Search by JSON payload (reliable even after row deletion/recreation), union with record_id
    // if the current row still exists, to catch very old entries with minimal payload.
    $userLike = '%"target_user_id":' . $userId . '%';
    $dateLike = '%"attendance_date":"' . $date . '"%';

    if ($recordId) {
        $stmt = $pdo->prepare("
            SELECT al.*, u.first_name_th, u.last_name_th, u.employee_code
            FROM hr_audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.table_name = 'hr_attendances'
              AND al.action IN ('ATTENDANCE_ADJUST','ATTENDANCE_DELETE','ATTENDANCE_CLEAR')
              AND (
                    al.record_id = ?
                 OR (al.new_values LIKE ? AND al.new_values LIKE ?)
                 OR (al.old_values LIKE ? AND al.old_values LIKE ?)
              )
            ORDER BY al.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$recordId, $userLike, $dateLike, $userLike, $dateLike]);
    } else {
        $stmt = $pdo->prepare("
            SELECT al.*, u.first_name_th, u.last_name_th, u.employee_code
            FROM hr_audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.table_name = 'hr_attendances'
              AND al.action IN ('ATTENDANCE_ADJUST','ATTENDANCE_DELETE','ATTENDANCE_CLEAR')
              AND (
                    (al.new_values LIKE ? AND al.new_values LIKE ?)
                 OR (al.old_values LIKE ? AND al.old_values LIKE ?)
              )
            ORDER BY al.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$userLike, $dateLike, $userLike, $dateLike]);
    }

    $logs = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $logs[] = [
            'id'          => (int)$r['id'],
            'action'      => $r['action'],
            'created_at'  => $r['created_at'],
            'by_user_id'  => (int)$r['user_id'],
            'by_name'     => trim(($r['first_name_th'] ?? '') . ' ' . ($r['last_name_th'] ?? '')),
            'by_code'     => $r['employee_code'] ?? '',
            'ip_address'  => $r['ip_address'] ?? '',
            'old_values'  => $r['old_values'] ? json_decode($r['old_values'], true) : null,
            'new_values'  => $r['new_values'] ? json_decode($r['new_values'], true) : null,
        ];
    }

    apiSuccess(['logs' => $logs, 'count' => count($logs)]);
}

/**
 * Clear check-in and/or check-out times (HR only) — for wrong clock-in/out cases.
 * scope: check_in | check_out | both
 * If both times become empty after clear, deletes the row (same audit as full delete).
 */
function handleClearTimes(PDO $pdo, array $user, array $input): void {
    if (!hr_can_manage_attendance()) {
        apiError('ไม่มีสิทธิ์ดำเนินการ — เฉพาะ HR/ผู้มีสิทธิ์จัดการเวลาเข้างาน', 403);
    }

    $userId = (int)($input['user_id'] ?? 0);
    $date = trim((string)($input['attendance_date'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $scope = trim((string)($input['scope'] ?? 'both'));

    if (!$userId || $date === '') {
        apiError('ข้อมูลไม่ครบถ้วน');
    }
    if ($note === '') {
        apiError('กรุณาระบุเหตุผลการลบเวลา');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        apiError('รูปแบบวันที่ไม่ถูกต้อง');
    }
    if (!in_array($scope, ['check_in', 'check_out', 'both'], true)) {
        apiError('ขอบเขตการลบไม่ถูกต้อง');
    }

    $stmt = $pdo->prepare('SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?');
    $stmt->execute([$userId, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        apiError('ไม่พบข้อมูลการลงเวลาของวันนี้');
    }

    $clearIn = $scope === 'check_in' || $scope === 'both';
    $clearOut = $scope === 'check_out' || $scope === 'both';

    $newCheckIn = $clearIn ? null : ($existing['check_in_time'] ?? null);
    $newCheckOut = $clearOut ? null : ($existing['check_out_time'] ?? null);

    if ($newCheckIn === null && $newCheckOut === null) {
        handleDelete($pdo, $user, $input);
        return;
    }

    $attendanceService = new AttendanceService($pdo);
    $targetUser = $attendanceService->getUserForAttendance($userId);
    if (!$targetUser) {
        apiError('ไม่พบพนักงาน');
    }

    $shift = !empty($existing['shift_id'])
        ? $attendanceService->getShiftById((int)$existing['shift_id'])
        : $attendanceService->getDefaultShift();

    $summary = $attendanceService->summarizeAttendance(
        $targetUser,
        $shift,
        $date,
        $newCheckIn,
        $newCheckOut,
        $existing['planned_start_time'] ?? null,
        $existing['status'] ?? null
    );

    try {
        $pdo->beginTransaction();

        $sql = 'UPDATE hr_attendances SET
            check_in_time = ?,
            check_out_time = ?,
            check_in_type = CASE WHEN ? = 1 THEN NULL ELSE check_in_type END,
            check_out_type = CASE WHEN ? = 1 THEN NULL ELSE check_out_type END,
            check_in_photo = CASE WHEN ? = 1 THEN NULL ELSE check_in_photo END,
            check_out_photo = CASE WHEN ? = 1 THEN NULL ELSE check_out_photo END,
            check_in_latitude = CASE WHEN ? = 1 THEN NULL ELSE check_in_latitude END,
            check_in_longitude = CASE WHEN ? = 1 THEN NULL ELSE check_in_longitude END,
            check_out_latitude = CASE WHEN ? = 1 THEN NULL ELSE check_out_latitude END,
            check_out_longitude = CASE WHEN ? = 1 THEN NULL ELSE check_out_longitude END,
            work_minutes = ?,
            break_minutes = ?,
            late_minutes = ?,
            early_leave_minutes = ?,
            ot_minutes = ?,
            status = ?,
            adjustment_reason = ?,
            adjusted_by = ?,
            adjusted_at = NOW(),
            updated_at = NOW()
            WHERE id = ?';

        $pdo->prepare($sql)->execute([
            $newCheckIn,
            $newCheckOut,
            $clearIn ? 1 : 0,
            $clearOut ? 1 : 0,
            $clearIn ? 1 : 0,
            $clearOut ? 1 : 0,
            $clearIn ? 1 : 0,
            $clearIn ? 1 : 0,
            $clearOut ? 1 : 0,
            $clearOut ? 1 : 0,
            (int)$summary['work_minutes'],
            (int)$summary['break_minutes'],
            (int)$summary['late_minutes'],
            (int)$summary['early_leave_minutes'],
            (int)$summary['ot_minutes'],
            (string)$summary['status'],
            $note,
            (int)$user['id'],
            (int)$existing['id'],
        ]);

        $pdo->commit();

        if ($clearIn && !empty($existing['check_in_photo'])) {
            $rel = ltrim((string)$existing['check_in_photo'], '/');
            if ($rel && str_starts_with($rel, 'storage/')) {
                $full = dirname(__DIR__) . '/' . $rel;
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }
        if ($clearOut && !empty($existing['check_out_photo'])) {
            $rel = ltrim((string)$existing['check_out_photo'], '/');
            if ($rel && str_starts_with($rel, 'storage/')) {
                $full = dirname(__DIR__) . '/' . $rel;
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }

        $scopeLabel = match ($scope) {
            'check_in' => 'ลบเวลาเข้า',
            'check_out' => 'ลบเวลาออก',
            default => 'ลบเวลาเข้าและออก',
        };

        Auth::log('ATTENDANCE_CLEAR', 'hr_attendances', (int)$existing['id'], [
            'target_user_id' => $userId,
            'attendance_date' => $date,
            'scope' => $scope,
            'check_in_time' => $existing['check_in_time'] ?? null,
            'check_out_time' => $existing['check_out_time'] ?? null,
            'status' => $existing['status'] ?? null,
        ], [
            'target_user_id' => $userId,
            'attendance_date' => $date,
            'scope' => $scope,
            'scope_label' => $scopeLabel,
            'check_in_time' => $newCheckIn,
            'check_out_time' => $newCheckOut,
            'status' => (string)$summary['status'],
            'adjustment_reason' => $note,
            'adjusted_by' => (int)$user['id'],
        ]);

        apiSuccess([], $scopeLabel . ' สำเร็จ — พนักงานสามารถลงเวลาใหม่ได้');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tpHrLogException($e, 'api/attendance handleClearTimes');
        apiError('เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่', 500);
    }
}

/**
 * Handle attendance record deletion (HR only).
 * Removes the whole hr_attendances row for (user, date), including photos, location, and minutes.
 * Status will naturally fall back to holiday/leave/day-off/absent on display.
 */
function handleDelete(PDO $pdo, array $user, array $input): void {
    if (!hr_can_manage_attendance()) {
        apiError('ไม่มีสิทธิ์ดำเนินการ — เฉพาะ HR/ผู้มีสิทธิ์จัดการเวลาเข้างาน', 403);
    }

    $userId = (int)($input['user_id'] ?? 0);
    $date   = $input['attendance_date'] ?? '';
    $note   = trim($input['note'] ?? '');

    if (!$userId || !$date) {
        apiError('ข้อมูลไม่ครบถ้วน');
    }
    if ($note === '') {
        apiError('กรุณาระบุเหตุผลการลบข้อมูล');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        apiError('รูปแบบวันที่ไม่ถูกต้อง');
    }

    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?");
    $stmt->execute([$userId, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        apiError('ไม่พบข้อมูลการลงเวลาของวันนี้');
    }

    try {
        $oldValues = [
            'target_user_id'      => $userId,
            'attendance_date'     => $date,
            'check_in_time'       => $existing['check_in_time'] ?? null,
            'check_out_time'      => $existing['check_out_time'] ?? null,
            'check_in_type'       => $existing['check_in_type'] ?? null,
            'check_out_type'      => $existing['check_out_type'] ?? null,
            'check_in_photo'      => $existing['check_in_photo'] ?? null,
            'check_out_photo'     => $existing['check_out_photo'] ?? null,
            'check_in_latitude'   => $existing['check_in_latitude'] ?? null,
            'check_in_longitude'  => $existing['check_in_longitude'] ?? null,
            'check_out_latitude'  => $existing['check_out_latitude'] ?? null,
            'check_out_longitude' => $existing['check_out_longitude'] ?? null,
            'work_minutes'        => (int)($existing['work_minutes'] ?? 0),
            'late_minutes'        => (int)($existing['late_minutes'] ?? 0),
            'early_leave_minutes' => (int)($existing['early_leave_minutes'] ?? 0),
            'status'              => $existing['status'] ?? null,
            'adjustment_reason'   => $existing['adjustment_reason'] ?? null,
        ];

        $attId = (int)$existing['id'];
        $pdo->beginTransaction();

        // Clean up dependent rows (FK constraints)
        $childCounts = ['outside_requests' => 0, 'adjustments' => 0];

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM hr_attendance_outside_requests WHERE attendance_id = ?");
        $cnt->execute([$attId]);
        $childCounts['outside_requests'] = (int)$cnt->fetchColumn();
        if ($childCounts['outside_requests'] > 0) {
            $pdo->prepare("DELETE FROM hr_attendance_outside_requests WHERE attendance_id = ?")->execute([$attId]);
        }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM hr_attendance_adjustments WHERE attendance_id = ?");
        $cnt->execute([$attId]);
        $childCounts['adjustments'] = (int)$cnt->fetchColumn();
        if ($childCounts['adjustments'] > 0) {
            $pdo->prepare("DELETE FROM hr_attendance_adjustments WHERE attendance_id = ?")->execute([$attId]);
        }

        // Delete the main record
        $del = $pdo->prepare("DELETE FROM hr_attendances WHERE id = ?");
        $del->execute([$attId]);

        $pdo->commit();

        // Best-effort: remove photo files on disk
        $photoFiles = array_filter([
            $existing['check_in_photo']  ?? null,
            $existing['check_out_photo'] ?? null,
        ]);
        foreach ($photoFiles as $rel) {
            // Only delete if under storage/
            $rel = ltrim((string)$rel, '/');
            if ($rel && strpos($rel, 'storage/') === 0) {
                $full = __DIR__ . '/../' . $rel;
                if (is_file($full)) @unlink($full);
            }
        }

        Auth::log('ATTENDANCE_DELETE', 'hr_attendances', $attId, $oldValues, [
            'target_user_id'    => $userId,
            'attendance_date'   => $date,
            'action'            => 'DELETED',
            'adjustment_reason' => $note,
            'adjusted_by'       => (int)$user['id'],
            'child_rows_deleted'=> $childCounts,
        ]);

        apiSuccess([], 'ลบข้อมูลการลงเวลาสำเร็จ');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        tpHrLogException($e, 'api/attendance handleDelete');
        apiError('เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่', 500);
    }
}

/**
 * POST action=request_late_start
 * Mirror ของ tp-checkin/api/attendance.php — ใช้ table เดียวกัน (hr_attendances)
 * Input: { target_date: Y-m-d, planned_start_time: HH:MM, reason: string }
 */
function handleLateStartRequest(PDO $pdo, array $user, array $input): void {
    $target_date   = trim((string)($input['target_date'] ?? ''));
    $planned_start = trim((string)($input['planned_start_time'] ?? ''));
    $reason        = trim((string)($input['reason'] ?? ''));

    if (!function_exists('canRequestLateStart') || !function_exists('validatePlannedStartTime')) {
        apiError('ระบบแจ้งเข้างานสายยังไม่พร้อมใช้งาน', 503);
    }

    $canRequest = canRequestLateStart($pdo, $target_date, null, (int)$user['id']);
    if (!$canRequest['ok']) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => $canRequest['message'],
            'data'    => ['reason' => $canRequest['reason'], 'fallback' => $canRequest['fallback']],
        ]);
        exit;
    }

    if (mb_strlen($reason) < 5) {
        apiError('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
    }

    $shift = $pdo->query("SELECT id, name, start_time, end_time, grace_period_minutes FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    $timeCheck = validatePlannedStartTime($planned_start, $shift);
    if (!$timeCheck['ok']) {
        apiError($timeCheck['message']);
    }

    $stmt = $pdo->prepare("SELECT id, check_in_time FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$user['id'], $target_date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && !empty($existing['check_in_time'])) {
        apiError('ลงเวลาเข้างานวันดังกล่าวไปแล้ว — กรุณาใช้แบบฟอร์มขอแก้ไขเวลาเข้างานแทน');
    }

    try {
        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE hr_attendances
                SET planned_start_time = ?,
                    planned_reason = ?,
                    planned_requested_at = NOW(),
                    planned_requested_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$planned_start . ':00', $reason, $user['id'], $existing['id']]);
            $attendanceId = (int)$existing['id'];
        } else {
            $shift_id = $shift['id'] ?? null;
            $stmt = $pdo->prepare("
                INSERT INTO hr_attendances
                    (user_id, attendance_date, shift_id,
                     planned_start_time, planned_reason, planned_requested_at, planned_requested_by,
                     created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())
            ");
            $stmt->execute([$user['id'], $target_date, $shift_id, $planned_start . ':00', $reason, $user['id']]);
            $attendanceId = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        tpHrLogException($e, 'api/attendance handleLateStartRequest');
        apiError('บันทึกคำขอไม่สำเร็จ กรุณาลองใหม่', 500);
    }

    if (class_exists('Auth') && method_exists('Auth', 'log')) {
        try {
            Auth::log('REQUEST_LATE_START', 'hr_attendances', $attendanceId, [
                'target_date'   => $target_date,
                'planned_start' => $planned_start,
                'reason'        => $reason,
            ]);
        } catch (Throwable $e) { /* non-fatal */ }
    }

    // LINE notification via CRM bridge (HR + Admin + Chairman + CEO)
    if (is_file(__DIR__ . '/../core/CrmLineNotifierBridge.php')) {
        try {
            require_once __DIR__ . '/../core/CrmLineNotifierBridge.php';
            if (function_exists('crm_line_notify_planned_late_request')) {
                crm_line_notify_planned_late_request(
                    $pdo, $user, $target_date, $planned_start . ':00', $reason, date('Y-m-d H:i:s')
                );
            }
        } catch (Throwable $e) { error_log('notify_planned_late_request error: ' . $e->getMessage()); }
    }

    apiSuccess([
        'attendance_id' => $attendanceId,
        'target_date'   => $target_date,
        'planned_start' => $planned_start,
    ], sprintf('แจ้งเข้างานสายวันที่ %s เวลา %s เรียบร้อย', $target_date, $planned_start));
}

/**
 * POST action=cancel_late_start
 * Input: { target_date: Y-m-d }
 */
function cancelLateStartRequest(PDO $pdo, array $user, array $input): void {
    $target_date = trim((string)($input['target_date'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
        apiError('รูปแบบวันที่ไม่ถูกต้อง');
    }

    $stmt = $pdo->prepare("SELECT id, check_in_time, planned_start_time FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$user['id'], $target_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['planned_start_time'])) {
        apiError('ไม่พบคำขอแจ้งเข้างานสายของวันดังกล่าว', 404);
    }
    if (!empty($row['check_in_time'])) {
        apiError('ลงเวลาเข้างานไปแล้ว — ไม่สามารถยกเลิกได้');
    }

    if (function_exists('canRequestLateStart')) {
        $canRequest = canRequestLateStart($pdo, $target_date, null, (int)$user['id']);
        if (!$canRequest['ok']) {
            apiError($canRequest['message']);
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE hr_attendances
            SET planned_start_time = NULL,
                planned_reason = NULL,
                planned_requested_at = NULL,
                planned_requested_by = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$row['id']]);
    } catch (PDOException $e) {
        tpHrLogException($e, 'api/attendance cancelLateStartRequest');
        apiError('ยกเลิกคำขอไม่สำเร็จ กรุณาลองใหม่', 500);
    }

    if (class_exists('Auth') && method_exists('Auth', 'log')) {
        try {
            Auth::log('CANCEL_LATE_START', 'hr_attendances', (int)$row['id'], [
                'target_date' => $target_date,
            ]);
        } catch (Throwable $e) { /* non-fatal */ }
    }

    if (is_file(__DIR__ . '/../core/CrmLineNotifierBridge.php')) {
        try {
            require_once __DIR__ . '/../core/CrmLineNotifierBridge.php';
            if (function_exists('crm_line_notify_planned_late_cancelled')) {
                crm_line_notify_planned_late_cancelled($pdo, $user, $target_date, $row['planned_start_time'] ?? null);
            }
        } catch (Throwable $e) { error_log('notify_planned_late_cancelled error: ' . $e->getMessage()); }
    }

    apiSuccess([], 'ยกเลิกคำขอแจ้งเข้างานสายเรียบร้อย');
}
