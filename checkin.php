<?php
/**
 * TP-HR Check-in/Check-out
 * ลงเวลาเข้า-ออกงาน
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$page_title = 'ลงเวลาเข้า-ออก';
$current_page = 'checkin';

$action = $_GET['action'] ?? '';
$message = '';
$error = '';

// Get today's attendance
$stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
$stmt->execute([$user['id']]);
$today_attendance = $stmt->fetch();

// Get user's shift
$stmt = $pdo->prepare("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
$stmt->execute();
$shift = $stmt->fetch();

// Holiday banner (today)
$today_holiday = null;
try {
    $stmt = $pdo->prepare("SELECT name, type FROM hr_holidays WHERE `date` = CURDATE() AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $today_holiday = $stmt->fetch();
} catch (Throwable $e) {
    // tp-checkin variant — column name "holiday_date"
    try {
        $stmt = $pdo->prepare("SELECT name, type FROM hr_holidays WHERE holiday_date = CURDATE() AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $today_holiday = $stmt->fetch();
    } catch (Throwable $e2) { $today_holiday = null; }
}

// Planned Late Start state (today + tomorrow) — mirror ของ tp-checkin/index.php
$ls_today    = date('Y-m-d');
$ls_tomorrow = date('Y-m-d', strtotime('+1 day'));
$late_start_by_date = [];
$late_start_cutoff_hour = 7;
$late_start_can_today_request = ['ok' => false];
$late_start_can_tomorrow_request = ['ok' => true];
try {
    if (function_exists('ensurePlannedStartTimeColumns')) {
        ensurePlannedStartTimeColumns($pdo);
    }
    $ls_stmt = $pdo->prepare("
        SELECT attendance_date, planned_start_time, planned_reason, planned_requested_at
        FROM hr_attendances
        WHERE user_id = ? AND attendance_date IN (?, ?)
    ");
    $ls_stmt->execute([$user['id'], $ls_today, $ls_tomorrow]);
    foreach ($ls_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $late_start_by_date[$r['attendance_date']] = $r;
    }
    if (function_exists('lateRequestCutoffHour'))  $late_start_cutoff_hour       = lateRequestCutoffHour($pdo);
    if (function_exists('canRequestLateStart'))   {
        $late_start_can_today_request    = canRequestLateStart($pdo, $ls_today);
        $late_start_can_tomorrow_request = canRequestLateStart($pdo, $ls_tomorrow);
    }
} catch (Throwable $e) {
    error_log('tp-hr checkin.php late-start load: ' . $e->getMessage());
}
$ls_today_row    = $late_start_by_date[$ls_today]    ?? null;
$ls_tomorrow_row = $late_start_by_date[$ls_tomorrow] ?? null;
$ls_has_any      = $ls_today_row || $ls_tomorrow_row;

// Get allowed check-in locations
$stmt = $pdo->query("SELECT * FROM hr_checkin_locations WHERE is_active = 1");
$locations = $stmt->fetchAll();

// Get attendance history (last 7 days)
$stmt = $pdo->prepare("
    SELECT a.*, s.name as shift_name, s.start_time as shift_start, s.end_time as shift_end
    FROM hr_attendances a
    LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
    WHERE a.user_id = ? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY a.attendance_date DESC
");
$stmt->execute([$user['id']]);
$attendance_history = $stmt->fetchAll();

// Build holiday map for last 7 days (สำหรับแสดง marker ใน history)
$holidayMap = [];
try {
    $hstmt = $pdo->prepare("SELECT `date` as d, name, type FROM hr_holidays WHERE `date` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND is_active = 1");
    $hstmt->execute();
    foreach ($hstmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $holidayMap[$h['d']] = $h;
    }
} catch (Throwable $e) {
    try {
        $hstmt = $pdo->prepare("SELECT holiday_date as d, name, type FROM hr_holidays WHERE holiday_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND is_active = 1");
        $hstmt->execute();
        foreach ($hstmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $holidayMap[$h['d']] = $h;
        }
    } catch (Throwable $e2) { $holidayMap = []; }
}

// Calculate this month's summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status IN ('PRESENT', 'LATE') THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'LATE' THEN 1 END) as late_days,
        COUNT(CASE WHEN status = 'ABSENT' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'LEAVE' THEN 1 END) as leave_days,
        SUM(COALESCE(work_minutes, 0)) as total_work_minutes,
        SUM(COALESCE(ot_minutes, 0)) as total_ot_minutes,
        SUM(COALESCE(late_minutes, 0)) as total_late_minutes
    FROM hr_attendances 
    WHERE user_id = ? AND MONTH(attendance_date) = MONTH(CURDATE()) AND YEAR(attendance_date) = YEAR(CURDATE())
");
$stmt->execute([$user['id']]);
$monthly_summary = $stmt->fetch();

require_once __DIR__ . '/templates/header.php';
?>

<div>
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">ลงเวลาเข้า-ออกงาน</h1>
        <p class="text-white/60 mt-1"><?php echo formatDateThai(date('Y-m-d')); ?></p>
    </div>
    
    <?php if ($error): ?>
    <div class="bg-red-500/20 border border-red-500/50 text-red-300 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
    <div class="bg-green-500/20 border border-green-500/50 text-green-300 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($today_holiday): ?>
    <div class="mb-6 rounded-xl bg-gradient-to-r from-rose-500/20 to-pink-500/15 border border-rose-400/30 px-4 py-3 flex items-center gap-3">
        <i class="fas fa-umbrella-beach text-rose-300 text-xl"></i>
        <div>
            <p class="text-rose-200 font-semibold">วันหยุด: <?php echo htmlspecialchars($today_holiday['name']); ?></p>
            <p class="text-rose-100/70 text-xs">
                <?php echo htmlspecialchars($today_holiday['type'] ?? ''); ?>
                &middot; หากต้องทำงานวันนี้ จะถูกนับเป็น OT โดยอัตโนมัติ
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Check-in Card -->
        <div class="lg:col-span-2">
            <div class="glass-card rounded-xl p-6">
                <!-- Current Time Display -->
                <div class="text-center mb-8">
                    <div class="text-6xl font-bold text-white mb-2" id="current-time">--:--:--</div>
                    <p class="text-white/60"><?php echo formatDateThai(date('Y-m-d')); ?></p>
                    
                    <div class="mt-4 flex items-center justify-center gap-2 flex-wrap">
                        <?php if ($shift): ?>
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10">
                            <i class="fas fa-clock text-violet-400"></i>
                            <span class="text-white"><?php echo htmlspecialchars(function_exists('shift_base_name') ? shift_base_name($shift['name']) : $shift['name']); ?></span>
                            <span class="text-white/60">
                                (<?php echo substr($shift['start_time'], 0, 5); ?> - <?php echo substr($shift['end_time'], 0, 5); ?>)
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (($user['work_mode'] ?? 'OFFICE') === 'WFH'): ?>
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/20 border border-emerald-400/30">
                            <i class="fas fa-home text-emerald-300"></i>
                            <span class="text-emerald-200 text-sm font-semibold">WFH</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
                // Check pending outside-location request (today)
                $pending_in  = null;
                $pending_out = null;
                try {
                    $pstmt = $pdo->prepare("SELECT id, request_type, status FROM hr_attendance_outside_requests WHERE user_id = ? AND request_date = CURDATE() AND status = 'PENDING'");
                    $pstmt->execute([$user['id']]);
                    foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                        if ($pr['request_type'] === 'CHECK_IN')  $pending_in  = $pr;
                        if ($pr['request_type'] === 'CHECK_OUT') $pending_out = $pr;
                    }
                } catch (Throwable $e) { /* table may not exist yet */ }
                ?>
                <!-- Today's Status -->
                <div class="grid grid-cols-2 gap-4 mb-8">
                    <div class="p-4 rounded-lg bg-white/5 text-center">
                        <p class="text-white/60 text-sm mb-1">เวลาเข้างาน</p>
                        <p class="text-2xl font-bold <?php echo $today_attendance && $today_attendance['check_in_time'] ? 'text-green-400' : 'text-white/30'; ?>">
                            <?php
                            if ($today_attendance && $today_attendance['check_in_time']) {
                                echo date('H:i', strtotime($today_attendance['check_in_time']));
                            } else {
                                echo '--:--';
                            }
                            ?>
                        </p>
                        <?php if ($today_attendance && $today_attendance['late_minutes'] > 0): ?>
                        <p class="text-red-400 text-sm mt-1">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            สาย <?php echo $today_attendance['late_minutes']; ?> นาที
                        </p>
                        <?php elseif ($today_attendance && $today_attendance['check_in_time'] && empty($today_attendance['check_in_location_id'])): ?>
                        <p class="text-amber-300 text-xs mt-1">
                            <i class="fas fa-map-pin mr-1"></i>นอกสถานที่
                        </p>
                        <?php endif; ?>
                        <?php if ($pending_in): ?>
                        <p class="text-yellow-300 text-xs mt-1 bg-yellow-500/10 rounded px-2 py-0.5 inline-block">
                            <i class="fas fa-hourglass-half mr-1"></i>รออนุมัติ (นอกสถานที่)
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="p-4 rounded-lg bg-white/5 text-center">
                        <p class="text-white/60 text-sm mb-1">เวลาออกงาน</p>
                        <p class="text-2xl font-bold <?php echo $today_attendance && $today_attendance['check_out_time'] ? 'text-blue-400' : 'text-white/30'; ?>">
                            <?php
                            if ($today_attendance && $today_attendance['check_out_time']) {
                                echo date('H:i', strtotime($today_attendance['check_out_time']));
                            } else {
                                echo '--:--';
                            }
                            ?>
                        </p>
                        <?php if ($today_attendance && $today_attendance['work_minutes'] > 0): ?>
                        <p class="text-white/60 text-sm mt-1">
                            ทำงาน <?php echo floor($today_attendance['work_minutes'] / 60); ?> ชม. <?php echo $today_attendance['work_minutes'] % 60; ?> น.
                        </p>
                        <?php endif; ?>
                        <?php if ($pending_out): ?>
                        <p class="text-yellow-300 text-xs mt-1 bg-yellow-500/10 rounded px-2 py-0.5 inline-block">
                            <i class="fas fa-hourglass-half mr-1"></i>รออนุมัติ (นอกสถานที่)
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Check-in/Check-out Buttons -->
                <div class="flex flex-col items-center gap-4">
                    <?php if (!$today_attendance || !$today_attendance['check_in_time']): ?>
                        <!-- Check-in Button -->
                        <button id="btn-checkin" 
                                onclick="startCheckin('in')"
                                class="w-48 h-48 rounded-full bg-gradient-to-br from-green-500 to-green-600 hover:from-green-400 hover:to-green-500 text-white shadow-lg shadow-green-500/30 transition-all hover:scale-105 flex flex-col items-center justify-center">
                            <i class="fas fa-fingerprint text-5xl mb-2"></i>
                            <span class="text-xl font-bold">ลงเวลาเข้า</span>
                        </button>
                        
                    <?php elseif (!$today_attendance['check_out_time']): ?>
                        <!-- Check-out Button -->
                        <button id="btn-checkout"
                                onclick="startCheckin('out')"
                                class="w-48 h-48 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 hover:from-blue-400 hover:to-blue-500 text-white shadow-lg shadow-blue-500/30 transition-all hover:scale-105 flex flex-col items-center justify-center">
                            <i class="fas fa-sign-out-alt text-5xl mb-2"></i>
                            <span class="text-xl font-bold">ลงเวลาออก</span>
                        </button>
                        
                    <?php else: ?>
                        <!-- All Done -->
                        <div class="w-48 h-48 rounded-full bg-gradient-to-br from-gray-600 to-gray-700 text-white flex flex-col items-center justify-center">
                            <i class="fas fa-check-circle text-5xl mb-2 text-green-400"></i>
                            <span class="text-lg font-medium">ลงเวลาครบแล้ว</span>
                        </div>
                    <?php endif; ?>
                    
                    <p class="text-white/50 text-sm" id="location-status">
                        <i class="fas fa-location-arrow mr-1"></i>
                        กำลังตรวจสอบตำแหน่ง...
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Summary & History -->
        <div class="space-y-6">
            <!-- Planned Late Start (แจ้งเข้างานสายล่วงหน้า) -->
            <?php if ($ls_has_any): ?>
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-semibold text-white">
                        <i class="fas fa-clock text-amber-400 mr-2"></i>แจ้งเข้างานสาย
                    </h2>
                    <span class="text-white/40 text-xs"><?php echo ($ls_today_row ? 1 : 0) + ($ls_tomorrow_row ? 1 : 0); ?> รายการ</span>
                </div>
                <div class="space-y-3">
                    <?php if ($ls_today_row): ?>
                    <div class="rounded-2xl bg-gradient-to-br from-amber-500/15 to-orange-500/10 border border-amber-400/30 p-4">
                        <p class="text-amber-300 text-xs font-semibold uppercase tracking-wide mb-1">
                            <i class="fas fa-sun mr-1"></i>วันนี้ · <?php echo date('d M', strtotime($ls_today)); ?>
                        </p>
                        <p class="text-white text-2xl font-bold tracking-tight">
                            <?php echo date('H:i', strtotime($ls_today_row['planned_start_time'])); ?>
                            <span class="text-white/50 text-sm font-normal">น.</span>
                        </p>
                        <?php if (!empty($ls_today_row['planned_reason'])): ?>
                        <p class="text-white/70 text-xs mt-1 line-clamp-2"><?php echo htmlspecialchars($ls_today_row['planned_reason']); ?></p>
                        <?php endif; ?>
                        <button type="button" onclick="cancelLateStart('<?php echo $ls_today; ?>')"
                                class="mt-3 w-full py-2 rounded-xl bg-red-500/15 hover:bg-red-500/25 border border-red-400/30 text-red-300 text-sm font-semibold transition-colors">
                            <i class="fas fa-times-circle mr-1"></i>ยกเลิกการแจ้ง
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($ls_tomorrow_row): ?>
                    <div class="rounded-2xl bg-gradient-to-br from-blue-500/15 to-indigo-500/10 border border-blue-400/30 p-4">
                        <p class="text-blue-300 text-xs font-semibold uppercase tracking-wide mb-1">
                            <i class="fas fa-moon mr-1"></i>พรุ่งนี้ · <?php echo date('d M', strtotime($ls_tomorrow)); ?>
                        </p>
                        <p class="text-white text-2xl font-bold tracking-tight">
                            <?php echo date('H:i', strtotime($ls_tomorrow_row['planned_start_time'])); ?>
                            <span class="text-white/50 text-sm font-normal">น.</span>
                        </p>
                        <?php if (!empty($ls_tomorrow_row['planned_reason'])): ?>
                        <p class="text-white/70 text-xs mt-1 line-clamp-2"><?php echo htmlspecialchars($ls_tomorrow_row['planned_reason']); ?></p>
                        <?php endif; ?>
                        <button type="button" onclick="cancelLateStart('<?php echo $ls_tomorrow; ?>')"
                                class="mt-3 w-full py-2 rounded-xl bg-red-500/15 hover:bg-red-500/25 border border-red-400/30 text-red-300 text-sm font-semibold transition-colors">
                            <i class="fas fa-times-circle mr-1"></i>ยกเลิกการแจ้ง
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if (!($ls_today_row && $ls_tomorrow_row)): ?>
                    <button type="button" onclick="openLateStartModal()"
                            class="w-full py-2.5 rounded-xl bg-white/5 hover:bg-white/10 border border-dashed border-white/20 text-white/70 text-sm font-medium transition-colors">
                        <i class="fas fa-plus-circle mr-1"></i>เพิ่มการแจ้งอีกวัน
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <button type="button" onclick="openLateStartModal()"
                    class="group w-full relative overflow-hidden rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/15 hover:from-amber-500/30 hover:to-orange-500/25 active:from-amber-500/40 active:to-orange-500/35 border border-amber-400/30 hover:border-amber-400/60 p-5 text-left transition-all duration-200 shadow-lg shadow-amber-500/5">
                <span class="absolute -right-6 -top-6 w-24 h-24 rounded-full bg-amber-400/10 blur-2xl group-hover:bg-amber-400/20 transition-colors"></span>
                <div class="relative flex items-center gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center shadow-lg shadow-amber-500/30 group-hover:scale-105 transition-transform">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-white font-bold text-base leading-tight mb-0.5 flex items-center gap-2">
                            แจ้งเข้างานสาย
                            <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-400/25 text-amber-200">ล่วงหน้า</span>
                        </h2>
                        <p class="text-white/70 text-xs leading-snug">
                            ทำงานดึก? แจ้งก่อน <?php echo sprintf('%02d:00', $late_start_cutoff_hour); ?> น. ของวันทำงาน
                        </p>
                    </div>
                    <div class="shrink-0 w-8 h-8 rounded-full bg-white/10 group-hover:bg-white/20 flex items-center justify-center transition-colors">
                        <i class="fas fa-chevron-right text-white text-xs"></i>
                    </div>
                </div>
            </button>
            <?php endif; ?>

            <!-- Quick links -->
            <div class="grid grid-cols-2 gap-3">
                <a href="attendance_history.php" class="flex items-center gap-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 px-3 py-2.5 text-white/80 hover:text-white text-sm transition-colors">
                    <i class="fas fa-history text-violet-400"></i>ประวัติเข้างาน
                </a>
                <a href="leave.php" class="flex items-center gap-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 px-3 py-2.5 text-white/80 hover:text-white text-sm transition-colors">
                    <i class="fas fa-calendar-check text-emerald-400"></i>ขอลา / OT
                </a>
            </div>

            <!-- Monthly Summary -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-chart-pie text-violet-400 mr-2"></i>
                    สรุปเดือนนี้
                </h2>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">มาทำงาน</span>
                        <span class="text-green-400 font-medium"><?php echo $monthly_summary['present_days'] ?? 0; ?> วัน</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">มาสาย</span>
                        <span class="text-yellow-400 font-medium"><?php echo $monthly_summary['late_days'] ?? 0; ?> ครั้ง</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">ลา</span>
                        <span class="text-blue-400 font-medium"><?php echo $monthly_summary['leave_days'] ?? 0; ?> วัน</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">ขาดงาน</span>
                        <span class="text-red-400 font-medium"><?php echo $monthly_summary['absent_days'] ?? 0; ?> วัน</span>
                    </div>
                    
                    <div class="pt-3 mt-3 border-t border-white/10">
                        <div class="flex justify-between items-center">
                            <span class="text-white/70">ชั่วโมงทำงาน</span>
                            <span class="text-white font-medium">
                                <?php 
                                $totalMinutes = $monthly_summary['total_work_minutes'] ?? 0;
                                echo floor($totalMinutes / 60) . ' ชม.';
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-white/70">OT</span>
                            <span class="text-emerald-400 font-medium">
                                <?php 
                                $otMinutes = $monthly_summary['total_ot_minutes'] ?? 0;
                                echo floor($otMinutes / 60) . ' ชม. ' . ($otMinutes % 60) . ' น.';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent History -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-history text-blue-400 mr-2"></i>
                    ประวัติ 7 วันล่าสุด
                </h2>
                
                <?php if ($attendance_history): ?>
                <div class="space-y-2">
                    <?php foreach ($attendance_history as $att):
                        $attDate = $att['attendance_date'];
                        $hol = $holidayMap[$attDate] ?? null;
                    ?>
                    <div class="p-3 rounded-lg bg-white/5 flex items-center justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-white text-sm truncate">
                                    <?php echo formatDateThai($attDate); ?>
                                </p>
                                <?php if ($hol): ?>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-400/30" title="<?php echo htmlspecialchars($hol['name']); ?>">
                                    <i class="fas fa-umbrella-beach"></i>
                                </span>
                                <?php endif; ?>
                                <?php if ($att['status'] === 'WFH' || (!empty($att['check_in_time']) && empty($att['check_in_location_id']))): ?>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300" title="นอกสถานที่/WFH">
                                    <i class="fas fa-home"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-white/50 text-xs">
                                <?php
                                echo $att['check_in_time']  ? date('H:i', strtotime($att['check_in_time']))  : '--:--';
                                echo ' - ';
                                echo $att['check_out_time'] ? date('H:i', strtotime($att['check_out_time'])) : '--:--';
                                if (!empty($att['late_minutes']) && (int)$att['late_minutes'] > 0) {
                                    echo ' <span class="text-amber-300">(สาย ' . (int)$att['late_minutes'] . ' น.)</span>';
                                }
                                ?>
                            </p>
                            <?php if ($hol): ?>
                            <p class="text-rose-300/80 text-[10px] mt-0.5 truncate"><?php echo htmlspecialchars($hol['name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="shrink-0 px-2 py-1 text-xs rounded <?php
                            echo match($att['status']) {
                                'PRESENT' => 'bg-green-500/20 text-green-400',
                                'LATE'    => 'bg-yellow-500/20 text-yellow-400',
                                'ABSENT'  => 'bg-red-500/20 text-red-400',
                                'LEAVE'   => 'bg-blue-500/20 text-blue-400',
                                'HOLIDAY' => 'bg-gray-500/20 text-gray-400',
                                'WFH'     => 'bg-emerald-500/20 text-emerald-400',
                                default   => 'bg-gray-500/20 text-gray-400'
                            };
                        ?>">
                            <?php echo ATTENDANCE_STATUS[$att['status']] ?? $att['status']; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-white/50 text-center py-4">ยังไม่มีประวัติ</p>
                <?php endif; ?>
                
                <a href="attendance_history.php" class="block text-center text-violet-400 hover:text-violet-300 text-sm mt-4">
                    ดูประวัติทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Planned Late Start Modal -->
<div id="late-start-modal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl p-5 w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-white text-lg font-bold flex items-center gap-2">
                <i class="fas fa-clock text-amber-400"></i>แจ้งเข้างานสายล่วงหน้า
            </h3>
            <button onclick="closeLateStartModal()" class="text-white/60 hover:text-white p-1">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Target Date -->
        <div class="mb-4">
            <label class="text-white/70 text-sm mb-2 block">แจ้งสำหรับวันไหน</label>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex items-center justify-center p-3 rounded-xl bg-white/5 border border-white/10 cursor-pointer has-[:checked]:bg-amber-500/20 has-[:checked]:border-amber-400 transition-colors">
                    <input type="radio" name="ls-target" value="tomorrow" class="hidden" onchange="updateLateStartDateLabel()" <?php echo $late_start_can_tomorrow_request['ok'] ? 'checked' : ''; ?>>
                    <div class="text-center">
                        <div class="text-white font-medium">พรุ่งนี้</div>
                        <div class="text-white/50 text-xs mt-0.5">แจ้งล่วงหน้า</div>
                    </div>
                </label>
                <label class="flex items-center justify-center p-3 rounded-xl bg-white/5 border border-white/10 <?php echo $late_start_can_today_request['ok'] ? 'cursor-pointer has-[:checked]:bg-amber-500/20 has-[:checked]:border-amber-400' : 'opacity-40 cursor-not-allowed'; ?> transition-colors">
                    <input type="radio" name="ls-target" value="today" class="hidden" onchange="updateLateStartDateLabel()" <?php echo $late_start_can_today_request['ok'] ? '' : 'disabled'; ?>>
                    <div class="text-center">
                        <div class="text-white font-medium">วันนี้</div>
                        <div class="text-white/50 text-xs mt-0.5">ก่อน <?php echo sprintf('%02d:00', $late_start_cutoff_hour); ?></div>
                    </div>
                </label>
            </div>
            <p class="text-amber-300 text-xs mt-2">
                <i class="fas fa-calendar-day mr-1"></i><span id="ls-date-label">—</span>
            </p>
        </div>

        <!-- Time Picker -->
        <div class="mb-4">
            <label class="text-white/70 text-sm mb-2 block">เวลาที่จะเข้างาน</label>
            <input type="time" id="ls-planned-time" step="900"
                   min="<?php echo htmlspecialchars(substr(($shift['start_time'] ?? '08:30'), 0, 5)); ?>"
                   value="<?php echo htmlspecialchars(substr(($shift['start_time'] ?? '08:30'), 0, 5)); ?>"
                   class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white text-lg font-medium focus:outline-none focus:border-amber-400">
            <p class="text-white/40 text-xs mt-1">
                หลังเข้าในเวลาที่แจ้ง ± 30 นาที จะไม่ถูกหัก
            </p>
        </div>

        <!-- Reason -->
        <div class="mb-5">
            <label class="text-white/70 text-sm mb-2 block">เหตุผล <span class="text-red-400">*</span></label>
            <textarea id="ls-reason" rows="3" maxlength="255"
                      placeholder="เช่น ทำงานดึกเมื่อคืน / มีนัดหมอ / ติดธุระส่วนตัว"
                      class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white text-sm placeholder-white/30 focus:outline-none focus:border-amber-400 resize-none"></textarea>
            <p class="text-white/40 text-xs mt-1">อย่างน้อย 5 ตัวอักษร</p>
        </div>

        <!-- Submit -->
        <div class="flex gap-2">
            <button type="button" onclick="closeLateStartModal()"
                    class="flex-1 py-3 bg-white/10 hover:bg-white/15 text-white rounded-xl font-medium transition-colors">
                ยกเลิก
            </button>
            <button type="button" onclick="submitLateStart()"
                    class="flex-1 py-3 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white rounded-xl font-medium transition-colors">
                <i class="fas fa-paper-plane mr-2"></i>ส่งคำขอ
            </button>
        </div>
    </div>
</div>

<!-- Off-site Reason Modal -->
<div id="offsite-modal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl p-6 w-full max-w-md">
        <div class="text-center mb-4">
            <div class="w-16 h-16 mx-auto mb-3 rounded-full bg-yellow-500/20 flex items-center justify-center">
                <i class="fas fa-map-marker-alt text-yellow-400 text-3xl"></i>
            </div>
            <h3 class="text-white text-lg font-bold mb-1">คุณอยู่นอกพื้นที่ที่อนุญาต</h3>
            <p class="text-white/60 text-sm" id="offsite-info">การลงเวลานอกพื้นที่ต้องระบุเหตุผลและรอผู้บังคับบัญชาอนุมัติ</p>
        </div>

        <div class="mb-4">
            <label class="block text-white/70 text-sm mb-2">
                <i class="fas fa-pen mr-1"></i>เหตุผล <span class="text-red-400">*</span>
            </label>
            <textarea id="offsite-reason" rows="3" maxlength="500"
                      placeholder="เช่น ไปพบลูกค้า, ประชุมนอกสำนักงาน, ทำงานนอกสถานที่"
                      class="w-full bg-white/10 border border-white/20 rounded-xl px-4 py-3 text-white text-sm placeholder-white/40 focus:outline-none focus:border-yellow-400 resize-none"></textarea>
            <p class="text-white/40 text-xs mt-1">อย่างน้อย 5 ตัวอักษร</p>
        </div>

        <div class="flex gap-3">
            <button type="button" onclick="closeOffsiteModal()" class="flex-1 py-3 bg-white/10 hover:bg-white/20 text-white rounded-xl font-medium transition-colors">
                ยกเลิก
            </button>
            <button type="button" onclick="submitOffsite()" class="flex-1 py-3 bg-yellow-500 hover:bg-yellow-600 text-white rounded-xl font-medium transition-colors">
                <i class="fas fa-paper-plane mr-1"></i>ส่งคำขอ
            </button>
        </div>
    </div>
</div>

<!-- Check-in Modal -->
<div id="checkin-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl p-6 w-full max-w-md">
        <div class="text-center mb-6">
            <div class="w-20 h-20 rounded-full bg-violet-600/20 flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-camera text-violet-400 text-3xl" id="modal-icon"></i>
            </div>
            <h3 class="text-xl font-bold text-white" id="modal-title">ลงเวลาเข้างาน</h3>
            <p class="text-white/60 text-sm mt-1" id="modal-subtitle">ถ่ายรูปเพื่อยืนยันตัวตน</p>
        </div>
        
        <!-- Camera Preview -->
        <div class="relative mb-6 rounded-xl overflow-hidden bg-black aspect-video" id="camera-container">
            <video id="camera-preview" class="w-full h-full object-cover" autoplay playsinline></video>
            <canvas id="camera-canvas" class="hidden"></canvas>
            <img id="captured-photo" class="w-full h-full object-cover hidden">
            
            <div id="camera-loading" class="absolute inset-0 flex items-center justify-center bg-black/80">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin text-white text-2xl mb-2"></i>
                    <p class="text-white/60 text-sm">กำลังเปิดกล้อง...</p>
                </div>
            </div>
        </div>
        
        <!-- Location Info -->
        <div class="p-3 rounded-lg bg-white/5 mb-6" id="location-info">
            <div class="flex items-center gap-2 text-white/70">
                <i class="fas fa-map-marker-alt text-red-400"></i>
                <span id="location-text">กำลังระบุตำแหน่ง...</span>
            </div>
            <input type="hidden" id="latitude" value="">
            <input type="hidden" id="longitude" value="">
        </div>
        
        <!-- Buttons -->
        <div class="flex gap-3">
            <button type="button" onclick="closeCheckinModal()" class="flex-1 py-3 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">
                ยกเลิก
            </button>
            <button type="button" id="btn-capture" onclick="capturePhoto()" class="flex-1 py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">
                <i class="fas fa-camera mr-2"></i>ถ่ายรูป
            </button>
            <button type="button" id="btn-confirm" onclick="confirmCheckin()" class="flex-1 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors hidden">
                <i class="fas fa-check mr-2"></i>ยืนยัน
            </button>
        </div>
    </div>
</div>

<script>
// Current time display
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    document.getElementById('current-time').textContent = time;
}
setInterval(updateClock, 1000);
updateClock();

// Variables
let checkinType = '';
let stream = null;
let photoData = null;
let userLatitude = null;
let userLongitude = null;

// Get user location on page load
navigator.geolocation.getCurrentPosition(
    (position) => {
        userLatitude = position.coords.latitude;
        userLongitude = position.coords.longitude;
        document.getElementById('location-status').innerHTML = '<i class="fas fa-check-circle text-green-400 mr-1"></i> พร้อมลงเวลา';
    },
    (error) => {
        document.getElementById('location-status').innerHTML = '<i class="fas fa-exclamation-triangle text-yellow-400 mr-1"></i> ไม่สามารถระบุตำแหน่งได้';
    },
    { enableHighAccuracy: true }
);

// Start check-in process
function startCheckin(type) {
    checkinType = type;
    
    // Update modal content
    const modal = document.getElementById('checkin-modal');
    const title = document.getElementById('modal-title');
    const icon = document.getElementById('modal-icon');
    
    if (type === 'in') {
        title.textContent = 'ลงเวลาเข้างาน';
        icon.className = 'fas fa-sign-in-alt text-green-400 text-3xl';
    } else {
        title.textContent = 'ลงเวลาออกงาน';
        icon.className = 'fas fa-sign-out-alt text-blue-400 text-3xl';
    }
    
    modal.classList.remove('hidden');
    startCamera();
    getLocation();
}

// Close modal
function closeCheckinModal() {
    const modal = document.getElementById('checkin-modal');
    modal.classList.add('hidden');
    stopCamera();
    resetModal();
}

// Reset modal state
function resetModal() {
    photoData = null;
    document.getElementById('captured-photo').classList.add('hidden');
    document.getElementById('camera-preview').classList.remove('hidden');
    document.getElementById('btn-capture').classList.remove('hidden');
    document.getElementById('btn-confirm').classList.add('hidden');
}

// Start camera
async function startCamera() {
    const video = document.getElementById('camera-preview');
    const loading = document.getElementById('camera-loading');
    
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        video.srcObject = stream;
        loading.style.display = 'none';
    } catch (err) {
        console.error('Camera error:', err);
        loading.innerHTML = '<div class="text-center"><i class="fas fa-exclamation-circle text-red-400 text-2xl mb-2"></i><p class="text-white/60 text-sm">ไม่สามารถเปิดกล้องได้</p></div>';
    }
}

