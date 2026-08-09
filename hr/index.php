<?php
/**
 * HR Admin Dashboard
 * แดชบอร์ด HR
 */

$page_title = 'HR Dashboard';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

// Check HR permission (role names or Acl hr.dashboard / hr.*)
if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Get today's stats
$today = date('Y-m-d');
$currentMonth = date('Y-m');

// Attendance today
$stmtAttendance = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT a.user_id) as checked_in,
        SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END) as late_count,
        (SELECT COUNT(*) FROM users WHERE is_active = 1 AND " . tp_hr_non_system_user_condition_sql('') . ") as total_employees
    FROM hr_attendances a
    WHERE DATE(a.check_in_time) = ?
");
$stmtAttendance->execute([$today]);
$attendanceStats = $stmtAttendance->fetch();

// Pending leave requests
$stmtLeave = $pdo->prepare("SELECT COUNT(*) FROM hr_leave_requests WHERE status = 'PENDING'");
$stmtLeave->execute();
$pendingLeaves = $stmtLeave->fetchColumn();

// Pending document requests
$stmtDoc = $pdo->prepare("SELECT COUNT(*) FROM hr_document_requests WHERE status IN ('PENDING', 'PROCESSING')");
$stmtDoc->execute();
$pendingDocs = $stmtDoc->fetchColumn();

// Pending outside-location attendance requests
$stmtOutside = $pdo->prepare("SELECT COUNT(*) FROM hr_attendance_outside_requests WHERE status = 'PENDING'");
$stmtOutside->execute();
$pendingOutside = $stmtOutside->fetchColumn();

$pendingDayoff = 0;
$pendingHolidayWork = 0;
if (isCEOOrAbove()) {
    try {
        $pendingDayoff = (int) $pdo->query("SELECT COUNT(*) FROM hr_dayoff_requests WHERE status = 'PENDING'")->fetchColumn();
    } catch (Throwable) {
        $pendingDayoff = 0;
    }
    try {
        $pendingHolidayWork = (int) $pdo->query("SELECT COUNT(*) FROM hr_holiday_work_exceptions WHERE status = 'PENDING'")->fetchColumn();
    } catch (Throwable) {
        $pendingHolidayWork = 0;
    }
}

// Recent leaves to approve
$stmtRecentLeaves = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
           u.first_name_th, u.last_name_th, u.employee_code, u.department
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    JOIN users u ON lr.user_id = u.id
    WHERE lr.status = 'PENDING'
    ORDER BY lr.created_at ASC
    LIMIT 5
");
$stmtRecentLeaves->execute();
$recentLeaves = $stmtRecentLeaves->fetchAll();

