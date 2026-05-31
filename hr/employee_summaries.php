<?php
/**
 * HR Employee Summaries — สรุปรายพนักงานรายเดือน (ผู้บริหาร / HR)
 */
$page_title = 'สรุปรายพนักงาน';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
if (!hr_can_access_hr_dashboard()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();
$summaryService = new EmployeeSummaryService($pdo);

$month = $_GET['month'] ?? (new PayrollService($pdo))->suggestPayrollMonth();
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = (new PayrollService($pdo))->suggestPayrollMonth();
}
$payrollSvcForPeriod = new PayrollService($pdo);
$payDayForPeriod = $payrollSvcForPeriod->getDefaultPayDay();
$summaryPeriod = $payrollSvcForPeriod->attendancePeriodBounds($month . '-01', $payDayForPeriod);
$reportFrom = $summaryPeriod['start'];
$reportTo = $summaryPeriod['end'];
$today = date('Y-m-d');
$summaryScanEnd = $payrollSvcForPeriod->attendanceClosedScanEnd($reportFrom, $reportTo, $today);
$summaryPeriodOpen = ($today <= $reportTo);
$summaryMonthNum = (int)substr($month, 5, 2);
$summaryMonthLabel = function_exists('thaiMonth') ? thaiMonth($summaryMonthNum) : $month;
$summaryYearBe = (int)substr($month, 0, 4) + 543;
$department = trim($_GET['department'] ?? '');

$departments = $pdo->query("
    SELECT DISTINCT department FROM users
    WHERE is_active = 1 AND department IS NOT NULL AND department != ''
    AND " . tp_hr_non_system_user_condition_sql('') . "
    ORDER BY department
")->fetchAll(PDO::FETCH_COLUMN);

$rows = $summaryService->getOrgMonthlySummaries($month, $department !== '' ? $department : null);
$orgKpi = $summaryService->getOrgMonthlyKpi($month);
$expandUserId = (int)($_GET['expand'] ?? 0);

$attendanceReturnQuery = ['month' => $month];
if ($department !== '') {
    $attendanceReturnQuery['department'] = $department;
}
$attendanceReturnUrl = '/hr/employee_summaries.php?' . http_build_query($attendanceReturnQuery);

$current_page = 'hr-employee-summaries';
include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1200px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">สรุปรายพนักงาน</span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">สรุปรายพนักงาน</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ภาพรวมวันทำงาน การลา วันหยุด การสลับวันหยุด และการขาดงาน — กด 「รายละเอียด」แล้วใช้ปุ่ม 「แก้ไขเวลา」 เพื่อไปแก้ที่หน้าจัดการลงเวลา</p>
        </div>
        <?php if (isCEOOrAbove()): ?>
        <a href="/hr/reports.php?report=attendance&from=<?php echo urlencode($reportFrom); ?>&to=<?php echo urlencode($reportTo); ?>"
           class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] font-medium touch-manipulation shrink-0">
            <i class="fas fa-file-export mr-2" aria-hidden="true"></i>รายงาน CEO
        </a>
        <?php endif; ?>
    </div>
</header>

