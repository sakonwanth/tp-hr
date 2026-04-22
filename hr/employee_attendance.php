<?php
/**
 * Employee Attendance Detail
 * ประวัติการลงเวลารายบุคคล - สำหรับ HR/CEO
 */

$page_title = 'ประวัติการลงเวลา';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$currentUser = Auth::user();

if (!isHR()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Get employee ID from URL
$employeeId = (int)($_GET['id'] ?? 0);
if (!$employeeId) {
    flash('error', 'ไม่พบข้อมูลพนักงาน');
    redirect('/hr/employees.php', 302);
}

// Get employee info
$stmtEmp = $pdo->prepare("
    SELECT u.*, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.id = ?
");
$stmtEmp->execute([$employeeId]);
$employee = $stmtEmp->fetch();

if (!$employee) {
    flash('error', 'ไม่พบข้อมูลพนักงาน');
    redirect('/hr/employees.php', 302);
}

$page_title = 'ประวัติลงเวลา - ' . $employee['first_name_th'] . ' ' . $employee['last_name_th'];

// Get month filter
$month = $_GET['month'] ?? date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$today = date('Y-m-d');
$lastDay = ($monthEnd <= $today) ? $monthEnd : $today;

// Get attendance records
$stmtAtt = $pdo->prepare("
    SELECT a.*, s.name as shift_name, s.start_time as shift_start, s.end_time as shift_end
    FROM hr_attendances a
    LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
    WHERE a.user_id = ? AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
    ORDER BY a.attendance_date DESC
");
$stmtAtt->execute([$employeeId, $month]);
$attendances = $stmtAtt->fetchAll();

// Index by date
$attendanceByDate = [];
foreach ($attendances as $att) {
    $attendanceByDate[$att['attendance_date']] = $att;
}

// Get holidays
$stmtHolidays = $pdo->prepare("
    SELECT date, name, type FROM hr_holidays 
    WHERE DATE_FORMAT(date, '%Y-%m') = ? AND is_active = 1
");
$stmtHolidays->execute([$month]);
$holidays = [];
foreach ($stmtHolidays->fetchAll() as $h) {
    $holidays[$h['date']] = $h;
}

// Get employee schedule (default day off)
$stmtSched = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
$stmtSched->execute([$employeeId]);
$schedule = $stmtSched->fetch();
$defaultDayOff = $schedule ? (int)$schedule['day_off'] : 0;

// Get approved day-off swaps for this month's weeks
$stmtSwaps = $pdo->prepare("
    SELECT week_start, week_end, requested_day_off 
    FROM hr_dayoff_requests 
    WHERE user_id = ? AND status = 'APPROVED' 
    AND week_start <= ? AND week_end >= ?
");
$stmtSwaps->execute([$employeeId, $lastDay, $monthStart]);
$dayoffSwaps = $stmtSwaps->fetchAll();

// Build all days
$allDays = [];
$currentDay = $monthStart;
while ($currentDay <= $lastDay) {
    $dow = (int)date('w', strtotime($currentDay));
    // Check if this date falls in an approved swap week
    $effectiveDayOff = $defaultDayOff;
    foreach ($dayoffSwaps as $swap) {
        if ($currentDay >= $swap['week_start'] && $currentDay <= $swap['week_end']) {
            $effectiveDayOff = (int)$swap['requested_day_off'];
            break;
        }
    }
    $isDayOff = ($dow === $effectiveDayOff);
    $holiday = $holidays[$currentDay] ?? null;
    $att = $attendanceByDate[$currentDay] ?? null;
    
    $allDays[] = [
        'date' => $currentDay,
        'dow' => $dow,
        'is_day_off' => $isDayOff,
        'holiday' => $holiday,
        'attendance' => $att,
    ];
    $currentDay = date('Y-m-d', strtotime('+1 day', strtotime($currentDay)));
}

// Monthly summary
$stmtSummary = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status IN ('PRESENT','LATE','WFH','HALF_DAY') THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'LATE'   THEN 1 END) as late_days,
        COUNT(CASE WHEN status = 'ABSENT' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'LEAVE'  THEN 1 END) as leave_days,
        SUM(COALESCE(work_minutes, 0)) as total_work_minutes,
        SUM(COALESCE(late_minutes, 0)) as total_late_minutes
    FROM hr_attendances 
    WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
");
$stmtSummary->execute([$employeeId, $month]);
$summary = $stmtSummary->fetch();

// Month options
$monthOptions = [];
for ($i = 0; $i < 12; $i++) {
    $d = date('Y-m', strtotime("-$i months"));
    $monthOptions[] = ['value' => $d, 'label' => formatDateThai($d . '-01')];
}

$current_page = 'hr-employees';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/reports.php?report=attendance" class="hover:text-white">รายงาน</a>
        <span class="mx-2">/</span>
        <span class="text-white">ประวัติลงเวลารายบุคคล</span>
    </nav>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-white">ประวัติลงเวลารายบุคคล</h1>
        <a href="javascript:history.back()" class="px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>กลับ
        </a>
    </div>
</div>

<!-- Employee Info Card -->
<div class="glass-card rounded-xl p-6 mb-6">
    <div class="flex items-center gap-4">
        <div class="w-16 h-16 rounded-full bg-violet-500/20 flex items-center justify-center">
            <i class="fas fa-user text-violet-400 text-2xl"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-xl font-bold text-white">
                <?php echo htmlspecialchars($employee['first_name_th'] . ' ' . $employee['last_name_th']); ?>
            </h2>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-white/60 mt-1">
                <span><i class="fas fa-id-badge mr-1"></i><?php echo htmlspecialchars($employee['employee_code'] ?? '-'); ?></span>
                <span><i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($employee['department'] ?? '-'); ?></span>
                <span><i class="fas fa-user-tag mr-1"></i><?php echo htmlspecialchars($employee['position'] ?? '-'); ?></span>
                <?php 
                $dayNames = THAI_DAY_NAMES;
                $daysOff = [$defaultDayOff];
                foreach ($dayoffSwaps as $swap) {
                    $d = (int)$swap['requested_day_off'];
                    if (!in_array($d, $daysOff, true)) $daysOff[] = $d;
                }
                $dayOffNames = array_map(fn($d) => $dayNames[$d] ?? '', $daysOff);
                ?>
                <span><i class="fas fa-calendar-minus mr-1"></i>วันหยุด: <?php echo implode(', ', $dayOffNames); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Month Filter -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-4">
        <input type="hidden" name="id" value="<?php echo $employeeId; ?>">
        <div class="flex items-center gap-2">
            <label class="text-white/70 text-sm">เดือน:</label>
            <select name="month" class="input-field w-auto" onchange="this.form.submit()">
                <?php foreach ($monthOptions as $opt): ?>
                <option value="<?php echo $opt['value']; ?>" <?php echo $month === $opt['value'] ? 'selected' : ''; ?>>
                    <?php echo $opt['label']; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="glass-card rounded-xl p-4 text-center">
        <p class="text-white/60 text-sm">มาทำงาน</p>
        <p class="text-3xl font-bold text-green-400"><?php echo (int)($summary['present_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">วัน</p>
    </div>
    <div class="glass-card rounded-xl p-4 text-center">
        <p class="text-white/60 text-sm">มาสาย</p>
        <p class="text-3xl font-bold text-yellow-400"><?php echo (int)($summary['late_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">ครั้ง</p>
    </div>
    <div class="glass-card rounded-xl p-4 text-center">
        <p class="text-white/60 text-sm">ขาดงาน</p>
        <p class="text-3xl font-bold text-red-400"><?php echo (int)($summary['absent_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">วัน</p>
    </div>
    <div class="glass-card rounded-xl p-4 text-center">
        <p class="text-white/60 text-sm">ลา</p>
        <p class="text-3xl font-bold text-blue-400"><?php echo (int)($summary['leave_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">วัน</p>
    </div>
    <div class="glass-card rounded-xl p-4 text-center">
        <p class="text-white/60 text-sm">ชั่วโมงทำงาน</p>
        <p class="text-3xl font-bold text-white"><?php echo floor(($summary['total_work_minutes'] ?? 0) / 60); ?></p>
        <p class="text-white/50 text-xs">ชั่วโมง</p>
    </div>
</div>

<!-- Attendance Table -->
<div class="glass-card rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/10">
                    <th class="px-4 py-3 text-left text-white/70 text-sm font-medium">วันที่</th>
                    <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">กะ</th>
                    <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">เข้างาน</th>
                    <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">ออกงาน</th>
                    <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">ชม.ทำงาน</th>
                    <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($allDays): ?>
                    <?php $dayNamesShort = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.']; ?>
                    <?php foreach ($allDays as $day): 
                        $att = $day['attendance'];
                        $isDayOff = $day['is_day_off'];
                        $holiday = $day['holiday'];
                        
                        $rowClass = 'border-b border-white/5 hover:bg-white/5';
                        if ($holiday) {
                            $rowClass = 'border-b border-white/5 bg-orange-500/5';
                        } elseif ($isDayOff) {
                            $rowClass = 'border-b border-white/5 bg-blue-500/5';
                        }
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div>
                                    <div class="text-white <?php echo ($isDayOff || $holiday) ? 'opacity-70' : ''; ?>">
                                        <?php echo formatDateThai($day['date']); ?>
                                    </div>
                                    <div class="text-xs <?php echo $isDayOff ? 'text-blue-400' : ($holiday ? 'text-orange-400' : 'text-white/50'); ?>">
                                        <?php echo $dayNamesShort[$day['dow']]; ?>
                                        <?php if ($holiday): ?>
                                            <span class="ml-1 text-orange-400">
                                                <i class="fas fa-star text-[10px]"></i>
                                                <?php echo htmlspecialchars($holiday['name']); ?>
                                            </span>
                                        <?php elseif ($isDayOff): ?>
                                            <span class="ml-1 text-blue-400/70">วันหยุด</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-white/70 text-sm">
                            <?php echo $att ? ($att['shift_name'] ?? '-') : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($att && $att['check_in_time']): ?>
                                <span class="text-green-400 font-medium"><?php echo date('H:i', strtotime($att['check_in_time'])); ?></span>
                                <?php if ($att['late_minutes'] > 0): ?>
                                <span class="text-red-400 text-xs ml-1">(+<?php echo $att['late_minutes']; ?>น.)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-white/30">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($att && $att['check_out_time']): ?>
                                <span class="text-blue-400 font-medium"><?php echo date('H:i', strtotime($att['check_out_time'])); ?></span>
                            <?php else: ?>
                                <span class="text-white/30">--:--</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-white">
                            <?php 
                            if ($att && $att['work_minutes']) {
                                echo floor($att['work_minutes'] / 60) . ':' . str_pad($att['work_minutes'] % 60, 2, '0', STR_PAD_LEFT);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($holiday): ?>
                            <span class="px-2 py-1 text-xs rounded bg-orange-500/20 text-orange-400">
                                <?php echo match($holiday['type']) {
                                    'PUBLIC' => 'วันหยุดราชการ',
                                    'COMPANY' => 'วันหยุดบริษัท',
                                    'SPECIAL' => 'วันหยุดพิเศษ',
                                    'SUBSTITUTE' => 'วันหยุดชดเชย',
                                    default => 'วันหยุด'
                                }; ?>
                            </span>
                            <?php elseif ($isDayOff): ?>
                            <span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">วันหยุด</span>
                            <?php elseif ($att): ?>
                            <span class="px-2 py-1 text-xs rounded <?php 
                                echo match($att['status']) {
                                    'PRESENT' => 'bg-green-500/20 text-green-400',
                                    'LATE' => 'bg-yellow-500/20 text-yellow-400',
                                    'ABSENT' => 'bg-red-500/20 text-red-400',
                                    'LEAVE' => 'bg-blue-500/20 text-blue-400',
                                    'HOLIDAY' => 'bg-orange-500/20 text-orange-400',
                                    'WFH' => 'bg-purple-500/20 text-purple-400',
                                    default => 'bg-gray-500/20 text-gray-400'
                                };
                            ?>">
                                <?php echo ATTENDANCE_STATUS[$att['status']] ?? $att['status']; ?>
                            </span>
                            <?php else: ?>
                            <span class="px-2 py-1 text-xs rounded bg-red-500/20 text-red-400">ขาดงาน</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-white/50">
                            <i class="fas fa-calendar-times text-4xl mb-3 block"></i>
                            <p>ไม่พบข้อมูลในเดือนนี้</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
