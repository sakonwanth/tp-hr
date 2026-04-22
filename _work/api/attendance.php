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
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Check for FormData input
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
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

        case 'request_adjustment':
            createAdjustmentRequest($pdo, $user, $input);
            break;

        case 'review_adjustment':
            reviewAdjustmentRequest($pdo, $user, $input);
            break;

        case 'review_outside_request':
            reviewOutsideLocationRequest($pdo, $user, $input);
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

        case 'adjustment_requests':
            getAdjustmentRequests($pdo, $user);
            break;

        case 'outside_requests':
            getOutsideLocationRequests($pdo, $user);
            break;
            
        default:
            apiError('Invalid action');
    }
} else {
    apiError('Method not allowed', 405);
}

/**
 * Handle Check-in
 */
function handleCheckIn(PDO $pdo, array $user, array $input): void {
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $photo = $input['photo'] ?? null;
    $outsideReason = trim($input['outside_reason'] ?? '');
    
    // Check if already checked in today
    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
    $stmt->execute([$user['id']]);
    $existing = $stmt->fetch();
    
    if ($existing && $existing['check_in_time']) {
        apiError('คุณได้ลงเวลาเข้างานวันนี้แล้ว');
    }
    
    // Get default shift
    $stmt = $pdo->query("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
    $shift = $stmt->fetch();
    
    // Calculate late minutes
    $lateMinutes = 0;
    $status = 'PRESENT';
    
    if ($shift) {
        $shiftStart = strtotime(date('Y-m-d') . ' ' . $shift['start_time']);
        $gracePeriod = ($shift['grace_period_minutes'] ?? 15) * 60;
        $now = time();
        
        if ($now > ($shiftStart + $gracePeriod)) {
            $lateMinutes = floor(($now - $shiftStart) / 60);
            $status = 'LATE';
        }
    }
    
    // Check if location enforcement is enabled
    $stmt = $pdo->prepare("SELECT value FROM hr_settings WHERE `key` = ?");
    $stmt->execute(['enforce_location_checkin']);
    $enforceSetting = $stmt->fetch();
    $enforceLocation = ($enforceSetting && $enforceSetting['value'] === '1');

    // Check if outside location should require approval (default true)
    $stmt = $pdo->prepare("SELECT value FROM hr_settings WHERE `key` = ?");
    $stmt->execute(['outside_location_requires_approval']);
    $outsideApprovalSetting = $stmt->fetch();
    $outsideRequiresApproval = !$outsideApprovalSetting || $outsideApprovalSetting['value'] !== '0';
    
    // Validate location
    $locationId = null;
    $locationName = null;
    
    if ($enforceLocation) {
        // Location is required
        if (!$latitude || !$longitude) {
            apiError('กรุณาเปิดการระบุตำแหน่ง (GPS) เพื่อลงเวลา');
        }
        
        $locationResult = validateLocationWithName($pdo, $latitude, $longitude);
        if (!$locationResult) {
            if ($outsideRequiresApproval) {
                if (mb_strlen($outsideReason) < 5) {
                    apiError('คุณอยู่นอกสถานที่ที่กำหนด กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
                }

                $pendingResult = createOutsideLocationRequest(
                    $pdo,
                    $user,
                    'CHECK_IN',
                    null,
                    $latitude,
                    $longitude,
                    $photo,
                    $outsideReason
                );

                apiSuccess([
                    'pending_approval' => true,
                    'request_id' => $pendingResult['request_id']
                ], 'ส่งคำขอลงเวลาเข้างานนอกสถานที่เรียบร้อยแล้ว รอผู้อนุมัติ');
            }

            apiError('คุณไม่ได้อยู่ในพื้นที่ที่อนุญาตให้ลงเวลา กรุณาตรวจสอบตำแหน่งของคุณ');
        }
        $locationId = $locationResult['id'];
        $locationName = $locationResult['name'];
    } elseif ($latitude && $longitude) {
        // Location provided but not enforced - still try to match
        $locationResult = validateLocationWithName($pdo, $latitude, $longitude);
        if ($locationResult) {
            $locationId = $locationResult['id'];
            $locationName = $locationResult['name'];
        }
    }
    
    // Save photo
    $photoPath = null;
    if ($photo) {
        $photoPath = savePhoto($photo, $user['id'], 'checkin');
    }
    
    // Insert or update attendance record
    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE hr_attendances SET
                check_in_time = NOW(),
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
        $stmt->execute([
            $latitude,
            $longitude,
            $locationId,
            $photoPath,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $lateMinutes,
            $status,
            $existing['id']
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO hr_attendances (
                user_id, attendance_date, shift_id,
                check_in_time, check_in_type, check_in_latitude, check_in_longitude,
                check_in_location_id, check_in_photo, check_in_ip,
                late_minutes, status
            ) VALUES (?, CURDATE(), ?, NOW(), 'GPS', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'],
            $shift['id'] ?? null,
            $latitude,
            $longitude,
            $locationId,
            $photoPath,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $lateMinutes,
            $status
        ]);
    }
    
    // Log action
    Auth::log('CHECK_IN', 'hr_attendances', $pdo->lastInsertId() ?: $existing['id']);
    
    apiSuccess([
        'check_in_time' => date('H:i:s'),
        'late_minutes' => $lateMinutes,
        'status' => $status
    ], 'ลงเวลาเข้างานสำเร็จ');
}

/**
 * Handle Check-out
 */
function handleCheckOut(PDO $pdo, array $user, array $input): void {
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    $photo = $input['photo'] ?? null;
    $outsideReason = trim($input['outside_reason'] ?? '');
    
    // Check if checked in today
    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
    $stmt->execute([$user['id']]);
    $attendance = $stmt->fetch();
    
    if (!$attendance || !$attendance['check_in_time']) {
        apiError('คุณยังไม่ได้ลงเวลาเข้างานวันนี้');
    }
    
    if ($attendance['check_out_time']) {
        apiError('คุณได้ลงเวลาออกงานวันนี้แล้ว');
    }
    
    // Get shift info
    $shift = null;
    if ($attendance['shift_id']) {
        $stmt = $pdo->prepare("SELECT * FROM hr_work_shifts WHERE id = ?");
        $stmt->execute([$attendance['shift_id']]);
        $shift = $stmt->fetch();
    }
    
    // Calculate work minutes
    $checkInTime = strtotime($attendance['check_in_time']);
    $checkOutTime = time();
    $workMinutes = floor(($checkOutTime - $checkInTime) / 60);
    
    // Subtract break time
    $breakMinutes = $shift['break_minutes'] ?? 60;
    $workMinutes -= $breakMinutes;
    if ($workMinutes < 0) $workMinutes = 0;
    
    // Calculate OT
    $otMinutes = 0;
    if ($shift) {
        $shiftEnd = strtotime(date('Y-m-d') . ' ' . $shift['end_time']);
        $expectedWorkMinutes = ($shift['work_hours_per_day'] ?? 8) * 60;
        
        if ($workMinutes > $expectedWorkMinutes) {
            $otMinutes = $workMinutes - $expectedWorkMinutes;
        }
    }
    
    // Calculate early leave
    $earlyLeaveMinutes = 0;
    if ($shift) {
        $shiftEnd = strtotime(date('Y-m-d') . ' ' . $shift['end_time']);
        if ($checkOutTime < $shiftEnd) {
            $earlyLeaveMinutes = floor(($shiftEnd - $checkOutTime) / 60);
        }
    }
    
    // Check if location enforcement is enabled
    $stmt = $pdo->prepare("SELECT value FROM hr_settings WHERE `key` = ?");
    $stmt->execute(['enforce_location_checkin']);
    $enforceSetting = $stmt->fetch();
    $enforceLocation = ($enforceSetting && $enforceSetting['value'] === '1');

    // Check if outside location should require approval (default true)
    $stmt = $pdo->prepare("SELECT value FROM hr_settings WHERE `key` = ?");
    $stmt->execute(['outside_location_requires_approval']);
    $outsideApprovalSetting = $stmt->fetch();
    $outsideRequiresApproval = !$outsideApprovalSetting || $outsideApprovalSetting['value'] !== '0';
    
    // Validate location
    $locationId = null;
    
    if ($enforceLocation) {
        if (!$latitude || !$longitude) {
            apiError('กรุณาเปิดการระบุตำแหน่ง (GPS) เพื่อลงเวลา');
        }
        
        $locationResult = validateLocationWithName($pdo, $latitude, $longitude);
        if (!$locationResult) {
            if ($outsideRequiresApproval) {
                if (mb_strlen($outsideReason) < 5) {
                    apiError('คุณอยู่นอกสถานที่ที่กำหนด กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
                }

                $pendingResult = createOutsideLocationRequest(
                    $pdo,
                    $user,
                    'CHECK_OUT',
                    (int)$attendance['id'],
                    $latitude,
                    $longitude,
                    $photo,
                    $outsideReason
                );

                apiSuccess([
                    'pending_approval' => true,
                    'request_id' => $pendingResult['request_id']
                ], 'ส่งคำขอลงเวลาออกงานนอกสถานที่เรียบร้อยแล้ว รอผู้อนุมัติ');
            }

            apiError('คุณไม่ได้อยู่ในพื้นที่ที่อนุญาตให้ลงเวลา กรุณาตรวจสอบตำแหน่งของคุณ');
        }
        $locationId = $locationResult['id'];
    } elseif ($latitude && $longitude) {
        $locationResult = validateLocationWithName($pdo, $latitude, $longitude);
        if ($locationResult) {
            $locationId = $locationResult['id'];
        }
    }
    
    // Save photo
    $photoPath = null;
    if ($photo) {
        $photoPath = savePhoto($photo, $user['id'], 'checkout');
    }
    
    // Update attendance record
    $stmt = $pdo->prepare("
        UPDATE hr_attendances SET
            check_out_time = NOW(),
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
        'check_out_time' => date('H:i:s'),
        'work_minutes' => $workMinutes,
        'ot_minutes' => $otMinutes
    ], 'ลงเวลาออกงานสำเร็จ');
}

/**
 * Validate check-in location
 */
/**
 * Validate location and return location info
 */
function validateLocationWithName(PDO $pdo, float $latitude, float $longitude): ?array {
    $stmt = $pdo->query("SELECT * FROM hr_checkin_locations WHERE is_active = 1 AND latitude IS NOT NULL");
    $locations = $stmt->fetchAll();
    
    foreach ($locations as $loc) {
        $distance = calculateDistance($latitude, $longitude, $loc['latitude'], $loc['longitude']);
        if ($distance <= $loc['radius_meters']) {
            return [
                'id' => $loc['id'],
                'name' => $loc['name'],
                'distance' => round($distance)
            ];
        }
    }
    
    return null;
}

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
 * Save photo to storage
 */
function savePhoto(string $base64Data, int $userId, string $type): string {
    // Remove data URL prefix
    $data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
    $data = base64_decode($data);
    
    if (!$data) {
        return '';
    }
    
    // Create directory
    $dir = STORAGE_PATH . '/uploads/attendance/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Generate filename
    $filename = sprintf('%s_%d_%s_%s.jpg', $type, $userId, date('Ymd_His'), substr(md5(uniqid()), 0, 8));
    $path = $dir . '/' . $filename;
    
    file_put_contents($path, $data);
    
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
    
    apiSuccess(['attendance' => $attendance]);
}

/**
 * Get attendance history
 */
function getAttendanceHistory(PDO $pdo, array $user): void {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 30);
    $offset = ($page - 1) * $limit;
    
    $month = $_GET['month'] ?? date('Y-m');
    
    $stmt = $pdo->prepare("
        SELECT a.*, s.name as shift_name
        FROM hr_attendances a
        LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
        WHERE a.user_id = ? AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
        ORDER BY a.attendance_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user['id'], $month, $limit, $offset]);
    $attendances = $stmt->fetchAll();
    
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
 * Get outside location attendance requests
 */
function getOutsideLocationRequests(PDO $pdo, array $user): void {
    $view = $_GET['view'] ?? 'mine';
    $status = strtoupper(trim($_GET['status'] ?? ''));

    $conditions = [];
    $params = [];

    if ($view === 'pending' || $view === 'all') {
        if (!canApproveAttendanceAdjustments()) {
            apiError('ไม่มีสิทธิ์เข้าถึงข้อมูลคำขอ', 403);
        }
        if ($view === 'pending') {
            $conditions[] = "orr.status = 'PENDING'";
        }
    } else {
        $conditions[] = "orr.user_id = ?";
        $params[] = $user['id'];
    }

    if ($status && in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])) {
        $conditions[] = "orr.status = ?";
        $params[] = $status;
    }

    $whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    $stmt = $pdo->prepare("
        SELECT
            orr.*,
            CONCAT(req.first_name_th, ' ', req.last_name_th) AS requester_name,
            req.employee_code AS requester_code,
            req.department AS requester_department,
            CONCAT(rev.first_name_th, ' ', rev.last_name_th) AS reviewer_name
        FROM hr_attendance_outside_requests orr
        JOIN users req ON req.id = orr.user_id
        LEFT JOIN users rev ON rev.id = orr.reviewed_by
        $whereSql
        ORDER BY orr.created_at DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    apiSuccess(['requests' => $requests]);
}

