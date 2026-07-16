<?php
/**
 * TP-HR Application Configuration
 */

// Application
define('APP_NAME', 'TP-HR');
define('APP_VERSION', '1.0.0');
define('APP_ENV', $_ENV['APP_ENV'] ?? 'development');
// Default: debug on in non-production; production must set APP_DEBUG=true explicitly to enable.
$__appEnv = strtolower((string) APP_ENV);
if (isset($_ENV['APP_DEBUG'])) {
    define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN));
} else {
    define('APP_DEBUG', $__appEnv !== 'production');
}
unset($__appEnv);
define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost/tp-hr');

// Session
define('SESSION_LIFETIME', 7200); // 2 hours

// Security
define('MIN_PASSWORD_LENGTH', 8);

// External services
define('CRM_BASE_URL', $_ENV['CRM_BASE_URL'] ?? 'http://localhost/tp-crm');

/** Public base URL of TP-Checkin (รูปลงเวลาเก็บใต้ {CHECKIN}/storage/photos/...) */
$checkinDefaultUrl = (defined('APP_ENV') && strtolower((string)APP_ENV) === 'production')
    ? 'https://checkin.tp-asset.com'
    : '';
define('CHECKIN_APP_URL', $_ENV['CHECKIN_APP_URL'] ?? $checkinDefaultUrl);

/**
 * Absolute path to TP-Checkin `storage` directory on this server (e.g. .../tp-checkin/storage).
 * When set, HR serves check-in photos via /api/checkin_storage_image.php (same origin as APP_URL).
 */
define('CHECKIN_STORAGE_PATH', $_ENV['CHECKIN_STORAGE_PATH'] ?? '');

// Filesystem path to TP-CRM (LINE bridge loads LineNotifier from disk). Optional if tp-crm is not ../tp-crm.
if (!defined('TP_CRM_PATH')) {
    $crmFs = $_ENV['TP_CRM_PATH'] ?? getenv('TP_CRM_PATH');
    if (is_string($crmFs) && $crmFs !== '' && is_dir($crmFs)) {
        define('TP_CRM_PATH', rtrim($crmFs, '/\\'));
    }
}

// File Upload
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Pagination
define('DEFAULT_PER_PAGE', 25);

// Date/Time
define('DATE_FORMAT', 'Y-m-d');
define('TIME_FORMAT', 'H:i:s');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('THAI_DATE_FORMAT', 'd/m/Y');

// HR Roles
define('HR_ROLES', ['HR', 'Admin', 'Chairman', 'CEO']);
define('CEO_ROLES', ['Admin', 'Chairman', 'CEO']);
define('MANAGER_ROLES', ['Manager', 'Director', 'HR', 'Admin', 'Chairman', 'CEO']);

// Thai day names (indexed 0=Sunday .. 6=Saturday)
define('THAI_DAY_NAMES', ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์']);

// Attendance
define('ATTENDANCE_STATUS', [
    'PENDING' => 'รอดำเนินการ',
    'PRESENT' => 'มาทำงาน',
    'LATE' => 'มาสาย',
    'ABSENT' => 'ขาดงาน',
    'LEAVE' => 'ลา',
    'HOLIDAY' => 'วันหยุด',
    'HALF_DAY' => 'ครึ่งวัน',
    'WFH' => 'ทำงานที่บ้าน'
]);

// Leave Status
define('LEAVE_STATUS', [
    'DRAFT' => 'แบบร่าง',
    'PENDING' => 'รออนุมัติ',
    'APPROVED' => 'อนุมัติ',
    'REJECTED' => 'ไม่อนุมัติ',
    'CANCELLED' => 'ยกเลิก'
]);

// Document Request Status
define('DOCUMENT_STATUS', [
    'PENDING' => 'รอดำเนินการ',
    'PROCESSING' => 'กำลังดำเนินการ',
    'READY' => 'พร้อมรับ',
    'DELIVERED' => 'รับแล้ว',
    'CANCELLED' => 'ยกเลิก',
    'REJECTED' => 'ไม่อนุมัติ'
]);

// Position Levels
define('POSITION_LEVELS', [
    1 => 'Staff',
    2 => 'Senior',
    3 => 'Lead',
    4 => 'Manager',
    5 => 'Director',
    6 => 'Executive'
]);

// Thai Titles
define('TITLES', [
    'นาย' => 'นาย',
    'นาง' => 'นาง',
    'นางสาว' => 'นางสาว',
    'Mr.' => 'Mr.',
    'Mrs.' => 'Mrs.',
    'Ms.' => 'Ms.'
]);

// Departments (for legacy, use hr_departments table instead)
define('DEPARTMENTS', [
    'ขาย' => 'ขาย',
    'สินเชื่อ' => 'สินเชื่อ',
    'รีโนเวท' => 'รีโนเวท',
    'บัญชี' => 'บัญชี',
    'กฎหมาย' => 'กฎหมาย',
    'IT' => 'IT',
    'HR' => 'HR',
    'อื่นๆ' => 'อื่นๆ'
]);
