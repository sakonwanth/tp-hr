<?php
/**
 * HR Attendance Management
 * จัดการการเข้างาน - สำหรับ HR
 */

$page_title = 'จัดการเวลาทำงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!isHR()) {
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
$stmtDepts = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND id NOT IN (" . SYSTEM_USER_IDS_SQL . ") ORDER BY department");
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
    WHERE u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
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
        (SELECT COUNT(*) FROM users WHERE is_active = 1 AND id NOT IN (" . SYSTEM_USER_IDS_SQL . ")) as total_employees,
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
    WHERE u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
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
    WHERE u.is_active = 1 AND u.id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
");
$stmtDayOff->execute([$weekday]);
$dayOffStats = $stmtDayOff->fetch();
$isWeekend = ($dayOffStats['total'] > 0 && (int)$dayOffStats['total'] === (int)$dayOffStats['matches']);

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการเวลาทำงาน</span>
    </nav>
    <h1 class="text-2xl font-bold text-white">จัดการเวลาทำงาน</h1>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="?date=<?php echo $date; ?>&status=" 
       class="glass-card rounded-xl p-4 <?php echo !$status ? 'ring-2 ring-violet-400' : ''; ?>">
        <p class="text-white/50 text-sm">พนักงานทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400"><?php echo $stats['total_employees']; ?></p>
    </a>
    <a href="?date=<?php echo $date; ?>&status=PRESENT"
       class="glass-card rounded-xl p-4 <?php echo $status === 'PRESENT' ? 'ring-2 ring-green-400' : ''; ?>">
        <p class="text-white/50 text-sm">เข้างาน</p>
        <p class="text-2xl font-bold text-green-400"><?php echo $stats['checked_in'] ?? 0; ?></p>
    </a>
    <a href="?date=<?php echo $date; ?>&status=ABSENT"
       class="glass-card rounded-xl p-4 <?php echo $status === 'ABSENT' ? 'ring-2 ring-red-400' : ''; ?>">
        <p class="text-white/50 text-sm">ขาดงาน</p>
        <p class="text-2xl font-bold text-red-400"><?php echo $absentCount; ?></p>
    </a>
    <a href="?date=<?php echo $date; ?>&status=LATE"
       class="glass-card rounded-xl p-4 <?php echo $status === 'LATE' ? 'ring-2 ring-yellow-400' : ''; ?>">
        <p class="text-white/50 text-sm">สาย</p>
        <p class="text-2xl font-bold text-yellow-400"><?php echo $stats['late_count'] ?? 0; ?></p>
    </a>
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">เวลาเข้างานเฉลี่ย</p>
        <p class="text-2xl font-bold text-white"><?php echo $stats['avg_check_in'] ? substr($stats['avg_check_in'], 0, 5) : '--:--'; ?></p>
    </div>
</div>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-white/60 text-xs mb-1">วันที่</label>
            <input type="date" name="date" value="<?php echo $date; ?>" class="input-field" onchange="this.form.submit()">
        </div>
        <div>
            <label class="block text-white/60 text-xs mb-1">แผนก</label>
            <select name="department" class="input-field" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <a href="?date=<?php echo date('Y-m-d'); ?>" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-lg transition-colors">วันนี้</a>
            <a href="?date=<?php echo date('Y-m-d', strtotime('-1 day', strtotime($date))); ?>" class="px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg">
                <i class="fas fa-chevron-left"></i>
            </a>
            <a href="?date=<?php echo date('Y-m-d', strtotime('+1 day', strtotime($date))); ?>" class="px-3 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-lg">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <div class="flex items-end gap-2">
            <a href="attendance.php?action=report&month=<?php echo date('Y-m', strtotime($date)); ?>" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-file-export mr-2"></i>รายงาน
            </a>
        </div>
    </form>
</div>

