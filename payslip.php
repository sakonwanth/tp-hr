<?php
/**
 * Payslip Page
 * สลิปเงินเดือน - ดูและดาวน์โหลดสลิปเงินเดือน
 */

$page_title = 'สลิปเงินเดือน';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();
$current_page = 'payslip';

$pdo = Database::getInstance()->getConnection();

if (($_GET['action'] ?? '') === 'download' && (int)($_GET['slip_id'] ?? 0) > 0) {
    $q = $_GET;
    unset($q['action']);
    flash('error', 'การดาวน์โหลดหรือพิมพ์สลิป กรุณาใช้ปุ่มบนหน้านี้');
    redirect('/payslip.php' . (count($q) ? '?' . http_build_query($q) : ''), 302);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['download_payslip'] ?? '') === '1') {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        flash('error', 'โทเค็นความปลอดภัยไม่ถูกต้อง');
        redirect('/payslip.php', 302);
    }
    $downloadSlipId = (int)($_POST['slip_id'] ?? 0);
    if ($downloadSlipId <= 0) {
        flash('error', 'ไม่พบสลิป');
        redirect('/payslip.php', 302);
    }

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
        $ytd_year = (int)date('Y', strtotime($slip['payroll_month']));
        $stmt_ytd = $pdo->prepare("
            SELECT 
                COALESCE(SUM(s.total_income), 0) as ytd_income,
                COALESCE(SUM(s.tax_withheld), 0) as ytd_tax,
                COALESCE(SUM(s.social_security), 0) as ytd_ss,
                COALESCE(SUM(s.provident_fund), 0) as ytd_pf,
                COALESCE(SUM(s.group_insurance), 0) as ytd_gi,
                COALESCE(SUM(s.health_insurance), 0) as ytd_hi,
                COALESCE(SUM(s.total_deductions), 0) as ytd_deductions,
                COALESCE(SUM(s.net_salary), 0) as ytd_net
            FROM payroll_slips s
            JOIN payroll_runs r ON s.payroll_run_id = r.id
            WHERE s.user_id = ? AND YEAR(r.payroll_month) = ? AND r.payroll_month <= ?
        ");
        $stmt_ytd->execute([$slip['user_id'], $ytd_year, $slip['payroll_month']]);
        $ytd = $stmt_ytd->fetch(PDO::FETCH_ASSOC);

        $payslip_back_url = 'payslip.php?year=' . $ytd_year;
        include __DIR__ . '/modules/employee/payslip/print_template.php';
        exit;
    }

    flash('error', 'ไม่พบสลิปหรือยังไม่พร้อม');
    redirect('/payslip.php', 302);
}

