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
    <div class="mb-4 md:mb-6">
        <h1 class="text-2xl font-bold text-white tracking-tight">ลงเวลาเข้า-ออกงาน</h1>
        <p class="text-white/60 text-sm mt-1"><?php echo formatDateThai(date('Y-m-d')); ?></p>
    </div>
    
    <?php if ($error): ?>
    <div class="tp-native-error-state bg-red-500/15 border border-red-400/45 text-red-200 px-4 py-3 rounded-[20px] mb-4 md:mb-6 flex items-start gap-3" role="alert">
        <i class="fas fa-exclamation-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
        <span class="text-base leading-snug"><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
    <div class="tp-native-success-state bg-emerald-500/15 border border-emerald-400/40 text-emerald-200 px-4 py-3 rounded-[20px] mb-4 md:mb-6 flex items-start gap-3" role="status">
        <i class="fas fa-check-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
        <span class="text-base leading-snug"><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($today_holiday): ?>
    <div class="mb-4 md:mb-6 rounded-[20px] border border-rose-400/35 bg-rose-500/10 px-4 py-3 flex items-start gap-3">
        <i class="fas fa-umbrella-beach text-rose-300 text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
        <div>
            <p class="text-rose-200 font-semibold">วันหยุด: <?php echo htmlspecialchars($today_holiday['name']); ?></p>
            <p class="text-rose-100/70 text-xs">
                <?php echo htmlspecialchars($today_holiday['type'] ?? ''); ?>
                &middot; หากต้องทำงานวันนี้ จะถูกนับเป็น OT โดยอัตโนมัติ
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 md:gap-6">
        <!-- Left Column: Check-in Card -->
        <div class="xl:col-span-2">
            <div class="native-card tp-native-card tp-native-data-card">
                <!-- Current Time Display -->
                <div class="text-center mb-6 md:mb-8">
                    <div class="text-[48px] leading-none font-bold text-white mb-2 tabular-nums time-display" id="current-time">--:--:--</div>
                    <p class="text-white/60 text-base"><?php echo formatDateThai(date('Y-m-d')); ?></p>
                    
                    <div class="mt-4 flex items-center justify-center gap-2 flex-wrap">
                        <?php if ($shift): ?>
                        <div class="inline-flex items-center gap-2 min-h-[48px] px-4 py-2 rounded-[20px] bg-white/10 border border-white/10">
                            <i class="fas fa-clock text-violet-400 text-2xl" aria-hidden="true"></i>
                            <span class="text-white"><?php echo htmlspecialchars(function_exists('shift_base_name') ? shift_base_name($shift['name']) : $shift['name']); ?></span>
                            <span class="text-white/60">
                                (<?php echo substr($shift['start_time'], 0, 5); ?> - <?php echo substr($shift['end_time'], 0, 5); ?>)
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (($user['work_mode'] ?? 'OFFICE') === 'WFH'): ?>
                        <div class="inline-flex items-center gap-2 min-h-[48px] px-3 py-1.5 rounded-[20px] bg-emerald-500/20 border border-emerald-400/30">
                            <i class="fas fa-home text-emerald-300 text-2xl" aria-hidden="true"></i>
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
                <div class="grid grid-cols-2 gap-4 mb-6 md:mb-8">
                    <div class="p-4 rounded-[20px] bg-white/5 border border-white/8 text-center">
                        <p class="text-white/60 text-sm mb-1">เวลาเข้างาน</p>
                        <p class="text-2xl font-bold <?php
                            $ciClass = 'text-white/30';
                            if ($today_attendance && $today_attendance['check_in_time']) {
                                $lateM = (int)($today_attendance['late_minutes'] ?? 0);
                                $st = (string)($today_attendance['status'] ?? '');
                                $ciClass = ($lateM > 0 || $st === 'LATE') ? 'text-amber-400' : 'text-green-400';
                            }
                            echo $ciClass;
                        ?>">
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

                    <div class="p-4 rounded-[20px] bg-white/5 border border-white/8 text-center">
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
                        <button id="btn-checkin" type="button"
                                onclick="startCheckin('in')"
                                class="w-48 h-48 max-w-[85vw] max-h-[85vw] aspect-square rounded-full bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white shadow-lg transition-colors active:scale-[0.98] touch-manipulation flex flex-col items-center justify-center border-0">
                            <i class="fas fa-fingerprint text-5xl mb-2 shrink-0" aria-hidden="true"></i>
                            <span class="text-lg font-bold whitespace-nowrap">ลงเวลาเข้า</span>
                        </button>
                        
                    <?php elseif (!$today_attendance['check_out_time']): ?>
                        <!-- Check-out Button -->
                        <button id="btn-checkout" type="button"
                                onclick="startCheckin('out')"
                                class="w-48 h-48 max-w-[85vw] max-h-[85vw] aspect-square rounded-full bg-sky-600 hover:bg-sky-700 active:bg-sky-800 text-white shadow-lg transition-colors active:scale-[0.98] touch-manipulation flex flex-col items-center justify-center border-0">
                            <i class="fas fa-sign-out-alt text-5xl mb-2 shrink-0" aria-hidden="true"></i>
                            <span class="text-lg font-bold whitespace-nowrap">ลงเวลาออก</span>
                        </button>
                        
                    <?php else: ?>
                        <!-- All Done -->
                        <div class="w-48 h-48 max-w-[85vw] max-h-[85vw] aspect-square rounded-full bg-slate-700 border border-white/12 text-white flex flex-col items-center justify-center">
                            <i class="fas fa-check-circle text-5xl mb-2 text-emerald-400 shrink-0" aria-hidden="true"></i>
                            <span class="text-base font-semibold text-center px-2">ลงเวลาครบแล้ว</span>
                        </div>
                    <?php endif; ?>
                    
                    <p class="text-white/50 text-sm text-center max-w-md px-2" id="location-status">
                        <i class="fas fa-location-arrow mr-1"></i>
                        กำลังตรวจสอบตำแหน่ง...
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Summary & History -->
        <div class="space-y-4 md:space-y-6">
            <!-- Planned Late Start (แจ้งเข้างานสายล่วงหน้า) -->
            <?php if ($ls_has_any): ?>
            <div>
                <div class="flex items-center justify-between mb-3 gap-2">
                    <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i class="fas fa-clock text-amber-400 text-2xl" aria-hidden="true"></i>แจ้งเข้างานสาย
                    </h2>
                    <span class="text-white/40 text-xs"><?php echo ($ls_today_row ? 1 : 0) + ($ls_tomorrow_row ? 1 : 0); ?> รายการ</span>
                </div>
                <div class="space-y-3">
                    <?php if ($ls_today_row): ?>
                    <div class="rounded-[20px] bg-amber-500/10 border border-amber-400/35 p-4">
                        <p class="text-amber-300 text-xs font-semibold uppercase tracking-wide mb-1">
                            <i class="fas fa-sun mr-1"></i>วันนี้ · <?php echo date('d M', strtotime($ls_today)); ?>
                        </p>
                        <p class="text-white text-2xl font-bold tracking-tight">
                            <?php
                            $lsTt = strtotime((string)($ls_today_row['planned_start_time'] ?? ''));
                            echo $lsTt !== false ? date('H:i', $lsTt) : '—:—';
                            ?>
                            <span class="text-white/50 text-sm font-normal">น.</span>
                        </p>
                        <?php if (!empty($ls_today_row['planned_reason'])): ?>
                        <p class="text-white/70 text-xs mt-1 line-clamp-2"><?php echo htmlspecialchars($ls_today_row['planned_reason']); ?></p>
                        <?php endif; ?>
                        <button type="button" onclick="cancelLateStart('<?php echo $ls_today; ?>')"
                                class="mt-3 w-full min-h-[52px] py-2 rounded-[20px] bg-red-500/15 hover:bg-red-500/25 border border-red-400/30 text-red-300 text-sm font-semibold transition-colors touch-manipulation">
                            <i class="fas fa-times-circle mr-1"></i>ยกเลิกการแจ้ง
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if ($ls_tomorrow_row): ?>
                    <div class="rounded-[20px] bg-sky-500/10 border border-sky-400/35 p-4">
                        <p class="text-blue-300 text-xs font-semibold uppercase tracking-wide mb-1">
                            <i class="fas fa-moon mr-1"></i>พรุ่งนี้ · <?php echo date('d M', strtotime($ls_tomorrow)); ?>
                        </p>
                        <p class="text-white text-2xl font-bold tracking-tight">
                            <?php
                            $lsTt2 = strtotime((string)($ls_tomorrow_row['planned_start_time'] ?? ''));
                            echo $lsTt2 !== false ? date('H:i', $lsTt2) : '—:—';
                            ?>
                            <span class="text-white/50 text-sm font-normal">น.</span>
                        </p>
                        <?php if (!empty($ls_tomorrow_row['planned_reason'])): ?>
                        <p class="text-white/70 text-xs mt-1 line-clamp-2"><?php echo htmlspecialchars($ls_tomorrow_row['planned_reason']); ?></p>
                        <?php endif; ?>
                        <button type="button" onclick="cancelLateStart('<?php echo $ls_tomorrow; ?>')"
                                class="mt-3 w-full min-h-[52px] py-2 rounded-[20px] bg-red-500/15 hover:bg-red-500/25 border border-red-400/30 text-red-300 text-sm font-semibold transition-colors touch-manipulation">
                            <i class="fas fa-times-circle mr-1"></i>ยกเลิกการแจ้ง
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if (!($ls_today_row && $ls_tomorrow_row)): ?>
                    <button type="button" onclick="openLateStartModal()"
                            class="w-full min-h-[52px] py-2.5 rounded-[20px] bg-white/5 hover:bg-white/10 border border-dashed border-white/20 text-white/70 text-sm font-medium transition-colors touch-manipulation">
                        <i class="fas fa-plus-circle mr-1"></i>เพิ่มการแจ้งอีกวัน
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <button type="button" onclick="openLateStartModal()"
                    class="w-full min-h-[56px] rounded-[20px] border border-amber-400/40 bg-amber-500/15 hover:bg-amber-500/22 p-4 text-left transition-colors touch-manipulation flex items-center gap-4">
                    <div class="shrink-0 w-12 h-12 rounded-[20px] bg-amber-500 flex items-center justify-center">
                        <i class="fas fa-clock text-white text-2xl" aria-hidden="true"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-white font-bold text-base leading-tight mb-0.5 flex flex-wrap items-center gap-2">
                            แจ้งเข้างานสาย
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-[20px] bg-amber-400/25 text-amber-100">ล่วงหน้า</span>
                        </h2>
                        <p class="text-white/70 text-sm leading-snug">
                            ทำงานดึก? แจ้งก่อน <?php echo sprintf('%02d:00', $late_start_cutoff_hour); ?> น. ของวันทำงาน
                        </p>
                    </div>
                    <i class="fas fa-chevron-right text-white/50 text-xl shrink-0" aria-hidden="true"></i>
            </button>
            <?php endif; ?>

            <!-- Quick links -->
            <div class="grid grid-cols-2 gap-4">
                <a href="attendance_history.php" class="flex min-h-[48px] items-center justify-center gap-2 rounded-[20px] bg-white/5 hover:bg-white/10 border border-white/10 px-3 py-2.5 text-white/80 hover:text-white text-sm font-medium transition-colors touch-manipulation whitespace-nowrap">
                    <i class="fas fa-history text-violet-400 text-xl" aria-hidden="true"></i>ประวัติเข้างาน
                </a>
                <a href="leave.php" class="flex min-h-[48px] items-center justify-center gap-2 rounded-[20px] bg-white/5 hover:bg-white/10 border border-white/10 px-3 py-2.5 text-white/80 hover:text-white text-sm font-medium transition-colors touch-manipulation whitespace-nowrap">
                    <i class="fas fa-calendar-check text-emerald-400 text-xl" aria-hidden="true"></i>ขอลา / OT
                </a>
            </div>

            <!-- Monthly Summary -->
            <div class="native-card tp-native-card tp-native-data-card">
                <h2 class="section-title mb-4">
                    <i class="fas fa-chart-pie text-violet-400 text-2xl" aria-hidden="true"></i>
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
            <div class="native-card tp-native-card tp-native-data-card">
                <h2 class="section-title mb-4">
                    <i class="fas fa-history text-blue-400 text-2xl" aria-hidden="true"></i>
                    ประวัติ 7 วันล่าสุด
                </h2>
                
                <?php if ($attendance_history): ?>
                <div class="space-y-2">
                    <?php foreach ($attendance_history as $att):
                        $attDate = $att['attendance_date'];
                        $hol = $holidayMap[$attDate] ?? null;
                    ?>
                    <div class="p-3 min-h-[52px] rounded-[20px] bg-white/5 border border-white/8 flex items-center justify-between gap-2">
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
                <div class="tp-native-empty-state text-center py-8 rounded-[20px] border border-dashed border-white/15">
                    <i class="fas fa-history text-slate-500 text-3xl mb-2" aria-hidden="true"></i>
                    <p class="text-slate-400 text-sm">ยังไม่มีประวัติ</p>
                </div>
                <?php endif; ?>
                
                <a href="attendance_history.php" class="mt-4 inline-flex min-h-[48px] w-full items-center justify-center gap-1 text-center text-violet-400 hover:text-violet-300 text-sm font-medium touch-manipulation whitespace-nowrap">
                    ดูประวัติทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Planned Late Start Modal -->
