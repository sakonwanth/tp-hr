<?php
/**
 * HR module — DB queries & helpers
 * Source of truth: hr_attendances + hr_leave_requests + hr_leave_types (shared with tp-hr).
 * Legacy tables attendance_logs / leave_requests / leave_types are NOT used.
 *
 * Downstream consumer: modules/payroll/queries.php::payroll_compute_attendance_deductions()
 */

/**
 * สิทธิ์ admin ของ HR module (ดู/แก้ของคนอื่น, อนุมัติลา, reclassify)
 * สอดคล้องกับ crm_user_can_access_payroll_web + fallback ชื่อ role จากพารามิเตอร์
 */
function hr_is_admin($role_name) {
    if (function_exists('crm_user_can_access_payroll_web') && crm_user_can_access_payroll_web()) {
        return true;
    }
    return in_array((string)$role_name, ['HR', 'Admin', 'Chairman', 'CEO'], true);
}

/**
 * SELECT columns: map hr_attendances → legacy shape expected by CRM views/actions.
 * attendance_type values: present|absent|on_leave|holiday|weekend (mapped from status).
 */
function hr_log_select_columns($alias = 'ha') {
    return "
        {$alias}.id,
        {$alias}.user_id,
        {$alias}.attendance_date,
        TIME({$alias}.check_in_time)  AS check_in_time,
        TIME({$alias}.check_out_time) AS check_out_time,
        {$alias}.check_in_latitude  AS check_in_lat,
        {$alias}.check_in_longitude AS check_in_lng,
        NULL AS check_in_location_type,
        {$alias}.remarks AS check_in_notes,
        NULL AS check_out_notes,
        {$alias}.break_minutes,
        ROUND({$alias}.work_minutes/60, 2) AS work_hours,
        CASE WHEN {$alias}.status='LATE' OR {$alias}.late_minutes>0 THEN 1 ELSE 0 END AS is_late,
        {$alias}.late_minutes,
        {$alias}.late_excused,
        {$alias}.late_excused_reason,
        {$alias}.late_notified_at,
        {$alias}.planned_start_time,
        {$alias}.planned_status,
        {$alias}.planned_reason,
        {$alias}.planned_requested_at,
        {$alias}.remarks AS notes,
        {$alias}.adjustment_reason AS admin_notes,
        CASE WHEN {$alias}.approved_by IS NOT NULL THEN 1 ELSE 0 END AS is_approved,
        {$alias}.approved_by,
        {$alias}.approved_at,
        CASE {$alias}.status
            WHEN 'PRESENT'  THEN 'present'
            WHEN 'LATE'     THEN 'present'
            WHEN 'WFH'      THEN 'present'
            WHEN 'HALF_DAY' THEN 'present'
            WHEN 'ABSENT'   THEN 'absent'
            WHEN 'LEAVE'    THEN 'on_leave'
            WHEN 'HOLIDAY'  THEN 'holiday'
            WHEN 'PENDING'  THEN 'present'
            ELSE 'present'
        END AS attendance_type,
        {$alias}.status AS hr_status,
        {$alias}.created_at
    ";
}

