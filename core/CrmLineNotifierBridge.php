<?php
/**
 * Bridge TP-HR leave actions to TP-CRM LINE notification events.
 *
 * The event configuration remains centralized in CRM:
 * settings.php?tab=line_notifications
 *
 * Deployment: set TP_CRM_PATH in .env to the filesystem path of tp-crm when it is not
 * a sibling directory (../tp-crm). See config/app.php.
 */

function crm_line_bridge_path(): ?string {
    if (defined('TP_CRM_PATH')) {
        $p = (string) TP_CRM_PATH;
        if ($p !== '' && is_dir($p)) {
            return rtrim($p, '/\\');
        }
    }
    $env = $_ENV['TP_CRM_PATH'] ?? getenv('TP_CRM_PATH');
    if (is_string($env) && $env !== '') {
        $p = rtrim($env, '/\\');
        if (is_dir($p)) {
            return $p;
        }
    }
    $fallback = dirname(BASE_PATH) . DIRECTORY_SEPARATOR . 'tp-crm';
    return is_dir($fallback) ? $fallback : null;
}

function crm_line_bridge_warn_missing_path(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    error_log(
        'TP-HR CRM LINE bridge: tp-crm directory not found. Set TP_CRM_PATH in .env to the absolute path '
        . 'of tp-crm (folder containing config/line.php), or deploy tp-crm as a sibling of tp-hr.'
    );
}

function crm_line_bridge_load(): bool {
    static $loadedOk = false;
    if ($loadedOk) {
        return true;
    }

    $crmPath = crm_line_bridge_path();
    if (!$crmPath) {
        crm_line_bridge_warn_missing_path();
        return false;
    }

    try {
        if (!defined('TP_CRM_PATH')) {
            define('TP_CRM_PATH', $crmPath);
        }
        if (!function_exists('env_value')) {
            function env_value($key, $default = null) {
                $value = $_ENV[$key] ?? getenv($key);
                return ($value === false || $value === null || $value === '') ? $default : $value;
            }
        }
        require_once $crmPath . '/config/line.php';
        require_once $crmPath . '/core/Services/LineNotifier.php';
        require_once $crmPath . '/modules/hr/notifications.php';
        if (!class_exists('LineNotifier') || !function_exists('hr_flex_new_leave')) {
            error_log(
                'TP-HR CRM LINE bridge: files loaded from ' . $crmPath
                . ' but LineNotifier class or hr_flex_new_leave() is missing.'
            );
            return false;
        }
        $loadedOk = true;
        return true;
    } catch (Throwable $e) {
        error_log('TP-HR CRM LINE bridge load error: ' . $e->getMessage());
        return false;
    }
}

function crm_line_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        return (new SettingsService($pdo))->getSystem($key, $default);
    } catch (Throwable $e) {
        return $default;
    }
}

function crm_line_user_name(array $user): string {
    $name = trim(($user['first_name_th'] ?? '') . ' ' . ($user['last_name_th'] ?? ''));
    if ($name !== '') return $name;
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    return $name !== '' ? $name : ($user['username'] ?? 'พนักงาน');
}

