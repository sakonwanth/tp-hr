<?php
/**
 * One-off backfill: mark ABSENT for past working days where no hr_attendances exists
 * Rules:
 *   - Skip today (cron handles today forward)
 *   - Skip weekends matching user's day_off (hr_employee_schedules.day_off; 0=Sun..6=Sat)
 *   - Skip holidays in hr_holidays (is_active=1)
 *   - Skip approved/pending leave (hr_leave_requests status NOT IN REJECTED/CANCELLED)
 *   - Skip approved dayoff_requests (hr_dayoff_requests status=APPROVED)
 *   - Skip WFH users on days they auto-stamp (handled by stamp_wfh cron, already ran)
 *   - Skip users not active
 *   - Skip SYSTEM_USER_IDS (1,12)
 *   - ONLY inserts; never overwrites existing rows (uses INSERT IGNORE via uk_user_date)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/WfhStamp.php';
$pdo = getDB();

$start = $argv[1] ?? '2026-04-01';
$end   = $argv[2] ?? date('Y-m-d', strtotime('-1 day'));

echo "[backfill_absences] range={$start}..{$end}\n";

// active non-system users with schedule
$users = $pdo->query("
    SELECT u.id, u.employee_code, u.first_name_th, u.last_name_th, u.work_mode,
           COALESCE(s.day_off, 0) AS day_off
    FROM users u
    LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
    WHERE u.is_active = 1 AND u.id NOT IN (1, 12)
      AND (u.employee_code IS NULL OR u.employee_code NOT LIKE 'CR%')
")->fetchAll(PDO::FETCH_ASSOC);

// pre-load holidays
$holi = [];
$hq = $pdo->prepare("SELECT date FROM hr_holidays WHERE is_active=1 AND date BETWEEN ? AND ?");
$hq->execute([$start, $end]);
foreach ($hq->fetchAll(PDO::FETCH_COLUMN) as $d) $holi[$d] = true;

$chkLeave = $pdo->prepare("
    SELECT 1 FROM hr_leave_requests
    WHERE user_id=? AND status NOT IN ('REJECTED','CANCELLED')
      AND start_date <= ? AND end_date >= ?
    LIMIT 1
");
$chkDayoff = $pdo->prepare("
    SELECT requested_day_off FROM hr_dayoff_requests
    WHERE user_id=? AND status='APPROVED' AND ? BETWEEN week_start AND week_end
    LIMIT 1
");
$chkExists = $pdo->prepare("SELECT 1 FROM hr_attendances WHERE user_id=? AND attendance_date=? LIMIT 1");
$ins = $pdo->prepare("
    INSERT INTO hr_attendances (user_id, attendance_date, status, adjustment_reason, adjusted_at)
    VALUES (?, ?, 'ABSENT', ?, NOW())
");

$marked = 0; $skipped = 0;
$audit = '[backfill ' . date('Y-m-d H:i') . '] marked absent (post-unification backfill)';

for ($ts = strtotime($start); $ts <= strtotime($end); $ts += 86400) {
    $date = date('Y-m-d', $ts);
    $dow = (int)date('w', $ts); // 0=Sun..6=Sat

    if (!empty($holi[$date])) continue;

    foreach ($users as $u) {
        // skip existing
        $chkExists->execute([$u['id'], $date]);
        if ($chkExists->fetchColumn()) { $skipped++; continue; }

        // effective day off: dayoff-request override, else schedule default
        $chkDayoff->execute([$u['id'], $date]);
        $reqDay = $chkDayoff->fetchColumn();
        $effDay = ($reqDay !== false) ? (int)$reqDay : (int)$u['day_off'];
        if ($dow === $effDay) continue;

        // approved/pending leave
        $chkLeave->execute([$u['id'], $date, $date]);
        if ($chkLeave->fetchColumn()) { $skipped++; continue; }

        // WFH users: auto-stamp WFH for past missed days (instead of ABSENT)
        if ($u['work_mode'] === 'WFH') {
            if (WfhStamp::ensureForUser((int)$u['id'], $date)) {
                echo "  {$date}  {$u['employee_code']}  {$u['first_name_th']} {$u['last_name_th']}  → WFH\n";
                $marked++;
            } else {
                $skipped++;
            }
            continue;
        }

        $ins->execute([$u['id'], $date, $audit]);
        echo "  {$date}  {$u['employee_code']}  {$u['first_name_th']} {$u['last_name_th']}  → ABSENT\n";
        $marked++;
    }
}

echo "[backfill_absences] done. marked={$marked}, skipped={$skipped}\n";
