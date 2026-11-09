<?php
/**
 * ใบ PAYROLL (สลิปเงินเดือน) สำหรับพิมพ์
 *
 * Usage (Admin):      payroll_print.php?slip_id=123
 * Usage (Employee):   payroll_print.php?token=<64-hex>     (ส่งผ่าน LINE)
 *
 * สิทธิ์ทาง session: HR, Admin, Chairman, CEO
 * สิทธิ์ทาง token  : เจ้าของสลิปเท่านั้น (token verified → ผูกกับ slip_id+user_id)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/./core/LegacyHrUi.php';
require_once __DIR__ . '/modules/payroll/queries.php';

$pdo = getDB();
$slip_id = 0;
$token_row = null;
$is_token_view = false;
$token_q = trim((string)($_GET['token'] ?? ''));
$pdf_mode = (($_GET['format'] ?? '') === 'pdf');

if ($token_q !== '') {
    // ----- Token-based (no login required) -----
    require_once __DIR__ . '/core/Services/PayslipTokenService.php';
    $token_row = PayslipTokenService::verify($pdo, $token_q);
    if (!$token_row) {
        http_response_code(403);
        die('ลิงก์ดาวน์โหลดไม่ถูกต้องหรือหมดอายุแล้ว กรุณาติดต่อฝ่ายบุคคลเพื่อขอลิงก์ใหม่');
    }
    $slip_id = (int)$token_row['slip_id'];
    $is_token_view = true;
    // Security headers — personal payroll data must not be cached by proxies/CDNs or indexed
    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
} else {
    // ----- Session-based (admin) -----
    Auth::requireLogin();
    $session_user = Auth::user();
    $stmt = $pdo->prepare("SELECT u.id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$session_user['id']]);
    $current = $stmt->fetch();
    if (!$current) {
        header('Location: login.php');
        exit;
    }
    if (!function_exists('crm_user_can_access_payroll_web') || !crm_user_can_access_payroll_web()) {
        header('Location: index.php?error=' . urlencode('ไม่มีสิทธิ์'));
        exit;
    }
    $slip_id = (int)($_GET['slip_id'] ?? 0);
    if (!$slip_id) {
        die('ไม่ระบุ slip_id');
    }
}

$stmt = $pdo->prepare("
    SELECT s.*, u.employee_code, u.title as u_title, u.first_name_th, u.last_name_th, u.department, u.position,
           r.payroll_month, r.pay_day, r.status as run_status
    FROM payroll_slips s
    JOIN users u ON s.user_id = u.id
    JOIN payroll_runs r ON s.payroll_run_id = r.id
    WHERE s.id = ?
");
$stmt->execute([$slip_id]);
$slip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slip) {
    die('ไม่พบสลิปเงินเดือน');
}

// ดึงภาษีหักเพิ่มรายเดือนจาก salary_setup (ถ้ามี) เพื่อแยกแสดงในสลิป
$extra_tax_withheld = 0;
try {
    $m_first = date('Y-m-01', strtotime($slip['payroll_month']));
    $st2 = $pdo->prepare("SELECT additional_tax_withholding FROM employee_salary_setup WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC, id DESC LIMIT 1");
    $st2->execute([$slip['user_id'], $m_first, $m_first]);
    $r2 = $st2->fetch(PDO::FETCH_ASSOC);
    if ($r2 && isset($r2['additional_tax_withholding'])) {
        $extra_tax_withheld = min((float)$r2['additional_tax_withholding'], (float)$slip['tax_withheld']);
    }
} catch (PDOException $e) { /* column may not exist on legacy schema */ }
$base_tax_withheld = max(0, (float)$slip['tax_withheld'] - $extra_tax_withheld);