<div id="late-start-modal" class="tp-native-modal fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-white text-lg font-bold flex items-center gap-2">
                <i class="fas fa-clock text-amber-400"></i>แจ้งเข้างานสายล่วงหน้า
            </h3>
            <button type="button" onclick="closeLateStartModal()" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center text-white/60 hover:text-white touch-manipulation rounded-[20px]" aria-label="ปิด">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Target Date -->
        <div class="mb-4">
            <label class="text-white/70 text-sm mb-2 block">แจ้งสำหรับวันไหน</label>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex min-h-[48px] items-center justify-center p-3 rounded-[20px] bg-white/5 border border-white/10 cursor-pointer has-[:checked]:bg-amber-500/20 has-[:checked]:border-amber-400 transition-colors touch-manipulation">
                    <input type="radio" name="ls-target" value="tomorrow" class="hidden" onchange="updateLateStartDateLabel()" <?php echo $late_start_can_tomorrow_request['ok'] ? 'checked' : ''; ?>>
                    <div class="text-center">
                        <div class="text-white font-medium">พรุ่งนี้</div>
                        <div class="text-white/50 text-xs mt-0.5">แจ้งล่วงหน้า</div>
                    </div>
                </label>
                <label class="flex min-h-[48px] items-center justify-center p-3 rounded-[20px] bg-white/5 border border-white/10 <?php echo $late_start_can_today_request['ok'] ? 'cursor-pointer has-[:checked]:bg-amber-500/20 has-[:checked]:border-amber-400 touch-manipulation' : 'opacity-40 cursor-not-allowed'; ?> transition-colors">
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
            <!-- iOS (Safari/Chrome WKWebView) can overflow native time input. Use select fallback on iOS. -->
            <select id="ls-planned-time-select" data-ios-time-select-for="ls-planned-time"
                    class="hidden w-full max-w-full min-w-0 bg-white/5 border border-white/10 rounded-[20px] px-4 py-3 text-white text-lg font-medium focus:outline-none focus:border-amber-400">
            </select>
            <input type="time" id="ls-planned-time" step="900"
                   min="<?php echo htmlspecialchars(substr(($shift['start_time'] ?? '08:30'), 0, 5)); ?>"
                   value="<?php echo htmlspecialchars(substr(($shift['start_time'] ?? '08:30'), 0, 5)); ?>"
                   class="w-full max-w-full min-w-0 bg-white/5 border border-white/10 rounded-[20px] px-4 py-3 text-white text-lg font-medium focus:outline-none focus:border-amber-400">
            <p class="text-white/40 text-xs mt-1">
                หลังเข้าในเวลาที่แจ้ง ± 30 นาที จะไม่ถูกหัก
            </p>
        </div>

        <!-- Reason -->
        <div class="mb-5">
            <label class="text-white/70 text-sm mb-2 block">เหตุผล <span class="text-red-400">*</span></label>
            <textarea id="ls-reason" rows="3" maxlength="255"
                      placeholder="เช่น ทำงานดึกเมื่อคืน / มีนัดหมอ / ติดธุระส่วนตัว"
                      class="w-full bg-white/5 border border-white/10 rounded-[20px] px-4 py-3 text-white text-sm placeholder-white/30 focus:outline-none focus:border-amber-400 resize-none"></textarea>
            <p class="text-white/40 text-xs mt-1">อย่างน้อย 5 ตัวอักษร</p>
        </div>

        <!-- Submit -->
        <div class="flex gap-2">
            <button type="button" onclick="closeLateStartModal()"
                    class="flex-1 min-h-[52px] py-3 bg-white/10 hover:bg-white/15 text-white rounded-[20px] font-medium transition-colors touch-manipulation border-0">
                ยกเลิก
            </button>
            <button type="button" onclick="submitLateStart()"
                    class="flex-1 min-h-[56px] py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-[20px] font-semibold transition-colors touch-manipulation border-0">
                <i class="fas fa-paper-plane mr-2"></i>ส่งคำขอ
            </button>
        </div>
    </div>
