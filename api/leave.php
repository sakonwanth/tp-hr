<?php
/**
 * Leave API
 * API สำหรับระบบลา
 */

require_once __DIR__ . '/../bootstrap.php';
require_once BASE_PATH . '/core/CrmLineNotifierBridge.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && empty($_POST) && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonInput = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($jsonInput)) {
        $_POST = $jsonInput;
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo, $user, $action);
            break;
        case 'POST':
            handlePost($pdo, $user, $action);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    tpHrLogException($e, 'api/leave');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง']);
}

function handleGet($pdo, $user, $action) {
    switch ($action) {
        case 'entitlements':
            getEntitlements($pdo, $user);
            break;
            
        case 'history':
            getHistory($pdo, $user);
            break;
            
        case 'detail':
            getDetail($pdo, $user);
            break;
            
        case 'pending':
            getPending($pdo, $user);
            break;
            
        case 'calendar':
            getCalendar($pdo, $user);
            break;
            
        default:
            getEntitlements($pdo, $user);
    }
}

function handlePost($pdo, $user, $action) {
    // Verify CSRF token
    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        return;
    }
    
    switch ($action) {
        case 'create':
            createLeaveRequest($pdo, $user);
            break;
            
        case 'cancel':
            cancelLeaveRequest($pdo, $user);
            break;
            
        case 'approve':
            approveLeaveRequest($pdo, $user);
            break;
            
        case 'reject':
            rejectLeaveRequest($pdo, $user);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

/**
 * Resolve subject user for leave read APIs: self, or HR dashboard ?user_id= (validated).
 * Cross-user reads require hr_can_access_hr_dashboard() — same gate as /hr/employees.php (not broad MANAGER_ROLES).
 *
 * @return int Subject users.id, or -1 if HR requested an unknown/system user.
 */
function leaveApiResolveSubjectUserId(PDO $pdo, array $user): int {
    $self = (int) $user['id'];
    if (!isset($_GET['user_id']) || !hr_can_access_hr_dashboard()) {
        return $self;
    }
    $req = (int) $_GET['user_id'];
    if ($req <= 0) {
        return $self;
    }
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = ? AND id NOT IN (' . SYSTEM_USER_IDS_SQL . ') LIMIT 1');
    $chk->execute([$req]);
    if (!$chk->fetchColumn()) {
        return -1;
    }
    return $req;
}

/**
 * Get leave entitlements
 */
function getEntitlements($pdo, $user) {
    $year = (int)($_GET['year'] ?? date('Y'));
    $userId = leaveApiResolveSubjectUserId($pdo, $user);
    if ($userId < 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ไม่พบพนักงาน']);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT lt.id, lt.name, lt.code, lt.color as color_code,
               COALESCE(le.entitled_days, lt.default_days_per_year) as entitled_days,
               COALESCE(le.carried_over_days, 0) as carried_over,
               COALESCE(le.used_days, 0) as used_days,
               COALESCE(le.pending_days, 0) as pending_days
        FROM hr_leave_types lt
        LEFT JOIN hr_leave_entitlements le ON lt.id = le.leave_type_id 
            AND le.user_id = ? AND le.year = ?
        WHERE lt.is_active = 1
        ORDER BY lt.sort_order
    ");
    $stmt->execute([$userId, $year]);
    $entitlements = $stmt->fetchAll();
    
    // Calculate remaining
    foreach ($entitlements as &$e) {
        $e['total_available'] = $e['entitled_days'] + $e['carried_over'];
        $e['remaining_days'] = $e['total_available'] - $e['used_days'] - $e['pending_days'];
        $e['remaining'] = $e['remaining_days'];
        $e['leave_type_name'] = $e['name'];
    }
    
    echo json_encode([
        'success' => true,
        'subject_user_id' => $userId,
        'year' => $year,
        'entitlements' => $entitlements
    ]);
}

/**
 * Get leave history (self; HR dashboard may pass ?user_id=)
 */
function getHistory($pdo, $user) {
    $year = (int)($_GET['year'] ?? date('Y'));
    $userId = leaveApiResolveSubjectUserId($pdo, $user);
    if ($userId < 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ไม่พบพนักงาน']);
        return;
    }

    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? DEFAULT_PER_PAGE);
    $offset = ($page - 1) * $limit;
    
    $sql = "
        SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
               lr.approver_1_remarks AS approver_comment,
               CONCAT(approver.first_name_th, ' ', approver.last_name_th) as approved_by_name
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN users approver ON lr.final_approved_by = approver.id
        WHERE lr.user_id = ? AND YEAR(lr.start_date) = ?
    ";
    $params = [$userId, $year];
    
    if ($type) {
        $sql .= " AND lr.leave_type_id = ?";
        $params[] = (int)$type;
    }
    
    if ($status) {
        $sql .= " AND lr.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY lr.created_at DESC LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM hr_leave_requests lr
        WHERE lr.user_id = ? AND YEAR(lr.start_date) = ?
    ";
    $countParams = [$userId, $year];
    if ($type) {
        $countSql .= " AND lr.leave_type_id = ?";
        $countParams[] = (int)$type;
    }
    if ($status) {
        $countSql .= " AND lr.status = ?";
        $countParams[] = $status;
    }
    
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($countParams);
    $total = $stmtCount->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'subject_user_id' => $userId,
        'requests' => $requests,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Get single leave request detail
 */
function getDetail($pdo, $user) {
    $id = (int)($_GET['id'] ?? 0);
    
    $canViewOthers = hr_can_access_hr_dashboard();

    $stmt = $pdo->prepare("
        SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
               lr.approver_1_remarks AS approver_comment,
               CONCAT(approver.first_name_th, ' ', approver.last_name_th) as approved_by_name,
               CONCAT(u.first_name_th, ' ', u.last_name_th) as user_name, u.email as user_email
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
        JOIN users u ON lr.user_id = u.id
        LEFT JOIN users approver ON lr.final_approved_by = approver.id
        WHERE lr.id = ? AND (lr.user_id = ? OR ? = 1)
    ");

    $stmt->execute([$id, $user['id'], $canViewOthers ? 1 : 0]);
    $request = $stmt->fetch();
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'request' => $request
    ]);
}

/**
 * Get pending requests (HR dashboard — same as /hr/leaves.php)
 */
function getPending($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์เข้าถึง']);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
               CONCAT(u.first_name_th, ' ', u.last_name_th) as user_name, u.employee_code,
               u.department as department_name
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
        JOIN users u ON lr.user_id = u.id
        WHERE lr.status = 'PENDING'
        ORDER BY lr.created_at ASC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'requests' => $requests
    ]);
}