<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="tp-native-form-group mb-0 min-w-[180px]">
            <label for="filter-month" class="text-white/70 text-sm font-medium">เดือน</label>
            <input type="month" id="filter-month" name="month" value="<?php echo htmlspecialchars($month); ?>"
                   class="input-field tp-native-input w-full min-h-[52px]" onchange="this.form.submit()">
        </div>
        <div class="tp-native-form-group mb-0 min-w-[200px] flex-1">
            <label for="filter-dept" class="text-white/70 text-sm font-medium">แผนก</label>
            <select id="filter-dept" name="department" class="input-field tp-native-select w-full" onchange="this.form.submit()">
                <option value="">ทุกแผนก</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 border border-violet-500/20 bg-violet-500/[0.06]" role="status" aria-live="polite">
    <div class="flex items-start gap-3 sm:gap-4">
        <div class="w-10 h-10 rounded-xl bg-violet-500/15 border border-violet-500/25 flex items-center justify-center shrink-0" aria-hidden="true">
            <i class="fas fa-calendar-range text-violet-300"></i>
        </div>
        <div class="min-w-0 flex-1 space-y-2">
            <p class="text-white font-semibold text-sm sm:text-base">
                รอบเงินเดือน <?php echo htmlspecialchars($summaryMonthLabel . ' ' . $summaryYearBe); ?>
                <span class="text-white/45 font-normal text-xs sm:text-sm ml-1">(เลือก <?php echo htmlspecialchars($month); ?>)</span>
            </p>
            <p class="text-white/75 text-sm leading-relaxed">
                ช่วงสรุปเข้างาน
                <strong class="text-violet-200 font-medium"><?php echo formatDateThai($reportFrom); ?></strong>
                –
                <strong class="text-violet-200 font-medium"><?php echo formatDateThai($reportTo); ?></strong>
                · ไม่ใช่ปฏิทิน 1–30 ของเดือน
            </p>
            <?php if ($summaryScanEnd !== ''): ?>
            <p class="text-white/65 text-sm leading-relaxed">
                ตัวเลขในตารางสรุปถึง
                <strong class="text-white/90"><?php echo formatDateThai($summaryScanEnd); ?></strong>
                <?php if ($summaryPeriodOpen && $today > $summaryScanEnd): ?>
                <span class="text-white/45">· ยังไม่รวมวันนี้ (<?php echo formatDateThai($today); ?>) จนกว่าจะจบวันทำงาน</span>
                <?php elseif ($summaryPeriodOpen && $today === $summaryScanEnd): ?>
                <span class="text-white/45">· วันนี้ยังไม่ถูกนับเป็นขาด/มาสายจนกว่าจะจบวัน</span>
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p class="text-amber-200/90 text-sm">รอบนี้ยังไม่เริ่ม — ยังไม่มีวันที่สรุปในตาราง</p>
            <?php endif; ?>
            <p class="text-white/45 text-xs leading-relaxed pt-1 border-t border-white/10">
                <i class="fas fa-shuffle text-violet-400/80 mr-1" aria-hidden="true"></i>
                หากพนักงาน<strong class="text-white/60">สลับวันหยุด</strong> วันที่เคยหยุดประจำอาจกลายเป็นวันทำงาน (และนับขาดถ้าไม่ลงเวลา) — ดูคำอธิบายในรายละเอียดแต่ละคน
            </p>
        </div>
    </div>
</div>

<div class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 border border-sky-500/15 bg-sky-500/[0.04]">
    <details class="group">
        <summary class="text-white text-sm font-semibold cursor-pointer list-none flex items-center justify-between gap-3 touch-manipulation">
            <span><i class="fas fa-circle-info text-sky-400 mr-2" aria-hidden="true"></i>วิธีแก้ไขข้อมูล</span>
            <i class="fas fa-chevron-down text-white/40 group-open:rotate-180 transition-transform text-xs shrink-0" aria-hidden="true"></i>
        </summary>
        <ul class="text-white/70 text-sm space-y-3 list-none mt-4 pt-4 border-t border-white/10 leading-relaxed">
            <li><strong class="text-amber-300">มาสาย / ขาด / ไม่มีลงเวลา</strong> — เลือกหลายวันแล้วกด <strong class="text-amber-200">แก้ทั้งกลุ่ม</strong> หรือ <strong class="text-amber-200">แก้ที่เลือก</strong> · หรือกด <strong class="text-amber-200">รายวัน</strong> เพื่อแก้ทีละวัน</li>
            <li><strong class="text-blue-300">ลา</strong> — พนักงานยื่นใบลา → HR อนุมัติที่ <a href="/hr/leaves.php?status=pending" class="text-violet-300 hover:text-violet-200 underline">อนุมัติการลา</a></li>
            <li><strong class="text-violet-300">สลับวันหยุด</strong> — CEO อนุมัติที่ <a href="/hr/dayoff_approvals.php" class="text-violet-300 hover:text-violet-200 underline">อนุมัติเปลี่ยนวันหยุด</a></li>
            <li><strong class="text-orange-300">มาทำงานวันหยุด / หยุดชดเชย</strong> — CEO อนุมัติที่ <a href="/hr/holiday_work_approvals.php" class="text-orange-300 hover:text-orange-200 underline">อนุมัติทำงานวันหยุด</a></li>
        </ul>
    </details>
</div>

