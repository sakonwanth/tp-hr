<?php
/**
 * TP-HR Bootstrap
 * ไฟล์เริ่มต้นระบบ - โหลดก่อนทุกหน้า
 */

// Base path
define('BASE_PATH', __DIR__);

// Detect TpCommon availability (local dev has vendor/ from composer install)
$_autoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($_autoload)) {
    require_once $_autoload;
}
define('TP_COMMON_AVAILABLE', class_exists('TpCommon\\ErrorHandler'));

if (TP_COMMON_AVAILABLE) {
    \TpCommon\ErrorHandler::register('tp-hr', BASE_PATH . '/logs');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/logs/php_errors.log');
}
unset($_autoload);

define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');
define('DOCUMENT_PATH', STORAGE_PATH . '/documents');

// System/non-person user IDs to exclude from attendance & employee lists
define('SYSTEM_USER_IDS', [1, 12]);
define('SYSTEM_USER_IDS_SQL', implode(',', SYSTEM_USER_IDS));

// Load environment variables
if (TP_COMMON_AVAILABLE) {
    \TpCommon\Env\Env::load(BASE_PATH . '/.env');
} else {
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
}

// Config (defines constants: APP_NAME, APP_DEBUG, DB_HOST, HR_ROLES, etc.)
require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';

// Session — SSO shared session (Phase 5)
if (TP_COMMON_AVAILABLE && class_exists('TpCommon\Session\SharedSession')) {
    \TpCommon\Session\SharedSession::start([
        'project'  => 'tp-hr',
        'lifetime' => defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200,
    ]);
} elseif (TP_COMMON_AVAILABLE) {
    \TpCommon\Auth\Session::start([
        'name'         => 'TPHRSESSID',
        'lifetime'     => defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200,
        'idle_timeout' => defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200,
    ]);
} else {
    if (session_status() === PHP_SESSION_NONE) {
        $lifetime = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 7200;
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        session_name('TPHRSESSID');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $now = time();
        if (!empty($_SESSION['_last_activity']) && ($now - (int)$_SESSION['_last_activity']) > $lifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_activity'] = $now;
    }
}

// Timezone
date_default_timezone_set('Asia/Bangkok');

// SSO Guard — configure central login URL
if (TP_COMMON_AVAILABLE && class_exists('TpCommon\Auth\SsoGuard')) {
    $crmUrl = defined('CRM_BASE_URL') ? CRM_BASE_URL
        : ($_ENV['CRM_BASE_URL'] ?? getenv('CRM_BASE_URL') ?: 'http://localhost/tp-crm');
    \TpCommon\Auth\SsoGuard::configure($crmUrl);
}

// Core classes
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Services/SettingsService.php';
require_once BASE_PATH . '/core/Helpers.php';
require_once BASE_PATH . '/core/ApiAuth.php';
require_once BASE_PATH . '/core/WfhStamp.php';
require_once BASE_PATH . '/core/Services/AttendanceService.php';

// Phase 7: Structured logging + audit log
if (TP_COMMON_AVAILABLE) {
    if (class_exists('TpCommon\Logging\Logger')) {
        \TpCommon\Logging\Logger::init('tp-hr', BASE_PATH . '/logs');
    }
    if (class_exists('TpCommon\AuditLog')) {
        try { \TpCommon\AuditLog::init(getDB(), 'tp-hr'); } catch (\Throwable $e) {}
    }
}

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
// Helper functions (keep legacy signatures — delegate to TpCommon when available)
// =====================================================================

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

/**
 * HR admin dashboard (/hr/) — legacy HR_ROLES plus optional Acl grants (Phase C).
 * Grant `hr.dashboard` or `hr.*` in permissions / role_permissions for non–HR-named roles.
 */
function hr_can_access_hr_dashboard(): bool {
    if (isHR()) {
        return true;
    }
    $acl = Auth::acl();
    if ($acl === null) {
        return false;
    }
    return $acl->can('hr.dashboard') || $acl->can('hr.*');
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

/**
 * Active user whose role name is in the allow-list (for API actor checks).
 */
function userHasOneOfRoles(PDO $pdo, int $userId, array $allowedRoleNames): bool {
    if ($userId <= 0 || $allowedRoleNames === []) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT r.name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $name = $stmt->fetchColumn();
    if ($name === false || $name === null || $name === '') {
        return false;
    }
    return in_array((string) $name, $allowedRoleNames, true);
}

/**
 * For external API: user id must be active and in MANAGER_ROLES (same family as session hasRole / leave approve).
 */
function userMayApproveLeaveByRole(PDO $pdo, int $userId): bool {
    return userHasOneOfRoles($pdo, $userId, MANAGER_ROLES);
}

/**
 * Resolve actor user id for API approve/reject (and similar): bind to API key creator when set, else legacy body field.
 */
/**
 * Server-side exception logging — never pass raw $e->getMessage() to HTTP clients in production APIs.
 */
function tpHrLogException(Throwable $e, string $context): void {
    $line = '[' . $context . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    error_log($line);
    if (defined('APP_DEBUG') && APP_DEBUG) {
        error_log('[' . $context . '] trace: ' . $e->getTraceAsString());
    }
}

/**
 * If the API key is restricted to one employee (hr_api_keys.service_user_id), return that users.id; else null.
 */
function apiKeyServiceUserId(?array $key): ?int {
    if (!$key) {
        return null;
    }
    $id = isset($key['service_user_id']) ? (int) $key['service_user_id'] : 0;
    return $id > 0 ? $id : null;
}

/**
 * For ?user_id= / body user_id on list or write: HR keys use client value; employee-scoped keys force own id.
 *
 * @return int 0 = no user filter (all rows), >0 = restrict to this user
 */
function apiKeyResolveScopedUserId(?array $key, int $clientUserId): int {
    $svc = apiKeyServiceUserId($key);
    if ($svc === null) {
        return max(0, $clientUserId);
    }
    if ($clientUserId > 0 && $clientUserId !== $svc) {
        ApiAuth::fail(403, 'user_id not allowed for this API key');
    }
    return $svc;
}

/**
 * For GET-by-id: ensure the resource belongs to the scoped employee when the key is restricted.
 */
function apiKeyAssertResourceOwnerUserId(?array $key, int $resourceOwnerUserId): void {
    $svc = apiKeyServiceUserId($key);
    if ($svc !== null && $resourceOwnerUserId !== $svc) {
        ApiAuth::fail(403, 'Forbidden');
    }
}

/**
 * GET /api/v1/employees without service_user_id: list-all requires explicit scope (or *).
 */
function apiKeyMayListAllEmployees(?array $key): bool {
    if (!$key) {
        return false;
    }
    $scopes = json_decode($key['scopes'] ?? '[]', true) ?: [];
    if (in_array('*', $scopes, true)) {
        return true;
    }
    return in_array('employees.read_all', $scopes, true);
}

/**
 * Unscoped API key: bulk payroll / arbitrary payslip access needs explicit scope (or *).
 */
function apiKeyMayAccessFullPayroll(?array $key): bool {
    if (!$key) {
        return false;
    }
    $scopes = json_decode($key['scopes'] ?? '[]', true) ?: [];
    if (in_array('*', $scopes, true)) {
        return true;
    }
    return in_array('payroll.read_all', $scopes, true);
}

function apiKeyHasReadAllScope(?array $key, string $readAllScope): bool {
    if (!$key) {
        return false;
    }
    $scopes = json_decode($key['scopes'] ?? '[]', true) ?: [];
    if (in_array('*', $scopes, true)) {
        return true;
    }
    return in_array($readAllScope, $scopes, true);
}

/**
 * List/filter arbitrary users: allowed if key has service_user_id, or read_all scope, or *.
 */
function apiKeyRequireServiceUserOrReadAllScope(?array $key, string $readAllScope, string $message): void {
    if ($key && apiKeyServiceUserId($key) !== null) {
        return;
    }
    if (!apiKeyHasReadAllScope($key, $readAllScope)) {
        ApiAuth::fail(403, $message);
    }
}

/**
 * Session profile API — strip secrets/finance fields; mask national id like the web profile UI.
 *
 * @param  array<string,mixed>|false|null $row
 * @return array<string,mixed>|null
 */
function tpHrSanitizeUserRowForSelfProfileApi(array|false|null $row): ?array {
    if (!is_array($row) || $row === []) {
        return null;
    }
    $out = $row;
    $removeExact = [
        'password',
        'salary',
        'probation_salary',
        'bank_name',
        'bank_account',
        'social_security_id',
        'tax_id',
        'remember_token',
        'reset_password_token',
        'password_reset_token',
        'email_verification_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'line_user_id',
        'google_id',
        'facebook_id',
    ];
    foreach ($removeExact as $k) {
        unset($out[$k]);
    }
    foreach (array_keys($out) as $k) {
        if (preg_match('/(password|_token|_secret|_salt)$/i', (string) $k)) {
            unset($out[$k]);
        }
    }
    if (!empty($out['id_card']) && is_string($out['id_card'])) {
        $out['id_card'] = mb_substr($out['id_card'], 0, 4) . '-XXXX-XXXXX-XX-X';
    }
    return $out;
}

/** Block manager-only approve/reject flows for keys restricted to one employee. */
function apiKeyForbidServiceScoped(string $message = 'This action is not allowed for employee-scoped API keys'): void {
    if (apiKeyServiceUserId(ApiAuth::currentKey()) !== null) {
        ApiAuth::fail(403, $message);
    }
}

function apiKeyResolveActorForApi(PDO $pdo, ?array $key, array $body, string $bodyField, array $allowedRoleNames): int {
    if (!$key) {
        ApiAuth::fail(500, 'Internal error');
    }
    $bodyVal = (int) ($body[$bodyField] ?? 0);
    $keyOwner = isset($key['created_by']) ? (int) $key['created_by'] : 0;
    if ($keyOwner > 0) {
        if (!userHasOneOfRoles($pdo, $keyOwner, $allowedRoleNames)) {
            ApiAuth::fail(403, 'API key issuer is not eligible for this action; re-issue the key');
        }
        if ($bodyVal > 0 && $bodyVal !== $keyOwner) {
            ApiAuth::fail(400, $bodyField . ' must match the user who issued this API key');
        }
        return $keyOwner;
    }
    if ($bodyVal <= 0) {
        ApiAuth::fail(400, $bodyField . ' required (legacy key without creator)');
    }
    if (!userHasOneOfRoles($pdo, $bodyVal, $allowedRoleNames)) {
        ApiAuth::fail(403, $bodyField . ' is not eligible for this action');
    }
    return $bodyVal;
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

/**
 * Post-login redirect target: same-site path only (mitigate open redirect).
 * Allows paths like /leave.php or /hr/ — rejects //evil.com, https://..., javascript:, etc.
 */
function safeRedirectTarget(?string $url, string $default = '/'): string {
    $url = trim((string) $url);
    if ($url === '') {
        return $default;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return $default;
    }
    if (preg_match('#^(javascript:|data:|vbscript:)#i', $url)) {
        return $default;
    }
    if (preg_match('#^https?://#i', $url)) {
        return $default;
    }
    if (strlen($url) >= 2 && $url[0] === '/' && $url[1] === '/') {
        return $default;
    }
    if ($url[0] !== '/') {
        return $default;
    }
    return $url;
}

// --- CSRF ---

function csrfToken(): string {
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Auth\Csrf::token();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Auth\Csrf::field('_token');
    }
    return '<input type="hidden" name="_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    $submittedToken = $_POST['_token']
        ?? ($_POST['csrf_token'] ?? null)
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Auth\Csrf::verify($submittedToken);
    }
    return is_string($submittedToken) && $submittedToken !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken);
}

