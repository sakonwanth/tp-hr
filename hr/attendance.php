<?php
/**
 * HR Attendance Management
 * จัดการการเข้างาน - สำหรับ HR
 */

$page_title = 'จัดการเวลาทำงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!hr_can_manage_attendance()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Filters
$date = $_GET['date'] ?? date('Y-m-d');
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = DEFAULT_PER_PAGE;
$offset = ($page - 1) * $limit;

// Get departments
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND " . tp_hr_non_system_user_condition_sql('') . " ORDER BY department");
$departments = $stmtDepts->fetchAll(PDO::FETCH_COLUMN);

// Check if selected date is a public/company holiday (used for status derivation below)
$stmtHoliday = $pdo->prepare("SELECT name, type FROM hr_holidays WHERE date = ? AND is_active = 1");
$stmtHoliday->execute([$date]);
$holidayInfo = $stmtHoliday->fetch();

$weekday = (int)date('w', strtotime($date));

// Get all employees with attendance + effective day-off + approved leave for the selected date
$sql = "
    SELECT u.id, u.first_name_th, u.last_name_th, u.employee_code, u.department,
           a.check_in_time, a.check_out_time, a.status, a.late_minutes, 
           a.early_leave_minutes, a.ot_minutes, a.work_minutes, a.id as attendance_id,
           a.check_in_photo, a.check_in_latitude, a.check_in_longitude,
           COALESCE(dor.requested_day_off, s.day_off) AS effective_day_off,
           lr_info.leave_name AS approved_leave_name
    FROM users u
    LEFT JOIN hr_attendances a ON u.id = a.user_id AND a.attendance_date = ?
    LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
    LEFT JOIN hr_dayoff_requests dor ON dor.user_id = u.id
         AND dor.status = 'APPROVED'
         AND ? BETWEEN dor.week_start AND dor.week_end
    LEFT JOIN (
        SELECT lr.user_id, lt.name AS leave_name
        FROM hr_leave_requests lr
        JOIN hr_leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.status = 'APPROVED' AND ? BETWEEN lr.start_date AND lr.end_date
    ) lr_info ON lr_info.user_id = u.id
    WHERE u.is_active = 1 AND " . tp_hr_non_system_user_condition_sql('u') . "
";
$params = [$date, $date, $date];

if ($department) {
    $sql .= " AND u.department = ?";
    $params[] = $department;
}

if ($status === 'PRESENT') {
    $sql .= " AND a.id IS NOT NULL";
} elseif ($status === 'ABSENT') {
    $sql .= " AND a.id IS NULL";
} elseif ($status === 'LATE') {
    $sql .= " AND a.late_minutes > 0";
}

// Count
$countSql = "SELECT COUNT(*) FROM (" . str_replace("u.id, u.first_name_th, u.last_name_th, u.employee_code, u.department,\n           a.check_in_time, a.check_out_time, a.status, a.late_minutes, \n           a.early_leave_minutes, a.ot_minutes, a.work_minutes, a.id as attendance_id,\n           a.check_in_photo, a.check_in_latitude, a.check_in_longitude", "1", $sql) . ") t";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$sql .= " ORDER BY a.check_in_time DESC, u.first_name_th ASC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Daily stats
$stmtStats = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE is_active = 1 AND " . tp_hr_non_system_user_condition_sql('') . ") as total_employees,
        COUNT(a.id) as checked_in,
        SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.check_out_time IS NOT NULL THEN 1 ELSE 0 END) as checked_out,
        SEC_TO_TIME(AVG(TIME_TO_SEC(a.check_in_time))) as avg_check_in
    FROM hr_attendances a
    WHERE a.attendance_date = ?
");
$stmtStats->execute([$date]);
$stats = $stmtStats->fetch();

// Count employees who are "excused" for this date (holiday, their day-off, or on approved leave)
// so that absentCount excludes them.
$stmtExcused = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id) AS excused
    FROM users u
    LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
    LEFT JOIN hr_dayoff_requests dor ON dor.user_id = u.id
         AND dor.status = 'APPROVED'
         AND ? BETWEEN dor.week_start AND dor.week_end
    LEFT JOIN hr_attendances a ON u.id = a.user_id AND a.attendance_date = ?
    LEFT JOIN hr_leave_requests lr ON lr.user_id = u.id
         AND lr.status = 'APPROVED' AND ? BETWEEN lr.start_date AND lr.end_date
    WHERE u.is_active = 1 AND " . tp_hr_non_system_user_condition_sql('u') . "
      AND a.id IS NULL
      AND (
          ? = 1 /* isHoliday flag */
          OR COALESCE(dor.requested_day_off, s.day_off) = ?
          OR lr.id IS NOT NULL
      )
");
$isHoliday = $holidayInfo ? 1 : 0;
$stmtExcused->execute([$date, $date, $date, $isHoliday, $weekday]);
$excusedCount = (int)$stmtExcused->fetchColumn();

$absentCount = max(0, $stats['total_employees'] - $stats['checked_in'] - $excusedCount);

// "Weekly day off" banner: show only if ALL active employees have this weekday as their day_off
$stmtDayOff = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id) AS total,
           SUM(CASE WHEN COALESCE(s.day_off, 0) = ? THEN 1 ELSE 0 END) AS matches
    FROM users u
    LEFT JOIN hr_employee_schedules s ON s.user_id = u.id
    WHERE u.is_active = 1 AND " . tp_hr_non_system_user_condition_sql('u') . "
");
$stmtDayOff->execute([$weekday]);
$dayOffStats = $stmtDayOff->fetch();
$isWeekend = ($dayOffStats['total'] > 0 && (int)$dayOffStats['total'] === (int)$dayOffStats['matches']);

$filterBase = ['date' => $date];
if ($department !== '') {
    $filterBase['department'] = $department;
}
$highlightUserId = (int)($_GET['user_id'] ?? 0);
$autoOpenFix = isset($_GET['fix']) && $highlightUserId > 0;
$attendanceReturnUrl = hr_safe_internal_return_url($_GET['return'] ?? null);

