<?php
/**
 * TP-HR Bootstrap
 * ไฟล์เริ่มต้นระบบ - โหลดก่อนทุกหน้า
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Base path
define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('DOCUMENT_PATH', STORAGE_PATH . '/documents');

// System/non-person user IDs to exclude from attendance & employee lists
// ID 1 = admin, ID 12 = line_official
define('SYSTEM_USER_IDS', [1, 12]);
define('SYSTEM_USER_IDS_SQL', implode(',', SYSTEM_USER_IDS));

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

// Session — apply lifetime before starting
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_name('TPHRSESSID');
    session_set_cookie_params([
        'lifetime' => 0, // session cookie; server-side gc handles expiry
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout enforcement
    $now = time();
    if (!empty($_SESSION['_last_activity']) && ($now - (int)$_SESSION['_last_activity']) > $lifetime) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_last_activity'] = $now;
}

// Core classes
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Helpers.php';
require_once BASE_PATH . '/core/ApiAuth.php';
require_once BASE_PATH . '/core/WfhStamp.php';

// TP-CRM shared components (ถ้ามี)
$tpCrmPath = dirname(BASE_PATH) . '/tp-crm';
if (file_exists($tpCrmPath . '/bootstrap.php')) {
    define('TP_CRM_PATH', $tpCrmPath);
    define('TP_CRM_AVAILABLE', true);
} else {
    define('TP_CRM_PATH', null);
    define('TP_CRM_AVAILABLE', false);
}

// Auto-stamp WFH attendance for logged-in WFH users (once per session-day).
// Skipped for API calls (/api/v1/*) — those use Bearer auth, no $_SESSION['user'].
if (!empty($_SESSION['user']['id']) && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/v1/') === false) {
    $today = date('Y-m-d');
    if (($_SESSION['_wfh_stamp_date'] ?? '') !== $today) {
        try { WfhStamp::ensureForUser((int)$_SESSION['user']['id'], $today); } catch (Throwable $e) { /* ignore */ }
        $_SESSION['_wfh_stamp_date'] = $today;
    }
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
    return in_array($user['role_name'] ?? '', HR_ROLES);
}

/**
 * Check if user is CEO level or above (can manage employees)
 * Only Admin, Chairman, CEO can add/edit/delete employees
 */
function isCEOOrAbove(): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    return in_array($user['role_name'] ?? '', CEO_ROLES);
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
 * Resolve effective salary for a user based on probation status.
 * - If probation_passed_date IS NULL (still in probation) AND probation_salary is set
 *   → use probation_salary.
 * - Otherwise → use salary.
 *
 * Accepts a user row array (must contain salary, probation_salary, probation_passed_date).
 * Returns float (0.0 if neither salary is set).
 */
function getEffectiveSalary(array $user): float {
    $passed = !empty($user['probation_passed_date']);
    $probSalary = isset($user['probation_salary']) && $user['probation_salary'] !== null && $user['probation_salary'] !== ''
        ? (float)$user['probation_salary'] : null;
    $salary = isset($user['salary']) && $user['salary'] !== null && $user['salary'] !== ''
        ? (float)$user['salary'] : null;

    if (!$passed && $probSalary !== null) return $probSalary;
    return $salary ?? ($probSalary ?? 0.0);
}

/**
 * Flash message helper
 */
function flash(string $key, ?string $message = null) {
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
function verifyCsrfToken(?string $token = null): bool {
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
 * Calculate working days between dates.
 * Excludes company holidays (hr_holidays) and — if $userId is given —
 * the user's per-schedule day_off (respecting approved day-off swaps).
 */
function calculateWorkingDays(string $startDate, string $endDate, ?int $userId = null): float {
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
        $dateStr = $date->format('Y-m-d');

        // Skip company holidays
        if (in_array($dateStr, $holidays)) continue;

        // Skip user's day off (if user supplied)
        if ($userId !== null && function_exists('isDayOff') && isDayOff($dateStr, $userId)) {
            continue;
        }

        $days++;
    }

    return $days;
}

// Auto-load timezone
date_default_timezone_set('Asia/Bangkok');