</div>

<!-- Off-site Reason Modal -->
<div id="offsite-modal" class="tp-native-modal fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
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
                      class="w-full bg-white/10 border border-white/20 rounded-[20px] px-4 py-3 text-white text-sm placeholder-white/40 focus:outline-none focus:border-amber-400 resize-none"></textarea>
            <p class="text-white/40 text-xs mt-1">อย่างน้อย 5 ตัวอักษร</p>
        </div>

        <div class="flex gap-3">
            <button type="button" onclick="closeOffsiteModal()" class="flex-1 min-h-[52px] py-3 bg-white/10 hover:bg-white/20 text-white rounded-[20px] font-medium transition-colors touch-manipulation border-0">
                ยกเลิก
            </button>
            <button type="button" onclick="submitOffsite()" class="flex-1 min-h-[56px] py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-[20px] font-semibold transition-colors touch-manipulation border-0">
                <i class="fas fa-paper-plane mr-1"></i>ส่งคำขอ
            </button>
        </div>
    </div>
</div>

<!-- Check-in Modal -->
<div id="checkin-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <div class="text-center mb-6">
            <div class="w-20 h-20 rounded-full bg-violet-600/20 flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-camera text-violet-400 text-3xl" id="modal-icon"></i>
            </div>
            <h3 class="text-xl font-bold text-white" id="modal-title">ลงเวลาเข้างาน</h3>
            <p class="text-white/60 text-sm mt-1" id="modal-subtitle">ถ่ายรูปเพื่อยืนยันตัวตน</p>
        </div>
        
        <!-- Camera Preview -->
        <div class="relative mb-6 rounded-[20px] overflow-hidden bg-black aspect-video" id="camera-container">
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
        <div class="p-3 rounded-[20px] bg-white/5 border border-white/8 mb-6" id="location-info">
            <div class="flex items-center gap-2 text-white/70">
                <i class="fas fa-map-marker-alt text-red-400"></i>
                <span id="location-text">กำลังระบุตำแหน่ง...</span>
            </div>
            <input type="hidden" id="latitude" value="">
            <input type="hidden" id="longitude" value="">
        </div>
        
        <!-- Buttons -->
        <div class="flex gap-3">
            <button type="button" onclick="closeCheckinModal()" class="flex-1 min-h-[52px] py-3 bg-white/10 hover:bg-white/20 text-white rounded-[20px] transition-colors touch-manipulation border-0">
                ยกเลิก
            </button>
            <button type="button" id="btn-capture" onclick="capturePhoto()" class="flex-1 min-h-[56px] py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-[20px] transition-colors touch-manipulation border-0">
                <i class="fas fa-camera mr-2" aria-hidden="true"></i>ถ่ายรูป
            </button>
            <button type="button" id="btn-confirm" onclick="confirmCheckin()" class="flex-1 min-h-[56px] py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[20px] transition-colors hidden touch-manipulation border-0">
                <i class="fas fa-check mr-2" aria-hidden="true"></i>ยืนยัน
            </button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