$current_page = 'hr-attendance';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="tp-tap-48 hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <?php if ($attendanceReturnUrl): ?>
        <a href="<?php echo htmlspecialchars($attendanceReturnUrl); ?>" class="tp-tap-48 hover:text-white touch-manipulation">กลับหน้าก่อน</a>
        <span class="mx-2">/</span>
        <?php endif; ?>
        <span class="text-white">จัดการเวลาทำงาน</span>
    </nav>
    <div class="min-w-0 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h1 class="tp-ios-page-title">จัดการเวลาทำงาน</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">สรุปการเข้างานตามวันที่ กรองแผนกและสถานะ · HR สามารถแก้ไข/ลบเวลาเข้า-ออกได้ (บันทึก audit log ทุกครั้ง)</p>
        </div>
        <?php if ($attendanceReturnUrl): ?>
        <a href="<?php echo htmlspecialchars($attendanceReturnUrl); ?>"
           class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] font-medium touch-manipulation shrink-0">
            <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i>กลับหน้าสรุป
        </a>
        <?php endif; ?>
    </div>
</header>

<?php if (hr_can_manage_attendance()): ?>
<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-5 mb-6 border border-sky-500/20 bg-sky-500/[0.06] text-sm text-sky-100/90">
    <i class="fas fa-shield-halved text-sky-300 mr-2" aria-hidden="true"></i>
    <strong class="text-sky-100">ลบ/ล้างเวลาเข้า-ออก</strong> — ใช้เมื่อลงเวลาผิดและต้องให้พนักงานลงใหม่
    · กด <strong class="text-sky-50">ลบเวลา</strong> ที่แถวพนักงาน (แสดงเมื่อมีการลงเวลาแล้ว)
    · เลือกได้ว่าจะลบทั้งวัน ลบเฉพาะเวลาเข้า หรือเวลาออก
    · ทุกการดำเนินการบันทึกใน <strong class="text-sky-50">ประวัติการแก้ไข</strong> (ผู้ทำ, เวลา, เหตุผล)
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-2 sm:gap-4 mb-6 min-w-0 max-w-full">
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => '']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo !$status ? 'ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">พนักงานทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)$stats['total_employees']; ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'PRESENT']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'PRESENT' ? 'ring-2 ring-emerald-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">เข้างาน</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($stats['checked_in'] ?? 0); ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'ABSENT']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'ABSENT' ? 'ring-2 ring-red-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">ขาดงาน</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)$absentCount; ?></p>
    </a>
    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['status' => 'LATE']))); ?>"
       class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden touch-manipulation transition-shadow <?php echo $status === 'LATE' ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>">
        <p class="text-slate-300 text-sm truncate">สาย</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($stats['late_count'] ?? 0); ?></p>
    </a>
    <div class="stat-card tp-native-summary-card rounded-[var(--tp-ios-card-radius)] p-5 min-w-0 overflow-hidden md:col-span-1 col-span-2">
        <p class="text-slate-300 text-sm truncate">เวลาเข้างานเฉลี่ย</p>
        <p class="text-2xl font-bold text-sky-300 tabular-nums mt-1"><?php echo $stats['avg_check_in'] ? substr($stats['avg_check_in'], 0, 5) : '--:--'; ?></p>
    </div>
</div>

<!-- Filters -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 overflow-hidden">
    <h2 class="section-title mb-4 text-white text-lg">
        <i class="fas fa-filter text-violet-400 text-xl mr-2" aria-hidden="true"></i>
        กรองและนำทางวันที่
    </h2>
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4">
        <?php if ($status !== ''): ?>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <?php endif; ?>
        <div class="tp-native-form-group mb-0 sm:col-span-1 lg:col-span-3">
            <label for="hr-att-filter-date" class="text-white/70 text-sm font-medium">วันที่</label>
            <input type="date" id="hr-att-filter-date" name="date" value="<?php echo htmlspecialchars($date); ?>" class="input-field tp-native-input w-full" onchange="this.form.submit()">
        </div>
        <div class="tp-native-form-group mb-0 sm:col-span-1 lg:col-span-3">
            <label for="hr-att-filter-dept" class="text-white/70 text-sm font-medium">แผนก</label>
            <select id="hr-att-filter-dept" name="department" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-wrap items-end gap-2 lg:col-span-4">
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['date' => date('Y-m-d')]))); ?>" class="flex-1 min-h-[56px] min-w-[7rem] py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation inline-flex items-center justify-center font-medium">วันนี้</a>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['date' => date('Y-m-d', strtotime('-1 day', strtotime($date)))]))); ?>" class="min-h-[56px] min-w-[48px] px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] inline-flex items-center justify-center touch-manipulation" aria-label="วันก่อนหน้า">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($filterBase, ['date' => date('Y-m-d', strtotime('+1 day', strtotime($date)))]))); ?>" class="min-h-[56px] min-w-[48px] px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] inline-flex items-center justify-center touch-manipulation" aria-label="วันถัดไป">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
        </div>
        <div class="flex items-end lg:col-span-2">
            <a href="attendance.php?action=report&amp;month=<?php echo htmlspecialchars(date('Y-m', strtotime($date))); ?>" class="w-full min-h-[56px] py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-center rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation inline-flex items-center justify-center font-semibold gap-2">
                <i class="fas fa-file-export" aria-hidden="true"></i>รายงาน
            </a>
        </div>
    </form>
</div>

<!-- Title -->
<div class="flex items-center justify-between mb-4 min-w-0">
    <h2 class="section-title text-lg font-semibold text-white min-w-0">
        <?php echo formatDateThai($date); ?>
        <?php
        $dayNames = THAI_DAY_NAMES;
        echo ' (' . $dayNames[date('w', strtotime($date))] . ')';
        ?>
    </h2>
