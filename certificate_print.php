<?php
/**
 * Certificate Print / Generator
 * พิมพ์หนังสือรับรอง - Browser Print-to-PDF (รูปแบบเดียวกับ CRM payroll print)
 *
 * Access:
 *   - HR: print any request
 *   - Owner: own request only when status in PROCESSING / READY / DELIVERED
 *
 * Params (POST + CSRF): id required, lang=TH|EN|BOTH optional, preview=1|0
 * GET ?id= is blocked — use buttons on certificate / HR documents pages.
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();
$pdo = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (int)($_GET['id'] ?? 0) > 0) {
    flash('error', 'การพิมพ์หรือดูตัวอย่างเอกสาร กรุณาใช้ปุ่มบนหน้าระบบ');
    if (hr_can_access_hr_dashboard()) {
        redirect('/hr/documents.php', 302);
    }
    redirect('/certificate.php', 302);
}

$reqId   = 0;
$langOv  = '';
$preview = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['certificate_print'] ?? '') === '1') {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        http_response_code(403);
        exit('โทเค็นความปลอดภัยไม่ถูกต้อง');
    }
    $reqId = (int)($_POST['id'] ?? 0);
    $pv = (string)($_POST['preview'] ?? '1');
    $preview = ($pv === '1');
    $langOv = strtoupper((string)($_POST['lang'] ?? ''));
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(400);
    exit('ไม่พบคำขอเอกสาร');
} else {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!$reqId) { http_response_code(400); exit('ไม่พบคำขอเอกสาร'); }

// ------------------------------------------------------------------
// Fetch request + employee + template
// ------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT dr.*, dt.code AS tpl_code, dt.name AS tpl_name, dt.name_en AS tpl_name_en,
           dt.footer_text AS tpl_footer_text, dt.layout_config AS tpl_layout,
           dt.signatory_name AS tpl_sig_name, dt.signatory_position AS tpl_sig_position,
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
$isHrDash = hr_can_access_hr_dashboard();
if (!$isHrDash && !$isOwner) { http_response_code(403); exit('ไม่มีสิทธิ์เข้าถึงเอกสารนี้'); }
if ($isOwner && !$isHrDash && !in_array($req['status'], ['PROCESSING','READY','DELIVERED','COMPLETED'], true)) {
    http_response_code(403);
    exit('เอกสารยังไม่พร้อมดาวน์โหลด (สถานะ: ' . htmlspecialchars($req['status']) . ')');
}

// ------------------------------------------------------------------
// Company settings — from CRM system_settings (real data, same as payslip)
// ------------------------------------------------------------------
$crm = [];
try {
    $rs = $pdo->query("SELECT setting_key, setting_value FROM system_settings
        WHERE setting_key IN ('company_name','company_name_en','company_address','company_phone','company_email','company_tax_id','company_website','company_logo','company_seal','doc_header_subtitle_th','doc_header_subtitle_en','doc_footer_note_th','doc_show_esignature')");
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

// Template layout_config (per-template overrides)
$tplLayout = [];
if (!empty($req['tpl_layout'])) { $tplLayout = json_decode((string)$req['tpl_layout'], true) ?: []; }
$_lh = is_array($tplLayout['header']     ?? null) ? $tplLayout['header']     : [];
$_lb = is_array($tplLayout['body']       ?? null) ? $tplLayout['body']       : [];
$_ls = is_array($tplLayout['signatures'] ?? null) ? $tplLayout['signatures'] : [];
$_lf = is_array($tplLayout['footer']     ?? null) ? $tplLayout['footer']     : [];
$LC = [
    'header_show_logo'    => $_lh['show_logo']            ?? 1,
    'header_show_addr'    => $_lh['show_company_address'] ?? 1,
    'header_sub_th'       => $_lh['subtitle_th']          ?? ($crm['doc_header_subtitle_th'] ?? ''),
    'header_sub_en'       => $_lh['subtitle_en']          ?? ($crm['doc_header_subtitle_en'] ?? ''),
    'use_custom_body'     => !empty($_lb['use_custom_body']),
    'signer_1_uid'        => (int)($_ls['signer_1_user_id'] ?? 0),
    'signer_2_uid'        => (int)($_ls['signer_2_user_id'] ?? 0),
    'show_two_signers'    => $_ls['show_two_signers']  ?? 1,
    'show_esignature'     => ($_ls['show_esignature'] ?? 0) || !empty($crm['doc_show_esignature']),
    'footer_show_qr'      => $_lf['show_qr_verify']    ?? 1,
    'footer_show_seal'    => $_lf['show_seal_area']    ?? 1,
    'footer_extra_note'   => $_lf['extra_note_th']     ?? ($crm['doc_footer_note_th'] ?? ''),
];

// Signers — real executives from users table (ประธานบริษัท + ประธานเจ้าหน้าที่บริหาร)
$sigStmt = $pdo->query("
    SELECT id, title, first_name_th, last_name_th, first_name_en, last_name_en, position, signature_image
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

// Override signers via template layout_config if configured
$findSigner = function(int $uid) use ($pdo) {
    if ($uid <= 0) return null;
    $s = $pdo->prepare("SELECT id, title, first_name_th, last_name_th, first_name_en, last_name_en, position, signature_image FROM users WHERE id=? LIMIT 1");
    $s->execute([$uid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};
$defaultBlank = ['id'=>0,'title'=>'','first_name_th'=>'','last_name_th'=>'','first_name_en'=>'','last_name_en'=>'','position'=>'','signature_image'=>null];
$signer1 = $findSigner($LC['signer_1_uid']) ?? ($signers[0] ?? array_merge($defaultBlank, ['position'=>'ประธานบริษัท']));
$signer2 = $findSigner($LC['signer_2_uid']) ?? ($signers[1] ?? array_merge($defaultBlank, ['position'=>'ประธานเจ้าหน้าที่บริหาร']));
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
if (!$docNumber && $isHrDash) {
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

$verifyHostPath = (string)(parse_url((string)APP_URL, PHP_URL_HOST) ?: '');
$verifyPathHint = $verifyHostPath !== '' ? ($verifyHostPath . '/verify_document.php') : 'verify_document.php';

$verifyUrl = rtrim(APP_URL, '/') . '/verify_document.php?code=' . urlencode($verifyCode ?: $docNumber);
$qrImg     = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&ecc=M&data=' . urlencode($verifyUrl);

// Brand assets (prefer DB setting; fallback same-origin logos for CSP)
$LOGO_BRAND = !empty($crm['company_logo']) ? $crm['company_logo'] : tp_hr_brand_logo_url('LOGO TP-ASSET - 6.png');
$COMPANY_SEAL = $crm['company_seal'] ?? '';
$WATERMARK  = tp_hr_brand_logo_url('LOGO TP-ASSET - 5.png');

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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($page_title); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/icons/tphr-app-icon.svg">
<link rel="stylesheet" href="/assets/css/native-shell.css?v=19">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ---------- Reset / Base (cloned from CRM payroll_print design system) ---------- */
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
html, body { overflow: visible; }
body {
    font-family: 'Sarabun', sans-serif;
    background: #e2e8f0;
    color: #1a1a1a;
    padding: 20px 0;
    min-height: 100vh;
    min-height: 100dvh;
}
@media screen {
    body {
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 52%, #0f172a 100%);
        background-attachment: fixed;
        color: #e2e8f0;
        padding-top: max(16px, env(safe-area-inset-top, 0px));
        padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
        padding-left: max(12px, env(safe-area-inset-left, 0px));
        padding-right: max(12px, env(safe-area-inset-right, 0px));
    }
    .screen-shell {
        max-width: calc(210mm + 40px);
        margin: 0 auto;
    }
    .pages-stack {
        border-radius: var(--tp-ios-card-radius);
        border: 1px solid rgba(255,255,255,0.1);
        background: rgba(30, 41, 59, 0.45);
        padding: 16px 12px 20px;
        margin-top: 4px;
    }
}

