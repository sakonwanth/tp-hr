<?php
/**
 * TP-HR Bootstrap
 * ไฟล์เริ่มต้นระบบ - โหลดก่อนทุกหน้า
 */

// Base path
define('BASE_PATH', __DIR__);

// Composer autoload — loads TpCommon namespace
require_once BASE_PATH . '/vendor/autoload.php';

// Shared error handler (registered early; APP_DEBUG resolved lazily)
\TpCommon\ErrorHandler::register('tp-hr', BASE_PATH . '/logs');

define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('DOCUMENT_PATH', STORAGE_PATH . '/documents');

// System/non-person user IDs to exclude from attendance & employee lists
define('SYSTEM_USER_IDS', [1, 12]);
define('SYSTEM_USER_IDS_SQL', implode(',', SYSTEM_USER_IDS));

// Load environment variables via TpCommon\Env
\TpCommon\Env\Env::load(BASE_PATH . '/.env');

// Config (defines constants: APP_NAME, APP_DEBUG, DB_HOST, HR_ROLES, etc.)
require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';

// Session via TpCommon\Auth\Session (replaces inline session_start block)
\TpCommon\Auth\Session::start([
    'name'         => 'TPHRSESSID',
    'lifetime'     => defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200,
    'idle_timeout' => defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200,
]);

// Timezone
date_default_timezone_set('Asia/Bangkok');

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
if (!empty($_SESSION['user']['id']) && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/v1/') === false) {
    $today = date('Y-m-d');
    if (($_SESSION['_wfh_stamp_date'] ?? '') !== $today) {
        try { WfhStamp::ensureForUser((int)$_SESSION['user']['id'], $today); } catch (Throwable $e) { /* ignore */ }
        $_SESSION['_wfh_stamp_date'] = $today;
    }
}

// =====================================================================
// Helper functions (keep legacy signatures — delegate to TpCommon)
// =====================================================================

/**
 * Database connection helper
 */
function getDB(): PDO {
    return Database::getInstance()->getConnection();
}

function getCurrentUser(): ?array {
    return Auth::user();
}

function isHR(): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    return in_array($user['role_name'] ?? '', HR_ROLES);
}

function isCEOOrAbove(): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    return in_array($user['role_name'] ?? '', CEO_ROLES);
}

function canManageUsers(): bool {
    return isCEOOrAbove();
}

function canViewSensitiveData(): bool {
    return isCEOOrAbove();
}

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
 * Flash message helper — delegates to TpCommon\Auth\Session::flash()
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

function redirect(string $url, int $status = 302): void {
    header("Location: $url", true, $status);
    exit;
}

// --- CSRF — delegate to TpCommon\Auth\Csrf ---

function csrfToken(): string {
    return \TpCommon\Auth\Csrf::token();
}

function csrfField(): string {
    return \TpCommon\Auth\Csrf::field('_token');
}

function verifyCsrf(): bool {
    return \TpCommon\Auth\Csrf::verify();
}

function verifyCsrfToken(?string $token = null): bool {
    return \TpCommon\Auth\Csrf::verify($token);
}

// --- Role helpers ---

function hasRole(string|array $roles): bool {
    return Auth::hasRole($roles);
}

// --- Date/Money helpers — delegate to TpCommon\Helpers\Date ---

function formatDateThai(string $date, bool $showTime = false): string {
    return \TpCommon\Helpers\Date::thai($date, $showTime);
}

function formatMoney(float $amount): string {
    return \TpCommon\Helpers\Date::money($amount);
}

function thaiMonth(int $month): string {
    return \TpCommon\Helpers\Date::thaiMonth($month);
}

function thaiMonthShort(int $month): string {
    return \TpCommon\Helpers\Date::thaiMonthShort($month);
}

function getUserFullName(array $user): string {
    $title = $user['title'] ?? '';
    $firstName = $user['first_name_th'] ?? $user['first_name'] ?? '';
    $lastName = $user['last_name_th'] ?? $user['last_name'] ?? '';
    return trim("$title$firstName $lastName");
}

/**
 * Calculate working days between dates.
 */
function calculateWorkingDays(string $startDate, string $endDate, ?int $userId = null): float {
    $pdo = getDB();

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
        if (in_array($dateStr, $holidays)) continue;
        if ($userId !== null && function_exists('isDayOff') && isDayOff($dateStr, $userId)) {
            continue;
        }
        $days++;
    }

    return $days;
}