</div>

<?php if ($holidayInfo || $isWeekend): ?>
<div class="rounded-[var(--tp-ios-card-radius)] p-5 mb-4 <?php echo $holidayInfo ? 'bg-orange-500/20 border border-orange-500/30' : 'bg-blue-500/20 border border-blue-500/30'; ?>">
    <div class="flex items-center gap-3">
        <i class="fas <?php echo $holidayInfo ? 'fa-calendar-check text-orange-400' : 'fa-calendar-day text-blue-400'; ?> text-xl" aria-hidden="true"></i>
        <div>
            <?php if ($holidayInfo): ?>
            <p class="text-orange-300 font-medium">
                <i class="fas fa-star text-xs mr-1" aria-hidden="true"></i>
                <?php echo htmlspecialchars($holidayInfo['name']); ?>
                <span class="text-orange-400/70 text-sm ml-2">
                    (<?php echo match($holidayInfo['type']) {
                        'PUBLIC' => 'วันหยุดราชการ',
                        'COMPANY' => 'วันหยุดบริษัท',
                        'SPECIAL' => 'วันหยุดพิเศษ',
                        'SUBSTITUTE' => 'วันหยุดชดเชย',
                        default => 'วันหยุด'
                    }; ?>)
                </span>
            </p>
            <?php endif; ?>
            <?php if ($isWeekend && !$holidayInfo): ?>
            <p class="text-blue-300 font-medium">
                <i class="fas fa-umbrella-beach text-xs mr-1"></i>
                วันหยุดประจำสัปดาห์ (<?php echo $dayNames[date('w', strtotime($date))]; ?>)
            </p>
            <?php elseif ($isWeekend && $holidayInfo): ?>
            <p class="text-orange-400/60 text-sm mt-1">วันหยุดประจำสัปดาห์ (<?php echo $dayNames[date('w', strtotime($date))]; ?>)</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- List -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($records)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-users text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ไม่พบข้อมูล</p>
    </div>
    <?php else: ?>
    <!-- Mobile-first: card list below md (table from md up — Wave B alignment) -->
    <div class="md:hidden p-5 space-y-4">
        <?php foreach ($records as $rec): ?>
        <?php
        $hasAttendance = !empty($rec['attendance_id']);
        $isLate = ($rec['late_minutes'] ?? 0) > 0;
        $isEarlyLeave = ($rec['early_leave_minutes'] ?? 0) > 0;
        $isUserDayOff = !$hasAttendance
            && $rec['effective_day_off'] !== null
            && (int)$rec['effective_day_off'] === $weekday;
        $onLeave = !$hasAttendance && !empty($rec['approved_leave_name']);

        $statusLabel = 'ปกติ';
        $statusCls = 'bg-green-500/15 border border-green-500/30 text-green-300';
        if (!$hasAttendance && $holidayInfo) {
            $statusLabel = 'วันหยุด';
            $statusCls = 'bg-orange-500/15 border border-orange-500/30 text-orange-200';
        } elseif (!$hasAttendance && $onLeave) {
            $statusLabel = 'ลา';
            $statusCls = 'bg-blue-500/15 border border-blue-500/30 text-blue-200';
        } elseif (!$hasAttendance && $isUserDayOff) {
            $statusLabel = 'วันหยุดประจำสัปดาห์';
            $statusCls = 'bg-sky-500/15 border border-sky-500/30 text-sky-200';
        } elseif (!$hasAttendance) {
            $statusLabel = 'ขาดงาน';
            $statusCls = 'bg-red-500/15 border border-red-500/30 text-red-200';
        } elseif ($isLate) {
            $statusLabel = 'มาสาย';
            $statusCls = 'bg-yellow-500/15 border border-yellow-500/30 text-yellow-200';
        }

        $notes = [];
        if ($onLeave) $notes[] = htmlspecialchars($rec['approved_leave_name']);
        if ($isLate) $notes[] = 'สาย ' . (int)$rec['late_minutes'] . ' นาที';
        if ($isEarlyLeave) $notes[] = 'ออกก่อน ' . (int)$rec['early_leave_minutes'] . ' นาที';
        $noteText = implode(' • ', $notes);

        $fullName = trim((string)($rec['first_name_th'] ?? '') . ' ' . (string)($rec['last_name_th'] ?? ''));
        $empCode = (string)($rec['employee_code'] ?? '');
        $dept = (string)($rec['department'] ?? '-');
        $checkInHHMM = $rec['check_in_time'] ? date('H:i', strtotime($rec['check_in_time'])) : '--:--';
        $checkOutHHMM = $rec['check_out_time'] ? date('H:i', strtotime($rec['check_out_time'])) : '--:--';
        $workHours = $rec['work_minutes'] ? number_format(((float)$rec['work_minutes']) / 60, 1) : '-';
        $otHours = ($rec['ot_minutes'] ?? 0) > 0 ? (int)floor(((int)$rec['ot_minutes']) / 60) : 0;
        ?>
        <div class="tp-ios-attendance-panel p-5<?php echo $highlightUserId === (int)$rec['id'] ? ' ring-2 ring-violet-400 ring-offset-2 ring-offset-slate-900/80' : ''; ?>"
             id="att-row-<?php echo (int)$rec['id']; ?>">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="/hr/employee_attendance.php?id=<?php echo (int)$rec['id']; ?>"
                       class="text-white font-semibold leading-tight hover:text-violet-300 transition-colors block truncate break-words">
                        <?php echo htmlspecialchars($fullName); ?>
                    </a>
                    <div class="text-white/50 text-xs mt-0.5 truncate">
                        <?php echo htmlspecialchars($empCode); ?> · <?php echo htmlspecialchars($dept); ?>
                    </div>
                </div>
                <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-semibold <?php echo $statusCls; ?>">
                    <?php echo htmlspecialchars($statusLabel); ?>
                </span>
            </div>

            <div class="grid grid-cols-3 gap-2 mt-4">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <div class="text-[11px] text-white/50">เข้า</div>
                    <div class="text-white font-semibold"><?php echo htmlspecialchars($checkInHHMM); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <div class="text-[11px] text-white/50">ออก</div>
                    <div class="text-white font-semibold"><?php echo htmlspecialchars($checkOutHHMM); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-3 py-2">
                    <div class="text-[11px] text-white/50">ชั่วโมง</div>
                    <div class="text-white font-semibold">
                        <?php echo htmlspecialchars($workHours); ?>
                        <?php if ($otHours > 0): ?>
                        <span class="text-emerald-300 text-xs font-bold ml-1">+<?php echo $otHours; ?>h</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($noteText !== ''): ?>
            <div class="mt-3 text-white/70 text-xs">
                <i class="fas fa-note-sticky text-white/40 mr-1"></i><?php echo $noteText; ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-2 mt-4">
                <button type="button"
                        onclick="editAttendance(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($date, ENT_QUOTES); ?>', <?php echo $rec['attendance_id'] ?? 'null'; ?>, '<?php echo $rec['check_in_time'] ? date('H:i', strtotime($rec['check_in_time'])) : ''; ?>', '<?php echo $rec['check_out_time'] ? date('H:i', strtotime($rec['check_out_time'])) : ''; ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>')"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไขเวลา
                </button>
                <button type="button"
                        onclick="viewHistory(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($date, ENT_QUOTES); ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>')"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-history mr-2" aria-hidden="true"></i>ประวัติ
                </button>
                <?php if ($hasAttendance && $rec['check_in_latitude']): ?>
                <button type="button"
                        onclick="viewLocation(<?php echo $rec['check_in_latitude']; ?>, <?php echo $rec['check_in_longitude']; ?>)"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-map-marker-alt mr-2" aria-hidden="true"></i>ตำแหน่ง
                </button>
                <?php endif; ?>
                <?php if ($hasAttendance): ?>
                <button type="button"
                        onclick="deleteAttendance(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars($date, ENT_QUOTES); ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>', 'full')"
                        class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-200 text-sm font-semibold touch-manipulation whitespace-nowrap">
                    <i class="fas fa-trash mr-2" aria-hidden="true"></i>ลบเวลา
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full" style="min-width:880px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เข้างาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ออกงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ชั่วโมงทำงาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">หมายเหตุ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($records as $rec): ?>
                <?php
                $hasAttendance = !empty($rec['attendance_id']);
                $isLate = ($rec['late_minutes'] ?? 0) > 0;
                $isEarlyLeave = ($rec['early_leave_minutes'] ?? 0) > 0;
                $isUserDayOff = !$hasAttendance
                    && $rec['effective_day_off'] !== null
                    && (int)$rec['effective_day_off'] === $weekday;
                $onLeave = !$hasAttendance && !empty($rec['approved_leave_name']);
                ?>
                <tr class="hover:bg-white/[0.04]<?php echo $highlightUserId === (int)$rec['id'] ? ' bg-violet-500/10 ring-1 ring-inset ring-violet-400/50' : ''; ?>"
                    id="att-row-<?php echo (int)$rec['id']; ?>">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($rec['check_in_photo']): ?>
                            <img src="<?php echo htmlspecialchars(attendancePhotoPublicUrl($rec['check_in_photo'])); ?>" alt="" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white/50">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <a href="/hr/employee_attendance.php?id=<?php echo $rec['id']; ?>" class="text-white font-medium hover:text-violet-400 transition-colors"><?php echo htmlspecialchars($rec['first_name_th'] . ' ' . $rec['last_name_th']); ?></a>
                                <p class="text-white/50 text-xs"><?php echo htmlspecialchars($rec['employee_code'] ?? ''); ?> | <?php echo htmlspecialchars($rec['department'] ?? '-'); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($rec['check_in_time']): ?>
                        <span class="text-white font-medium"><?php echo date('H:i', strtotime($rec['check_in_time'])); ?></span>
                        <?php else: ?>
                        <span class="text-white/40">--:--</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($rec['check_out_time']): ?>
                        <span class="text-white font-medium"><?php echo date('H:i', strtotime($rec['check_out_time'])); ?></span>
                        <?php else: ?>
                        <span class="text-white/40">--:--</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($rec['work_minutes']): ?>
                        <span class="text-white"><?php echo number_format($rec['work_minutes']/60, 1); ?> ชม.</span>
                        <?php if ((int)($rec['ot_minutes'] ?? 0) > 0): ?>
                        <span class="text-emerald-400 text-xs ml-1">(+<?php echo (int)floor((int)$rec['ot_minutes'] / 60); ?>h)</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-white/40">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (!$hasAttendance && $holidayInfo): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs border border-orange-500/25 bg-orange-500/15 text-orange-300">วันหยุด</span>
                        <?php elseif (!$hasAttendance && $onLeave): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs border border-blue-500/25 bg-blue-500/15 text-blue-300">ลา</span>
                        <?php elseif (!$hasAttendance && $isUserDayOff): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs border border-sky-500/25 bg-sky-500/15 text-sky-300">วันหยุดประจำสัปดาห์</span>
                        <?php elseif (!$hasAttendance): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs border border-red-500/25 bg-red-500/15 text-red-300">ขาดงาน</span>
                        <?php elseif ($isLate): ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs border border-amber-500/25 bg-amber-500/15 text-amber-300">มาสาย</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs border border-emerald-500/25 bg-emerald-500/15 text-emerald-300">ปกติ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white/60 text-sm">
                        <?php
                        $notes = [];
                        if ($onLeave) $notes[] = htmlspecialchars($rec['approved_leave_name']);
                        if ($isLate) $notes[] = 'สาย ' . (int)$rec['late_minutes'] . ' นาที';
                        if ($isEarlyLeave) $notes[] = 'ออกก่อน ' . (int)$rec['early_leave_minutes'] . ' นาที';
                        echo implode(', ', $notes) ?: '-';
                        ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($hasAttendance && $rec['check_in_latitude']): ?>
                        <button onclick="viewLocation(<?php echo $rec['check_in_latitude']; ?>, <?php echo $rec['check_in_longitude']; ?>)" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors mr-1 min-h-[48px] min-w-[48px] inline-flex items-center justify-center" title="ดูตำแหน่ง">
                            <i class="fas fa-map-marker-alt"></i>
                        </button>
                        <?php endif; ?>
                        <button onclick="editAttendance(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', <?php echo $rec['attendance_id'] ?? 'null'; ?>, '<?php echo $rec['check_in_time'] ? date('H:i', strtotime($rec['check_in_time'])) : ''; ?>', '<?php echo $rec['check_out_time'] ? date('H:i', strtotime($rec['check_out_time'])) : ''; ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>')" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors min-h-[48px] min-w-[48px] inline-flex items-center justify-center" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ($hasAttendance): ?>
                        <button onclick="deleteAttendance(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>', 'full')" 
                                class="min-h-[48px] inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-500/20 hover:bg-red-500/30 text-red-200 text-xs rounded transition-colors ml-1 font-medium whitespace-nowrap" title="ลบ/ล้างเวลาเข้า-ออก">
                            <i class="fas fa-trash" aria-hidden="true"></i><span>ลบเวลา</span>
                        </button>
                        <?php endif; ?>
                        <button onclick="viewHistory(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>')" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors min-h-[48px] min-w-[48px] inline-flex items-center justify-center ml-1" title="ประวัติการแก้ไข">
                            <i class="fas fa-history"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <p class="text-white/60 text-sm">
            หน้า <?php echo $page; ?> จาก <?php echo $totalPages; ?>
        </p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center px-3 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation" aria-label="หน้าก่อนหน้า">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center px-3 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation" aria-label="หน้าถัดไป">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<!-- Edit Modal -->