/**
 * Create outside location request (check-in/check-out)
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

    // Prevent duplicate pending requests for same user/type/date
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
        $user['id'],
        $attendanceId,
        $requestType,
        $requestDate,
        $requestTime,
        $latitude,
        $longitude,
        $photoPath,
        $reason,
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    $requestId = (int)$pdo->lastInsertId();
    Auth::log('OUTSIDE_LOCATION_REQUEST', 'hr_attendance_outside_requests', $requestId, null, [
        'request_type' => $requestType,
        'request_date' => $requestDate,
        'attendance_id' => $attendanceId
    ]);

    return ['request_id' => $requestId, 'photo_path' => $photoPath];
}

/**
 * Review outside location request
 */
function reviewOutsideLocationRequest(PDO $pdo, array $user, array $input): void {
    if (!canApproveAttendanceAdjustments()) {
        apiError('ไม่มีสิทธิ์อนุมัติคำขอ', 403);
    }
    if (!verifyCsrfToken($input['_token'] ?? '')) {
        apiError('Invalid token', 403);
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $decision = strtoupper(trim($input['decision'] ?? $input['status'] ?? ''));
    $remarks = trim($input['review_remarks'] ?? '');

    if ($requestId <= 0) {
        apiError('ไม่พบคำขอที่ต้องการดำเนินการ');
    }
    if (!in_array($decision, ['APPROVED', 'REJECTED'])) {
        apiError('สถานะการอนุมัติไม่ถูกต้อง');
    }
    if ($decision === 'REJECTED' && $remarks === '') {
        apiError('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM hr_attendance_outside_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            $pdo->rollBack();
            apiError('ไม่พบคำขอที่ต้องการดำเนินการ');
        }
        if ($request['status'] !== 'PENDING') {
            $pdo->rollBack();
            apiError('คำขอนี้ถูกดำเนินการไปแล้ว');
        }

        $attendanceId = $request['attendance_id'] ? (int)$request['attendance_id'] : null;

        if ($decision === 'APPROVED') {
            $requestDate = $request['request_date'];
            $requestTime = $request['request_time'];

            $stmt = $pdo->query("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
            $shift = $stmt->fetch();

            if ($request['request_type'] === 'CHECK_IN') {
                $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?");
                $stmt->execute([$request['user_id'], $requestDate]);
                $existing = $stmt->fetch();

                $metrics = calculateAttendanceMetrics($requestTime, $existing['check_out_time'] ?? null, $shift, $requestDate);
                $note = '[OUTSIDE CHECK-IN APPROVED #' . $request['id'] . '] ' . $request['reason'];
                if ($remarks !== '') {
                    $note .= ' | Approver: ' . $remarks;
                }

                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE hr_attendances SET
                            check_in_time = ?,
                            check_in_type = 'GPS',
                            check_in_latitude = ?,
                            check_in_longitude = ?,
                            check_in_location_id = NULL,
                            check_in_photo = ?,
                            check_in_ip = ?,
                            late_minutes = ?,
                            status = ?,
                            remarks = CASE WHEN remarks IS NULL OR remarks = '' THEN ? ELSE CONCAT(remarks, ' | ', ?) END,
                            approved_by = ?,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $requestTime,
                        $request['latitude'],
                        $request['longitude'],
                        $request['photo_path'],
                        $request['request_ip'],
                        $metrics['late_minutes'],
                        $metrics['status'],
                        $note,
                        $note,
                        $user['id'],
                        $existing['id']
                    ]);
                    $attendanceId = (int)$existing['id'];
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO hr_attendances (
                            user_id, attendance_date, shift_id,
                            check_in_time, check_in_type,
                            check_in_latitude, check_in_longitude, check_in_location_id,
                            check_in_photo, check_in_ip,
                            late_minutes, status, remarks,
                            approved_by, approved_at
                        ) VALUES (?, ?, ?, ?, 'GPS', ?, ?, NULL, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $request['user_id'],
                        $requestDate,
                        $shift['id'] ?? null,
                        $requestTime,
                        $request['latitude'],
                        $request['longitude'],
                        $request['photo_path'],
                        $request['request_ip'],
                        $metrics['late_minutes'],
                        $metrics['status'],
                        $note,
                        $user['id']
                    ]);
                    $attendanceId = (int)$pdo->lastInsertId();
                }
            } else {
                // CHECK_OUT request
                if (!$attendanceId) {
                    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?");
                    $stmt->execute([$request['user_id'], $requestDate]);
                    $attendance = $stmt->fetch();
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE id = ?");
                    $stmt->execute([$attendanceId]);
                    $attendance = $stmt->fetch();
                }

                if (!$attendance || !$attendance['check_in_time']) {
                    $pdo->rollBack();
                    apiError('ไม่พบข้อมูลเข้างานสำหรับอนุมัติเวลาออก');
                }

                $metrics = calculateAttendanceMetrics($attendance['check_in_time'], $requestTime, $shift, $requestDate);
                $note = '[OUTSIDE CHECK-OUT APPROVED #' . $request['id'] . '] ' . $request['reason'];
                if ($remarks !== '') {
                    $note .= ' | Approver: ' . $remarks;
                }

                $stmt = $pdo->prepare("
                    UPDATE hr_attendances SET
                        check_out_time = ?,
                        check_out_type = 'GPS',
                        check_out_latitude = ?,
                        check_out_longitude = ?,
                        check_out_location_id = NULL,
                        check_out_photo = ?,
                        check_out_ip = ?,
                        work_minutes = ?,
                        break_minutes = ?,
                        late_minutes = ?,
                        ot_minutes = ?,
                        early_leave_minutes = ?,
                        status = ?,
                        remarks = CASE WHEN remarks IS NULL OR remarks = '' THEN ? ELSE CONCAT(remarks, ' | ', ?) END,
                        approved_by = ?,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $requestTime,
                    $request['latitude'],
                    $request['longitude'],
                    $request['photo_path'],
                    $request['request_ip'],
                    $metrics['work_minutes'],
                    $metrics['break_minutes'],
                    $metrics['late_minutes'],
                    $metrics['ot_minutes'],
                    $metrics['early_leave_minutes'],
                    $metrics['status'],
                    $note,
                    $note,
                    $user['id'],
                    $attendance['id']
                ]);

                $attendanceId = (int)$attendance['id'];
            }
        }

        $stmt = $pdo->prepare("
            UPDATE hr_attendance_outside_requests
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?, attendance_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$decision, $user['id'], $remarks, $attendanceId, $requestId]);

        $pdo->commit();

        Auth::log('OUTSIDE_LOCATION_REVIEW', 'hr_attendance_outside_requests', $requestId, null, [
            'decision' => $decision,
            'attendance_id' => $attendanceId
        ]);

        apiSuccess([], $decision === 'APPROVED' ? 'อนุมัติคำขอนอกสถานที่เรียบร้อยแล้ว' : 'ไม่อนุมัติคำขอนอกสถานที่เรียบร้อยแล้ว');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiError('เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

/**
 * Get attendance adjustment requests
 */
function getAdjustmentRequests(PDO $pdo, array $user): void {
    $view = $_GET['view'] ?? 'mine';
    $status = strtoupper(trim($_GET['status'] ?? ''));

    $conditions = [];
    $params = [];

    if ($view === 'pending' || $view === 'all') {
        if (!canApproveAttendanceAdjustments()) {
            apiError('ไม่มีสิทธิ์เข้าถึงข้อมูลคำขอ', 403);
        }
        if ($view === 'pending') {
            $conditions[] = "aar.status = 'PENDING'";
        }
    } else {
        $conditions[] = "aar.user_id = ?";
        $params[] = $user['id'];
    }

    if ($status && in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])) {
        $conditions[] = "aar.status = ?";
        $params[] = $status;
    }

    $whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    $sql = "
        SELECT
            aar.*,
            a.attendance_date,
            CONCAT(req.first_name_th, ' ', req.last_name_th) AS requester_name,
            req.employee_code AS requester_code,
            CONCAT(rev.first_name_th, ' ', rev.last_name_th) AS reviewer_name
        FROM hr_attendance_adjustments aar
        JOIN hr_attendances a ON a.id = aar.attendance_id
        JOIN users req ON req.id = aar.user_id
        LEFT JOIN users rev ON rev.id = aar.reviewed_by
        $whereSql
        ORDER BY aar.created_at DESC
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    apiSuccess(['requests' => $requests]);
}