/**
 * Get calendar data
 */
function getCalendar($pdo, $user) {
    $month = (int)($_GET['month'] ?? date('m'));
    $year = (int)($_GET['year'] ?? date('Y'));
    
    $startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $endDate = date('Y-m-t', strtotime($startDate));

    // Company-wide calendar only for HR dashboard; everyone else sees own leaves only.
    $canViewAllLeaves = hr_can_access_hr_dashboard();

    $sql = "
        SELECT lr.id, lr.start_date, lr.end_date, lr.total_days, lr.status,
               lt.name as leave_type_name, lt.color as color_code,
               CONCAT(u.first_name_th, ' ', u.last_name_th) as user_name
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
        JOIN users u ON lr.user_id = u.id
        WHERE lr.status IN ('PENDING', 'APPROVED')
        AND ((lr.start_date BETWEEN ? AND ?) OR (lr.end_date BETWEEN ? AND ?))
    ";
    $params = [$startDate, $endDate, $startDate, $endDate];
    if (!$canViewAllLeaves) {
        $sql .= " AND lr.user_id = ?";
        $params[] = (int) $user['id'];
    }
    $sql .= " ORDER BY lr.start_date";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
    
    // Get holidays
    $stmtHolidays = $pdo->prepare("
        SELECT date, name, (type = 'PUBLIC') as is_national_holiday
        FROM hr_holidays
        WHERE date BETWEEN ? AND ? AND is_active = 1
    ");
    $stmtHolidays->execute([$startDate, $endDate]);
    $holidays = $stmtHolidays->fetchAll();
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'holidays' => $holidays
    ]);
}

