<?php
/**
 * Certificate Print / Generator
 * พิมพ์หนังสือรับรอง - Browser Print-to-PDF (รูปแบบเดียวกับ CRM payroll print)
 *
 * Access:
 *   - HR: print any request
 *   - Owner: own request only when status in PROCESSING / READY / DELIVERED
 *
 * Params: ?id= required, ?lang=TH|EN|BOTH optional, ?preview=1 to skip auto-print
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
           u.id_card AS national_id, u.position, u.department, u.salary, u.hire_date, u.title,
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
// Company settings — from CRM system_settings (real data, same as payslip)
// ------------------------------------------------------------------
$crm = [];
try {
    $rs = $pdo->query("SELECT setting_key, setting_value FROM system_settings
        WHERE setting_key IN ('company_name','company_name_en','company_address','company_phone','company_email','company_tax_id','company_website','company_logo')");
    foreach ($rs as $row) { $crm[$row['setting_key']] = trim((string)$row['setting_value']); }
} catch (Exception $e) { /* ignore */ }

$company = [
    'name_th' => $crm['company_name']    ?? 'บริษัท',
    'name_en' => $crm['company_name_en'] ?? '',
    'address' => $crm['company_address'] ?? '',
    'phone'   => $crm['company_phone']   ?? '',
    'email'   => $crm['company_email']   ?? '',
    'website' => $crm['company_website'] ?? '',
    'tax_id'  => $crm['company_tax_id']  ?? '',
];

