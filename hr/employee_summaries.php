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

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$department = trim($_GET['department'] ?? '');

$departments = $pdo->query("
    SELECT DISTINCT department FROM users
    WHERE is_active = 1 AND department IS NOT NULL AND department != ''
    AND id NOT IN (" . SYSTEM_USER_IDS_SQL . ")
    ORDER BY department
")->fetchAll(PDO::FETCH_COLUMN);

$rows = $summaryService->getOrgMonthlySummaries($month, $department !== '' ? $department : null);
$orgKpi = $summaryService->getOrgMonthlyKpi($month);

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
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ภาพรวมวันทำงาน การลา วันหยุด การสลับวันหยุด และการขาดงาน — สำหรับผู้บริหารและ HR</p>
        </div>
        <?php if (isCEOOrAbove()): ?>
        <a href="/hr/reports.php?report=attendance&from=<?php echo urlencode($month . '-01'); ?>&to=<?php echo urlencode(date('Y-m-t', strtotime($month . '-01'))); ?>"
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
</div>

<div class="native-card tp-native-card tp-native-data-card min-w-0 overflow-hidden">
    <?php if ($rows): ?>
    <div class="md:hidden p-4 space-y-3">
        <?php foreach ($rows as $row): ?>
        <details class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 overflow-hidden group">
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
                    <div><span class="text-white/45 block">ชม.</span><span class="text-white font-bold tabular-nums"><?php echo number_format((float)$row['work_hours'], 0); ?></span></div>
                </div>
            </summary>
            <div class="px-4 pb-4 pt-0 border-t border-white/10">
                <?php $summary = $row['summary']; $compact = true; $compactHidePresent = true; include dirname(__DIR__) . '/modules/hr/employee_monthly_summary_details.php'; ?>
                <a href="/hr/employee_view.php?id=<?php echo (int)$row['id']; ?>&month=<?php echo urlencode($month); ?>"
                   class="mt-3 inline-flex items-center justify-center min-h-[44px] w-full px-3 py-2 bg-violet-500/15 hover:bg-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] text-xs font-medium touch-manipulation">
                    ดูโปรไฟล์เต็ม
                </a>
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
                    <th class="px-3 py-3 text-center text-white/65 text-xs font-medium uppercase">ชม.</th>
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
                    <td class="px-3 py-3 text-center text-white tabular-nums"><?php echo number_format((float)$row['work_hours'], 1); ?></td>
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
                <tr id="emp-detail-<?php echo (int)$row['id']; ?>" class="hidden border-b border-white/5 bg-black/20">
                    <td colspan="11" class="px-4 py-4">
                        <?php $summary = $row['summary']; $compact = true; $compactHidePresent = true; include dirname(__DIR__) . '/modules/hr/employee_monthly_summary_details.php'; ?>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="/hr/employee_view.php?id=<?php echo (int)$row['id']; ?>&month=<?php echo urlencode($month); ?>"
                               class="inline-flex items-center justify-center min-h-[40px] px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] text-xs font-medium touch-manipulation">
                                โปรไฟล์เต็ม
                            </a>
                            <a href="/hr/employee_attendance.php?id=<?php echo (int)$row['id']; ?>&month=<?php echo urlencode($month); ?>"
                               class="inline-flex items-center justify-center min-h-[40px] px-3 py-1.5 bg-violet-500/15 hover:bg-violet-500/25 text-violet-200 rounded-[var(--tp-ios-card-radius)] text-xs font-medium touch-manipulation">
                                ปฏิทินรายวัน
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="tp-native-empty-state text-center py-12 px-4">
        <i class="fas fa-users-slash text-4xl text-slate-500 mb-3 block" aria-hidden="true"></i>
        <p class="text-white/60">ไม่พบพนักงานในตัวกรองนี้</p>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
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
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
