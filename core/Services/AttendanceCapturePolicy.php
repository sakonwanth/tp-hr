<?php
/** Normal attendance capture policy. Administrative corrections use separate routes. */
final class AttendanceCapturePolicy
{
    public const MAX_AGE_MS = 120000;
    public const FUTURE_TOLERANCE_MS = 30000;

    public static function validateGps(array $input, ?int $nowMs = null): array
    {
        $nowMs ??= (int)round(microtime(true) * 1000);
        $values = [];
        foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
            $v = $input[$field] ?? null;
            if (!is_scalar($v) || !is_numeric($v) || !is_finite((float)$v) || abs((float)$v) > $limit) {
                throw new DomainException('กรุณาเปิด GPS และระบุพิกัดที่ถูกต้อง', 422);
            }
            $values[$field] = (float)$v;
        }
        $captured = $input['gps_captured_at'] ?? null;
        if (!is_scalar($captured) || !is_numeric($captured) || !is_finite((float)$captured)
            || (float)$captured < 1000000000000 || (float)$captured > $nowMs + self::FUTURE_TOLERANCE_MS
            || $nowMs - (float)$captured > self::MAX_AGE_MS) {
            throw new DomainException('พิกัดหมดอายุหรือยังไม่พร้อม กรุณารีเฟรชหน้าและระบุตำแหน่งใหม่', 422);
        }
        $accuracy = $input['accuracy'] ?? null;
        if ($accuracy !== null && (!is_scalar($accuracy) || !is_numeric($accuracy)
            || !is_finite((float)$accuracy) || (float)$accuracy < 0 || (float)$accuracy > 99999999)) {
            throw new DomainException('ข้อมูลความแม่นยำ GPS ไม่ถูกต้อง กรุณาระบุตำแหน่งใหม่', 422);
        }
        return $values + [
            'gps_captured_at' => (int)round((float)$captured),
            'captured_at' => date('Y-m-d H:i:s', (int)floor((float)$captured / 1000)),
            'accuracy' => $accuracy === null ? null : round((float)$accuracy, 2),
        ];
    }

    public static function normalCaptureTime(array $input, ?int $now = null): string
    {
        if (!empty($input['time']) || strtoupper((string)($input['type'] ?? 'GPS')) !== 'GPS') {
            throw new DomainException('การลงเวลาปกติใช้ GPS และเวลาของระบบ กรุณาใช้ขั้นตอนแก้ไขเวลาของ HR สำหรับรายการย้อนหลัง', 422);
        }
        return date('Y-m-d H:i:s', $now ?? time());
    }

    public static function assertLocation(PDO $pdo, array $gps, ?int $locationId, array $user, bool $enforce): void
    {
        $required = $enforce && strtoupper((string)($user['work_mode'] ?? 'OFFICE')) !== 'WFH';
        if (!$locationId) {
            if ($required) throw new DomainException('กรุณาเลือกจุดลงเวลาที่เปิดใช้งาน หรือตั้งคำขอลงเวลานอกพื้นที่เพื่อรออนุมัติ', 409);
            return;
        }
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $q = $pdo->prepare('SELECT id,is_active,latitude,longitude,radius_meters FROM hr_checkin_locations WHERE id=?' . $lock);
        $q->execute([$locationId]); $location = $q->fetch(PDO::FETCH_ASSOC);
        if (!$location || !(int)$location['is_active'] || !is_numeric($location['latitude']) || !is_numeric($location['longitude'])) {
            throw new DomainException('จุดลงเวลาเปลี่ยนแปลงหรือปิดใช้งาน กรุณารีเฟรชข้อมูล', 409);
        }
        $distance = self::distance($gps['latitude'], $gps['longitude'], (float)$location['latitude'], (float)$location['longitude']);
        if ($distance > max(0, (float)$location['radius_meters'])) {
            throw new DomainException('อยู่นอกพื้นที่ลงเวลา กรุณาส่งคำขอพร้อมเหตุผลเพื่อรออนุมัติ', 409);
        }
    }

    public static function distance(float $lat, float $lng, float $otherLat, float $otherLng): float
    {
        $a = sin(deg2rad($otherLat - $lat) / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad($otherLat)) * sin(deg2rad($otherLng - $lng) / 2) ** 2;
        return 6371000 * 2 * atan2(sqrt(max(0, min(1, $a))), sqrt(max(0, 1 - min(1, $a))));
    }

    public static function validatePhotoPath(string $relative, int $userId, string $side, ?string $root = null, ?int $now = null): string
    {
        if (!in_array($side, ['in', 'out'], true)) throw new InvalidArgumentException('Invalid capture side');
        $type = $side === 'in' ? 'checkin' : 'checkout';
        $pattern = '#^photos/' . $type . '/[0-9]{4}/[0-9]{2}/' . $type . '_' . $userId . '_[0-9]{14}_[a-f0-9]+\\.jpg$#D';
        if (!preg_match($pattern, $relative)) throw new DomainException('ไม่พบรูปยืนยันสำหรับรายการนี้ กรุณาถ่ายรูปใหม่', 422);
        $root ??= self::checkinStorageRoot(); $base = realpath($root);
        $file = $base ? realpath($base . '/' . $relative) : false;
        if (!$file || $file !== $base . '/' . $relative || !is_file($file) || is_link($file)) {
            throw new DomainException('ไม่พบรูปยืนยัน กรุณาถ่ายรูปใหม่', 422);
        }
        $age = ($now ?? time()) - (int)filemtime($file);
        if ($age < -30 || $age > 120 || filesize($file) > 10485760) {
            throw new DomainException('รูปยืนยันหมดอายุ กรุณาถ่ายรูปใหม่', 422);
        }
        require_once __DIR__ . '/AttendancePhotoEvidence.php';
        if (!AttendancePhotoEvidence::isValidBinary((string)file_get_contents($file))) {
            throw new DomainException('รูปยืนยันไม่มีรายละเอียดหรือไม่ถูกต้อง กรุณาถ่ายรูปใหม่', 422);
        }
        return $relative;
    }

    private static function checkinStorageRoot(): string
    {
        if (defined('CHECKIN_STORAGE_PATH') && CHECKIN_STORAGE_PATH !== '') return CHECKIN_STORAGE_PATH;
        $parent = dirname(__DIR__, 3);
        foreach (['checkin.tp-asset.com', 'tp-checkin'] as $name) {
            if (is_dir($parent . '/' . $name . '/storage')) return $parent . '/' . $name . '/storage';
        }
        throw new DomainException('ระบบจัดเก็บรูปยังไม่พร้อม กรุณาลองใหม่', 503);
    }

    /** Must be called in the same transaction as the attendance timestamp. */
    public static function persistMetadata(PDO $pdo, int $id, string $side, array $gps, string $photoPath, string $device, string $receivedAt): void
    {
        if (!in_array($side, ['in', 'out'], true) || !$pdo->inTransaction()) throw new LogicException('Capture metadata requires its write transaction');
        $prefix = 'check_' . $side;
        $pdo->prepare("UPDATE hr_attendances SET {$prefix}_gps_captured_at=?,{$prefix}_gps_accuracy=?,{$prefix}_server_received_at=?,{$prefix}_photo=?,{$prefix}_device_info=? WHERE id=?")
            ->execute([$gps['captured_at'], $gps['accuracy'], $receivedAt, $photoPath, mb_substr($device, 0, 2048), $id]);
    }
}
