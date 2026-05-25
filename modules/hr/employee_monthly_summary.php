<?php
/**
 * Partial: สรุปรายเดือนรายพนักงาน (ใช้ใน employee_view + employee_summaries)
 * @var array $summary จาก EmployeeSummaryService::getMonthlySummary()
 * @var int $employeeId
 */
if (empty($summary) || !is_array($summary)) {
    return;
}
$c = $summary['counts'] ?? [];
$dayNames = defined('THAI_DAY_NAMES') ? THAI_DAY_NAMES : ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
$defaultOffLabel = $dayNames[(int)($summary['default_day_off'] ?? 0)] ?? '-';
?>

<section class="native-card tp-native-card tp-native-data-card p-5 sm:p-6 mb-6 min-w-0 max-w-full overflow-hidden" aria-labelledby="emp-monthly-summary-title">
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between mb-5">
        <div class="min-w-0">
            <h2 id="emp-monthly-summary-title" class="section-title mb-1 text-white text-base sm:text-lg">
                <i class="fas fa-chart-pie text-violet-400 mr-2 text-xl" aria-hidden="true"></i>
                สรุปรายเดือน — <?php echo htmlspecialchars($summary['month_label'] ?? ''); ?>
            </h2>
            <p class="text-white/55 text-sm">
                ช่วง <?php echo formatDateThai($summary['period_start'] ?? ''); ?>
                – <?php echo formatDateThai($summary['period_end'] ?? ''); ?>
                · วันหยุดประจำ: <?php echo htmlspecialchars($defaultOffLabel); ?>
            </p>
        </div>
        <?php if (!empty($showMonthPicker)): ?>
        <form method="GET" class="flex items-end gap-2 shrink-0">
            <?php if (!empty($preserveQuery)): foreach ($preserveQuery as $k => $v): ?>
            <input type="hidden" name="<?php echo htmlspecialchars((string)$k); ?>" value="<?php echo htmlspecialchars((string)$v); ?>">
            <?php endforeach; endif; ?>
            <div class="tp-native-form-group mb-0 min-w-[180px]">
                <label for="emp-summary-month" class="text-white/70 text-sm font-medium">เดือน</label>
                <input type="month" id="emp-summary-month" name="month" value="<?php echo htmlspecialchars($summary['month'] ?? date('Y-m')); ?>"
                       class="input-field tp-native-input w-full min-h-[52px]" onchange="this.form.submit()">
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 sm:gap-4 mb-6">
        <div class="stat-card tp-native-summary-card text-center py-4 min-w-0">
            <p class="text-slate-300 text-xs">วันทำงาน (ควรมา)</p>
            <p class="text-2xl font-bold text-white tabular-nums mt-1"><?php echo (int)($c['expected_work_days'] ?? 0); ?></p>
        </div>
        <div class="stat-card tp-native-summary-card text-center py-4 min-w-0">
            <p class="text-slate-300 text-xs">มาทำงาน</p>
            <p class="text-2xl font-bold text-emerald-400 tabular-nums mt-1"><?php echo (int)($c['present_days'] ?? 0); ?></p>
        </div>
        <div class="stat-card tp-native-summary-card text-center py-4 min-w-0">
            <p class="text-slate-300 text-xs">มาสาย</p>
            <p class="text-2xl font-bold text-amber-400 tabular-nums mt-1"><?php echo (int)($c['late_days'] ?? 0); ?></p>
        </div>
        <div class="stat-card tp-native-summary-card text-center py-4 min-w-0">
            <p class="text-slate-300 text-xs">WFH</p>
            <p class="text-2xl font-bold text-purple-400 tabular-nums mt-1"><?php echo (int)($c['wfh_days'] ?? 0); ?></p>
        </div>
        <div class="stat-card tp-native-summary-card text-center py-4 min-w-0">
            <p class="text-slate-300 text-xs">ลา / ขาด</p>
            <p class="text-2xl font-bold text-blue-400 tabular-nums mt-1"><?php echo (int)($c['leave_days'] ?? 0); ?></p>
            <p class="text-red-400 text-xs tabular-nums mt-0.5">ขาด <?php echo (int)($c['absent_days'] ?? 0); ?></p>
        </div>
        <div class="stat-card tp-native-summary-card text-center py-4 min-w-0">
            <p class="text-slate-300 text-xs">ชม.ทำงาน</p>
            <p class="text-2xl font-bold text-white tabular-nums mt-1"><?php echo number_format((float)($summary['work_hours'] ?? 0), 1); ?></p>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 px-4 py-3 min-w-0">
            <p class="text-white/55 text-xs">วันหยุดนักขัตฤกษ์/บริษัท</p>
            <p class="text-white font-semibold tabular-nums mt-1"><?php echo (int)($c['holiday_days'] ?? 0); ?> วัน</p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 px-4 py-3 min-w-0">
            <p class="text-white/55 text-xs">วันหยุดประจำสัปดาห์</p>
            <p class="text-white font-semibold tabular-nums mt-1"><?php echo (int)($c['scheduled_off_days'] ?? 0); ?> วัน</p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 px-4 py-3 min-w-0">
            <p class="text-white/55 text-xs">ลาอนุมัติ (ใบลา)</p>
            <p class="text-white font-semibold tabular-nums mt-1"><?php echo number_format((float)($summary['approved_leave_days'] ?? 0), 1); ?> วัน</p>
        </div>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 px-4 py-3 min-w-0">
            <p class="text-white/55 text-xs">สลับวันหยุด / รออนุมัติ</p>
            <p class="text-white font-semibold tabular-nums mt-1"><?php echo count($summary['dayoff_swaps'] ?? []); ?> / <?php echo (int)($summary['pending_leave_requests'] ?? 0); ?></p>
        </div>
    </div>

    <?php if (!empty($summary['leave_by_type'])): ?>
    <div class="mb-6">
        <h3 class="text-white/80 text-sm font-semibold mb-3">การลาในเดือนนี้ (ตามประเภท)</h3>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($summary['leave_by_type'] as $lt): ?>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium border border-white/10"
                  style="background-color: <?php echo htmlspecialchars($lt['color'] ?? '#6B7280'); ?>20; color: <?php echo htmlspecialchars($lt['color'] ?? '#e5e7eb'); ?>">
                <?php echo htmlspecialchars($lt['name'] ?? $lt['code']); ?>
                <span class="tabular-nums font-bold"><?php echo number_format((float)$lt['days'], 1); ?></span>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="mb-6 pt-4 border-t border-white/10">
        <h3 class="text-white/85 text-sm font-semibold mb-5">
            <i class="fas fa-list-ul text-violet-400 mr-2" aria-hidden="true"></i>รายละเอียดรายวัน
        </h3>
        <?php
        $showActions = !empty($employeeId);
        include __DIR__ . '/employee_monthly_summary_details.php';
        ?>
    </div>

    <?php if (!empty($summary['leave_entitlements'])): ?>
    <div>
        <h3 class="text-white/80 text-sm font-semibold mb-3">สิทธิ์วันลาคงเหลือ (ปี <?php echo (int)date('Y', strtotime($summary['period_start'] ?? date('Y-m-d'))) + 543; ?>)</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <?php foreach ($summary['leave_entitlements'] as $ent): ?>
            <div class="flex items-center justify-between gap-2 rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 px-4 py-2.5 min-w-0">
                <span class="text-white/80 text-sm truncate"><?php echo htmlspecialchars($ent['name'] ?? ''); ?></span>
                <span class="text-white font-semibold tabular-nums shrink-0"><?php echo number_format((float)$ent['remaining_days'], 1); ?> วัน</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($employeeId)): ?>
    <div class="mt-6 pt-4 border-t border-white/10 flex flex-wrap gap-3">
        <a href="/hr/employee_attendance.php?id=<?php echo (int)$employeeId; ?>&month=<?php echo urlencode($summary['month'] ?? date('Y-m')); ?>"
           class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-violet-500/15 hover:bg-violet-500/25 border border-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
            <i class="fas fa-calendar-day mr-2" aria-hidden="true"></i>ดูปฏิทินรายวัน
        </a>
        <a href="/leave_history.php?year=<?php echo (int)date('Y', strtotime($summary['period_start'] ?? date('Y-m-d'))); ?>"
           class="inline-flex items-center justify-center min-h-[48px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] text-sm font-medium touch-manipulation">
            <i class="fas fa-file-alt mr-2" aria-hidden="true"></i>ประวัติใบลา
        </a>
    </div>
    <?php endif; ?>
</section>
