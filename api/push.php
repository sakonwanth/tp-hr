<?php
/**
 * Web Push subscription API
 * รับ-ถอน subscription ของ PWA (เรียกจาก assets/js/pwa.js)
 *
 * GET  ?action=config      → VAPID public key + สถานะปัจจุบันของผู้ใช้
 * POST action=subscribe    → บันทึก subscription
 * POST action=unsubscribe  → ลบ subscription
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::user();
$userId = (int)($user['id'] ?? 0);

$pdo = Database::getInstance()->getConnection();
$push = new PushService($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// The PWA posts JSON, matching api/leave.php.
if ($method === 'POST' && empty($_POST) && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonInput = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($jsonInput)) {
        $_POST = $jsonInput;
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'config') {
        echo json_encode([
            'success'    => true,
            'enabled'    => $push->isConfigured(),
            'public_key' => $push->isConfigured() ? $push->publicKey() : '',
            'subscribed' => $push->countForUser($userId) > 0,
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าใหม่']);
        exit;
    }

    if (!$push->isConfigured()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'ยังไม่ได้เปิดใช้งานการแจ้งเตือน']);
        exit;
    }

    switch ($action) {
        case 'subscribe':
            $subscription = $_POST['subscription'] ?? null;
            if (!is_array($subscription)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'subscription ไม่ถูกต้อง']);
                exit;
            }
            $ok = $push->subscribe($userId, $subscription, $_SERVER['HTTP_USER_AGENT'] ?? null);
            echo json_encode(['success' => $ok]);
            break;

        case 'unsubscribe':
            $endpoint = trim((string)($_POST['endpoint'] ?? ''));
            $ok = $push->unsubscribe($userId, $endpoint);
            echo json_encode(['success' => $ok]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'action ไม่ถูกต้อง']);
    }
} catch (Throwable $e) {
    tpHrLogException($e, 'api/push');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง']);
}
