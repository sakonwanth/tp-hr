<?php
/**
 * Helper Functions
 */

/**
 * Generate running number
 */
function generateRunningNumber(string $prefix, string $table, string $column = 'request_number'): string {
    $pdo = getDB();
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
    
    // Check file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'message' => 'ไฟล์มีขนาดใหญ่เกินไป'];
    }
    
    // Get file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = $allowedTypes ?? ALLOWED_FILE_TYPES;
    
    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'message' => 'ประเภทไฟล์ไม่ถูกต้อง'];
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
 * Check if date is weekend
 */
function isWeekend(string $date): bool {
    $dayOfWeek = date('N', strtotime($date));
    return $dayOfWeek >= 6;
}

/**
 * Check if date is working day
 */
function isWorkingDay(string $date): bool {
    return !isWeekend($date) && !isHoliday($date);
}

/**
 * Get next working day
 */
function getNextWorkingDay(string $date, int $days = 1): string {
    $current = new DateTime($date);
    $count = 0;
    
    while ($count < $days) {
        $current->modify('+1 day');
        if (isWorkingDay($current->format('Y-m-d'))) {
            $count++;
        }
    }
    
    return $current->format('Y-m-d');
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
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * API Error response
 */
function apiError(string $message, int $status = 400): void {
    apiResponse(['success' => false, 'error' => $message], $status);
}

/**
 * API Success response
 */
function apiSuccess(array $data = [], ?string $message = null): void {
    $response = ['success' => true];
    if ($message) $response['message'] = $message;
    $response = array_merge($response, $data);
    apiResponse($response);
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
        $stmt = $pdo->prepare("SELECT `value`, `type` FROM hr_settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return $default;
        }
        
        $value = $result['value'];
        
        // Cast based on type
        switch ($result['type']) {
            case 'NUMBER':
                $value = is_numeric($value) ? (strpos($value, '.') !== false ? (float)$value : (int)$value) : $default;
                break;
            case 'BOOLEAN':
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'JSON':
                $value = json_decode($value, true) ?? $default;
                break;
        }
        
        $cache[$key] = $value;
        return $value;
        
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set settings value
 */
function setSetting(string $key, $value, string $type = 'STRING'): bool {
    try {
        $pdo = getDB();
        
        if ($type === 'JSON' && is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'BOOLEAN') {
            $value = $value ? 'true' : 'false';
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO hr_settings (`key`, `value`, `type`, updated_by) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)
        ");
        
        return $stmt->execute([$key, $value, $type, Auth::id()]);
        
    } catch (Exception $e) {
        return false;
    }
}
