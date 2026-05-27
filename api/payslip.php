<?php
/**
 * Payslip API
 * API สำหรับสลิปเงินเดือน
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'download':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                http_response_code(405);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Use POST with slip_id and CSRF token']);
                exit;
            }
            $slipId = 0;
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($ct, 'application/json') !== false) {
                $raw = file_get_contents('php://input') ?: '';
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    $data = [];
                }
                $slipId = (int)($data['slip_id'] ?? 0);
                $tok = trim((string)($data['_token'] ?? $data['csrf_token'] ?? ''));
                if (!verifyCsrfToken($tok !== '' ? $tok : null)) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Invalid token']);
                    exit;
                }
            } else {
                if (!verifyCsrfToken($_POST['_token'] ?? null)) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Invalid token']);
                    exit;
                }
                $slipId = (int)($_POST['slip_id'] ?? 0);
            }
            downloadPDF($pdo, $user, $slipId);
            break;
            
        case 'list':
            getSlipList($pdo, $user);
            break;
            
        case 'detail':
            getSlipDetail($pdo, $user);
            break;
            
        case 'ytd':
            getYTD($pdo, $user);
            break;
            
        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    tpHrLogException($e, 'api/payslip');
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด']);
}

/**
 * Download payslip as HTML attachment (same visibility as payslip.php: approved/paid only).
 */
function downloadPDF(PDO $pdo, array $user, int $slipId): void {
    if ($slipId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'slip_id required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT ps.*, pr.payroll_month, pr.status as run_status, pr.paid_date,
               emp.first_name_th, emp.last_name_th, emp.employee_code, emp.department, emp.position
        FROM payroll_slips ps
        JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
        JOIN users emp ON ps.user_id = emp.id
        WHERE ps.id = ? AND ps.user_id = ? AND pr.status IN ('approved', 'paid')
    ");
    $stmt->execute([$slipId, $user['id']]);
    $slip = $stmt->fetch();

    if (!$slip) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูลสลิป']);
        exit;
    }
    
    // Generate HTML for PDF
    $month = date('n', strtotime($slip['payroll_month']));
    $year = date('Y', strtotime($slip['payroll_month'])) + 543;
    $monthName = thaiMonth($month);
    
    $html = generatePayslipHTML($slip, $monthName, $year);
    
    // For now, output as HTML (in production, use a PDF library like TCPDF or DOMPDF)
    $attachName = hr_safe_content_disposition_filename(
        'payslip_' . ($slip['employee_code'] ?? '') . '_' . date('Ym', strtotime($slip['payroll_month'])) . '.html',
        'payslip.html'
    );
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $attachName . '"');
    header('X-Content-Type-Options: nosniff');
    echo $html;
}

/**
 * Generate HTML content for payslip
 */
