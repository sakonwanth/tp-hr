<?php
/**
 * Payslip Print Template
 * Template สลิปเงินเดือนสำหรับพิมพ์ - ใช้ format เดียวกับ CRM payroll_print.php
 * 
 * Required variables:
 * - $slip: array from payroll_slips with user info
 * - $ytd: array YTD summary (optional)
 * - $pdo: database connection
 */

if (!isset($slip) || !$slip) {
    die('ไม่พบข้อมูลสลิปเงินเดือน');
}

// Get company info from CRM's system_settings table (same source as CRM payroll)
$company_name = 'บริษัท ทีพี-แอสเสท ดีเวลลอปเม้นท์ จำกัด';
$company_name_en = 'TP-ASSET DEVELOPMENT CO., LTD.';
$company_tax_id = '0135569010741';

try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name','company_name_en','company_tax_id')");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $v = trim((string)($row['setting_value'] ?? ''));
        if ($v === '') continue;
        switch ($row['setting_key']) {
            case 'company_name':    $company_name = $v; break;
            case 'company_name_en': $company_name_en = $v; break;
            case 'company_tax_id':  $company_tax_id = $v; break;
        }
    }
} catch (Exception $e) {
    // fall back to defaults
}

// Logo & watermark — use EXACT same files as CRM payroll_print.php
// Header: LOGO TP-ASSET - 6.png (horizontal brand)
// Watermark: LOGO TP-ASSET - 5.png (square brand mark)
$logo_brand_src = CRM_BASE_URL . '/asset/logo/LOGO%20TP-ASSET%20-%206.png';
$watermark_src  = CRM_BASE_URL . '/asset/logo/LOGO%20TP-ASSET%20-%205.png';

$pm = strtotime($slip['payroll_month']);
$month_label_th = thaiMonth((int)date('n', $pm)) . ' ' . ((int)date('Y', $pm) + 543);
$month_label_en = date('F Y', $pm);
$period_label = date('m/Y', $pm);
$ytd_year = (int)date('Y', $pm);

$full_name = trim(($slip['emp_title'] ?? '') . ($slip['first_name_th'] ?? '') . ' ' . ($slip['last_name_th'] ?? ''));
$emp_code = $slip['employee_code'] ?? '';

// Bilingual labels (same as CRM)
$bilingual_labels = [
    'ค่าตำแหน่ง' => 'ค่าตำแหน่ง / Position Allowance',
    'ค่าเดินทาง' => 'ค่าเดินทาง / Travel Allowance',
    'ค่าคอมมิชชั่น' => 'ค่าคอมมิชชั่น / Commission',
    'ค่าบริหาร' => 'ค่าบริหาร / Management Allowance',
    'ค่าอาหาร' => 'ค่าอาหาร / Meal Allowance',
    'ค่าที่พัก' => 'ค่าที่พัก / Housing Allowance',
    'ค่าโทรศัพท์' => 'ค่าโทรศัพท์ / Telephone Allowance',
    'ค่าน้ำมัน' => 'ค่าน้ำมัน / Fuel Allowance',
    'ค่าล่วงเวลา' => 'ค่าล่วงเวลา / Overtime',
    'ค่าครองชีพ' => 'ค่าครองชีพ / Cost of Living Allowance',
    'ค่ารถ' => 'ค่ารถ / Transportation Allowance',
    'ค่าเช่าบ้าน' => 'ค่าเช่าบ้าน / Housing Rental',
    'ค่าวิชาชีพ' => 'ค่าวิชาชีพ / Professional Fee',
    'ค่าภาษา' => 'ค่าภาษา / Language Allowance',
    'เบี้ยขยัน' => 'เบี้ยขยัน / Diligence Allowance',
    'คอมมิชชั่น' => 'คอมมิชชั่น / Commission',
    'รายได้อื่น' => 'รายได้อื่น / Other Income',
    'หักเงินกู้' => 'หักเงินกู้ / Loan Deduction',
    'เงินกู้' => 'เงินกู้ / Loan Deduction',
    'ค่าขาดงาน' => 'ค่าขาดงาน / Absence Deduction',
    'ค่าสาย' => 'ค่าสาย / Late Deduction',
    'เงินยืม' => 'เงินยืม / Loan Advance',
    'หักอื่น' => 'หักอื่น / Other Deduction',
    'ค่าประกัน' => 'ค่าประกัน / Insurance',
    'ค่าสหกรณ์' => 'ค่าสหกรณ์ / Cooperative Fee',
    'ค่าหอพัก' => 'ค่าหอพัก / Dormitory Fee',
    'ค่าเครื่องแบบ' => 'ค่าเครื่องแบบ / Uniform Fee',
];