function hr_get_log_for_date($pdo, $user_id, $date) {
    $cols = hr_log_select_columns('ha');
    $stmt = $pdo->prepare("SELECT {$cols} FROM hr_attendances ha WHERE ha.user_id = ? AND ha.attendance_date = ? LIMIT 1");
    $stmt->execute([$user_id, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hr_get_month_logs($pdo, $month_first, $user_id = null) {
    if (!function_exists('payroll_attendance_period_bounds')) {
        require_once dirname(__DIR__) . '/payroll/queries.php';
    }
    $pay_day = function_exists('payroll_get_default_pay_day') ? payroll_get_default_pay_day() : 25;
    $period = payroll_attendance_period_bounds($month_first, $pay_day);
    $month_start = $period['start'];
    $month_end = $period['end'];
    $cols = hr_log_select_columns('ha');
    $sql = "
        SELECT {$cols},
               CONCAT(COALESCE(u.first_name_th,''), ' ', COALESCE(u.last_name_th,'')) AS full_name,
               u.employee_code
        FROM hr_attendances ha
        JOIN users u ON ha.user_id = u.id
        WHERE ha.attendance_date BETWEEN ? AND ?
    ";
    $params = [$month_start, $month_end];
    if ($user_id) { $sql .= " AND ha.user_id = ?"; $params[] = $user_id; }
    $sql .= " ORDER BY ha.attendance_date DESC, full_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hr_month_stats($pdo, $user_id, $month_first) {
    if (!function_exists('payroll_attendance_period_bounds')) {
        require_once dirname(__DIR__) . '/payroll/queries.php';
    }
    $pay_day = function_exists('payroll_get_default_pay_day') ? payroll_get_default_pay_day() : 25;
    $period = payroll_attendance_period_bounds($month_first, $pay_day);
    $month_start = $period['start'];
    $scan_end = payroll_attendance_closed_scan_end($month_start, $period['end']);
    $tier1 = function_exists('payroll_late_tier1_min_minutes') ? payroll_late_tier1_min_minutes() : 20;
    if ($scan_end === '' || $scan_end < $month_start) {
        return ['total_days'=>0,'absent'=>0,'late_30'=>0,'late_60'=>0,'late_over60'=>0,'excused'=>0];
    }
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_days,
            SUM(CASE WHEN status='ABSENT' THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN status IN ('PRESENT','LATE','WFH','HALF_DAY')
                      AND late_minutes BETWEEN ? AND 30
                      AND late_excused=0 THEN 1 ELSE 0 END) AS late_30,
            SUM(CASE WHEN status IN ('PRESENT','LATE','WFH','HALF_DAY')
                      AND late_minutes BETWEEN 31 AND 60
                      AND late_excused=0 THEN 1 ELSE 0 END) AS late_60,
            SUM(CASE WHEN status IN ('PRESENT','LATE','WFH','HALF_DAY')
                      AND late_minutes>60
                      AND late_excused=0 THEN 1 ELSE 0 END) AS late_over60,
            SUM(CASE WHEN late_excused=1 THEN 1 ELSE 0 END) AS excused
        FROM hr_attendances
        WHERE user_id = ? AND attendance_date BETWEEN ? AND ?
    ");
    $stmt->execute([$tier1, $user_id, $month_start, $scan_end]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_days'=>0,'absent'=>0,'late_30'=>0,'late_60'=>0,'late_over60'=>0,'excused'=>0];
}

/** CRM Thai leave-type label → hr_leave_types.code */
function hr_map_leave_type_code($thai) {
    static $map = [
        'ลาป่วย'    => 'SICK',
        'ลากิจ'     => 'PERSONAL',
        'ลาพักร้อน' => 'ANNUAL',
        'ลาคลอด'    => 'MATERNITY',
        'ลาอื่นๆ'   => 'UNPAID',
    ];
    return $map[$thai] ?? 'UNPAID';
}

function hr_leave_code_to_label($code) {
    static $map = [
        'SICK'        => 'ลาป่วย',
        'PERSONAL'    => 'ลากิจ',
        'ANNUAL'      => 'ลาพักร้อน',
        'MATERNITY'   => 'ลาคลอด',
        'ORDINATION'  => 'ลาบวช',
        'MARRIAGE'    => 'ลาแต่งงาน',
        'BEREAVEMENT' => 'ลากรณีญาติเสีย',
        'MILITARY'    => 'ลารับราชการทหาร',
        'TRAINING'    => 'ลาฝึกอบรม',
        'UNPAID'      => 'ลาอื่นๆ',
    ];
    return $map[$code] ?? 'ลาอื่นๆ';
}

function hr_leave_status_to_legacy($status) {
    static $m = [
        'PENDING'   => 'รอการอนุมัติ',
        'APPROVED'  => 'อนุมัติ',
        'REJECTED'  => 'ไม่อนุมัติ',
        'CANCELLED' => 'ยกเลิก',
        'DRAFT'     => 'รอการอนุมัติ',
    ];
    return $m[$status] ?? 'รอการอนุมัติ';
}

function hr_get_leaves($pdo, $filters = []) {
    static $status_map_in = [
        'รอการอนุมัติ'  => 'PENDING',
        'อนุมัติ'       => 'APPROVED',
        'ไม่อนุมัติ'     => 'REJECTED',
        'ยกเลิก'        => 'CANCELLED',
    ];
    $sql = "
        SELECT
            lr.id, lr.user_id, lr.start_date, lr.end_date,
            lr.total_days AS leave_days,
            lr.reason,
            CASE WHEN lt.requires_document=1 AND lr.document_path IS NOT NULL THEN 1 ELSE 0 END AS has_medical_cert,
            lr.status AS hr_status,
            CASE lr.status
                WHEN 'PENDING'   THEN 'รอการอนุมัติ'
                WHEN 'APPROVED'  THEN 'อนุมัติ'
                WHEN 'REJECTED'  THEN 'ไม่อนุมัติ'
                WHEN 'CANCELLED' THEN 'ยกเลิก'
                WHEN 'DRAFT'     THEN 'รอการอนุมัติ'
                ELSE 'รอการอนุมัติ'
            END AS status,
            lt.code AS leave_type_code,
            CASE lt.code
                WHEN 'SICK'        THEN 'ลาป่วย'
                WHEN 'PERSONAL'    THEN 'ลากิจ'
                WHEN 'ANNUAL'      THEN 'ลาพักร้อน'
                WHEN 'MATERNITY'   THEN 'ลาคลอด'
                WHEN 'ORDINATION'  THEN 'ลาบวช'
                WHEN 'MARRIAGE'    THEN 'ลาแต่งงาน'
                WHEN 'BEREAVEMENT' THEN 'ลากรณีญาติเสีย'
                WHEN 'MILITARY'    THEN 'ลารับราชการทหาร'
                WHEN 'TRAINING'    THEN 'ลาฝึกอบรม'
                ELSE 'ลาอื่นๆ'
            END AS leave_type,
            lr.final_approved_by,
            lr.final_approved_at,
            lr.cancel_reason AS rejection_reason,
            lr.created_at,
            lr.updated_at,
            CONCAT(COALESCE(u.first_name_th,''), ' ', COALESCE(u.last_name_th,'')) AS full_name,
            u.employee_code,
            DATEDIFF(lr.start_date, DATE(lr.created_at)) AS notice_days
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
        JOIN users u ON lr.user_id = u.id
        WHERE 1=1
    ";
    $params = [];
    if (!empty($filters['user_id'])) { $sql .= " AND lr.user_id = ?"; $params[] = $filters['user_id']; }
    if (!empty($filters['status'])) {
        $hs = $status_map_in[$filters['status']] ?? $filters['status'];
        $sql .= " AND lr.status = ?"; $params[] = $hs;
    }
    if (!empty($filters['month'])) {
        $m_start = date('Y-m-01', strtotime($filters['month']));
        $m_end   = date('Y-m-t',  strtotime($filters['month']));
        $sql .= " AND lr.start_date <= ? AND lr.end_date >= ?";
        $params[] = $m_end; $params[] = $m_start;
    }
    $sql .= " ORDER BY lr.start_date DESC, lr.id DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Source-of-truth: hr_work_shifts.start_time ของ default shift
 *
 * Priority:
 *   1. hr_work_shifts WHERE is_default=1 AND is_active=1 (ตรงกับ tp-checkin/tp-hr ใช้)
 *   2. system_settings.work_start_time (legacy key; เก็บ 08:30 ในตาราง tp-crm)
 *   3. '08:30' hardcoded fallback
 *
 * NOTE (audit 2026-04-23):
 *   - เดิม code อ่าน key 'hr_work_start_time' แต่ DB seed ใช้ 'work_start_time' (ไม่มี prefix)
 *     → getSetting() หาไม่เจอ → fallback '08:30' เสมอ ไม่ว่า admin จะ config อะไร
 *   - Fix: อ่านจาก hr_work_shifts (single source) ก่อน เพื่อให้ sync กับ checkin/hr module อัตโนมัติ
 */
function hr_work_start_time() {
    static $cached = null;
    if ($cached !== null) return $cached;

    // Priority 1: default active shift in hr_work_shifts
    try {
        $pdo = function_exists('getDB') ? getDB() : (isset($GLOBALS['pdo']) ? $GLOBALS['pdo'] : null);
        if ($pdo instanceof PDO) {
            $s = $pdo->query("SELECT start_time FROM hr_work_shifts WHERE is_default=1 AND is_active=1 LIMIT 1");
            $row = $s ? $s->fetch(PDO::FETCH_ASSOC) : null;
            if ($row && !empty($row['start_time'])) {
                $cached = substr((string)$row['start_time'], 0, 5);
                if (preg_match('/^\d{2}:\d{2}$/', $cached)) return $cached;
            }
        }
    } catch (Throwable $e) {
        // ignore — fall through to setting / hardcoded
    }

    // Priority 2: system_settings (check both keys for backward-compat)
    if (function_exists('getSetting')) {
        foreach (['work_start_time', 'hr_work_start_time'] as $k) {
            $t = getSetting($k, null);
            if (!empty($t) && preg_match('/^\d{2}:\d{2}$/', $t)) {
                $cached = $t;
                return $cached;
            }
        }
    }

    $cached = '08:30';
    return $cached;
}

function hr_calc_late_minutes($check_in_time, $work_start = null) {
    $ws = $work_start ?: hr_work_start_time();
    $diff = (strtotime($check_in_time) - strtotime($ws)) / 60;
    return $diff > 0 ? (int)round($diff) : 0;
}

/**
 * =============================================================================
 * Late-Shift Request helpers (Sprint S1)
 * =============================================================================
 */

/**
 * คำนวณนาทีเข้างานสาย โดยรองรับ "เวลาเข้างานที่แจ้งล่วงหน้า"
 *
 * - ถ้ามี $planned_start → ใช้แทน work_start (override shift start)
 * - ถ้าเช็คอินภายใน $grace_minutes ของ effective_start → คืน 0 (ไม่หัก)
 * - ถ้าเลย grace → คืนนาทีรวมจาก effective_start (ไม่ใช่จาก grace cutoff)
 *
 * NOTE: ไม่แก้พฤติกรรมของ hr_calc_late_minutes() เดิม — ฟังก์ชันนี้เป็น superset
 *       ที่ payroll & checkin จะเรียกในเฟส S2-S4
 *
 * @param string  $check_in_time  เวลาที่เช็คอินจริง (HH:MM:SS หรือ datetime)
 * @param ?string $planned_start  เวลาที่แจ้งล่วงหน้า (HH:MM, null = ใช้ work_start ปกติ)
 * @param ?string $work_start     เวลาเข้างานปกติ (null = อ่านจาก setting hr_work_start_time)
 * @param int     $grace_minutes  ระยะเวลาผ่อนผัน (default 30 นาที ตาม payroll_late_30_rate)
 * @return int                    นาทีที่สาย (0 ถ้าไม่สาย / มาตรงเวลา / ภายใน grace)
 */
function hr_calc_late_minutes_with_planned($check_in_time, $planned_start = null, $work_start = null, $grace_minutes = 30) {
    $effective_start = !empty($planned_start) ? $planned_start : ($work_start ?: hr_work_start_time());
    $checkin_ts = strtotime($check_in_time);
    $start_ts   = strtotime($effective_start);
    if (!$checkin_ts || !$start_ts) return 0;

    // ถ้ายังภายใน grace → ไม่ถือว่าสาย
    if (($checkin_ts - $start_ts) <= ($grace_minutes * 60)) {
        return 0;
    }

    // เลย grace → คืนนาทีรวมจาก effective_start (จะถูกใช้ใน bucket 30/60/>60)
    return (int) round(($checkin_ts - $start_ts) / 60);
}

/**
 * ชั่วโมง cutoff สำหรับการแจ้งเข้างานสาย "ของวันปัจจุบัน"
 * Default 7 = ต้องแจ้งก่อน 07:00 ของวันที่ตั้งใจมาสาย
 * Override ได้ผ่าน system_settings.hr_late_request_cutoff_hour
 */
function hr_late_request_cutoff_hour(): int {
    $h = function_exists('getSetting') ? (int) getSetting('hr_late_request_cutoff_hour', '7') : 7;
    return ($h >= 0 && $h <= 12) ? $h : 7;
}

/**
 * ตรวจสอบว่าผู้ใช้สามารถแจ้งเข้างานสายสำหรับวันที่ระบุได้หรือไม่
 *
 * เงื่อนไขตามที่ตกลงกับ business:
 *   - target_date เป็นอนาคต (พรุ่งนี้หรือไกลกว่า) → ได้เสมอ
 *   - target_date เป็นวันนี้ + เวลาปัจจุบันก่อน cutoff (default 07:00) → ได้
 *   - target_date เป็นวันนี้ + หลัง cutoff → ต้องใช้ flow `hr_attendance_adjustments`
 *   - target_date เป็นอดีต → ใช้ flow `hr_attendance_adjustments` เท่านั้น
 *
 * @param string $target_date 'Y-m-d'
 * @param ?int   $now_ts      timestamp ปัจจุบัน (null = time())
 * @return array {
 *   ok: bool,
 *   reason: 'allowed'|'past_date'|'after_cutoff'|'invalid_date',
 *   message: string,
 *   fallback: ?string  // คำแนะนำ flow ที่ใช้แทนได้ (เช่น 'request_adjustment')
 * }
 */
function hr_can_request_late_start(string $target_date, ?int $now_ts = null, ?int $user_id = null): array {
    $now      = $now_ts ?? time();
    $today    = date('Y-m-d', $now);
    $valid_dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date) === 1;

    if (!$valid_dt) {
        return [
            'ok'       => false,
            'reason'   => 'invalid_date',
            'message'  => 'รูปแบบวันที่ไม่ถูกต้อง',
            'fallback' => null,
        ];
    }

    if ($target_date < $today) {
        return [
            'ok'       => false,
            'reason'   => 'past_date',
            'message'  => 'ไม่สามารถแจ้งเข้างานสายย้อนหลังได้ — กรุณาใช้แบบฟอร์ม "ขอแก้ไขเวลาเข้างาน" แทน',
            'fallback' => 'request_adjustment',
        ];
    }

    if ($user_id === null && function_exists('getCurrentUserId')) {
        $user_id = (int)getCurrentUserId();
    }
    if ($user_id > 0 && function_exists('getDB') && class_exists(\TpCommon\Hr\WorkdayCalculator::class)) {
        $pdo = getDB();
        if (!\TpCommon\Hr\WorkdayCalculator::isExpectedWorkdayForUser($pdo, $user_id, $target_date)) {
            return [
                'ok'       => false,
                'reason'   => 'non_workday',
                'message'  => 'วันที่เลือกเป็นวันหยุด — ไม่ต้องแจ้งเข้างานสาย',
                'fallback' => null,
            ];
        }
    }

    if ($target_date === $today) {
        $cutoff_h  = hr_late_request_cutoff_hour();
        $cutoff_ts = strtotime($today . ' ' . sprintf('%02d:00:00', $cutoff_h));
        if ($now >= $cutoff_ts) {
            return [
                'ok'       => false,
                'reason'   => 'after_cutoff',
                'message'  => sprintf(
                    'เลยเวลาแจ้งเข้างานสายของวันนี้แล้ว (ต้องแจ้งก่อน %02d:00) — กรุณาเข้างานตามเวลาปกติ หากผิดพลาดให้ใช้แบบฟอร์ม "ขอแก้ไขเวลาเข้างาน"',
                    $cutoff_h
                ),
                'fallback' => 'request_adjustment',
            ];
        }
    }

    return [
        'ok'       => true,
        'reason'   => 'allowed',
        'message'  => '',
        'fallback' => null,
    ];
}
