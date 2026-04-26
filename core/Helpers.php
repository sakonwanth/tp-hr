<?php
/**
 * Helper Functions
 */

/**
 * Generate running number
 */
function generateRunningNumber($prefix, string $table = '', string $column = 'request_number'): string {
    if ($prefix instanceof PDO) {
        $pdo = $prefix;
        $legacyType = $table;
        $prefix = $column;
        $column = 'request_number';
        $table = match ($legacyType) {
            'LEAVE' => 'hr_leave_requests',
            'DOC_REQUEST' => 'hr_document_requests',
            default => $legacyType,
        };
    } else {
        $pdo = getDB();
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) {
        throw new InvalidArgumentException('Invalid running number target');
    }

    $year = date('Y');
    $pattern = $prefix . '-' . $year . '-%';
    
    $stmt = $pdo->prepare("SELECT MAX($column) as last_number FROM $table WHERE $column LIKE ?");
    $stmt->execute([$pattern]);
    $result = $stmt->fetch();
    
    $lastNumber = 0;
    if ($result && $result['last_number']) {
        preg_match('/-(\d+)$/', $result['last_number'], $matches);
        $lastNumber = (int)($matches[1] ?? 0);
    }
    
    $newNumber = $lastNumber + 1;
    return sprintf("%s-%s-%05d", $prefix, $year, $newNumber);
}

/**
 * Upload file
 */
function uploadFile(array $file, string $destination, ?array $allowedTypes = null): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'ไฟล์มีขนาดใหญ่เกินไป',
            UPLOAD_ERR_FORM_SIZE => 'ไฟล์มีขนาดใหญ่เกินไป',
            UPLOAD_ERR_PARTIAL => 'อัปโหลดไฟล์ไม่สมบูรณ์',
            UPLOAD_ERR_NO_FILE => 'ไม่มีไฟล์ที่อัปโหลด',
            UPLOAD_ERR_NO_TMP_DIR => 'ไม่มีโฟลเดอร์ชั่วคราว',
            UPLOAD_ERR_CANT_WRITE => 'เขียนไฟล์ไม่ได้',
        ];
        return ['success' => false, 'message' => $errors[$file['error']] ?? 'เกิดข้อผิดพลาด'];
    }
    
    $maxSize = MAX_UPLOAD_SIZE;
    if (isset($allowedTypes['max_size']) && is_numeric($allowedTypes['max_size'])) {
        $maxSize = (int)$allowedTypes['max_size'];
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'ไฟล์มีขนาดใหญ่เกินไป'];
    }
    
    // Get file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = $allowedTypes['types'] ?? $allowedTypes ?? ALLOWED_FILE_TYPES;

    if (!in_array($ext, $allowedTypes, true)) {
        return ['success' => false, 'message' => 'ประเภทไฟล์ไม่ถูกต้อง'];
    }

    // Best-effort content validation (don't trust extension)
    $mime = '';
    try {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $file['tmp_name']);
                finfo_close($fi);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    $mime = strtolower(trim($mime));

    $allowedMimeByExt = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    if (isset($allowedMimeByExt[$ext]) && $mime !== '' && !in_array($mime, $allowedMimeByExt[$ext], true)) {
        return ['success' => false, 'message' => 'ไฟล์ไม่ถูกต้อง (ชนิดไฟล์ไม่ตรงกับนามสกุล)'];
    }

    // Extra guard for images
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        try {
            if (@getimagesize($file['tmp_name']) === false) {
                return ['success' => false, 'message' => 'ไฟล์รูปภาพไม่ถูกต้อง'];
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'ไฟล์รูปภาพไม่ถูกต้อง'];
        }
    }

    if (in_array($ext, ['doc', 'xls'], true)) {
        try {
            $fh = @fopen($file['tmp_name'], 'rb');
            if (!$fh) return ['success' => false, 'message' => 'อ่านไฟล์ไม่ได้'];
            $head = (string)fread($fh, 8);
            fclose($fh);
            if (bin2hex($head) !== 'd0cf11e0a1b11ae1') {
                return ['success' => false, 'message' => 'ไฟล์ Office ไม่ถูกต้อง'];
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'ไฟล์ Office ไม่ถูกต้อง'];
        }
    }

    if (in_array($ext, ['docx', 'xlsx'], true)) {
        try {
            $fh = @fopen($file['tmp_name'], 'rb');
            if (!$fh) return ['success' => false, 'message' => 'อ่านไฟล์ไม่ได้'];
            $head = (string)fread($fh, 4);
            fclose($fh);
            if ($head !== "PK\x03\x04" && $head !== "PK\x05\x06" && $head !== "PK\x07\x08") {
                return ['success' => false, 'message' => 'ไฟล์ Office ไม่ถูกต้อง'];
            }

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($file['tmp_name']) !== true) {
                    return ['success' => false, 'message' => 'ไฟล์ Office ไม่ถูกต้อง'];
                }
                $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
                $hasAppDir = $ext === 'docx'
                    ? $zip->locateName('word/document.xml') !== false
                    : $zip->locateName('xl/workbook.xml') !== false;
                $zip->close();
                if (!$hasContentTypes || !$hasAppDir) {
                    return ['success' => false, 'message' => 'ไฟล์ Office ไม่ถูกต้อง'];
                }
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'ไฟล์ Office ไม่ถูกต้อง'];
        }
    }

    // Extra guard for PDF
    if ($ext === 'pdf') {
        try {
            $fh = @fopen($file['tmp_name'], 'rb');
            if (!$fh) return ['success' => false, 'message' => 'อ่านไฟล์ไม่ได้'];
            $head = (string)fread($fh, 5);
            fclose($fh);
            if ($head !== '%PDF-') {
                return ['success' => false, 'message' => 'ไฟล์ PDF ไม่ถูกต้อง'];
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'ไฟล์ PDF ไม่ถูกต้อง'];
        }
    }
    
    // Create destination directory if not exists
    $fullPath = STORAGE_PATH . '/' . $destination;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0755, true);
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $filePath = $fullPath . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['success' => false, 'message' => 'ไม่สามารถบันทึกไฟล์ได้'];
    }

    @chmod($filePath, 0644);
    
    return [
        'success' => true,
        'filename' => $filename,
        'path' => $destination . '/' . $filename,
        'full_path' => $filePath,
        'size' => $file['size'],
        'type' => $ext
    ];
}