<div id="edit-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)] rounded-[var(--tp-ios-card-radius)]">
        <form id="edit-form" class="p-6">
            <h3 id="edit-modal-title" class="text-xl font-bold text-white mb-4">แก้ไขเวลาทำงาน</h3>
            <input type="hidden" name="user_id" id="edit-user-id">
            <input type="hidden" name="attendance_date" id="edit-date">
            <input type="hidden" name="attendance_id" id="edit-attendance-id">
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="edit-check-in" class="block text-white/80 text-sm mb-2">เวลาเข้างาน</label>
                    <select id="edit-check-in-select" data-ios-time-select-for="edit-check-in"
                            class="hidden w-full input-field tp-native-select"></select>
                    <input type="time" name="check_in_time" id="edit-check-in" class="input-field tp-native-input">
                </div>
                <div>
                    <label for="edit-check-out" class="block text-white/80 text-sm mb-2">เวลาออกงาน</label>
                    <select id="edit-check-out-select" data-ios-time-select-for="edit-check-out"
                            class="hidden w-full input-field tp-native-select"></select>
                    <input type="time" name="check_out_time" id="edit-check-out" class="input-field tp-native-input">
                </div>
            </div>
            
            <div class="mb-4">
                <label for="edit-note" class="block text-white/80 text-sm mb-2">เหตุผลการแก้ไข <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="note" id="edit-note" rows="2" class="input-field tp-native-textarea" placeholder="ระบุเหตุผลการแก้ไข (จำเป็น)" required></textarea>
                <p class="text-white/50 text-xs mt-1">การแก้ไขทั้งหมดจะถูกบันทึกใน audit log</p>
                <p id="edit-open-delete-hint" class="text-white/50 text-xs mt-3 hidden">ลงเวลาผิด?</p>
                <button type="button" id="edit-open-delete-btn" class="mt-1 min-h-[48px] inline-flex items-center px-2 text-red-300/90 hover:text-red-200 text-xs font-medium touch-manipulation whitespace-nowrap hidden">
                    <i class="fas fa-trash mr-1" aria-hidden="true"></i>ลบ/ล้างเวลาเพื่อลงใหม่
                </button>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-4">
                <button type="button" onclick="closeEditModal()" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation whitespace-nowrap">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Location Modal -->
