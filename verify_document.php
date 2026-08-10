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
<meta name="theme-color" content="#b79168">
<title>ตรวจสอบความถูกต้องของเอกสาร</title>
<link rel="icon" type="image/png" href="/assets/icons/icon-192-v3.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css?v=31">
<link rel="stylesheet" href="/assets/css/native-shell.css?v=31">
<style>
    * {
        font-family: 'IBM Plex Sans Thai', system-ui, sans-serif;
        -webkit-tap-highlight-color: transparent;
        box-sizing: border-box;
    }
    body {
        min-height: 100vh;
        min-height: 100dvh;
        overflow-x: hidden;
        background: linear-gradient(135deg, #0f172a 0%, #2b2119 50%, #0f172a 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        padding-top: max(16px, env(safe-area-inset-top, 0px));
        padding-right: max(16px, env(safe-area-inset-right, 0px));
        padding-bottom: max(24px, env(safe-area-inset-bottom, 0px));
        padding-left: max(16px, env(safe-area-inset-left, 0px));
    }
    @media (min-width: 768px) {
        body {
            padding-top: max(24px, env(safe-area-inset-top, 0px));
            padding-right: max(24px, env(safe-area-inset-right, 0px));
            padding-bottom: max(28px, env(safe-area-inset-bottom, 0px));
            padding-left: max(24px, env(safe-area-inset-left, 0px));
        }
    }
</style>
</head>
<body class="tp-native-app verify-doc-page">
<div class="native-card tp-native-card tp-native-data-card w-full max-w-[min(560px,100%)] overflow-hidden rounded-[var(--tp-ios-card-radius)] shadow-xl" id="verify-root">
    <?php if ($result): ?>
        <div class="px-5 py-6 text-center border-b border-emerald-400/35 bg-emerald-500/15" role="status">
            <div class="mb-3 text-4xl leading-none text-emerald-200" aria-hidden="true">✓</div>
            <h1 class="text-lg font-bold text-white tracking-tight">เอกสารผ่านการตรวจสอบ</h1>
            <p class="tp-ios-caption-muted mt-2">Document Verified</p>
        </div>
        <div class="p-5 sm:p-6 space-y-4">
            <div class="overflow-x-auto rounded-[var(--tp-ios-card-radius)] border border-white/10">
            <table class="w-full min-w-[280px] text-sm border-collapse">
                <tbody class="divide-y divide-white/10">
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">ชื่อบริษัทผู้ออกเอกสาร</th><td class="py-3 px-3 font-medium text-white"><?php echo htmlspecialchars($result['company_name_th'] ?: '-'); ?></td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">เลขที่เอกสาร</th><td class="py-3 px-3 font-medium text-white"><?php echo htmlspecialchars($result['document_number']); ?></td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">ประเภทเอกสาร</th><td class="py-3 px-3 font-medium text-white"><?php echo htmlspecialchars($result['tpl_name']); ?></td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">ออกให้กับ</th><td class="py-3 px-3 font-medium text-white"><?php echo htmlspecialchars($result['first_name_th'] . ' ' . $result['last_name_th']); ?>
                    <?php if ($result['first_name_en']): ?>
                    <span class="mt-2 block font-medium text-xs text-white/55">
                        <?php echo htmlspecialchars($result['first_name_en'] . ' ' . $result['last_name_en']); ?>
                    </span>
                    <?php endif; ?>
                </td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">รหัสพนักงาน</th><td class="py-3 px-3 font-medium text-white"><?php echo htmlspecialchars($result['employee_code']); ?></td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">วันที่ออกเอกสาร</th><td class="py-3 px-3 font-medium text-white"><?php echo thaiDate($result['document_date']); ?></td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">สถานะ</th><td class="py-3 px-3 font-medium text-emerald-300">✓ ถูกต้อง (<?php echo htmlspecialchars($result['status']); ?>)</td></tr>
                    <tr><th scope="row" class="text-left py-3 px-3 bg-white/[0.04] text-white/60 font-semibold align-top">รหัสยืนยัน</th><td class="py-3 px-3 font-mono text-[0.8125rem] break-all"><?php echo htmlspecialchars($result['qr_verification_code']); ?></td></tr>
                </tbody>
            </table>
            </div>
            <p class="tp-ios-caption-muted text-center text-xs leading-relaxed">ข้อมูลที่แสดงยืนยันว่าเอกสารฉบับนี้ออกโดยระบบ HR ของบริษัทจริง</p>
        </div>
    <?php elseif ($error): ?>
        <div class="px-5 py-6 text-center border-b border-red-400/35 bg-red-500/15" role="alert">
            <div class="mb-3 text-4xl leading-none text-red-200" aria-hidden="true">✗</div>
            <h1 class="text-lg font-bold text-white tracking-tight">ไม่พบเอกสาร</h1>
            <p class="tp-ios-caption-muted mt-2">Document Not Found</p>
        </div>
        <div class="p-5 sm:p-6 space-y-5">
            <div class="tp-native-error-state bg-red-500/15 border border-red-400/45 text-red-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] text-sm leading-snug">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <form class="space-y-4" method="get" aria-describedby="form-hint-err">
                <div class="tp-native-form-group mb-0">
                    <label class="block text-white/80 text-sm font-medium mb-2" for="verify-code-err">รหัสยืนยัน (QR)</label>
                    <input id="verify-code-err" type="text" name="code" autocomplete="off" placeholder="เช่น FE0845C3E352" autofocus class="input-field tp-native-input w-full">
                </div>
                <div class="tp-native-form-group mb-0">
                    <label class="block text-white/80 text-sm font-medium mb-2" for="verify-doc-err">เลขที่เอกสาร <span class="font-normal text-white/50">(ถ้ามี)</span></label>
                    <input id="verify-doc-err" type="text" name="doc" autocomplete="off" placeholder="เช่น HR-2569-00001" class="input-field tp-native-input w-full">
                </div>
                <button type="submit" class="tp-native-btn-primary w-full touch-manipulation whitespace-nowrap">ตรวจสอบ</button>
            </form>
            <p class="tp-ios-caption-muted text-center text-xs leading-relaxed" id="form-hint-err">กรอกรหัสยืนยันจาก QR หรือเลขที่เอกสารอย่างใดอย่างหนึ่ง</p>
        </div>
    <?php else: ?>
        <div class="px-5 py-6 text-center border-b border-violet-400/30 bg-violet-500/15">
            <div class="mb-3 text-4xl leading-none" aria-hidden="true">📋</div>
            <h1 class="text-lg font-bold text-white tracking-tight">ตรวจสอบความถูกต้องของเอกสาร</h1>
            <p class="tp-ios-caption-muted mt-2">Document Verification Service</p>
        </div>
        <div class="p-5 sm:p-6 space-y-5">
            <form class="space-y-4" method="get" aria-describedby="form-hint-init">
                <div class="tp-native-form-group mb-0">
                    <label class="block text-white/80 text-sm font-medium mb-2" for="verify-code-init">รหัสยืนยัน (QR)</label>
                    <input id="verify-code-init" type="text" name="code" autocomplete="off" placeholder="กรอกรหัสจาก QR หรือเว้นว่างหากมีแต่เลขที่เอกสาร" autofocus class="input-field tp-native-input w-full">
                </div>
                <div class="tp-native-form-group mb-0">
                    <label class="block text-white/80 text-sm font-medium mb-2" for="verify-doc-init">เลขที่เอกสาร <span class="font-normal text-white/50">(ถ้ามี)</span></label>
                    <input id="verify-doc-init" type="text" name="doc" autocomplete="off" placeholder="เช่น HR-2569-00001" class="input-field tp-native-input w-full">
                </div>
                <button type="submit" class="tp-native-btn-primary w-full touch-manipulation whitespace-nowrap">ตรวจสอบ</button>
            </form>
            <p class="tp-ios-caption-muted text-center text-xs leading-relaxed" id="form-hint-init">กรอกรหัสยืนยันที่ปรากฏในหนังสือรับรอง หรือเลขที่เอกสาร อย่างน้อยหนึ่งช่อง</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
