<?php
/**
 * TP-HR Bootstrap
 * ไฟล์เริ่มต้นระบบ - โหลดก่อนทุกหน้า
 */

// Error reporting (ปิดใน production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base path
define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('DOCUMENT_PATH', STORAGE_PATH . '/documents');

// Load environment variables
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Config
require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';

// Core classes
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Helpers.php';

// TP-CRM shared components (ถ้ามี)
$tpCrmPath = dirname(BASE_PATH) . '/tp-crm';
if (file_exists($tpCrmPath . '/bootstrap.php')) {
    define('TP_CRM_PATH', $tpCrmPath);
    define('TP_CRM_AVAILABLE', true);
} else {
    define('TP_CRM_PATH', null);
    define('TP_CRM_AVAILABLE', false);
}

/**
 * Database connection helper
 */
function getDB(): PDO {
    return Database::getInstance()->getConnection();
}

/**
 * Get current user
 */
function getCurrentUser(): ?array {
    return Auth::user();
}

/**
 * Check if user is HR (can view HR pages)
 */
function isHR(): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    
    $hrRoles = ['HR', 'Admin', 'Chairman', 'CEO'];
    return in_array($user['role_name'] ?? '', $hrRoles);
}

/**
 * Check if user is CEO level or above (can manage employees)
 * Only Admin, Chairman, CEO can add/edit/delete employees
 */
function isCEOOrAbove(): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    
    $ceoRoles = ['Admin', 'Chairman', 'CEO'];
    return in_array($user['role_name'] ?? '', $ceoRoles);
}

/**
 * Check if user can manage users (same as isCEOOrAbove)
 */
function canManageUsers(): bool {
    return isCEOOrAbove();
}

/**
 * Check if user can view sensitive data (salary, etc.)
 */
function canViewSensitiveData(): bool {
    return isCEOOrAbove();
}

/**
 * Check if user can approve attendance adjustment requests
 */
function canApproveAttendanceAdjustments(): bool {
    $user = getCurrentUser();
    if (!$user) return false;

    $roles = ['HR', 'Manager', 'Admin', 'Chairman', 'CEO'];
    return in_array($user['role_name'] ?? '', $roles);
}

/**
 * Flash message helper
 */
function flash(string $key, string $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
    } else {
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
}

/**
 * Redirect helper
 */
function redirect(string $url, int $status = 302): void {
    header("Location: $url", true, $status);
    exit;
}

/**
 * CSRF Token
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    return isset($_POST['_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token']);
}

/**
 * Alias for verifyCsrf (compatibility)
 */
function verifyCsrfToken(string $token = null): bool {
    $submittedToken = $token ?? ($_POST['_token'] ?? '');
    return hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken);
}

/**
 * Check if user has specific role (helper function)
 */
function hasRole(string|array $roles): bool {
    return Auth::hasRole($roles);
}

/**
 * Format date in Thai
 */
function formatDateThai(string $date, bool $showTime = false): string {
    if (empty($date)) return '-';
    
    $months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    
    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp) + 543;
    
    $result = "$day $month $year";
    if ($showTime) {
        $result .= ' ' . date('H:i', $timestamp);
    }
    
    return $result;
}

/**
 * Format number with Thai Baht
 */
function formatMoney(float $amount): string {
    return number_format($amount, 2) . ' บาท';
}

/**
 * Get Thai month name (full name)
 */
function thaiMonth(int $month): string {
    $months = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];
    return $months[$month] ?? '';
}

/**
 * Get Thai month name (short)
 */
function thaiMonthShort(int $month): string {
    $months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    return $months[$month] ?? '';
}

/**
 * Get user's full name
 */
function getUserFullName(array $user): string {
    $title = $user['title'] ?? '';
    $firstName = $user['first_name_th'] ?? $user['first_name'] ?? '';
    $lastName = $user['last_name_th'] ?? $user['last_name'] ?? '';
    
    return trim("$title$firstName $lastName");
}

/**
 * Calculate working days between dates
 */
function calculateWorkingDays(string $startDate, string $endDate): float {
    $pdo = getDB();
    
    // Get holidays
    $stmt = $pdo->prepare("SELECT date FROM hr_holidays WHERE date BETWEEN ? AND ? AND is_active = 1");
    $stmt->execute([$startDate, $endDate]);
    $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day');
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    $days = 0;
    foreach ($period as $date) {
        $dayOfWeek = $date->format('N');
        $dateStr = $date->format('Y-m-d');
        
        // Skip weekends (Saturday = 6, Sunday = 7)
        if ($dayOfWeek >= 6) continue;
        
        // Skip holidays
        if (in_array($dateStr, $holidays)) continue;
        
        $days++;
    }
    
    return $days;
}

// Auto-load timezone
date_default_timezone_set('Asia/Bangkok');
