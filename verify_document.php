<?php
/**
 * Document Verification Endpoint
 * หน้าตรวจสอบความถูกต้องของหนังสือรับรอง (public — ไม่ต้อง login)
 *
 * URL: /verify_document.php?code=XXXX   หรือ   ?doc=HR-2569-00001
 */

require_once __DIR__ . '/bootstrap.php';
// Public page — no Auth::requireLogin()

$pdo = Database::getInstance()->getConnection();

$code = trim($_GET['code'] ?? '');
$doc  = trim($_GET['doc']  ?? '');

$result = null;
$error  = null;

// Light rate limit per session (discourage verification-code brute force)
if ($code !== '' || $doc !== '') {
    $now = time();
    if (!isset($_SESSION['_verify_doc_rl'])) {
        $_SESSION['_verify_doc_rl'] = ['window' => $now, 'count' => 0];
    }
    $rl = &$_SESSION['_verify_doc_rl'];
    if ($now - (int)($rl['window'] ?? 0) > 60) {
        $rl['window'] = $now;
        $rl['count'] = 0;
    }
    $rl['count'] = (int)($rl['count'] ?? 0) + 1;
    if ($rl['count'] > 45) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'คำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
        exit;
    }
}

if ($code || $doc) {
    $sql = "
        SELECT dr.document_number, dr.document_date, dr.qr_verification_code, dr.status,
               dr.language, dt.name AS tpl_name, dt.code AS tpl_code,
               u.first_name_th, u.last_name_th, u.first_name_en, u.last_name_en,
               u.employee_code,
               si.setting_value AS company_name_th
        FROM hr_document_requests dr
        JOIN hr_document_templates dt ON dt.id = dr.template_id
        JOIN users u ON u.id = dr.user_id
        LEFT JOIN system_settings si ON si.setting_key = 'company_name'
        WHERE " . ($code ? "dr.qr_verification_code = ?" : "dr.document_number = ?") . "
          AND dr.status IN ('PROCESSING','READY','DELIVERED')
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code ?: $doc]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$result) {
        $error = 'ไม่พบเอกสารที่ตรงกับรหัสยืนยันนี้ หรือเอกสารถูกยกเลิก';
    }
}

