<?php
/**
 * LINE Login API for TP-HR
 * Uses CRM as proxy for LINE OAuth (since HR callback URL is not registered in LINE)
 * 
 * Flow:
 * 1. HR redirects to CRM's LINE login with source=hr
 * 2. CRM handles LINE OAuth and creates one-time token
 * 3. CRM redirects back to HR with token
 * 4. HR validates token and creates session
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? '';

// ============================================
// Action: Start LINE Login via CRM
// ============================================
if ($action === 'login') {
    // Redirect to CRM's LINE login endpoint with source=hr
    header('Location: ' . CRM_BASE_URL . '/api/line_login_callback.php?action=get_login_url&source=hr');
    exit;
}

// ============================================
// Token Callback: Validate token from CRM and create session
// ============================================
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    if (empty($token) || strlen($token) !== 64) {
        header('Location: /login.php?error=line_invalid');
        exit;
    }
    
    try {
        // Validate token from CRM database (shared tp_crm database)
        $stmt = $pdo->prepare("
            SELECT t.*, u.*, r.name as role_name
            FROM cross_domain_tokens t
            JOIN users u ON t.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE t.token = ? 
              AND t.source_system = 'hr'
              AND t.expires_at > NOW()
              AND t.used_at IS NULL
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            error_log("HR LINE Login: Invalid or expired token");
            header('Location: /login.php?error=line_csrf');
            exit;
        }
        
        // Mark token as used
        $updateStmt = $pdo->prepare("UPDATE cross_domain_tokens SET used_at = NOW() WHERE token = ?");
        $updateStmt->execute([$token]);
        
        // Create session (same as Auth::login)
        $_SESSION['user_id'] = $result['user_id'];
        $_SESSION['logged_in_at'] = time();
        $_SESSION['login_method'] = 'line';
        
        // Update last login
        $loginStmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?");
        $loginStmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $result['user_id']]);
        
        // Log activity
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, table_name, record_id, ip_address, user_agent, created_at)
                VALUES (?, 'LOGIN_LINE_HR', 'users', ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $result['user_id'],
                $result['user_id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Ignore logging errors
        }
        
        // Redirect to home
        header('Location: /');
        exit;
        
    } catch (Exception $e) {
        error_log("HR LINE Login error: " . $e->getMessage());
        header('Location: /login.php?error=line_error');
        exit;
    }
}

// Default: redirect to login page
header('Location: /login.php');
exit;
