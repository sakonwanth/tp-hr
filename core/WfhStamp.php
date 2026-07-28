<?php
/**
 * WFH auto-stamp helper.
 *
 * For users whose work_mode = 'WFH', ensure a hr_attendances row exists for the
 * given date with status = 'WFH', provided the day is not:
 *   - a holiday (hr_holidays)
 *   - the employee's weekly day off (hr_employee_schedules / hr_dayoff_requests)
 *   - covered by an approved leave (hr_leave_requests)
 *
 * Idempotent: if a row for (user_id, date) already exists, do nothing.
 * Safe to call frequently; gate at the caller if needed.
 */
class WfhStamp
{
    /** Auto-stamp WFH for one user on one date (default: today). */
    public static function ensureForUser(int $userId, ?string $date = null): bool
    {
        $date = $date ?: date('Y-m-d');
        $pdo = getDB();

        $u = $pdo->prepare("SELECT u.id, u.work_mode, u.is_active, u.attendance_exempt, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
        $u->execute([$userId]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_active'] !== 1 || $user['work_mode'] !== 'WFH' || tp_hr_is_attendance_exempt($user)) return false;

        return self::stamp($pdo, $userId, $date);
    }

    /**
     * Auto-stamp for ALL active WFH users on $date.
     * Intended for cron (daily, after midnight) or admin-triggered.
     */
    public static function ensureAllForDate(?string $date = null): int
    {
        $date = $date ?: date('Y-m-d');
        $pdo = getDB();
        $rows = $pdo->query("
            SELECT u.id FROM users u
            WHERE u.is_active = 1 AND u.work_mode = 'WFH'
              AND " . tp_hr_attendance_scope_filter_sql('u') . "
        ")->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($rows as $uid) {
            if (self::stamp($pdo, (int)$uid, $date)) $count++;
        }
        return $count;
    }

    private static function stamp(PDO $pdo, int $userId, string $date): bool
    {
        // Already stamped?
        $exists = $pdo->prepare("SELECT id FROM hr_attendances WHERE user_id = ? AND attendance_date = ? LIMIT 1");
        $exists->execute([$userId, $date]);
        if ($exists->fetch()) return false;

        // Holiday?
        $h = $pdo->prepare("SELECT 1 FROM hr_holidays WHERE date = ? AND is_active = 1 LIMIT 1");
        $h->execute([$date]);
        if ($h->fetchColumn()) return false;

        // Day off (schedule / approved change request)?
        $weekday = (int)date('w', strtotime($date)); // 0=Sun..6=Sat
        $d = $pdo->prepare("
            SELECT COALESCE(dor.requested_day_off, s.day_off) AS eff_dayoff
            FROM users u
            LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
            LEFT JOIN hr_dayoff_requests dor
              ON dor.user_id = u.id AND dor.status = 'APPROVED'
             AND ? BETWEEN dor.week_start AND dor.week_end
            WHERE u.id = ? LIMIT 1
        ");
        $d->execute([$date, $userId]);
        $eff = $d->fetch(PDO::FETCH_ASSOC);
        if ($eff && $eff['eff_dayoff'] !== null && (int)$eff['eff_dayoff'] === $weekday) return false;

        // On approved leave?
        $l = $pdo->prepare("
            SELECT 1 FROM hr_leave_requests
            WHERE user_id = ? AND status = 'APPROVED'
              AND ? BETWEEN start_date AND end_date LIMIT 1
        ");
        $l->execute([$userId, $date]);
        if ($l->fetchColumn()) return false;

        // Stamp WFH — idempotent via uk_user_date unique index (guards against race)
        $pdo->prepare("
            INSERT IGNORE INTO hr_attendances
                (user_id, attendance_date, check_in_time, check_in_type,
                 check_out_time, check_out_type, work_minutes, status, remarks)
            VALUES (?, ?, NULL, 'MANUAL', NULL, NULL, 0, 'WFH', 'Auto-stamped (Work From Home)')
        ")->execute([$userId, $date]);
        return true;
    }
}