// Recent document requests
$stmtRecentDocs = $pdo->prepare("
    SELECT dr.*, dt.name as template_name,
           u.first_name_th, u.last_name_th, u.employee_code
    FROM hr_document_requests dr
    JOIN hr_document_templates dt ON dr.template_id = dt.id
    JOIN users u ON dr.user_id = u.id
    WHERE dr.status IN ('PENDING', 'PROCESSING')
    ORDER BY dr.created_at ASC
    LIMIT 5
");
$stmtRecentDocs->execute();
$recentDocs = $stmtRecentDocs->fetchAll();

// Employees on leave today
$stmtOnLeave = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, lt.color as color_code,
           u.first_name_th, u.last_name_th, u.department
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    JOIN users u ON lr.user_id = u.id
    WHERE lr.status = 'APPROVED'
    AND ? BETWEEN lr.start_date AND lr.end_date
");
$stmtOnLeave->execute([$today]);
$onLeaveToday = $stmtOnLeave->fetchAll();

// Monthly org summary (EmployeeSummaryService)
$summaryService = new EmployeeSummaryService($pdo);
$orgMonthlyKpi = $summaryService->getOrgMonthlyKpi($currentMonth);

$current_page = 'hr-dashboard';

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <h1 class="tp-ios-page-title">แดชบอร์ด HR</h1>
    <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ภาพรวมการเข้างาน การลา และคำขอเอกสาร ณ วันที่ <?php echo formatDateThai($today); ?></p>
</header>

<!-- Quick Stats -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 md:gap-6 mb-6 md:mb-8 min-w-0 max-w-full">
    <div class="stat-card tp-native-summary-card group min-w-0">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-emerald-500/15 border border-emerald-400/25 transition-colors">
                <i class="fas fa-user-check text-emerald-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-slate-300 text-sm">เข้างานวันนี้</p>
                <p class="text-2xl font-bold text-white tabular-nums">
                    <?php echo (int)($attendanceStats['checked_in'] ?? 0); ?>/<span class="text-white/80"><?php echo (int)($attendanceStats['total_employees'] ?? 0); ?></span>
                </p>
            </div>
        </div>
    </div>

    <div class="stat-card tp-native-summary-card group min-w-0">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-amber-500/15 border border-amber-400/25 transition-colors">
                <i class="fas fa-clock text-amber-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-slate-300 text-sm">มาสาย</p>
                <p class="text-2xl font-bold text-white tabular-nums"><?php echo (int)($attendanceStats['late_count'] ?? 0); ?></p>
            </div>
        </div>
    </div>

    <div class="stat-card tp-native-summary-card group min-w-0">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-violet-500/15 border border-violet-400/25 transition-colors">
                <i class="fas fa-calendar-check text-violet-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-slate-300 text-sm">รอลาอนุมัติ</p>
                <p class="text-2xl font-bold text-white tabular-nums"><?php echo (int)$pendingLeaves; ?></p>
            </div>
        </div>
        <a href="leaves.php" class="text-violet-400 text-sm hover:underline mt-3 inline-block touch-manipulation font-medium">ดูทั้งหมด →</a>
    </div>

    <div class="stat-card tp-native-summary-card group min-w-0">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-sky-500/15 border border-sky-400/25 transition-colors">
                <i class="fas fa-location-dot text-sky-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-slate-300 text-sm">รอนอกสถานที่</p>
                <p class="text-2xl font-bold text-white tabular-nums"><?php echo (int)$pendingOutside; ?></p>
            </div>
        </div>
        <a href="outside_attendance.php" class="text-sky-400 text-sm hover:underline mt-3 inline-block touch-manipulation font-medium">ดูทั้งหมด →</a>
    </div>

    <div class="stat-card tp-native-summary-card group min-w-0">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-blue-500/15 border border-blue-400/25 transition-colors">
                <i class="fas fa-file-alt text-blue-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-slate-300 text-sm">รอออกเอกสาร</p>
                <p class="text-2xl font-bold text-white tabular-nums"><?php echo (int)$pendingDocs; ?></p>
            </div>
        </div>
        <a href="documents.php" class="text-blue-400 text-sm hover:underline mt-3 inline-block touch-manipulation font-medium">ดูทั้งหมด →</a>
    </div>
</div>

<!-- สรุปรายเดือนองค์กร -->
<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 md:mb-8 min-w-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-5">
        <div>
            <h2 class="section-title mb-1 text-white text-base sm:text-lg">
                <i class="fas fa-chart-bar text-violet-400 mr-2" aria-hidden="true"></i>
                สรุปรายเดือน — <?php echo formatDateThai($currentMonth . '-01'); ?>
            </h2>
            <p class="text-white/55 text-sm">วันทำงาน การลา วันหยุด การขาด และการสลับวันหยุด ทั้งองค์กร</p>
        </div>
        <a href="/hr/employee_summaries.php?month=<?php echo urlencode($currentMonth); ?>"
           class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] font-semibold touch-manipulation shrink-0">
            <i class="fas fa-users mr-2" aria-hidden="true"></i>ดูรายพนักงาน
        </a>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-7 gap-3">
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">อัตราเข้างาน</p>
            <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo number_format((float)$orgMonthlyKpi['attendance_rate'], 1); ?>%</p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">มาสาย</p>
            <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)$orgMonthlyKpi['late_days']; ?></p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">ขาดงาน</p>
            <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)$orgMonthlyKpi['absent_days']; ?></p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">วันลา</p>
            <p class="text-2xl font-bold text-blue-400 tabular-nums mt-1"><?php echo number_format((float)$orgMonthlyKpi['approved_leave_days'], 1); ?></p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">WFH</p>
            <p class="text-2xl font-bold text-purple-400 tabular-nums mt-1"><?php echo (int)$orgMonthlyKpi['wfh_days']; ?></p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">สลับวันหยุด</p>
            <p class="text-2xl font-bold text-violet-300 tabular-nums mt-1"><?php echo (int)$orgMonthlyKpi['dayoff_swaps']; ?></p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-4 text-center">
            <p class="text-white/55 text-xs">มาทำงานวันหยุด</p>
            <p class="text-2xl font-bold text-orange-300 tabular-nums mt-1"><?php echo (int)($orgMonthlyKpi['holiday_work_count'] ?? 0); ?></p>
        </div>
    </div>