// Signers — real executives from users table (ประธานบริษัท + ประธานเจ้าหน้าที่บริหาร)
$sigStmt = $pdo->query("
    SELECT title, first_name_th, last_name_th, first_name_en, last_name_en, position
    FROM users
    WHERE is_active = 1 AND (position LIKE 'ประธาน%' OR position LIKE '%กรรมการผู้จัดการ%' OR position LIKE '%CEO%')
    ORDER BY
      CASE
        WHEN position LIKE 'ประธานบริษัท%' THEN 1
        WHEN position LIKE 'ประธานเจ้าหน้าที่บริหาร%' THEN 2
        WHEN position LIKE 'ประธานกรรมการ%' THEN 3
        ELSE 4
      END, id
    LIMIT 2
");
$signers = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
$signer1 = $signers[0] ?? ['title'=>'','first_name_th'=>'','last_name_th'=>'','first_name_en'=>'','last_name_en'=>'','position'=>'ประธานบริษัท'];
$signer2 = $signers[1] ?? ['title'=>'','first_name_th'=>'','last_name_th'=>'','first_name_en'=>'','last_name_en'=>'','position'=>'ประธานเจ้าหน้าที่บริหาร'];
$posEnMap = [
    'ประธานบริษัท' => 'President',
    'ประธานเจ้าหน้าที่บริหาร' => 'Chief Executive Officer',
    'ประธานกรรมการบริษัท' => 'Chairman of the Board',
    'ประธานกรรมการ' => 'Chairman',
    'กรรมการผู้จัดการ' => 'Managing Director',
];
$signer1['position_en'] = $posEnMap[$signer1['position']] ?? 'Authorized Signatory';
$signer2['position_en'] = $posEnMap[$signer2['position']] ?? 'Authorized Signatory';

// ------------------------------------------------------------------
// Issue / reuse document number + verification code
// ------------------------------------------------------------------
$docNumber = $req['document_number'];
$verifyCode = $req['qr_verification_code'];
if (!$docNumber && $isHR) {
    $year = (int)date('Y') + 543;
    $seqStmt = $pdo->prepare("SELECT COUNT(*)+1 FROM hr_document_requests WHERE document_number LIKE ?");
    $seqStmt->execute(["HR-{$year}-%"]);
    $seq = (int)$seqStmt->fetchColumn();
    $docNumber  = sprintf('HR-%d-%05d', $year, $seq);
    $verifyCode = strtoupper(bin2hex(random_bytes(6)));
    $upd = $pdo->prepare("UPDATE hr_document_requests
        SET document_number=?, document_date=CURDATE(), qr_verification_code=?,
            status=IF(status='PENDING','PROCESSING',status),
            processed_by=COALESCE(processed_by,?), processed_at=COALESCE(processed_at,NOW()),
            updated_at=NOW()
        WHERE id=?");
    $upd->execute([$docNumber, $verifyCode, $user['id'], $reqId]);
    $req['document_number'] = $docNumber;
    $req['document_date']   = date('Y-m-d');
}
$docDate = $req['document_date'] ?: date('Y-m-d');

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------
function fmtThaiDate(string $d): string {
    $m = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $t = strtotime($d); if (!$t) return $d;
    return (int)date('j',$t) . ' ' . $m[(int)date('n',$t)] . ' พ.ศ. ' . ((int)date('Y',$t) + 543);
}
function fmtEnDate(string $d): string {
    $t = strtotime($d); return $t ? date('j F Y', $t) : $d;
}
function thaiBaht(float $n): string {
    static $txt = ['ศูนย์','หนึ่ง','สอง','สาม','สี่','ห้า','หก','เจ็ด','แปด','เก้า'];
    static $pos = ['','สิบ','ร้อย','พัน','หมื่น','แสน','ล้าน'];
    $n = round($n, 2);
    [$baht, $satang] = explode('.', number_format($n, 2, '.', ''));
    $c = function($num) use ($txt,$pos) {
        if ($num == 0) return 'ศูนย์';
        $o=''; $s=(string)$num; $l=strlen($s);
        for ($i=0;$i<$l;$i++) {
            $d=(int)$s[$i]; $p=$l-$i-1; if ($d==0) continue;
            if ($p==0 && $d==1 && $l>1) $o.='เอ็ด';
            elseif ($p==1 && $d==2) $o.='ยี่'.$pos[1];
            elseif ($p==1 && $d==1) $o.=$pos[1];
            else $o.=$txt[$d].$pos[$p];
        }
        return $o;
    };
    return $c((int)$baht).'บาท'.(((int)$satang===0)?'ถ้วน':$c((int)$satang).'สตางค์');
}
function yearsMonths(string $hire, string $asOf): string {
    $a=new DateTime($hire); $b=new DateTime($asOf);
    if ($a>$b) return '-';
    $d=$a->diff($b); $p=[];
    if ($d->y>0) $p[]=$d->y.' ปี';
    if ($d->m>0) $p[]=$d->m.' เดือน';
    if (!$p) $p[]='ไม่ถึง 1 เดือน';
    return implode(' ',$p);
}
function yearsMonthsEn(string $hire, string $asOf): string {
    $a=new DateTime($hire); $b=new DateTime($asOf);
    if ($a>$b) return '-';
    $d=$a->diff($b); $p=[];
    if ($d->y>0) $p[]=$d->y.' year'.($d->y>1?'s':'');
    if ($d->m>0) $p[]=$d->m.' month'.($d->m>1?'s':'');
    if (!$p) $p[]='less than 1 month';
    return implode(' ',$p);
}

// ------------------------------------------------------------------
// Decide render languages
// ------------------------------------------------------------------
$reqLang = $langOv ?: ($req['language'] ?: 'TH');
$tplCode = $req['tpl_code'];
$renderLangs = [];
if     ($reqLang === 'BOTH')                                      $renderLangs = ['TH','EN'];
elseif ($reqLang === 'EN' || str_ends_with($tplCode, '_EN'))      $renderLangs = ['EN'];
else                                                              $renderLangs = ['TH'];
if ($tplCode === 'CERT_SALARY_BANK' || $tplCode === 'TAX_50TAWI') $renderLangs = ['TH'];

$V = [
    'fullName_th' => trim(($req['title'] ?? '') . ($req['first_name_th'] ?? '') . ' ' . ($req['last_name_th'] ?? '')),
    'fullName_en' => trim(($req['first_name_en'] ?? '') . ' ' . ($req['last_name_en'] ?? '')) ?: '-',
    'empCode'     => $req['employee_code'] ?: '-',
    'nationalId'  => $req['national_id'] ?: '-',
    'position'    => $req['position'] ?: '-',
    'department'  => $req['department'] ?: '-',
    'salary'      => (float)($req['salary'] ?? 0),
    'hireDate'    => $req['hire_date'],
    'purpose'     => trim($req['purpose_detail'] ?? '') ?: (match($req['purpose'] ?? '') {
        'VISA'  => 'ประกอบการขอวีซ่า / เดินทางไปต่างประเทศ',
        'BANK'  => 'ประกอบการติดต่อธนาคาร',
        'STUDY' => 'ประกอบการสมัครเรียนต่อ',
        'JOB'   => 'ประกอบการสมัครงาน',
        'COURT' => 'ประกอบการติดต่อราชการ',
        default => 'ประกอบการใช้ตามวัตถุประสงค์ของผู้ร้องขอ',
    }),
    'purposeEn'   => match($req['purpose'] ?? '') {
        'VISA'  => 'for visa application / overseas travel',
        'BANK'  => 'for banking purposes',
        'STUDY' => 'for further education',
        'JOB'   => 'for job application',
        'COURT' => 'for government purposes',
        default => 'for personal use as required',
    },
];

$page_title = 'หนังสือรับรอง - ' . $V['fullName_th'];

$verifyUrl = 'https://hr.tp-asset.com/verify_document.php?code=' . urlencode($verifyCode ?: $docNumber);
$qrImg     = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=0&ecc=M&data=' . urlencode($verifyUrl);

// Brand assets (same exact URLs as CRM payslip)
$LOGO_BRAND = 'https://crm.tp-asset.com/asset/logo/LOGO%20TP-ASSET%20-%206.png';
$WATERMARK  = 'https://crm.tp-asset.com/asset/logo/LOGO%20TP-ASSET%20-%205.png';

function signerName(array $s, bool $isEn): string {
    if ($isEn) {
        $n = trim(($s['first_name_en'] ?? '') . ' ' . ($s['last_name_en'] ?? ''));
        return $n !== '' ? $n : '—';
    }
    $n = trim(($s['title'] ?? '') . ($s['first_name_th'] ?? '') . ' ' . ($s['last_name_th'] ?? ''));
    return $n !== '' ? $n : '—';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($page_title); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ---------- Reset / Base (cloned from CRM payroll_print design system) ---------- */
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { overflow: visible; }
body {
    font-family: 'Sarabun', sans-serif;
    background: #e2e8f0;
    color: #1a1a1a;
    padding: 20px 0;
    min-height: 100vh;
}

/* ---------- A4 Page ---------- */
.page {
    max-width: 210mm;
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto 20px;
    background: #fff;
    padding: 22px 28px 28px;
    position: relative;
    box-shadow: 0 4px 24px rgba(15,23,42,.12);
    page-break-after: always;
}
.page:last-child { page-break-after: auto; margin-bottom: 0; }

/* ---------- Toolbar (no-print) ---------- */
.toolbar {
    max-width: 210mm;
    margin: 0 auto 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 4px;
    font-family: system-ui, -apple-system, sans-serif;
}
.toolbar-left { display: flex; gap: 6px; }
.toolbar a, .toolbar button {
    padding: 9px 16px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}
.toolbar .btn-print {
    background: #1a365d; color: #fff; border-color: #1a365d; font-weight: 600;
}
.toolbar .btn-print:hover { background: #2d4a7c; }

/* ---------- Watermark ---------- */
.watermark {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none;
    z-index: 0;
}
.watermark img {
    width: 50%;
    height: auto;
    opacity: 0.04;
    filter: grayscale(100%) brightness(1.2);
}
.page > *:not(.watermark) { position: relative; z-index: 1; }

/* ---------- Header (logo left, company right) — identical to payslip ---------- */
.doc-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding-bottom: 18px;
    border-bottom: 2px solid #1a365d;
    margin-bottom: 22px;
}
.doc-header-logo { flex-shrink: 0; }
.doc-header-logo img { height: 72px; width: auto; display: block; }
.doc-header-right { text-align: right; max-width: 62%; }
.doc-header-right .company-name {
    font-size: 15px;
    font-weight: 700;
    color: #1a365d;
    line-height: 1.35;
    letter-spacing: 0.02em;
}
.doc-header-right .company-name-en {
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    margin-top: 2px;
    letter-spacing: 0.02em;
}
.doc-header-right .company-addr {
    font-size: 11px;
    color: #475569;
    margin-top: 4px;
    line-height: 1.45;
}
.doc-header-right .company-tax {
    font-size: 11px;
    color: #475569;
    margin-top: 3px;
    font-weight: 500;
}
.doc-header-right .company-tax b { color: #1a365d; font-weight: 600; font-variant-numeric: tabular-nums; }

/* ---------- Doc reference line ---------- */
.doc-ref {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin: 4px 0 18px;
    font-size: 13px;
    color: #334155;
}
.doc-ref .no { font-weight: 600; color: #1a365d; }
.doc-ref .date { font-weight: 500; }

/* ---------- Title ---------- */
.doc-title {
    text-align: center;
    font-size: 20px;
    font-weight: 700;
    color: #1a365d;
    margin: 6px 0 20px;
    line-height: 1.4;
}
.doc-title .sub {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    margin-top: 2px;
    letter-spacing: 0.04em;
}

/* ---------- Body ---------- */
.body { font-size: 14px; line-height: 1.8; color: #1a1a1a; }
.body p { margin: 0 0 10px; text-indent: 2.5em; text-align: justify; }
.body p.no-indent { text-indent: 0; }

/* ---------- Info table (bordered — same as payslip) ---------- */
.info-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #e2e8f0;
    margin: 10px 0 14px;
}
.info-table th, .info-table td {
    padding: 9px 12px;
    font-size: 13px;
    border: 1px solid #e2e8f0;
    vertical-align: middle;
}
.info-table th {
    width: 32%;
    background: #f8fafc;
    color: #475569;
    font-weight: 500;
    text-align: left;
}
.info-table td { color: #0f172a; font-weight: 600; }
.info-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.info-table .highlight {
    color: #1a365d;
    font-size: 14px;
    font-weight: 700;
}
.info-table .baht-words {
    display: block;
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
    margin-top: 1px;
}

/* ---------- Salary Emphasis ---------- */
.salary-box {
    margin-top: 10px;
    padding: 12px 16px;
    border: 2px solid #1a365d;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.salary-box .label { font-size: 14px; color: #1a365d; font-weight: 600; }
.salary-box .value {
    font-size: 18px; font-weight: 700; color: #0f172a;
    font-variant-numeric: tabular-nums;
}
.salary-box .value span { font-size: 12px; font-weight: 500; color: #64748b; }

/* ---------- Remark banner ---------- */
.remark {
    margin: 12px 0 6px;
    padding: 10px 14px;
    background: #eff6ff;
    border-left: 3px solid #1a365d;
    font-size: 13px;
    color: #1e3a8a;
    line-height: 1.6;
}

/* ---------- Signatures: TWO signers side-by-side ---------- */
.signatures {
    margin-top: 30px;
    display: flex;
    justify-content: space-around;
    gap: 24px;
}
.sig-block {
    flex: 1;
    text-align: center;
    max-width: 48%;
}
.sig-block .sig-prefix {
    font-size: 13px;
    color: #475569;
    margin-bottom: 60px;
}
.sig-block .sig-line {
    border-top: 1px dotted #475569;
    margin: 0 10px 6px;
}
.sig-block .sig-name {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
}
.sig-block .sig-position {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}
.sig-block .sig-position-en {
    font-size: 11px;
    color: #94a3b8;
    font-style: italic;
}

/* Company seal (center between signatures) */
.seal-area {
    position: relative;
    text-align: center;
    margin-top: 22px;
}
.seal-area .seal-circle {
    display: inline-block;
    width: 80px; height: 80px;
    border: 1.5px dashed #94a3b8;
    border-radius: 50%;
    line-height: 76px;
    font-size: 10px;
    color: #94a3b8;
    font-weight: 500;
}
.seal-area .seal-note {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 4px;
    font-style: italic;
}

/* ---------- Footer: verification + QR ---------- */
.verify-footer {
    margin-top: 24px;
    padding: 10px 14px;
    border-top: 2px solid #1a365d;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #f8fafc;
}
.verify-footer .vf-left { flex: 1; font-size: 10.5px; color: #475569; line-height: 1.5; }
.verify-footer .vf-left h4 { font-size: 11.5px; font-weight: 700; color: #1a365d; margin-bottom: 4px; }
.verify-footer .vf-left .mono { font-family: 'Courier New', monospace; color: #1a365d; font-weight: 600; letter-spacing: 0.05em; }
.verify-footer .qr-box {
    flex-shrink: 0; text-align: center;
    padding: 4px; background: #fff; border: 1px solid #cbd5e1;
}
.verify-footer .qr-box img { display: block; width: 70px; height: 70px; }
.verify-footer .qr-box .cap { font-size: 9px; color: #64748b; margin-top: 2px; }

.footer-note {
    margin-top: 8px;
    padding-top: 8px;
    text-align: center;
    font-size: 10px;
    color: #94a3b8;
    line-height: 1.5;
}

/* ---------- Tax form (50 ทวิ) ---------- */
.tax-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 10px 0 14px; }
.tax-parties .party { border: 1px solid #e2e8f0; background: #f8fafc; padding: 10px 14px; font-size: 12px; line-height: 1.55; }
.tax-parties .party h5 { font-size: 11px; font-weight: 700; color: #1a365d; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #1a365d; }
.tax-parties .party b { color: #0f172a; font-weight: 600; }
.tax-table {
    width: 100%; border-collapse: collapse; font-size: 12.5px;
    margin: 10px 0;
}
.tax-table th {
    background: #1a365d; color: #fff;
    padding: 9px 10px; border: 1px solid #1a365d;
    font-size: 12px; font-weight: 600;
}
.tax-table td {
    padding: 9px 10px; border: 1px solid #e2e8f0;
    color: #1e293b;
}
.tax-table td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.tax-table tr.total td { background: #f1f5f9; font-weight: 700; color: #0f172a; }
.tax-note {
    margin-top: 10px; padding: 9px 12px;
    background: #fef9e7; border-left: 3px solid #c8a951;
    font-size: 11px; color: #78350f; line-height: 1.55;
}

/* ---------- Print ---------- */
@media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none !important; }
    .page {
        width: 210mm; min-height: 297mm; margin: 0;
        box-shadow: none; padding: 18px 24px 24px;
        page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }
    .watermark img { opacity: 0.035; width: 48%; }
    @page { size: A4; margin: 0; }
}
</style>
</head>
<body>

<div class="toolbar">
    <div class="toolbar-left">
        <?php if (count($renderLangs) === 2 || $req['language'] === 'BOTH'): ?>
            <a href="?id=<?= $reqId ?>&lang=TH&preview=1">ไทย</a>
            <a href="?id=<?= $reqId ?>&lang=EN&preview=1">English</a>
            <a href="?id=<?= $reqId ?>&lang=BOTH&preview=1">ทั้งสองภาษา</a>
        <?php endif; ?>
        <a href="javascript:history.back()">← กลับ</a>
    </div>
    <button onclick="window.print()" class="btn-print">พิมพ์ / บันทึกเป็น PDF</button>
</div>

<?php foreach ($renderLangs as $idx => $lang):
    $isEn = ($lang === 'EN');
    $pageNo = $idx + 1; $pageTotal = count($renderLangs);
    $code = $tplCode;
    $hireStr = $V['hireDate'] ? ($isEn ? fmtEnDate($V['hireDate']) : fmtThaiDate($V['hireDate'])) : '-';
    $tenure  = $V['hireDate'] ? ($isEn ? yearsMonthsEn($V['hireDate'], $docDate) : yearsMonths($V['hireDate'], $docDate)) : '-';
    // Normalize base code for bilingual rendering
    $baseCode = $code;
    if (in_array($code, ['CERT_WORK_TH','CERT_WORK_EN']))     $baseCode = 'CERT_WORK';
    if (in_array($code, ['CERT_SALARY_TH','CERT_SALARY_EN'])) $baseCode = 'CERT_SALARY';
?>
<div class="page">
    <!-- Watermark (same asset as payslip) -->
    <div class="watermark"><img src="<?php echo $WATERMARK; ?>" alt=""></div>

    <!-- Header: logo left, company right -->
    <header class="doc-header">
        <div class="doc-header-logo">
            <img src="<?php echo $LOGO_BRAND; ?>" alt="<?php echo htmlspecialchars($company['name_th']); ?>"
                 onerror="this.style.display='none'">
        </div>
        <div class="doc-header-right">
            <div class="company-name"><?php echo htmlspecialchars($company['name_th']); ?></div>
            <?php if ($company['name_en']): ?>
                <div class="company-name-en"><?php echo htmlspecialchars($company['name_en']); ?></div>
            <?php endif; ?>
            <div class="company-addr"><?php echo htmlspecialchars($company['address']); ?></div>
            <div class="company-tax">
                <?php if ($company['phone']): ?><?php echo $isEn?'Tel':'โทร'; ?>: <?php echo htmlspecialchars($company['phone']); ?> &nbsp;|&nbsp; <?php endif; ?>
                <?php if ($company['email']): ?><?php echo $isEn?'Email':'อีเมล'; ?>: <?php echo htmlspecialchars($company['email']); ?><?php endif; ?>
            </div>
            <?php if ($company['tax_id']): ?>
            <div class="company-tax"><?php echo $isEn?'Tax ID':'เลขประจำตัวผู้เสียภาษี'; ?>: <b><?php echo htmlspecialchars($company['tax_id']); ?></b></div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Doc reference -->
    <div class="doc-ref">
        <span class="no"><?php echo $isEn?'Ref. No.':'ที่'; ?> <?php echo htmlspecialchars($docNumber); ?></span>
        <span class="date"><?php echo $isEn ? fmtEnDate($docDate) : 'วันที่ ' . fmtThaiDate($docDate); ?></span>
    </div>

    <?php if ($baseCode === 'CERT_WORK' && !$isEn): ?>
        <h1 class="doc-title">
            หนังสือรับรองการทำงาน
            <span class="sub">CERTIFICATE OF EMPLOYMENT</span>
        </h1>
        <div class="body">
            <p><?php echo htmlspecialchars($company['name_th']); ?>
                <?php echo $company['name_en']?'('.htmlspecialchars($company['name_en']).')':''; ?>
                ขอรับรองว่าบุคคลซึ่งมีรายนามและข้อมูลปรากฏด้านล่างนี้ เป็นพนักงานของบริษัทฯ จริง</p>
            <table class="info-table">
                <tr><th>ชื่อ-นามสกุล</th><td><?php echo htmlspecialchars($V['fullName_th']); ?></td></tr>
                <tr><th>เลขประจำตัวประชาชน</th><td class="mono"><?php echo htmlspecialchars($V['nationalId']); ?></td></tr>
                <tr><th>รหัสพนักงาน</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>ตำแหน่ง / Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>แผนก / Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>วันที่เริ่มปฏิบัติงาน</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>อายุการทำงาน</th><td><?php echo htmlspecialchars($tenure); ?> <span style="font-weight:500; color:#64748b;">(นับถึงวันที่ออกหนังสือ)</span></td></tr>
            </table>
            <p>บุคคลดังกล่าวยังคงปฏิบัติงานเป็นพนักงานของบริษัทฯ ในปัจจุบัน บริษัทฯ จึงออกหนังสือรับรองฉบับนี้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?></p>
            <p>ขอรับรองว่าข้อความข้างต้นเป็นความจริงทุกประการ</p>
        </div>

    <?php elseif ($baseCode === 'CERT_WORK' && $isEn): ?>
        <h1 class="doc-title">
            CERTIFICATE OF EMPLOYMENT
            <span class="sub">หนังสือรับรองการทำงาน</span>
        </h1>
        <div class="body">
            <p class="no-indent"><strong>TO WHOM IT MAY CONCERN,</strong></p>
            <p>This is to certify that the individual whose details appear below is currently an employee of <strong><?php echo htmlspecialchars($company['name_en'] ?: $company['name_th']); ?></strong>:</p>
            <table class="info-table">
                <tr><th>Full Name</th><td><?php echo htmlspecialchars($V['fullName_en']); ?></td></tr>
                <tr><th>Employee ID</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>Date of Employment</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>Length of Service</th><td><?php echo htmlspecialchars($tenure); ?> <span style="font-weight:500;color:#64748b;">(as of the issue date)</span></td></tr>
            </table>
            <p>The above-mentioned person is currently in active employment with the Company. This certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>.</p>
            <p>This certifies that the information stated herein is true and correct.</p>
        </div>

    <?php elseif (($baseCode === 'CERT_SALARY' || $code === 'CERT_SALARY_BANK') && !$isEn): ?>
        <h1 class="doc-title">
            หนังสือรับรองเงินเดือน<?php echo $code==='CERT_SALARY_BANK'?' (ฉบับสำหรับธนาคาร)':''; ?>
            <span class="sub">CERTIFICATE OF SALARY<?php echo $code==='CERT_SALARY_BANK'?' (FOR BANK)':''; ?></span>
        </h1>
        <div class="body">
            <p><?php echo htmlspecialchars($company['name_th']); ?> ขอรับรองว่าบุคคลซึ่งมีรายนามและข้อมูลปรากฏด้านล่างนี้ เป็นพนักงานของบริษัทฯ และได้รับเงินเดือนตามอัตราที่ระบุจริง</p>
            <table class="info-table">
                <tr><th>ชื่อ-นามสกุล</th><td><?php echo htmlspecialchars($V['fullName_th']); ?></td></tr>
                <tr><th>เลขประจำตัวประชาชน</th><td><?php echo htmlspecialchars($V['nationalId']); ?></td></tr>
                <tr><th>รหัสพนักงาน</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>ตำแหน่ง / Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>แผนก / Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>วันที่เริ่มปฏิบัติงาน</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>ประเภทการจ้าง</th><td>พนักงานประจำ</td></tr>
            </table>
            <div class="salary-box">
                <span class="label">อัตราเงินเดือน / Monthly Salary</span>
                <span class="value"><?php echo number_format($V['salary'], 2); ?> <span>บาท (<?php echo thaiBaht($V['salary']); ?>)</span></span>
            </div>
            <?php if ($code==='CERT_SALARY_BANK' && $req['recipient']): ?>
            <div class="remark"><strong>เรียน:</strong> <?php echo htmlspecialchars($req['recipient']); ?> &nbsp;|&nbsp;
                หนังสือฉบับนี้ออกให้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?> โดยเฉพาะ
            </div>
            <?php else: ?>
            <p style="margin-top:12px;">บริษัทฯ ขอออกหนังสือรับรองฉบับนี้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?></p>
            <?php endif; ?>
            <p>ขอรับรองว่าข้อความข้างต้นเป็นความจริงทุกประการ</p>
        </div>

    <?php elseif ($baseCode === 'CERT_SALARY' && $isEn): ?>
        <h1 class="doc-title">
            CERTIFICATE OF SALARY
            <span class="sub">หนังสือรับรองเงินเดือน</span>
        </h1>
        <div class="body">
            <p class="no-indent"><strong>TO WHOM IT MAY CONCERN,</strong></p>
            <p>This is to certify that the following individual is currently employed by <strong><?php echo htmlspecialchars($company['name_en'] ?: $company['name_th']); ?></strong> and receives the monthly salary specified below:</p>
            <table class="info-table">
                <tr><th>Full Name</th><td><?php echo htmlspecialchars($V['fullName_en']); ?></td></tr>
                <tr><th>Employee ID</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>Date of Employment</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>Employment Type</th><td>Permanent / Full-time</td></tr>
            </table>
            <div class="salary-box">
                <span class="label">Monthly Salary</span>
                <span class="value">THB <?php echo number_format($V['salary'], 2); ?></span>
            </div>
            <p style="margin-top:12px;">This certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>.</p>
            <p>This certifies that the information stated herein is true and correct.</p>
        </div>

    <?php elseif ($code === 'TAX_50TAWI'): ?>
        <?php
            $yearTH = (int)date('Y', strtotime($docDate)) + 543;
            $yearPrev = $yearTH - 1;
            $annualSalary = $V['salary'] * 12;
            $ssAnnual = min($annualSalary * 0.05, 9000);
            $wht = max(0, min($annualSalary * 0.05, $annualSalary - 150000));
        ?>
        <h1 class="doc-title">
            หนังสือรับรองการหักภาษี ณ ที่จ่าย
            <span class="sub">(ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร) &nbsp;·&nbsp; WITHHOLDING TAX CERTIFICATE</span>
        </h1>

        <div class="tax-parties">
            <div class="party">
                <h5>ผู้มีหน้าที่หักภาษี ณ ที่จ่าย (Payer)</h5>
                <b><?php echo htmlspecialchars($company['name_th']); ?></b><br>
                <span style="color:#64748b;"><?php echo htmlspecialchars($company['name_en']); ?></span><br>
                <?php echo htmlspecialchars($company['address']); ?><br>
                เลขประจำตัวผู้เสียภาษี: <b><?php echo htmlspecialchars($company['tax_id']); ?></b>
            </div>
            <div class="party">
                <h5>ผู้ถูกหักภาษี ณ ที่จ่าย (Payee)</h5>
                <b><?php echo htmlspecialchars($V['fullName_th']); ?></b><br>
                <span style="color:#64748b;"><?php echo htmlspecialchars($V['fullName_en']); ?></span><br>
                เลขประจำตัวประชาชน: <b><?php echo htmlspecialchars($V['nationalId']); ?></b><br>
                รหัสพนักงาน: <b><?php echo htmlspecialchars($V['empCode']); ?></b> &nbsp;|&nbsp;
                ปีภาษี: <b><?php echo $yearPrev; ?></b>
            </div>
        </div>

        <table class="tax-table">
            <thead>
                <tr>
                    <th style="width:7%;">ลำดับ</th>
                    <th style="text-align:left;">ประเภทเงินได้พึงประเมินที่จ่าย</th>
                    <th style="width:20%;">จำนวนเงินที่จ่าย (บาท)</th>
                    <th style="width:20%;">ภาษีที่หัก / นำส่ง (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="text-align:center;">1</td>
                    <td>เงินเดือน ค่าจ้าง เบี้ยเลี้ยง โบนัส ฯลฯ ตามมาตรา 40 (1)<br>
                        <span style="font-size:10.5px;color:#64748b;">Salary, wages, bonus under Section 40(1)</span></td>
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
                    <td colspan="2" style="text-align:right;">รวม (Total)</td>
                    <td class="num"><?php echo number_format($annualSalary, 2); ?></td>
                    <td class="num"><?php echo number_format($wht, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <table class="info-table">
            <tr><th style="width:40%;">ประกันสังคมที่หักไว้</th><td class="num"><?php echo number_format($ssAnnual, 2); ?> บาท</td></tr>
            <tr><th>กองทุนสำรองเลี้ยงชีพ</th><td class="num">0.00 บาท</td></tr>
            <tr><th>เลขที่หนังสือรับรอง</th><td><?php echo htmlspecialchars($docNumber); ?></td></tr>
            <tr><th>วันที่ออกหนังสือ</th><td><?php echo fmtThaiDate($docDate); ?></td></tr>
        </table>

        <div class="tax-note">
            <strong>หมายเหตุ:</strong> เอกสารฉบับนี้เป็นหนังสือรับรองการหักภาษี ณ ที่จ่าย ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร
            ออกโดยระบบบริหารทรัพยากรบุคคลของบริษัทฯ ข้อมูลเงินได้ประเมินจากฐานเงินเดือนในระบบ
            หากต้องการเอกสารฉบับทางการของกรมสรรพากร (กระดาษแบบ พ.ง.ด. 1ก) กรุณาติดต่อฝ่ายบัญชี
        </div>

    <?php else: ?>
        <div class="body"><p class="no-indent">[<?php echo htmlspecialchars($req['tpl_name']); ?>] ยังไม่ได้กำหนดเทมเพลตสำหรับเอกสารประเภทนี้</p></div>
    <?php endif; ?>

    <!-- ------ Signatures: TWO signers + company seal ------ -->
    <div class="signatures">
        <div class="sig-block">
            <div class="sig-prefix"><?php echo $isEn?'Authorized by,':'ลงชื่อ'; ?></div>
            <div class="sig-line"></div>
            <div class="sig-name">( <?php echo htmlspecialchars(signerName($signer1, $isEn)); ?> )</div>
            <div class="sig-position"><?php echo htmlspecialchars($signer1['position']); ?></div>
            <?php if ($isEn): ?>
                <div class="sig-position-en"><?php echo htmlspecialchars($signer1['position_en']); ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-block">
            <div class="sig-prefix"><?php echo $isEn?'Authorized by,':'ลงชื่อ'; ?></div>
            <div class="sig-line"></div>
            <div class="sig-name">( <?php echo htmlspecialchars(signerName($signer2, $isEn)); ?> )</div>
            <div class="sig-position"><?php echo htmlspecialchars($signer2['position']); ?></div>
            <?php if ($isEn): ?>
                <div class="sig-position-en"><?php echo htmlspecialchars($signer2['position_en']); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="seal-area">
        <div class="seal-circle"><?php echo $isEn?'(Company<br>Seal)':'(ตราประทับ<br>บริษัท)'; ?></div>
        <div class="seal-note">
            <?php echo $isEn?'This document is valid only when sealed with the official company stamp':
                             'เอกสารฉบับนี้มีผลสมบูรณ์ต่อเมื่อมีการประทับตราบริษัทเท่านั้น'; ?>
        </div>
        <div style="font-size:11px; color:#64748b; margin-top:8px; font-style:italic;">
            <?php echo $isEn?'Issued on ':'ออก ณ วันที่ '; ?><?php echo $isEn?fmtEnDate($docDate):fmtThaiDate($docDate); ?>
        </div>
    </div>

    <!-- ------ Verification footer (with real working QR) ------ -->
    <div class="verify-footer">
        <div class="vf-left">
            <h4><?php echo $isEn?'Document Verification':'การตรวจสอบความถูกต้องของเอกสาร'; ?></h4>
            <?php echo $isEn?'Doc No.':'เลขที่เอกสาร'; ?>: <span class="mono"><?php echo htmlspecialchars($docNumber); ?></span>
            &nbsp;|&nbsp;
            <?php echo $isEn?'Verification Code':'รหัสยืนยัน'; ?>: <span class="mono"><?php echo htmlspecialchars($verifyCode ?: '-'); ?></span>
            <br>
            <?php echo $isEn?'Verify online at':'ตรวจสอบออนไลน์ที่'; ?>:
            <span class="mono">hr.tp-asset.com/verify_document.php</span>
            &nbsp;·&nbsp;
            <?php echo $isEn?'Page':'หน้า'; ?> <?php echo $pageNo; ?>/<?php echo $pageTotal; ?>
        </div>
        <div class="qr-box">
            <img src="<?php echo htmlspecialchars($qrImg); ?>" alt="QR Verify">
            <div class="cap"><?php echo $isEn?'Scan to verify':'สแกนเพื่อตรวจสอบ'; ?></div>
        </div>
    </div>

    <div class="footer-note">
        <?php echo $isEn
            ? 'This document is issued and controlled electronically by the HR system of ' . htmlspecialchars($company['name_en'] ?: $company['name_th'])
            : 'เอกสารฉบับนี้จัดทำและออกโดยระบบบริหารทรัพยากรบุคคลของ ' . htmlspecialchars($company['name_th']) . ' ผู้ได้รับเอกสารสามารถสแกน QR Code เพื่อยืนยันความถูกต้องได้ตลอดเวลา'; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$preview): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
<?php endif; ?>
</body>
</html>