/**
 * Create new leave request
 */
function createLeaveRequest($pdo, $user) {
    $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $startPeriod = $_POST['start_period'] ?? 'FULL';
    $endPeriod = $_POST['end_period'] ?? 'FULL';
    $totalDays = (float)($_POST['total_days'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    
    // Validate
    if (!$leaveTypeId || !$startDate || !$endDate || !$reason) {
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    if ($totalDays <= 0) {
        echo json_encode(['success' => false, 'error' => 'จำนวนวันลาไม่ถูกต้อง']);
        return;
    }
    
    // Validate dates
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if ($end < $start) {
        echo json_encode(['success' => false, 'error' => 'วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น']);
        return;
    }
    
    // Get leave type info
    $stmt = $pdo->prepare("SELECT * FROM hr_leave_types WHERE id = ? AND is_active = 1");
    $stmt->execute([$leaveTypeId]);
    $leaveType = $stmt->fetch();
    
    if (!$leaveType) {
        echo json_encode(['success' => false, 'error' => 'ประเภทการลาไม่ถูกต้อง']);
        return;
    }
    
    // ย้อนหลังได้ — กฎขอล่วงหน้าใช้เฉพาะวันลาในอนาคต (ลาป่วยยกเว้นเสมอ)
    $minRetroDate = (clone $today)->modify('-365 days');
    if ($start < $minRetroDate) {
        echo json_encode(['success' => false, 'error' => 'ไม่สามารถยื่นลาย้อนหลังเกิน 1 ปี']);
        return;
    }
    if ($leaveType['min_days_advance'] > 0 && $leaveType['code'] !== 'SICK' && $start > $today) {
        $daysAdvance = $today->diff($start)->days;
        if ($daysAdvance < $leaveType['min_days_advance']) {
            echo json_encode([
                'success' => false,
                'error' => "การลา{$leaveType['name']}ต้องขอล่วงหน้าอย่างน้อย {$leaveType['min_days_advance']} วัน"
            ]);
            return;
        }
    }
    
    // Check max consecutive days
    if ($leaveType['max_consecutive_days'] && $totalDays > $leaveType['max_consecutive_days']) {
        echo json_encode([
            'success' => false, 
            'error' => "การลา{$leaveType['name']}ลาติดต่อกันได้ไม่เกิน {$leaveType['max_consecutive_days']} วัน"
        ]);
        return;
    }
    
    $leaveYear = (int)date('Y', strtotime($startDate));

    // Check entitlement (ปีตามวันเริ่มลา — รองรับย้อนหลัง)
    $stmt = $pdo->prepare("
        SELECT * FROM hr_leave_entitlements 
        WHERE user_id = ? AND leave_type_id = ? AND year = ?
    ");
    $stmt->execute([$user['id'], $leaveTypeId, $leaveYear]);
    $entitlement = $stmt->fetch();
    
    if (!$entitlement) {
        // Create default entitlement
        $stmt = $pdo->prepare("
            INSERT INTO hr_leave_entitlements (user_id, leave_type_id, year, entitled_days)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $leaveTypeId, $leaveYear, $leaveType['default_days_per_year']]);
        
        $entitlement = [
            'entitled_days' => $leaveType['default_days_per_year'],
            'carried_over_days' => 0,
            'used_days' => 0,
            'pending_days' => 0
        ];
    }
    
    $available = $entitlement['entitled_days'] + ($entitlement['carried_over_days'] ?? 0) 
                - $entitlement['used_days'] - $entitlement['pending_days'];
    
    // Only check for limited leave types
    if (!$leaveType['is_paid'] || $leaveType['code'] === 'ANNUAL' || $leaveType['code'] === 'PERSONAL') {
        if ($totalDays > $available) {
            echo json_encode([
                'success' => false, 
                'error' => "วันลาคงเหลือไม่เพียงพอ (คงเหลือ " . number_format($available, 1) . " วัน)"
            ]);
            return;
        }
    }
    
    // Check for overlapping requests
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM hr_leave_requests 
        WHERE user_id = ? AND status IN ('PENDING', 'APPROVED')
        AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))
    ");
    $stmt->execute([$user['id'], $endDate, $startDate, $startDate, $startDate]);
    $overlapping = $stmt->fetchColumn();
    
    if ($overlapping > 0) {
        echo json_encode(['success' => false, 'error' => 'มีคำขอลาในช่วงวันที่นี้อยู่แล้ว']);
        return;
    }
    
    // Handle file upload
    $documentPath = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['document'], 'leave_documents', [
            'types' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size' => MAX_UPLOAD_SIZE
        ]);
        
        if ($uploadResult['success']) {
            $documentPath = $uploadResult['path'];
        }
    }
    
    // Check if document is required
    if ($leaveType['requires_document'] && !$documentPath && $totalDays >= ($leaveType['document_after_days'] ?? 1)) {
        echo json_encode(['success' => false, 'error' => "การลา{$leaveType['name']}ต้องแนบเอกสารประกอบ"]);
        return;
    }
    
    // Generate request number
    $requestNumber = generateRunningNumber($pdo, 'LEAVE', 'LV');
    
    $pdo->beginTransaction();
    try {
        // Insert leave request
        $stmt = $pdo->prepare("
            INSERT INTO hr_leave_requests (
                request_number, user_id, leave_type_id, start_date, end_date,
                start_period, end_period, total_days, reason, contact_number,
                document_path, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        $stmt->execute([
            $requestNumber, $user['id'], $leaveTypeId, $startDate, $endDate,
            $startPeriod, $endPeriod, $totalDays, $reason, $contactNumber,
            $documentPath
        ]);
        $requestId = $pdo->lastInsertId();
        
        // Update pending days in entitlement
        $stmt = $pdo->prepare("
            UPDATE hr_leave_entitlements 
            SET pending_days = pending_days + ?
            WHERE user_id = ? AND leave_type_id = ? AND year = ?
        ");
        $stmt->execute([$totalDays, $user['id'], $leaveTypeId, $leaveYear]);
        
        // Log action
        Auth::log('leave_request', 'hr_leave_requests', $requestId, null, [
            'request_number' => $requestNumber,
            'leave_type' => $leaveType['name'],
            'days' => $totalDays
        ]);
        
        $pdo->commit();

        crm_line_notify_new_leave($pdo, (int)$requestId);
        
        echo json_encode([
            'success' => true,
            'message' => 'ส่งคำขอลาสำเร็จ',
            'request_id' => $requestId,
            'request_number' => $requestNumber
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cancel leave request
 */
function cancelLeaveRequest($pdo, $user) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    
    // Get request
    $stmt = $pdo->prepare("SELECT * FROM hr_leave_requests WHERE id = ? AND user_id = ?");
    $stmt->execute([$requestId, $user['id']]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูลคำขอลา']);
        return;
    }
    
    if ($request['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'ไม่สามารถยกเลิกคำขอที่ได้รับการพิจารณาแล้ว']);
        return;
    }
    
    $pdo->beginTransaction();
    try {
        // Update status
        $stmt = $pdo->prepare("UPDATE hr_leave_requests SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$requestId]);
        
        // Update pending days
        $stmt = $pdo->prepare("
            UPDATE hr_leave_entitlements 
            SET pending_days = GREATEST(0, pending_days - ?)
            WHERE user_id = ? AND leave_type_id = ? AND year = ?
        ");
        $stmt->execute([$request['total_days'], $user['id'], $request['leave_type_id'], date('Y', strtotime($request['start_date']))]);
        
        // Log action
        Auth::log('leave_cancel', 'hr_leave_requests', $requestId, null, [
            'request_number' => $request['request_number']
        ]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'ยกเลิกคำขอลาสำเร็จ']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Approve leave request (HR dashboard — same gate as /hr/leaves.php)
 */
function approveLeaveRequest($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์ดำเนินการ']);
        return;
    }
    
    $requestId = (int)($_POST['request_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    
    // Get request
    $stmt = $pdo->prepare("SELECT * FROM hr_leave_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูลคำขอลา']);
        return;
    }
    
    if ($request['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'คำขอนี้ได้รับการพิจารณาแล้ว']);
        return;
    }
    
    $pdo->beginTransaction();
    try {
        $actorId = (int)$user['id'];
        // Schema: final_approved_* + approver_1_* (ไม่มี approved_by / approver_comment)
        $stmt = $pdo->prepare("
            UPDATE hr_leave_requests 
            SET status = 'APPROVED',
                final_approved_by = ?,
                final_approved_at = NOW(),
                approver_1_id = COALESCE(approver_1_id, ?),
                approver_1_status = 'APPROVED',
                approver_1_date = NOW(),
                approver_1_remarks = COALESCE(NULLIF(?, ''), approver_1_remarks),
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$actorId, $actorId, $comment, $requestId]);
        
        // Move from pending to used
        $stmt = $pdo->prepare("
            UPDATE hr_leave_entitlements 
            SET pending_days = GREATEST(0, pending_days - ?),
                used_days = used_days + ?
            WHERE user_id = ? AND leave_type_id = ? AND year = ?
        ");
        $stmt->execute([
            $request['total_days'], $request['total_days'],
            $request['user_id'], $request['leave_type_id'], 
            date('Y', strtotime($request['start_date']))
        ]);

        // Log action
        Auth::log('leave_approve', 'hr_leave_requests', $requestId, null, [
            'request_number' => $request['request_number']
        ]);
        
        $pdo->commit();

        $actorName = trim(($user['first_name_th'] ?? '') . ' ' . ($user['last_name_th'] ?? '')) ?: ($user['username'] ?? 'system');
        try {
            crm_line_sync_approved_leave_attendance($pdo, (int)$requestId, $actorId, $actorName);
        } catch (Throwable $syncErr) {
            tpHrLogException($syncErr, 'api/leave/approve-attendance-sync');
        }

        crm_line_notify_leave_decision($pdo, (int)$requestId, 'APPROVED', $comment);
        
        echo json_encode(['success' => true, 'message' => 'อนุมัติคำขอลาสำเร็จ']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reject leave request (HR dashboard — same gate as /hr/leaves.php)
 */
function rejectLeaveRequest($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์ดำเนินการ']);
        return;
    }
    
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if (!$reason) {
        echo json_encode(['success' => false, 'error' => 'กรุณาระบุเหตุผลในการไม่อนุมัติ']);
        return;
    }
    
    // Get request
    $stmt = $pdo->prepare("SELECT * FROM hr_leave_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูลคำขอลา']);
        return;
    }
    
    if ($request['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'คำขอนี้ได้รับการพิจารณาแล้ว']);
        return;
    }
    
    $pdo->beginTransaction();
    try {
        $actorId = (int)$user['id'];
        $stmt = $pdo->prepare("
            UPDATE hr_leave_requests 
            SET status = 'REJECTED',
                final_approved_by = ?,
                final_approved_at = NOW(),
                approver_1_id = COALESCE(approver_1_id, ?),
                approver_1_status = 'REJECTED',
                approver_1_date = NOW(),
                approver_1_remarks = ?,
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$actorId, $actorId, $reason, $requestId]);
        
        // Release pending days
        $stmt = $pdo->prepare("
            UPDATE hr_leave_entitlements 
            SET pending_days = GREATEST(0, pending_days - ?)
            WHERE user_id = ? AND leave_type_id = ? AND year = ?
        ");
        $stmt->execute([
            $request['total_days'],
            $request['user_id'], $request['leave_type_id'], 
            date('Y', strtotime($request['start_date']))
        ]);
        
        // Log action
        Auth::log('leave_reject', 'hr_leave_requests', $requestId, null, [
            'request_number' => $request['request_number'],
            'reason' => $reason
        ]);
        
        $pdo->commit();

        crm_line_notify_leave_decision($pdo, (int)$requestId, 'REJECTED', $reason);
        
        echo json_encode(['success' => true, 'message' => 'ไม่อนุมัติคำขอลาสำเร็จ']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
