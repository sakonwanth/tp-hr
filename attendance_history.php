<?php
/**
 * TP-HR Attendance History
 * ประวัติการลงเวลา
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$page_title = 'ประวัติการลงเวลา';
$current_page = 'checkin';

// Get filter parameters
$month = $_GET['month'] ?? date('Y-m');
$status_filter = $_GET['status'] ?? '';

// Build query
$where = ["a.user_id = ?", "DATE_FORMAT(a.attendance_date, '%Y-%m') = ?"];
$params = [$user['id'], $month];

if ($status_filter) {
    $where[] = "a.status = ?";
    $params[] = $status_filter;
}

$whereClause = implode(' AND ', $where);

// Get attendance records
$stmt = $pdo->prepare("
    SELECT a.*, s.name as shift_name, s.start_time as shift_start, s.end_time as shift_end
    FROM hr_attendances a
    LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
    WHERE $whereClause
    ORDER BY a.attendance_date DESC
");
$stmt->execute($params);
$attendances = $stmt->fetchAll();

// Get monthly summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status IN ('PRESENT', 'LATE') THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'LATE' THEN 1 END) as late_days,
        COUNT(CASE WHEN status = 'ABSENT' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'LEAVE' THEN 1 END) as leave_days,
        SUM(COALESCE(work_minutes, 0)) as total_work_minutes,
        SUM(COALESCE(late_minutes, 0)) as total_late_minutes,
        SUM(COALESCE(ot_minutes, 0)) as total_ot_minutes
    FROM hr_attendances 
    WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
");
$stmt->execute([$user['id'], $month]);
$summary = $stmt->fetch();

// Get holidays for this month
$stmtHolidays = $pdo->prepare("
    SELECT date, name, type 
    FROM hr_holidays 
    WHERE DATE_FORMAT(date, '%Y-%m') = ? AND is_active = 1
    ORDER BY date
");
$stmtHolidays->execute([$month]);
$holidays = [];
foreach ($stmtHolidays->fetchAll() as $h) {
    $holidays[$h['date']] = $h;
}

// Build full calendar for the month (all days up to today or end of month)
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$today = date('Y-m-d');
$lastDay = ($monthEnd <= $today) ? $monthEnd : $today;

// Index attendance records by date
$attendanceByDate = [];
foreach ($attendances as $att) {
    $attendanceByDate[$att['attendance_date']] = $att;
}

// Get employee's day-off schedule (default)
$stmtSched = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
$stmtSched->execute([$user['id']]);
$schedule = $stmtSched->fetch();
$defaultDayOff = $schedule ? (int)$schedule['day_off'] : 0;

// Get approved day-off swaps for this month
$stmtSwaps = $pdo->prepare("
    SELECT week_start, week_end, requested_day_off 
    FROM hr_dayoff_requests 
    WHERE user_id = ? AND status = 'APPROVED' 
    AND week_start <= ? AND week_end >= ?
");
$stmtSwaps->execute([$user['id'], $lastDay, $monthStart]);
$dayoffSwaps = $stmtSwaps->fetchAll();

// Generate all days
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

// Generate month options (last 12 months)
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $date = date('Y-m', strtotime("-$i months"));
    $month_options[] = [
        'value' => $date,
        'label' => formatDateThai($date . '-01')
    ];
}

require_once __DIR__ . '/templates/header.php';

$dayNamesShort = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
?>

<div class="tp-attendance-history-stack tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
    <!-- Page Header -->
    <div class="mb-5 md:mb-8 min-w-0">
        <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
            <a href="checkin.php" class="hover:text-white touch-manipulation">ลงเวลา</a>
            <span class="mx-2">/</span>
            <span class="text-white">ประวัติ</span>
        </nav>
        <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between gap-y-4">
            <div class="min-w-0 flex-1">
                <h1 class="tp-ios-page-title">ประวัติการลงเวลา</h1>
                <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">สรุปรายเดือน กรองสถานะ และดูรายวันจนถึงวันนี้</p>
            </div>
            <a href="checkin.php" class="btn-primary btn-primary-prominent w-full sm:w-auto shrink-0 inline-flex items-center justify-center touch-manipulation rounded-[var(--tp-ios-card-radius)]">
                <i class="fas fa-fingerprint mr-2 text-xl" aria-hidden="true"></i>ลงเวลา
            </a>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="native-card tp-native-card tp-native-data-card p-4 mb-4 md:mb-6 min-w-0">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 gap-4 min-w-0 max-w-full">
            <div class="min-w-0">
                <label class="block text-white/60 text-xs mb-1">เดือน</label>
                <select name="month" class="input-field w-full" onchange="this.form.submit()">
                    <?php foreach ($month_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt['value']); ?>" <?php echo $month === $opt['value'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($opt['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-0">
                <label class="block text-white/60 text-xs mb-1">สถานะ</label>
                <select name="status" class="input-field w-full" onchange="this.form.submit()">
                    <option value="">ทั้งหมด</option>
                    <option value="PRESENT" <?php echo $status_filter === 'PRESENT' ? 'selected' : ''; ?>>มาทำงาน</option>
                    <option value="LATE" <?php echo $status_filter === 'LATE' ? 'selected' : ''; ?>>มาสาย</option>
                    <option value="ABSENT" <?php echo $status_filter === 'ABSENT' ? 'selected' : ''; ?>>ขาดงาน</option>
                    <option value="LEAVE" <?php echo $status_filter === 'LEAVE' ? 'selected' : ''; ?>>ลา</option>
                    <option value="HOLIDAY" <?php echo $status_filter === 'HOLIDAY' ? 'selected' : ''; ?>>วันหยุด</option>
                </select>
            </div>
        </form>
    </div>
    
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 md:gap-6 mb-4 md:mb-6 min-w-0 max-w-full">
        <div class="native-card tp-native-card tp-native-data-card p-4 text-center min-w-0">
            <p class="text-white/60 text-sm">มาทำงาน</p>
            <p class="text-3xl font-bold text-emerald-400"><?php echo $summary['present_days'] ?? 0; ?></p>
            <p class="text-white/50 text-xs">วัน</p>
        </div>
        
        <div class="native-card tp-native-card tp-native-data-card p-4 text-center min-w-0">
            <p class="text-white/60 text-sm">มาสาย</p>
            <p class="text-3xl font-bold text-amber-400"><?php echo $summary['late_days'] ?? 0; ?></p>
            <p class="text-white/50 text-xs">ครั้ง</p>
        </div>
        
        <div class="native-card tp-native-card tp-native-data-card p-4 text-center min-w-0">
            <p class="text-white/60 text-sm">ชั่วโมงทำงาน</p>
            <p class="text-3xl font-bold text-white">
                <?php echo floor(($summary['total_work_minutes'] ?? 0) / 60); ?>
            </p>
            <p class="text-white/50 text-xs">ชั่วโมง</p>
        </div>
        
        <div class="native-card tp-native-card tp-native-data-card p-4 text-center min-w-0">
            <p class="text-white/60 text-sm">OT</p>
            <p class="text-3xl font-bold text-emerald-400">
                <?php echo floor(($summary['total_ot_minutes'] ?? 0) / 60); ?>
            </p>
            <p class="text-white/50 text-xs">ชั่วโมง</p>
        </div>
    </div>
    
    <!-- Attendance Table -->
    <div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
        <?php if ($allDays): ?>
        <div class="md:hidden p-3 space-y-3 min-w-0">
            <?php foreach ($allDays as $day): ?>
            <?php
            $att = $day['attendance'];
            $isDayOff = $day['is_day_off'];
            $holiday = $day['holiday'];
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
                    'LATE' => 'bg-amber-500/15 border border-amber-500/30 text-amber-200',
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
                ? floor($att['work_minutes'] / 60) . ':' . str_pad($att['work_minutes'] % 60, 2, '0', STR_PAD_LEFT)
                : '-';
            $ot = ($att && $att['ot_minutes'] > 0)
                ? floor($att['ot_minutes'] / 60) . ':' . str_pad($att['ot_minutes'] % 60, 2, '0', STR_PAD_LEFT)
                : '-';
            ?>
            <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/8 p-4 min-w-0">
                <div class="flex items-start justify-between gap-3 min-w-0">
                    <div class="min-w-0 flex-1">
                        <div class="text-white font-semibold"><?php echo formatDateThai($day['date']); ?></div>
                        <div class="text-xs <?php echo $holiday ? 'text-orange-300' : ($isDayOff ? 'text-blue-300' : 'text-white/50'); ?>" style="word-break: break-word;">
                            <?php echo $dayNamesShort[$day['dow']]; ?>
                            <?php if ($holiday): ?>
                                · <?php echo htmlspecialchars($holiday['name']); ?>
                            <?php elseif ($isDayOff): ?>
                                · วันหยุด
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="shrink-0 px-2.5 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-semibold <?php echo $statusClass; ?>">
                        <?php echo htmlspecialchars($statusLabel); ?>
                    </span>
                </div>

                <div class="grid grid-cols-4 gap-2 mt-4 min-w-0">
                    <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 px-2 py-2 min-w-0">
                        <div class="text-[11px] text-white/50">เข้า</div>
                        <div class="text-white font-semibold"><?php echo htmlspecialchars($checkIn); ?></div>
                    </div>
                    <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 px-2 py-2 min-w-0">
                        <div class="text-[11px] text-white/50">ออก</div>
                        <div class="text-white font-semibold"><?php echo htmlspecialchars($checkOut); ?></div>
                    </div>
                    <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 px-2 py-2 min-w-0">
                        <div class="text-[11px] text-white/50">ชม.</div>
                        <div class="text-white font-semibold"><?php echo htmlspecialchars($work); ?></div>
                    </div>
                    <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 px-2 py-2 min-w-0">
                        <div class="text-[11px] text-white/50">OT</div>
                        <div class="text-emerald-300 font-semibold"><?php echo htmlspecialchars($ot); ?></div>
                    </div>
                </div>

                <?php if ($att && !empty($att['shift_name'])): ?>
                <div class="mt-3 text-xs text-white/50">
                    <i class="fas fa-clock mr-1" aria-hidden="true"></i>
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
        <div class="md:hidden px-4 py-8">
            <div class="tp-native-empty-state text-center py-8 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
                <i class="fas fa-calendar-times text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
                <p class="text-slate-400 text-sm">ไม่พบข้อมูลในเดือนนี้</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1">
            <table class="w-full min-w-[720px]">
                <thead>
                    <tr class="border-b border-white/10">
                        <th class="px-4 py-3 text-left text-white/70 text-sm font-medium">วันที่</th>
                        <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">กะ</th>
                        <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">เข้างาน</th>
                        <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">ออกงาน</th>
                        <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">ชม.ทำงาน</th>
                        <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">OT</th>
                        <th class="px-4 py-3 text-center text-white/70 text-sm font-medium">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($allDays): ?>
                        <?php foreach ($allDays as $day): 
                            $att = $day['attendance'];
                            $isDayOff = $day['is_day_off'];
                            $holiday = $day['holiday'];
                            
                            // Determine row style
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
                                                    <i class="fas fa-star text-[10px]" aria-hidden="true"></i>
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
                                    <span class="text-green-400 font-medium">
                                        <?php echo date('H:i', strtotime($att['check_in_time'])); ?>
                                    </span>
                                    <?php if ($att['late_minutes'] > 0): ?>
                                    <span class="text-red-400 text-xs ml-1">(+<?php echo $att['late_minutes']; ?>น.)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-white/30">--:--</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($att && $att['check_out_time']): ?>
                                    <span class="text-blue-400 font-medium">
                                        <?php echo date('H:i', strtotime($att['check_out_time'])); ?>
                                    </span>
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
                                <?php if ($att && $att['ot_minutes'] > 0): ?>
                                    <span class="text-emerald-400">
                                        <?php echo floor($att['ot_minutes'] / 60); ?>:<?php echo str_pad($att['ot_minutes'] % 60, 2, '0', STR_PAD_LEFT); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-white/30">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($holiday): ?>
                                <span class="px-2 py-1 text-xs rounded-[var(--tp-ios-card-radius)] font-medium border border-white/10 bg-orange-500/20 text-orange-400">
                                    <?php echo match($holiday['type']) {
                                        'PUBLIC' => 'วันหยุดราชการ',
                                        'COMPANY' => 'วันหยุดบริษัท',
                                        'SPECIAL' => 'วันหยุดพิเศษ',
                                        'SUBSTITUTE' => 'วันหยุดชดเชย',
                                        default => 'วันหยุด'
                                    }; ?>
                                </span>
                                <?php elseif ($isDayOff): ?>
                                <span class="px-2 py-1 text-xs rounded-[var(--tp-ios-card-radius)] font-medium border border-white/10 bg-blue-500/20 text-blue-400">วันหยุด</span>
                                <?php elseif ($att): ?>
                                <span class="px-2 py-1 text-xs rounded-[var(--tp-ios-card-radius)] font-medium border border-white/10 <?php 
                                    echo match($att['status']) {
                                        'PRESENT' => 'bg-green-500/20 text-green-400',
                                        'LATE' => 'bg-amber-500/20 text-amber-300',
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
                                <span class="px-2 py-1 text-xs rounded-[var(--tp-ios-card-radius)] font-medium border border-white/10 bg-red-500/20 text-red-400">ขาดงาน</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8">
                                <div class="tp-native-empty-state text-center py-8 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-md mx-auto">
                                    <i class="fas fa-calendar-times text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
                                    <p class="text-slate-400 text-sm">ไม่พบข้อมูลในเดือนนี้</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
