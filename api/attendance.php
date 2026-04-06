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
    
    // Validate location (optional)
    $locationId = null;
    if ($latitude && $longitude) {
        $locationId = validateLocation($pdo, $latitude, $longitude);
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
    
    // Validate location
    $locationId = null;
    if ($latitude && $longitude) {
        $locationId = validateLocation($pdo, $latitude, $longitude);
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
                    notes = ?,
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
                    work_minutes, late_minutes, status, notes,
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
