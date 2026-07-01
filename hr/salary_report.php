<?php
/**
 * HR Salary Report - Monthly payroll report matching the manual Excel template
 * CEO level only. Live-calculated via PayrollService; ค่าบริหาร/ชดเชยวันหยุด/กยศ.
 * are entered manually per employee per month (no formula exists for them).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/Services/PayrollService.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

Auth::requireLogin();
Auth::requireHR();

if (!isCEOOrAbove()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้ารายงานเงินเดือน');
    redirect('/hr/', 302);
}

$pdo = getDB();
$user = Auth::user();
$service = new PayrollService($pdo);

$page_title = 'รายงานเงินเดือน';
$current_page = 'hr-salary-report';

const HR_SALARY_ALLOWANCE_LABELS = ['ค่าตำแหน่ง', 'ค่าครองชีพ', 'ค่าเดินทาง'];

/** Safe float cast — rejects arrays/objects that could slip in via malformed POST field names. */
function hr_salary_scalar_float($value): float
{
    return is_scalar($value) ? (float)$value : 0.0;
}

/** @return array<string,float> label => amount */
function hr_salary_extract_allowances(?array $setup): array
{
    $out = array_fill_keys(HR_SALARY_ALLOWANCE_LABELS, 0.0);
    if ($setup && !empty($setup['allowance_json'])) {
        $items = json_decode((string)$setup['allowance_json'], true);
        if (is_array($items)) {
            foreach ($items as $item) {
                $label = trim((string)($item['label'] ?? ''));
                if (isset($out[$label])) {
                    $out[$label] += (float)($item['amount'] ?? 0);
                }
            }
        }
    }
    return $out;
}

/**
 * Merge the manually-entered ค่าบริหาร/ชดเชยวันหยุด/กยศ. on top of whatever
 * income_other_json/deduction_other_json the employee already has configured,
 * as a PayrollService::calculateSlip() $setupOverride.
 */
/** Remove any existing items whose label matches one we're about to set, so re-saving never double-counts. */
function hr_salary_strip_labels(array $items, array $labels): array
{
    return array_values(array_filter($items, static function ($item) use ($labels) {
        return !is_array($item) || !in_array(trim((string)($item['label'] ?? '')), $labels, true);
    }));
}

