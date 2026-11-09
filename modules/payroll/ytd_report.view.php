<?php
if (!defined('BASE_PATH')) {http_response_code(403);exit;}
/**
 * รายงานรายได้สะสมรายปี (Year-to-Date Report)
 * Variables available: $pdo, $current_user
 */

$ytd_year = (int)($_GET['year'] ?? date('Y'));
$years_available = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT YEAR(r.payroll_month) as yr FROM payroll_runs r ORDER BY yr DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $years_available[] = (int)$row['yr'];
    }
} catch (Exception $e) { /* ignore */ }
if (empty($years_available)) $years_available = [(int)date('Y')];
if (!in_array($ytd_year, $years_available)) $ytd_year = $years_available[0];

// Query YTD summary per employee
$stmt = $pdo->prepare("
    SELECT 
        u.id as user_id,
        u.employee_code,
        u.first_name_th,
        u.last_name_th,
        u.department,
        u.position,
        COUNT(s.id) as slip_count,
        COALESCE(SUM(s.gross_salary), 0) as ytd_salary,
        COALESCE(SUM(s.bonus), 0) as ytd_bonus,
        COALESCE(SUM(s.allowances), 0) as ytd_allowances,
        COALESCE(SUM(s.total_income), 0) as ytd_income,
        COALESCE(SUM(s.tax_withheld), 0) as ytd_tax,
        COALESCE(SUM(s.provident_fund), 0) as ytd_pf,
        COALESCE(SUM(s.social_security), 0) as ytd_ss,
        COALESCE(SUM(s.group_insurance), 0) as ytd_gi,
        COALESCE(SUM(s.health_insurance), 0) as ytd_hi,
        COALESCE(SUM(s.total_deductions), 0) as ytd_deductions,
        COALESCE(SUM(s.net_salary), 0) as ytd_net
    FROM payroll_slips s
    JOIN payroll_runs r ON s.payroll_run_id = r.id
    JOIN users u ON s.user_id = u.id
    WHERE YEAR(r.payroll_month) = ?
      AND " . payroll_employee_filter_sql('u') . "
    GROUP BY u.id, u.employee_code, u.first_name_th, u.last_name_th, u.department, u.position
    ORDER BY u.employee_code, u.first_name_th
");
$stmt->execute([$ytd_year]);
$ytd_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grand totals
$grand = ['income' => 0, 'tax' => 0, 'ss' => 0, 'pf' => 0, 'gi' => 0, 'hi' => 0, 'deductions' => 0, 'net' => 0];
foreach ($ytd_data as $row) {
    $grand['income'] += $row['ytd_income'];
    $grand['tax'] += $row['ytd_tax'];
    $grand['ss'] += $row['ytd_ss'];
    $grand['pf'] += $row['ytd_pf'];
    $grand['gi'] += $row['ytd_gi'];
    $grand['hi'] += $row['ytd_hi'];
    $grand['deductions'] += $row['ytd_deductions'];
    $grand['net'] += $row['ytd_net'];
}
?>

<div class="glass-card rounded-2xl p-6 mb-6 border border-white/10">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
        <h3 class="text-base font-semibold text-white flex items-center gap-2">
            <i class="fas fa-chart-bar text-violet-400"></i> รายงานรายได้สะสมรายปี / Annual Income Report
        </h3>
        <form method="get" class="flex items-center gap-3">
            <input type="hidden" name="action" value="ytd_report">
            <label class="text-sm text-white/70">ปี / Year:</label>
            <select name="year" onchange="this.form.submit()" class="rounded-xl border border-white/20 bg-white/5 text-white px-4 py-2 focus:ring-2 focus:ring-violet-500">
                <?php foreach ($years_available as $yr): ?>
                <option value="<?php echo $yr; ?>" <?php echo $yr === $ytd_year ? 'selected' : ''; ?>><?php echo $yr; ?> (พ.ศ. <?php echo $yr + 543; ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="rounded-xl bg-white/5 border border-white/10 p-4">
            <div class="text-xs text-white/50 mb-1">จำนวนพนักงาน / Employees</div>
            <div class="text-xl font-bold text-white"><?php echo count($ytd_data); ?></div>
        </div>
        <div class="rounded-xl bg-white/5 border border-white/10 p-4">
            <div class="text-xs text-white/50 mb-1">รายได้รวม / Total Income</div>
            <div class="text-xl font-bold text-emerald-400">฿<?php echo number_format($grand['income'], 2); ?></div>
        </div>
        <div class="rounded-xl bg-white/5 border border-white/10 p-4">
            <div class="text-xs text-white/50 mb-1">หักรวม / Total Deduction</div>
            <div class="text-xl font-bold text-rose-400">฿<?php echo number_format($grand['deductions'], 2); ?></div>
        </div>
        <div class="rounded-xl bg-white/5 border border-white/10 p-4">
            <div class="text-xs text-white/50 mb-1">สุทธิรวม / Total Net</div>
            <div class="text-xl font-bold text-violet-400">฿<?php echo number_format($grand['net'], 2); ?></div>
        </div>
    </div>

    <?php if (empty($ytd_data)): ?>
    <div class="text-center py-12 text-white/50">
        <i class="fas fa-inbox text-4xl mb-3 block"></i>
        <p>ไม่พบข้อมูลสลิปเงินเดือนในปี <?php echo $ytd_year; ?></p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-left responsive-table">
            <thead>
                <tr class="bg-white/5">
                    <th class="px-4 py-3 text-xs font-medium text-white/60">รหัส / Emp.ID</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60">ชื่อ-นามสกุล / Name</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60">ตำแหน่ง / Position</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-center">จำนวนเดือน</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">รายได้รวม / Income</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">ภาษี / Tax</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">ประกันสังคม / SS</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">กองทุนฯ / PF</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">ประกันกลุ่ม / GI</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">ประกันสุขภาพ / HI</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">หักรวม / Deduction</th>
                    <th class="px-4 py-3 text-xs font-medium text-white/60 text-right">สุทธิรวม / Net</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($ytd_data as $row): ?>
                <tr class="table-row transition-colors hover:bg-white/5">
                    <td class="px-4 py-3 font-mono text-purple-300 text-sm"><?php echo htmlspecialchars($row['employee_code'] ?: '-'); ?></td>
                    <td class="px-4 py-3 font-medium text-white text-sm"><?php echo htmlspecialchars($row['first_name_th'] . ' ' . $row['last_name_th']); ?></td>
                    <td class="px-4 py-3 text-white/70 text-sm"><?php echo htmlspecialchars($row['position'] ?: '-'); ?></td>
                    <td class="px-4 py-3 text-center text-white/80 text-sm"><?php echo (int)$row['slip_count']; ?></td>
                    <td class="px-4 py-3 text-right text-emerald-400 text-sm font-medium"><?php echo number_format($row['ytd_income'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white/80 text-sm"><?php echo number_format($row['ytd_tax'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white/80 text-sm"><?php echo number_format($row['ytd_ss'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white/80 text-sm"><?php echo number_format($row['ytd_pf'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white/80 text-sm"><?php echo number_format($row['ytd_gi'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white/80 text-sm"><?php echo number_format($row['ytd_hi'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-rose-400 text-sm"><?php echo number_format($row['ytd_deductions'], 2); ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-violet-400 text-sm"><?php echo number_format($row['ytd_net'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-white/5 font-semibold">
                    <td class="px-4 py-3 text-white text-sm" colspan="4">รวมทั้งหมด / Grand Total</td>
                    <td class="px-4 py-3 text-right text-emerald-400 text-sm"><?php echo number_format($grand['income'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white text-sm"><?php echo number_format($grand['tax'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white text-sm"><?php echo number_format($grand['ss'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white text-sm"><?php echo number_format($grand['pf'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white text-sm"><?php echo number_format($grand['gi'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-white text-sm"><?php echo number_format($grand['hi'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-rose-400 text-sm"><?php echo number_format($grand['deductions'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-violet-400 text-sm"><?php echo number_format($grand['net'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>