<div id="location-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="location-modal-title">
    <div class="native-card tp-native-card w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)] rounded-[var(--tp-ios-card-radius)]">
        <div class="p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 id="location-modal-title" class="text-lg font-bold text-white">ตำแหน่ง Check-in</h3>
                <button type="button" onclick="closeLocationModal()" class="p-2 min-h-[48px] min-w-[48px] text-white/60 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)]" aria-label="ปิด">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div id="map" class="w-full h-80 rounded-[var(--tp-ios-card-radius)] bg-white/10"></div>
            <div class="mt-2 text-center">
                <a id="map-link" href="#" target="_blank" class="text-blue-400 hover:text-blue-300 text-sm">
                    <i class="fas fa-external-link-alt mr-1"></i>เปิดใน Google Maps
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)] rounded-[var(--tp-ios-card-radius)]">
        <form id="delete-form" class="p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-full bg-red-500/20 flex items-center justify-center text-red-400">
                    <i class="fas fa-triangle-exclamation text-xl" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 id="delete-modal-title" class="text-xl font-bold text-white">ลบ / ล้างเวลาเข้า-ออก</h3>
                    <p id="delete-subtitle" class="text-white/60 text-sm"></p>
                </div>
            </div>
            <fieldset class="mb-4 space-y-2 border-0 p-0">
                <legend class="text-white/80 text-sm font-medium mb-2">เลือกสิ่งที่ต้องการลบ</legend>
                <label class="flex items-start gap-3 p-3 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 cursor-pointer touch-manipulation">
                    <input type="radio" name="delete_scope" value="full" class="mt-1" checked>
                    <span class="text-sm"><strong class="text-white">ลบทั้งวัน</strong> <span class="text-white/55 block mt-0.5">ลบเวลา รูป พิกัด และชั่วโมงทำงานทั้งหมด — เหมาะเมื่อต้องการลงเวลาใหม่ทั้งเข้าและออก</span></span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 cursor-pointer touch-manipulation">
                    <input type="radio" name="delete_scope" value="check_in" class="mt-1">
                    <span class="text-sm"><strong class="text-white">ลบเฉพาะเวลาเข้า</strong> <span class="text-white/55 block mt-0.5">ลบเวลาเข้า+รูป/พิกัดฝั่งเข้า — พนักงานลงเวลาเข้าใหม่ได้</span></span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 cursor-pointer touch-manipulation">
                    <input type="radio" name="delete_scope" value="check_out" class="mt-1">
                    <span class="text-sm"><strong class="text-white">ลบเฉพาะเวลาออก</strong> <span class="text-white/55 block mt-0.5">ลบเวลาออก+รูป/พิกัดฝั่งออก — พนักงานลงเวลาออกใหม่ได้</span></span>
                </label>
            </fieldset>
            <div class="bg-amber-500/10 border border-amber-500/25 rounded-[var(--tp-ios-card-radius)] p-3 mb-4 text-amber-100/90 text-xs">
                <i class="fas fa-clipboard-list mr-1" aria-hidden="true"></i>
                บันทึก audit log: ผู้ดำเนินการ, IP, เวลา, เหตุผล, ข้อมูลก่อน/หลัง — ดูได้ที่ปุ่ม 「ประวัติ」
            </div>
            <input type="hidden" name="user_id" id="delete-user-id">
            <input type="hidden" name="attendance_date" id="delete-date">
            <div class="mb-4">
                <label for="delete-note" class="block text-white/80 text-sm mb-2">เหตุผล <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="note" id="delete-note" rows="3" class="input-field tp-native-textarea" placeholder="เช่น ลงเวลาเข้าผิด อนุมัติให้ลบและลงใหม่" required></textarea>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" onclick="closeDeleteModal()" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation whitespace-nowrap">ยกเลิก</button>
                <button type="submit" class="flex-1 min-h-[56px] py-2 bg-red-600 hover:bg-red-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap"><i class="fas fa-trash mr-1" aria-hidden="true"></i> ยืนยัน</button>
            </div>
        </form>
    </div>