// Current time display (manual HH:MM:SS — avoids blank/stuck output on some WebKit locale combos)
function formatClock(d) {
    const h = String(d.getHours()).padStart(2, '0');
    const m = String(d.getMinutes()).padStart(2, '0');
    const s = String(d.getSeconds()).padStart(2, '0');
    return h + ':' + m + ':' + s;
}
function updateClock() {
    const el = document.getElementById('current-time');
    if (!el) return;
    try {
        el.textContent = formatClock(new Date());
    } catch (e) { /* noop */ }
}
function startClockTick() {
    updateClock();
    setInterval(updateClock, 1000);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startClockTick);
} else {
    startClockTick();
}

// Variables
let checkinType = '';
let stream = null;
let photoData = null;
let userLatitude = null;
let userLongitude = null;

// Get user location on page load (timeout + maximumAge — without timeout some devices hang on "กำลังตรวจสอบ...")
(function initPageGeolocation() {
    const lsEl = document.getElementById('location-status');
    const geoOpts = { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 };
    let settled = false;
    function setStatus(html) {
        if (lsEl) lsEl.innerHTML = html;
        settled = true;
    }
    if (!navigator.geolocation) {
        if (lsEl) lsEl.innerHTML = '<i class="fas fa-exclamation-triangle text-amber-400 mr-1"></i> เบราว์เซอร์ไม่รองรับ GPS';
        settled = true;
        return;
    }
    navigator.geolocation.getCurrentPosition(
        (position) => {
            userLatitude = position.coords.latitude;
            userLongitude = position.coords.longitude;
            setStatus('<i class="fas fa-check-circle text-green-400 mr-1"></i> พร้อมลงเวลา');
        },
        () => {
            setStatus('<i class="fas fa-exclamation-triangle text-yellow-400 mr-1"></i> ไม่สามารถระบุตำแหน่งได้ — เปิด GPS แล้วลองใหม่');
        },
        geoOpts
    );
    setTimeout(function () {
        if (!settled && lsEl && (lsEl.textContent || '').indexOf('กำลังตรวจสอบ') !== -1) {
            lsEl.innerHTML = '<i class="fas fa-exclamation-triangle text-amber-400 mr-1"></i> รอระบุตำแหน่งนาน — ลองเปิด GPS หรือรีเฟรช';
            settled = true;
        }
    }, 18000);
})();

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
    
    if (typeof uiOpenModal === 'function') uiOpenModal('checkin-modal');
    else modal.classList.remove('hidden');
    startCamera();
    getLocation();
}