/**
 * Delete file
 */
function deleteFile(string $path): bool {
    $fullPath = STORAGE_PATH . '/' . $path;
    if (file_exists($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}

/**
 * Get file URL
 */
function fileUrl(string $path): string {
    return APP_URL . '/storage/' . $path;
}

/**
 * Public base URL for TP-Checkin (static files e.g. {base}/storage/photos/...).
 * - CHECKIN_APP_URL ถ้าตั้งใน .env
 * - ถ้า APP_URL เป็นโฮสต์แบบ hr.* ให้ใช้ checkin.* (scheme/port เดิม) — เช่น hr.tp-asset.com → checkin.tp-asset.com
 * - นอกนั้น fallback เป็น APP_URL (โฟลเดอร์ storage ร่วมบนโดเมนเดียวกัน)
 */
function checkinPublicBaseUrl(): string {
    if (CHECKIN_APP_URL !== '') {
        return rtrim(CHECKIN_APP_URL, '/');
    }
    $parts = parse_url(APP_URL);
    if (!empty($parts['host']) && preg_match('/^hr\./i', $parts['host'])) {
        $scheme = $parts['scheme'] ?? 'https';
        $host = preg_replace('/^hr\./i', 'checkin.', $parts['host'], 1);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return rtrim($scheme . '://' . $host . $port, '/');
    }
    return rtrim(APP_URL, '/');
}

/**
 * Allowed relative paths under TP-Checkin storage (anti path-traversal).
 */
function checkinStorageRelativePathIsAllowed(string $path): bool {
    $path = ltrim($path, '/');
    if (preg_match('#^photos/[a-z0-9_]+/\d{4}/\d{2}/[A-Za-z0-9._-]+$#', $path)) {
        return true;
    }
    if (preg_match('#^storage/adjustments/\d{4}/\d{2}/[A-Za-z0-9._-]+$#', $path)) {
        return true;
    }
    return false;
}

/**
 * Resolve a TP-Checkin storage-relative path to an absolute file, or null if not allowed / missing.
 */
function checkinStorageResolveDiskPath(string $relativePath): ?string {
    if (CHECKIN_STORAGE_PATH === '') {
        return null;
    }
    $relativePath = ltrim($relativePath, '/');
    if (!checkinStorageRelativePathIsAllowed($relativePath)) {
        return null;
    }
    $base = realpath(CHECKIN_STORAGE_PATH);
    if ($base === false || !is_dir($base)) {
        return null;
    }
    if (strpos($relativePath, 'photos/') === 0) {
        $candidate = $base . '/' . $relativePath;
    } else {
        $candidate = $base . '/' . substr($relativePath, strlen('storage/'));
    }
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }
    if (strpos($resolved, $base) !== 0) {
        return null;
    }
    return $resolved;
}

/**
 * Public URL for attendance check-in/out photos (DB may store tp-checkin paths or tp-hr uploads).
 */
function attendancePhotoPublicUrl(?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $path = ltrim($path, '/');
    if (strpos($path, 'photos/') === 0) {
        if (CHECKIN_STORAGE_PATH !== '') {
            return rtrim(APP_URL, '/') . '/api/checkin_storage_image.php?' . http_build_query(['path' => $path], '', '&', PHP_QUERY_RFC3986);
        }
        return checkinPublicBaseUrl() . '/storage/' . $path;
    }
    if (strpos($path, 'storage/') === 0) {
        if (CHECKIN_STORAGE_PATH !== '' && strpos($path, 'storage/adjustments/') === 0) {
            return rtrim(APP_URL, '/') . '/api/checkin_storage_image.php?' . http_build_query(['path' => $path], '', '&', PHP_QUERY_RFC3986);
        }
        return checkinPublicBaseUrl() . '/' . $path;
    }
    return fileUrl($path);
}

/**
 * Validate Thai ID Card
 */
function validateThaiIdCard(string $idCard): bool {
    $idCard = preg_replace('/[^0-9]/', '', $idCard);
    
    if (strlen($idCard) !== 13) {
        return false;
    }
    
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$idCard[$i] * (13 - $i);
    }
    
    $checkDigit = (11 - ($sum % 11)) % 10;
    
    return $checkDigit === (int)$idCard[12];
}

