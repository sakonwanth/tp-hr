<?php
/**
 * Authentication Class
 * ใช้ร่วมกับ TP-CRM
 */
class Auth {
    private static ?array $user = null;

    /** @var \TpCommon\Auth\Acl|null|false Cached ACL; false = tp-common Acl unavailable */
    private static $aclInstance = false;
    
    /**
     * Check if user is logged in
     */
    public static function check(): bool {
        return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
    }
    
    /**
     * Get current logged in user
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }
        
        if (self::$user === null) {
            $pdo = getDB();
            $stmt = $pdo->prepare("
                SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions as role_permissions
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.id = ? AND u.is_active = 1
            ");
            $stmt->execute([$_SESSION['user_id']]);
            self::$user = $stmt->fetch();
            
            if (!self::$user) {
                self::logout();
                return null;
            }
        }
        
        return self::$user;
    }
    
    /**
     * Get user ID
     */
    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Login user
     */
    public static function login(string $username, string $password): array {
        $pdo = getDB();
        
        // Find user by username or email
        $stmt = $pdo->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'ไม่พบชื่อผู้ใช้งาน'];
        }
        
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง'];
        }

        // Mitigate session fixation: new session ID after successful authentication
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        
        // Set session (logged_in required for SSO compatibility with CRM)
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['logged_in'] = true;
        $_SESSION['logged_in_at'] = time();
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?");
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
        
        // Log
        self::log('LOGIN', 'users', $user['id']);

        self::$user = null;
        self::$aclInstance = false;
        
        return ['success' => true, 'user' => $user];
    }
    
    /**
     * Logout user
     */
    public static function logout(): void {
        if (self::check()) {
            self::log('LOGOUT', 'users', self::id());
        }
        
        self::$user = null;
        self::$aclInstance = false;

        if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE
            && class_exists('TpCommon\Session\SharedSession')) {
            \TpCommon\Session\SharedSession::logout();
        } else {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 86400,
                    $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
    }
    
    /**
     * Require user to be logged in.
     * SSO: redirects to CRM login if TpCommon\Auth\SsoGuard is available.
     */
    public static function requireLogin(): void {
        if (self::check()) {
            self::requireUnlockIfNeeded();
            return;
        }

        // SSO — redirect to central CRM login
        if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE
            && class_exists('TpCommon\Auth\SsoGuard')) {
            \TpCommon\Auth\SsoGuard::requireLogin();
            return;
        }

        // Fallback — local login page
        if (self::isAjax()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $redirectUrl = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect('/login.php?redirect=' . $redirectUrl);
    }

    /**
     * Send a signed-in user to the unlock screen when tp-hr has locked itself.
     *
     * Locking is not signing out — the session is intact, and so is the user's
     * session in tp-crm and the rest. Only tp-hr wants the password again,
     * which is the whole point of the per-project lock in tp-common.
     *
     * The unlock page and the logout route are exempt, or there would be no
     * way out of the lock.
     */
    private static function requireUnlockIfNeeded(): void {
        // method_exists as well as class_exists: the deploy pulls tp-common on
        // the server and tolerates that pull failing, so tp-hr can end up new
        // while the library is old. Without this the whole app would fatal on
        // an undefined method; with it, the lock simply does not engage.
        if (!defined('TP_COMMON_AVAILABLE') || !TP_COMMON_AVAILABLE
            || !class_exists('TpCommon\Session\SharedSession')
            || !method_exists('TpCommon\Session\SharedSession', 'needsReauth')
            || !\TpCommon\Session\SharedSession::needsReauth('tp-hr')) {
            return;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (['/unlock.php', '/logout.php', '/login.php'] as $allowed) {
            if ($path === $allowed) return;
        }

        if (self::isAjax()) {
            http_response_code(401);
            echo json_encode(['error' => 'Reauthentication required', 'unlock' => '/unlock.php']);
            exit;
        }

        redirect('/unlock.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /**
     * Require HR role
     */
    public static function requireHR(): void {
        self::requireLogin();

        if (hr_can_access_hr_dashboard()) {
            return;
        }
        if (self::isAjax()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }
        redirect('/index.php?error=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงส่วนนี้'));
    }
    
    /**
     * Check if user has specific role
     */
    public static function hasRole(string|array $roles): bool {
        $user = self::user();
        if (!$user) return false;
        
        $roles = is_array($roles) ? $roles : [$roles];
        return in_array($user['role_name'] ?? '', $roles);
    }
    
    /**
     * TpCommon ACL for dot-notation permissions (Phase C pilot). Null if unavailable.
     */
    public static function acl(): ?\TpCommon\Auth\Acl {
        if (self::$aclInstance !== false) {
            return self::$aclInstance;
        }
        if (!defined('TP_COMMON_AVAILABLE') || !TP_COMMON_AVAILABLE
            || !class_exists(\TpCommon\Auth\Acl::class)) {
            return self::$aclInstance = null;
        }
        $user = self::user();
        if (!$user) {
            return self::$aclInstance = null;
        }
        try {
            $acl = new \TpCommon\Auth\Acl(getDB());
            $acl->loadForUser((int) $user['id']);
            return self::$aclInstance = $acl;
        } catch (\Throwable $e) {
            return self::$aclInstance = null;
        }
    }

    /**
     * Check if user has specific permission
     */
    public static function can(string $permission): bool {
        $user = self::user();
        if (!$user) return false;
        
        // Admin always has all permissions
        if (($user['role_name'] ?? '') === 'Admin') {
            return true;
        }

        $acl = self::acl();
        if ($acl !== null && str_contains($permission, '.') && $acl->can($permission)) {
            return true;
        }
        
        $permissions = json_decode($user['role_permissions'] ?? '[]', true);
        if (!is_array($permissions)) {
            return false;
        }
        return in_array($permission, $permissions, true);
    }
    
    /**
     * Log action
     */
    public static function log(string $action, ?string $table = null, ?int $recordId = null, ?array $oldValues = null, ?array $newValues = null): void {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO hr_audit_logs (user_id, action, module, table_name, record_id, old_values, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $rawAction = $action;
            $action = self::normalizeAuditAction($action);
            if ($rawAction !== $action) {
                $newValues = $newValues ?? [];
                $newValues['_raw_action'] = $rawAction;
            }
            
            $module = 'hr';
            if (strpos($table ?? '', 'hr_') === 0) {
                $module = str_replace(['hr_', '_'], ['', '-'], $table);
            }
            
            $stmt->execute([
                self::id(),
                $action,
                $module,
                $table,
                $recordId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Silent fail for logging
            error_log("Audit log failed: " . $e->getMessage());
        }
    }

    private static function normalizeAuditAction(string $action): string {
        $key = strtoupper(trim(str_replace(['-', ' '], '_', $action)));
        $aliases = [
            'ADD_HOLIDAY' => 'HOLIDAY_ADD',
            'DELETE_HOLIDAY' => 'HOLIDAY_DELETE',
            'UPDATE_LEAVE_TYPE' => 'LEAVE_TYPE_UPDATE',
            'UPDATE_SETTINGS' => 'SETTINGS_UPDATE',
            'UPDATE_SHIFT' => 'SHIFT_UPDATE',
            'PROFILE_UPDATE' => 'PROFILE_UPDATE',
            'EMERGENCY_CONTACT_ADD' => 'EMERGENCY_CONTACT_ADD',
            'EMERGENCY_CONTACT_DELETE' => 'EMERGENCY_CONTACT_DELETE',
            'FAMILY_ADD' => 'FAMILY_MEMBER_ADD',
            'FAMILY_DELETE' => 'FAMILY_MEMBER_DELETE',
            'EDUCATION_ADD' => 'EDUCATION_ADD',
            'EDUCATION_DELETE' => 'EDUCATION_DELETE',
            'WORK_HISTORY_ADD' => 'WORK_HISTORY_ADD',
            'WORK_HISTORY_DELETE' => 'WORK_HISTORY_DELETE',
            'DAYOFF_REQUEST_APPROVE' => 'DAYOFF_REQUEST_APPROVE',
            'DAYOFF_REQUEST_REJECT' => 'DAYOFF_REQUEST_REJECT',
            'DAYOFF_REQUEST_APPROVE_ALL' => 'DAYOFF_REQUEST_APPROVE_ALL',
            'LEAVE_REQUEST' => 'LEAVE_REQUEST_CREATE',
            'LEAVE_CANCEL' => 'LEAVE_REQUEST_CANCEL',
            'LEAVE_APPROVE' => 'LEAVE_REQUEST_APPROVE',
            'LEAVE_REJECT' => 'LEAVE_REQUEST_REJECT',
            'EMPLOYEE_DEACTIVATE' => 'EMPLOYEE_DEACTIVATE',
            'API_KEY_CREATE' => 'API_KEY_CREATE',
            'API_KEY_REVOKE' => 'API_KEY_REVOKE',
            'API_KEY_ACTIVATE' => 'API_KEY_ACTIVATE',
            'CERTIFICATE_REQUEST' => 'CERTIFICATE_REQUEST_CREATE',
            'CERTIFICATE_CANCEL' => 'CERTIFICATE_REQUEST_CANCEL',
            'CERTIFICATE_PROCESS' => 'CERTIFICATE_REQUEST_PROCESS',
            'CERTIFICATE_STATUS_UPDATE' => 'CERTIFICATE_STATUS_UPDATE',
            'CERTIFICATE_COMPLETE' => 'CERTIFICATE_REQUEST_COMPLETE',
            'CERTIFICATE_REJECT' => 'CERTIFICATE_REQUEST_REJECT',
        ];

        return substr($aliases[$key] ?? $key, 0, 50);
    }
    
    /**
     * Check if request is AJAX
     */
    private static function isAjax(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