</div>

<details class="hr-dashboard-secondary-accordion min-w-0 xl:contents group">
    <summary class="hr-dashboard-secondary-accordion__summary native-card tp-native-card xl:hidden flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer touch-manipulation select-none text-left mb-4 list-none border border-white/10">
        <span class="text-white font-semibold flex items-center gap-2 min-w-0">
            <i class="fas fa-calendar-alt text-violet-400 flex-shrink-0" aria-hidden="true"></i>
            <span class="truncate">คำขอลาและพนักงานลาวันนี้</span>
        </span>
        <i class="fas fa-chevron-down text-white/60 text-sm hr-dashboard-secondary-accordion__chevron flex-shrink-0 transition-transform duration-200" aria-hidden="true"></i>
    </summary>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 md:gap-8 mb-6 md:mb-8 min-w-0 max-w-full">
    <!-- Pending Leave Requests -->
    <div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-white/10 flex items-center justify-between gap-3 min-w-0">
            <h2 class="section-title mb-0 flex flex-wrap items-center gap-2 text-white min-w-0">
                <i class="fas fa-calendar-alt text-violet-400 text-2xl shrink-0" aria-hidden="true"></i>
                <span class="min-w-0">คำขอลารออนุมัติ</span>
            </h2>
            <a href="leaves.php" class="text-violet-400 text-sm hover:underline shrink-0 touch-manipulation font-medium">ดูทั้งหมด</a>
        </div>

        <?php if (empty($recentLeaves)): ?>
        <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
            <i class="fas fa-check-circle text-emerald-400/90 text-4xl mb-3 block" aria-hidden="true"></i>
            <p class="text-slate-400 text-sm">ไม่มีคำขอลารออนุมัติ</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-white/10">
            <?php foreach ($recentLeaves as $leave): ?>
            <div class="p-5 hover:bg-white/[0.04] transition-colors min-w-0">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between min-w-0">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1 min-w-0">
                            <span class="w-2 h-2 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars($leave['color_code'] ?? '#6366f1', ENT_QUOTES, 'UTF-8'); ?>"></span>
                            <span class="text-white font-medium truncate"><?php echo htmlspecialchars($leave['first_name_th'] . ' ' . $leave['last_name_th']); ?></span>
                        </div>
                        <p class="text-white/70 text-sm"><?php echo htmlspecialchars($leave['leave_type_name']); ?></p>
                        <p class="text-white/50 text-sm">
                            <?php echo formatDateThai($leave['start_date']); ?>
                            <?php if ($leave['start_date'] !== $leave['end_date']): ?>
                            - <?php echo formatDateThai($leave['end_date']); ?>
                            <?php endif; ?>
                            (<?php echo number_format((float)$leave['total_days'], 1); ?> วัน)
                        </p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2 shrink-0 w-full sm:w-auto">
                        <button type="button" onclick="approveLeave(<?php echo (int)$leave['id']; ?>)"
                                class="min-h-[56px] px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold whitespace-nowrap">
                            อนุมัติ
                        </button>
                        <button type="button" onclick="rejectLeave(<?php echo (int)$leave['id']; ?>)"
                                class="min-h-[48px] px-4 py-2 bg-red-500/20 hover:bg-red-500/30 border border-red-500/25 text-red-300 text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-medium whitespace-nowrap">
                            ไม่อนุมัติ
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- On Leave Today -->
    <div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-white/10">
            <h2 class="section-title mb-0 flex flex-wrap items-center gap-2 text-white">
                <i class="fas fa-user-minus text-orange-400 text-2xl" aria-hidden="true"></i>
                ลาวันนี้ (<?php echo count($onLeaveToday); ?> คน)
            </h2>
        </div>

        <?php if (empty($onLeaveToday)): ?>
        <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
            <i class="fas fa-users text-emerald-400/90 text-4xl mb-3 block" aria-hidden="true"></i>
            <p class="text-slate-400 text-sm">ไม่มีพนักงานลาวันนี้</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-white/10 max-h-80 overflow-y-auto overscroll-contain">
            <?php foreach ($onLeaveToday as $emp): ?>
            <div class="p-5 flex items-center gap-3 min-w-0">
                <span class="w-2 h-2 rounded-full shrink-0" style="background-color: <?php echo htmlspecialchars($emp['color_code'] ?? '#6366f1', ENT_QUOTES, 'UTF-8'); ?>"></span>
                <div class="flex-1 min-w-0">
                    <p class="text-white text-sm truncate"><?php echo htmlspecialchars($emp['first_name_th'] . ' ' . $emp['last_name_th']); ?></p>
                    <p class="text-white/50 text-xs truncate"><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></p>
                </div>
                <span class="text-white/60 text-xs shrink-0 text-right max-w-[40%]"><?php echo htmlspecialchars($emp['leave_type_name']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</details>

<!-- Quick Actions -->
<div class="native-card tp-native-card tp-native-data-card mb-6 min-w-0 max-w-full overflow-hidden">
    <div class="p-5 sm:p-6 border-b border-white/10">
        <h2 class="section-title mb-0 flex flex-wrap items-center gap-2 text-white">
            <i class="fas fa-bolt text-amber-400 text-2xl" aria-hidden="true"></i>
            ทางลัด HR
        </h2>
    </div>
    <div class="p-5 sm:p-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 min-w-0">
            <a href="leaves.php" class="quick-action tp-native-quick-action-card group relative min-h-[96px] sm:min-h-[116px] touch-manipulation border-violet-500/20">
                <div class="quick-action-icon bg-violet-500/15 border border-violet-400/25 group-hover:bg-violet-500/25">
                    <i class="fas fa-calendar-check text-violet-400 text-2xl" aria-hidden="true"></i>
                </div>
                <span class="text-white font-semibold text-center text-sm sm:text-base leading-snug">จัดการการลา</span>
            </a>

            <a href="attendance.php" class="quick-action tp-native-quick-action-card group relative min-h-[96px] sm:min-h-[116px] touch-manipulation border-emerald-500/20">
                <div class="quick-action-icon bg-emerald-500/15 border border-emerald-400/25 group-hover:bg-emerald-500/25">
                    <i class="fas fa-user-clock text-emerald-400 text-2xl" aria-hidden="true"></i>
                </div>
                <span class="text-white font-semibold text-center text-sm sm:text-base leading-snug">ตรวจสอบการเข้างาน</span>
            </a>

            <a href="documents.php" class="quick-action tp-native-quick-action-card group relative min-h-[96px] sm:min-h-[116px] touch-manipulation border-blue-500/20">
                <div class="quick-action-icon bg-blue-500/15 border border-blue-400/25 group-hover:bg-blue-500/25">
                    <i class="fas fa-file-alt text-blue-400 text-2xl" aria-hidden="true"></i>
                </div>
                <span class="text-white font-semibold text-center text-sm sm:text-base leading-snug">ออกเอกสาร</span>
            </a>

            <a href="employees.php" class="quick-action tp-native-quick-action-card group relative min-h-[96px] sm:min-h-[116px] touch-manipulation border-amber-500/20">
                <div class="quick-action-icon bg-amber-500/15 border border-amber-400/25 group-hover:bg-amber-500/25">
                    <i class="fas fa-users text-amber-400 text-2xl" aria-hidden="true"></i>
                </div>
                <span class="text-white font-semibold text-center text-sm sm:text-base leading-snug">พนักงาน</span>
            </a>

            <?php if (isCEOOrAbove()): ?>
            <a href="dayoff_approvals.php" class="quick-action tp-native-quick-action-card group relative min-h-[96px] sm:min-h-[116px] touch-manipulation border-violet-500/20">
                <div class="quick-action-icon bg-violet-500/15 border border-violet-400/25 group-hover:bg-violet-500/25">
                    <i class="fas fa-calendar-day text-violet-400 text-2xl" aria-hidden="true"></i>
                </div>
                <span class="text-white font-semibold text-center text-sm sm:text-base leading-snug">อนุมัติเปลี่ยนวันหยุด</span>
                <?php if ($pendingDayoff > 0): ?>
                <span class="absolute top-2 right-2 min-w-[1.5rem] h-6 px-1.5 rounded-full bg-amber-500 text-white text-xs font-bold flex items-center justify-center"><?php echo (int) $pendingDayoff; ?></span>
                <?php endif; ?>
            </a>

            <a href="holiday_work_approvals.php" class="quick-action tp-native-quick-action-card group relative min-h-[96px] sm:min-h-[116px] touch-manipulation border-orange-500/20">
                <div class="quick-action-icon bg-orange-500/15 border border-orange-400/25 group-hover:bg-orange-500/25">
                    <i class="fas fa-briefcase text-orange-400 text-2xl" aria-hidden="true"></i>
                </div>
                <span class="text-white font-semibold text-center text-sm sm:text-base leading-snug">อนุมัติทำงานวันหยุด</span>
                <?php if ($pendingHolidayWork > 0): ?>
                <span class="absolute top-2 right-2 min-w-[1.5rem] h-6 px-1.5 rounded-full bg-amber-500 text-white text-xs font-bold flex items-center justify-center"><?php echo (int) $pendingHolidayWork; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Pending Documents -->
<?php if (!empty($recentDocs)): ?>
<details class="hr-dashboard-secondary-accordion min-w-0 xl:contents group">
    <summary class="hr-dashboard-secondary-accordion__summary native-card tp-native-card xl:hidden flex items-center justify-between gap-3 px-4 py-3.5 cursor-pointer touch-manipulation select-none text-left mb-4 list-none border border-white/10">
        <span class="text-white font-semibold flex items-center gap-2 min-w-0">
            <i class="fas fa-file-signature text-blue-400 flex-shrink-0" aria-hidden="true"></i>
            <span class="truncate">คำขอเอกสารรอดำเนินการ</span>
        </span>
        <i class="fas fa-chevron-down text-white/60 text-sm hr-dashboard-secondary-accordion__chevron flex-shrink-0 transition-transform duration-200" aria-hidden="true"></i>
    </summary>
<div class="min-w-0 max-w-full">
<div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
    <div class="p-5 sm:p-6 border-b border-white/10 flex items-center justify-between gap-3 min-w-0">
        <h2 class="section-title mb-0 flex flex-wrap items-center gap-2 text-white min-w-0">
            <i class="fas fa-file-signature text-blue-400 text-2xl shrink-0" aria-hidden="true"></i>
            <span class="min-w-0">คำขอเอกสารรอดำเนินการ</span>
        </h2>
        <a href="documents.php" class="text-blue-400 text-sm hover:underline shrink-0 touch-manipulation font-medium">ดูทั้งหมด</a>
    </div>

    <div class="md:hidden p-5 space-y-4">
        <?php foreach ($recentDocs as $doc): ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5 space-y-3 min-w-0">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-white/50 text-xs uppercase tracking-wide">เลขที่</p>
                    <p class="text-white font-mono text-sm"><?php echo htmlspecialchars($doc['request_number']); ?></p>
                </div>
                <?php if ($doc['status'] === 'PENDING'): ?>
                <span class="shrink-0 px-2 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded-[var(--tp-ios-card-radius)]">รอดำเนินการ</span>
                <?php else: ?>
                <span class="shrink-0 px-2 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-[var(--tp-ios-card-radius)]">กำลังดำเนินการ</span>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-white/50 text-xs">พนักงาน</p>
                <p class="text-white font-medium"><?php echo htmlspecialchars($doc['first_name_th'] . ' ' . $doc['last_name_th']); ?></p>
                <p class="text-white/40 text-xs mt-0.5"><?php echo htmlspecialchars($doc['employee_code'] ?? ''); ?></p>
            </div>
            <div>
                <p class="text-white/50 text-xs">ประเภทเอกสาร</p>
                <p class="text-white text-sm"><?php echo htmlspecialchars($doc['template_name']); ?></p>
            </div>
            <div>
                <p class="text-white/50 text-xs">วันที่ขอ</p>
                <p class="text-white/80 text-sm"><?php echo formatDateThai($doc['created_at']); ?></p>
            </div>
            <a href="documents.php?action=process&id=<?php echo (int)$doc['id']; ?>"
               class="flex min-h-[56px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold transition-colors touch-manipulation">
                ดำเนินการ
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1">
        <table class="w-full" style="min-width:640px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เลขที่</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภทเอกสาร</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ขอ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">การดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($recentDocs as $doc): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3 text-white/70 text-sm font-mono"><?php echo htmlspecialchars($doc['request_number']); ?></td>
                    <td class="px-4 py-3 text-white"><?php echo htmlspecialchars($doc['first_name_th'] . ' ' . $doc['last_name_th']); ?></td>
                    <td class="px-4 py-3 text-white"><?php echo htmlspecialchars($doc['template_name']); ?></td>
                    <td class="px-4 py-3 text-white/70 text-sm"><?php echo formatDateThai($doc['created_at']); ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($doc['status'] === 'PENDING'): ?>
                        <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded-[var(--tp-ios-card-radius)]">รอดำเนินการ</span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-[var(--tp-ios-card-radius)]">กำลังดำเนินการ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="documents.php?action=process&id=<?php echo (int)$doc['id']; ?>"
                           class="inline-flex min-h-[56px] items-center justify-center px-4 bg-violet-600 hover:bg-violet-700 text-white text-sm rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold">
                            ดำเนินการ
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
</details>
<?php endif; ?>
</div>

<!-- Reject Modal -->
<div id="reject-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <form id="reject-form" class="space-y-4">
            <h3 id="reject-modal-title" class="text-xl font-bold text-white">ไม่อนุมัติคำขอลา</h3>
            <input type="hidden" name="request_id" id="reject-request-id">
            <div class="tp-native-form-group mb-0">
                <label for="reject-reason" class="text-white/80 text-sm">เหตุผล <span class="text-red-400" aria-hidden="true">*</span></label>
                <textarea name="reason" id="reject-reason" required rows="3" class="input-field tp-native-textarea w-full"
                          placeholder="ระบุเหตุผลที่ไม่อนุมัติ..."></textarea>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 pt-2">
                <button type="button" onclick="closeRejectModal()" class="flex-1 min-h-[48px] py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-medium whitespace-nowrap">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 min-h-[56px] py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">
                    ไม่อนุมัติ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
async function approveLeave(id) {
    if (!confirm('อนุมัติคำขอลานี้?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'approve');
        formData.append('request_id', id);
        formData.append('_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('อนุมัติคำขอลาสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

function rejectLeave(id) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-reason').value = '';
    if (typeof uiOpenModal === 'function') uiOpenModal('reject-modal');
    else {
        const m = document.getElementById('reject-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeRejectModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('reject-modal');
    else {
        const m = document.getElementById('reject-modal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

document.getElementById('reject-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const id = document.getElementById('reject-request-id').value;
    const reason = document.getElementById('reject-reason').value;
    
    try {
        const formData = new FormData();
        formData.append('action', 'reject');
        formData.append('request_id', id);
        formData.append('reason', reason);
        formData.append('_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('บันทึกผลสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
});

document.getElementById('reject-modal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>
<script>
(function () {
    function hrDashboardSecondaryWide() {
        return window.matchMedia('(min-width: 1280px)').matches;
    }
    function syncHrDashboardSecondaryAccordions() {
        var wide = hrDashboardSecondaryWide();
        document.querySelectorAll('.hr-dashboard-secondary-accordion').forEach(function (d) {
            d.open = wide;
        });
    }
    window.addEventListener('resize', syncHrDashboardSecondaryAccordions, { passive: true });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncHrDashboardSecondaryAccordions);
    } else {
        syncHrDashboardSecondaryAccordions();
    }
})();
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