/* ---------- A4 Page ---------- */
.page {
    max-width: 210mm;
    width: 210mm;
    min-height: 297mm;
    max-height: 297mm;
    margin: 0 auto 20px;
    background: #fff;
    padding: 16mm 14mm 12mm;
    position: relative;
    box-shadow: 0 4px 24px rgba(15,23,42,.12);
    page-break-after: always;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    font-family: 'Sarabun', sans-serif;
}
.page:last-child { page-break-after: auto; margin-bottom: 0; }

/* ---------- Toolbar (no-print) ---------- */
.toolbar {
    max-width: 210mm;
    margin: 0 auto 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 12px 14px;
    font-family: system-ui, -apple-system, sans-serif;
    border-radius: var(--tp-ios-card-radius);
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(30, 41, 59, 0.85);
}
.toolbar-left { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.toolbar a, .toolbar button, .toolbar .tb-btn {
    padding: 10px 16px;
    min-height: var(--tp-native-touch-min, 48px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--tp-radius-small-control);
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(15, 23, 42, 0.6);
    color: #e2e8f0;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}
.toolbar a:hover, .toolbar button:hover, .toolbar .tb-btn:hover {
    background: rgba(51, 65, 85, 0.75);
}
.toolbar form { display: inline; margin: 0; }
.toolbar .btn-print {
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
    color: #fff;
    border-color: rgba(255,255,255,0.2);
    font-weight: 600;
    min-height: var(--tp-native-btn-secondary-min, 54px);
    box-shadow: 0 4px 20px rgba(124, 58, 237, 0.35);
}
.toolbar .btn-print:hover { background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%); }

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

/* ---------- Header (logo left, company right) ---------- */
.doc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid #1a365d;
    margin-bottom: 12px;
}
.doc-header-logo { flex-shrink: 0; }
.doc-header-logo img { height: 90px; width: auto; display: block; }
.doc-header-right { text-align: right; max-width: 68%; }
.doc-header-right .company-name {
    font-size: 16px;
    font-weight: 700;
    color: #1a365d;
    line-height: 1.3;
    letter-spacing: 0.02em;
}
.doc-header-right .company-name-en {
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    margin-top: 2px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.doc-header-right .company-addr {
    font-size: 11.5px;
    color: #475569;
    margin-top: 5px;
    line-height: 1.5;
}
.doc-header-right .company-tax {
    font-size: 11.5px;
    color: #475569;
    margin-top: 2px;
    font-weight: 500;
}
.doc-header-right .company-tax b { color: #1a365d; font-weight: 600; font-variant-numeric: tabular-nums; }

/* ---------- Doc reference line ---------- */
.doc-ref {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin: 2px 0 8px;
    font-size: 12.5px;
    color: #334155;
}
.doc-ref .no { font-weight: 600; color: #1a365d; }
.doc-ref .date { font-weight: 500; }

/* ---------- Title ---------- */
.doc-title {
    text-align: center;
    font-size: 19px;
    font-weight: 700;
    color: #1a365d;
    margin: 2px 0 10px;
    line-height: 1.3;
}
.doc-title .sub {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: #475569;
    margin-top: 1px;
    letter-spacing: 0.04em;
}

/* ---------- Body ---------- */
.body { font-size: 13.5px; line-height: 1.65; color: #1a1a1a; }
.body p { margin: 0 0 6px; text-indent: 2.5em; text-align: left; word-spacing: 0; }
.body p.no-indent { text-indent: 0; }
.body .nowrap { white-space: nowrap; }

/* Flexible vertical spacer that pushes seal+footer to bottom */
.flex-grow { flex: 1 1 auto; min-height: 28px; }

/* ---------- Info table (bordered — same as payslip) ---------- */
.info-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #e2e8f0;
    margin: 6px 0 8px;
}
.info-table th, .info-table td {
    padding: 4px 10px;
    font-size: 12px;
    border: 1px solid #e2e8f0;
    vertical-align: middle;
    line-height: 1.4;
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
    margin: 6px 0 4px;
    padding: 8px 14px;
    border: 2px solid #1a365d;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.salary-box .label { font-size: 13px; color: #1a365d; font-weight: 600; }
.salary-box .value {
    font-size: 16px; font-weight: 700; color: #0f172a;
    font-variant-numeric: tabular-nums;
}
.salary-box .value span { font-size: 11.5px; font-weight: 500; color: #64748b; }

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
    margin-top: 18px;
    display: flex;
    justify-content: space-around;
    gap: 20px;
}
.sig-block {
    flex: 1;
    text-align: center;
    max-width: 48%;
}
.sig-block .sig-prefix {
    font-size: 12.5px;
    color: #475569;
    margin-bottom: 6px;
}
.sig-block .sig-image {
    height: 40px;
    margin: 0 auto 4px;
    display: block;
    object-fit: contain;
}
.sig-block .sig-image-placeholder {
    height: 40px;
    margin-bottom: 4px;
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

/* Company seal area (text-only, no circle) */
.seal-area {
    position: relative;
    text-align: center;
    margin: 6px 0 8px;
    padding: 6px 12px;
    border-top: 1px dashed #cbd5e1;
    border-bottom: 1px dashed #cbd5e1;
}
.seal-area .seal-note {
    font-size: 11.5px;
    color: #64748b;
    font-weight: 500;
    letter-spacing: 0.02em;
}
.seal-area .seal-note b { color: #1a365d; font-weight: 700; }

/* ---------- Footer: verification + QR ---------- */
.verify-footer {
    padding: 7px 12px;
    border: 1px solid #cbd5e1;
    border-top: 2px solid #1a365d;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #f8fafc;
}
.verify-footer .vf-left { flex: 1; font-size: 10px; color: #475569; line-height: 1.45; }
.verify-footer .vf-left h4 {
    font-size: 10.5px; font-weight: 700; color: #1a365d;
    margin-bottom: 3px; padding-bottom: 2px;
    border-bottom: 1px solid #e2e8f0;
}
.verify-footer .vf-left .mono { font-family: 'Courier New', monospace; color: #1a365d; font-weight: 700; letter-spacing: 0.04em; }
.verify-footer .vf-left .vf-row { margin: 0; }
.verify-footer .qr-box {
    flex-shrink: 0; text-align: center;
    padding: 4px; background: #fff; border: 1px solid #1a365d;
    box-shadow: 0 1px 2px rgba(15,23,42,.06);
}
.verify-footer .qr-box img { display: block; width: 62px; height: 62px; }
.verify-footer .qr-box .cap { font-size: 8.5px; color: #1a365d; margin-top: 2px; font-weight: 600; letter-spacing: 0.04em; }

.footer-note {
    margin-top: 4px;
    padding-top: 4px;
    text-align: center;
    font-size: 9.5px;
    color: #94a3b8;
    line-height: 1.45;
}

/* ---------- Tax form (50 ทวิ) ---------- */
.tax-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 6px 0 8px; }
.tax-parties .party { border: 1px solid #e2e8f0; background: #f8fafc; padding: 7px 10px; font-size: 11px; line-height: 1.45; }
.tax-parties .party h5 { font-size: 10.5px; font-weight: 700; color: #1a365d; margin-bottom: 4px; padding-bottom: 3px; border-bottom: 1px solid #1a365d; }
.tax-parties .party b { color: #0f172a; font-weight: 600; }
.tax-table {
    width: 100%; border-collapse: collapse; font-size: 11.5px;
    margin: 6px 0;
}
.tax-table th {
    background: #1a365d; color: #fff;
    padding: 6px 8px; border: 1px solid #1a365d;
    font-size: 11px; font-weight: 600;
}
.tax-table td {
    padding: 5px 8px; border: 1px solid #e2e8f0;
    color: #1e293b;
    line-height: 1.4;
}
.tax-table td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.tax-table tr.total td { background: #f1f5f9; font-weight: 700; color: #0f172a; }
.tax-note {
    margin-top: 6px; padding: 6px 10px;
    background: #fef9e7; border-left: 3px solid #c8a951;
    font-size: 10px; color: #78350f; line-height: 1.5;
}

/* ---------- Print ---------- */
@media print {
    body { background: #fff; padding: 0; color: #1a1a1a; }
    .screen-shell { max-width: none; margin: 0; }
    .pages-stack { border: none; background: transparent; padding: 0; margin: 0; border-radius: 0; }
    .toolbar { display: none !important; }
    .page {
        width: 210mm; height: 297mm; min-height: 297mm; max-height: 297mm;
        margin: 0;
        box-shadow: none;
        padding: 12mm 12mm 8mm;
        page-break-after: always;
        overflow: hidden;
    }
    .page:last-child { page-break-after: auto; }
    .watermark img { opacity: 0.035; width: 48%; }
    @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<div class="screen-shell">
<div class="toolbar" role="toolbar" aria-label="ตัวเลือกพิมพ์หนังสือรับรอง">
    <div class="toolbar-left">
        <?php if (count($renderLangs) === 2 || ($req['language'] ?? '') === 'BOTH'): ?>
            <form method="post" action="/certificate_print.php">
                <?= csrfField() ?>
                <input type="hidden" name="certificate_print" value="1">
                <input type="hidden" name="id" value="<?= (int)$reqId ?>">
                <input type="hidden" name="preview" value="<?= $preview ? '1' : '0' ?>">
                <input type="hidden" name="lang" value="TH">
                <button type="submit" class="tb-btn">ไทย</button>
            </form>
            <form method="post" action="/certificate_print.php">
                <?= csrfField() ?>
                <input type="hidden" name="certificate_print" value="1">
                <input type="hidden" name="id" value="<?= (int)$reqId ?>">
                <input type="hidden" name="preview" value="<?= $preview ? '1' : '0' ?>">
                <input type="hidden" name="lang" value="EN">
                <button type="submit" class="tb-btn">English</button>
            </form>
            <form method="post" action="/certificate_print.php">
                <?= csrfField() ?>
                <input type="hidden" name="certificate_print" value="1">
                <input type="hidden" name="id" value="<?= (int)$reqId ?>">
                <input type="hidden" name="preview" value="<?= $preview ? '1' : '0' ?>">
                <input type="hidden" name="lang" value="BOTH">
                <button type="submit" class="tb-btn">ทั้งสองภาษา</button>
            </form>
        <?php endif; ?>
        <button type="button" class="tb-btn" onclick="history.back()">← กลับ</button>
    </div>
    <button type="button" onclick="window.print()" class="btn-print">พิมพ์ / บันทึกเป็น PDF</button>
</div>

<div class="pages-stack">
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
        <?php if (!empty($LC['header_show_logo'])): ?>
        <div class="doc-header-logo">
            <img src="<?php echo $LOGO_BRAND; ?>" alt="<?php echo htmlspecialchars($company['name_th']); ?>"
                 onerror="this.style.display='none'">
        </div>
        <?php endif; ?>
        <div class="doc-header-right">
            <div class="company-name"><?php echo htmlspecialchars($company['name_th']); ?></div>
            <?php if ($company['name_en']): ?>
                <div class="company-name-en"><?php echo htmlspecialchars(strtoupper($company['name_en'])); ?></div>
            <?php endif; ?>
            <?php $subT = $isEn ? $LC['header_sub_en'] : $LC['header_sub_th']; ?>
            <?php if (!empty($subT)): ?>
                <div class="company-addr" style="font-weight:600;color:#1a365d;"><?php echo htmlspecialchars($subT); ?></div>
            <?php endif; ?>
            <?php
                // Split Thai address at ตำบล / แขวง for cleaner 2-line display
                $addrFull = trim((string)$company['address']);
                $parts = preg_split('/(?=ตำบล|แขวง)/u', $addrFull, 2);
                $addrLine1 = trim($parts[0] ?? $addrFull);
                $addrLine2 = isset($parts[1]) ? trim($parts[1]) : '';
            ?>
            <?php if (!empty($LC['header_show_addr']) && $addrLine1): ?>
                <div class="company-addr">
                    <?php echo htmlspecialchars($addrLine1); ?>
                    <?php if ($addrLine2): ?><br><?php echo htmlspecialchars($addrLine2); ?><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="company-tax">
                <?php
                    $contactParts = [];
                    if ($company['phone']) $contactParts[] = ($isEn?'Tel':'โทร').': '.htmlspecialchars($company['phone']);
                    if ($company['email']) $contactParts[] = ($isEn?'Email':'อีเมล').': '.htmlspecialchars($company['email']);
                    if ($company['website']) $contactParts[] = htmlspecialchars($company['website']);
                    echo implode(' &nbsp;|&nbsp; ', $contactParts);
                ?>
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

    <?php
    // Custom body mode: user explicitly set use_custom_body=1 OR template code is not a built-in layout
    $isBuiltIn = in_array($baseCode, ['CERT_WORK','CERT_SALARY','TAX_50TAWI'], true) || $code === 'CERT_SALARY_BANK';
    $useCustom = $LC['use_custom_body'] || !$isBuiltIn;
    ?>

    <?php if ($useCustom):
        $rawBody = $isEn ? ($req['template_en'] ?? '') : ($req['template_th'] ?? '');
        $subs = [
            '{employee_name}'    => $V['fullName_th'],
            '{employee_name_en}' => $V['fullName_en'],
            '{employee_code}'    => $V['empCode'],
            '{national_id}'      => $V['nationalId'],
            '{position}'         => $V['position'],
            '{department}'       => $V['department'],
            '{salary}'           => number_format((float)$V['salary'], 2),
            '{salary_words}'     => $V['salary'] > 0 ? thaiBaht((float)$V['salary']) : '-',
            '{hire_date}'        => $hireStr,
            '{years_of_service}' => $tenure,
            '{purpose}'          => $isEn ? $V['purposeEn'] : $V['purpose'],
            '{company_name}'     => $company['name_th'],
            '{company_name_en}'  => $company['name_en'],
            '{doc_number}'       => $docNumber,
            '{doc_date}'         => $isEn ? fmtEnDate($docDate) : fmtThaiDate($docDate),
            '{today}'            => $isEn ? fmtEnDate(date('Y-m-d')) : fmtThaiDate(date('Y-m-d')),
        ];
        $renderedBody = strtr($rawBody, $subs);
    ?>
        <h1 class="doc-title">
            <?php echo htmlspecialchars($isEn ? ($req['tpl_name_en'] ?: $req['tpl_name']) : $req['tpl_name']); ?>
        </h1>
        <div class="body">
            <?php if (trim($renderedBody) === ''): ?>
                <p class="no-indent" style="color:#94a3b8;font-style:italic;">[ยังไม่ได้กรอกเนื้อหาเทมเพลต — กรุณาตั้งค่าในหน้า "ตั้งค่าเอกสารรับรอง"]</p>
            <?php else: ?>
                <?php echo nl2br(htmlspecialchars($renderedBody)); ?>
            <?php endif; ?>
        </div>

    <?php elseif ($baseCode === 'CERT_WORK' && !$isEn): ?>
        <h1 class="doc-title">
            หนังสือรับรองการทำงาน
            <span class="sub">CERTIFICATE OF EMPLOYMENT</span>
        </h1>
        <div class="body">
            <p>ตามที่ <?php echo htmlspecialchars($V['fullName_th']); ?>
                ได้ขอให้ <?php echo htmlspecialchars($company['name_th']); ?>
                <?php echo $company['name_en'] ? '<span class="nowrap">(' . htmlspecialchars(strtoupper($company['name_en'])) . ')</span>' : ''; ?>
                ออกหนังสือรับรองการทำงานให้ไว้เป็นหลักฐานนั้น บริษัทฯ ขอรับรองว่าบุคคลดังกล่าว เป็นพนักงานของบริษัทฯ โดยมีรายละเอียดตามที่ปรากฏในตารางด้านล่างนี้จริงทุกประการ</p>
            <table class="info-table">
                <tr><th>ชื่อ-นามสกุล</th><td><?php echo htmlspecialchars($V['fullName_th']); ?></td></tr>
                <tr><th>เลขประจำตัวประชาชน</th><td><?php echo htmlspecialchars($V['nationalId']); ?></td></tr>
                <tr><th>รหัสพนักงาน</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>ตำแหน่ง / Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>แผนก / Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>วันที่เริ่มปฏิบัติงาน</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>อายุการทำงาน</th><td><?php echo htmlspecialchars($tenure); ?> <span style="font-weight:500; color:#64748b;">(นับถึงวันที่ออกหนังสือ)</span></td></tr>
            </table>
            <p>โดยปัจจุบันบุคคลดังกล่าวยังคงปฏิบัติหน้าที่เป็นพนักงานของบริษัทฯ อยู่ บริษัทฯ จึงออกหนังสือรับรองฉบับนี้ให้ไว้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?> ทั้งนี้ ขอรับรองว่าข้อความที่ปรากฏข้างต้นเป็นความจริงทุกประการ</p>
            <p>จึงเรียนมาเพื่อโปรดพิจารณาและใช้เป็นหลักฐานอ้างอิงต่อไป</p>
        </div>

    <?php elseif ($baseCode === 'CERT_WORK' && $isEn): ?>
        <h1 class="doc-title">
            CERTIFICATE OF EMPLOYMENT
            <span class="sub">หนังสือรับรองการทำงาน</span>
        </h1>
        <div class="body">
            <p class="no-indent"><strong>TO WHOM IT MAY CONCERN,</strong></p>
            <p>This letter is issued at the request of the individual named below to certify that he/she is a current employee of <strong><?php echo htmlspecialchars(strtoupper($company['name_en'] ?: $company['name_th'])); ?></strong> with the particulars as set forth in the table hereunder:</p>
            <table class="info-table">
                <tr><th>Full Name</th><td><?php echo htmlspecialchars($V['fullName_en']); ?></td></tr>
                <tr><th>Employee ID</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>Date of Employment</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>Length of Service</th><td><?php echo htmlspecialchars($tenure); ?> <span style="font-weight:500;color:#64748b;">(as of the issue date)</span></td></tr>
            </table>
            <p>The above-named person remains in active employment with the Company as of the date of issue. This Certificate is issued <?php echo htmlspecialchars($V['purposeEn']); ?>, and the Company hereby certifies that all information stated above is true and correct in every respect.</p>
            <p>Should any further verification be required, please contact the Human Resources Department or scan the QR code provided at the bottom of this document.</p>
        </div>

    <?php elseif (($baseCode === 'CERT_SALARY' || $code === 'CERT_SALARY_BANK') && !$isEn): ?>
        <h1 class="doc-title">
            หนังสือรับรองเงินเดือน<?php echo $code==='CERT_SALARY_BANK'?' (ฉบับสำหรับธนาคาร)':''; ?>
            <span class="sub">CERTIFICATE OF SALARY<?php echo $code==='CERT_SALARY_BANK'?' (FOR BANK)':''; ?></span>
        </h1>
        <div class="body">
            <p>ตามที่ <?php echo htmlspecialchars($V['fullName_th']); ?>
                ได้ขอให้ <?php echo htmlspecialchars($company['name_th']); ?>
                <?php echo $company['name_en'] ? '<span class="nowrap">(' . htmlspecialchars(strtoupper($company['name_en'])) . ')</span>' : ''; ?>
                ออกหนังสือรับรองเงินเดือนให้ไว้เป็นหลักฐานนั้น บริษัทฯ ขอรับรองว่าบุคคลดังกล่าวเป็นพนักงานของบริษัทฯ และได้รับเงินเดือนตามอัตราที่ระบุไว้ในเอกสารฉบับนี้จริง โดยมีรายละเอียดดังนี้</p>
            <table class="info-table">
                <tr><th>ชื่อ-นามสกุล</th><td><?php echo htmlspecialchars($V['fullName_th']); ?></td></tr>
                <tr><th>เลขประจำตัวประชาชน</th><td><?php echo htmlspecialchars($V['nationalId']); ?></td></tr>
                <tr><th>รหัสพนักงาน</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>ตำแหน่ง / Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>แผนก / Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>วันที่เริ่มปฏิบัติงาน</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>ประเภทการจ้าง</th><td>พนักงานประจำ (รายเดือน)</td></tr>
            </table>
            <div class="salary-box">
                <span class="label">อัตราเงินเดือน / Monthly Salary</span>
                <span class="value"><?php echo number_format($V['salary'], 2); ?> <span>บาท (<?php echo thaiBaht($V['salary']); ?>)</span></span>
            </div>
            <?php if ($code==='CERT_SALARY_BANK' && $req['recipient']): ?>
            <div class="remark"><strong>เรียน:</strong> <?php echo htmlspecialchars($req['recipient']); ?> &nbsp;|&nbsp;
                หนังสือฉบับนี้ออกให้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?>โดยเฉพาะ
            </div>
            <?php endif; ?>
            <p>บริษัทฯ จึงออกหนังสือรับรองเงินเดือนฉบับนี้ให้ไว้เพื่อ<?php echo htmlspecialchars($V['purpose']); ?> ทั้งนี้ ขอรับรองว่าข้อความที่ปรากฏข้างต้นเป็นความจริงทุกประการ</p>
        </div>

    <?php elseif ($baseCode === 'CERT_SALARY' && $isEn): ?>
        <h1 class="doc-title">
            CERTIFICATE OF SALARY
            <span class="sub">หนังสือรับรองเงินเดือน</span>
        </h1>
        <div class="body">
            <p class="no-indent"><strong>TO WHOM IT MAY CONCERN,</strong></p>
            <p>This letter is issued at the request of the individual named below to certify that he/she is a current employee of <strong><?php echo htmlspecialchars(strtoupper($company['name_en'] ?: $company['name_th'])); ?></strong> and is in receipt of the monthly salary as specified herein. The particulars are as follows:</p>
            <table class="info-table">
                <tr><th>Full Name</th><td><?php echo htmlspecialchars($V['fullName_en']); ?></td></tr>
                <tr><th>Employee ID</th><td><?php echo htmlspecialchars($V['empCode']); ?></td></tr>
                <tr><th>Position</th><td><?php echo htmlspecialchars($V['position']); ?></td></tr>
                <tr><th>Department</th><td><?php echo htmlspecialchars($V['department']); ?></td></tr>
                <tr><th>Date of Employment</th><td><?php echo htmlspecialchars($hireStr); ?></td></tr>
                <tr><th>Employment Type</th><td>Permanent / Full-time (Monthly-paid)</td></tr>
            </table>
            <div class="salary-box">
                <span class="label">Monthly Salary</span>
                <span class="value">THB <?php echo number_format($V['salary'], 2); ?></span>
            </div>
            <p>This Certificate is hereby issued <?php echo htmlspecialchars($V['purposeEn']); ?>. The Company certifies that all information stated above is true and correct in every respect.</p>
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
                <span style="color:#64748b;"><?php echo htmlspecialchars(strtoupper($company['name_en'] ?? '')); ?></span><br>
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
            <strong>หมายเหตุ:</strong> เอกสารฉบับนี้ไม่ใช่แบบทางการของกรมสรรพากร (พ.ง.ด. 1ก) ออกโดยระบบ HR ของบริษัทฯ จากฐานเงินเดือน — หากต้องการแบบทางการ กรุณาติดต่อฝ่ายบัญชี
        </div>

    <?php else: ?>
        <div class="body"><p class="no-indent">[<?php echo htmlspecialchars($req['tpl_name']); ?>] ยังไม่ได้กำหนดเทมเพลตสำหรับเอกสารประเภทนี้</p></div>
    <?php endif; ?>

    <!-- ------ Signatures: TWO signers + company seal ------ -->
    <div class="signatures">
        <div class="sig-block">
            <div class="sig-prefix"><?php echo $isEn?'Authorized by,':'ลงชื่อ'; ?></div>
            <?php if ($LC['show_esignature'] && !empty($signer1['signature_image'])): ?>
                <img class="sig-image" src="<?php echo htmlspecialchars($signer1['signature_image']); ?>" alt="signature">
            <?php else: ?>
                <div class="sig-image-placeholder"></div>
            <?php endif; ?>
            <div class="sig-line"></div>
            <div class="sig-name">( <?php echo htmlspecialchars(signerName($signer1, $isEn)); ?> )</div>
            <div class="sig-position"><?php echo htmlspecialchars($signer1['position']); ?></div>
            <?php if ($isEn): ?>
                <div class="sig-position-en"><?php echo htmlspecialchars($signer1['position_en']); ?></div>
            <?php endif; ?>
        </div>
        <?php if (!empty($LC['show_two_signers'])): ?>
        <div class="sig-block">
            <div class="sig-prefix"><?php echo $isEn?'Authorized by,':'ลงชื่อ'; ?></div>
            <?php if ($LC['show_esignature'] && !empty($signer2['signature_image'])): ?>
                <img class="sig-image" src="<?php echo htmlspecialchars($signer2['signature_image']); ?>" alt="signature">
            <?php else: ?>
                <div class="sig-image-placeholder"></div>
            <?php endif; ?>
            <div class="sig-line"></div>
            <div class="sig-name">( <?php echo htmlspecialchars(signerName($signer2, $isEn)); ?> )</div>
            <div class="sig-position"><?php echo htmlspecialchars($signer2['position']); ?></div>
            <?php if ($isEn): ?>
                <div class="sig-position-en"><?php echo htmlspecialchars($signer2['position_en']); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Empty space reserved for physical seal stamp -->
    <div class="flex-grow" aria-hidden="true"></div>

    <?php if (!empty($LC['footer_show_seal'])): ?>
    <div class="seal-area">
        <?php if (!empty($COMPANY_SEAL)): ?>
            <img src="<?php echo htmlspecialchars($COMPANY_SEAL); ?>" alt="company seal" style="max-height:70px;margin-bottom:4px;">
        <?php endif; ?>
        <div class="seal-note">
            <?php echo $isEn
                ? 'This document is valid <b>only when affixed with the official company seal</b>.'
                : '<b>เอกสารฉบับนี้มีผลสมบูรณ์ต่อเมื่อมีการประทับตราบริษัทเท่านั้น</b>'; ?>
        </div>
        <?php if (!empty($LC['footer_extra_note'])): ?>
            <div class="seal-note" style="margin-top:4px;font-size:10px;"><?php echo nl2br(htmlspecialchars($LC['footer_extra_note'])); ?></div>
        <?php endif; ?>
        <?php if (!empty($req['tpl_footer_text'])): ?>
            <div class="seal-note" style="margin-top:4px;font-size:10px;"><?php echo nl2br(htmlspecialchars($req['tpl_footer_text'])); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ------ Verification footer (with real working QR) ------ -->
    <?php if (!empty($LC['footer_show_qr'])): ?>
    <div class="verify-footer">
        <div class="vf-left">
            <h4><?php echo $isEn?'Document Verification':'การตรวจสอบความถูกต้องของเอกสาร'; ?></h4>
            <div class="vf-row"><?php echo $isEn?'Document No.':'เลขที่เอกสาร'; ?>: <span class="mono"><?php echo htmlspecialchars($docNumber); ?></span></div>
            <div class="vf-row"><?php echo $isEn?'Verification Code':'รหัสยืนยัน'; ?>: <span class="mono"><?php echo htmlspecialchars($verifyCode ?: '-'); ?></span></div>
            <div class="vf-row"><?php echo $isEn?'Verify at':'ตรวจสอบออนไลน์ที่'; ?>: <span class="mono"><?php echo htmlspecialchars($verifyPathHint); ?></span></div>
        </div>
        <div class="qr-box">
            <img src="<?php echo htmlspecialchars($qrImg); ?>" alt="QR Verify">
            <div class="cap"><?php echo $isEn?'SCAN TO VERIFY':'สแกนเพื่อตรวจสอบ'; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="footer-note">
        <?php echo $isEn
            ? 'Issued electronically by the HR system of ' . htmlspecialchars(strtoupper($company['name_en'] ?: $company['name_th'])) . ' &nbsp;·&nbsp; Page ' . $pageNo . '/' . $pageTotal
            : 'ออกโดยระบบบริหารทรัพยากรบุคคล ' . htmlspecialchars($company['name_th']) . ' &nbsp;·&nbsp; หน้า ' . $pageNo . '/' . $pageTotal; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>

<?php if (!$preview): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
<?php endif; ?>
</body>
</html>