<!-- Title -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-white">
        <?php echo formatDateThai($date); ?>
        <?php 
        $dayNames = THAI_DAY_NAMES;
        echo ' (' . $dayNames[date('w', strtotime($date))] . ')';
        ?>
    </h2>
</div>

<?php if ($holidayInfo || $isWeekend): ?>
<div class="rounded-xl p-4 mb-4 <?php echo $holidayInfo ? 'bg-orange-500/20 border border-orange-500/30' : 'bg-blue-500/20 border border-blue-500/30'; ?>">
    <div class="flex items-center gap-3">
        <i class="fas <?php echo $holidayInfo ? 'fa-calendar-check text-orange-400' : 'fa-calendar-day text-blue-400'; ?> text-xl"></i>
        <div>
            <?php if ($holidayInfo): ?>
            <p class="text-orange-300 font-medium">
                <i class="fas fa-star text-xs mr-1"></i>
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
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($records)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-users text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบข้อมูล</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">เข้างาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ออกงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ชั่วโมงทำงาน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">หมายเหตุ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
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
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($rec['check_in_photo']): ?>
                            <img src="<?php echo htmlspecialchars($rec['check_in_photo']); ?>" class="w-10 h-10 rounded-full object-cover">
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
                        <?php if ($rec['ot_minutes'] > 0): ?>
                        <span class="text-green-400 text-xs ml-1">(+<?php echo floor($rec['ot_minutes']/60); ?>h)</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-white/40">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (!$hasAttendance && $holidayInfo): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-orange-500/20 text-orange-300">วันหยุด</span>
                        <?php elseif (!$hasAttendance && $onLeave): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-blue-500/20 text-blue-300">ลา</span>
                        <?php elseif (!$hasAttendance && $isUserDayOff): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-sky-500/20 text-sky-300">วันหยุดประจำสัปดาห์</span>
                        <?php elseif (!$hasAttendance): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-red-500/20 text-red-400">ขาดงาน</span>
                        <?php elseif ($isLate): ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-yellow-500/20 text-yellow-400">มาสาย</span>
                        <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs bg-green-500/20 text-green-400">ปกติ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white/60 text-sm">
                        <?php
                        $notes = [];
                        if ($onLeave) $notes[] = htmlspecialchars($rec['approved_leave_name']);
                        if ($isLate) $notes[] = 'สาย ' . $rec['late_minutes'] . ' นาที';
                        if ($isEarlyLeave) $notes[] = 'ออกก่อน ' . $rec['early_leave_minutes'] . ' นาที';
                        echo implode(', ', $notes) ?: '-';
                        ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($hasAttendance && $rec['check_in_latitude']): ?>
                        <button onclick="viewLocation(<?php echo $rec['check_in_latitude']; ?>, <?php echo $rec['check_in_longitude']; ?>)" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors mr-1" title="ดูตำแหน่ง">
                            <i class="fas fa-map-marker-alt"></i>
                        </button>
                        <?php endif; ?>
                        <button onclick="editAttendance(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', <?php echo $rec['attendance_id'] ?? 'null'; ?>, '<?php echo $rec['check_in_time'] ? date('H:i', strtotime($rec['check_in_time'])) : ''; ?>', '<?php echo $rec['check_out_time'] ? date('H:i', strtotime($rec['check_out_time'])) : ''; ?>')" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ($hasAttendance): ?>
                        <button onclick="deleteAttendance(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>')" 
                                class="px-2 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-300 text-xs rounded transition-colors ml-1" title="ลบข้อมูลการลงเวลา">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                        <button onclick="viewHistory(<?php echo $rec['id']; ?>, '<?php echo $date; ?>', '<?php echo htmlspecialchars(($rec['first_name_th'] ?? '') . ' ' . ($rec['last_name_th'] ?? ''), ENT_QUOTES); ?>')" 
                                class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors ml-1" title="ประวัติการแก้ไข">
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
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="edit-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="edit-form" class="p-6">
            <h3 class="text-xl font-bold text-white mb-4">แก้ไขเวลาทำงาน</h3>
            <input type="hidden" name="user_id" id="edit-user-id">
            <input type="hidden" name="attendance_date" id="edit-date">
            <input type="hidden" name="attendance_id" id="edit-attendance-id">
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-white/80 text-sm mb-2">เวลาเข้างาน</label>
                    <input type="time" name="check_in_time" id="edit-check-in" class="input-field">
                </div>
                <div>
                    <label class="block text-white/80 text-sm mb-2">เวลาออกงาน</label>
                    <input type="time" name="check_out_time" id="edit-check-out" class="input-field">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">เหตุผลการแก้ไข <span class="text-red-400">*</span></label>
                <textarea name="note" id="edit-note" rows="2" class="input-field" placeholder="ระบุเหตุผลการแก้ไข (จำเป็น)" required></textarea>
                <p class="text-white/50 text-xs mt-1">การแก้ไขทั้งหมดจะถูกบันทึกใน audit log</p>
            </div>
            
            <div class="flex gap-4">
                <button type="button" onclick="closeEditModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Location Modal -->
