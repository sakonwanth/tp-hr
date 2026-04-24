<?php
/**
 * Payslip Page
 * สลิปเงินเดือน - ดูและดาวน์โหลดสลิปเงินเดือน
 */

$page_title = 'สลิปเงินเดือน';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();

// Handle download/print action - use CRM-style print template
$action = $_GET['action'] ?? '';
$downloadSlipId = (int)($_GET['slip_id'] ?? 0);

if ($action === 'download' && $downloadSlipId > 0) {
    // Get slip data for print template
    $stmt = $pdo->prepare("
        SELECT ps.*, pr.payroll_month, pr.pay_day, pr.status as run_status,
               emp.title as emp_title, emp.first_name_th, emp.last_name_th, emp.employee_code, emp.department, emp.position
        FROM payroll_slips ps
        JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
        JOIN users emp ON ps.user_id = emp.id
        WHERE ps.id = ? AND ps.user_id = ? AND pr.status IN ('approved', 'paid')
    ");
    $stmt->execute([$downloadSlipId, $user['id']]);
    $slip = $stmt->fetch();
    
    if ($slip) {
        // Get YTD for print template
        $ytd_year = (int)date('Y', strtotime($slip['payroll_month']));
        $stmt_ytd = $pdo->prepare("
            SELECT 
                COALESCE(SUM(s.total_income), 0) as ytd_income,
                COALESCE(SUM(s.tax_withheld), 0) as ytd_tax,
                COALESCE(SUM(s.social_security), 0) as ytd_ss,
                COALESCE(SUM(s.provident_fund), 0) as ytd_pf,
                COALESCE(SUM(s.total_deductions), 0) as ytd_deductions,
                COALESCE(SUM(s.net_salary), 0) as ytd_net
            FROM payroll_slips s
            JOIN payroll_runs r ON s.payroll_run_id = r.id
            WHERE s.user_id = ? AND YEAR(r.payroll_month) = ? AND r.payroll_month <= ?
        ");
        $stmt_ytd->execute([$slip['user_id'], $ytd_year, $slip['payroll_month']]);
        $ytd = $stmt_ytd->fetch(PDO::FETCH_ASSOC);
        
        // Include print template and exit
        include __DIR__ . '/modules/employee/payslip/print_template.php';
        exit;
    }
}

// Get current year and month filter
$year = (int)($_GET['year'] ?? date('Y'));
$viewMonth = $_GET['month'] ?? '';
$viewSlipId = (int)($_GET['slip_id'] ?? 0);

// Get user's payroll slips - connect to tp-crm payroll_slips table
// แสดงเฉพาะรอบที่อนุมัติแล้ว (approved/paid) เท่านั้น ซ่อน draft/calculated ตามกระบวนการ CRM
$stmt = $pdo->prepare("
    SELECT ps.*, pr.payroll_month, pr.status as run_status,
           pr.approved_by, u.first_name_th as approver_first, u.last_name_th as approver_last
    FROM payroll_slips ps
    JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
    LEFT JOIN users u ON pr.approved_by = u.id
    WHERE ps.user_id = ? AND YEAR(pr.payroll_month) = ?
    AND pr.status IN ('approved', 'paid')
    ORDER BY pr.payroll_month DESC
");
$stmt->execute([$user['id'], $year]);
$slips = $stmt->fetchAll();

// Get available years
$stmtYears = $pdo->prepare("
    SELECT DISTINCT YEAR(pr.payroll_month) as year 
    FROM payroll_slips ps
    JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
    WHERE ps.user_id = ?
    ORDER BY year DESC
");
$stmtYears->execute([$user['id']]);
$availableYears = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $availableYears)) {
    $availableYears[] = $year;
    rsort($availableYears);
}

// Get single slip if requested
$slip = null;
if ($viewSlipId > 0) {
    $stmt = $pdo->prepare("
        SELECT ps.*, pr.payroll_month, pr.status as run_status,
               pr.approved_by, u.first_name_th as approver_first, u.last_name_th as approver_last,
               emp.title as emp_title, emp.first_name_th, emp.last_name_th, emp.employee_code, emp.department, emp.position
        FROM payroll_slips ps
        JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
        LEFT JOIN users u ON pr.approved_by = u.id
        JOIN users emp ON ps.user_id = emp.id
        WHERE ps.id = ? AND ps.user_id = ? AND pr.status IN ('approved', 'paid')
    ");
    $stmt->execute([$viewSlipId, $user['id']]);
    $slip = $stmt->fetch();
}

// Get YTD summary (year-to-date)
$stmtYTD = $pdo->prepare("
    SELECT 
        SUM(ps.total_income) as ytd_income,
        SUM(ps.tax_withheld) as ytd_tax,
        SUM(ps.social_security) as ytd_ss,
        SUM(ps.provident_fund) as ytd_pf,
        SUM(ps.net_salary) as ytd_net,
        COUNT(*) as slip_count
    FROM payroll_slips ps
    JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
    WHERE ps.user_id = ? AND YEAR(pr.payroll_month) = ?
    AND pr.status IN ('approved', 'paid')
");
$stmtYTD->execute([$user['id'], $year]);
$ytd = $stmtYTD->fetch();

// Get latest slip
$latestSlip = !empty($slips) ? $slips[0] : null;

include 'templates/header.php';
?>

<?php if ($slip): ?>
<!-- Slip Detail View -->
<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="payslip.php" class="hover:text-white">สลิปเงินเดือน</a>
        <span class="mx-2">/</span>
        <span class="text-white">รายละเอียดสลิป</span>
    </nav>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-white">
            สลิปเงินเดือน <?php echo thaiMonth(date('n', strtotime($slip['payroll_month']))); ?> <?php echo date('Y', strtotime($slip['payroll_month'])) + 543; ?>
        </h1>
        <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
            <a href="payslip.php?action=download&slip_id=<?php echo $slip['id']; ?>"
               class="w-full sm:w-auto min-h-[44px] px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl transition-colors inline-flex items-center justify-center">
                <i class="fas fa-print mr-2"></i>พิมพ์
            </a>
            <a href="payslip.php?action=download&slip_id=<?php echo $slip['id']; ?>" target="_blank"
               class="w-full sm:w-auto min-h-[44px] px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-xl transition-colors inline-flex items-center justify-center font-semibold">
                <i class="fas fa-download mr-2"></i>ดาวน์โหลด PDF
            </a>
        </div>
    </div>
</div>

<!-- Payslip Card -->
<div class="glass-card rounded-xl p-6 print:bg-white print:text-black" id="payslip-content">
    <!-- Header -->
    <div class="flex items-start justify-between mb-6 pb-6 border-b border-white/10 print:border-gray-200">
        <div>
            <h2 class="text-xl font-bold text-white print:text-black">บริษัท ทีพี โฮม จำกัด</h2>
            <p class="text-white/60 print:text-gray-600 text-sm">TP Home Company Limited</p>
        </div>
        <div class="text-right">
            <p class="text-white/60 print:text-gray-600 text-sm">ใบแสดงรายได้</p>
            <p class="text-white print:text-black font-medium">
                ประจำเดือน <?php echo thaiMonth(date('n', strtotime($slip['payroll_month']))); ?> <?php echo date('Y', strtotime($slip['payroll_month'])) + 543; ?>
            </p>
            <?php if ($slip['run_status'] === 'paid'): ?>
            <p class="text-white/50 print:text-gray-500 text-sm">สถานะ: จ่ายแล้ว</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Employee Info -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 pb-6 border-b border-white/10 print:border-gray-200">
        <div>
            <p class="text-white/50 print:text-gray-500 text-sm">รหัสพนักงาน</p>
            <p class="text-white print:text-black font-medium"><?php echo htmlspecialchars($slip['employee_code'] ?? '-'); ?></p>
        </div>
        <div>
            <p class="text-white/50 print:text-gray-500 text-sm">ชื่อ-นามสกุล</p>
            <p class="text-white print:text-black font-medium"><?php echo htmlspecialchars($slip['first_name_th'] . ' ' . $slip['last_name_th']); ?></p>
        </div>
        <div>
            <p class="text-white/50 print:text-gray-500 text-sm">แผนก</p>
            <p class="text-white print:text-black"><?php echo htmlspecialchars($slip['department'] ?? '-'); ?></p>
        </div>
        <div>
            <p class="text-white/50 print:text-gray-500 text-sm">ตำแหน่ง</p>
            <p class="text-white print:text-black"><?php echo htmlspecialchars($slip['position'] ?? '-'); ?></p>
        </div>
    </div>
    
    <!-- Income & Deduction -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <!-- Income -->
        <div>
            <h3 class="text-lg font-semibold text-green-400 print:text-green-600 mb-4">
                <i class="fas fa-plus-circle mr-2"></i>รายได้
            </h3>
            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">เงินเดือน</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['gross_salary'], 2); ?></span>
                </div>
                <?php if ($slip['bonus'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">โบนัส</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['bonus'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($slip['allowances'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">ค่าเบี้ยเลี้ยง/สวัสดิการ</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['allowances'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php 
                // Other income items
                if (!empty($slip['income_other_json'])) {
                    $otherIncomes = json_decode($slip['income_other_json'], true);
                    if (is_array($otherIncomes)) {
                        foreach ($otherIncomes as $item) {
                            if ($item['amount'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700"><?php echo htmlspecialchars($item['label']); ?></span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($item['amount'], 2); ?></span>
                </div>
                            <?php endif;
                        }
                    }
                }
                ?>
                <div class="flex justify-between pt-3 border-t border-white/10 print:border-gray-200">
                    <span class="text-white print:text-black font-semibold">รวมรายได้</span>
                    <span class="text-green-400 print:text-green-600 font-bold text-lg"><?php echo number_format($slip['total_income'], 2); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Deduction -->
        <div>
            <h3 class="text-lg font-semibold text-red-400 print:text-red-600 mb-4">
                <i class="fas fa-minus-circle mr-2"></i>รายการหัก
            </h3>
            <div class="space-y-3">
                <?php if ($slip['tax_withheld'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">ภาษีหัก ณ ที่จ่าย</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['tax_withheld'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($slip['social_security'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">ประกันสังคม</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['social_security'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($slip['provident_fund'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">กองทุนสำรองเลี้ยงชีพ</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['provident_fund'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($slip['group_insurance']) && (float)$slip['group_insurance'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700">ประกันกลุ่ม (ส่วนพนักงาน)</span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($slip['group_insurance'], 2); ?></span>
                </div>
                <?php endif; ?>
                <?php 
                // Other deduction items
                if (!empty($slip['deduction_other_json'])) {
                    $otherDeds = json_decode($slip['deduction_other_json'], true);
                    if (is_array($otherDeds)) {
                        foreach ($otherDeds as $item) {
                            if ($item['amount'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-white/70 print:text-gray-700"><?php echo htmlspecialchars($item['label']); ?></span>
                    <span class="text-white print:text-black font-medium"><?php echo number_format($item['amount'], 2); ?></span>
                </div>
                            <?php endif;
                        }
                    }
                }
                ?>
                <div class="flex justify-between pt-3 border-t border-white/10 print:border-gray-200">
                    <span class="text-white print:text-black font-semibold">รวมรายการหัก</span>
                    <span class="text-red-400 print:text-red-600 font-bold text-lg"><?php echo number_format($slip['total_deductions'], 2); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Net Salary -->
    <div class="p-6 rounded-xl bg-gradient-to-r from-violet-600/20 to-purple-600/20 border border-violet-500/30 print:bg-gray-100 print:border-gray-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-white/70 print:text-gray-600">เงินได้สุทธิ</p>
                <p class="text-sm text-white/50 print:text-gray-500">Net Salary</p>
            </div>
            <div class="text-right">
                <p class="text-3xl font-bold text-white print:text-black"><?php echo number_format($slip['net_salary'], 2); ?></p>
                <p class="text-white/60 print:text-gray-600 text-sm">บาท</p>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="mt-6 pt-6 border-t border-white/10 print:border-gray-200 text-center">
        <p class="text-white/50 print:text-gray-500 text-sm">
            เอกสารนี้ออกโดยระบบอัตโนมัติ ไม่ต้องลงลายมือชื่อ
        </p>
        <?php if ($slip['approver_first']): ?>
        <p class="text-white/40 print:text-gray-400 text-xs mt-1">
            อนุมัติโดย: <?php echo htmlspecialchars($slip['approver_first'] . ' ' . $slip['approver_last']); ?>
        </p>
        <?php endif; ?>
    </div>
</div>

<div class="mt-6">
    <a href="payslip.php?year=<?php echo $year; ?>" class="inline-flex items-center text-white/60 hover:text-white">
        <i class="fas fa-arrow-left mr-2"></i>กลับไปรายการสลิป
    </a>
</div>

<?php else: ?>
<!-- Slip List View -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-white">สลิปเงินเดือน</h1>
    <p class="text-white/60">ดูและดาวน์โหลดสลิปเงินเดือนของคุณ</p>
</div>

<!-- YTD Summary -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">รายได้สะสม <?php echo $year + 543; ?></p>
        <p class="text-xl font-bold text-green-400"><?php echo number_format($ytd['ytd_income'] ?? 0, 2); ?></p>
    </div>
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">ภาษีสะสม</p>
        <p class="text-xl font-bold text-red-400"><?php echo number_format($ytd['ytd_tax'] ?? 0, 2); ?></p>
    </div>
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">ประกันสังคมสะสม</p>
        <p class="text-xl font-bold text-yellow-400"><?php echo number_format($ytd['ytd_ss'] ?? 0, 2); ?></p>
    </div>
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">กองทุนสำรองฯ สะสม</p>
        <p class="text-xl font-bold text-blue-400"><?php echo number_format($ytd['ytd_pf'] ?? 0, 2); ?></p>
    </div>
    <div class="glass-card rounded-xl p-4">
        <p class="text-white/50 text-sm">รายได้สุทธิสะสม</p>
        <p class="text-xl font-bold text-violet-400"><?php echo number_format($ytd['ytd_net'] ?? 0, 2); ?></p>
    </div>
</div>

<!-- Year Filter -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <label class="text-white/60">ปี:</label>
        <select name="year" class="input-field w-32" onchange="this.form.submit()">
            <?php foreach ($availableYears as $y): ?>
            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- Slip List -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($slips)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-file-invoice-dollar text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบสลิปเงินเดือนในปี <?php echo $year + 543; ?></p>
    </div>
    <?php else: ?>
    <div class="divide-y divide-white/10">
        <?php foreach ($slips as $s): ?>
        <?php
        $monthName = thaiMonth(date('n', strtotime($s['payroll_month'])));
        $yearBE = date('Y', strtotime($s['payroll_month'])) + 543;
        $statusColors = [
            'draft' => 'bg-gray-500/20 text-gray-400',
            'calculated' => 'bg-yellow-500/20 text-yellow-400',
            'approved' => 'bg-blue-500/20 text-blue-400',
            'paid' => 'bg-green-500/20 text-green-400'
        ];
        $statusText = [
            'draft' => 'ฉบับร่าง',
            'calculated' => 'คำนวณแล้ว',
            'approved' => 'อนุมัติแล้ว',
            'paid' => 'จ่ายแล้ว'
        ];
        ?>
        <div class="p-4 md:p-6 hover:bg-white/5 transition-colors">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-violet-600/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-invoice-dollar text-violet-400 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-white font-medium"><?php echo $monthName; ?> <?php echo $yearBE; ?></h3>
                        <p class="text-white/60 text-sm">
                            รายได้ <?php echo number_format($s['total_income'], 2); ?> | 
                            หัก <?php echo number_format($s['total_deductions'], 2); ?>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <p class="text-white/50 text-sm">เงินได้สุทธิ</p>
                        <p class="text-xl font-bold text-green-400"><?php echo number_format($s['net_salary'], 2); ?></p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs <?php echo $statusColors[$s['run_status']] ?? 'bg-gray-500/20 text-gray-400'; ?>">
                        <?php echo $statusText[$s['run_status']] ?? $s['run_status']; ?>
                    </span>
                    <div class="flex gap-2">
                        <a href="payslip.php?slip_id=<?php echo $s['id']; ?>" 
                           class="min-w-[44px] min-h-[44px] p-2 bg-white/10 hover:bg-white/20 text-white rounded-xl transition-colors flex items-center justify-center" 
                           title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="payslip.php?action=download&slip_id=<?php echo $s['id']; ?>" 
                           class="min-w-[44px] min-h-[44px] p-2 bg-violet-600 hover:bg-violet-700 text-white rounded-xl transition-colors flex items-center justify-center"
                           title="ดาวน์โหลด PDF">
                            <i class="fas fa-download"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
@media print {
    body { background: white !important; }
    .glass-card { background: white !important; border: 1px solid #e5e7eb !important; box-shadow: none !important; }
    nav, .no-print { display: none !important; }
    main { padding: 0 !important; }
}
</style>

<?php include 'templates/footer.php'; ?>