// Close modal
function closeCheckinModal() {
    const modal = document.getElementById('checkin-modal');
    if (typeof uiCloseModal === 'function') uiCloseModal('checkin-modal');
    else modal.classList.add('hidden');
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
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
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
            _token: CSRF_TOKEN,
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
                showToast('success', 'สำเร็จ', result.message || 'ส่งคำขอเรียบร้อย รอผู้อนุมัติ');
            } else {
                showToast('success', 'สำเร็จ', checkinType === 'in' ? 'ลงเวลาเข้างานสำเร็จ' : 'ลงเวลาออกงานสำเร็จ');
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
        showToast('error', 'ผิดพลาด', result.error || 'เกิดข้อผิดพลาด');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-2"></i>ยืนยัน';
    } catch (err) {
        console.error('Check-in error:', err);
        showToast('error', 'ผิดพลาด', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-2"></i>ยืนยัน';
    }
}

/* Off-site reason modal — retry flow when user is outside geofence */
function openOffsiteModal(infoMsg = null) {
    if (infoMsg) document.getElementById('offsite-info').textContent = infoMsg;
    document.getElementById('offsite-reason').value = '';
    if (typeof uiOpenModal === 'function') uiOpenModal('offsite-modal');
    else document.getElementById('offsite-modal').classList.remove('hidden');
    setTimeout(() => document.getElementById('offsite-reason').focus(), 300);
}
function closeOffsiteModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('offsite-modal');
    else document.getElementById('offsite-modal').classList.add('hidden');
}
function submitOffsite() {
    const reason = document.getElementById('offsite-reason').value.trim();
    if (reason.length < 5) {
        showToast('error', 'ผิดพลาด', 'กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
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
    if (typeof uiOpenModal === 'function') uiOpenModal('late-start-modal');
    else { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    updateLateStartDateLabel();
}
function closeLateStartModal() {
    const modal = document.getElementById('late-start-modal');
    if (!modal) return;
    if (typeof uiCloseModal === 'function') uiCloseModal('late-start-modal');
    else { modal.classList.add('hidden'); modal.classList.remove('flex'); }
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
    const planned_time = (function () {
        const sel = document.getElementById('ls-planned-time-select');
        if (sel && !sel.classList.contains('hidden')) return sel.value;
        return document.getElementById('ls-planned-time').value;
    })();
    const reason = document.getElementById('ls-reason').value.trim();

    if (!planned_time) { showToast('error', 'ผิดพลาด', 'กรุณาเลือกเวลาที่จะเข้างาน'); return; }
    if (reason.length < 5) { showToast('error', 'ผิดพลาด', 'กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร'); return; }

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
                _token: CSRF_TOKEN,
            }),
        });
        const data = await resp.json();
        if (data.success) {
            showToast('success', 'สำเร็จ', data.message || 'แจ้งเข้างานสายเรียบร้อย');
            closeLateStartModal();
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('error', 'ผิดพลาด', data.error || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        console.error('submitLateStart:', err);
        showToast('error', 'ผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    }
}
async function cancelLateStart(targetDate) {
    if (!confirm('ยกเลิกการแจ้งเข้างานสายของ ' + targetDate + ' ?')) return;
    try {
        const resp = await fetch('/api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_late_start', target_date: targetDate, _token: CSRF_TOKEN }),
        });
        const data = await resp.json();
        if (data.success) {
            showToast('success', 'สำเร็จ', data.message || 'ยกเลิกเรียบร้อย');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('error', 'ผิดพลาด', data.error || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        console.error('cancelLateStart:', err);
        showToast('error', 'ผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    }
}

// iOS time picker fallback is initialized globally in templates/footer.php
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
