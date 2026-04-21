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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ตรวจสอบความถูกต้องของเอกสาร</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Sarabun', sans-serif;
        background: #f1f5f9;
        color: #0f172a;
        min-height: 100vh;
        display: flex; align-items: center; justify-content: center;
        padding: 24px;
    }
    .card {
        max-width: 560px; width: 100%;
        background: #fff; border-radius: 12px;
        box-shadow: 0 4px 24px rgba(15,23,42,.08);
        overflow: hidden;
    }
    .banner {
        padding: 24px; color: #fff; text-align: center;
    }
    .banner.ok    { background: linear-gradient(135deg,#059669,#047857); }
    .banner.err   { background: linear-gradient(135deg,#dc2626,#991b1b); }
    .banner.init  { background: linear-gradient(135deg,#1a365d,#0f2847); }
    .banner .icon { font-size: 48px; margin-bottom: 8px; }
    .banner h1   { font-size: 20px; font-weight: 700; }
    .banner p    { font-size: 14px; opacity: .9; margin-top: 4px; }
    .body { padding: 24px; }
    .form { display: flex; gap: 8px; }
    .form input {
        flex: 1; padding: 12px; border: 1px solid #cbd5e1;
        border-radius: 8px; font-family: inherit; font-size: 15px;
    }
    .form button {
        padding: 12px 20px; background: #1a365d; color: #fff;
        border: 0; border-radius: 8px; font-family: inherit; font-weight: 600;
        cursor: pointer;
    }
    .info-table { width: 100%; margin-top: 16px; border-collapse: collapse; }
    .info-table th {
        width: 40%; text-align: left; padding: 10px 12px;
        background: #f8fafc; color: #475569; font-weight: 500; font-size: 13px;
        border: 1px solid #e2e8f0;
    }
    .info-table td {
        padding: 10px 12px; font-size: 14px; font-weight: 600; color: #0f172a;
        border: 1px solid #e2e8f0;
    }
    .muted { color: #64748b; font-size: 13px; margin-top: 12px; text-align: center; }
</style>
</head>
<body>
<div class="card">
    <?php if ($result): ?>
        <div class="banner ok">
            <div class="icon">✓</div>
            <h1>เอกสารผ่านการตรวจสอบ</h1>
            <p>Document Verified</p>
        </div>
        <div class="body">
            <table class="info-table">
                <tr><th>ชื่อบริษัทผู้ออกเอกสาร</th><td><?php echo htmlspecialchars($result['company_name_th'] ?: '-'); ?></td></tr>
                <tr><th>เลขที่เอกสาร</th><td><?php echo htmlspecialchars($result['document_number']); ?></td></tr>
                <tr><th>ประเภทเอกสาร</th><td><?php echo htmlspecialchars($result['tpl_name']); ?></td></tr>
                <tr><th>ออกให้กับ</th><td>
                    <?php echo htmlspecialchars($result['first_name_th'] . ' ' . $result['last_name_th']); ?>
                    <?php if ($result['first_name_en']): ?>
                    <br><span style="font-weight:500; color:#64748b; font-size:13px;">
                        <?php echo htmlspecialchars($result['first_name_en'] . ' ' . $result['last_name_en']); ?>
                    </span>
                    <?php endif; ?>
                </td></tr>
                <tr><th>รหัสพนักงาน</th><td><?php echo htmlspecialchars($result['employee_code']); ?></td></tr>
                <tr><th>วันที่ออกเอกสาร</th><td><?php echo thaiDate($result['document_date']); ?></td></tr>
                <tr><th>สถานะ</th><td style="color:#059669;">✓ ถูกต้อง (<?php echo htmlspecialchars($result['status']); ?>)</td></tr>
                <tr><th>รหัสยืนยัน</th><td style="font-family: monospace;"><?php echo htmlspecialchars($result['qr_verification_code']); ?></td></tr>
            </table>
            <p class="muted">ข้อมูลที่แสดงยืนยันว่าเอกสารฉบับนี้ออกโดยระบบ HR ของบริษัทจริง</p>
        </div>
    <?php elseif ($error): ?>
        <div class="banner err">
            <div class="icon">✗</div>
            <h1>ไม่พบเอกสาร</h1>
            <p>Document Not Found</p>
        </div>
        <div class="body">
            <p style="color:#dc2626; margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></p>
            <form class="form" method="get">
                <input type="text" name="code" placeholder="กรอกรหัสยืนยัน (เช่น FE0845C3E352)" autofocus>
                <button type="submit">ตรวจสอบ</button>
            </form>
        </div>
    <?php else: ?>
        <div class="banner init">
            <div class="icon">📋</div>
            <h1>ตรวจสอบความถูกต้องของเอกสาร</h1>
            <p>Document Verification Service</p>
        </div>
        <div class="body">
            <form class="form" method="get">
                <input type="text" name="code" placeholder="กรอกรหัสยืนยัน หรือสแกน QR" autofocus>
                <button type="submit">ตรวจสอบ</button>
            </form>
            <p class="muted">กรอกรหัสยืนยัน 12 หลักที่ปรากฏในหนังสือรับรอง หรือสแกน QR Code บนเอกสาร</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
