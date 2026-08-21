<?php

/**
 * Pure presentation policy for the HR daily attendance report.
 *
 * This class deliberately does not query or write the database. Callers resolve
 * the employee's effective calendar first, then pass the facts here so the UI,
 * filters, and KPI cards all use the same classification.
 */
final class DailyAttendancePolicy
{
    /** @return array{code:string,label:string,is_working:bool,is_absent:bool,is_late:bool} */
    public static function classify(array $row, string $date, string $today): array
    {
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $hasCheckIn = !empty($row['check_in_time']);
        $lateMinutes = (int)($row['late_minutes'] ?? 0);

        if ($hasCheckIn || in_array($status, ['WFH', 'HALF_DAY'], true)) {
            if ($status === 'WFH') {
                return self::result('WFH', 'ทำงานที่บ้าน', true, false, false);
            }
            if ($lateMinutes > 0 || $status === 'LATE') {
                return self::result('LATE', 'มาสาย', true, false, true);
            }
            return self::result('PRESENT', 'ปกติ', true, false, false);
        }

        if (!empty($row['approved_leave_name']) || $status === 'LEAVE') {
            return self::result('LEAVE', 'ลา', false, false, false);
        }
        if (!empty($row['is_comp_day'])) {
            return self::result('COMP_DAY', 'วันหยุดชดเชย', false, false, false);
        }
        if (!empty($row['is_holiday'])) {
            return self::result('HOLIDAY', 'วันหยุด', false, false, false);
        }
        if (!empty($row['is_scheduled_off'])) {
            return self::result('DAY_OFF', 'วันหยุดประจำสัปดาห์', false, false, false);
        }
        // Reports and payroll close attendance on the following day. Even if a
        // backfill row appears early, today's absence must not leak into Daily
        // while Monthly/Payroll still intentionally exclude today.
        if ($date >= $today) {
            return self::result('NOT_YET', $date === $today ? 'ยังไม่ลงเวลา' : 'ยังไม่ถึงวันทำงาน', false, false, false);
        }
        return self::result('ABSENT', 'ขาดงาน', false, true, false);
    }

    /** @return array{code:string,label:string,is_working:bool,is_absent:bool,is_late:bool} */
    private static function result(string $code, string $label, bool $isWorking, bool $isAbsent, bool $isLate): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'is_working' => $isWorking,
            'is_absent' => $isAbsent,
            'is_late' => $isLate,
        ];
    }
}