function verifyCsrfToken(?string $token = null): bool {
    $submittedToken = $token;
    if ($submittedToken === null || $submittedToken === '') {
        $submittedToken = $_POST['_token']
            ?? ($_POST['csrf_token'] ?? null)
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    }
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Auth\Csrf::verify($submittedToken);
    }
    return is_string($submittedToken) && $submittedToken !== ''
        && hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken);
}

/**
 * POST + CSRF — เปิดหน้าพิมพ์/ดูตัวอย่างหนังสือรับรอง (มักใช้ target=_blank)
 */
function tpHrCertificatePrintForm(
    int $requestId,
    string $formClass,
    string $buttonClass,
    string $innerHtml,
    bool $newTab,
    bool $preview = true,
    ?string $lang = null,
    ?string $buttonTitle = null
): void {
    if ($requestId <= 0) {
        return;
    }
    $target = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
    $titleAttr = ($buttonTitle !== null && $buttonTitle !== '')
        ? (' title="' . htmlspecialchars($buttonTitle, ENT_QUOTES, 'UTF-8') . '"')
        : '';
    echo '<form method="post" action="/certificate_print.php" class="' . htmlspecialchars($formClass, ENT_QUOTES, 'UTF-8') . '"' . $target . '>';
    echo csrfField();
    echo '<input type="hidden" name="certificate_print" value="1">';
    echo '<input type="hidden" name="id" value="' . $requestId . '">';
    echo '<input type="hidden" name="preview" value="' . ($preview ? '1' : '0') . '">';
    if ($lang !== null && $lang !== '') {
        echo '<input type="hidden" name="lang" value="' . htmlspecialchars(strtoupper($lang), ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<button type="submit" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '"' . $titleAttr . '>' . $innerHtml . '</button>';
    echo '</form>';
}

// --- Role helpers ---

function hasRole(string|array $roles): bool {
    return Auth::hasRole($roles);
}

// --- Date/Money helpers ---

function formatDateThai(string $date, bool $showTime = false): string {
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Helpers\Date::thai($date, $showTime);
    }
    if (empty($date)) return '-';
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $ts = strtotime($date);
    $result = date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . (date('Y', $ts) + 543);
    if ($showTime) $result .= ' ' . date('H:i', $ts);
    return $result;
}

function formatMoney(float $amount): string {
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Helpers\Date::money($amount);
    }
    return number_format($amount, 2) . ' บาท';
}

function thaiMonth(int $month): string {
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Helpers\Date::thaiMonth($month);
    }
    $months = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
    return $months[$month] ?? '';
}

function thaiMonthShort(int $month): string {
    if (TP_COMMON_AVAILABLE) {
        return \TpCommon\Helpers\Date::thaiMonthShort($month);
    }
    $months = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    return $months[$month] ?? '';
}

function getUserFullName(array $user): string {
    $title = $user['title'] ?? '';
    $firstName = $user['first_name_th'] ?? $user['first_name'] ?? '';
    $lastName = $user['last_name_th'] ?? $user['last_name'] ?? '';
    return trim("$title$firstName $lastName");
}

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