function bilingual_label($label, $map) {
    $label = trim($label);
    if (isset($map[$label])) return $map[$label];
    if (strpos($label, ' / ') !== false) return $label;
    return $label;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ใบรับเงินเดือน - <?php echo htmlspecialchars($emp_code); ?> <?php echo htmlspecialchars($full_name); ?> - <?php echo $month_label_th; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        html, body {
            overflow: visible;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background: #fff;
            color: #1a1a1a;
            padding: 0;
            max-width: 210mm;
            margin: 0 auto;
            min-height: 297mm;
            position: relative;
        }
        @media screen {
            body {
                padding-left: env(safe-area-inset-left, 0px);
                padding-right: env(safe-area-inset-right, 0px);
                padding-top: env(safe-area-inset-top, 0px);
            }
        }
        .page {
            position: relative;
            padding: 24px 28px 32px;
        }
        .slip-body {
            position: relative;
        }
        .watermark {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 10;
        }
        .watermark img {
            width: 50%;
            height: auto;
            object-fit: contain;
            opacity: 0.04;
            filter: grayscale(100%) brightness(1.2);
        }

        .doc-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid #1a365d;
            margin-bottom: 24px;
        }
        .doc-header-logo {
            flex-shrink: 0;
        }
        .doc-header-logo img {
            height: 72px;
            width: auto;
            display: block;
        }
        .doc-header-right {
            text-align: right;
        }
        .doc-header-right .company-name {
            font-size: 15px;
            font-weight: 700;
            color: #1a365d;
            letter-spacing: 0.02em;
            line-height: 1.35;
        }
        .doc-header-right .doc-type {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
            font-weight: 500;
        }
        .doc-header-right .doc-period {
            font-size: 12px;
            color: #475569;
            margin-top: 2px;
            font-weight: 600;
        }

        .doc-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a365d;
            text-align: center;
            margin-bottom: 24px;
            letter-spacing: 0.01em;
        }
        .doc-title span {
            font-size: 14px;
            font-weight: 600;
            color: #475569;
        }

        .section {
            margin-bottom: 20px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #e2e8f0;
        }
        .info-table th {
            width: 28%;
            background: #f8fafc;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }
        .info-table td {
            font-size: 13px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }

        .amount-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #e2e8f0;
        }
        .amount-table th {
            font-size: 12px;
            font-weight: 700;
            color: #1a365d;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }
        .amount-table th.amt { width: 32%; text-align: right; }
        .amount-table td {
            font-size: 13px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            color: #334155;
        }
        .amount-table td.amt { text-align: right; font-variant-numeric: tabular-nums; }
        .amount-table tr.total th,
        .amount-table tr.total td {
            background: #f1f5f9;
            font-weight: 700;
            font-size: 13px;
            color: #1e293b;
        }

        .net-box {
            margin-top: 24px;
            border: 2px solid #1a365d;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .net-box .label {
            font-size: 15px;
            font-weight: 700;
            color: #1a365d;
        }
        .net-box .value {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            font-variant-numeric: tabular-nums;
        }
        .net-box .value span { font-size: 14px; font-weight: 500; color: #64748b; }

        .footer {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            font-size: 11px;
            color: #64748b;
            text-align: center;
            line-height: 1.5;
        }

        .no-print { margin-bottom: 16px; }
        .btn-print {
            padding: 10px 20px;
            min-height: 44px;
            background: #1a365d;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-print:hover { background: #2d4a7c; }
        .link-back { margin-left: 12px; color: #1a365d; font-weight: 500; text-decoration: none; }
        .link-back:hover { text-decoration: underline; }

        @media print {
            body { padding: 0; min-height: auto; overflow: visible; }
            .page { padding: 18px 22px 24px; }
            .no-print { display: none !important; }
            .watermark img { opacity: 0.035; width: 48%; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="no-print">
            <button type="button" class="btn-print" onclick="window.print();">พิมพ์ / บันทึกเป็น PDF</button>
            <a href="payslip.php?slip_id=<?php echo (int)$slip['id']; ?>" class="link-back">← กลับรายการสลิป</a>
        </div>

        <header class="doc-header">
            <div class="doc-header-logo">
                <img src="<?php echo htmlspecialchars($logo_brand_src); ?>" alt="<?php echo htmlspecialchars($company_name); ?>" onerror="this.style.display='none'">
            </div>
            <div class="doc-header-right">
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="doc-type">Payroll Statement / ใบสำคัญจ่ายเงินเดือน</div>
                <?php if (!empty($company_tax_id)): ?>
                <div class="tax-id" style="font-size:11px;color:#475569;margin-top:3px;font-weight:500;">เลขทะเบียนนิติบุคคล / Tax ID: <span style="font-weight:600;color:#1a365d;font-variant-numeric:tabular-nums;"><?php echo htmlspecialchars($company_tax_id); ?></span></div>
                <?php endif; ?>
                <div class="doc-period" style="font-size:12px;color:#475569;margin-top:3px;font-weight:600;">Period <?php echo $period_label; ?></div>
            </div>
        </header>

        <h1 class="doc-title">
            สลิปเงินเดือน ประจำเดือน <?php echo $month_label_th; ?><br>
            <span>Payroll Slip — <?php echo $month_label_en; ?></span>
        </h1>

        <div class="slip-body">
            <div class="watermark" aria-hidden="true">
                <img src="<?php echo htmlspecialchars($watermark_src); ?>" alt="">
            </div>

            <section class="section">
                <table class="info-table">
                    <tr>
                        <th>รหัสพนักงาน / Emp.ID</th>
                        <td><?php echo htmlspecialchars($emp_code ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>ชื่อ-นามสกุล / Name</th>
                        <td><?php echo htmlspecialchars($full_name); ?></td>
                    </tr>
                    <tr>
                        <th>ตำแหน่ง / Position</th>
                        <td><?php echo htmlspecialchars(trim($slip['position'] ?? '') !== '' ? $slip['position'] : '-'); ?></td>
                    </tr>
                </table>
            </section>

            <section class="section">
                <table class="amount-table">
                    <thead>
                        <tr>
                            <th>รายการรับ / Income</th>
                            <th class="amt">จำนวน (บาท) / Amount (THB)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>เงินเดือน / SALARY</td>
                            <td class="amt"><?php echo number_format($slip['gross_salary'], 2); ?></td>
                        </tr>
                        <?php if ((float)($slip['bonus'] ?? 0) != 0): ?>
                        <tr>
                            <td>โบนัส / BONUS</td>
                            <td class="amt"><?php echo number_format($slip['bonus'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ((float)($slip['allowances'] ?? 0) != 0): ?>
                        <tr>
                            <td>เบี้ยเลี้ยง / ALLOWANCE</td>
                            <td class="amt"><?php echo number_format($slip['allowances'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php
                        $income_other = !empty($slip['income_other_json']) ? json_decode($slip['income_other_json'], true) : [];
                        if (is_array($income_other)) foreach ($income_other as $io):
                            $amt = (float)($io['amount'] ?? 0);
                            if ($amt == 0) continue;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(bilingual_label($io['label'] ?? 'รายได้อื่น', $bilingual_labels)); ?></td>
                            <td class="amt"><?php echo number_format($amt, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total">
                            <th>รายการรับทั้งสิ้น / Total Income</th>
                            <td class="amt"><?php echo number_format($slip['total_income'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="section">
                <table class="amount-table">
                    <thead>
                        <tr>
                            <th>รายการหัก / Deductions</th>
                            <th class="amt">จำนวน (บาท) / Amount (THB)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>ภาษีหัก ณ ที่จ่าย / Withholding Tax</td>
                            <td class="amt"><?php echo number_format($slip['tax_withheld'] ?? 0, 2); ?></td>
                        </tr>
                        <?php if ((float)($slip['provident_fund'] ?? 0) != 0): ?>
                        <tr>
                            <td>กองทุนสำรองเลี้ยงชีพ / Provident Fund</td>
                            <td class="amt"><?php echo number_format($slip['provident_fund'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ((float)($slip['social_security'] ?? 0) != 0): ?>
                        <tr>
                            <td>ประกันสังคม / Social Security</td>
                            <td class="amt"><?php echo number_format($slip['social_security'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ((float)($slip['group_insurance'] ?? 0) != 0): ?>
                        <tr>
                            <td>ประกันกลุ่ม / Group Insurance</td>
                            <td class="amt"><?php echo number_format($slip['group_insurance'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php
                        $deduction_other = !empty($slip['deduction_other_json']) ? json_decode($slip['deduction_other_json'], true) : [];
                        if (is_array($deduction_other)) foreach ($deduction_other as $do):
                            $amt = (float)($do['amount'] ?? 0);
                            if ($amt == 0) continue;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(bilingual_label($do['label'] ?? 'หักอื่น', $bilingual_labels)); ?></td>
                            <td class="amt"><?php echo number_format($amt, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total">
                            <th>รายการหักทั้งสิ้น / Total Deduction</th>
                            <td class="amt"><?php echo number_format($slip['total_deductions'] ?? 0, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="net-box">
                <span class="label">เงินได้สุทธิ / Net Total Income</span>
                <span class="value"><?php echo number_format($slip['net_salary'], 2); ?> <span>THB</span></span>
            </div>

            <?php if (isset($ytd) && $ytd): ?>
            <section class="section" style="margin-top:20px;">
                <table class="amount-table">
                    <thead>
                        <tr>
                            <th colspan="2" style="background:#1a365d;color:#fff;font-size:12px;letter-spacing:0.03em;">
                                รายได้สะสมตั้งแต่ต้นปี / Year-to-Date (<?php echo $ytd_year; ?>)
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>รายได้รวมสะสม / Cumulative Income</td>
                            <td class="amt"><?php echo number_format($ytd['ytd_income'] ?? 0, 2); ?></td>
                        </tr>
                        <tr>
                            <td>ภาษีหัก ณ ที่จ่ายสะสม / Cumulative Withholding Tax</td>
                            <td class="amt"><?php echo number_format($ytd['ytd_tax'] ?? 0, 2); ?></td>
                        </tr>
                        <tr>
                            <td>ประกันสังคมสะสม / Cumulative Social Security</td>
                            <td class="amt"><?php echo number_format($ytd['ytd_ss'] ?? 0, 2); ?></td>
                        </tr>
                        <?php if ((float)($ytd['ytd_pf'] ?? 0) > 0): ?>
                        <tr>
                            <td>กองทุนสำรองเลี้ยงชีพสะสม / Cumulative Provident Fund</td>
                            <td class="amt"><?php echo number_format($ytd['ytd_pf'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="total">
                            <th>เงินได้สุทธิสะสม / Cumulative Net Income</th>
                            <td class="amt"><?php echo number_format($ytd['ytd_net'] ?? 0, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
        </div>

        <footer class="footer">
            เอกสารนี้จัดทำและออกโดยบริษัท ซึ่งบุคคลผู้มีรายชื่อปรากฏอยู่ในเอกสารนี้สามารถใช้ยืนยันข้อมูลต่าง ๆ ตามที่ปรากฏในเอกสารนี้ได้อย่างสมบูรณ์
        </footer>
    </div>
</body>
</html>
<?php exit; ?>