function generatePayslipHTML($slip, $monthName, $year) {
    $otherIncome = [];
    if (!empty($slip['income_other_json'])) {
        $otherIncome = json_decode($slip['income_other_json'], true) ?: [];
    }
    
    $otherDeduction = [];
    if (!empty($slip['deduction_other_json'])) {
        $otherDeduction = json_decode($slip['deduction_other_json'], true) ?: [];
    }
    
    $companyName = getSetting('company_name', 'บริษัท ทีพี-แอสเสท ดีเวลลอปเม้นท์ จำกัด');
    
    $html = '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>สลิปเงินเดือน ' . $monthName . ' ' . $year . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: "TH Sarabun New", "Sarabun", sans-serif; font-size: 16px; line-height: 1.5; min-height: 100vh; min-height: 100dvh; }
        @media screen {
            body {
                padding-left: env(safe-area-inset-left, 0px);
                padding-right: env(safe-area-inset-right, 0px);
                padding-top: env(safe-area-inset-top, 0px);
                padding-bottom: env(safe-area-inset-bottom, 0px);
            }
        }
        .container { max-width: 800px; margin: 20px auto; padding: 30px; border: 1px solid #ddd; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header h2 { font-size: 18px; color: #666; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .info-col { width: 48%; }
        .info-label { color: #666; font-size: 14px; }
        .section { margin: 20px 0; }
        .section-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; padding: 5px 10px; }
        .income-title { background: #e8f5e9; color: #2e7d32; }
        .deduction-title { background: #ffebee; color: #c62828; }
        .item-row { display: flex; justify-content: space-between; padding: 5px 10px; border-bottom: 1px dotted #ddd; }
        .total-row { font-weight: bold; border-top: 2px solid #333; margin-top: 10px; padding-top: 10px; }
        .net-box { background: #f5f5f5; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
        .net-label { font-size: 16px; color: #666; }
        .net-amount { font-size: 32px; font-weight: bold; color: #1976d2; }
        .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; color: #999; font-size: 14px; }
        .grid { display: flex; gap: 30px; }
        .grid > div { flex: 1; }
        @media print {
            body { padding: 0 !important; min-height: auto !important; }
            .container { border: none; margin: 0; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($companyName) . '</h1>
            <h2>ใบแสดงรายได้ประจำเดือน ' . $monthName . ' ' . $year . '</h2>
        </div>
        
        <div class="info-row">
            <div class="info-col">
                <span class="info-label">รหัสพนักงาน:</span> ' . htmlspecialchars($slip['employee_code'] ?? '-') . '
            </div>
            <div class="info-col">
                <span class="info-label">แผนก:</span> ' . htmlspecialchars($slip['department'] ?? '-') . '
            </div>
        </div>
        <div class="info-row">
            <div class="info-col">
                <span class="info-label">ชื่อ-นามสกุล:</span> ' . htmlspecialchars($slip['first_name_th'] . ' ' . $slip['last_name_th']) . '
            </div>
            <div class="info-col">
                <span class="info-label">ตำแหน่ง:</span> ' . htmlspecialchars($slip['position'] ?? '-') . '
            </div>
        </div>
        
        <div class="grid">
            <div class="section">
                <div class="section-title income-title">รายได้</div>
                <div class="item-row">
                    <span>เงินเดือน</span>
                    <span>' . number_format($slip['gross_salary'], 2) . '</span>
                </div>';
    
    if ($slip['bonus'] > 0) {
        $html .= '<div class="item-row">
                    <span>โบนัส</span>
                    <span>' . number_format($slip['bonus'], 2) . '</span>
                </div>';
    }
    
    if ($slip['allowances'] > 0) {
        $html .= '<div class="item-row">
                    <span>ค่าเบี้ยเลี้ยง/สวัสดิการ</span>
                    <span>' . number_format($slip['allowances'], 2) . '</span>
                </div>';
    }
    
    foreach ($otherIncome as $item) {
        if ($item['amount'] > 0) {
            $html .= '<div class="item-row">
                    <span>' . htmlspecialchars($item['label']) . '</span>
                    <span>' . number_format($item['amount'], 2) . '</span>
                </div>';
        }
    }
    
    $html .= '<div class="item-row total-row">
                    <span>รวมรายได้</span>
                    <span>' . number_format($slip['total_income'], 2) . '</span>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title deduction-title">รายการหัก</div>';
    
    if ($slip['tax_withheld'] > 0) {
        $html .= '<div class="item-row">
                    <span>ภาษีหัก ณ ที่จ่าย</span>
                    <span>' . number_format($slip['tax_withheld'], 2) . '</span>
                </div>';
    }
    
    if ($slip['social_security'] > 0) {
        $html .= '<div class="item-row">
                    <span>ประกันสังคม</span>
                    <span>' . number_format($slip['social_security'], 2) . '</span>
                </div>';
    }
    
    if ($slip['provident_fund'] > 0) {
        $html .= '<div class="item-row">
                    <span>กองทุนสำรองเลี้ยงชีพ</span>
                    <span>' . number_format($slip['provident_fund'], 2) . '</span>
                </div>';
    }
    
    foreach ($otherDeduction as $item) {
        if ($item['amount'] > 0) {
            $html .= '<div class="item-row">
                    <span>' . htmlspecialchars($item['label']) . '</span>
                    <span>' . number_format($item['amount'], 2) . '</span>
                </div>';
        }
    }
    
    $html .= '<div class="item-row total-row">
                    <span>รวมรายการหัก</span>
                    <span>' . number_format($slip['total_deductions'], 2) . '</span>
                </div>
            </div>
        </div>
        
        <div class="net-box">
            <div class="net-label">เงินได้สุทธิ</div>
            <div class="net-amount">' . number_format($slip['net_salary'], 2) . ' บาท</div>
        </div>
        
        <div class="footer">
            เอกสารนี้ออกโดยระบบอัตโนมัติ ไม่ต้องลงลายมือชื่อ
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

/**
 * Get slip list
 */
function getSlipList($pdo, $user) {
    header('Content-Type: application/json');
    
    $year = (int)($_GET['year'] ?? date('Y'));
    
    $stmt = $pdo->prepare("
        SELECT ps.id, ps.gross_salary, ps.total_income, ps.total_deductions, ps.net_salary,
               pr.payroll_month, pr.status, pr.paid_date
        FROM payroll_slips ps
        JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
        WHERE ps.user_id = ? AND YEAR(pr.payroll_month) = ?
        AND pr.status IN ('pending', 'approved', 'paid')
        ORDER BY pr.payroll_month DESC
    ");
    $stmt->execute([$user['id'], $year]);
    $slips = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'slips' => $slips]);
}

/**
 * Get slip detail
 */
function getSlipDetail($pdo, $user) {
    header('Content-Type: application/json');
    
    $slipId = (int)($_GET['slip_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT ps.*, pr.payroll_month, pr.status as run_status, pr.paid_date,
               emp.first_name_th, emp.last_name_th, emp.employee_code, emp.department, emp.position
        FROM payroll_slips ps
        JOIN payroll_runs pr ON ps.payroll_run_id = pr.id
        JOIN users emp ON ps.user_id = emp.id
        WHERE ps.id = ? AND ps.user_id = ?
    ");
    $stmt->execute([$slipId, $user['id']]);
    $slip = $stmt->fetch();
    
    if (!$slip) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
        return;
    }
    
    echo json_encode(['success' => true, 'slip' => $slip]);
}

/**
 * Get Year-to-Date summary
 */
function getYTD($pdo, $user) {
    header('Content-Type: application/json');
    
    $year = (int)($_GET['year'] ?? date('Y'));
    
    $stmt = $pdo->prepare("
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
    $stmt->execute([$user['id'], $year]);
    $ytd = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'year' => $year,
        'ytd' => $ytd
    ]);
}