function crm_line_leave_context(PDO $pdo, int $requestId): ?array {
    $stmt = $pdo->prepare("
        SELECT lr.*, lt.code AS leave_code, lt.name AS leave_type_name,
               u.id AS employee_id, u.username,
               u.first_name, u.last_name, u.first_name_th, u.last_name_th
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
        JOIN users u ON lr.user_id = u.id
        WHERE lr.id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_line_notify_new_leave(PDO $pdo, int $requestId): void {
    if (!crm_line_bridge_load()) return;

    try {
        $ctx = crm_line_leave_context($pdo, $requestId);
        if (!$ctx) return;

        $noticeDays = max(0, (int)floor((strtotime($ctx['start_date']) - strtotime(date('Y-m-d'))) / 86400));
        $threshold = (int)crm_line_setting($pdo, 'payroll_leave_advance_days', '7');
        $belowThreshold = ($ctx['leave_code'] ?? '') !== 'SICK' && $noticeDays < $threshold;

        $flex = hr_flex_new_leave(
            crm_line_user_name($ctx),
            $ctx['leave_type_name'] ?? $ctx['leave_code'] ?? 'ลา',
            $ctx['start_date'],
            $ctx['end_date'],
            (float)$ctx['total_days'],
            $noticeDays,
            mb_substr((string)($ctx['reason'] ?? ''), 0, 200),
            $belowThreshold,
            $threshold
        );
        LineNotifier::sendEvent($pdo, 'hr.new_leave', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
    } catch (Throwable $e) {
        error_log('crm_line_notify_new_leave error: ' . $e->getMessage());
    }
}

function crm_line_notify_leave_decision(PDO $pdo, int $requestId, string $decision, string $note = ''): void {
    if (!crm_line_bridge_load()) return;

    try {
        $ctx = crm_line_leave_context($pdo, $requestId);
        if (!$ctx) return;

        $leaveName = $ctx['leave_type_name'] ?? $ctx['leave_code'] ?? 'ลา';
        if ($decision === 'APPROVED') {
            $flex = hr_flex_leave_approved($leaveName, $ctx['start_date'], $ctx['end_date'], (float)$ctx['total_days'], $note);
            LineNotifier::sendEvent($pdo, 'hr.leave_approved', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
        } elseif ($decision === 'REJECTED') {
            $flex = hr_flex_leave_rejected($leaveName, $ctx['start_date'], $ctx['end_date'], $note);
            LineNotifier::sendEvent($pdo, 'hr.leave_rejected', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_leave_decision error: ' . $e->getMessage());
    }
}

function crm_line_sync_approved_leave_attendance(PDO $pdo, int $requestId, int $actorId, string $actorName): void {
    $ctx = crm_line_leave_context($pdo, $requestId);
    if (!$ctx) return;

    $audit = "[" . date('Y-m-d H:i') . " by {$actorName}] leave #{$requestId} approved -> LEAVE";
    $sync = $pdo->prepare("
        INSERT INTO hr_attendances (user_id, attendance_date, status, adjustment_reason, adjusted_by, adjusted_at, approved_by, approved_at)
        VALUES (?, ?, 'LEAVE', ?, ?, NOW(), ?, NOW())
        ON DUPLICATE KEY UPDATE
            status = 'LEAVE',
            adjustment_reason = CONCAT_WS(\"\\n\", NULLIF(adjustment_reason,''), VALUES(adjustment_reason)),
            adjusted_by = VALUES(adjusted_by), adjusted_at = NOW(),
            approved_by = VALUES(approved_by), approved_at = NOW()
    ");

    $start = strtotime($ctx['start_date']);
    $end = strtotime($ctx['end_date']);
    for ($t = $start; $t <= $end; $t += 86400) {
        $sync->execute([(int)$ctx['user_id'], date('Y-m-d', $t), $audit, $actorId, $actorId]);
    }
}

/**
 * Post-S5: แจ้ง HR + Admin + Chairman + CEO เมื่อพนักงานยื่นคำขอเข้างานสายล่วงหน้า
 * และส่ง confirmation Flex กลับให้พนักงานด้วย
 * (mirror ของ tp-checkin/core/CrmLineNotifierBridge.php)
 */
function crm_line_notify_planned_late_request(PDO $pdo, array $user, string $targetDate, string $plannedTime, string $reason, ?string $requestedAt = null): void {
    if (!crm_line_bridge_load()) return;

    try {
        if (function_exists('ensurePlannedLateNotificationEvents')) {
            try { ensurePlannedLateNotificationEvents($pdo); } catch (Throwable $e) { /* non-fatal */ }
        }

        $employeeName = crm_line_user_name($user);

        // 1) HR + Admin + Chairman + CEO
        if (function_exists('hr_flex_planned_late_request')) {
            $flex = hr_flex_planned_late_request($employeeName, $targetDate, $plannedTime, mb_substr($reason, 0, 200), $requestedAt);
            LineNotifier::sendEvent($pdo, 'hr.new_planned_late', $flex, ['triggering_user_id' => (int)$user['id']]);
        }

        // 2) Confirmation Flex → พนักงาน
        if (function_exists('hr_flex_planned_late_confirmed')) {
            $graceMin = (int)crm_line_setting($pdo, 'payroll_planned_grace_minutes', '30');

            $flex2 = hr_flex_planned_late_confirmed($targetDate, $plannedTime, $graceMin);
            LineNotifier::sendEvent($pdo, 'hr.planned_late_confirmed', $flex2, ['triggering_user_id' => (int)$user['id']]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_planned_late_request error: ' . $e->getMessage());
    }
}

/**
 * Post-S5: แจ้ง HR + Admin + Chairman + CEO เมื่อพนักงานยกเลิกคำขอเข้างานสายล่วงหน้า
 */
function crm_line_notify_planned_late_cancelled(PDO $pdo, array $user, string $targetDate, ?string $previousTime = null): void {
    if (!crm_line_bridge_load()) return;

    try {
        if (function_exists('ensurePlannedLateNotificationEvents')) {
            try { ensurePlannedLateNotificationEvents($pdo); } catch (Throwable $e) { /* non-fatal */ }
        }

        if (function_exists('hr_flex_planned_late_cancelled')) {
            $flex = hr_flex_planned_late_cancelled(crm_line_user_name($user), $targetDate, $previousTime);
            LineNotifier::sendEvent($pdo, 'hr.cancel_planned_late', $flex, ['triggering_user_id' => (int)$user['id']]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_planned_late_cancelled error: ' . $e->getMessage());
    }
}

function crm_line_adjustment_context(PDO $pdo, int $adjustmentId): ?array {
    $stmt = $pdo->prepare("
        SELECT adj.*, att.attendance_date,
               u.id AS employee_id, u.username,
               u.first_name, u.last_name, u.first_name_th, u.last_name_th
        FROM hr_attendance_adjustments adj
        JOIN hr_attendances att ON att.id = adj.attendance_id
        JOIN users u ON u.id = adj.user_id
        WHERE adj.id = ?
        LIMIT 1
    ");
    $stmt->execute([$adjustmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_line_notify_attendance_adjustment_requested(PDO $pdo, int $adjustmentId): void {
    if (!crm_line_bridge_load()) return;

    try {
        if (function_exists('ensureAttendanceAdjustmentNotificationEvents')) {
            try { ensureAttendanceAdjustmentNotificationEvents($pdo); } catch (Throwable $e) { /* non-fatal */ }
        }
        $ctx = crm_line_adjustment_context($pdo, $adjustmentId);
        if (!$ctx || !function_exists('hr_flex_attendance_adjustment_requested')) return;

        $flex = hr_flex_attendance_adjustment_requested(
            crm_line_user_name($ctx),
            $ctx['attendance_date'],
            $ctx['adjustment_type'] ?? 'both',
            $ctx['original_check_in'] ?? null,
            $ctx['original_check_out'] ?? null,
            $ctx['requested_check_in'] ?? null,
            $ctx['requested_check_out'] ?? null,
            (string)($ctx['reason'] ?? '')
        );
        LineNotifier::sendEvent($pdo, 'hr.attendance_adjustment_requested', $flex, [
            'triggering_user_id' => (int)$ctx['user_id'],
        ]);
    } catch (Throwable $e) {
        error_log('crm_line_notify_attendance_adjustment_requested error: ' . $e->getMessage());
    }
}

function crm_line_notify_attendance_adjustment_decision(PDO $pdo, int $adjustmentId, string $decision, string $note = ''): void {
    if (!crm_line_bridge_load()) return;

    try {
        if (function_exists('ensureAttendanceAdjustmentNotificationEvents')) {
            try { ensureAttendanceAdjustmentNotificationEvents($pdo); } catch (Throwable $e) { /* non-fatal */ }
        }
        $ctx = crm_line_adjustment_context($pdo, $adjustmentId);
        if (!$ctx) return;

        $type = $ctx['adjustment_type'] ?? 'both';
        $date = $ctx['attendance_date'];
        if ($decision === 'APPROVED' && function_exists('hr_flex_attendance_adjustment_approved')) {
            $flex = hr_flex_attendance_adjustment_approved($date, $type, $note);
            LineNotifier::sendEvent($pdo, 'hr.attendance_adjustment_approved', $flex, [
                'triggering_user_id' => (int)$ctx['user_id'],
            ]);
        } elseif ($decision === 'REJECTED' && function_exists('hr_flex_attendance_adjustment_rejected')) {
            $flex = hr_flex_attendance_adjustment_rejected($date, $type, $note);
            LineNotifier::sendEvent($pdo, 'hr.attendance_adjustment_rejected', $flex, [
                'triggering_user_id' => (int)$ctx['user_id'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_attendance_adjustment_decision error: ' . $e->getMessage());
    }
}

function crm_line_dayoff_context(PDO $pdo, int $requestId): ?array {
    $stmt = $pdo->prepare("
        SELECT d.*, u.username, u.first_name, u.last_name, u.first_name_th, u.last_name_th
        FROM hr_dayoff_requests d
        JOIN users u ON u.id = d.user_id
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_line_notify_dayoff_requested(PDO $pdo, int $requestId): void {
    if (!crm_line_bridge_load()) return;
    try {
        $ctx = crm_line_dayoff_context($pdo, $requestId);
        if (!$ctx || !function_exists('hr_flex_dayoff_requested')) return;
        $flex = hr_flex_dayoff_requested(
            crm_line_user_name($ctx),
            $ctx['week_start'],
            $ctx['week_end'],
            (int)$ctx['original_day_off'],
            (int)$ctx['requested_day_off'],
            (string)($ctx['reason'] ?? '')
        );
        LineNotifier::sendEvent($pdo, 'hr.dayoff_requested', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
    } catch (Throwable $e) {
        error_log('crm_line_notify_dayoff_requested error: ' . $e->getMessage());
    }
}

function crm_line_notify_dayoff_decision(PDO $pdo, int $requestId, string $decision, string $note = ''): void {
    if (!crm_line_bridge_load()) return;
    try {
        $ctx = crm_line_dayoff_context($pdo, $requestId);
        if (!$ctx) return;
        if ($decision === 'APPROVED' && function_exists('hr_flex_dayoff_approved')) {
            $flex = hr_flex_dayoff_approved($ctx['week_start'], $ctx['week_end'], (int)$ctx['requested_day_off'], $note);
            LineNotifier::sendEvent($pdo, 'hr.dayoff_approved', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
        } elseif ($decision === 'REJECTED' && function_exists('hr_flex_dayoff_rejected')) {
            $flex = hr_flex_dayoff_rejected($ctx['week_start'], $ctx['week_end'], $note);
            LineNotifier::sendEvent($pdo, 'hr.dayoff_rejected', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_dayoff_decision error: ' . $e->getMessage());
    }
}

function crm_line_holiday_work_context(PDO $pdo, int $requestId): ?array {
    $stmt = $pdo->prepare("
        SELECT r.*, u.username, u.first_name, u.last_name, u.first_name_th, u.last_name_th
        FROM hr_holiday_work_exceptions r
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_line_notify_holiday_work_requested(PDO $pdo, int $requestId): void {
    if (!crm_line_bridge_load()) {
        return;
    }
    try {
        $ctx = crm_line_holiday_work_context($pdo, $requestId);
        if (!$ctx || !function_exists('hr_flex_holiday_work_requested')) {
            return;
        }
        $flex = hr_flex_holiday_work_requested(
            crm_line_user_name($ctx),
            $ctx['holiday_date'],
            $ctx['comp_date'] ?? null,
            (string) ($ctx['holiday_name'] ?? ''),
            (string) ($ctx['reason'] ?? '')
        );
        LineNotifier::sendEvent($pdo, 'hr.holiday_work_requested', $flex, ['triggering_user_id' => (int) $ctx['user_id']]);
    } catch (Throwable $e) {
        error_log('crm_line_notify_holiday_work_requested error: ' . $e->getMessage());
    }
}

function crm_line_notify_holiday_work_decision(PDO $pdo, int $requestId, string $decision, string $note = ''): void {
    if (!crm_line_bridge_load()) {
        return;
    }
    try {
        $ctx = crm_line_holiday_work_context($pdo, $requestId);
        if (!$ctx) {
            return;
        }
        if ($decision === 'APPROVED' && function_exists('hr_flex_holiday_work_approved')) {
            $flex = hr_flex_holiday_work_approved($ctx['holiday_date'], $ctx['comp_date'] ?? null, $note);
            LineNotifier::sendEvent($pdo, 'hr.holiday_work_approved', $flex, ['triggering_user_id' => (int) $ctx['user_id']]);
        } elseif ($decision === 'REJECTED' && function_exists('hr_flex_holiday_work_rejected')) {
            $flex = hr_flex_holiday_work_rejected($ctx['holiday_date'], $note);
            LineNotifier::sendEvent($pdo, 'hr.holiday_work_rejected', $flex, ['triggering_user_id' => (int) $ctx['user_id']]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_holiday_work_decision error: ' . $e->getMessage());
    }
}

function crm_line_outside_context(PDO $pdo, int $requestId): ?array {
    $stmt = $pdo->prepare("
        SELECT r.*, u.username, u.first_name, u.last_name, u.first_name_th, u.last_name_th
        FROM hr_attendance_outside_requests r
        JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function crm_line_notify_outside_attendance_requested(PDO $pdo, int $requestId): void {
    if (!crm_line_bridge_load()) return;
    try {
        $ctx = crm_line_outside_context($pdo, $requestId);
        if (!$ctx || !function_exists('hr_flex_outside_attendance_requested')) return;
        $flex = hr_flex_outside_attendance_requested(
            crm_line_user_name($ctx),
            $ctx['request_date'],
            (string)$ctx['request_type'],
            $ctx['request_time'] ?? null,
            (string)($ctx['reason'] ?? '')
        );
        LineNotifier::sendEvent($pdo, 'hr.outside_attendance_requested', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
    } catch (Throwable $e) {
        error_log('crm_line_notify_outside_attendance_requested error: ' . $e->getMessage());
    }
}

function crm_line_notify_outside_attendance_decision(PDO $pdo, int $requestId, string $decision, string $remarks = ''): void {
    if (!crm_line_bridge_load()) return;
    try {
        $ctx = crm_line_outside_context($pdo, $requestId);
        if (!$ctx) return;
        $type = (string)$ctx['request_type'];
        $date = (string)$ctx['request_date'];
        if ($decision === 'APPROVED' && function_exists('hr_flex_outside_attendance_approved')) {
            $flex = hr_flex_outside_attendance_approved($date, $type, $remarks);
            LineNotifier::sendEvent($pdo, 'hr.outside_attendance_approved', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
        } elseif ($decision === 'REJECTED' && function_exists('hr_flex_outside_attendance_rejected')) {
            $flex = hr_flex_outside_attendance_rejected($date, $type, $remarks);
            LineNotifier::sendEvent($pdo, 'hr.outside_attendance_rejected', $flex, ['triggering_user_id' => (int)$ctx['user_id']]);
        }
    } catch (Throwable $e) {
        error_log('crm_line_notify_outside_attendance_decision error: ' . $e->getMessage());
    }
}