/**
 * Create attendance adjustment request (employee)
 */
function createAdjustmentRequest(PDO $pdo, array $user, array $input): void {
    if (!verifyCsrfToken($input['_token'] ?? '')) {
        apiError('Invalid token', 403);
    }

    $attendanceId = (int)($input['attendance_id'] ?? 0);
    $attendanceDate = trim($input['attendance_date'] ?? '');
    $requestedCheckIn = trim($input['requested_check_in'] ?? '');
    $requestedCheckOut = trim($input['requested_check_out'] ?? '');
    $reason = trim($input['reason'] ?? '');

    if ($attendanceId <= 0 && !$attendanceDate) {
        apiError('กรุณาระบุวันที่ที่ต้องการแก้ไข');
    }
    if ($requestedCheckIn === '' && $requestedCheckOut === '') {
        apiError('กรุณาระบุเวลาเข้างานหรือเวลาออกงานที่ต้องการแก้ไข');
    }
    if (mb_strlen($reason) < 5) {
        apiError('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
    }

    if ($requestedCheckIn !== '' && !preg_match('/^\d{2}:\d{2}$/', $requestedCheckIn)) {
        apiError('รูปแบบเวลาเข้างานไม่ถูกต้อง');
    }
    if ($requestedCheckOut !== '' && !preg_match('/^\d{2}:\d{2}$/', $requestedCheckOut)) {
        apiError('รูปแบบเวลาออกงานไม่ถูกต้อง');
    }

    if ($requestedCheckIn !== '' && $requestedCheckOut !== '' && strtotime($requestedCheckOut) <= strtotime($requestedCheckIn)) {
        apiError('เวลาออกงานต้องมากกว่าเวลาเข้างาน');
    }

    // Find user's attendance by attendance id or date
    if ($attendanceId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE id = ? AND user_id = ?");
        $stmt->execute([$attendanceId, $user['id']]);
    } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
            apiError('รูปแบบวันที่ไม่ถูกต้อง');
        }
        $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?");
        $stmt->execute([$user['id'], $attendanceDate]);
    }

    $attendance = $stmt->fetch();
    if (!$attendance) {
        apiError('ไม่พบข้อมูลการลงเวลาของวันที่ต้องการแก้ไข');
    }

    $attendanceDate = $attendance['attendance_date'];

    // Prevent duplicate pending request per attendance
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hr_attendance_adjustments WHERE attendance_id = ? AND user_id = ? AND status = 'PENDING'");
    $stmt->execute([$attendance['id'], $user['id']]);
    if ((int)$stmt->fetchColumn() > 0) {
        apiError('มีคำขอที่รออนุมัติสำหรับวันดังกล่าวอยู่แล้ว');
    }

    $requestedCheckInFull = $requestedCheckIn !== '' ? ($attendanceDate . ' ' . $requestedCheckIn . ':00') : null;
    $requestedCheckOutFull = $requestedCheckOut !== '' ? ($attendanceDate . ' ' . $requestedCheckOut . ':00') : null;

    $adjustmentType = 'both';
    if ($requestedCheckInFull && !$requestedCheckOutFull) {
        $adjustmentType = 'check_in';
    } elseif (!$requestedCheckInFull && $requestedCheckOutFull) {
        $adjustmentType = 'check_out';
    }

    $stmt = $pdo->prepare("
        INSERT INTO hr_attendance_adjustments (
            attendance_id, user_id, adjustment_type,
            original_check_in, original_check_out,
            requested_check_in, requested_check_out,
            reason, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
    ");
    $stmt->execute([
        $attendance['id'],
        $user['id'],
        $adjustmentType,
        $attendance['check_in_time'],
        $attendance['check_out_time'],
        $requestedCheckInFull,
        $requestedCheckOutFull,
        $reason
    ]);

    $requestId = (int)$pdo->lastInsertId();
    Auth::log('ATTENDANCE_ADJUST_REQUEST', 'hr_attendance_adjustments', $requestId, null, [
        'attendance_id' => $attendance['id'],
        'attendance_date' => $attendanceDate,
        'adjustment_type' => $adjustmentType
    ]);

    apiSuccess(['request_id' => $requestId], 'ส่งคำขอแก้ไขเวลาเรียบร้อยแล้ว');
}

/**
 * Review attendance adjustment request (approver)
 */
function reviewAdjustmentRequest(PDO $pdo, array $user, array $input): void {
    if (!canApproveAttendanceAdjustments()) {
        apiError('ไม่มีสิทธิ์อนุมัติคำขอ', 403);
    }
    if (!verifyCsrfToken($input['_token'] ?? '')) {
        apiError('Invalid token', 403);
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $decision = strtoupper(trim($input['decision'] ?? $input['status'] ?? ''));
    $remarks = trim($input['review_remarks'] ?? '');

    if ($requestId <= 0) {
        apiError('ไม่พบคำขอที่ต้องการดำเนินการ');
    }
    if (!in_array($decision, ['APPROVED', 'REJECTED'])) {
        apiError('สถานะการอนุมัติไม่ถูกต้อง');
    }
    if ($decision === 'REJECTED' && $remarks === '') {
        apiError('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT aar.*, a.attendance_date, a.shift_id
            FROM hr_attendance_adjustments aar
            JOIN hr_attendances a ON a.id = aar.attendance_id
            WHERE aar.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            $pdo->rollBack();
            apiError('ไม่พบคำขอที่ต้องการดำเนินการ');
        }
        if ($request['status'] !== 'PENDING') {
            $pdo->rollBack();
            apiError('คำขอนี้ถูกดำเนินการไปแล้ว');
        }

        if ($decision === 'APPROVED') {
            $checkIn = $request['requested_check_in'] ?: $request['original_check_in'];
            $checkOut = $request['requested_check_out'] ?: $request['original_check_out'];

            $shift = null;
            if (!empty($request['shift_id'])) {
                $stmt = $pdo->prepare("SELECT * FROM hr_work_shifts WHERE id = ?");
                $stmt->execute([$request['shift_id']]);
                $shift = $stmt->fetch();
            }
            if (!$shift) {
                $stmt = $pdo->query("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
                $shift = $stmt->fetch();
            }

            $metrics = calculateAttendanceMetrics($checkIn, $checkOut, $shift, $request['attendance_date']);
            $note = trim('[APPROVED ADJUSTMENT #' . $requestId . '] ' . $remarks);

            $stmt = $pdo->prepare("
                UPDATE hr_attendances SET
                    check_in_time = ?,
                    check_out_time = ?,
                    check_in_type = CASE WHEN ? IS NOT NULL THEN 'MANUAL' ELSE check_in_type END,
                    check_out_type = CASE WHEN ? IS NOT NULL THEN 'MANUAL' ELSE check_out_type END,
                    work_minutes = ?,
                    break_minutes = ?,
                    late_minutes = ?,
                    ot_minutes = ?,
                    early_leave_minutes = ?,
                    status = ?,
                    remarks = CASE
                        WHEN remarks IS NULL OR remarks = '' THEN ?
                        ELSE CONCAT(remarks, ' | ', ?)
                    END,
                    adjusted_by = ?,
                    adjusted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $checkIn,
                $checkOut,
                $checkIn,
                $checkOut,
                $metrics['work_minutes'],
                $metrics['break_minutes'],
                $metrics['late_minutes'],
                $metrics['ot_minutes'],
                $metrics['early_leave_minutes'],
                $metrics['status'],
                $note,
                $note,
                $user['id'],
                $request['attendance_id']
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE hr_attendance_adjustments
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$decision, $user['id'], $remarks, $requestId]);

        $pdo->commit();

        Auth::log('ATTENDANCE_ADJUST_REVIEW', 'hr_attendance_adjustments', $requestId, null, [
            'decision' => $decision,
            'attendance_id' => $request['attendance_id']
        ]);

        apiSuccess([], $decision === 'APPROVED' ? 'อนุมัติคำขอเรียบร้อยแล้ว' : 'ไม่อนุมัติคำขอเรียบร้อยแล้ว');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiError('เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

/**
 * Calculate attendance metrics from manual approved times
 */
function calculateAttendanceMetrics(?string $checkIn, ?string $checkOut, ?array $shift, string $attendanceDate): array {
    $breakMinutes = (int)($shift['break_minutes'] ?? 60);
    $lateMinutes = 0;
    $otMinutes = 0;
    $earlyLeaveMinutes = 0;
    $workMinutes = 0;
    $status = $checkIn ? 'PRESENT' : 'ABSENT';

    if ($checkIn && $shift) {
        $shiftStartTs = strtotime($attendanceDate . ' ' . $shift['start_time']);
        $gracePeriod = ((int)($shift['grace_period_minutes'] ?? 15)) * 60;
        $checkInTs = strtotime($checkIn);

        if ($checkInTs > ($shiftStartTs + $gracePeriod)) {
            $lateMinutes = (int)floor(($checkInTs - $shiftStartTs) / 60);
            $status = 'LATE';
        }
    }

    if ($checkIn && $checkOut) {
        $checkInTs = strtotime($checkIn);
        $checkOutTs = strtotime($checkOut);
        if ($checkOutTs > $checkInTs) {
            $workMinutes = (int)floor(($checkOutTs - $checkInTs) / 60) - $breakMinutes;
            if ($workMinutes < 0) {
                $workMinutes = 0;
            }

            if ($shift) {
                $expectedWorkMinutes = ((int)($shift['work_hours_per_day'] ?? 8)) * 60;
                if ($workMinutes > $expectedWorkMinutes) {
                    $otMinutes = $workMinutes - $expectedWorkMinutes;
                }

                $shiftEndTs = strtotime($attendanceDate . ' ' . $shift['end_time']);
                if ($checkOutTs < $shiftEndTs) {
                    $earlyLeaveMinutes = (int)floor(($shiftEndTs - $checkOutTs) / 60);
                }
            }
        }
    }

    return [
        'work_minutes' => $workMinutes,
        'break_minutes' => $breakMinutes,
        'late_minutes' => $lateMinutes,
        'ot_minutes' => $otMinutes,
        'early_leave_minutes' => $earlyLeaveMinutes,
        'status' => $status,
    ];
}

/**
 * Handle attendance adjustment (HR only)
 */
function handleAdjust(PDO $pdo, array $user, array $input): void {
    // Check HR permission
    if (!isHR()) {
        apiError('ไม่มีสิทธิ์ดำเนินการ', 403);
    }
    
    // Verify CSRF
    if (!verifyCsrfToken($input['_token'] ?? '')) {
        apiError('Invalid token', 403);
    }
    
    $userId = (int)($input['user_id'] ?? 0);
    $date = $input['attendance_date'] ?? '';
    $attendanceId = $input['attendance_id'] ?? '';
    $checkInTime = $input['check_in_time'] ?? '';
    $checkOutTime = $input['check_out_time'] ?? '';
    $note = trim($input['note'] ?? '');
    
    if (!$userId || !$date) {
        apiError('ข้อมูลไม่ครบถ้วน');
    }
    
    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        apiError('รูปแบบวันที่ไม่ถูกต้อง');
    }
    
    // Get existing attendance
    $stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = ?");
    $stmt->execute([$userId, $date]);
    $existing = $stmt->fetch();
    
    // Get default shift
    $stmt = $pdo->query("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
    $shift = $stmt->fetch();
    
    // Prepare times
    $checkInFull = $checkInTime ? "$date $checkInTime:00" : null;
    $checkOutFull = $checkOutTime ? "$date $checkOutTime:00" : null;
    
    // Calculate work hours and late minutes
    $workMinutes = 0;
    $lateMinutes = 0;
    $status = 'PRESENT';
    
    if ($checkInFull && $shift) {
        $shiftStart = strtotime("$date " . $shift['start_time']);
        $gracePeriod = ($shift['grace_period_minutes'] ?? 15) * 60;
        $checkInTs = strtotime($checkInFull);
        
        if ($checkInTs > ($shiftStart + $gracePeriod)) {
            $lateMinutes = floor(($checkInTs - $shiftStart) / 60);
            $status = 'LATE';
        }
    }
    
    if ($checkInFull && $checkOutFull) {
        $workMinutes = floor((strtotime($checkOutFull) - strtotime($checkInFull)) / 60);
        $workMinutes -= ($shift['break_minutes'] ?? 60);
        if ($workMinutes < 0) $workMinutes = 0;
    }
    
    try {
        if ($existing) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE hr_attendances SET
                    check_in_time = COALESCE(?, check_in_time),
                    check_out_time = COALESCE(?, check_out_time),
                    check_in_type = CASE WHEN ? IS NOT NULL THEN 'MANUAL' ELSE check_in_type END,
                    check_out_type = CASE WHEN ? IS NOT NULL THEN 'MANUAL' ELSE check_out_type END,
                    work_minutes = CASE WHEN ? > 0 THEN ? ELSE work_minutes END,
                    late_minutes = CASE WHEN ? IS NOT NULL THEN ? ELSE late_minutes END,
                    status = ?,
                    remarks = ?,
                    adjusted_by = ?,
                    adjusted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $checkInFull, $checkOutFull,
                $checkInFull, $checkOutFull,
                $workMinutes, $workMinutes,
                $checkInFull, $lateMinutes,
                $status,
                $note,
                $user['id'],
                $existing['id']
            ]);
        } else {
            // Create new
            $stmt = $pdo->prepare("
                INSERT INTO hr_attendances (
                    user_id, attendance_date, shift_id,
                    check_in_time, check_in_type,
                    check_out_time, check_out_type,
                    work_minutes, late_minutes, status, remarks,
                    adjusted_by, adjusted_at
                ) VALUES (?, ?, ?, ?, 'MANUAL', ?, 'MANUAL', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId, $date, $shift['id'] ?? null,
                $checkInFull, $checkOutFull,
                $workMinutes, $lateMinutes, $status, $note,
                $user['id']
            ]);
        }
        
        // Log action
        Auth::log('ATTENDANCE_ADJUST', 'hr_attendances', $existing['id'] ?? $pdo->lastInsertId(), [
            'target_user_id' => $userId,
            'date' => $date,
            'note' => $note
        ]);
        
        apiSuccess([], 'บันทึกข้อมูลสำเร็จ');
        
    } catch (Exception $e) {
        apiError('เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}
