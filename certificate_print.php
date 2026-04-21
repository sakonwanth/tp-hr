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
           u.id_card AS national_id, u.position, u.department, u.salary, u.hire_date,
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

// Verification URL for QR
$verifyUrl = 'https://hr.tp-asset.com/verify_document.php?code=' . urlencode($verifyCode ?: $docNumber);
$qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&margin=0&data=' . urlencode($verifyUrl);

// Brand assets — same as CRM payslip
$LOGO_BRAND = 'https://crm.tp-asset.com/asset/logo/LOGO%20TP-ASSET%20-%206.png';
$WATERMARK  = 'https://crm.tp-asset.com/asset/logo/LOGO%20TP-ASSET%20-%205.png';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($page_title); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --brand: #1a365d;
        --brand-2: #c8a951;
        --ink: #0f172a;
        --muted: #64748b;
        --line: #cbd5e1;
        --soft: #f8fafc;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
        font-family: 'Sarabun', 'TH SarabunNew', sans-serif;
        font-size: 14pt;
        line-height: 1.55;
        color: var(--ink);
        background: #e5e7eb;
    }
    body.preview { padding: 24px 0 48px; }

    .toolbar {
        max-width: 210mm;
        margin: 0 auto 16px;
        display: flex; gap: 8px; justify-content: flex-end;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 13px;
        padding: 0 8px;
    }
    .toolbar a, .toolbar button {
        padding: 8px 14px; border-radius: 8px;
        border: 1px solid var(--line); background: #fff; color: var(--ink);
        cursor: pointer; text-decoration: none; font-weight: 500;
    }
    .toolbar .primary { background: var(--brand); color: #fff; border-color: var(--brand); }
    .toolbar .primary:hover { background: #0f2847; }

    /* ----- A4 page ----- */
    .page {
        width: 210mm;
        min-height: 297mm;
        background: #fff;
        padding: 18mm 20mm 22mm;
        margin: 0 auto 22px;
        box-shadow: 0 6px 30px rgba(15,23,42,.15);
        position: relative;
        page-break-after: always;
        overflow: hidden;
    }
    .page:last-child { page-break-after: auto; margin-bottom: 0; }

    /* Watermark */
    .watermark {
        position: absolute; inset: 0;
        display: flex; align-items: center; justify-content: center;
        pointer-events: none; z-index: 0;
    }
    .watermark img {
        width: 62%; height: auto; object-fit: contain;
        opacity: 0.05; filter: grayscale(100%);
    }

    /* Content sits above watermark */
    .page > *:not(.watermark) { position: relative; z-index: 1; }

    /* ----- Letterhead ----- */
    .letterhead {
        display: flex; align-items: center; gap: 18pt;
        padding-bottom: 12pt;
        border-bottom: 3px double var(--brand);
        margin-bottom: 4pt;
    }
    .letterhead .logo { flex-shrink: 0; }
    .letterhead .logo img { height: 62pt; width: auto; display: block; }
    .letterhead .co { flex: 1; text-align: right; line-height: 1.35; }
    .letterhead .co .name-th {
        font-size: 18pt; font-weight: 700; color: var(--brand);
        letter-spacing: 0.01em;
    }
    .letterhead .co .name-en {
        font-size: 13pt; font-weight: 600; color: #334155;
        margin-top: 1pt;
    }
    .letterhead .co .addr {
        font-size: 11.5pt; color: var(--muted); margin-top: 4pt;
    }
    .letterhead .co .contact {
        font-size: 11pt; color: var(--muted); margin-top: 2pt;
    }
    .letterhead-accent {
        height: 3pt;
        background: linear-gradient(90deg, var(--brand) 0%, var(--brand) 60%, var(--brand-2) 60%, var(--brand-2) 100%);
        margin-bottom: 16pt;
    }

    /* ----- Doc ref strip ----- */
    .doc-ref {
        display: flex; justify-content: space-between; align-items: baseline;
        font-size: 13pt; color: #334155;
        margin-bottom: 18pt;
    }
    .doc-ref .ref-no { font-weight: 600; }
    .doc-ref .ref-date { font-weight: 500; }

    /* ----- Title ----- */
    .doc-title {
        text-align: center;
        margin: 4pt 0 18pt;
    }
    .doc-title .main {
        font-size: 22pt; font-weight: 700; color: var(--brand);
        letter-spacing: 0.02em;
        position: relative; display: inline-block;
        padding: 0 28pt;
    }
    .doc-title .main::before, .doc-title .main::after {
        content: ''; position: absolute; top: 50%;
        width: 20pt; height: 2px; background: var(--brand-2);
    }
    .doc-title .main::before { left: 0; }
    .doc-title .main::after { right: 0; }
    .doc-title .sub {
        font-size: 13pt; font-weight: 500; color: var(--muted);
        margin-top: 3pt; letter-spacing: 0.04em;
    }

    /* ----- Body paragraphs ----- */
    .body { font-size: 14.5pt; color: var(--ink); }
    .body p { margin: 0 0 9pt; text-indent: 3.2em; text-align: justify; }
    .body p.no-indent { text-indent: 0; }
    .body p.lead { margin-bottom: 14pt; }

    /* ----- Info data block (bordered) ----- */
    .info-box {
        border: 1px solid #e2e8f0;
        border-left: 4px solid var(--brand);
        background: linear-gradient(180deg, #fcfdff 0%, #f8fafc 100%);
        padding: 12pt 18pt;
        margin: 10pt 0 14pt;
    }
    .info-grid {
        display: grid;
        grid-template-columns: 150pt 1fr;
        row-gap: 6pt; column-gap: 14pt;
    }
    .info-grid .k {
        font-weight: 500; color: var(--muted);
        font-size: 13pt;
    }
    .info-grid .v {
        font-weight: 600; color: var(--ink);
        font-size: 14pt;
    }
    .info-grid .k.wide + .v, .info-grid .v.wide { grid-column: 2; }

    /* ----- Signature ----- */
    .signature {
        margin-top: 28pt;
        display: flex; justify-content: flex-end;
    }
    .signature .block { text-align: center; min-width: 280pt; }
    .signature .prefix { font-size: 13pt; margin-bottom: 56pt; }
    .signature .name { font-weight: 700; font-size: 14pt; color: var(--ink); padding: 0 28pt; border-top: 1px dotted #475569; padding-top: 6pt; }
    .signature .position { font-size: 12.5pt; color: var(--muted); margin-top: 2pt; }
    .signature .issue-date { font-size: 12pt; color: var(--muted); margin-top: 8pt; font-style: italic; }
    .signature .seal {
        display: inline-block;
        width: 80pt; height: 80pt;
        border: 1.5pt dashed #94a3b8;
        border-radius: 50%;
        font-size: 9pt; color: #94a3b8;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 8pt;
    }

    /* ----- Verification footer ----- */
    .verify-footer {
        position: absolute;
        left: 20mm; right: 20mm; bottom: 10mm;
        border-top: 1pt solid var(--line);
        padding-top: 6pt;
        display: flex; justify-content: space-between; align-items: flex-end;
        font-size: 9.5pt; color: var(--muted);
        line-height: 1.4;
    }
    .verify-footer .left { max-width: 65%; }
    .verify-footer .left strong { color: #334155; }
    .verify-footer .qr { text-align: center; }
    .verify-footer .qr img { width: 58pt; height: 58pt; display: block; }
    .verify-footer .qr .cap { font-size: 8pt; margin-top: 2pt; }

    /* ----- 50 ทวิ form ----- */
    .tax-form { font-size: 13pt; }
    .tax-form .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; margin: 12pt 0; }
    .tax-form .party {
        border: 1pt solid var(--line); padding: 10pt 12pt;
        background: var(--soft);
    }
    .tax-form .party h4 {
        margin: 0 0 6pt; font-size: 12pt; color: var(--brand);
        padding-bottom: 4pt; border-bottom: 1pt solid var(--brand);
        font-weight: 700;
    }
    .tax-form .party div { font-size: 12pt; line-height: 1.5; }
    .tax-table {
        width: 100%; border-collapse: collapse;
        font-size: 12.5pt; margin: 10pt 0;
    }
    .tax-table th {
        background: var(--brand); color: #fff;
        padding: 8pt 10pt; border: 1pt solid var(--brand);
        font-weight: 600; text-align: center;
    }
    .tax-table td {
        padding: 8pt 10pt; border: 1pt solid var(--line);
    }
    .tax-table td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
    .tax-table tr.total td { background: #f1f5f9; font-weight: 700; color: var(--ink); }
    .tax-note {
        margin-top: 10pt; padding: 8pt 12pt;
        background: #fef9e7; border-left: 3pt solid var(--brand-2);
        font-size: 11pt; color: #78350f;
    }

    /* ----- Remark banner (before signature) ----- */
    .remark {
        margin: 14pt 0 6pt;
        padding: 10pt 14pt;
        background: #eff6ff; border-left: 3pt solid var(--brand);
        font-size: 13pt; color: #1e3a8a;
    }

    @media print {
        body { background: #fff; padding: 0; }
        body.preview { padding: 0; }
        .toolbar { display: none !important; }
        .page {
            width: 210mm; min-height: 297mm; margin: 0;
            box-shadow: none; padding: 18mm 20mm 22mm;
        }
        @page { size: A4; margin: 0; }
    }
</style>
</head>
<body class="<?php echo $preview ? 'preview' : ''; ?>">

<div class="toolbar">
    <?php if ($req['language'] === 'BOTH' || count($renderLangs) === 2): ?>
        <a href="?id=<?= $reqId ?>&lang=TH&preview=1">ไทยอย่างเดียว</a>
        <a href="?id=<?= $reqId ?>&lang=EN&preview=1">อังกฤษอย่างเดียว</a>
        <a href="?id=<?= $reqId ?>&lang=BOTH&preview=1">ทั้งสองภาษา</a>
    <?php endif; ?>
    <a href="javascript:history.back()">กลับ</a>
    <button onclick="window.print()" class="primary">พิมพ์ / บันทึกเป็น PDF</button>
</div>

<?php foreach ($renderLangs as $idx => $lang):
    $isEn = ($lang === 'EN');
    $pageNo = $idx + 1; $pageTotal = count($renderLangs);
?>
<div class="page">
    <!-- Watermark -->
    <div class="watermark"><img src="<?php echo $WATERMARK; ?>" alt=""></div>

    <!-- Letterhead -->
    <div class="letterhead">
        <div class="logo"><img src="<?php echo $LOGO_BRAND; ?>" alt="Logo"></div>
        <div class="co">
            <div class="name-th"><?php echo htmlspecialchars($company['name_th']); ?></div>
            <div class="name-en"><?php echo htmlspecialchars($company['name_en']); ?></div>
            <div class="addr"><?php echo htmlspecialchars($company['address']); ?></div>
            <div class="contact">
                <?php echo $isEn ? 'Tel' : 'โทร'; ?>: <?php echo htmlspecialchars($company['phone']); ?>
                &nbsp;|&nbsp; <?php echo $isEn ? 'Email' : 'อีเมล'; ?>: <?php echo htmlspecialchars($company['email']); ?>
                <?php if ($company['tax_id']): ?>
                &nbsp;|&nbsp; <?php echo $isEn ? 'Tax ID' : 'เลขประจำตัวผู้เสียภาษี'; ?>: <?php echo htmlspecialchars($company['tax_id']); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="letterhead-accent"></div>

    <!-- Doc ref strip -->
    <div class="doc-ref">
        <span class="ref-no"><?php echo $isEn ? 'Ref. No.' : 'ที่'; ?> <?php echo htmlspecialchars($docNumber); ?></span>
        <span class="ref-date"><?php echo $isEn ? fmtEnDate($docDate) : 'วันที่ ' . fmtThaiDate($docDate); ?></span>
    </div>

    <?php
    $code    = $tplCode;
    $hireStr = $V['hireDate'] ? ($isEn ? fmtEnDate($V['hireDate']) : fmtThaiDate($V['hireDate'])) : '-';
    $tenure  = $V['hireDate'] ? ($isEn ? yearsMonthsEn($V['hireDate'], $docDate) : yearsMonths($V['hireDate'], $docDate)) : '-';

    // Normalize: if template is _EN but we're rendering TH (BOTH mode), render TH variant; vice-versa
    $effectiveLang = $isEn ? 'EN' : 'TH';
    $baseCode = $code;
    if ($code === 'CERT_WORK_TH'   || $code === 'CERT_WORK_EN')   $baseCode = 'CERT_WORK';
    if ($code === 'CERT_SALARY_TH' || $code === 'CERT_SALARY_EN') $baseCode = 'CERT_SALARY';
    ?>

    <?php if ($baseCode === 'CERT_WORK' && !$isEn): ?>
        <div class="doc-title">
            <div class="main">หนังสือรับรองการทำงาน</div>
            <div class="sub">CERTIFICATE OF EMPLOYMENT</div>
        </div>
        <div class="body">
            <p class="lead"><?php echo htmlspecialchars($company['name_th']); ?> ขอรับรองว่าบุคคลซึ่งมีรายนามและข้อมูลปรากฏดังต่อไปนี้ เป็นพนักงานของบริษัทฯ จริง</p>
            <div class="info-box">
                <div class="info-grid">
                    <div class="k">ชื่อ-นามสกุล</div><div class="v"><?php echo htmlspecialchars($V['fullName_th']); ?></div>
                    <div class="k">เลขประจำตัวประชาชน</div><div class="v"><?php echo htmlspecialchars($V['nationalId']); ?></div>
                    <div class="k">รหัสพนักงาน</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div>
                    <div class="k">ตำแหน่ง</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div>
                    <div class="k">แผนก / ฝ่าย</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div>
                    <div class="k">วันที่เริ่มปฏิบัติงาน</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div>
                    <div class="k">อายุการทำงาน</div><div class="v"><?php echo htmlspecialchars($tenure); ?> (นับถึงวันที่ออกหนังสือ)</div>
                </div>
            </div>
            <p>บุคคลดังกล่าวข้างต้นยังคงปฏิบัติงานเป็นพนักงานของบริษัทฯ อยู่ในปัจจุบัน บริษัทฯ จึงออกหนังสือรับรองฉบับนี้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?></p>
            <p>ขอรับรองว่าข้อความข้างต้นเป็นความจริงทุกประการ จึงออกหนังสือฉบับนี้ไว้เป็นหลักฐาน</p>
        </div>

    <?php elseif ($baseCode === 'CERT_WORK' && $isEn): ?>
        <div class="doc-title">
            <div class="main">CERTIFICATE OF EMPLOYMENT</div>
            <div class="sub">หนังสือรับรองการทำงาน</div>
        </div>
        <div class="body">
            <p class="no-indent lead"><strong>TO WHOM IT MAY CONCERN,</strong></p>
            <p>This is to certify that the individual whose details appear below is currently an employee of <strong><?php echo htmlspecialchars($company['name_en']); ?></strong></p>
            <div class="info-box">
                <div class="info-grid">
                    <div class="k">Full Name</div><div class="v"><?php echo htmlspecialchars($V['fullName_en']); ?></div>
                    <div class="k">Employee ID</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div>
                    <div class="k">Position</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div>
                    <div class="k">Department</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div>
                    <div class="k">Date of Employment</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div>
                    <div class="k">Length of Service</div><div class="v"><?php echo htmlspecialchars($tenure); ?> (as of the issue date)</div>
                </div>
            </div>
            <p>The above-mentioned person is currently in active employment with the Company. This certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>.</p>
            <p>This is to certify that the information stated herein is true and correct.</p>
        </div>

    <?php elseif (($baseCode === 'CERT_SALARY' || $code === 'CERT_SALARY_BANK') && !$isEn): ?>
        <div class="doc-title">
            <div class="main">หนังสือรับรองเงินเดือน<?php echo $code === 'CERT_SALARY_BANK' ? ' (ฉบับธนาคาร)' : ''; ?></div>
            <div class="sub">CERTIFICATE OF SALARY<?php echo $code === 'CERT_SALARY_BANK' ? ' (FOR BANK)' : ''; ?></div>
        </div>
        <div class="body">
            <p class="lead"><?php echo htmlspecialchars($company['name_th']); ?> ขอรับรองว่าบุคคลซึ่งมีรายนามและข้อมูลปรากฏดังต่อไปนี้ เป็นพนักงานของบริษัทฯ และได้รับเงินเดือนตามอัตราที่ระบุจริง</p>
            <div class="info-box">
                <div class="info-grid">
                    <div class="k">ชื่อ-นามสกุล</div><div class="v"><?php echo htmlspecialchars($V['fullName_th']); ?></div>
                    <div class="k">เลขประจำตัวประชาชน</div><div class="v"><?php echo htmlspecialchars($V['nationalId']); ?></div>
                    <div class="k">รหัสพนักงาน</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div>
                    <div class="k">ตำแหน่ง</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div>
                    <div class="k">แผนก / ฝ่าย</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div>
                    <div class="k">วันที่เริ่มปฏิบัติงาน</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div>
                    <div class="k">อายุการทำงาน</div><div class="v"><?php echo htmlspecialchars($tenure); ?></div>
                    <div class="k">อัตราเงินเดือน</div><div class="v" style="color:var(--brand); font-size:15pt;">
                        <?php echo number_format($V['salary'], 2); ?> บาท
                        <span style="font-weight:500; font-size:12.5pt; color:var(--muted);">(<?php echo thaiBaht($V['salary']); ?>)</span>
                    </div>
                    <div class="k">ประเภทการจ้าง</div><div class="v">พนักงานประจำ</div>
                </div>
            </div>
            <?php if ($code === 'CERT_SALARY_BANK'): ?>
            <div class="remark">
                <strong>หมายเหตุ:</strong> หนังสือรับรองฉบับนี้ออกให้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?>
                โดยเฉพาะ ไม่สามารถนำไปใช้เพื่อวัตถุประสงค์อื่น
                <?php if ($req['recipient']): ?><br>เรียน: <?php echo htmlspecialchars($req['recipient']); ?><?php endif; ?>
            </div>
            <?php else: ?>
            <p>บริษัทฯ ขอออกหนังสือรับรองฉบับนี้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?></p>
            <?php endif; ?>
            <p>ขอรับรองว่าข้อความข้างต้นเป็นความจริงทุกประการ จึงออกหนังสือฉบับนี้ไว้เป็นหลักฐาน</p>
        </div>

    <?php elseif ($baseCode === 'CERT_SALARY' && $isEn): ?>
        <div class="doc-title">
            <div class="main">CERTIFICATE OF SALARY</div>
            <div class="sub">หนังสือรับรองเงินเดือน</div>
        </div>
        <div class="body">
            <p class="no-indent lead"><strong>TO WHOM IT MAY CONCERN,</strong></p>
            <p>This is to certify that the following individual is currently employed by <strong><?php echo htmlspecialchars($company['name_en']); ?></strong> and receives the monthly salary specified below:</p>
            <div class="info-box">
                <div class="info-grid">
                    <div class="k">Full Name</div><div class="v"><?php echo htmlspecialchars($V['fullName_en']); ?></div>
                    <div class="k">Employee ID</div><div class="v"><?php echo htmlspecialchars($V['empCode']); ?></div>
                    <div class="k">Position</div><div class="v"><?php echo htmlspecialchars($V['position']); ?></div>
                    <div class="k">Department</div><div class="v"><?php echo htmlspecialchars($V['department']); ?></div>
                    <div class="k">Date of Employment</div><div class="v"><?php echo htmlspecialchars($hireStr); ?></div>
                    <div class="k">Length of Service</div><div class="v"><?php echo htmlspecialchars($tenure); ?></div>
                    <div class="k">Monthly Salary</div><div class="v" style="color:var(--brand); font-size:15pt;">THB <?php echo number_format($V['salary'], 2); ?></div>
                    <div class="k">Employment Type</div><div class="v">Permanent / Full-time</div>
                </div>
            </div>
            <p>This certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>.</p>
            <p>This is to certify that the information stated herein is true and correct.</p>
        </div>

    <?php elseif ($code === 'TAX_50TAWI'): ?>
        <?php
            $yearTH = (int)date('Y', strtotime($docDate)) + 543;
            $yearPrev = $yearTH - 1;
            $annualSalary = $V['salary'] * 12;
            $ssAnnual = min($annualSalary * 0.05, 9000); // 5% capped at 9,000/year
            $wht = max(0, min($annualSalary * 0.05, $annualSalary - 150000));
        ?>
        <div class="doc-title">
            <div class="main">หนังสือรับรองการหักภาษี ณ ที่จ่าย</div>
            <div class="sub">(ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร) &nbsp;•&nbsp; WITHHOLDING TAX CERTIFICATE</div>
        </div>

        <div class="tax-form">
            <div class="grid">
                <div class="party">
                    <h4>ผู้มีหน้าที่หักภาษี ณ ที่จ่าย <span style="font-weight:500; font-size:10pt;">(Payer)</span></h4>
                    <div><strong><?php echo htmlspecialchars($company['name_th']); ?></strong></div>
                    <div style="color:var(--muted); font-size:11pt;"><?php echo htmlspecialchars($company['name_en']); ?></div>
                    <div style="margin-top:4pt;"><?php echo htmlspecialchars($company['address']); ?></div>
                    <div>เลขประจำตัวผู้เสียภาษี: <strong><?php echo htmlspecialchars($company['tax_id']); ?></strong></div>
                </div>
                <div class="party">
                    <h4>ผู้ถูกหักภาษี ณ ที่จ่าย <span style="font-weight:500; font-size:10pt;">(Payee)</span></h4>
                    <div><strong><?php echo htmlspecialchars($V['fullName_th']); ?></strong></div>
                    <div style="color:var(--muted); font-size:11pt;"><?php echo htmlspecialchars($V['fullName_en']); ?></div>
                    <div style="margin-top:4pt;">เลขประจำตัวประชาชน: <strong><?php echo htmlspecialchars($V['nationalId']); ?></strong></div>
                    <div>รหัสพนักงาน: <strong><?php echo htmlspecialchars($V['empCode']); ?></strong></div>
                    <div>ปีภาษี: <strong><?php echo $yearPrev; ?> (<?php echo $yearPrev - 543; ?>)</strong></div>
                </div>
            </div>

            <table class="tax-table">
                <thead>
                    <tr>
                        <th style="width:8%;">ลำดับ</th>
                        <th style="text-align:left;">ประเภทเงินได้พึงประเมินที่จ่าย</th>
                        <th style="width:20%;">จำนวนเงินที่จ่าย</th>
                        <th style="width:20%;">ภาษีที่หัก / นำส่ง</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="text-align:center;">1</td>
                        <td>เงินเดือน ค่าจ้าง เบี้ยเลี้ยง โบนัส ฯลฯ ตามมาตรา 40 (1)<br>
                            <span style="font-size:10pt; color:var(--muted);">Salary, wages, bonus etc. per Section 40(1)</span></td>
                        <td class="num"><?php echo number_format($annualSalary, 2); ?></td>
                        <td class="num"><?php echo number_format($wht, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="text-align:center;">2</td>
                        <td>ค่าธรรมเนียม ค่านายหน้า ฯลฯ ตามมาตรา 40 (2)</td>
                        <td class="num">0.00</td>
                        <td class="num">0.00</td>
                    </tr>
                    <tr class="total">
                        <td colspan="2" style="text-align:right;">รวม (Total) หน่วย: บาท</td>
                        <td class="num"><?php echo number_format($annualSalary, 2); ?></td>
                        <td class="num"><?php echo number_format($wht, 2); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="info-box" style="border-left-color: var(--brand-2); margin-top: 14pt;">
                <div class="info-grid">
                    <div class="k">ประกันสังคมที่หักไว้</div><div class="v"><?php echo number_format($ssAnnual, 2); ?> บาท</div>
                    <div class="k">กองทุนสำรองเลี้ยงชีพ</div><div class="v">0.00 บาท</div>
                    <div class="k">เลขที่หนังสือรับรอง</div><div class="v"><?php echo htmlspecialchars($docNumber); ?></div>
                    <div class="k">วันที่ออกหนังสือ</div><div class="v"><?php echo fmtThaiDate($docDate); ?></div>
                </div>
            </div>

            <div class="tax-note">
                <strong>หมายเหตุ:</strong> เอกสารฉบับนี้เป็นหนังสือรับรองการหักภาษี ณ ที่จ่าย ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร
                ออกโดยระบบบริหารทรัพยากรบุคคลของบริษัทฯ ข้อมูลเงินได้ประเมินจากอัตราเงินเดือนที่บันทึกในระบบ
                หากต้องการเอกสารฉบับทางการ (กระดาษแบบ พ.ง.ด. 1ก) กรุณาติดต่อฝ่ายบัญชี
            </div>
        </div>

    <?php else: ?>
        <div class="body"><p class="no-indent">[<?php echo htmlspecialchars($req['tpl_name']); ?>] ยังไม่ได้กำหนดเทมเพลต</p></div>
    <?php endif; ?>

    <!-- Signature block -->
    <div class="signature">
        <div class="block">
            <div class="prefix"><?php echo $isEn ? 'Yours sincerely,' : 'ขอแสดงความนับถือ'; ?></div>
            <div class="seal"><?php echo $isEn ? '(Company Seal)' : '(ตราประทับบริษัท)'; ?></div>
            <div class="name">(<?php echo htmlspecialchars($isEn ? $company['signer_en'] : $company['signer_th']); ?>)</div>
            <div class="position"><?php echo htmlspecialchars($isEn ? $company['signer_position_en'] : $company['signer_position_th']); ?></div>
            <div class="issue-date">
                <?php echo $isEn ? 'Issued on ' . fmtEnDate($docDate) : 'ออก ณ วันที่ ' . fmtThaiDate($docDate); ?>
            </div>
        </div>
    </div>

    <!-- Verification footer -->
    <div class="verify-footer">
        <div class="left">
            <strong><?php echo $isEn ? 'Document Verification' : 'การยืนยันความถูกต้องของเอกสาร'; ?></strong><br>
            <?php echo $isEn ? 'Doc No.' : 'เลขที่'; ?>: <?php echo htmlspecialchars($docNumber); ?>
            <?php if ($verifyCode): ?>&nbsp;|&nbsp; <?php echo $isEn ? 'Code' : 'รหัสยืนยัน'; ?>: <?php echo htmlspecialchars($verifyCode); ?><?php endif; ?><br>
            <?php echo $isEn ? 'Verify online at' : 'ตรวจสอบออนไลน์ได้ที่'; ?>:
            <span style="color:var(--brand);">hr.tp-asset.com/verify</span>
            &nbsp;<span style="color:#94a3b8;">•</span>&nbsp;
            <?php echo $isEn ? 'Page' : 'หน้า'; ?> <?php echo $pageNo; ?>/<?php echo $pageTotal; ?>
        </div>
        <div class="qr">
            <img src="<?php echo htmlspecialchars($qrImg); ?>" alt="QR">
            <div class="cap"><?php echo $isEn ? 'Scan to verify' : 'สแกนเพื่อตรวจสอบ'; ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$preview): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
<?php endif; ?>
</body>
</html>
