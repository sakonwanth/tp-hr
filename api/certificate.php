<?php
/**
 * Certificate API
 * API สำหรับขอหนังสือรับรอง
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
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
    tpHrLogException($e, 'api/certificate');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด']);
}

function handleGet($pdo, $user, $action) {
    switch ($action) {
        case 'templates':
            getTemplates($pdo);
            break;
            
        case 'requests':
            getRequests($pdo, $user);
            break;
            
        case 'detail':
            getDetail($pdo, $user);
            break;
            
        default:
            getRequests($pdo, $user);
    }
}

function handlePost($pdo, $user, $action) {
    // Verify CSRF token
    if (!verifyCsrf()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        return;
    }
    
    switch ($action) {
        case 'create':
            createRequest($pdo, $user);
            break;
            
        case 'cancel':
            cancelRequest($pdo, $user);
            break;
            
        // HR Actions
        case 'process':
            processRequest($pdo, $user);
            break;
            
        case 'update_status':
            updateStatus($pdo, $user);
            break;
            
        case 'complete':
            completeRequest($pdo, $user);
            break;
            
        case 'reject':
            rejectRequest($pdo, $user);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

/**
 * Get document templates
 */
function getTemplates($pdo) {
    $stmt = $pdo->query("SELECT * FROM hr_document_templates WHERE is_active = 1 ORDER BY sort_order");
    $templates = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'templates' => $templates]);
}

/**
 * Get user's requests
 */