</div>

<!-- History Modal -->
<div id="history-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="history-modal-title">
    <div class="native-card tp-native-card w-full max-w-3xl my-auto max-h-[calc(100dvh-2rem)] flex flex-col overflow-hidden rounded-[var(--tp-ios-card-radius)]">
        <div class="p-6 border-b border-white/10 flex items-center justify-between">
            <div>
                <h3 id="history-modal-title" class="text-xl font-bold text-white">ประวัติการแก้ไขเวลาทำงาน</h3>
                <p id="history-subtitle" class="text-white/60 text-sm mt-1"></p>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-info-circle"></i> ประวัติทั้งหมดของพนักงานคนนี้ในวันนี้ เรียงจากล่าสุด — ชื่อที่แสดงคือผู้ดำเนินการแก้ไข</p>
            </div>
            <button type="button" onclick="closeHistoryModal()" class="p-2 min-h-[48px] min-w-[48px] text-white/60 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)]" aria-label="ปิด">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div id="history-body" class="p-6 overflow-y-auto flex-1">
            <div class="tp-native-loading-state py-8 text-white/60" role="status" aria-live="polite" aria-busy="true">
                <i class="fas fa-spinner fa-spin text-2xl" aria-hidden="true"></i>
                <span class="tp-visually-hidden">กำลังโหลด</span>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