if (!function_exists('tp_hr_payslip_download_form')) {
    /**
     * POST + CSRF — opens print template (same tab or target=_blank).
     */
    function tp_hr_payslip_download_form(int $slipId, string $buttonClass, string $innerHtml, bool $newTab, ?string $buttonTitle = null): void {
        $target = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
        $titleAttr = ($buttonTitle !== null && $buttonTitle !== '')
            ? (' title="' . htmlspecialchars($buttonTitle, ENT_QUOTES, 'UTF-8') . '"')
            : '';
        echo '<form method="post" action="payslip.php" class="payslip-download-form inline-flex w-full sm:w-auto min-w-0"' . $target . '>';
        echo csrfField();
        echo '<input type="hidden" name="download_payslip" value="1">';
        echo '<input type="hidden" name="slip_id" value="' . $slipId . '">';
        echo '<button type="submit" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '"' . $titleAttr . '>' . $innerHtml . '</button></form>';
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

// Get single slip if requested — แสดงด้วย print template เดียวกับ CRM payroll_print.php
$slip = null;
if ($viewSlipId > 0) {
    $stmt = $pdo->prepare("
        SELECT ps.*, pr.payroll_month, pr.pay_day, pr.status as run_status,
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

    if ($slip) {
        $ytd_year = (int)date('Y', strtotime($slip['payroll_month']));
        $stmt_ytd = $pdo->prepare("
            SELECT
                COALESCE(SUM(s.total_income), 0) as ytd_income,
                COALESCE(SUM(s.tax_withheld), 0) as ytd_tax,
                COALESCE(SUM(s.social_security), 0) as ytd_ss,
                COALESCE(SUM(s.provident_fund), 0) as ytd_pf,
                COALESCE(SUM(s.group_insurance), 0) as ytd_gi,
                COALESCE(SUM(s.health_insurance), 0) as ytd_hi,
                COALESCE(SUM(s.total_deductions), 0) as ytd_deductions,
                COALESCE(SUM(s.net_salary), 0) as ytd_net
            FROM payroll_slips s
            JOIN payroll_runs r ON s.payroll_run_id = r.id
            WHERE s.user_id = ? AND YEAR(r.payroll_month) = ? AND r.payroll_month <= ?
              AND r.status IN ('approved', 'paid')
        ");
        $stmt_ytd->execute([$slip['user_id'], $ytd_year, $slip['payroll_month']]);
        $ytd = $stmt_ytd->fetch(PDO::FETCH_ASSOC);
        $payslip_back_url = 'payslip.php?year=' . $ytd_year;
        include __DIR__ . '/modules/employee/payslip/print_template.php';
        exit;
    }
}

// Get YTD summary (year-to-date)
$stmtYTD = $pdo->prepare("
    SELECT 
        SUM(ps.total_income) as ytd_income,
        SUM(ps.tax_withheld) as ytd_tax,
        SUM(ps.social_security) as ytd_ss,
        SUM(ps.provident_fund) as ytd_pf,
        SUM(ps.group_insurance) as ytd_gi,
        SUM(ps.health_insurance) as ytd_hi,
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

<div class="tp-payslip-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<!-- Slip List View -->
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="index.php" class="tp-tap-48 hover:text-white touch-manipulation">หน้าแรก</a>
        <span class="mx-2">/</span>
        <span class="text-white">สลิปเงินเดือน</span>
    </nav>
    <h1 class="tp-ios-page-title">สลิปเงินเดือน</h1>
    <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ดูสรุปสะสมตามปี กรองปี พร้อมดูรายละเอียดและดาวน์โหลดสลิปที่อนุมัติแล้ว</p>
</header>

<!-- YTD Summary -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 sm:gap-6 mb-6 min-w-0 max-w-full">
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <p class="text-white/50 text-sm truncate">รายได้สะสม <?php echo $year + 543; ?></p>
        <p class="text-lg sm:text-xl font-bold text-green-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_income'] ?? 0, 2); ?></p>
    </div>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <p class="text-white/50 text-sm truncate">ภาษีสะสม</p>
        <p class="text-lg sm:text-xl font-bold text-red-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_tax'] ?? 0, 2); ?></p>
    </div>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <p class="text-white/50 text-sm truncate">ประกันสังคมสะสม</p>
        <p class="text-lg sm:text-xl font-bold text-yellow-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_ss'] ?? 0, 2); ?></p>
    </div>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <p class="text-white/50 text-sm truncate">กองทุนสำรองฯ สะสม</p>
        <p class="text-lg sm:text-xl font-bold text-blue-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_pf'] ?? 0, 2); ?></p>
    </div>
    <?php if ((float)($ytd['ytd_gi'] ?? 0) > 0): ?>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <p class="text-white/50 text-sm truncate">ประกันกลุ่มสะสม</p>
        <p class="text-lg sm:text-xl font-bold text-cyan-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_gi'], 2); ?></p>
    </div>
    <?php endif; ?>
    <?php if ((float)($ytd['ytd_hi'] ?? 0) > 0): ?>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <p class="text-white/50 text-sm truncate">ประกันสุขภาพสะสม</p>
        <p class="text-lg sm:text-xl font-bold text-rose-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_hi'], 2); ?></p>
    </div>
    <?php endif; ?>
    <div class="native-card tp-native-card tp-native-data-card min-w-0 sm:col-span-2 lg:col-span-1">
        <p class="text-white/50 text-sm truncate">รายได้สุทธิสะสม</p>
        <p class="text-lg sm:text-xl font-bold text-violet-400 mt-1 tabular-nums"><?php echo number_format($ytd['ytd_net'] ?? 0, 2); ?></p>
    </div>
</div>

<!-- Year Filter -->
<div class="native-card tp-native-card tp-native-data-card mb-6 min-w-0">
    <form method="GET" class="flex flex-wrap items-end gap-4 min-w-0">
        <div class="min-w-0">
            <label class="block text-white/60 text-xs mb-1" for="payslip-year">ปี (พ.ศ.)</label>
            <select id="payslip-year" name="year" class="input-field tp-native-select w-full sm:w-40 min-w-0" onchange="this.form.submit()">
                <?php foreach ($availableYears as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y + 543; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<!-- Slip List -->
<?php
// The list used to sit inside a card, which then held another padded div,
// which held each slip card, which held the net-pay box — four levels of
// padding stacking up. On a 375px screen that left the content about 190px
// wide, which is what made these cards feel so cramped. The slip cards are
// cards already; they stand on the page directly.
?>
    <?php if (empty($slips)): ?>
    <div class="native-card tp-native-card tp-native-data-card min-w-0">
        <div class="tp-native-empty-state text-center py-12 px-4">
            <i class="fas fa-file-invoice-dollar text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
            <p class="text-slate-400 text-sm">ไม่พบสลิปเงินเดือนในปี <?php echo $year + 543; ?></p>
        </div>
    </div>
    <?php else: ?>
    <div class="space-y-4 min-w-0">
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
        <div class="tp-ios-attendance-panel p-5 md:p-6 min-w-0 transition-colors hover:bg-white/[0.07]">
            <div class="flex flex-col gap-4 min-w-0">
                <div class="flex items-start gap-4 min-w-0">
                    <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-violet-600/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-invoice-dollar text-violet-400 text-xl" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <h3 class="text-white font-medium text-base leading-snug"><?php echo htmlspecialchars($monthName); ?> <?php echo (int)$yearBE; ?></h3>
                        <p class="text-white/60 text-sm mt-1.5 leading-relaxed">
                            รายได้ <?php echo number_format($s['total_income'], 2); ?> · หัก <?php echo number_format($s['total_deductions'], 2); ?>
                        </p>
                    </div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 px-4 py-4 min-w-0">
                    <p class="text-white/50 text-xs uppercase tracking-wide">เงินได้สุทธิ</p>
                    <p class="text-2xl font-bold text-green-400 tabular-nums mt-2 leading-tight"><?php echo number_format($s['net_salary'], 2); ?> <span class="text-sm font-normal text-white/50">บาท</span></p>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 min-w-0">
                    <span class="inline-flex self-start px-3 py-1.5 rounded-full text-xs font-medium <?php echo $statusColors[$s['run_status']] ?? 'bg-gray-500/20 text-gray-400'; ?>">
                        <?php echo $statusText[$s['run_status']] ?? htmlspecialchars($s['run_status']); ?>
                    </span>
                    <div class="flex items-center gap-3 sm:justify-end shrink-0">
                        <?php // Both actions share one height token — they sat at 48px and 56px, which read as a mistake next to each other. ?>
                        <a href="payslip.php?slip_id=<?php echo (int)$s['id']; ?>"
                           class="flex-1 sm:flex-none min-h-[56px] min-w-[56px] px-4 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] transition-colors inline-flex items-center justify-center gap-2 touch-manipulation font-medium text-sm whitespace-nowrap"
                           title="ดูรายละเอียด">
                            <i class="fas fa-eye" aria-hidden="true"></i><span class="sm:hidden">ดู</span>
                        </a>
                        <?php tp_hr_payslip_download_form((int)$s['id'], 'flex-1 sm:flex-none min-h-[56px] min-w-[56px] px-4 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors inline-flex items-center justify-center gap-2 touch-manipulation font-medium text-sm whitespace-nowrap border-0', '<i class="fas fa-download" aria-hidden="true"></i><span class="sm:hidden">ดาวน์โหลด</span><span class="hidden sm:inline">PDF</span>', true, 'ดาวน์โหลด PDF'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<style>
@media print {
    body { background: white !important; }
    .glass-card,
    .native-card { background: white !important; border: 1px solid #e5e7eb !important; box-shadow: none !important; }
    nav, .no-print { display: none !important; }
    main { padding: 0 !important; }
}
</style>

<?php include 'templates/footer.php'; ?>
<script>
(function () {
    document.querySelectorAll('form.payslip-download-form').forEach(function (el) {
        el.addEventListener('submit', function () {
            if (typeof showToast === 'function') {
                showToast('success', 'เริ่มดาวน์โหลดสลิป', 'หากไม่เห็นไฟล์ ให้ตรวจสอบแท็บใหม่หรือการบล็อกป๊อปอัปของเบราว์เซอร์');
            }
        });
    });
})();
</script>