function getRequests($pdo, $user) {
    $status = $_GET['status'] ?? '';
    
    $sql = "
        SELECT dr.*, dt.name as template_name, 
               id.file_path, id.issued_date
        FROM hr_document_requests dr
        JOIN hr_document_templates dt ON dr.template_id = dt.id
        LEFT JOIN hr_issued_documents id ON dr.id = id.request_id
        WHERE dr.user_id = ?
    ";
    $params = [$user['id']];
    
    if ($status) {
        $sql .= " AND dr.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY dr.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'requests' => $requests]);
}

/**
 * Get single request detail
 */
function getDetail($pdo, $user) {
    $id = (int)($_GET['id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT dr.*, dt.name as template_name, dt.description as template_desc,
               id.file_path, id.issued_date, id.issued_by,
               issuer.first_name_th as issuer_first, issuer.last_name_th as issuer_last
        FROM hr_document_requests dr
        JOIN hr_document_templates dt ON dr.template_id = dt.id
        LEFT JOIN hr_issued_documents id ON dr.id = id.request_id
        LEFT JOIN users issuer ON id.issued_by = issuer.id
        WHERE dr.id = ? AND (dr.user_id = ? OR ? = 1)
    ");
    
    $canHrDash = hr_can_access_hr_dashboard() ? 1 : 0;
    $stmt->execute([$id, $user['id'], $canHrDash]);
    $request = $stmt->fetch();
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    echo json_encode(['success' => true, 'request' => $request]);
}

/**
 * Create new certificate request
 */
function createRequest($pdo, $user) {
    $templateId = (int)($_POST['template_id'] ?? 0);
    $language = $_POST['language'] ?? 'TH';
    $copies = max(1, min(10, (int)($_POST['copies'] ?? 1)));
    $purpose = trim($_POST['purpose'] ?? '');
    $purposeDetail = trim($_POST['purpose_detail'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $isUrgent = isset($_POST['is_urgent']) && $_POST['is_urgent'] == '1';
    
    // Validate
    if (!$templateId || !$purpose) {
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }
    
    // Get template
    $stmt = $pdo->prepare("SELECT * FROM hr_document_templates WHERE id = ? AND is_active = 1");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบประเภทเอกสาร']);
        return;
    }
    
    // Check for duplicate pending request
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM hr_document_requests 
        WHERE user_id = ? AND template_id = ? AND status IN ('PENDING', 'PROCESSING')
    ");
    $stmt->execute([$user['id'], $templateId]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'คุณมีคำขอเอกสารประเภทนี้ที่รอดำเนินการอยู่แล้ว']);
        return;
    }
    
    // Combine purpose
    $fullPurpose = $purpose;
    if ($purpose === 'OTHER' && $purposeDetail) {
        $fullPurpose = $purposeDetail;
    }
    
    // Generate request number
    $requestNumber = generateRunningNumber($pdo, 'DOC_REQUEST', 'DR');
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO hr_document_requests (
                request_number, user_id, template_id, language, copies,
                purpose, purpose_detail, remarks, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        $stmt->execute([
            $requestNumber, $user['id'], $templateId, $language, $copies,
            $fullPurpose, $purposeDetail, $notes
        ]);
        $requestId = $pdo->lastInsertId();
        
        // Log action
        Auth::log('certificate_request', 'hr_document_requests', $requestId, null, [
            'request_number' => $requestNumber,
            'template' => $template['name']
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'ส่งคำขอเรียบร้อยแล้ว',
            'request_id' => $requestId,
            'request_number' => $requestNumber
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cancel request
 */
function cancelRequest($pdo, $user) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    
    // Get request
    $stmt = $pdo->prepare("SELECT * FROM hr_document_requests WHERE id = ? AND user_id = ?");
    $stmt->execute([$requestId, $user['id']]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูลคำขอ']);
        return;
    }
    
    if ($request['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'ไม่สามารถยกเลิกคำขอที่กำลังดำเนินการแล้ว']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE hr_document_requests SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$requestId]);
    
    Auth::log('certificate_cancel', 'hr_document_requests', $requestId, null, [
        'request_number' => $request['request_number']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'ยกเลิกคำขอเรียบร้อยแล้ว']);
}

/**
 * Process request (HR)
 */
function processRequest($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์ดำเนินการ']);
        return;
    }
    
    $requestId = (int)($_POST['request_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT * FROM hr_document_requests WHERE id = ? AND status = 'PENDING'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบคำขอที่รอดำเนินการ']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE hr_document_requests SET status = 'PROCESSING', processed_by = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id'], $requestId]);
    
    Auth::log('certificate_process', 'hr_document_requests', $requestId);
    
    echo json_encode(['success' => true, 'message' => 'รับเรื่องเรียบร้อยแล้ว']);
}

/**
 * Update request status (HR)
 */
function updateStatus($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์ดำเนินการ']);
        return;
    }
    
    $requestId = (int)($_POST['request_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    
    $allowedStatuses = ['PENDING', 'PROCESSING', 'COMPLETED'];
    if (!in_array($status, $allowedStatuses)) {
        echo json_encode(['success' => false, 'error' => 'สถานะไม่ถูกต้อง']);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM hr_document_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบคำขอ']);
        return;
    }
    
    if ($status === 'PROCESSING') {
        $stmt = $pdo->prepare("UPDATE hr_document_requests SET status = 'PROCESSING', processed_by = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id'], $requestId]);
    } else {
        $stmt = $pdo->prepare("UPDATE hr_document_requests SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $requestId]);
    }
    
    Auth::log('certificate_status_update', 'hr_document_requests', $requestId, null, [
        'new_status' => $status
    ]);
    
    echo json_encode(['success' => true, 'message' => 'อัปเดตสถานะสำเร็จ']);
}

/**
 * Complete request (HR)
 */
function completeRequest($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์ดำเนินการ']);
        return;
    }
    
    $requestId = (int)($_POST['request_id'] ?? 0);
    $documentNumber = trim($_POST['document_number'] ?? '');
    
    $stmt = $pdo->prepare("SELECT * FROM hr_document_requests WHERE id = ? AND status IN ('PENDING', 'PROCESSING')");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบคำขอ']);
        return;
    }
    
    // Handle file upload
    $filePath = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['document'], 'certificates', [
            'types' => ['pdf'],
            'max_size' => 10 * 1024 * 1024
        ]);
        
        if ($uploadResult['success']) {
            $filePath = $uploadResult['path'];
        }
    }
    
    $pdo->beginTransaction();
    try {
        // Update request status
        $stmt = $pdo->prepare("UPDATE hr_document_requests SET status = 'COMPLETED', completed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$requestId]);
        
        // Create issued document record
        if ($documentNumber || $filePath) {
            $stmt = $pdo->prepare("
                INSERT INTO hr_issued_documents (request_id, document_number, file_path, issued_by, issued_date)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$requestId, $documentNumber, $filePath, $user['id']]);
        }
        
        Auth::log('certificate_complete', 'hr_document_requests', $requestId, null, [
            'document_number' => $documentNumber
        ]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'ออกเอกสารเรียบร้อยแล้ว']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reject request (HR)
 */
function rejectRequest($pdo, $user) {
    if (!hr_can_access_hr_dashboard()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'ไม่มีสิทธิ์ดำเนินการ']);
        return;
    }
    
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if (!$reason) {
        echo json_encode(['success' => false, 'error' => 'กรุณาระบุเหตุผล']);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM hr_document_requests WHERE id = ? AND status IN ('PENDING', 'PROCESSING')");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบคำขอ']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE hr_document_requests SET status = 'REJECTED', reject_reason = ?, rejected_by = ?, rejected_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$reason, $user['id'], $requestId]);
    
    Auth::log('certificate_reject', 'hr_document_requests', $requestId, null, [
        'reason' => $reason
    ]);
    
    echo json_encode(['success' => true, 'message' => 'บันทึกผลเรียบร้อยแล้ว']);
}