$company_name = function_exists('getSetting') ? getSetting('company_name', 'TP-ASSET DEVELOPMENT CO., LTD.') : 'TP-ASSET DEVELOPMENT CO., LTD.';
$base_path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if ($base_path === '/' || $base_path === '\\') $base_path = '';
// โลโก้หัวแบบแนวนอน (horizontal) สำหรับส่วนหัวของเอกสาร
$logo_brand_file = file_exists(__DIR__ . '/asset/logo/LOGO TP-ASSET - 6.png')
    ? '/asset/logo/LOGO TP-ASSET - 6.png'
    : '/asset/logo/tp-brand.png';
$logo_brand_src = $base_path . $logo_brand_file;
// โลโก้แบบกล่อง (square) สำหรับลายน้ำ
$watermark_file = file_exists(__DIR__ . '/asset/logo/LOGO TP-ASSET - 5.png')
    ? '/asset/logo/LOGO TP-ASSET - 5.png'
    : '/asset/logo/TP-ASSET DEVELOPMENT-logo-08.png';
$watermark_src = $base_path . $watermark_file;

$thai_months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$pm = strtotime($slip['payroll_month']);
$month_label_th = $thai_months[(int)date('n', $pm)] . ' ' . ((int)date('Y', $pm) + 543);
$month_label_en = date('F Y', $pm);
$pay_date = payroll_slip_payment_date($pdo, $slip);
$pay_date_th = (int)date('j', strtotime($pay_date)) . ' ' . $thai_months[(int)date('n', strtotime($pay_date))] . ' ' . ((int)date('Y', strtotime($pay_date)) + 543);
$pay_date_en = date('j F Y', strtotime($pay_date));
$full_name = trim(($slip['u_title'] ?? '') . ($slip['first_name_th'] ?? '') . ' ' . ($slip['last_name_th'] ?? ''));
$emp_code = $slip['employee_code'] ?? '';

// YTD (Year-to-Date) cumulative income for this employee
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

if ($pdf_mode) {
    // Capture the exact HTML document below and render it to PDF via headless
    // Chromium. This gives pixel-identical output to the browser view — same
    // engine, same CSS, same fonts (Sarabun via Google Fonts), same watermark.
    ob_start();
    // Cap server time: chromium spawn + render typically <5s. Abort if hung.
    @set_time_limit(30);
}