<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 sm:gap-4 mb-6 min-w-0">
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">พนักงาน</p>
        <p class="text-2xl font-bold text-white tabular-nums mt-1"><?php echo (int)$orgKpi['employee_count']; ?></p>
    </div>
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">อัตราเข้างาน</p>
        <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo number_format((float)$orgKpi['attendance_rate'], 1); ?>%</p>
    </div>
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">มาสาย (รวม)</p>
        <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)$orgKpi['late_days']; ?></p>
    </div>
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">ขาด (รวม)</p>
        <p class="text-2xl font-bold text-red-400 tabular-nums mt-1"><?php echo (int)$orgKpi['absent_days']; ?></p>
    </div>
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">ลา (รวม)</p>
        <p class="text-2xl font-bold text-blue-400 tabular-nums mt-1"><?php echo number_format((float)$orgKpi['approved_leave_days'], 1); ?></p>
    </div>
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">สลับวันหยุด</p>
        <p class="text-2xl font-bold text-violet-400 tabular-nums mt-1"><?php echo (int)$orgKpi['dayoff_swaps']; ?></p>
    </div>
    <div class="stat-card tp-native-summary-card text-center py-4">
        <p class="text-slate-300 text-xs">มาทำงานวันหยุด</p>
        <p class="text-2xl font-bold text-orange-400 tabular-nums mt-1"><?php echo (int)($orgKpi['holiday_work_count'] ?? 0); ?></p>
    </div>
</div>

