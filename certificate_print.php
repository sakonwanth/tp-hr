<?php
/**
 * Certificate Print / Generator
 * พิมพ์หนังสือรับรอง - ใช้ Browser Print-to-PDF (มาตรฐานบริษัท)
 *
 * Access rules:
 *   - HR staff: can print any request
 *   - Owner (requester): can print own request only after status = PROCESSING / READY
 *
 * Query params:
 *   id = hr_document_requests.id
 *   lang (optional) = TH | EN — override request.language (BOTH renders both)
 *   preview (optional) = 1 — show preview chrome (default: print-ready, no chrome)
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$pdo = Database::getInstance()->getConnection();

$reqId   = (int)($_GET['id'] ?? 0);
$langOv  = strtoupper($_GET['lang'] ?? '');
$preview = !empty($_GET['preview']);

if (!$reqId) { http_response_code(400); exit('ไม่พบคำขอเอกสาร'); }

// ------------------------------------------------------------------
// Fetch request + employee + template
// ------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT dr.*, dt.code AS tpl_code, dt.name AS tpl_name, dt.name_en AS tpl_name_en,
           u.id AS uid, u.employee_code, u.first_name_th, u.last_name_th,
           u.first_name_en, u.last_name_en, u.email, u.phone,
           u.national_id, u.position, u.department, u.salary, u.hire_date,
           u.address
    FROM hr_document_requests dr
    JOIN hr_document_templates dt ON dt.id = dr.template_id
    JOIN users u ON u.id = dr.user_id
    WHERE dr.id = ?
");
$stmt->execute([$reqId]);
$req = $stmt->fetch();

if (!$req) { http_response_code(404); exit('ไม่พบคำขอเอกสาร'); }

// Authorization
$isOwner = ((int)$req['user_id'] === (int)$user['id']);
$isHR    = isHR();
if (!$isHR && !$isOwner) { http_response_code(403); exit('ไม่มีสิทธิ์เข้าถึงเอกสารนี้'); }
if ($isOwner && !$isHR && !in_array($req['status'], ['PROCESSING','READY','DELIVERED'], true)) {
    http_response_code(403);
    exit('เอกสารยังไม่พร้อมดาวน์โหลด (สถานะ: ' . htmlspecialchars($req['status']) . ')');
}

// ------------------------------------------------------------------
// Company settings
// ------------------------------------------------------------------
$settingsRaw = $pdo->query("SELECT `key`, `value` FROM hr_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$S = fn(string $k, string $default = '') => $settingsRaw[$k] ?? $default;

$company = [
    'name_th'    => $S('company_name',    'บริษัท'),
    'name_en'    => $S('company_name_en', 'Company Name Co., Ltd.'),
    'address'    => $S('company_address', ''),
    'phone'      => $S('company_phone',   ''),
    'email'      => $S('company_email',   ''),
    'tax_id'     => $S('tax_id',          ''),
    'signer_th'  => $S('document_signer_name_th',  'ผู้จัดการฝ่ายทรัพยากรบุคคล'),
    'signer_en'  => $S('document_signer_name_en',  'Human Resources Manager'),
    'signer_position_th' => $S('document_signer_position_th', 'ผู้จัดการฝ่ายทรัพยากรบุคคล'),
    'signer_position_en' => $S('document_signer_position_en', 'Human Resources Manager'),
];

// ------------------------------------------------------------------
// Issue / reuse document number + verification code
// ------------------------------------------------------------------
$docNumber = $req['document_number'];
$verifyCode = null;
if (!$docNumber && $isHR) {
    // auto-assign on first HR print
    $year = (int)date('Y') + 543; // B.E.
    $seqStmt = $pdo->prepare("SELECT COUNT(*)+1 FROM hr_document_requests WHERE document_number LIKE ?");
    $seqStmt->execute(["HR-{$year}-%"]);
    $seq = (int)$seqStmt->fetchColumn();
    $docNumber = sprintf('HR-%d-%05d', $year, $seq);
    $verifyCode = strtoupper(bin2hex(random_bytes(6)));
    $upd = $pdo->prepare("UPDATE hr_document_requests SET document_number=?, document_date=CURDATE(), qr_verification_code=?, status=IF(status='PENDING','PROCESSING',status), processed_by=COALESCE(processed_by,?), processed_at=COALESCE(processed_at,NOW()), updated_at=NOW() WHERE id=?");
    $upd->execute([$docNumber, $verifyCode, $user['id'], $reqId]);
    $req['document_number'] = $docNumber;
    $req['document_date']   = date('Y-m-d');
} else {
    $verifyCode = $req['qr_verification_code'];
}

$docDate = $req['document_date'] ?: date('Y-m-d');

// ------------------------------------------------------------------
// Helpers (Thai / English formatters)
// ------------------------------------------------------------------
function fmtThaiDate(string $d): string {
    $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $t = strtotime($d); if (!$t) return $d;
    return (int)date('j',$t) . ' ' . $months[(int)date('n',$t)] . ' ' . ((int)date('Y',$t) + 543);
}
function fmtEnDate(string $d): string {
    $t = strtotime($d); if (!$t) return $d;
    return date('j F Y', $t);
}
function thaiBaht(float $n): string {
    static $txt = ['ศูนย์','หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า'];
    static $pos = ['','สิบ','ร้อย','พัน','หมื่น','แสน','ล้าน'];
    $n = round($n, 2);
    [$baht, $satang] = explode('.', number_format($n, 2, '.', ''));
    $convert = function($num) use ($txt,$pos) {
        if ($num == 0) return 'ศูนย์';
        $out = ''; $s = (string)$num; $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $d = (int)$s[$i]; $p = $len - $i - 1;
            if ($d == 0) continue;
            if ($p == 0 && $d == 1 && $len > 1) { $out .= 'เอ็ด'; }
            elseif ($p == 1 && $d == 2) { $out .= 'ยี่' . $pos[1]; }
            elseif ($p == 1 && $d == 1) { $out .= $pos[1]; }
            else { $out .= $txt[$d] . $pos[$p]; }
        }
        return $out;
    };
    $result = $convert((int)$baht) . 'บาท';
    $result .= ((int)$satang === 0) ? 'ถ้วน' : $convert((int)$satang) . 'สตางค์';
    return $result;
}
function yearsMonths(string $hireDate, string $asOf): string {
    $a = new DateTime($hireDate); $b = new DateTime($asOf);
    if ($a > $b) return '-';
    $d = $a->diff($b);
    $parts = [];
    if ($d->y > 0) $parts[] = $d->y . ' ปี';
    if ($d->m > 0) $parts[] = $d->m . ' เดือน';
    if (!$parts) $parts[] = 'ไม่ถึง 1 เดือน';
    return implode(' ', $parts);
}
function yearsMonthsEn(string $hireDate, string $asOf): string {
    $a = new DateTime($hireDate); $b = new DateTime($asOf);
    if ($a > $b) return '-';
    $d = $a->diff($b);
    $parts = [];
    if ($d->y > 0) $parts[] = $d->y . ' year' . ($d->y > 1 ? 's' : '');
    if ($d->m > 0) $parts[] = $d->m . ' month' . ($d->m > 1 ? 's' : '');
    if (!$parts) $parts[] = 'less than 1 month';
    return implode(' ', $parts);
}

// ------------------------------------------------------------------
// Decide which languages to render
// ------------------------------------------------------------------
$reqLang = $langOv ?: ($req['language'] ?: 'TH');
// TAX_50TAWI is always bilingual (50 ทวิ is standard Thai tax form). CERT_*_EN → EN. etc.
$tplCode = $req['tpl_code'];
$renderLangs = [];
if ($reqLang === 'BOTH') $renderLangs = ['TH', 'EN'];
elseif ($reqLang === 'EN' || str_ends_with($tplCode, '_EN')) $renderLangs = ['EN'];
else $renderLangs = ['TH'];
if ($tplCode === 'CERT_SALARY_BANK') $renderLangs = ['TH']; // bank letter = TH standard

// Prepare view data
$V = [
    'fullName_th' => trim(($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? '')),
    'fullName_en' => trim(($req['first_name_en'] ?? '') . ' ' . ($req['last_name_en'] ?? '')) ?: 'N/A',
    'empCode'     => $req['employee_code'] ?: '-',
    'nationalId'  => $req['national_id'] ?: '-',
    'position'    => $req['position'] ?: '-',
    'department'  => $req['department'] ?: '-',
    'salary'      => (float)($req['salary'] ?? 0),
    'hireDate'    => $req['hire_date'],
    'purpose'     => trim($req['purpose_detail'] ?? '') ?: match($req['purpose']) {
        'VISA'  => 'ประกอบการขอวีซ่า / เดินทางไปต่างประเทศ',
        'BANK'  => 'ประกอบการติดต่อธนาคาร',
        'STUDY' => 'ประกอบการสมัครเรียนต่อ',
        'JOB'   => 'ประกอบการสมัครงาน',
        'COURT' => 'ประกอบการติดต่อราชการ',
        default => 'ประกอบการใช้ตามวัตถุประสงค์ของผู้ร้องขอ',
    },
    'purposeEn'   => match($req['purpose']) {
        'VISA'  => 'for visa application / overseas travel',
        'BANK'  => 'for banking purposes',
        'STUDY' => 'for further education',
        'JOB'   => 'for job application',
        'COURT' => 'for government purposes',
        default => 'for personal use as required',
    },
];

$page_title = 'หนังสือรับรอง - ' . $V['fullName_th'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($page_title); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Sarabun', 'TH SarabunNew', 'Sarabun New', sans-serif;
        font-size: 16pt;
        line-height: 1.55;
        color: #111;
        background: #e5e7eb;
        margin: 0;
        padding: 20px;
    }
    .toolbar {
        max-width: 210mm;
        margin: 0 auto 16px;
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 14px;
    }
    .toolbar button, .toolbar a {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #0f172a;
        cursor: pointer;
        text-decoration: none;
        font-weight: 500;
    }
    .toolbar .primary { background: #7c3aed; color: #fff; border-color: #7c3aed; }
    .toolbar .primary:hover { background: #6d28d9; }

    .page {
        width: 210mm;
        min-height: 297mm;
        background: #fff;
        padding: 25mm 22mm 25mm 25mm;
        margin: 0 auto 20px;
        box-shadow: 0 4px 24px rgba(0,0,0,.12);
        position: relative;
        page-break-after: always;
    }
    .page:last-child { page-break-after: auto; margin-bottom: 0; }

    .letterhead {
        border-bottom: 2px solid #111;
        padding-bottom: 10pt;
        margin-bottom: 18pt;
        text-align: center;
    }
    .letterhead h1 { font-size: 22pt; margin: 0 0 2pt; font-weight: 700; }
    .letterhead h2 { font-size: 14pt; margin: 0 0 6pt; font-weight: 500; color: #374151; }
    .letterhead .meta { font-size: 13pt; color: #4b5563; line-height: 1.4; }

    .doc-meta { display: flex; justify-content: space-between; margin: 14pt 0 18pt; font-size: 15pt; }
    .doc-title { text-align: center; font-size: 20pt; font-weight: 700; margin: 18pt 0 20pt; text-decoration: underline; text-underline-offset: 4pt; }

    .body p { margin: 0 0 10pt; text-indent: 2em; text-align: justify; }
    .body p.no-indent { text-indent: 0; }
    .body .kv-row { display: flex; margin: 4pt 0; }
    .body .kv-row .k { min-width: 180pt; }
    .body .kv-row .v { flex: 1; font-weight: 500; }

    .signature {
        margin-top: 40pt;
        display: flex;
        justify-content: flex-end;
    }
    .signature .block {
        text-align: center;
        min-width: 260pt;
    }
    .signature .sign-line {
        border-bottom: 1px dotted #111;
        margin: 60pt 20pt 6pt;
    }
    .signature .name { font-weight: 600; }
    .signature .title { font-size: 14pt; color: #374151; }

    .footer-verify {
        position: absolute;
        left: 25mm;
        right: 22mm;
        bottom: 15mm;
        border-top: 1px solid #d1d5db;
        padding-top: 6pt;
        font-size: 10pt;
        color: #6b7280;
        display: flex;
        justify-content: space-between;
    }

    /* 50 ทวิ table */
    .tax-table { width: 100%; border-collapse: collapse; font-size: 13pt; margin-top: 10pt; }
    .tax-table th, .tax-table td { border: 1px solid #111; padding: 6pt 8pt; }
    .tax-table th { background: #f3f4f6; text-align: center; }
    .tax-table td.num { text-align: right; font-variant-numeric: tabular-nums; }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar { display: none; }
        .page {
            width: auto; min-height: auto; margin: 0; padding: 25mm 22mm 25mm 25mm;
            box-shadow: none;
        }
        @page { size: A4; margin: 0; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <?php if ($req['language'] === 'BOTH' || count($renderLangs) === 2): ?>
        <a href="?id=<?= $reqId ?>&lang=TH&preview=1" class="">ไทยอย่างเดียว</a>
        <a href="?id=<?= $reqId ?>&lang=EN&preview=1" class="">อังกฤษอย่างเดียว</a>
        <a href="?id=<?= $reqId ?>&lang=BOTH&preview=1" class="">ทั้งสองภาษา</a>
    <?php endif; ?>
    <a href="/hr/documents.php" class="">กลับ</a>
    <button onclick="window.print()" class="primary"><i></i> พิมพ์ / บันทึกเป็น PDF</button>
</div>

<?php foreach ($renderLangs as $lang):
    $isEn = ($lang === 'EN');
?>
<div class="page">
    <!-- Letterhead -->
    <div class="letterhead">
        <h1><?php echo htmlspecialchars($isEn ? $company['name_en'] : $company['name_th']); ?></h1>
        <?php if (!$isEn && $company['name_en']): ?>
            <h2><?php echo htmlspecialchars($company['name_en']); ?></h2>
        <?php endif; ?>
        <div class="meta">
            <?php echo htmlspecialchars($company['address']); ?><br>
            <?php echo $isEn ? 'Tel.' : 'โทร.'; ?> <?php echo htmlspecialchars($company['phone']); ?>
            &nbsp;•&nbsp; <?php echo $isEn ? 'Email' : 'อีเมล'; ?>: <?php echo htmlspecialchars($company['email']); ?>
            <?php if ($company['tax_id']): ?>
                &nbsp;•&nbsp; <?php echo $isEn ? 'Tax ID' : 'เลขประจำตัวผู้เสียภาษี'; ?> <?php echo htmlspecialchars($company['tax_id']); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Doc meta -->
    <div class="doc-meta">
        <span><?php echo $isEn ? 'Ref.' : 'ที่'; ?> <?php echo htmlspecialchars($docNumber); ?></span>
        <span><?php echo $isEn ? 'Date: ' . fmtEnDate($docDate) : 'วันที่ ' . fmtThaiDate($docDate); ?></span>
    </div>

    <?php
    // ================== BODY PER TEMPLATE ==================
    $code = $tplCode;
    $hireStr = $V['hireDate'] ? ($isEn ? fmtEnDate($V['hireDate']) : fmtThaiDate($V['hireDate'])) : '-';
    $tenure  = $V['hireDate'] ? ($isEn ? yearsMonthsEn($V['hireDate'], $docDate) : yearsMonths($V['hireDate'], $docDate)) : '-';
    ?>

    <?php if ($code === 'CERT_WORK_TH' || ($code === 'CERT_WORK_EN' && !$isEn) || (in_array($code,['CERT_WORK_TH','CERT_WORK_EN']) && !$isEn)): ?>
        <!-- หนังสือรับรองการทำงาน - ไทย -->
        <div class="doc-title">หนังสือรับรองการทำงาน</div>
        <div class="body">
            <p><?php echo htmlspecialchars($company['name_th']); ?> ขอรับรองว่า</p>
            <div class="kv-row"><div class="k">ชื่อ-นามสกุล</div><div class="v"><?php echo htmlspecialchars($V['fullName_th']); ?></div></div>
            <div class="kv-row"><div class="k">เลขประจำตัวประชาชน</div><div class="v"><?php echo htmlspecialchars($V['nationalId']); ?></div></div>
            <div class="kv-row"><div class="k">รหัสพนักงาน</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div></div>
            <div class="kv-row"><div class="k">ตำแหน่ง</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div></div>
            <div class="kv-row"><div class="k">แผนก / ฝ่าย</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div></div>
            <div class="kv-row"><div class="k">วันเริ่มปฏิบัติงาน</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div></div>
            <div class="kv-row"><div class="k">อายุงาน</div><div class="v"><?php echo htmlspecialchars($tenure); ?> (นับถึงวันที่ออกหนังสือ)</div></div>
            <p style="margin-top:12pt;">เป็นพนักงานของบริษัทฯ และปัจจุบันยังคงปฏิบัติงานอยู่ หนังสือรับรองฉบับนี้ออกให้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?></p>
            <p>จึงออกหนังสือฉบับนี้ไว้เป็นหลักฐาน</p>
        </div>

    <?php elseif ($code === 'CERT_WORK_EN' || ($code === 'CERT_WORK_TH' && $isEn) || (in_array($code,['CERT_WORK_TH','CERT_WORK_EN']) && $isEn)): ?>
        <!-- Certificate of Employment - English -->
        <div class="doc-title">CERTIFICATE OF EMPLOYMENT</div>
        <div class="body">
            <p class="no-indent">TO WHOM IT MAY CONCERN,</p>
            <p>This is to certify that <strong><?php echo htmlspecialchars($V['fullName_en']); ?></strong> has been employed by <?php echo htmlspecialchars($company['name_en']); ?> with the following details:</p>
            <div class="kv-row"><div class="k">Full Name</div><div class="v"><?php echo htmlspecialchars($V['fullName_en']); ?></div></div>
            <div class="kv-row"><div class="k">Employee ID</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div></div>
            <div class="kv-row"><div class="k">Position</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div></div>
            <div class="kv-row"><div class="k">Department</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div></div>
            <div class="kv-row"><div class="k">Date of Employment</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div></div>
            <div class="kv-row"><div class="k">Length of Service</div><div class="v"><?php echo htmlspecialchars($tenure); ?> (as of the issue date)</div></div>
            <p style="margin-top:12pt;">The above-mentioned person is currently employed by the company. This certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>.</p>
            <p>Issued as evidence of the above.</p>
        </div>

    <?php elseif ($code === 'CERT_SALARY_TH' || $code === 'CERT_SALARY_BANK' || ($code === 'CERT_SALARY_EN' && !$isEn)): ?>
        <!-- หนังสือรับรองเงินเดือน - ไทย -->
        <div class="doc-title">หนังสือรับรองเงินเดือน<?php echo $code === 'CERT_SALARY_BANK' ? ' (สำหรับธนาคาร)' : ''; ?></div>
        <div class="body">
            <p><?php echo htmlspecialchars($company['name_th']); ?> ขอรับรองว่า</p>
            <div class="kv-row"><div class="k">ชื่อ-นามสกุล</div><div class="v"><?php echo htmlspecialchars($V['fullName_th']); ?></div></div>
            <div class="kv-row"><div class="k">เลขประจำตัวประชาชน</div><div class="v"><?php echo htmlspecialchars($V['nationalId']); ?></div></div>
            <div class="kv-row"><div class="k">ตำแหน่ง</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div></div>
            <div class="kv-row"><div class="k">แผนก / ฝ่าย</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div></div>
            <div class="kv-row"><div class="k">วันเริ่มปฏิบัติงาน</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div></div>
            <div class="kv-row"><div class="k">อายุงาน</div><div class="v"><?php echo htmlspecialchars($tenure); ?></div></div>
            <div class="kv-row"><div class="k">อัตราเงินเดือน</div><div class="v"><?php echo number_format($V['salary'], 2); ?> บาท (<?php echo thaiBaht($V['salary']); ?>)</div></div>
            <p style="margin-top:12pt;">เป็นพนักงานของบริษัทฯ และได้รับเงินเดือนตามอัตราที่ระบุข้างต้น หนังสือรับรองฉบับนี้ออกให้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?></p>
            <p>จึงออกหนังสือฉบับนี้ไว้เป็นหลักฐาน</p>
        </div>

    <?php elseif ($code === 'CERT_SALARY_EN' && $isEn): ?>
        <!-- Salary Certificate - English -->
        <div class="doc-title">CERTIFICATE OF SALARY</div>
        <div class="body">
            <p class="no-indent">TO WHOM IT MAY CONCERN,</p>
            <p>This is to certify that the following person is currently employed by <?php echo htmlspecialchars($company['name_en']); ?> and receives the monthly salary specified below:</p>
            <div class="kv-row"><div class="k">Full Name</div><div class="v"><?php echo htmlspecialchars($V['fullName_en']); ?></div></div>
            <div class="kv-row"><div class="k">Employee ID</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div></div>
            <div class="kv-row"><div class="k">Position</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div></div>
            <div class="kv-row"><div class="k">Department</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div></div>
            <div class="kv-row"><div class="k">Date of Employment</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div></div>
            <div class="kv-row"><div class="k">Length of Service</div><div class="v"><?php echo htmlspecialchars($tenure); ?></div></div>
            <div class="kv-row"><div class="k">Monthly Salary</div><div class="v">THB <?php echo number_format($V['salary'], 2); ?></div></div>
            <p style="margin-top:12pt;">This certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>.</p>
            <p>Issued as evidence of the above.</p>
        </div>

    <?php elseif ($code === 'TAX_50TAWI'): ?>
        <!-- 50 ทวิ - simplified version (ให้ใช้แทน 50 ทวิ ฉบับย่อ) -->
        <?php
            $yearTH = (int)date('Y', strtotime($docDate)) + 543;
            $yearPrev = $yearTH - 1;
            $annualSalary = $V['salary'] * 12;
            // Basic withholding estimate: 5% bracket >= 150k; this is a simplified preview only
            $wht = max(0, min($annualSalary * 0.05, $annualSalary - 150000));
        ?>
        <div class="doc-title">หนังสือรับรองการหักภาษี ณ ที่จ่าย<br>
            <span style="font-size:14pt; font-weight:500;">(ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร)</span>
        </div>
        <div class="body">
            <div class="kv-row"><div class="k">ผู้มีหน้าที่หักภาษี ณ ที่จ่าย</div><div class="v"><?php echo htmlspecialchars($company['name_th']); ?></div></div>
            <div class="kv-row"><div class="k">เลขประจำตัวผู้เสียภาษี</div><div class="v"><?php echo htmlspecialchars($company['tax_id']); ?></div></div>
            <div class="kv-row"><div class="k">ที่อยู่</div><div class="v"><?php echo htmlspecialchars($company['address']); ?></div></div>
            <hr style="margin:10pt 0; border:0; border-top:1px dashed #9ca3af;">
            <div class="kv-row"><div class="k">ผู้ถูกหักภาษี ณ ที่จ่าย</div><div class="v"><?php echo htmlspecialchars($V['fullName_th']); ?></div></div>
            <div class="kv-row"><div class="k">เลขประจำตัวประชาชน</div><div class="v"><?php echo htmlspecialchars($V['nationalId']); ?></div></div>
            <div class="kv-row"><div class="k">ปีภาษี</div><div class="v"><?php echo $yearPrev; ?> (ค.ศ. <?php echo $yearPrev - 543; ?>)</div></div>
        </div>
        <table class="tax-table">
            <thead>
                <tr>
                    <th style="width:55%;">ประเภทเงินได้ตามมาตรา 40</th>
                    <th>จำนวนเงินที่จ่าย (บาท)</th>
                    <th>ภาษีที่หัก / นำส่ง (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1. เงินเดือน ค่าจ้าง ฯลฯ ตามมาตรา 40 (1)</td>
                    <td class="num"><?php echo number_format($annualSalary, 2); ?></td>
                    <td class="num"><?php echo number_format($wht, 2); ?></td>
                </tr>
                <tr>
                    <th style="text-align:right;">รวม</th>
                    <th class="num"><?php echo number_format($annualSalary, 2); ?></th>
                    <th class="num"><?php echo number_format($wht, 2); ?></th>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:10pt; font-size:12pt; color:#6b7280;">
            * เอกสารฉบับย่อนี้แสดงข้อมูลเงินได้และภาษีประเมินจากฐานเงินเดือนในระบบ สำหรับเอกสาร 50 ทวิ ฉบับทางการให้ติดต่อฝ่ายบัญชี
        </p>

    <?php else: ?>
        <div class="body">
            <p class="no-indent">[<?php echo htmlspecialchars($req['tpl_name']); ?>]</p>
            <p>ยังไม่ได้กำหนดเทมเพลตสำหรับเอกสารประเภทนี้ กรุณาติดต่อฝ่ายทรัพยากรบุคคล</p>
        </div>
    <?php endif; ?>

    <!-- Signature block -->
    <div class="signature">
        <div class="block">
            <div style="font-size:15pt;"><?php echo $isEn ? 'Sincerely,' : 'ขอแสดงความนับถือ'; ?></div>
            <div class="sign-line"></div>
            <div class="name">( <?php echo htmlspecialchars($isEn ? $company['signer_en'] : $company['signer_th']); ?> )</div>
            <div class="title"><?php echo htmlspecialchars($isEn ? $company['signer_position_en'] : $company['signer_position_th']); ?></div>
        </div>
    </div>

    <!-- Verification footer -->
    <div class="footer-verify">
        <span>
            <?php echo $isEn ? 'Document No.' : 'เลขที่เอกสาร'; ?>: <?php echo htmlspecialchars($docNumber); ?>
            <?php if ($verifyCode): ?>
                &nbsp;|&nbsp; <?php echo $isEn ? 'Verification' : 'รหัสยืนยัน'; ?>: <?php echo htmlspecialchars($verifyCode); ?>
            <?php endif; ?>
        </span>
        <span><?php echo $isEn ? 'Page' : 'หน้า'; ?> 1 / 1</span>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$preview): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
<?php endif; ?>
</body>
</html>