const attendanceReturnUrl = <?php echo $attendanceReturnUrl ? json_encode($attendanceReturnUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;
const attendanceAutoFix = <?php echo $autoOpenFix ? 'true' : 'false'; ?>;

function attendanceNavigateAfterFix() {
    if (attendanceReturnUrl) {
        window.location.href = attendanceReturnUrl;
        return true;
    }
    return false;
}

function syncEditTimeSelectFromInput(inputId) {
    const input = document.getElementById(inputId);
    const sel = document.querySelector('select[data-ios-time-select-for="' + inputId + '"]');
    if (!input || !sel || sel.classList.contains('hidden')) return;
    const v = input.value || '';
    if (v && [...sel.options].some(o => o.value === v)) sel.value = v;
}

function syncEditTimeInputFromSelect(inputId) {
    const input = document.getElementById(inputId);
    const sel = document.querySelector('select[data-ios-time-select-for="' + inputId + '"]');
    if (!input || !sel || sel.classList.contains('hidden')) return;
    input.value = sel.value || '';
}

(function initEditAttendanceTimeSync() {
    ['edit-check-in', 'edit-check-out'].forEach(id => {
        const sel = document.querySelector('select[data-ios-time-select-for="' + id + '"]');
        if (sel) sel.addEventListener('change', () => syncEditTimeInputFromSelect(id));
    });
})();

function editAttendance(userId, date, attendanceId, checkIn, checkOut, empName) {
    document.getElementById('edit-user-id').value = userId;
    document.getElementById('edit-date').value = date;
    document.getElementById('edit-attendance-id').value = attendanceId || '';
    document.getElementById('edit-check-in').value = checkIn || '';
    document.getElementById('edit-check-out').value = checkOut || '';
    document.getElementById('edit-note').value = '';
    syncEditTimeSelectFromInput('edit-check-in');
    syncEditTimeSelectFromInput('edit-check-out');
    var delLink = document.getElementById('edit-open-delete-btn');
    var delHint = document.getElementById('edit-open-delete-hint');
    if (delLink) {
        if (attendanceId) {
            delLink.classList.remove('hidden');
            if (delHint) delHint.classList.remove('hidden');
            delLink.onclick = function () {
                if (typeof uiCloseModal === 'function') uiCloseModal('edit-modal');
                else document.getElementById('edit-modal').classList.add('hidden');
                deleteAttendance(userId, date, empName || ('พนักงาน #' + userId), 'check_in');
            };
        } else {
            delLink.classList.add('hidden');
            if (delHint) delHint.classList.add('hidden');
        }
    }
    if (typeof uiOpenModal === 'function') uiOpenModal('edit-modal');
    else {
        const m = document.getElementById('edit-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function deleteAttendance(userId, date, empName, defaultScope) {
    document.getElementById('delete-user-id').value = userId;
    document.getElementById('delete-date').value = date;
    document.getElementById('delete-note').value = '';
    document.getElementById('delete-subtitle').textContent = empName + ' — ' + date;
    var scope = defaultScope || 'full';
    document.querySelectorAll('input[name="delete_scope"]').forEach(function (r) {
        r.checked = (r.value === scope);
    });
    if (typeof uiOpenModal === 'function') uiOpenModal('delete-modal');
    else {
        const m = document.getElementById('delete-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeDeleteModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('delete-modal');
    else {
        const m = document.getElementById('delete-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function closeEditModal() {
    if (attendanceReturnUrl && attendanceAutoFix) {
        attendanceNavigateAfterFix();
        return;
    }
    if (typeof uiCloseModal === 'function') uiCloseModal('edit-modal');
    else {
        const m = document.getElementById('edit-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const note = document.getElementById('edit-note').value.trim();
    if (!note) {
        showToast('กรุณาระบุเหตุผลการแก้ไข', 'error');
        return;
    }
    
    syncEditTimeInputFromSelect('edit-check-in');
    syncEditTimeInputFromSelect('edit-check-out');
    const ci = document.getElementById('edit-check-in').value;
    const co = document.getElementById('edit-check-out').value;
    if (!ci && !co) {
        showToast('กรุณาระบุเวลาเข้าหรือเวลาออกอย่างน้อยหนึ่งช่อง หากต้องการลบเวลา ให้ใช้ปุ่ม 「ลบเวลา」', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'adjust');
    formData.append('user_id', document.getElementById('edit-user-id').value);
    formData.append('attendance_date', document.getElementById('edit-date').value);
    formData.append('attendance_id', document.getElementById('edit-attendance-id').value);
    formData.append('check_in_time', ci);
    formData.append('check_out_time', co);
    formData.append('note', note);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/attendance.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกสำเร็จ', 'success');
        setTimeout(function () {
            if (!attendanceNavigateAfterFix()) location.reload();
        }, 700);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

document.getElementById('delete-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const note = document.getElementById('delete-note').value.trim();
    if (!note) {
        showToast('กรุณาระบุเหตุผล', 'error');
        return;
    }
    const scopeEl = document.querySelector('input[name="delete_scope"]:checked');
    const scope = scopeEl ? scopeEl.value : 'full';
    const formData = new FormData();
    if (scope === 'full') {
        formData.append('action', 'delete');
    } else {
        formData.append('action', 'clear_times');
        formData.append('scope', scope);
    }
    formData.append('user_id', document.getElementById('delete-user-id').value);
    formData.append('attendance_date', document.getElementById('delete-date').value);
    formData.append('note', note);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/attendance.php', { method: 'POST', body: formData });
    const result = await response.json();
    if (result.success) {
        showToast(result.message || 'ดำเนินการสำเร็จ', 'success');
        closeDeleteModal();
        setTimeout(function () {
            if (!attendanceNavigateAfterFix()) location.reload();
        }, 700);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

let map, marker;
function viewLocation(lat, lng) {
    if (typeof uiOpenModal === 'function') uiOpenModal('location-modal');
    else {
        const m = document.getElementById('location-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    document.getElementById('map-link').href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
    
    setTimeout(() => {
        if (!map) {
            map = L.map('map').setView([lat, lng], 17);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);
            marker = L.marker([lat, lng]).addTo(map);
        } else {
            map.setView([lat, lng], 17);
            marker.setLatLng([lat, lng]);
        }
        map.invalidateSize();
    }, 100);
}

function closeLocationModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('location-modal');
    else {
        const m = document.getElementById('location-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('edit-modal').addEventListener('click', e => { if (e.target === document.getElementById('edit-modal')) closeEditModal(); });
document.getElementById('location-modal').addEventListener('click', e => { if (e.target === document.getElementById('location-modal')) closeLocationModal(); });
document.getElementById('history-modal').addEventListener('click', e => { if (e.target === document.getElementById('history-modal')) closeHistoryModal(); });
document.getElementById('delete-modal').addEventListener('click', e => { if (e.target === document.getElementById('delete-modal')) closeDeleteModal(); });

function closeHistoryModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('history-modal');
    else {
        const m = document.getElementById('history-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function fmtVal(v) {
    if (v === null || v === undefined || v === '') return '<span class="text-white/40">—</span>';
    if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(v)) {
        return v.substring(11, 16);
    }
    return String(v);
}

function diffRow(label, oldV, newV) {
    const changed = String(oldV ?? '') !== String(newV ?? '');
    const cls = changed ? 'bg-yellow-500/10' : '';
    return `<tr class="${cls}">
        <td class="py-1 pr-4 text-white/60">${label}</td>
        <td class="py-1 pr-4 text-white/80">${fmtVal(oldV)}</td>
        <td class="py-1 text-white">${fmtVal(newV)}</td>
    </tr>`;
}

async function viewHistory(userId, date, empName) {
    document.getElementById('history-subtitle').textContent = empName + ' — ' + date;
    document.getElementById('history-body').innerHTML = typeof tpHrNativeLoadingHtml === 'function'
        ? tpHrNativeLoadingHtml()
        : '<div class="tp-native-loading-state py-8 text-white/60" role="status" aria-live="polite" aria-busy="true"><i class="fas fa-spinner fa-spin text-2xl" aria-hidden="true"></i><span class="tp-visually-hidden">กำลังโหลด</span></div>';
    if (typeof uiOpenModal === 'function') uiOpenModal('history-modal');
    else {
        const m = document.getElementById('history-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }

    try {
        const res = await fetch('/api/attendance.php?action=adjustment_history&user_id=' + encodeURIComponent(userId) + '&date=' + encodeURIComponent(date));
        const result = await res.json();
        if (!result.success) {
            document.getElementById('history-body').innerHTML = '<p class="text-red-400 text-center py-8">' + (result.error || 'เกิดข้อผิดพลาด') + '</p>';
            return;
        }
        const logs = result.logs || [];
        if (logs.length === 0) {
            document.getElementById('history-body').innerHTML = '<p class="text-white/60 text-center py-8">ยังไม่มีประวัติการแก้ไข</p>';
            return;
        }
        const html = logs.map(log => {
            const o = log.old_values || {};
            const n = log.new_values || {};
            const isDelete = log.action === 'ATTENDANCE_DELETE';
            const isClear = log.action === 'ATTENDANCE_CLEAR';
            // Detect legacy entry (older format before audit refactor)
            const isLegacy = !isDelete && !isClear && (!n || Object.keys(n).length === 0 ||
                             (!('check_in_time' in n) && !('status' in n)));
            const actionBadge = isDelete
                ? '<span class="inline-block px-2 py-0.5 rounded-full text-xs bg-red-500/20 text-red-300 ml-2"><i class="fas fa-trash"></i> ลบทั้งวัน</span>'
                : isClear
                ? '<span class="inline-block px-2 py-0.5 rounded-full text-xs bg-orange-500/20 text-orange-200 ml-2"><i class="fas fa-eraser"></i> ' + (n.scope_label || 'ลบเวลา') + '</span>'
                : '<span class="inline-block px-2 py-0.5 rounded-full text-xs bg-violet-500/20 text-violet-300 ml-2"><i class="fas fa-edit"></i> แก้ไข</span>';
            const header = `
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-white/10">
                    <div>
                        <div class="text-white/60 text-xs">ผู้ดำเนินการ ${actionBadge}</div>
                        <div class="text-white font-semibold">${log.by_name || '—'} <span class="text-white/50 text-xs">(${log.by_code || ''})</span></div>
                        <div class="text-white/50 text-xs mt-0.5">IP: ${log.ip_address || '—'}</div>
                    </div>
                    <div class="text-white/70 text-sm">${log.created_at}</div>
                </div>`;
            if (isLegacy) {
                const legacyNote = (o.note && o.note.trim()) ? o.note : '<span class="text-white/40">(ไม่ได้ระบุเหตุผล)</span>';
                return `
                <div class="border border-white/10 rounded-lg p-5 mb-3 opacity-80">
                    ${header}
                    <div class="text-xs text-yellow-300 mb-2"><i class="fas fa-info-circle"></i> รายการเก่า — ระบบยังไม่ได้เก็บรายละเอียดก่อน/หลังในช่วงนั้น</div>
                    <div class="text-sm text-white/80"><span class="text-white/50">เหตุผล:</span> ${legacyNote}</div>
                </div>`;
            }
            if (isDelete) {
                return `
                <div class="border border-red-500/30 bg-red-500/5 rounded-lg p-5 mb-3">
                    ${header}
                    <div class="text-sm text-white/80 mb-2"><span class="text-white/50">เหตุผลการลบ:</span> ${n.adjustment_reason || '—'}</div>
                    <div class="text-xs text-white/60 mb-1">ข้อมูลก่อนถูกลบ:</div>
                    <table class="w-full text-sm">
                        <tbody>
                            ${[['เวลาเข้างาน','check_in_time'],['เวลาออกงาน','check_out_time'],['ชม. ทำงาน (นาที)','work_minutes'],['สาย (นาที)','late_minutes'],['สถานะ','status']]
                              .map(([lbl,k]) => `<tr><td class="py-1 pr-4 text-white/60 w-40">${lbl}</td><td class="py-1 text-white/80">${fmtVal(o[k])}</td></tr>`).join('')}
                        </tbody>
                    </table>
                </div>`;
            }
            const fields = [
                ['เวลาเข้างาน',   'check_in_time'],
                ['เวลาออกงาน',   'check_out_time'],
                ['ชม. ทำงาน (นาที)', 'work_minutes'],
                ['สาย (นาที)',   'late_minutes'],
                ['สถานะ',         'status'],
                ['เหตุผล',         'adjustment_reason'],
            ];
            const rows = fields.map(([lbl, k]) => diffRow(lbl, o[k], n[k])).join('');
            return `
            <div class="border border-white/10 rounded-lg p-5 mb-3">
                ${header}
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-white/50 text-xs border-b border-white/10">
                            <th class="text-left py-1 pr-4 w-40">ฟิลด์</th>
                            <th class="text-left py-1 pr-4">ก่อน</th>
                            <th class="text-left py-1">หลัง</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
        }).join('');
        document.getElementById('history-body').innerHTML = html;
    } catch (e) {
        document.getElementById('history-body').innerHTML = '<p class="text-red-400 text-center py-8">โหลดประวัติไม่สำเร็จ</p>';
    }
}
</script>

<?php if ($highlightUserId > 0): ?>
<script>
(function () {
    var uid = <?php echo (int)$highlightUserId; ?>;
    var autoFix = <?php echo $autoOpenFix ? 'true' : 'false'; ?>;
    var el = document.getElementById('att-row-' + uid);
    if (!el) return;
    setTimeout(function () {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (autoFix) {
            var btn = el.querySelector('button[onclick*="editAttendance"]');
            if (btn) btn.click();
        }
    }, 300);
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