<div id="location-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-lg">
        <div class="p-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-white">ตำแหน่ง Check-in</h3>
                <button onclick="closeLocationModal()" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="map" class="w-full h-80 rounded-lg bg-white/10"></div>
            <div class="mt-2 text-center">
                <a id="map-link" href="#" target="_blank" class="text-blue-400 hover:text-blue-300 text-sm">
                    <i class="fas fa-external-link-alt mr-1"></i>เปิดใน Google Maps
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="delete-form" class="p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-full bg-red-500/20 flex items-center justify-center text-red-400">
                    <i class="fas fa-triangle-exclamation text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-white">ลบข้อมูลการลงเวลา</h3>
                    <p id="delete-subtitle" class="text-white/60 text-sm"></p>
                </div>
            </div>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-3 mb-4 text-red-200 text-sm">
                <i class="fas fa-info-circle mr-1"></i> การลบจะลบข้อมูลทั้งหมดของวันนี้ (เวลาเข้า/ออก, รูป, พิกัด, ชั่วโมงทำงาน) และระบบจะคำนวณสถานะใหม่ตามบริบท (วันหยุด/ลา/ขาดงาน) — ไม่สามารถกู้คืนได้
            </div>
            <input type="hidden" name="user_id" id="delete-user-id">
            <input type="hidden" name="attendance_date" id="delete-date">
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">เหตุผลการลบ <span class="text-red-400">*</span></label>
                <textarea name="note" id="delete-note" rows="3" class="input-field" placeholder="ระบุเหตุผลการลบข้อมูล (จำเป็น)" required></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeDeleteModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg"><i class="fas fa-trash mr-1"></i> ยืนยันการลบ</button>
            </div>
        </form>
    </div>
</div>

<!-- History Modal -->
<div id="history-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-3xl max-h-[85vh] flex flex-col">
        <div class="p-6 border-b border-white/10 flex items-center justify-between">
            <div>
                <h3 class="text-xl font-bold text-white">ประวัติการแก้ไขเวลาทำงาน</h3>
                <p id="history-subtitle" class="text-white/60 text-sm mt-1"></p>
                <p class="text-white/40 text-xs mt-1"><i class="fas fa-info-circle"></i> ประวัติทั้งหมดของพนักงานคนนี้ในวันนี้ เรียงจากล่าสุด — ชื่อที่แสดงคือผู้ดำเนินการแก้ไข</p>
            </div>
            <button onclick="closeHistoryModal()" class="p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-lg">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="history-body" class="p-6 overflow-y-auto flex-1">
            <div class="text-center text-white/60 py-8">
                <i class="fas fa-spinner fa-spin text-2xl"></i>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