// Stop camera
function stopCamera() {
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
}

// Get location
function getLocation() {
    const locationText = document.getElementById('location-text');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    
    navigator.geolocation.getCurrentPosition(
        (position) => {
            userLatitude = position.coords.latitude;
            userLongitude = position.coords.longitude;
            latInput.value = userLatitude;
            lngInput.value = userLongitude;
            locationText.innerHTML = `<span class="text-green-400"><i class="fas fa-check-circle mr-1"></i>ระบุตำแหน่งสำเร็จ</span>`;
        },
        (error) => {
            locationText.innerHTML = `<span class="text-yellow-400"><i class="fas fa-exclamation-triangle mr-1"></i>ไม่สามารถระบุตำแหน่งได้</span>`;
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

// Capture photo
function capturePhoto() {
    const video = document.getElementById('camera-preview');
    const canvas = document.getElementById('camera-canvas');
    const photo = document.getElementById('captured-photo');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);
    
    // Add timestamp overlay
    ctx.fillStyle = 'rgba(0,0,0,0.5)';
    ctx.fillRect(0, canvas.height - 40, canvas.width, 40);
    ctx.fillStyle = 'white';
    ctx.font = '16px Sarabun';
    ctx.fillText(new Date().toLocaleString('th-TH'), 10, canvas.height - 15);
    
    photoData = canvas.toDataURL('image/jpeg', 0.8);
    photo.src = photoData;
    
    // Show photo, hide video
    video.classList.add('hidden');
    photo.classList.remove('hidden');
    
    // Switch buttons
    document.getElementById('btn-capture').classList.add('hidden');
    document.getElementById('btn-confirm').classList.remove('hidden');
    
    stopCamera();
}

// Confirm check-in (รองรับ outside_reason retry flow)
async function confirmCheckin(outsideReason = null) {
    const btn = document.getElementById('btn-confirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';

    try {
        const body = {
            action: checkinType === 'in' ? 'check_in' : 'check_out',
            latitude: userLatitude,
            longitude: userLongitude,
            photo: photoData,
        };
        if (outsideReason) body.outside_reason = outsideReason;

        const response = await fetch('/api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const result = await response.json();

        if (result.success) {
            const isPending = !!(result.data && result.data.pending_approval);
            closeCheckinModal();
            if (isPending) {
                showToast(result.message || 'ส่งคำขอเรียบร้อย รอผู้อนุมัติ', 'success');
            } else {
                showToast(checkinType === 'in' ? 'ลงเวลาเข้างานสำเร็จ' : 'ลงเวลาออกงานสำเร็จ', 'success');
            }
            setTimeout(() => location.reload(), 1500);
            return;
        }

        // Error paths
        if (result.data && result.data.requires_outside_reason) {
            // ปิด checkin modal ก่อน แล้วเปิด offsite modal (retry)
            closeCheckinModal();
            openOffsiteModal(result.error);
            return;
        }
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-2"></i>ยืนยัน';
    } catch (err) {
        console.error('Check-in error:', err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-2"></i>ยืนยัน';
    }
}

/* Off-site reason modal — retry flow when user is outside geofence */
function openOffsiteModal(infoMsg = null) {
    if (infoMsg) document.getElementById('offsite-info').textContent = infoMsg;
    document.getElementById('offsite-reason').value = '';
    const modal = document.getElementById('offsite-modal');
    modal.classList.remove('hidden');
    setTimeout(() => document.getElementById('offsite-reason').focus(), 300);
}
function closeOffsiteModal() {
    document.getElementById('offsite-modal').classList.add('hidden');
}
function submitOffsite() {
    const reason = document.getElementById('offsite-reason').value.trim();
    if (reason.length < 5) {
        showToast('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร', 'error');
        document.getElementById('offsite-reason').focus();
        return;
    }
    closeOffsiteModal();
    // Re-submit with reason — photoData still in memory
    confirmCheckin(reason);
}

/* =====================================================
 * Planned Late Start — mirror ของ tp-checkin/index.php
 * ===================================================== */
function openLateStartModal() {
    const modal = document.getElementById('late-start-modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    updateLateStartDateLabel();
}
function closeLateStartModal() {
    const modal = document.getElementById('late-start-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.getElementById('ls-reason').value = '';
}
function updateLateStartDateLabel() {
    const target = document.querySelector('input[name="ls-target"]:checked')?.value || 'tomorrow';
    const now = new Date();
    if (target === 'today') {
        const d = now;
        document.getElementById('ls-date-label').textContent = d.toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + ' (วันนี้)';
    } else {
        const d = new Date(now.getTime() + 86400000);
        document.getElementById('ls-date-label').textContent = d.toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + ' (พรุ่งนี้)';
    }
}
async function submitLateStart() {
    const target = document.querySelector('input[name="ls-target"]:checked')?.value || 'tomorrow';
    const planned_time = document.getElementById('ls-planned-time').value;
    const reason = document.getElementById('ls-reason').value.trim();

    if (!planned_time) { showToast('กรุณาเลือกเวลาที่จะเข้างาน', 'error'); return; }
    if (reason.length < 5) { showToast('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร', 'error'); return; }

    const now = new Date();
    const targetDate = (target === 'today')
        ? now.toISOString().slice(0, 10)
        : new Date(now.getTime() + 86400000).toISOString().slice(0, 10);

    try {
        const resp = await fetch('/api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'request_late_start',
                target_date: targetDate,
                planned_start_time: planned_time,
                reason: reason,
            }),
        });
        const data = await resp.json();
        if (data.success) {
            showToast(data.message || 'แจ้งเข้างานสายเรียบร้อย', 'success');
            closeLateStartModal();
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error('submitLateStart:', err);
        showToast('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error');
    }
}
async function cancelLateStart(targetDate) {
    if (!confirm('ยกเลิกการแจ้งเข้างานสายของ ' + targetDate + ' ?')) return;
    try {
        const resp = await fetch('/api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_late_start', target_date: targetDate }),
        });
        const data = await resp.json();
        if (data.success) {
            showToast(data.message || 'ยกเลิกเรียบร้อย', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error('cancelLateStart:', err);
        showToast('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error');
    }
}
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
