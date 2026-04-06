<?php
/**
 * Authentication Class
 * ใช้ร่วมกับ TP-CRM
 */
class Auth {
    private static ?array $user = null;
    
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
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['logged_in_at'] = time();
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?");
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
        
        // Log
        self::log('LOGIN', 'users', $user['id']);
        
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
        $_SESSION = [];
        
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        session_destroy();
    }
    
    /**
     * Require user to be logged in
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            if (self::isAjax()) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }
            
            $redirectUrl = urlencode($_SERVER['REQUEST_URI'] ?? '');
            redirect('/tp-hr/login.php?redirect=' . $redirectUrl);
        }
    }
    
    /**
     * Require HR role
     */
    public static function requireHR(): void {
        self::requireLogin();
        
        $user = self::user();
        if (!in_array($user['role_name'] ?? '', HR_ROLES)) {
            if (self::isAjax()) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }
            redirect('/tp-hr/index.php?error=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงส่วนนี้'));
        }
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
     * Check if user has specific permission
     */
    public static function can(string $permission): bool {
        $user = self::user();
        if (!$user) return false;
        
        // Admin always has all permissions
        if (($user['role_name'] ?? '') === 'Admin') {
            return true;
        }
        
        $permissions = json_decode($user['role_permissions'] ?? '[]', true);
        return in_array($permission, $permissions);
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
    
    /**
     * Check if request is AJAX
     */
    private static function isAjax(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