/**
 * Calculate age from birthdate
 */
function calculateAge(string $birthDate): int {
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

/**
 * Calculate years of service
 */
function calculateYearsOfService(string $hireDate): array {
    $hire = new DateTime($hireDate);
    $today = new DateTime();
    $diff = $today->diff($hire);
    
    return [
        'years' => $diff->y,
        'months' => $diff->m,
        'days' => $diff->d,
        'text' => sprintf('%d ปี %d เดือน', $diff->y, $diff->m)
    ];
}

/**
 * Check if date is holiday
 */
function isHoliday(string $date): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hr_holidays WHERE date = ? AND is_active = 1");
    $stmt->execute([$date]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Get the effective day-off weekday for a user on a given date.
 * Consults hr_employee_schedules and approved hr_dayoff_requests swaps.
 * Returns null if the user has no schedule row.
 */
function getUserDayOff(int $userId, string $date): ?int {
    static $cache = [];
    $key = $userId . '|' . $date;
    if (isset($cache[$key])) return $cache[$key];

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return $cache[$key] = null;

    $defaultDayOff = (int)$row['day_off'];

    // Check for an approved swap covering this date
    $stmtSwap = $pdo->prepare("
        SELECT requested_day_off FROM hr_dayoff_requests
        WHERE user_id = ? AND status = 'APPROVED'
          AND ? BETWEEN week_start AND week_end
        LIMIT 1
    ");
    $stmtSwap->execute([$userId, $date]);
    $swap = $stmtSwap->fetch();
    if ($swap) {
        return $cache[$key] = (int)$swap['requested_day_off'];
    }
    return $cache[$key] = $defaultDayOff;
}

/**
 * Check if date is a day off for a specific user (per employee schedule).
 * If $userId is null, returns false (no global weekend assumption — use
 * hr_holidays for company-wide off days).
 */
function isDayOff(string $date, ?int $userId = null): bool {
    if ($userId === null) return false;
    $userDayOff = getUserDayOff($userId, $date);
    if ($userDayOff === null) return false;
    return (int)date('w', strtotime($date)) === $userDayOff;
}

/**
 * Check if date is a working day for the given user.
 * Working day = not day-off, not a holiday.
 */
function isWorkingDay(string $date, ?int $userId = null): bool {
    return !isDayOff($date, $userId) && !isHoliday($date);
}

/**
 * Get next working day for user.
 */
function getNextWorkingDay(string $date, int $days = 1, ?int $userId = null): string {
    $current = new DateTime($date);
    $count = 0;
    while ($count < $days) {
        $current->modify('+1 day');
        if (isWorkingDay($current->format('Y-m-d'), $userId)) {
            $count++;
        }
    }
    return $current->format('Y-m-d');
}

/**
 * Thai weekday name (full form). $dow = 0 (Sunday) .. 6 (Saturday).
 */
function thaiDayName(int $dow): string {
    return THAI_DAY_NAMES[$dow] ?? '';
}

/**
 * Calculate distance between two GPS coordinates (in meters)
 */
function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000; // meters
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Send notification (placeholder)
 */
function sendNotification(int $userId, string $title, string $message, string $type = 'info'): bool {
    // TODO: Implement email/LINE notification
    return true;
}

/**
 * Sanitize input
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * API Response helper
 */
function apiResponse(array $data, int $status = 200): void {
    if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE
        && class_exists('TpCommon\Http\ApiResponse')) {
        \TpCommon\Http\ApiResponse::send($data, $status);
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(string $message, int $status = 400): void {
    if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE
        && class_exists('TpCommon\Http\ApiResponse')) {
        \TpCommon\Http\ApiResponse::error($message, $status);
    }
    apiResponse(['success' => false, 'error' => $message], $status);
}

function apiSuccess(array $data = [], ?string $message = null): void {
    if (defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE
        && class_exists('TpCommon\Http\ApiResponse')) {
        $body = ['success' => true];
        if ($message) $body['message'] = $message;
        $body = array_merge($body, $data);
        \TpCommon\Http\ApiResponse::send($body, 200);
    }
    $response = ['success' => true];
    if ($message) $response['message'] = $message;
    $response = array_merge($response, $data);
    apiResponse($response);
}

/**
 * Get shift defaults merged from (in order): passed $shift row, hr_settings,
 * then final safe fallbacks. Returns keys: grace_period_minutes, break_minutes,
 * work_hours_per_day.
 */
function getShiftDefaults(?array $shift = null): array {
    static $settingsDefaults = null;
    if ($settingsDefaults === null) {
        $settingsDefaults = [
            'grace_period_minutes' => (int)getSetting('grace_period_minutes', 15),
            'break_minutes'        => (int)getSetting('break_minutes', 60),
            'work_hours_per_day'   => (float)getSetting('work_hours_per_day', 8),
        ];
    }
    return [
        'grace_period_minutes' => (int)($shift['grace_period_minutes'] ?? $settingsDefaults['grace_period_minutes']),
        'break_minutes'        => (int)($shift['break_minutes']        ?? $settingsDefaults['break_minutes']),
        'work_hours_per_day'   => (float)($shift['work_hours_per_day'] ?? $settingsDefaults['work_hours_per_day']),
    ];
}

/**
 * Get settings value
 */
function getSetting(string $key, $default = null) {
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    try {
        $pdo = getDB();
        $value = (new SettingsService($pdo))->get($key, $default);
        $cache[$key] = $value;
        return $value;
        
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Set settings value
 */
function setSetting(string $key, $value, string $type = 'STRING'): bool {
    try {
        $pdo = getDB();
        return (new SettingsService($pdo))->set($key, $value, $type, Auth::id());
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Shift display label helpers
 *
 * ปัญหาเดิม: hr_work_shifts.name ถูกเก็บแบบ hardcoded เช่น "กะปกติ (08:30-17:30)"
 * ทำให้เวลา admin แก้ start_time/end_time แล้ว label ไม่ sync
 *
 * Solution:
 *   - shift_base_name(): strip "(HH:MM-HH:MM)" ออกจาก name → ได้ชื่อ base
 *   - shift_display_label(): ประกอบ base + "(start-end)" สด ๆ จาก column จริง
 *   - shift_sanitize_name_on_save(): clean name ก่อน INSERT/UPDATE — เก็บเฉพาะ base
 */
if (!function_exists('shift_base_name')) {
    function shift_base_name(string $name): string {
        // ลบ "(...)" ที่อยู่ท้ายชื่อ — รองรับทั้งตัวเลขล้วน, HH:MM, HH.MM, เว้นวรรค
        $clean = preg_replace('/\s*\(\s*\d{1,2}[.:]\d{2}\s*[-–—]\s*\d{1,2}[.:]\d{2}\s*\)\s*$/u', '', $name);
        return trim($clean ?? $name);
    }
}

if (!function_exists('shift_display_label')) {
    /**
     * @param array|null $shift assoc row with name + start_time + end_time, or [shift_name, shift_start, shift_end]
     * @return string "base (HH:MM-HH:MM)" or '-' if empty
     */
    function shift_display_label($shift): string {
        if (!is_array($shift)) return '-';
        $name  = $shift['name']       ?? ($shift['shift_name']  ?? '');
        $start = $shift['start_time'] ?? ($shift['shift_start'] ?? '');
        $end   = $shift['end_time']   ?? ($shift['shift_end']   ?? '');
        if ($name === '' && $start === '') return '-';
        $base = shift_base_name((string)$name);
        if ($start !== '' && $end !== '') {
            return $base . ' (' . substr((string)$start, 0, 5) . '-' . substr((string)$end, 0, 5) . ')';
        }
        return $base ?: '-';
    }
}

if (!function_exists('shift_sanitize_name_on_save')) {
    /**
     * เมื่อ admin บันทึกกะใหม่ — ตัด "(...)" ทิ้ง เหลือแค่ชื่อ base
     * เพื่อให้ display layer คุม format "(start-end)" ได้ 100%
     */
    function shift_sanitize_name_on_save(string $name): string {
        return shift_base_name($name);
    }
}

/**
 * =============================================================================
 * Planned Late Start helpers — mirror ของ tp-checkin/core/Helpers.php
 * =============================================================================
 * tp-hr/checkin.php ต้องมีฟีเจอร์ "แจ้งเข้างานสายล่วงหน้า" เหมือน tp-checkin
 * โดย write ลง table เดียวกัน (hr_attendances.planned_start_time)
 */
if (!function_exists('ensurePlannedStartTimeColumns')) {
    function ensurePlannedStartTimeColumns(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $flagDir  = sys_get_temp_dir() . '/tp_hr_schema';
        if (!is_dir($flagDir)) @mkdir($flagDir, 0755, true);
        $flagFile = $flagDir . '/hr_attendances_planned_start.ok';
        if (file_exists($flagFile) && (time() - filemtime($flagFile)) < 86400) {
            return;
        }

        try {
            $hasCol = function (string $col) use ($pdo): bool {
                $s = $pdo->prepare(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'hr_attendances'
                       AND COLUMN_NAME  = ?"
                );
                $s->execute([$col]);
                return (bool) $s->fetchColumn();
            };

            $cols = [
                ['planned_start_time',   "TIME NULL DEFAULT NULL",         'shift_id'],
                ['planned_reason',       "VARCHAR(255) NULL DEFAULT NULL", 'planned_start_time'],
                ['planned_requested_at', "DATETIME NULL DEFAULT NULL",     'planned_reason'],
                ['planned_requested_by', "INT NULL DEFAULT NULL",          'planned_requested_at'],
            ];
            foreach ($cols as [$name, $def, $after]) {
                if (!$hasCol($name)) {
                    try { $pdo->exec("ALTER TABLE hr_attendances ADD COLUMN {$name} {$def} AFTER {$after}"); }
                    catch (PDOException $e) { /* ignore */ }
                }
            }
        } catch (Throwable $e) {
            error_log('[tp-hr] ensurePlannedStartTimeColumns failed: ' . $e->getMessage());
        }

        @file_put_contents($flagFile, date('c'));
    }
}

/**
 * อ่าน system_settings (tp-crm shared table) — tp-hr เก็บ config ในตารางนี้ด้วย
 */
if (!function_exists('getSystemSetting')) {
    function getSystemSetting(PDO $pdo, string $key, string $default = ''): string {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            return $cache[$key] = (new SettingsService($pdo))->getSystem($key, $default);
        } catch (Throwable $e) {
            return $cache[$key] = $default;
        }
    }
}

if (!function_exists('lateRequestCutoffHour')) {
    function lateRequestCutoffHour(PDO $pdo): int {
        $h = (int) getSystemSetting($pdo, 'hr_late_request_cutoff_hour', '7');
        return ($h >= 0 && $h <= 12) ? $h : 7;
    }
}

if (!function_exists('canRequestLateStart')) {
    /**
     * @return array { ok: bool, reason: string, message: string, fallback: ?string }
     */
    function canRequestLateStart(PDO $pdo, string $target_date, ?int $now_ts = null): array {
        $now      = $now_ts ?? time();
        $today    = date('Y-m-d', $now);
        $valid_dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date) === 1;

        if (!$valid_dt) {
            return ['ok' => false, 'reason' => 'invalid_date', 'message' => 'รูปแบบวันที่ไม่ถูกต้อง', 'fallback' => null];
        }

        if ($target_date < $today) {
            return [
                'ok'       => false,
                'reason'   => 'past_date',
                'message'  => 'ไม่สามารถแจ้งเข้างานสายย้อนหลังได้ — กรุณาใช้แบบฟอร์ม "ขอแก้ไขเวลาเข้างาน" แทน',
                'fallback' => 'request_adjustment',
            ];
        }

        if ($target_date === $today) {
            $cutoff_h  = lateRequestCutoffHour($pdo);
            $cutoff_ts = strtotime($today . ' ' . sprintf('%02d:00:00', $cutoff_h));
            if ($now >= $cutoff_ts) {
                return [
                    'ok'       => false,
                    'reason'   => 'after_cutoff',
                    'message'  => sprintf(
                        'เลยเวลาแจ้งเข้างานสายของวันนี้แล้ว (ต้องแจ้งก่อน %02d:00) — กรุณาเข้างานตามเวลาปกติ',
                        $cutoff_h
                    ),
                    'fallback' => 'request_adjustment',
                ];
            }
        }

        return ['ok' => true, 'reason' => 'allowed', 'message' => '', 'fallback' => null];
    }
}

if (!function_exists('validatePlannedStartTime')) {
    function validatePlannedStartTime(string $planned_time, ?array $shift = null): array {
        if (!preg_match('/^\d{2}:\d{2}$/', $planned_time)) {
            return ['ok' => false, 'message' => 'รูปแบบเวลาไม่ถูกต้อง (ต้องเป็น HH:MM)'];
        }
        $ps_ts  = strtotime('1970-01-01 ' . $planned_time);
        $start  = ($shift['start_time'] ?? null) ?: '08:30';
        $end    = ($shift['end_time']   ?? null) ?: '17:30';
        $start_ts = strtotime('1970-01-01 ' . $start);
        if ($ps_ts < $start_ts) {
            return ['ok' => false, 'message' => sprintf('เวลาที่แจ้งต้องไม่เร็วกว่าเวลาเข้างานปกติ (%s)', substr($start, 0, 5))];
        }
        $end_ts = strtotime('1970-01-01 ' . $end);
        if (($end_ts - $ps_ts) < (4 * 3600)) {
            return ['ok' => false, 'message' => sprintf('ต้องเหลือเวลาทำงานอย่างน้อย 4 ชั่วโมง (สิ้นสุดงาน %s)', substr($end, 0, 5))];
        }
        return ['ok' => true, 'message' => ''];
    }
}
