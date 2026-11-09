<?php
declare(strict_types=1);

/** Read-only admission/health view of the operator-owned clock monitor. */
final class ClockHealth
{
    public const DEFAULT_PATH = '/var/www/vhosts/tp-asset.com/.clock-health/status.json';
    public const MAX_AGE_SECONDS = 180;

    public static function read(?string $path = null, ?int $now = null, ?bool $required = null): array
    {
        $required ??= defined('APP_ENV') && strtolower((string) APP_ENV) === 'production';
        $path ??= defined('TP_CLOCK_HEALTH_PATH') ? (string) TP_CLOCK_HEALTH_PATH : self::DEFAULT_PATH;
        $now ??= time();
        try {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                return $required ? self::failure('monitor_missing') : ['status' => 'disabled', 'reason_codes' => [], 'checked_at' => null, 'age_seconds' => null];
            }
            if (is_link($path)) return self::failure('monitor_untrusted');
            $handle = @fopen($path, 'rb');
            if ($handle === false) return self::failure('monitor_unreadable');
            try {
                // Bind trust checks and content reads to the same open inode.
                $meta = fstat($handle);
                if (!is_array($meta) || ($meta['mode'] & 0170000) !== 0100000 || ($meta['mode'] & 0022) !== 0
                    || ($meta['nlink'] ?? 0) !== 1 || ($path === self::DEFAULT_PATH && $meta['uid'] !== 0)) {
                    return self::failure('monitor_untrusted');
                }
                if ($meta['size'] > 65536) return self::failure('monitor_invalid');
                $raw = stream_get_contents($handle, 65537);
                if (!is_string($raw) || strlen($raw) > 65536) return self::failure('monitor_invalid');
            } finally { fclose($handle); }
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) return self::failure('monitor_invalid');
            // Plesk may forbid /proc even when the protected monitor file is readable.
            try { $uptime = @file_get_contents('/proc/uptime'); } catch (Throwable $e) { $uptime = false; }
            $bootSeconds = is_string($uptime) && preg_match('/^([0-9.]+)/', $uptime, $m) ? (float) $m[1] : null;
            return self::evaluate($data, $now, $bootSeconds, hrtime(true) / 1000000000);
        } catch (Throwable $e) {
            return self::failure('monitor_unreadable');
        }
    }

    /** Pure policy; no shell commands, network requests, clock adjustments or DB writes. */
    public static function evaluate(array $data, int $now, ?float $bootSeconds = null, ?float $monotonicSeconds = null): array
    {
        if (!isset($data['checked_at'], $data['status']) || !is_numeric($data['checked_at']) || !in_array($data['status'], ['healthy', 'warning', 'critical'], true)) return self::failure('monitor_invalid');
        $age = $now - (int) $data['checked_at'];
        $reasons = array_values(array_filter((array) ($data['reason_codes'] ?? []), static fn($v) => is_string($v) && preg_match('/^[a-z_]+$/D', $v)));
        $status = $data['status'];
        if ($age > self::MAX_AGE_SECONDS || $age < -5) {
            $status = 'critical';$reasons[] = $age < -5 ? 'clock_jump' : 'monitor_stale';
        }
        if ($bootSeconds !== null && isset($data['checked_boot_seconds']) && is_numeric($data['checked_boot_seconds'])) {
            $elapsed = $bootSeconds - (float) $data['checked_boot_seconds'];
            if ($elapsed < -5 || abs($age - $elapsed) > 2) {$status = 'critical';$reasons[] = 'clock_jump';}
        }
        // CLOCK_MONOTONIC is shared by Python/PHP on this host and needs no /proc access.
        if ($monotonicSeconds !== null && isset($data['checked_monotonic_seconds']) && is_numeric($data['checked_monotonic_seconds'])) {
            $elapsed = $monotonicSeconds - (float)$data['checked_monotonic_seconds'];
            if ($elapsed < -5 || abs($age - $elapsed) > 2) { $status='critical';$reasons[]='clock_jump'; }
        }
        $offset = $data['offset_ms'] ?? null;
        $limit = is_numeric($data['critical_threshold_ms'] ?? null) ? (float) $data['critical_threshold_ms'] : 2000.0;
        if ($limit <= 0 || $limit > 60000) return self::failure('monitor_invalid');
        if (($data['ntp_active'] ?? false) !== true || ($data['synchronized'] ?? false) !== true) {$status = 'critical';$reasons[] = 'ntp_unsynchronized';}
        if (!is_numeric($offset) || !is_finite((float) $offset)) {$status = 'critical';$reasons[] = 'offset_unknown';}
        elseif (abs((float) $offset) > $limit) {$status = 'critical';$reasons[] = 'clock_offset';}
        return ['status' => $status, 'checked_at' => (int) $data['checked_at'], 'age_seconds' => $age, 'offset_ms' => is_numeric($offset) ? (float) $offset : null, 'reason_codes' => array_values(array_unique($reasons))];
    }

    public static function assertSafe(?string $path = null, ?int $now = null, ?bool $required = null): void
    {
        if (self::read($path, $now, $required)['status'] === 'critical') {
            throw new DomainException('เวลาของระบบยังตรวจสอบไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบก่อนลงเวลา', 503);
        }
    }

    public static function healthCheck(): array
    {
        $state = self::read();
        return ['status' => match ($state['status']) {'critical' => 'error', 'warning' => 'warning', default => 'ok'}, 'monitor_status' => $state['status'], 'checked_at' => $state['checked_at'] ?? null, 'age_seconds' => $state['age_seconds'] ?? null, 'offset_ms' => $state['offset_ms'] ?? null];
    }

    private static function failure(string $reason): array
    {
        return ['status' => 'critical', 'reason_codes' => [$reason], 'checked_at' => null, 'age_seconds' => null, 'offset_ms' => null];
    }
}