function hr_salary_build_override(?array $setup, float $adminFee, float $holidayComp, float $studentLoan): array
{
    $override = [];
    if ($adminFee != 0.0 || $holidayComp != 0.0) {
        $items = [];
        if ($setup && !empty($setup['income_other_json'])) {
            $decoded = json_decode((string)$setup['income_other_json'], true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        $items = hr_salary_strip_labels($items, ['ค่าบริหาร', 'เงินชดเชยวันทำงาน']);
        if ($adminFee != 0.0) {
            $items[] = ['label' => 'ค่าบริหาร', 'amount' => round($adminFee, 2), 'ss_exclude' => 1];
        }
        if ($holidayComp != 0.0) {
            $items[] = ['label' => 'เงินชดเชยวันทำงาน', 'amount' => round($holidayComp, 2), 'ss_exclude' => 1];
        }
        $override['income_other_json'] = json_encode($items, JSON_UNESCAPED_UNICODE);
    }
    if ($studentLoan != 0.0) {
        $items = [];
        if ($setup && !empty($setup['deduction_other_json'])) {
            $decoded = json_decode((string)$setup['deduction_other_json'], true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        $items = hr_salary_strip_labels($items, ['กยศ.']);
        $items[] = ['label' => 'กยศ.', 'amount' => round($studentLoan, 2)];
        $override['deduction_other_json'] = json_encode($items, JSON_UNESCAPED_UNICODE);
    }
    return $override;
}

/** Build one report row: live PayrollService calc + manual entries. */
function hr_salary_build_row(PayrollService $service, array $employee, string $monthFirst, int $payDay, array $manual): array
{
    $userId = (int)$employee['id'];
    $setup = $service->getSalarySetup($userId, $monthFirst);
    $allowances = hr_salary_extract_allowances($setup);
    $adminFee = (float)($manual['admin_fee'] ?? 0);
    $holidayComp = (float)($manual['holiday_compensation'] ?? 0);
    $studentLoan = (float)($manual['student_loan_deduction'] ?? 0);
    $override = hr_salary_build_override($setup, $adminFee, $holidayComp, $studentLoan);
    $slip = $service->calculateSlip($userId, $monthFirst, $payDay, $override ?: null);

    return [
        'user_id' => $userId,
        'employee_code' => $employee['employee_code'] ?? '',
        'full_name' => $employee['full_name'],
        'position' => $employee['position'] ?? '',
        'bank_name' => $employee['bank_name'] ?? '',
        'base_salary' => (float)$slip['gross_salary'],
        'allowance_position' => $allowances['ค่าตำแหน่ง'],
        'allowance_col' => $allowances['ค่าครองชีพ'],
        'allowance_transport' => $allowances['ค่าเดินทาง'],
        'bonus' => (float)$slip['bonus'],
        'admin_fee' => $adminFee,
        'holiday_compensation' => $holidayComp,
        'total_income' => (float)$slip['total_income'],
        'social_security' => (float)$slip['social_security'],
        'leave_deduction' => (float)$slip['absence_deduction'],
        'tax' => (float)$slip['tax_withheld'],
        'student_loan' => $studentLoan,
        'total_deductions' => (float)$slip['total_deductions'],
        'net_salary' => (float)$slip['net_salary'],
    ];
}

// --- Resolve selected period ---
$periodMonthInput = (string)($_REQUEST['period_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $periodMonthInput)) {
    $periodMonthInput = date('Y-m');
}
$monthFirst = $periodMonthInput . '-01';
$monthDt = DateTimeImmutable::createFromFormat('Y-m-d', $monthFirst);
if (!$monthDt) {
    $periodMonthInput = date('Y-m');
    $monthFirst = date('Y-m-01');
    $monthDt = new DateTimeImmutable($monthFirst);
}

$payDay = (int)($_REQUEST['pay_day'] ?? $service->getDefaultPayDay());
if ($payDay < 1 || $payDay > 31) {
    $payDay = $service->getDefaultPayDay();
}

$payDateInput = trim((string)($_REQUEST['pay_date'] ?? ''));
$payDateDt = DateTimeImmutable::createFromFormat('Y-m-d', $payDateInput);
if (!$payDateDt || $payDateDt->format('Y-m-d') !== $payDateInput) {
    $defaultDay = min((int)$monthDt->format('t'), max(1, $payDay));
    $payDateDt = $monthDt->modify('+' . ($defaultDay - 1) . ' days');
}
$payDateStr = $payDateDt->format('Y-m-d');

// --- Employees eligible for payroll (same scope PayrollService::createRun() uses) ---
$employeesStmt = $pdo->prepare("
    SELECT u.id, u.employee_code, CONCAT(u.first_name_th, ' ', u.last_name_th) AS full_name,
           u.position, u.bank_name, u.bank_account
    FROM users u
    WHERE u.is_active = 1 AND " . tp_hr_payroll_employee_filter_sql('u') . "
    ORDER BY u.id
");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
$employeeIds = array_map(static fn($e) => (int)$e['id'], $employees);

$redirectQuery = static function () use ($periodMonthInput, $payDay, $payDateStr): string {
    return '/hr/salary_report.php?' . http_build_query([
        'period_month' => $periodMonthInput,
        'pay_day' => $payDay,
        'pay_date' => $payDateStr,
    ]);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        flash('error', 'โทเค็นความปลอดภัยไม่ถูกต้อง');
        redirect($redirectQuery(), 302);
    }
    $action = (string)($_POST['action'] ?? 'save');
    $postAdminFee = $_POST['admin_fee'] ?? [];
    $postHolidayComp = $_POST['holiday_compensation'] ?? [];
    $postStudentLoan = $_POST['student_loan'] ?? [];

    $upsert = $pdo->prepare("
        INSERT INTO hr_payroll_manual_items (user_id, period_month, admin_fee, holiday_compensation, student_loan_deduction, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE admin_fee = VALUES(admin_fee), holiday_compensation = VALUES(holiday_compensation),
            student_loan_deduction = VALUES(student_loan_deduction), updated_by = VALUES(updated_by)
    ");
    $pdo->beginTransaction();
    try {
        foreach ($employeeIds as $empId) {
            $adminFee = round(hr_salary_scalar_float($postAdminFee[$empId] ?? 0), 2);
            $holidayComp = round(hr_salary_scalar_float($postHolidayComp[$empId] ?? 0), 2);
            $studentLoan = round(hr_salary_scalar_float($postStudentLoan[$empId] ?? 0), 2);
            $upsert->execute([$empId, $monthFirst, $adminFee, $holidayComp, $studentLoan, (int)$user['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', 'บันทึกข้อมูลไม่สำเร็จ');
        redirect($redirectQuery(), 302);
    }

    if ($action === 'export') {
        $manualByUser = [];
        foreach ($employeeIds as $empId) {
            $manualByUser[$empId] = [
                'admin_fee' => round(hr_salary_scalar_float($postAdminFee[$empId] ?? 0), 2),
                'holiday_compensation' => round(hr_salary_scalar_float($postHolidayComp[$empId] ?? 0), 2),
                'student_loan_deduction' => round(hr_salary_scalar_float($postStudentLoan[$empId] ?? 0), 2),
            ];
        }
        $rows = [];
        foreach ($employees as $employee) {
            $rows[] = hr_salary_build_row($service, $employee, $monthFirst, $payDay, $manualByUser[(int)$employee['id']] ?? []);
        }
        $bankNote = trim((string)($_POST['bank_note'] ?? ''));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(thaiMonth((int)$monthDt->format('n')) . ' ' . ((int)$monthDt->format('Y') + 543), 0, 31));

        $lastCol = 'Q'; // 17 columns: A..Q
        $titleText = 'รายงานเงินเดือนพนักงาน ประจำเดือน' . thaiMonth((int)$monthDt->format('n')) . ' ' . ((int)$monthDt->format('Y') + 543)
            . ' (วันที่นำจ่าย ' . (int)$payDateDt->format('j') . '/' . (int)$payDateDt->format('n') . '/' . substr((string)((int)$payDateDt->format('Y') + 543), -2) . ')';
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', $titleText);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'ข้อมูลพนักงาน');
        $sheet->mergeCells('D2:J2');
        $sheet->setCellValue('D2', 'รายรับ');
        $sheet->mergeCells('K2:N2');
        $sheet->setCellValue('K2', 'รายจ่าย');
        $sheet->mergeCells('O2:Q2');
        $sheet->setCellValue('O2', 'จ่ายสุทธิ');
        $sheet->getStyle('A2:Q2')->getFont()->setBold(true);
        $sheet->getStyle('A2:Q2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headers = ['ลำดับ', 'ชื่อ - นามสกุล', 'ตำแหน่ง', 'เงินเดือน', 'ค่าตำแหน่ง', 'ค่าครองชีพ', 'ค่าเดินทาง',
            'โบนัสประจำเดือน', 'ค่าบริหาร', 'ชดเชยวันหยุด', 'รวมรายรับ', 'ประกันสังคม', 'ลางาน', 'ภาษี', 'กยศ.',
            'รวมรายจ่าย', 'จ่ายสุทธิ'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue("{$col}3", $h);
            $col++;
        }
        $sheet->getStyle('A3:Q3')->getFont()->setBold(true);
        $sheet->getStyle('A3:Q3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A3:Q3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFEFEF');

        $rowIdx = 4;
        $seq = 1;
        $bankCounts = [];
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$rowIdx}", $seq);
            $sheet->setCellValue("B{$rowIdx}", $row['full_name']);
            $sheet->setCellValue("C{$rowIdx}", $row['position']);
            $sheet->setCellValue("D{$rowIdx}", $row['base_salary']);
            $sheet->setCellValue("E{$rowIdx}", $row['allowance_position']);
            $sheet->setCellValue("F{$rowIdx}", $row['allowance_col']);
            $sheet->setCellValue("G{$rowIdx}", $row['allowance_transport']);
            $sheet->setCellValue("H{$rowIdx}", $row['bonus']);
            $sheet->setCellValue("I{$rowIdx}", $row['admin_fee']);
            $sheet->setCellValue("J{$rowIdx}", $row['holiday_compensation']);
            $sheet->setCellValue("K{$rowIdx}", $row['total_income']);
            $sheet->setCellValue("L{$rowIdx}", $row['social_security']);
            $sheet->setCellValue("M{$rowIdx}", $row['leave_deduction']);
            $sheet->setCellValue("N{$rowIdx}", $row['tax']);
            $sheet->setCellValue("O{$rowIdx}", $row['student_loan']);
            $sheet->setCellValue("P{$rowIdx}", $row['total_deductions']);
            $sheet->setCellValue("Q{$rowIdx}", $row['net_salary']);
            if ($row['net_salary'] > 0 && $row['bank_name'] !== '') {
                $bankCounts[$row['bank_name']] = ($bankCounts[$row['bank_name']] ?? 0) + 1;
            }
            $seq++;
            $rowIdx++;
        }
        $sheet->getStyle("D4:Q" . ($rowIdx - 1))->getNumberFormat()->setFormatCode('#,##0.00');

        $totalRow = $rowIdx + 1;
        $netTotal = array_sum(array_column($rows, 'net_salary'));
        $sheet->setCellValue("Q{$totalRow}", $netTotal);
        $sheet->getStyle("Q{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("Q{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        $noteLines = [];
        foreach ($bankCounts as $bankName => $count) {
            $noteLines[] = '#เข้าบัญชีธนาคาร' . $bankName . ' จำนวน ' . $count . ' คน';
        }
        if ($bankNote !== '') {
            $noteLines[] = $bankNote;
        }
        if ($noteLines) {
            $noteRow = $totalRow + 2;
            $sheet->mergeCells("A{$noteRow}:{$lastCol}{$noteRow}");
            $sheet->setCellValue("A{$noteRow}", implode('   ', $noteLines));
            $sheet->getStyle("A{$noteRow}")->getAlignment()->setWrapText(true);
        }

        foreach (range('A', 'Q') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(32);

        Auth::log('hr_salary_report_export_xlsx', null, null, null, [
            'period_month' => $periodMonthInput,
            'pay_day' => $payDay,
            'row_count' => count($rows),
        ]);

        $filename = hr_safe_content_disposition_filename('salary_report_' . $periodMonthInput . '.xlsx', 'salary_report.xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    flash('success', 'บันทึกข้อมูลเรียบร้อย');
    redirect($redirectQuery(), 302);
}

// --- GET: load manual entries + build rows for on-screen review ---
$manualStmt = $pdo->prepare("SELECT user_id, admin_fee, holiday_compensation, student_loan_deduction FROM hr_payroll_manual_items WHERE period_month = ?");
$manualStmt->execute([$monthFirst]);
$manualByUser = [];
foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $manualByUser[(int)$m['user_id']] = $m;
}

$rows = [];
foreach ($employees as $employee) {
    $rows[] = hr_salary_build_row($service, $employee, $monthFirst, $payDay, $manualByUser[(int)$employee['id']] ?? []);
}

$reportFlashOk = flash('success');
$reportFlashErr = flash('error');

require_once __DIR__ . '/../templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(1200px,100%)] mx-auto min-w-0">
<?php if ($reportFlashOk): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200 text-sm" role="status">
    <i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($reportFlashOk); ?>
</div>
<?php endif; ?>
<?php if ($reportFlashErr): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200 text-sm" role="alert">
    <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($reportFlashErr); ?>
</div>
<?php endif; ?>

<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/reports.php" class="hover:text-white touch-manipulation">รายงาน</a>
        <span class="mx-2">/</span>
        <span class="text-white">รายงานเงินเดือน</span>
    </nav>
    <div class="min-w-0">
        <h1 class="tp-ios-page-title flex flex-wrap items-center gap-2 mb-2">
            <i class="fas fa-money-check-dollar text-violet-400 shrink-0" aria-hidden="true"></i>
            <span>รายงานเงินเดือนพนักงาน</span>
        </h1>
        <p class="tp-ios-caption-muted max-w-[42rem]">คำนวณสดจากฐานเงินเดือน/ค่าเผื่อ/ประกันสังคม/ภาษี ปัจจุบัน — ค่าบริหาร ชดเชยวันหยุด และ กยศ. กรอกเองรายเดือน แล้วดาวน์โหลดเป็นไฟล์ Excel</p>
    </div>
</header>

<div class="native-card tp-native-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 mb-6 min-w-0 border border-white/10">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="tp-native-form-group mb-0 min-w-[10rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-month">เดือน</label>
            <input id="hr-sal-month" type="month" name="period_month" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo htmlspecialchars($periodMonthInput); ?>">
        </div>
        <div class="tp-native-form-group mb-0 min-w-[8rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-payday">วันตัดรอบ (payDay)</label>
            <input id="hr-sal-payday" type="number" min="1" max="31" name="pay_day" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo (int)$payDay; ?>">
        </div>
        <div class="tp-native-form-group mb-0 min-w-[10rem]">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-paydate">วันที่นำจ่าย</label>
            <input id="hr-sal-paydate" type="date" name="pay_date" class="input-field tp-native-input w-full min-h-[48px]" value="<?php echo htmlspecialchars($payDateStr); ?>">
        </div>
        <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2">
            <i class="fas fa-search" aria-hidden="true"></i>โหลดข้อมูล
        </button>
    </form>
</div>

<form method="POST" action="/hr/salary_report.php">
    <?php echo csrfField(); ?>
    <input type="hidden" name="period_month" value="<?php echo htmlspecialchars($periodMonthInput); ?>">
    <input type="hidden" name="pay_day" value="<?php echo (int)$payDay; ?>">
    <input type="hidden" name="pay_date" value="<?php echo htmlspecialchars($payDateStr); ?>">

    <div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
        <div class="mb-4 pb-4 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-white">
                ประจำเดือน<?php echo htmlspecialchars(thaiMonth((int)$monthDt->format('n')) . ' ' . ((int)$monthDt->format('Y') + 543)); ?>
            </h2>
            <p class="text-white/55 text-sm" role="status">พนักงาน <?php echo count($rows); ?> คน</p>
        </div>

        <?php if ($rows): ?>
        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
            <table class="w-full text-sm" style="min-width:1700px">
                <thead class="bg-white/5">
                    <tr>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-white/60 uppercase">ลำดับ</th>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อ-นามสกุล</th>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-medium text-white/60 uppercase">ตำแหน่ง</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">เงินเดือน</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ค่าตำแหน่ง</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ค่าครองชีพ</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ค่าเดินทาง</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">โบนัส</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-amber-300/90 uppercase">ค่าบริหาร<span class="block normal-case text-[10px] text-amber-300/70">กรอกเอง</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-amber-300/90 uppercase">ชดเชยวันหยุด<span class="block normal-case text-[10px] text-amber-300/70">กรอกเอง</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">รวมรายรับ</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ประกันสังคม</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ลางาน</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">ภาษี</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-amber-300/90 uppercase">กยศ.<span class="block normal-case text-[10px] text-amber-300/70">กรอกเอง</span></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-white/60 uppercase">รวมรายจ่าย</th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-medium text-emerald-400/90 uppercase">จ่ายสุทธิ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php foreach ($rows as $i => $row): ?>
                    <tr class="hover:bg-white/[0.04]">
                        <td class="px-3 py-2 text-white/60"><?php echo $i + 1; ?></td>
                        <td class="px-3 py-2 text-white"><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td class="px-3 py-2 text-white/60"><?php echo htmlspecialchars($row['position']); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['base_salary'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['allowance_position'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['allowance_col'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['allowance_transport'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['bonus'], 2); ?></td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" name="admin_fee[<?php echo (int)$row['user_id']; ?>]" value="<?php echo htmlspecialchars((string)$row['admin_fee']); ?>" class="input-field tp-native-input w-28 text-right min-h-[48px]">
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" name="holiday_compensation[<?php echo (int)$row['user_id']; ?>]" value="<?php echo htmlspecialchars((string)$row['holiday_compensation']); ?>" class="input-field tp-native-input w-28 text-right min-h-[48px]">
                        </td>
                        <td class="px-3 py-2 text-right text-white font-medium"><?php echo number_format($row['total_income'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['social_security'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['leave_deduction'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['tax'], 2); ?></td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" name="student_loan[<?php echo (int)$row['user_id']; ?>]" value="<?php echo htmlspecialchars((string)$row['student_loan']); ?>" class="input-field tp-native-input w-28 text-right min-h-[48px]">
                        </td>
                        <td class="px-3 py-2 text-right text-white/80"><?php echo number_format($row['total_deductions'], 2); ?></td>
                        <td class="px-3 py-2 text-right text-emerald-400 font-semibold"><?php echo number_format($row['net_salary'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            <label class="block text-white/70 text-sm mb-1" for="hr-sal-note">หมายเหตุเพิ่มเติม (ต่อท้ายบรรทัดจำนวนคนต่อธนาคาร ในไฟล์ Excel)</label>
            <textarea id="hr-sal-note" name="bank_note" rows="2" class="input-field tp-native-input w-full" placeholder="เช่น ค่าธรรมเนียมโอนธนาคารอื่น 20 บาท"></textarea>
        </div>

        <div class="flex flex-wrap gap-3 mt-5">
            <button type="submit" name="action" value="save" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-5 text-sm font-semibold text-white hover:bg-white/20 touch-manipulation gap-2">
                <i class="fas fa-floppy-disk" aria-hidden="true"></i>บันทึกข้อมูล
            </button>
            <button type="submit" name="action" value="export" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-5 text-sm font-semibold text-white touch-manipulation gap-2">
                <i class="fas fa-file-excel" aria-hidden="true"></i>บันทึกและดาวน์โหลด Excel
            </button>
        </div>
        <?php else: ?>
        <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
            <i class="fas fa-users-slash text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
            <p class="text-slate-400 text-sm">ไม่พบพนักงานที่อยู่ในระบบเงินเดือน</p>
        </div>
        <?php endif; ?>
    </div>
</form>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