if (!$pdf_mode) {
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบรับเงินเดือน - <?php echo htmlspecialchars($emp_code); ?> <?php echo htmlspecialchars($full_name); ?> - <?php echo $month_label_th; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
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

        .doc-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a365d;
            text-align: center;
            margin-bottom: 24px;
            letter-spacing: 0.01em;
        }

        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            background: #1a365d;
            padding: 8px 14px;
            letter-spacing: 0.05em;
            margin-bottom: 0;
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
        .slip-crumb-bar {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 12px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 6px;
            line-height: 1.4;
        }
        .slip-crumb-link {
            color: #1a365d;
            text-decoration: none;
            font-weight: 500;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            padding: 0 6px;
            margin: 0 -4px;
            border-radius: 6px;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .slip-crumb-link:hover { text-decoration: underline; background: rgba(26, 54, 93, 0.06); }
        .slip-crumb-sep {
            color: #94a3b8;
            user-select: none;
            padding: 0 2px;
        }
        .slip-crumb-current {
            color: #0f172a;
            font-weight: 600;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            padding: 0 4px;
        }
        .slip-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
        .btn-print {
            padding: 12px 20px;
            min-height: 44px;
            background: #1a365d;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-print:hover { background: #2d4a7c; }
        .link-back {
            margin-left: 0;
            color: #1a365d;
            font-weight: 500;
            text-decoration: none;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .link-back:hover { text-decoration: underline; background: rgba(26, 54, 93, 0.06); }

        @media print {
            @page { size: A4 portrait; margin: 12mm 10mm; }
            html, body {
                width: 210mm;
                max-width: 210mm;
                min-height: 0;
                height: auto;
                margin: 0;
                padding: 0;
                overflow: visible;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .page {
                width: auto;
                padding: 0;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
            .no-print { display: none !important; }
            .watermark img { opacity: 0.035; width: 48%; }
            /* Tighten vertical rhythm to guarantee single-page A4 fit */
            .doc-header { padding-bottom: 12px; margin-bottom: 14px; }
            .doc-title { margin-bottom: 14px; font-size: 16px; }
            .section { margin-bottom: 10px; }
            .info-table th, .info-table td,
            .amount-table th, .amount-table td { padding: 6px 10px; font-size: 12px; }
            .net-box { margin-top: 12px; padding: 10px 14px; }
            .net-box .label { font-size: 14px; }
            .net-box .value { font-size: 18px; }
            .footer { margin-top: 14px; padding-top: 8px; font-size: 10px; }
            /* Prevent table rows from splitting across pages */
            tr, .section, .net-box, .footer { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php if (!$pdf_mode): ?>
        <div class="no-print">
            <?php if (empty($is_token_view)): ?>
            <nav class="slip-crumb-bar" aria-label="Breadcrumb">
                <a href="index.php" class="slip-crumb-link">หน้าแรก</a>
                <span class="slip-crumb-sep" aria-hidden="true">/</span>
                <a href="payroll.php" class="slip-crumb-link">เงินเดือน</a>
                <span class="slip-crumb-sep" aria-hidden="true">/</span>
                <a href="payroll.php?run_id=<?php echo (int) $slip['payroll_run_id']; ?>" class="slip-crumb-link">รายละเอียดรอบ</a>
                <span class="slip-crumb-sep" aria-hidden="true">/</span>
                <span class="slip-crumb-current"><?php echo htmlspecialchars($emp_code !== '' ? $emp_code : 'สลิป', ENT_QUOTES, 'UTF-8'); ?></span>
            </nav>
            <?php endif; ?>
            <div class="slip-toolbar">
                <button type="button" class="btn-print" onclick="window.print();">พิมพ์ / บันทึกเป็น PDF</button>
                <?php if (empty($is_token_view)): ?>
                <a href="payroll.php?run_id=<?php echo (int) $slip['payroll_run_id']; ?>" class="link-back">← กลับรายการสลิป</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <header class="doc-header">
            <div class="doc-header-logo">
                <?php if (file_exists(__DIR__ . $logo_brand_file)): ?>
                <img src="<?php echo htmlspecialchars($logo_brand_src); ?>" alt="<?php echo htmlspecialchars($company_name); ?>">
                <?php else: ?>
                <span style="font-size:18px;font-weight:700;color:#1a365d;"><?php echo htmlspecialchars($company_name); ?></span>
                <?php endif; ?>
            </div>
            <div class="doc-header-right">
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="doc-type">Payroll Statement / ใบสำคัญจ่ายเงินเดือน</div>
                <div class="tax-id" style="font-size:11px;color:#475569;margin-top:3px;font-weight:500;">เลขทะเบียนนิติบุคคล / Tax ID: <span style="font-weight:600;color:#1a365d;font-variant-numeric:tabular-nums;">0135569010741</span></div>
                <div class="doc-period" style="font-size:12px;color:#475569;margin-top:3px;font-weight:600;">Period <?php echo date('m/Y', strtotime($slip['payroll_month'])); ?></div>
                <div class="doc-paydate" style="font-size:11px;color:#0f766e;margin-top:3px;font-weight:700;">Pay Date <?php echo htmlspecialchars($pay_date_en); ?></div>
            </div>
        </header>

        <h1 class="doc-title">สลิปเงินเดือน ประจำเดือน <?php echo $month_label_th; ?><br><span style="font-size:14px;font-weight:600;color:#475569;">Payroll Slip — <?php echo $month_label_en; ?></span></h1>

        <div class="slip-body">
            <div class="watermark" aria-hidden="true">
                <?php if (file_exists(__DIR__ . $watermark_file)): ?>
                <img src="<?php echo htmlspecialchars($watermark_src); ?>" alt="">
                <?php endif; ?>
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
                <tr>
                    <th>งวดเงินเดือน / Pay Period</th>
                    <td><?php echo htmlspecialchars($month_label_th); ?> (<?php echo htmlspecialchars($month_label_en); ?>)</td>
                </tr>
                <tr>
                    <th>วันที่จ่ายเงินเดือน / Pay Date</th>
                    <td style="font-weight:600;color:#0f766e;"><?php echo htmlspecialchars($pay_date_th); ?> &nbsp;<span style="color:#64748b;font-weight:500;">(<?php echo htmlspecialchars($pay_date_en); ?>)</span></td>
                </tr>
            </table>
        </section>

        <?php
        // Bilingual label mapping for dynamic payroll items
        $bilingual_labels = [
            // Income items
            'ค่าตำแหน่ง'       => 'ค่าตำแหน่ง / Position Allowance',
            'ค่าเดินทาง'       => 'ค่าเดินทาง / Travel Allowance',
            'ค่าคอมมิชชั่น'    => 'ค่าคอมมิชชั่น / Commission',
            'ค่าบริหาร'        => 'ค่าบริหาร / Management Allowance',
            'ค่าอาหาร'         => 'ค่าอาหาร / Meal Allowance',
            'ค่าที่พัก'        => 'ค่าที่พัก / Housing Allowance',
            'ค่าโทรศัพท์'      => 'ค่าโทรศัพท์ / Telephone Allowance',
            'ค่าน้ำมัน'        => 'ค่าน้ำมัน / Fuel Allowance',
            'ค่าล่วงเวลา'      => 'ค่าล่วงเวลา / Overtime',
            'ค่าครองชีพ'       => 'ค่าครองชีพ / Cost of Living Allowance',
            'ค่ารถ'            => 'ค่ารถ / Transportation Allowance',
            'ค่าเช่าบ้าน'      => 'ค่าเช่าบ้าน / Housing Rental',
            'ค่าวิชาชีพ'       => 'ค่าวิชาชีพ / Professional Fee',
            'ค่าภาษา'          => 'ค่าภาษา / Language Allowance',
            'เบี้ยขยัน'        => 'เบี้ยขยัน / Diligence Allowance',
            'คอมมิชชั่น'       => 'คอมมิชชั่น / Commission',
            'รายได้อื่น'       => 'รายได้อื่น / Other Income',
            // Deduction items
            'หักเงินกู้'       => 'หักเงินกู้ / Loan Deduction',
            'เงินกู้'          => 'เงินกู้ / Loan Deduction',
            'หักเงินเบิกล่วงหน้า' => 'หักเงินเบิกล่วงหน้า / Salary Advance Deduction',
            'ค่าขาดงาน'        => 'ค่าขาดงาน / Absence Deduction',
            'ค่าสาย'           => 'ค่าสาย / Late Deduction',
            'เงินยืม'          => 'เงินยืม / Loan Advance',
            'หักอื่น'          => 'หักอื่น / Other Deduction',
            'ค่าประกัน'        => 'ค่าประกัน / Insurance',
            'ค่าสหกรณ์'        => 'ค่าสหกรณ์ / Cooperative Fee',
            'ค่าหอพัก'         => 'ค่าหอพัก / Dormitory Fee',
            'ค่าเครื่องแบบ'    => 'ค่าเครื่องแบบ / Uniform Fee',
        ];
        function bilingual_label($label, $map) {
            $label = trim($label);
            if (isset($map[$label])) return $map[$label];
            // If already contains " / " assume it's bilingual
            if (strpos($label, ' / ') !== false) return $label;
            return $label;
        }
        ?>

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
                    <?php if ((float)$slip['bonus'] != 0): ?>
                    <tr>
                        <td>โบนัส / BONUS</td>
                        <td class="amt"><?php echo number_format($slip['bonus'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float)$slip['allowances'] != 0): ?>
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
                        <td class="amt"><?php echo number_format($base_tax_withheld, 2); ?></td>
                    </tr>
                    <?php if ($extra_tax_withheld > 0): ?>
                    <tr>
                        <td>ภาษีหักเพิ่มตามคำขอ / Additional Withholding (requested)</td>
                        <td class="amt"><?php echo number_format($extra_tax_withheld, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float)$slip['provident_fund'] != 0): ?>
                    <tr>
                        <td>กองทุนสำรองเลี้ยงชีพ / Provident Fund</td>
                        <td class="amt"><?php echo number_format($slip['provident_fund'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float)$slip['social_security'] != 0): ?>
                    <tr>
                        <td>ประกันสังคม / Social Security</td>
                        <td class="amt"><?php echo number_format($slip['social_security'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (isset($slip['group_insurance']) && (float)$slip['group_insurance'] != 0): ?>
                    <tr>
                        <td>ประกันกลุ่ม (ส่วนของพนักงาน) / Group Insurance (Employee Share)</td>
                        <td class="amt"><?php echo number_format($slip['group_insurance'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (isset($slip['health_insurance']) && (float)$slip['health_insurance'] != 0): ?>
                    <tr>
                        <td>ประกันสุขภาพ (ส่วนของพนักงาน) / Health Insurance (Employee Share)</td>
                        <td class="amt"><?php echo number_format($slip['health_insurance'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (isset($slip['absence_deduction']) && (float)$slip['absence_deduction'] != 0): ?>
                    <tr>
                        <td>ค่าขาดงาน / Absence Deduction<?php
                            $ad = (float)($slip['absent_days'] ?? 0);
                            if ($ad > 0) echo ' <span style="color:#64748b;font-size:10px;">(' . rtrim(rtrim(number_format($ad, 1), '0'), '.') . ' วัน)</span>';
                        ?></td>
                        <td class="amt"><?php echo number_format($slip['absence_deduction'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (isset($slip['lateness_deduction']) && (float)$slip['lateness_deduction'] != 0): ?>
                    <tr>
                        <td>ค่ามาสาย / Late Deduction<?php
                            $l30 = (int)($slip['late_count_30'] ?? 0);
                            $l60 = (int)($slip['late_count_60'] ?? 0);
                            $tier1 = function_exists('payroll_late_tier1_min_minutes') ? payroll_late_tier1_min_minutes() : 20;
                            $parts = [];
                            if ($l30 > 0) $parts[] = "{$tier1}-30นาที × {$l30}";
                            if ($l60 > 0) $parts[] = "31-60นาที × {$l60}";
                            if ($parts) echo ' <span style="color:#64748b;font-size:10px;">(' . implode(', ', $parts) . ')</span>';
                        ?></td>
                        <td class="amt"><?php echo number_format($slip['lateness_deduction'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php
                    // Reserved labels ถูกแสดงเป็นแถวเฉพาะ (absence/lateness) ด้านบนแล้ว — filter ออกกัน duplicate
                    $reserved_labels = ['ค่าขาดงาน', 'ค่าสาย', 'ค่ามาสาย'];
                    $deduction_other = !empty($slip['deduction_other_json']) ? json_decode($slip['deduction_other_json'], true) : [];
                    if (is_array($deduction_other)) foreach ($deduction_other as $do):
                        $amt = (float)($do['amount'] ?? 0);
                        $label = trim((string)($do['label'] ?? $do['name'] ?? ''));
                        $source_type = (string)($do['source_type'] ?? '');
                        if ($source_type === 'employee_loan_repayment') {
                            $installment = (int)($do['installment_no'] ?? 0);
                            $installment_total = (int)($do['installment_total'] ?? 0);
                            if (($installment <= 0 || $installment_total <= 0) && !empty($do['source_id'])) {
                                $loanContext = payroll_employee_loan_installment_context($pdo, (int)$do['source_id']);
                                $installment = (int)($loanContext['installment_no'] ?? $installment);
                                $installment_total = (int)($loanContext['installment_total'] ?? $installment_total);
                            }
                            $label = 'เงินกู้บริษัท งวดที่ ' . $installment . ' / ' . $installment_total;
                        } elseif ($source_type === 'salary_advance') {
                            $label = 'หักเงินเบิกล่วงหน้า';
                        } elseif ($label === '') {
                            $label = 'หักอื่น';
                        }
                        if ($amt == 0) continue;
                        if (in_array($label, $reserved_labels, true)) continue;
                    ?>
                    <tr>
                        <td><?php
                            echo htmlspecialchars($source_type === 'employee_loan_repayment' ? $label : bilingual_label($label, $bilingual_labels));
                        ?></td>
                        <td class="amt"><?php echo number_format($amt, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total">
                        <th>รายการหักทั้งสิ้น / Total Deduction</th>
                        <td class="amt"><?php echo number_format($slip['total_deductions'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <div class="net-box">
            <span class="label">เงินได้สุทธิ / Net Total Income</span>
            <span class="value"><?php echo number_format($slip['net_salary'], 2); ?> <span>THB</span></span>
        </div>

        <?php if ($ytd): ?>
        <section class="section" style="margin-top:20px;">
            <table class="amount-table">
                <thead>
                    <tr>
                        <th colspan="2" style="background:#1a365d;color:#fff;font-size:12px;letter-spacing:0.03em;">รายได้สะสมตั้งแต่ต้นปี / Year-to-Date (<?php echo $ytd_year; ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>รายได้รวมสะสม / Cumulative Income</td>
                        <td class="amt"><?php echo number_format($ytd['ytd_income'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>ภาษีหัก ณ ที่จ่ายสะสม / Cumulative Withholding Tax</td>
                        <td class="amt"><?php echo number_format($ytd['ytd_tax'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>ประกันสังคมสะสม / Cumulative Social Security</td>
                        <td class="amt"><?php echo number_format($ytd['ytd_ss'], 2); ?></td>
                    </tr>
                    <?php if ((float)$ytd['ytd_pf'] > 0): ?>
                    <tr>
                        <td>กองทุนสำรองเลี้ยงชีพสะสม / Cumulative Provident Fund</td>
                        <td class="amt"><?php echo number_format($ytd['ytd_pf'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float)($ytd['ytd_gi'] ?? 0) > 0): ?>
                    <tr>
                        <td>ประกันกลุ่มสะสม / Cumulative Group Insurance</td>
                        <td class="amt"><?php echo number_format($ytd['ytd_gi'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float)($ytd['ytd_hi'] ?? 0) > 0): ?>
                    <tr>
                        <td>ประกันสุขภาพสะสม / Cumulative Health Insurance</td>
                        <td class="amt"><?php echo number_format($ytd['ytd_hi'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total">
                        <th>เงินได้สุทธิสะสม / Cumulative Net Income</th>
                        <td class="amt"><?php echo number_format($ytd['ytd_net'], 2); ?></td>
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
<?php
if ($pdf_mode) {
    $html = ob_get_clean();

    // Inline local logo/watermark as data URIs so chromium (file://) resolves them
    $__inline = static function (string $absPath): string {
        if (!is_file($absPath)) return '';
        $ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png'
              : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg'
              : ($ext === 'svg' ? 'image/svg+xml' : 'application/octet-stream'));
        return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($absPath));
    };
    foreach ([$logo_brand_file, $watermark_file] as $rel) {
        $abs = __DIR__ . $rel;
        $src = $base_path . $rel;
        $data = $__inline($abs);
        if ($data !== '') {
            $html = str_replace('src="' . htmlspecialchars($src) . '"', 'src="' . $data . '"', $html);
        }
    }

    // Locate chromium binary. Use shell test because is_executable() may be
    // blocked by open_basedir on managed hosts (e.g. Plesk).
    $chromium = null;
    $candidates = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/snap/bin/chromium',
        '/opt/homebrew/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ];
    foreach ($candidates as $bin) {
        $probe = [];
        $prc = 1;
        @exec('test -x ' . escapeshellarg($bin) . ' && echo OK', $probe, $prc);
        if ($prc === 0 && isset($probe[0]) && $probe[0] === 'OK') {
            $chromium = $bin;
            break;
        }
    }
    if ($chromium === null) {
        // fallback: ask the shell
        $which = [];
        @exec('command -v chromium || command -v chromium-browser || command -v google-chrome', $which);
        if (!empty($which[0])) { $chromium = trim($which[0]); }
    }
    if ($chromium === null) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><h2>ไม่สามารถสร้างไฟล์ PDF ได้</h2><p>Chromium ไม่ได้ติดตั้ง กรุณาแจ้งผู้ดูแลระบบ</p>';
        exit;
    }

    // Write HTML to a temp file, render to PDF via headless chromium.
    // Use workspace storage/ dir (guaranteed writable + inside open_basedir).
    $workDir = __DIR__ . '/storage/tmp/pdf';
    if (!is_dir($workDir)) { @mkdir($workDir, 0775, true); }
    // Sweep orphans older than 1 hour (best-effort; ignore errors)
    $cutoff = time() - 3600;
    foreach ((array)@glob($workDir . '/slip_*') as $old) {
        if (@filemtime($old) < $cutoff) { @unlink($old); }
    }
    foreach ((array)@glob($workDir . '/profile_*', GLOB_ONLYDIR) as $old) {
        if (@filemtime($old) < $cutoff) { @exec('rm -rf ' . escapeshellarg($old)); }
    }
    $uid   = bin2hex(random_bytes(8));
    $htmlF = $workDir . '/slip_' . $uid . '.html';
    $pdfF  = $workDir . '/slip_' . $uid . '.pdf';
    $userDataDir = $workDir . '/profile_' . $uid;
    @mkdir($userDataDir, 0775, true);
    file_put_contents($htmlF, $html);

    // Guarantee cleanup even on fatal error / client disconnect mid-readfile
    register_shutdown_function(static function () use ($htmlF, $pdfF, $userDataDir) {
        @unlink($htmlF);
        @unlink($pdfF);
        if (is_dir($userDataDir)) { @exec('rm -rf ' . escapeshellarg($userDataDir)); }
    });

    $cmd = escapeshellcmd($chromium)
        . ' --headless=new --disable-gpu --no-sandbox --no-first-run'
        . ' --no-default-browser-check --disable-extensions --disable-translate'
        . ' --hide-scrollbars --disable-dev-shm-usage'
        . ' --user-data-dir=' . escapeshellarg($userDataDir)
        . ' --no-pdf-header-footer --print-to-pdf-no-header'
        . ' --virtual-time-budget=8000'
        . ' --print-to-pdf=' . escapeshellarg($pdfF)
        . ' ' . escapeshellarg('file://' . $htmlF)
        . ' 2>&1';

    $output = [];
    $rc = 0;
    exec($cmd, $output, $rc);

    $ok = is_file($pdfF) && filesize($pdfF) > 1024;
    if (!$ok) {
        error_log('[payslip_pdf] chromium failed rc=' . $rc . ' out=' . implode("\n", $output));
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><h2>ไม่สามารถสร้างไฟล์ PDF ได้</h2><p>กรุณาติดต่อฝ่ายบุคคล</p>';
        exit;
    }

    $filename = 'payslip_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $emp_code) . '_' . date('Y-m', $pm) . '.pdf';
    $safeFilename = crm_safe_content_disposition_filename($filename, 'payslip.pdf');
    if (!empty($is_token_view)) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
    }
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($pdfF));
    readfile($pdfF);
    // Cleanup is handled by register_shutdown_function above.
}