function thaiDate(?string $d): string {
    if (!$d) return '-';
    $m = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $t = strtotime($d);
    return (int)date('j',$t) . ' ' . $m[(int)date('n',$t)] . ' ' . ((int)date('Y',$t) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ตรวจสอบความถูกต้องของเอกสาร</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
    body {
        font-family: 'IBM Plex Sans Thai', system-ui, sans-serif;
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 52%, #0f172a 100%);
        background-attachment: fixed;
        color: #e2e8f0;
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: max(16px, env(safe-area-inset-top, 0px)) max(16px, env(safe-area-inset-right, 0px)) max(16px, env(safe-area-inset-bottom, 0px)) max(16px, env(safe-area-inset-left, 0px));
    }
    .card {
        max-width: 560px;
        width: 100%;
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.1);
        background: rgba(30, 41, 59, 0.92);
        overflow: hidden;
    }
    .banner {
        padding: 22px 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .banner.ok {
        background: rgba(16, 185, 129, 0.18);
        color: #a7f3d0;
    }
    .banner.err {
        background: rgba(239, 68, 68, 0.15);
        color: #fecaca;
    }
    .banner.init {
        background: rgba(124, 58, 237, 0.15);
        color: #ddd6fe;
    }
    .banner .icon { font-size: 2.5rem; line-height: 1; margin-bottom: 8px; }
    .banner h1 { font-size: 1.125rem; font-weight: 700; color: #f8fafc; }
    .banner p { font-size: 0.875rem; opacity: 0.9; margin-top: 6px; color: rgba(248,250,252,0.85); }
    .body { padding: 20px 20px 22px; }
    .form-stack { display: flex; flex-direction: column; gap: 12px; }
    .form-actions { display: flex; flex-direction: column; gap: 10px; }
    @media (min-width: 480px) {
        .form-actions { flex-direction: row; align-items: stretch; }
        .form-actions button { width: auto; min-width: 120px; }
    }
    .field label {
        display: block;
        font-size: 0.8125rem;
        font-weight: 600;
        color: rgba(226,232,240,0.72);
        margin-bottom: 6px;
    }
    .field input {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid rgba(255,255,255,0.12);
        border-radius: 20px;
        font-family: inherit;
        font-size: 1rem;
        min-height: 52px;
        background: rgba(15,23,42,0.5);
        color: #f8fafc;
    }
    .field input::placeholder { color: rgba(148,163,184,0.7); }
    .field input:focus {
        outline: none;
        border-color: rgba(167, 139, 250, 0.55);
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.25);
    }
    .form-actions button {
        min-height: 56px;
        padding: 12px 20px;
        background: #7c3aed;
        color: #fff;
        border: 0;
        border-radius: 20px;
        font-family: inherit;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        touch-action: manipulation;
        width: 100%;
    }
    .form-actions button:hover { background: #6d28d9; }
    .info-table { width: 100%; margin-top: 8px; border-collapse: collapse; border-radius: 20px; overflow: hidden; }
    .info-table th {
        width: 38%;
        text-align: left;
        padding: 10px 12px;
        background: rgba(15,23,42,0.55);
        color: rgba(203,213,225,0.9);
        font-weight: 600;
        font-size: 0.8125rem;
        border: 1px solid rgba(255,255,255,0.08);
        vertical-align: top;
    }
    .info-table td {
        padding: 10px 12px;
        font-size: 0.875rem;
        font-weight: 600;
        color: #f1f5f9;
        border: 1px solid rgba(255,255,255,0.08);
        vertical-align: top;
    }
    .info-table .sub-en { font-weight: 500; color: rgba(148,163,184,0.95); font-size: 0.8125rem; }
    .muted { color: rgba(148,163,184,0.9); font-size: 0.8125rem; margin-top: 16px; text-align: center; line-height: 1.45; }
    .err-msg {
        color: #fecaca;
        font-size: 0.9375rem;
        margin-bottom: 14px;
        padding: 12px 14px;
        border-radius: 20px;
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(248, 113, 113, 0.35);
    }
    .status-ok { color: #6ee7b7 !important; }
</style>
</head>
<body>
<div class="card" id="verify-root">
    <?php if ($result): ?>
        <div class="banner ok" role="status">
            <div class="icon" aria-hidden="true">✓</div>
            <h1>เอกสารผ่านการตรวจสอบ</h1>
            <p>Document Verified</p>
        </div>
        <div class="body">
            <table class="info-table">
                <tr><th scope="row">ชื่อบริษัทผู้ออกเอกสาร</th><td><?php echo htmlspecialchars($result['company_name_th'] ?: '-'); ?></td></tr>
                <tr><th scope="row">เลขที่เอกสาร</th><td><?php echo htmlspecialchars($result['document_number']); ?></td></tr>
                <tr><th scope="row">ประเภทเอกสาร</th><td><?php echo htmlspecialchars($result['tpl_name']); ?></td></tr>
                <tr><th scope="row">ออกให้กับ</th><td>
                    <?php echo htmlspecialchars($result['first_name_th'] . ' ' . $result['last_name_th']); ?>
                    <?php if ($result['first_name_en']): ?>
                    <br><span class="sub-en">
                        <?php echo htmlspecialchars($result['first_name_en'] . ' ' . $result['last_name_en']); ?>
                    </span>
                    <?php endif; ?>
                </td></tr>
                <tr><th scope="row">รหัสพนักงาน</th><td><?php echo htmlspecialchars($result['employee_code']); ?></td></tr>
                <tr><th scope="row">วันที่ออกเอกสาร</th><td><?php echo thaiDate($result['document_date']); ?></td></tr>
                <tr><th scope="row">สถานะ</th><td class="status-ok">✓ ถูกต้อง (<?php echo htmlspecialchars($result['status']); ?>)</td></tr>
                <tr><th scope="row">รหัสยืนยัน</th><td style="font-family: ui-monospace, monospace; word-break: break-all;"><?php echo htmlspecialchars($result['qr_verification_code']); ?></td></tr>
            </table>
            <p class="muted">ข้อมูลที่แสดงยืนยันว่าเอกสารฉบับนี้ออกโดยระบบ HR ของบริษัทจริง</p>
        </div>
    <?php elseif ($error): ?>
        <div class="banner err" role="alert">
            <div class="icon" aria-hidden="true">✗</div>
            <h1>ไม่พบเอกสาร</h1>
            <p>Document Not Found</p>
        </div>
        <div class="body">
            <p class="err-msg"><?php echo htmlspecialchars($error); ?></p>
            <form class="form-stack" method="get" aria-describedby="form-hint-err">
                <div class="field">
                    <label for="verify-code-err">รหัสยืนยัน (QR)</label>
                    <input id="verify-code-err" type="text" name="code" autocomplete="off" placeholder="เช่น FE0845C3E352" autofocus>
                </div>
                <div class="field">
                    <label for="verify-doc-err">เลขที่เอกสาร <span style="font-weight:400;opacity:.75">(ถ้ามี)</span></label>
                    <input id="verify-doc-err" type="text" name="doc" autocomplete="off" placeholder="เช่น HR-2569-00001">
                </div>
                <div class="form-actions">
                    <button type="submit">ตรวจสอบ</button>
                </div>
            </form>
            <p class="muted" id="form-hint-err">กรอกรหัสยืนยันจาก QR หรือเลขที่เอกสารอย่างใดอย่างหนึ่ง</p>
        </div>
    <?php else: ?>
        <div class="banner init">
            <div class="icon" aria-hidden="true">📋</div>
            <h1>ตรวจสอบความถูกต้องของเอกสาร</h1>
            <p>Document Verification Service</p>
        </div>
        <div class="body">
            <form class="form-stack" method="get" aria-describedby="form-hint-init">
                <div class="field">
                    <label for="verify-code-init">รหัสยืนยัน (QR)</label>
                    <input id="verify-code-init" type="text" name="code" autocomplete="off" placeholder="กรอกรหัสจาก QR หรือเว้นว่างหากมีแต่เลขที่เอกสาร" autofocus>
                </div>
                <div class="field">
                    <label for="verify-doc-init">เลขที่เอกสาร <span style="font-weight:400;opacity:.75">(ถ้ามี)</span></label>
                    <input id="verify-doc-init" type="text" name="doc" autocomplete="off" placeholder="เช่น HR-2569-00001">
                </div>
                <div class="form-actions">
                    <button type="submit">ตรวจสอบ</button>
                </div>
            </form>
            <p class="muted" id="form-hint-init">กรอกรหัสยืนยันที่ปรากฏในหนังสือรับรอง หรือเลขที่เอกสาร อย่างน้อยหนึ่งช่อง</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