<div class="native-card tp-native-card tp-native-data-card min-w-0 overflow-hidden">
    <?php if ($rows): ?>
    <div class="md:hidden p-4 space-y-3">
        <?php foreach ($rows as $row): ?>
        <details class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 overflow-hidden group" data-emp-id="<?php echo (int)$row['id']; ?>">
            <summary class="p-4 cursor-pointer list-none touch-manipulation">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="text-white font-semibold truncate"><?php echo htmlspecialchars($row['name']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($row['employee_code']); ?> · <?php echo htmlspecialchars($row['department'] ?: '-'); ?></p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <?php if ((int)$row['absent_days'] > 0): ?>
                        <span class="px-2 py-0.5 rounded text-xs bg-red-500/20 text-red-300">ขาด <?php echo (int)$row['absent_days']; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down text-white/40 group-open:rotate-180 transition-transform text-xs" aria-hidden="true"></i>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 mt-3 text-center text-xs">
                    <div><span class="text-white/45 block">มา</span><span class="text-emerald-400 font-bold tabular-nums"><?php echo (int)$row['present_days']; ?></span></div>
                    <div><span class="text-white/45 block">สาย</span><span class="text-amber-400 font-bold tabular-nums"><?php echo (int)$row['late_days']; ?></span></div>
                    <div><span class="text-white/45 block">ลา</span><span class="text-blue-400 font-bold tabular-nums"><?php echo number_format((float)$row['approved_leave_days'], 1); ?></span></div>
                    <div><span class="text-white/45 block" title="ชั่วโมงทำงานสะสม (เข้า-ออกครบ)">ชม.</span><span class="text-white font-bold tabular-nums"><?php
                        echo (float)$row['work_hours'] > 0
                            ? number_format((float)$row['work_hours'], 0)
                            : '—';
                    ?></span></div>
                </div>
            </summary>
            <div class="px-4 pb-5 pt-4 border-t border-white/10 space-y-5">
                <?php $summary = $row['summary']; $panelLayout = true; $compact = false; $compactHidePresent = true; $showActions = true; $employeeId = (int)$row['id']; include dirname(__DIR__) . '/modules/hr/employee_monthly_summary_details.php'; ?>
                <div class="flex flex-col sm:flex-row flex-wrap gap-3 pt-2 border-t border-white/10">
                    <a href="/hr/attendance.php?date=<?php echo urlencode($month . '-01'); ?>&user_id=<?php echo (int)$row['id']; ?>"
                       class="inline-flex items-center justify-center min-h-[44px] px-4 py-2.5 bg-amber-500/15 hover:bg-amber-500/25 text-amber-200 rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
                        <i class="fas fa-user-clock mr-2" aria-hidden="true"></i>จัดการเวลา
                    </a>
                    <a href="/hr/employee_view.php?id=<?php echo (int)$row['id']; ?>&month=<?php echo urlencode($month); ?>"
                       class="inline-flex items-center justify-center min-h-[44px] px-4 py-2.5 bg-violet-500/15 hover:bg-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
                        ดูโปรไฟล์เต็ม
                    </a>
                </div>
            </div>
        </details>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto overscroll-x-contain">
        <table class="w-full" style="min-width:960px">
            <thead class="bg-white/5">
                <tr class="border-b border-white/10">
                    <th class="px-4 py-3 text-left text-white/65 text-xs font-medium uppercase">พนักงาน</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">ควรมา</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">มา</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">สาย</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">WFH</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">ลา</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">ขาด</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">หยุด</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">สลับหยุด</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">ทำงานวันหยุด</th>
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">
                        <span title="รวมชั่วโมงทำงานจริงจากวันที่ลงเวลาเข้า-ออกครบในเดือนนี้ (หักพัก)">ชม.ทำงาน</span>
                    </th>
                    <th class="px-4 py-3 text-center text-white/65 text-xs font-medium uppercase"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr class="border-b border-white/5 hover:bg-white/[0.04]">
                    <td class="px-4 py-3 min-w-0">
                        <div class="text-white font-medium truncate"><?php echo htmlspecialchars($row['name']); ?></div>
                        <div class="text-white/50 text-xs truncate"><?php echo htmlspecialchars($row['employee_code']); ?> · <?php echo htmlspecialchars($row['department'] ?: '-'); ?></div>
                    </td>
                    <td class="px-3 py-3 text-center text-white/80 tabular-nums"><?php echo (int)$row['expected_work_days']; ?></td>
                    <td class="px-3 py-3 text-center text-emerald-400 tabular-nums font-medium"><?php echo (int)$row['present_days']; ?></td>
                    <td class="px-3 py-3 text-center text-amber-400 tabular-nums"><?php echo (int)$row['late_days']; ?></td>
                    <td class="px-3 py-3 text-center text-purple-400 tabular-nums"><?php echo (int)$row['wfh_days']; ?></td>
                    <td class="px-3 py-3 text-center text-blue-400 tabular-nums"><?php echo number_format((float)$row['approved_leave_days'], 1); ?></td>
                    <td class="px-3 py-3 text-center <?php echo (int)$row['absent_days'] > 0 ? 'text-red-400 font-semibold' : 'text-white/50'; ?> tabular-nums"><?php echo (int)$row['absent_days']; ?></td>
                    <td class="px-3 py-3 text-center text-white/60 tabular-nums"><?php echo (int)$row['holiday_days'] + (int)$row['scheduled_off_days']; ?></td>
                    <td class="px-3 py-3 text-center text-violet-300 tabular-nums"><?php echo (int)$row['dayoff_swap_count']; ?></td>
                    <td class="px-3 py-3 text-center text-orange-300 tabular-nums"><?php echo (int)($row['holiday_work_count'] ?? 0); ?></td>
                    <td class="px-3 py-3 text-center tabular-nums">
                        <?php if ((float)$row['work_hours'] > 0): ?>
                        <span class="text-white font-medium"><?php echo number_format((float)$row['work_hours'], 1); ?></span>
                        <?php if ((int)$row['days_with_work_hours'] > 0): ?>
                        <span class="block text-[10px] text-white/40 mt-0.5"><?php echo (int)$row['days_with_work_hours']; ?> วัน</span>
                        <?php endif; ?>
                        <?php elseif ((int)$row['incomplete_checkout_days'] > 0): ?>
                        <span class="text-amber-400/90 text-sm" title="มีการลงเวลาเข้าแต่ยังไม่มีเวลาออก — ชม.จะคำนวณเมื่อออกงานครบ">—</span>
                        <span class="block text-[10px] text-amber-400/70 mt-0.5">รอออก <?php echo (int)$row['incomplete_checkout_days']; ?></span>
                        <?php else: ?>
                        <span class="text-white/30">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button type="button"
                                class="emp-summary-toggle inline-flex items-center justify-center min-h-[40px] px-3 py-1.5 bg-violet-500/15 hover:bg-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] text-xs font-medium touch-manipulation"
                                aria-expanded="false"
                                data-target="emp-detail-<?php echo (int)$row['id']; ?>">
                            <i class="fas fa-chevron-down mr-1 transition-transform emp-summary-chevron" aria-hidden="true"></i>
                            รายละเอียด
                        </button>
                    </td>
                </tr>
                <tr id="emp-detail-<?php echo (int)$row['id']; ?>" class="hidden border-b border-white/10 bg-black/25">
                    <td colspan="11" class="p-0 align-top">
                        <div class="px-6 py-8 sm:px-8 sm:py-10 border-y border-white/10">
                            <?php $summary = $row['summary']; $panelLayout = true; $compact = false; $compactHidePresent = true; $showActions = true; $employeeId = (int)$row['id']; include dirname(__DIR__) . '/modules/hr/employee_monthly_summary_details.php'; ?>
                            <div class="mt-8 pt-6 border-t border-white/10 flex flex-wrap gap-3">
                                <a href="/hr/attendance.php?date=<?php echo urlencode($month . '-01'); ?>&user_id=<?php echo (int)$row['id']; ?>"
                                   class="inline-flex items-center justify-center min-h-[44px] px-5 py-2.5 bg-amber-500/15 hover:bg-amber-500/25 text-amber-200 rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
                                    <i class="fas fa-user-clock mr-2" aria-hidden="true"></i>จัดการเวลา
                                </a>
                                <a href="/hr/leaves.php?status=pending"
                                   class="inline-flex items-center justify-center min-h-[44px] px-5 py-2.5 bg-blue-500/15 hover:bg-blue-500/25 text-blue-200 rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
                                    <i class="fas fa-calendar-check mr-2" aria-hidden="true"></i>อนุมัติลา
                                </a>
                                <a href="/hr/employee_view.php?id=<?php echo (int)$row['id']; ?>&month=<?php echo urlencode($month); ?>"
                                   class="inline-flex items-center justify-center min-h-[44px] px-5 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
                                    โปรไฟล์เต็ม
                                </a>
                                <a href="/hr/employee_attendance.php?id=<?php echo (int)$row['id']; ?>&month=<?php echo urlencode($month); ?>"
                                   class="inline-flex items-center justify-center min-h-[44px] px-5 py-2.5 bg-violet-500/15 hover:bg-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
                                    <i class="fas fa-calendar-day mr-2" aria-hidden="true"></i>ปฏิทินรายวัน
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="px-4 py-3 text-white/45 text-xs border-t border-white/10 leading-relaxed">
            <strong class="text-white/60">ชม.ทำงาน</strong> = ผลรวมเวลาทำงานจริง (เวลาออก − เวลาเข้า − พัก) เฉพาะวันที่ลงเวลาเข้าและออกครบ
            · แสดง 「—」 หากยังไม่มีวันที่ครบคู่ หรือ 「รอออก N」 หากเข้างานแล้วแต่ยังไม่ออก
        </p>
    </div>
    <?php else: ?>
    <div class="tp-native-empty-state text-center py-12 px-4">
        <i class="fas fa-users-slash text-4xl text-slate-500 mb-3 block" aria-hidden="true"></i>
        <p class="text-white/60">ไม่พบพนักงานในตัวกรองนี้</p>
    </div>
    <?php endif; ?>
</div>
</div>

<?php
$bulkDefaultCheckIn = substr((string)(getSetting('default_work_start', '08:45') ?? '08:45'), 0, 5);
$bulkDefaultCheckOut = substr((string)(getSetting('default_work_end', '17:30') ?? '17:30'), 0, 5);
$bulkReloadBase = '/hr/employee_summaries.php?' . http_build_query(array_filter([
    'month' => $month,
    'department' => $department !== '' ? $department : null,
]));
include dirname(__DIR__) . '/modules/hr/employee_summary_bulk_attendance_modal.php';
?>

<script>
function tpHrExpandEmployeeSummary(targetId, triggerBtn) {
    var row = document.getElementById(targetId);
    if (!row) return;
    row.classList.remove('hidden');
    if (triggerBtn) {
        triggerBtn.setAttribute('aria-expanded', 'true');
        var chevron = triggerBtn.querySelector('.emp-summary-chevron');
        if (chevron) chevron.classList.add('rotate-180');
    }
    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

document.querySelectorAll('.emp-summary-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-target');
        var row = document.getElementById(id);
        if (!row) return;
        var open = row.classList.toggle('hidden') === false;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        var chevron = btn.querySelector('.emp-summary-chevron');
        if (chevron) chevron.classList.toggle('rotate-180', open);
    });
});

(function () {
    var expandUid = <?php echo (int)$expandUserId; ?>;
    if (expandUid <= 0) return;
    var mobileDetails = document.querySelector('details[data-emp-id="' + expandUid + '"]');
    if (mobileDetails) {
        mobileDetails.open = true;
        mobileDetails.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    var desktopBtn = document.querySelector('.emp-summary-toggle[data-target="emp-detail-' + expandUid + '"]');
    tpHrExpandEmployeeSummary('emp-detail-' + expandUid, desktopBtn);
})();
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
