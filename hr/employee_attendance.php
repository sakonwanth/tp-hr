<?php
/**
 * Employee Attendance Detail
 * ประวัติการลงเวลารายบุคคล - สำหรับ HR/CEO
 */

$page_title = 'ประวัติการลงเวลา';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$currentUser = Auth::user();

if (!hr_can_access_hr_dashboard()) {
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

$empFullNameTh = trim(($employee['title'] ?? '') . ' ' . ($employee['first_name_th'] ?? '') . ' ' . ($employee['last_name_th'] ?? ''));

$current_page = 'hr-attendance';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6 min-w-0">
    <nav class="text-sm text-white/60 mb-1" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/attendance.php" class="hover:text-white touch-manipulation">จัดการเวลาทำงาน</a>
        <span class="mx-2">/</span>
        <span class="text-white">ประวัติลงเวลา</span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold text-white tracking-tight">ประวัติลงเวลารายบุคคล</h1>
            <p class="text-slate-300 text-sm mt-1.5 leading-relaxed"><?php echo htmlspecialchars($empFullNameTh); ?></p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto shrink-0">
            <a href="/hr/employee_view.php?id=<?php echo (int)$employeeId; ?>" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl transition-colors font-medium touch-manipulation">
                <i class="fas fa-user mr-2"></i>โปรไฟล์
            </a>
            <a href="/hr/attendance.php" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl transition-colors font-medium touch-manipulation">
                <i class="fas fa-arrow-left mr-2"></i>กลับ
            </a>
        </div>
    </div>
</div>

<!-- Employee Info Card -->
<div class="glass-card rounded-xl p-4 sm:p-6 mb-6 min-w-0 overflow-hidden">
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
<div class="glass-card rounded-xl p-4 sm:p-6 mb-6 min-w-0 overflow-hidden">
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
<div class="grid grid-cols-2 md:grid-cols-5 gap-2 sm:gap-4 mb-6 min-w-0 max-w-full">
    <div class="glass-card rounded-xl p-4 min-w-0 overflow-hidden text-center">
        <p class="text-white/60 text-sm truncate">มาทำงาน</p>
        <p class="text-3xl font-bold text-green-400 tabular-nums mt-1"><?php echo (int)($summary['present_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">วัน</p>
    </div>
    <div class="glass-card rounded-xl p-4 min-w-0 overflow-hidden text-center">
        <p class="text-white/60 text-sm truncate">มาสาย</p>
        <p class="text-3xl font-bold text-yellow-400 tabular-nums mt-1"><?php echo (int)($summary['late_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">ครั้ง</p>
    </div>
    <div class="glass-card rounded-xl p-4 min-w-0 overflow-hidden text-center">
        <p class="text-white/60 text-sm truncate">ขาดงาน</p>
        <p class="text-3xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)($summary['absent_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">วัน</p>
    </div>
    <div class="glass-card rounded-xl p-4 min-w-0 overflow-hidden text-center">
        <p class="text-white/60 text-sm truncate">ลา</p>
        <p class="text-3xl font-bold text-blue-400 tabular-nums mt-1"><?php echo (int)($summary['leave_days'] ?? 0); ?></p>
        <p class="text-white/50 text-xs">วัน</p>
    </div>
    <div class="glass-card rounded-xl p-4 min-w-0 overflow-hidden text-center md:col-span-1 col-span-2">
        <p class="text-white/60 text-sm truncate">ชั่วโมงทำงาน</p>
        <p class="text-3xl font-bold text-white tabular-nums mt-1"><?php echo floor(($summary['total_work_minutes'] ?? 0) / 60); ?></p>
        <p class="text-white/50 text-xs">ชั่วโมง</p>
    </div>
</div>

<!-- Attendance Table -->
<div class="glass-card rounded-xl overflow-hidden min-w-0">
    <?php if ($allDays): ?>
    <div class="md:hidden p-3 space-y-3">
        <?php foreach ($allDays as $day): ?>
        <?php
        $att = $day['attendance'];
        $isDayOff = $day['is_day_off'];
        $holiday = $day['holiday'];
        $dayNamesCard = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
        $statusLabel = 'ขาดงาน';
        $statusClass = 'bg-red-500/15 border border-red-500/30 text-red-200';
        if ($holiday) {
            $statusLabel = match($holiday['type']) {
                'PUBLIC' => 'วันหยุดราชการ',
                'COMPANY' => 'วันหยุดบริษัท',
                'SPECIAL' => 'วันหยุดพิเศษ',
                'SUBSTITUTE' => 'วันหยุดชดเชย',
                default => 'วันหยุด'
            };
            $statusClass = 'bg-orange-500/15 border border-orange-500/30 text-orange-200';
        } elseif ($isDayOff) {
            $statusLabel = 'วันหยุด';
            $statusClass = 'bg-blue-500/15 border border-blue-500/30 text-blue-200';
        } elseif ($att) {
            $statusLabel = ATTENDANCE_STATUS[$att['status']] ?? $att['status'];
            $statusClass = match($att['status']) {
                'PRESENT' => 'bg-green-500/15 border border-green-500/30 text-green-200',
                'LATE' => 'bg-yellow-500/15 border border-yellow-500/30 text-yellow-200',
                'ABSENT' => 'bg-red-500/15 border border-red-500/30 text-red-200',
                'LEAVE' => 'bg-blue-500/15 border border-blue-500/30 text-blue-200',
                'HOLIDAY' => 'bg-orange-500/15 border border-orange-500/30 text-orange-200',
                'WFH' => 'bg-purple-500/15 border border-purple-500/30 text-purple-200',
                default => 'bg-gray-500/15 border border-gray-500/30 text-gray-200'
            };
        }
        $checkIn = ($att && $att['check_in_time']) ? date('H:i', strtotime($att['check_in_time'])) : '--:--';
        $checkOut = ($att && $att['check_out_time']) ? date('H:i', strtotime($att['check_out_time'])) : '--:--';
        $work = ($att && $att['work_minutes'])
            ? floor($att['work_minutes'] / 60) . ':' . str_pad((string)($att['work_minutes'] % 60), 2, '0', STR_PAD_LEFT)
            : '-';
        ?>
        <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-white font-semibold"><?php echo formatDateThai($day['date']); ?></div>
                    <div class="text-xs <?php echo $holiday ? 'text-orange-300' : ($isDayOff ? 'text-blue-300' : 'text-white/50'); ?>">
                        <?php echo $dayNamesCard[$day['dow']]; ?>
                        <?php if ($holiday): ?>
                            · <?php echo htmlspecialchars($holiday['name']); ?>
                        <?php elseif ($isDayOff): ?>
                            · วันหยุด
                        <?php endif; ?>
                    </div>
                </div>
                <span class="shrink-0 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($statusLabel); ?>
                </span>
            </div>

            <div class="grid grid-cols-3 gap-2 mt-4">
                <div class="rounded-xl bg-black/20 border border-white/10 px-2 py-2">
                    <div class="text-[11px] text-white/50">เข้า</div>
                    <div class="text-white font-semibold"><?php echo htmlspecialchars($checkIn); ?></div>
                </div>
                <div class="rounded-xl bg-black/20 border border-white/10 px-2 py-2">
                    <div class="text-[11px] text-white/50">ออก</div>
                    <div class="text-white font-semibold"><?php echo htmlspecialchars($checkOut); ?></div>
                </div>
                <div class="rounded-xl bg-black/20 border border-white/10 px-2 py-2">
                    <div class="text-[11px] text-white/50">ชม.</div>
                    <div class="text-white font-semibold"><?php echo htmlspecialchars($work); ?></div>
                </div>
            </div>

            <?php if ($att && !empty($att['shift_name'])): ?>
            <div class="mt-3 text-xs text-white/50">
                <i class="fas fa-clock mr-1"></i>
                <?php echo htmlspecialchars(function_exists('shift_display_label') ? shift_display_label($att) : $att['shift_name']); ?>
                <?php if (($att['late_minutes'] ?? 0) > 0): ?>
                    <span class="text-red-300 ml-2">สาย <?php echo (int)$att['late_minutes']; ?> นาที</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="md:hidden px-4 py-8 text-center text-white/50">
        <i class="fas fa-calendar-times text-4xl mb-3 block"></i>
        <p>ไม่พบข้อมูลในเดือนนี้</p>
    </div>
    <?php endif; ?>

    <div class="hidden md:block overflow-x-auto">
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
                            <?php
                            if ($att && !empty($att['shift_name'])) {
                                echo htmlspecialchars(function_exists('shift_display_label') ? shift_display_label($att) : $att['shift_name']);
                            } else {
                                echo '-';
                            }
                            ?>
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