function editAttendance(userId, date, attendanceId, checkIn, checkOut) {
    document.getElementById('edit-user-id').value = userId;
    document.getElementById('edit-date').value = date;
    document.getElementById('edit-attendance-id').value = attendanceId || '';
    document.getElementById('edit-check-in').value = checkIn || '';
    document.getElementById('edit-check-out').value = checkOut || '';
    document.getElementById('edit-note').value = '';
    document.getElementById('edit-modal').classList.remove('hidden');
}

function deleteAttendance(userId, date, empName) {
    document.getElementById('delete-user-id').value = userId;
    document.getElementById('delete-date').value = date;
    document.getElementById('delete-note').value = '';
    document.getElementById('delete-subtitle').textContent = empName + ' — ' + date;
    document.getElementById('delete-modal').classList.remove('hidden');
}
function closeDeleteModal() {
    document.getElementById('delete-modal').classList.add('hidden');
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.add('hidden');
}

document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const note = document.getElementById('edit-note').value.trim();
    if (!note) {
        showToast('กรุณาระบุเหตุผลการแก้ไข', 'error');
        return;
    }
    
    const ci = document.getElementById('edit-check-in').value;
    const co = document.getElementById('edit-check-out').value;
    if (!ci && !co) {
        showToast('กรุณาระบุเวลาเข้าหรือเวลาออกอย่างน้อยหนึ่งช่อง หากต้องการลบข้อมูล ให้ใช้ปุ่มลบ', 'error');
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
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

document.getElementById('delete-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const note = document.getElementById('delete-note').value.trim();
    if (!note) {
        showToast('กรุณาระบุเหตุผลการลบ', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('user_id', document.getElementById('delete-user-id').value);
    formData.append('attendance_date', document.getElementById('delete-date').value);
    formData.append('note', note);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/attendance.php', { method: 'POST', body: formData });
    const result = await response.json();
    if (result.success) {
        showToast('ลบข้อมูลสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

let map, marker;
function viewLocation(lat, lng) {
    document.getElementById('location-modal').classList.remove('hidden');
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
    document.getElementById('location-modal').classList.add('hidden');
}

document.getElementById('edit-modal').addEventListener('click', e => { if (e.target === document.getElementById('edit-modal')) closeEditModal(); });
document.getElementById('location-modal').addEventListener('click', e => { if (e.target === document.getElementById('location-modal')) closeLocationModal(); });
document.getElementById('history-modal').addEventListener('click', e => { if (e.target === document.getElementById('history-modal')) closeHistoryModal(); });
document.getElementById('delete-modal').addEventListener('click', e => { if (e.target === document.getElementById('delete-modal')) closeDeleteModal(); });

function closeHistoryModal() {
    document.getElementById('history-modal').classList.add('hidden');
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
    document.getElementById('history-body').innerHTML = '<div class="text-center text-white/60 py-8"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
    document.getElementById('history-modal').classList.remove('hidden');

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
            // Detect legacy entry (older format before audit refactor)
            const isLegacy = !isDelete && (!n || Object.keys(n).length === 0 ||
                             (!('check_in_time' in n) && !('status' in n)));
            const actionBadge = isDelete
                ? '<span class="inline-block px-2 py-0.5 rounded-full text-xs bg-red-500/20 text-red-300 ml-2"><i class="fas fa-trash"></i> ลบข้อมูล</span>'
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
                <div class="border border-white/10 rounded-lg p-4 mb-3 opacity-80">
                    ${header}
                    <div class="text-xs text-yellow-300 mb-2"><i class="fas fa-info-circle"></i> รายการเก่า — ระบบยังไม่ได้เก็บรายละเอียดก่อน/หลังในช่วงนั้น</div>
                    <div class="text-sm text-white/80"><span class="text-white/50">เหตุผล:</span> ${legacyNote}</div>
                </div>`;
            }
            if (isDelete) {
                return `
                <div class="border border-red-500/30 bg-red-500/5 rounded-lg p-4 mb-3">
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
            <div class="border border-white/10 rounded-lg p-4 mb-3">
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

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
