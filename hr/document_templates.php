<?php
/**
 * HR Document Templates & Company Document Settings
 * ตั้งค่าเอกสารรับรอง — ข้อมูลบริษัท / ส่วนหัว / ส่วนกลาง / ส่วนลงนาม / ส่วนท้าย
 * Access: HR dashboard (role or Acl hr.dashboard / hr.*)
 */

$page_title = 'ตั้งค่าเอกสารรับรอง';
$current_page = 'hr-document-templates';

require_once dirname(__DIR__) . '/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();

if (!hr_can_access_hr_dashboard()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();
$success = null; $error = null;

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------
function dt_setSetting(PDO $pdo, string $key, string $value): void {
    (new SettingsService($pdo))->setSystem($key, $value, 'STRING', Auth::id(), 'ทั่วไป', 'ตั้งค่าเอกสารรับรอง');
}

function dt_getAllSettings(PDO $pdo): array {
    return (new SettingsService($pdo))->getSystemMany([
        'company_name','company_name_en','company_address','company_phone','company_email',
        'company_website','company_tax_id','company_logo','company_seal',
        'doc_header_subtitle_th','doc_header_subtitle_en','doc_footer_note_th','doc_show_esignature',
    ]);
}

function dt_handleUpload(array $file, string $subdir, array $allowed = ['png','jpg','jpeg','webp']): string {
    if (!in_array($subdir, ['company','signatures'], true)) {
        throw new Exception('ประเภทการอัปโหลดไม่ถูกต้อง');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('อัปโหลดล้มเหลว (error: ' . $file['error'] . ')');
    }

    $allowedNorm = array_map(static function ($e) {
        $e = strtolower((string)$e);
        return $e === 'jpeg' ? 'jpg' : $e;
    }, $allowed);
    $allowedNorm = array_values(array_unique($allowedNorm));

    $result = uploadFile($file, 'uploads/' . $subdir, [
        'types' => $allowedNorm,
        'max_size' => 5 * 1024 * 1024,
    ]);
    if (!($result['success'] ?? false)) {
        throw new Exception((string)($result['message'] ?? 'อัปโหลดไม่สำเร็จ'));
    }
    $rel = (string)($result['path'] ?? '');
    if ($rel === '') {
        throw new Exception('บันทึกไฟล์ไม่ได้');
    }
    return '/' . str_replace('\\', '/', $rel);
}

// ------------------------------------------------------------------
// Categories & placeholder reference
// ------------------------------------------------------------------
$categories = [
    'CERTIFICATE' => 'หนังสือรับรอง',
    'CONTRACT'    => 'สัญญา',
    'LETTER'      => 'จดหมาย',
    'FORM'        => 'แบบฟอร์ม',
    'OTHER'       => 'อื่น ๆ',
];

$placeholderGuide = [
    '{employee_name}'    => 'ชื่อ-นามสกุลพนักงาน (ไทย)',
    '{employee_name_en}' => 'ชื่อ-นามสกุล (อังกฤษ)',
    '{employee_code}'    => 'รหัสพนักงาน',
    '{national_id}'      => 'เลขบัตรประชาชน',
    '{position}'         => 'ตำแหน่ง',
    '{department}'       => 'แผนก',
    '{salary}'           => 'เงินเดือน (บาท)',
    '{salary_words}'     => 'เงินเดือนเป็นตัวอักษร',
    '{hire_date}'        => 'วันที่เริ่มงาน (ไทย)',
    '{years_of_service}' => 'อายุงาน',
    '{purpose}'          => 'วัตถุประสงค์',
    '{company_name}'     => 'ชื่อบริษัท',
    '{company_name_en}'  => 'ชื่อบริษัท (อังกฤษ)',
    '{doc_number}'       => 'เลขที่เอกสาร',
    '{doc_date}'         => 'วันที่ออกเอกสาร',
    '{today}'            => 'วันนี้',
];

// ------------------------------------------------------------------
// POST actions
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'โทเค็นความปลอดภัยไม่ถูกต้อง';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            // ---- Company-wide document settings ----
            if ($action === 'save_company') {
                $fields = [
                    'company_name', 'company_name_en', 'company_tax_id',
                    'company_phone', 'company_email', 'company_website', 'company_address',
                    'doc_header_subtitle_th', 'doc_header_subtitle_en', 'doc_footer_note_th',
                ];
                foreach ($fields as $f) {
                    if (!isset($_POST[$f])) continue;
                    $val = trim($_POST[$f]);
                    if ($f === 'company_email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
                    }
                    if ($f === 'company_website' && $val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                        throw new Exception('รูปแบบเว็บไซต์ไม่ถูกต้อง');
                    }
                    dt_setSetting($pdo, $f, $val);
                }
                dt_setSetting($pdo, 'doc_show_esignature', !empty($_POST['doc_show_esignature']) ? '1' : '0');

                if (!empty($_FILES['company_logo']['name'])) {
                    $url = dt_handleUpload($_FILES['company_logo'], 'company');
                    if ($url) dt_setSetting($pdo, 'company_logo', $url);
                } elseif (isset($_POST['company_logo_url'])) {
                    dt_setSetting($pdo, 'company_logo', trim($_POST['company_logo_url']));
                }
                if (!empty($_FILES['company_seal']['name'])) {
                    $url = dt_handleUpload($_FILES['company_seal'], 'company');
                    if ($url) dt_setSetting($pdo, 'company_seal', $url);
                } elseif (isset($_POST['company_seal_url'])) {
                    dt_setSetting($pdo, 'company_seal', trim($_POST['company_seal_url']));
                }
                $success = 'บันทึกข้อมูลบริษัทเรียบร้อยแล้ว';
            }
            // ---- Signature upload for a user ----
            elseif ($action === 'upload_signature') {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid <= 0) throw new Exception('ไม่พบผู้ใช้');
                $chk = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND is_active=1");
                $chk->execute([$uid]);
                if (!$chk->fetchColumn()) throw new Exception('ไม่พบผู้ใช้หรือผู้ใช้ถูกปิดใช้งาน');
                if (empty($_FILES['signature']['name'])) throw new Exception('กรุณาเลือกไฟล์ลายเซ็น');
                $url = dt_handleUpload($_FILES['signature'], 'signatures', ['png','jpg','jpeg','webp']);
                $pdo->prepare("UPDATE users SET signature_image=? WHERE id=?")->execute([$url, $uid]);
                $success = 'อัปโหลดลายเซ็นสำเร็จ';
            }
            elseif ($action === 'remove_signature') {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid > 0) {
                    $pdo->prepare("UPDATE users SET signature_image=NULL WHERE id=?")->execute([$uid]);
                    $success = 'ลบลายเซ็นเรียบร้อย';
                }
            }
            // ---- Full template save (all sections) ----
            elseif ($action === 'save_template') {
                $id = (int)($_POST['id'] ?? 0);
                $code = strtoupper(trim($_POST['code'] ?? ''));
                $name = trim($_POST['name'] ?? '');
                $name_en = trim($_POST['name_en'] ?? '');
                $category = $_POST['category'] ?? 'CERTIFICATE';
                $description = trim($_POST['description'] ?? '');
                $processing_days = max(0, (int)($_POST['processing_days'] ?? 1));
                $requires_approval = isset($_POST['requires_approval']) ? 1 : 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $sort_order = (int)($_POST['sort_order'] ?? 0);

                if ($code === '' || $name === '') throw new Exception('กรุณากรอกรหัสและชื่อเอกสาร');
                if (!preg_match('/^[A-Z0-9_]+$/', $code)) throw new Exception('รหัสเอกสารต้องเป็น A-Z / 0-9 / _');

                // Body
                $template_th = $_POST['template_th'] ?? '';
                $template_en = $_POST['template_en'] ?? '';
                $footer_text = trim($_POST['footer_text'] ?? '');
                $signatory_name = trim($_POST['signatory_name'] ?? '');
                $signatory_position = trim($_POST['signatory_position'] ?? '');

                // Layout config JSON (header / body options / signatures / footer)
                $layout = [
                    'header' => [
                        'show_logo'            => isset($_POST['layout']['header']['show_logo']) ? 1 : 0,
                        'show_company_address' => isset($_POST['layout']['header']['show_company_address']) ? 1 : 0,
                        'subtitle_th'          => trim($_POST['layout']['header']['subtitle_th'] ?? ''),
                        'subtitle_en'          => trim($_POST['layout']['header']['subtitle_en'] ?? ''),
                    ],
                    'body' => [
                        'use_custom_body' => isset($_POST['layout']['body']['use_custom_body']) ? 1 : 0,
                    ],
                    'signatures' => [
                        'signer_1_user_id' => (int)($_POST['layout']['signatures']['signer_1_user_id'] ?? 0),
                        'signer_2_user_id' => (int)($_POST['layout']['signatures']['signer_2_user_id'] ?? 0),
                        'show_esignature'  => isset($_POST['layout']['signatures']['show_esignature']) ? 1 : 0,
                        'show_two_signers' => isset($_POST['layout']['signatures']['show_two_signers']) ? 1 : 0,
                    ],
                    'footer' => [
                        'show_qr_verify'  => isset($_POST['layout']['footer']['show_qr_verify']) ? 1 : 0,
                        'show_seal_area'  => isset($_POST['layout']['footer']['show_seal_area']) ? 1 : 0,
                        'extra_note_th'   => trim($_POST['layout']['footer']['extra_note_th'] ?? ''),
                    ],
                ];

                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE hr_document_templates SET
                        code=?, name=?, name_en=?, category=?, description=?,
                        template_th=?, template_en=?, footer_text=?,
                        signatory_name=?, signatory_position=?,
                        processing_days=?, requires_approval=?, is_active=?, sort_order=?,
                        layout_config=?, updated_at=NOW()
                        WHERE id=?");
                    $stmt->execute([$code,$name,$name_en,$category,$description,
                        $template_th,$template_en,$footer_text,
                        $signatory_name,$signatory_position,
                        $processing_days,$requires_approval,$is_active,$sort_order,
                        json_encode($layout, JSON_UNESCAPED_UNICODE), $id]);
                    $success = 'บันทึกเอกสารเรียบร้อยแล้ว';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO hr_document_templates
                        (code,name,name_en,category,description,template_th,template_en,footer_text,
                         signatory_name,signatory_position,processing_days,requires_approval,
                         is_active,sort_order,layout_config,created_at,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
                    $stmt->execute([$code,$name,$name_en,$category,$description,
                        $template_th,$template_en,$footer_text,
                        $signatory_name,$signatory_position,$processing_days,$requires_approval,
                        $is_active,$sort_order, json_encode($layout, JSON_UNESCAPED_UNICODE)]);
                    $id = (int)$pdo->lastInsertId();
                    $success = 'เพิ่มเอกสารใหม่เรียบร้อยแล้ว';
                }
                // After save, keep user on edit page
                redirect('document_templates.php?edit=' . $id . '&saved=1', 302);
            }
            elseif ($action === 'toggle_active') {
                $id = (int)$_POST['id'];
                $pdo->prepare("UPDATE hr_document_templates SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?")->execute([$id]);
                $success = 'เปลี่ยนสถานะเรียบร้อย';
            }
            elseif ($action === 'delete_template') {
                $id = (int)$_POST['id'];
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM hr_document_requests WHERE template_id=?");
                $cnt->execute([$id]);
                if ((int)$cnt->fetchColumn() > 0) throw new Exception('มีคำขอใช้งานอยู่ ไม่สามารถลบได้ — ให้ปิดใช้งานแทน');
                $pdo->prepare("DELETE FROM hr_document_templates WHERE id=?")->execute([$id]);
                $success = 'ลบเอกสารเรียบร้อย';
            }
        } catch (Throwable $e) {
            tpHrLogException($e, 'hr/document_templates POST');
            if ($e instanceof PDOException) {
                $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
            } elseif ($e instanceof Exception) {
                $error = $e->getMessage();
            } else {
                $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
            }
        }
    }
}

if (!empty($_GET['saved'])) $success = $success ?? 'บันทึกเอกสารเรียบร้อยแล้ว';

// ------------------------------------------------------------------
// Load data
// ------------------------------------------------------------------
$settings = dt_getAllSettings($pdo);

$tpls = $pdo->query("
    SELECT dt.*,
        (SELECT COUNT(*) FROM hr_document_requests dr WHERE dr.template_id = dt.id) AS request_count
    FROM hr_document_templates dt
    ORDER BY dt.is_active DESC, dt.sort_order ASC, dt.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Signer candidates: users with President/CEO/Chairman positions
$signerCandidates = $pdo->query("
    SELECT id, title, first_name_th, last_name_th, first_name_en, last_name_en, position, signature_image
    FROM users
    WHERE is_active=1 AND (
        position LIKE 'ประธาน%'
        OR position LIKE '%กรรมการผู้จัดการ%'
        OR position LIKE '%CEO%'
        OR position LIKE '%ผู้จัดการฝ่ายบุคคล%'
        OR position LIKE '%HR%'
    )
    ORDER BY
      CASE
        WHEN position LIKE 'ประธานบริษัท%' THEN 1
        WHEN position LIKE 'ประธานเจ้าหน้าที่บริหาร%' THEN 2
        WHEN position LIKE 'ประธานกรรมการ%' THEN 3
        ELSE 9
      END, id
")->fetchAll(PDO::FETCH_ASSOC);

// Edit target
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
$isCreating = isset($_GET['edit']) && $_GET['edit'] === 'new';
if ($editId) {
    foreach ($tpls as $t) { if ((int)$t['id'] === $editId) { $editRow = $t; break; } }
}
$layout = [];
if ($editRow && !empty($editRow['layout_config'])) {
    $layout = json_decode($editRow['layout_config'], true) ?: [];
}
// Defaults for layout
$L = [
    'header' => [
        'show_logo'            => $layout['header']['show_logo']            ?? 1,
        'show_company_address' => $layout['header']['show_company_address'] ?? 1,
        'subtitle_th'          => $layout['header']['subtitle_th']          ?? '',
        'subtitle_en'          => $layout['header']['subtitle_en']          ?? '',
    ],
    'body' => [
        'use_custom_body' => $layout['body']['use_custom_body'] ?? 0,
    ],
    'signatures' => [
        'signer_1_user_id' => $layout['signatures']['signer_1_user_id'] ?? 0,
        'signer_2_user_id' => $layout['signatures']['signer_2_user_id'] ?? 0,
        'show_esignature'  => $layout['signatures']['show_esignature']  ?? 0,
        'show_two_signers' => $layout['signatures']['show_two_signers'] ?? 1,
    ],
    'footer' => [
        'show_qr_verify' => $layout['footer']['show_qr_verify'] ?? 1,
        'show_seal_area' => $layout['footer']['show_seal_area'] ?? 1,
        'extra_note_th'  => $layout['footer']['extra_note_th'] ?? '',
    ],
];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/documents.php" class="hover:text-white touch-manipulation">จัดการคำขอเอกสาร</a>
        <span class="mx-2">/</span>
        <span class="text-white">ตั้งค่าเอกสารรับรอง</span>
    </nav>
    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between gap-y-4">
        <div class="min-w-0 flex-1">
            <h1 class="tp-ios-page-title">ตั้งค่าเอกสารรับรอง</h1>
            <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ข้อมูลบริษัท โลโก้ ตราประทับ ลายเซ็น และเค้าโครงเอกสาร</p>
        </div>
        <?php if (!$editRow && !$isCreating): ?>
        <div class="flex gap-2 shrink-0 w-full sm:w-auto">
            <a href="?edit=new" class="inline-flex items-center justify-center min-h-[56px] px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold touch-manipulation w-full sm:w-auto">
                <i class="fas fa-plus mr-1" aria-hidden="true"></i> เพิ่มเอกสารใหม่
            </a>
        </div>
        <?php endif; ?>
    </div>
</header>

<?php if ($success): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200 text-sm" role="status">
    <i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200 text-sm" role="alert">
    <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if (!$editRow && !$isCreating): ?>
<!-- ======================================================== -->
<!-- COMPANY-WIDE DOCUMENT SETTINGS                            -->
<!-- ======================================================== -->
<details class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] mb-6 overflow-hidden border border-white/10 group" <?php echo empty($settings['company_logo']) ? 'open' : ''; ?>>
    <summary class="p-5 cursor-pointer flex items-center justify-between hover:bg-white/5">
        <div class="flex items-center gap-3">
            <i class="fas fa-building text-violet-400 text-lg"></i>
            <div>
                <h2 class="text-white font-semibold">ข้อมูลบริษัท & ทรัพย์สินดิจิทัล</h2>
                <p class="text-white/50 text-xs">ใช้เป็นค่าเริ่มต้นสำหรับเอกสารทุกฉบับ — โลโก้, ตราประทับ, ชื่อ, ที่อยู่, ติดต่อ</p>
            </div>
        </div>
        <i class="fas fa-chevron-down text-white/40 group-open:rotate-180 transition-transform"></i>
    </summary>
    <div class="p-5 border-t border-white/10">
        <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="save_company">

            <!-- Logo & Seal -->
                <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-5 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10">
                    <label class="block text-white/80 text-sm font-medium mb-2">
                        <i class="fas fa-image mr-1 text-violet-300"></i> โลโก้บริษัท
                    </label>
                    <?php if (!empty($settings['company_logo'])): ?>
                        <div class="bg-white rounded p-3 mb-2 flex justify-center">
                            <img src="<?php echo htmlspecialchars($settings['company_logo']); ?>" alt="logo" style="max-height:80px;">
                        </div>
                    <?php endif; ?>
                    <input type="url" name="company_logo_url" placeholder="URL ภาพ (หรือจะอัปโหลดด้านล่าง)"
                        value="<?php echo htmlspecialchars($settings['company_logo'] ?? ''); ?>"
                        class="input-field tp-native-input w-full mb-2">
                    <input type="file" name="company_logo" accept="image/*" class="w-full text-white/70 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-violet-500 file:text-white file:text-xs">
                </div>
                <div class="p-5 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10">
                    <label class="block text-white/80 text-sm font-medium mb-2">
                        <i class="fas fa-stamp mr-1 text-amber-300"></i> ตราประทับบริษัท (สำหรับพิมพ์ทับลายเซ็น)
                    </label>
                    <?php if (!empty($settings['company_seal'])): ?>
                        <div class="bg-white rounded p-3 mb-2 flex justify-center">
                            <img src="<?php echo htmlspecialchars($settings['company_seal']); ?>" alt="seal" style="max-height:80px;">
                        </div>
                    <?php else: ?>
                        <div class="bg-white/5 border border-dashed border-white/20 rounded p-5 mb-2 text-center text-white/40 text-xs">
                            ยังไม่มีตราประทับ — เอกสารจะเว้นที่ว่างสำหรับประทับจริง
                        </div>
                    <?php endif; ?>
                    <input type="url" name="company_seal_url" placeholder="URL ภาพตรา (PNG โปร่งใส) หรืออัปโหลด"
                        value="<?php echo htmlspecialchars($settings['company_seal'] ?? ''); ?>"
                        class="input-field tp-native-input w-full mb-2">
                    <input type="file" name="company_seal" accept="image/*" class="w-full text-white/70 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-amber-500 file:text-white file:text-xs">
                </div>
            </div>

            <!-- Names -->
            <div>
                <label class="block text-white/70 text-xs mb-1">ชื่อบริษัท (ไทย)</label>
                <input name="company_name" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ชื่อบริษัท (English)</label>
                <input name="company_name_en" value="<?php echo htmlspecialchars($settings['company_name_en'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เลขประจำตัวผู้เสียภาษี</label>
                <input name="company_tax_id" value="<?php echo htmlspecialchars($settings['company_tax_id'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">โทรศัพท์</label>
                <input name="company_phone" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">อีเมล</label>
                <input type="email" name="company_email" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เว็บไซต์</label>
                <input type="url" name="company_website" value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div class="md:col-span-2">
                <label class="block text-white/70 text-xs mb-1">ที่อยู่บริษัท</label>
                <textarea name="company_address" rows="2" class="input-field tp-native-textarea w-full"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
            </div>

            <!-- Document defaults -->
            <div class="md:col-span-2 pt-3 border-t border-white/10">
                <h3 class="text-white/80 text-sm font-medium mb-3"><i class="fas fa-cog mr-1"></i> ค่าเริ่มต้นของเอกสาร</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-white/70 text-xs mb-1">คำบรรยายใต้หัวเอกสาร (ไทย)</label>
                        <input name="doc_header_subtitle_th" placeholder="เช่น ฝ่ายทรัพยากรบุคคล" value="<?php echo htmlspecialchars($settings['doc_header_subtitle_th'] ?? ''); ?>" class="input-field tp-native-input w-full">
                    </div>
                    <div>
                        <label class="block text-white/70 text-xs mb-1">คำบรรยายใต้หัวเอกสาร (English)</label>
                        <input name="doc_header_subtitle_en" placeholder="e.g. Human Resources Department" value="<?php echo htmlspecialchars($settings['doc_header_subtitle_en'] ?? ''); ?>" class="input-field tp-native-input w-full">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-white/70 text-xs mb-1">ข้อความท้ายเอกสารเริ่มต้น</label>
                        <textarea name="doc_footer_note_th" rows="2" placeholder="เช่น เอกสารฉบับนี้ออกโดยระบบ HR อัตโนมัติ สามารถตรวจสอบผ่าน QR Code ได้" class="input-field tp-native-textarea w-full"><?php echo htmlspecialchars($settings['doc_footer_note_th'] ?? ''); ?></textarea>
                    </div>
                    <div class="md:col-span-2 p-3 rounded-[var(--tp-ios-card-radius)] bg-indigo-500/10 border border-indigo-500/30">
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="doc_show_esignature" value="1" <?php echo !empty($settings['doc_show_esignature']) ? 'checked' : ''; ?> class="h-4 w-4 accent-indigo-500 rounded">
                            <span class="ml-2 text-sm text-white/90">เปิดใช้ลายเซ็นอิเล็กทรอนิกส์ทั่วทั้งระบบ <span class="text-white/50 text-xs">(แสดงภาพลายเซ็นของผู้ลงนามในเอกสาร)</span></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="md:col-span-2 flex justify-end">
                <button type="submit" class="px-5 py-2 rounded-[var(--tp-ios-card-radius)] bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium min-h-[48px] inline-flex items-center justify-center">
                    <i class="fas fa-save mr-1"></i> บันทึกข้อมูลบริษัท
                </button>
            </div>
        </form>
    </div>
</details>

<!-- ======================================================== -->
<!-- E-SIGNATURE MANAGEMENT                                    -->
<!-- ======================================================== -->
<details class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] mb-6 overflow-hidden border border-white/10 group">
    <summary class="p-5 cursor-pointer flex items-center justify-between hover:bg-white/5">
        <div class="flex items-center gap-3">
            <i class="fas fa-signature text-indigo-400 text-lg"></i>
            <div>
                <h2 class="text-white font-semibold">ลายเซ็นอิเล็กทรอนิกส์ของผู้บริหาร</h2>
                <p class="text-white/50 text-xs">อัปโหลดภาพลายเซ็น (PNG โปร่งใส) สำหรับผู้ลงนามในเอกสาร</p>
            </div>
        </div>
        <i class="fas fa-chevron-down text-white/40 group-open:rotate-180 transition-transform"></i>
    </summary>
    <div class="p-5 border-t border-white/10 grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($signerCandidates as $sc): ?>
        <div class="p-5 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10">
            <div class="flex items-start justify-between mb-2">
                <div>
                    <p class="text-white font-medium"><?php echo htmlspecialchars(($sc['title'] ?? '') . ($sc['first_name_th'] ?? '') . ' ' . ($sc['last_name_th'] ?? '')); ?></p>
                    <p class="text-white/50 text-xs"><?php echo htmlspecialchars($sc['position'] ?? ''); ?></p>
                </div>
            </div>
            <?php if (!empty($sc['signature_image'])): ?>
                <div class="bg-white rounded p-2 mb-2 flex justify-center">
                    <img src="<?php echo htmlspecialchars($sc['signature_image']); ?>" alt="signature" style="max-height:60px;">
                </div>
                <button type="button" class="text-red-300 hover:text-red-200 text-xs touch-manipulation min-h-[48px] inline-flex items-center gap-1 px-2 touch-manipulation"
                    data-dt-sig-user="<?php echo (int)$sc['id']; ?>"
                    onclick="dtOpenRemoveSignatureModal(this)">
                    <i class="fas fa-trash mr-1" aria-hidden="true"></i>ลบลายเซ็น
                </button>
            <?php else: ?>
                <div class="bg-white/5 border border-dashed border-white/20 rounded p-3 mb-2 text-center text-white/40 text-xs">ยังไม่มีลายเซ็น</div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="mt-2 flex gap-2">
                <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="upload_signature">
                <input type="hidden" name="user_id" value="<?php echo (int)$sc['id']; ?>">
                <input type="file" name="signature" accept="image/png,image/jpeg,image/webp" required class="flex-1 text-white/70 text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-indigo-500 file:text-white file:text-xs">
                <button type="submit" class="px-3 py-1 rounded bg-indigo-500 hover:bg-indigo-600 text-white text-xs whitespace-nowrap min-h-[48px] inline-flex items-center justify-center"><i class="fas fa-upload"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php if (empty($signerCandidates)): ?>
        <p class="md:col-span-2 text-white/50 text-sm text-center py-4">ไม่พบผู้ใช้ในตำแหน่งผู้ลงนาม (ประธาน, CEO, กรรมการผู้จัดการ)</p>
        <?php endif; ?>
    </div>
</details>

<!-- ======================================================== -->
<!-- TEMPLATE LIST                                             -->
<!-- ======================================================== -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden min-w-0 rounded-[var(--tp-ios-card-radius)]">
    <?php if (empty($tpls)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-file-alt text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ยังไม่มีเทมเพลตเอกสาร</p>
        <a href="?edit=new" class="mt-4 inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-4 text-white text-sm font-semibold touch-manipulation">
            <i class="fas fa-plus mr-2" aria-hidden="true"></i>เพิ่มเอกสารแรก
        </a>
    </div>
    <?php else: ?>
    <div class="p-5 border-b border-white/10 flex items-center justify-between">
        <h2 class="text-white font-semibold"><i class="fas fa-file-alt mr-2 text-violet-400" aria-hidden="true"></i>รายการเอกสารในระบบ</h2>
        <a href="?edit=new" class="px-3 py-1.5 rounded-[var(--tp-ios-card-radius)] bg-violet-500 hover:bg-violet-600 text-white text-xs font-medium touch-manipulation"><i class="fas fa-plus mr-1" aria-hidden="true"></i>เพิ่ม</a>
    </div>
    <div class="md:hidden p-5 space-y-4">
        <?php foreach ($tpls as $t): ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5 space-y-3">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-white/50 text-xs">ลำดับ <?= (int)$t['sort_order']; ?> · <span class="font-mono text-violet-300"><?php echo htmlspecialchars($t['code']); ?></span></p>
                    <p class="text-white font-semibold mt-1"><?php echo htmlspecialchars($t['name']); ?></p>
                    <?php if (!empty($t['name_en'])): ?><p class="text-white/40 text-xs mt-0.5"><?php echo htmlspecialchars($t['name_en']); ?></p><?php endif; ?>
                </div>
                <?php if (!empty($t['is_active'])): ?>
                    <span class="shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs bg-green-500/20 text-green-300">เปิด</span>
                <?php else: ?>
                    <span class="shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-500/20 text-gray-400">ปิด</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2 px-1">
                    <div class="text-white/45">หมวด</div>
                    <div class="text-white/85 truncate"><?php echo htmlspecialchars($categories[$t['category']] ?? $t['category']); ?></div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-white/45">ดำเนินการ</div>
                    <div class="text-white font-medium"><?php echo (int)$t['processing_days']; ?> วัน</div>
                </div>
                <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/10 py-2">
                    <div class="text-white/45">ใช้งาน</div>
                    <div class="text-white/80"><?php echo (int)$t['request_count']; ?></div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <a href="?edit=<?php echo (int)$t['id']; ?>" class="min-h-[48px] flex items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 text-sm font-semibold touch-manipulation">
                    <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไข
                </a>
                <form method="POST" class="contents">
                    <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                    <button type="submit" class="w-full min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 text-sm font-semibold touch-manipulation" title="สลับเปิด/ปิด">
                        <i class="fas fa-power-off mr-2" aria-hidden="true"></i>เปิด/ปิด
                    </button>
                </form>
            </div>
            <?php if ((int)$t['request_count'] === 0): ?>
            <button type="button"
                class="w-full min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-red-500/20 hover:bg-red-500/30 text-red-300 text-sm font-semibold touch-manipulation"
                data-dt-delete-id="<?php echo (int)$t['id']; ?>"
                data-dt-delete-name="<?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>"
                onclick="dtOpenDeleteTemplateModal(this)">
                <i class="fas fa-trash mr-2" aria-hidden="true"></i>ลบ
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full" style="min-width:960px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ลำดับ</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">รหัส</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อเอกสาร</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">หมวดหมู่</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ระยะเวลา</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ใช้งาน</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($tpls as $t): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3 text-white/60 text-sm"><?php echo (int)$t['sort_order']; ?></td>
                    <td class="px-4 py-3"><span class="font-mono text-violet-300 text-sm"><?php echo htmlspecialchars($t['code']); ?></span></td>
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($t['name']); ?></p>
                        <?php if (!empty($t['name_en'])): ?><p class="text-white/40 text-xs mt-0.5"><?php echo htmlspecialchars($t['name_en']); ?></p><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white/80 text-sm"><?php echo htmlspecialchars($categories[$t['category']] ?? $t['category']); ?></td>
                    <td class="px-4 py-3 text-center text-white/80 text-sm"><?php echo (int)$t['processing_days']; ?> วัน</td>
                    <td class="px-4 py-3 text-center text-sm"><span class="text-white/70"><?php echo (int)$t['request_count']; ?></span></td>
                    <td class="px-4 py-3 text-center">
                        <?php if (!empty($t['is_active'])): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-500/20 text-green-300">เปิด</span>
                        <?php else: ?>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-500/20 text-gray-400">ปิด</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <a href="?edit=<?php echo (int)$t['id']; ?>" class="inline-block px-2 py-1 rounded-[var(--tp-ios-card-radius)] bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 text-xs touch-manipulation" title="แก้ไข"><i class="fas fa-edit" aria-hidden="true"></i></a>
                        <form method="POST" class="inline-block">
                            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button type="submit" class="px-2 py-1 rounded-[var(--tp-ios-card-radius)] bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 text-xs touch-manipulation min-h-[48px] inline-flex items-center justify-center" title="สลับเปิด/ปิด"><i class="fas fa-power-off" aria-hidden="true"></i></button>
                        </form>
                        <?php if ((int)$t['request_count'] === 0): ?>
                        <button type="button"
                            class="px-2 py-1 rounded-[var(--tp-ios-card-radius)] bg-red-500/20 hover:bg-red-500/30 text-red-300 text-xs touch-manipulation min-h-[48px] inline-flex items-center justify-center"
                            data-dt-delete-id="<?php echo (int)$t['id']; ?>"
                            data-dt-delete-name="<?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            onclick="dtOpenDeleteTemplateModal(this)"
                            title="ลบ"><i class="fas fa-trash" aria-hidden="true"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: /* ================= EDIT MODE ================= */ ?>
<?php
$certPreviewReqId = 0;
if (!empty($editRow['id'])) {
    $prevCertStmt = $pdo->prepare('SELECT id FROM hr_document_requests WHERE template_id=? ORDER BY id DESC LIMIT 1');
    $prevCertStmt->execute([(int)$editRow['id']]);
    $certPreviewReqId = (int)($prevCertStmt->fetchColumn() ?: 0);
}
?>

<!-- Back link -->
<div class="mb-4">
    <a href="document_templates.php" class="text-white/60 hover:text-white text-sm"><i class="fas fa-arrow-left mr-1"></i> กลับไปหน้ารายการ</a>
</div>

<?php if ($certPreviewReqId > 0): ?>
<form id="hr_doc_tpl_cert_preview" method="post" action="/certificate_print.php" target="_blank" class="hidden" aria-hidden="true">
    <?php echo csrfField(); ?>
    <input type="hidden" name="certificate_print" value="1">
    <input type="hidden" name="id" value="<?php echo $certPreviewReqId; ?>">
    <input type="hidden" name="preview" value="1">
</form>
<?php endif; ?>

<form method="POST" class="space-y-5">
    <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="id" value="<?php echo (int)($editRow['id'] ?? 0); ?>">

    <!-- Section 1: Basic -->
    <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] overflow-hidden">
        <div class="p-5 border-b border-white/10 bg-white/5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-violet-500/30 text-violet-200 flex items-center justify-center font-bold">1</div>
            <div>
                <h2 class="text-white font-semibold">ข้อมูลพื้นฐาน</h2>
                <p class="text-white/50 text-xs">รหัส ชื่อ หมวดหมู่ และสถานะ</p>
            </div>
        </div>
        <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-white/70 text-xs mb-1">รหัสเอกสาร <span class="text-red-400">*</span></label>
                <input name="code" required pattern="[A-Z0-9_]+" value="<?php echo htmlspecialchars($editRow['code'] ?? ''); ?>" class="input-field tp-native-input w-full font-mono text-sm">
                <p class="text-white/40 text-xs mt-1">A-Z, 0-9, _ เท่านั้น</p>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">หมวดหมู่</label>
                <select name="category" class="input-field tp-native-select w-full">
                    <?php foreach ($categories as $k=>$lbl): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($editRow['category'] ?? 'CERTIFICATE')===$k?'selected':''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ลำดับ</label>
                <input type="number" name="sort_order" min="0" value="<?php echo (int)($editRow['sort_order'] ?? 0); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ชื่อ (ไทย) <span class="text-red-400">*</span></label>
                <input name="name" required value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ชื่อ (English)</label>
                <input name="name_en" value="<?php echo htmlspecialchars($editRow['name_en'] ?? ''); ?>" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ระยะเวลาดำเนินการ (วัน)</label>
                <input type="number" name="processing_days" min="0" value="<?php echo (int)($editRow['processing_days'] ?? 1); ?>" class="input-field tp-native-input w-full">
            </div>
            <div class="md:col-span-3">
                <label class="block text-white/70 text-xs mb-1">คำอธิบาย</label>
                <textarea name="description" rows="2" class="input-field tp-native-textarea w-full"><?php echo htmlspecialchars($editRow['description'] ?? ''); ?></textarea>
            </div>
            <div class="md:col-span-3 flex gap-6 pt-2">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="requires_approval" value="1" <?php echo !empty($editRow['requires_approval']) ? 'checked' : ''; ?> class="h-4 w-4 accent-amber-500 rounded">
                    <span class="ml-2 text-sm text-white/80">ต้องขออนุมัติก่อนจัดทำ</span>
                </label>
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" <?php echo (!isset($editRow['is_active']) || !empty($editRow['is_active'])) ? 'checked' : ''; ?> class="h-4 w-4 accent-green-500 rounded">
                    <span class="ml-2 text-sm text-white/80">เปิดใช้งานเอกสาร</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Section 2: Header -->
    <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] overflow-hidden">
        <div class="p-5 border-b border-white/10 bg-white/5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-blue-500/30 text-blue-200 flex items-center justify-center font-bold">2</div>
            <div>
                <h2 class="text-white font-semibold">ส่วนหัวเอกสาร (Header)</h2>
                <p class="text-white/50 text-xs">โลโก้ ชื่อบริษัท ที่อยู่ และคำบรรยาย</p>
            </div>
        </div>
        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2 flex gap-6">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="layout[header][show_logo]" value="1" <?php echo !empty($L['header']['show_logo']) ? 'checked' : ''; ?> class="h-4 w-4 accent-blue-500 rounded">
                    <span class="ml-2 text-sm text-white/80">แสดงโลโก้บริษัท</span>
                </label>
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="layout[header][show_company_address]" value="1" <?php echo !empty($L['header']['show_company_address']) ? 'checked' : ''; ?> class="h-4 w-4 accent-blue-500 rounded">
                    <span class="ml-2 text-sm text-white/80">แสดงที่อยู่บริษัท</span>
                </label>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">คำบรรยายใต้หัว (ไทย) — ทับค่ากลาง</label>
                <input name="layout[header][subtitle_th]" value="<?php echo htmlspecialchars($L['header']['subtitle_th']); ?>" placeholder="เว้นว่างเพื่อใช้ค่าเริ่มต้นของบริษัท" class="input-field tp-native-input w-full">
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">คำบรรยายใต้หัว (English)</label>
                <input name="layout[header][subtitle_en]" value="<?php echo htmlspecialchars($L['header']['subtitle_en']); ?>" placeholder="Leave blank to use company default" class="input-field tp-native-input w-full">
            </div>
        </div>
    </div>

    <!-- Section 3: Body -->
    <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] overflow-hidden">
        <div class="p-5 border-b border-white/10 bg-white/5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-emerald-500/30 text-emerald-200 flex items-center justify-center font-bold">3</div>
            <div>
                <h2 class="text-white font-semibold">ส่วนกลาง (เนื้อหาหลัก)</h2>
                <p class="text-white/50 text-xs">ข้อความเนื้อหาและตัวแปรที่ใช้ได้</p>
            </div>
        </div>
        <div class="p-5 space-y-4">
            <label class="flex items-start gap-2 p-3 rounded bg-amber-500/10 border border-amber-500/30 cursor-pointer">
                <input type="checkbox" name="layout[body][use_custom_body]" value="1" <?php echo !empty($L['body']['use_custom_body']) ? 'checked' : ''; ?> class="h-4 w-4 accent-amber-500 rounded mt-0.5">
                <div>
                    <span class="text-sm text-white/90 font-medium">ใช้เนื้อหาแบบกำหนดเอง (Custom Body)</span>
                    <p class="text-white/60 text-xs mt-0.5">เมื่อเปิด: ระบบจะใช้ข้อความด้านล่างแทนเค้าโครงมาตรฐาน — สำหรับเอกสารมาตรฐาน (CERT_WORK, CERT_SALARY, TAX_50TAWI) แนะนำให้ปิดเพื่อใช้ตารางและรูปแบบที่ออกแบบไว้แล้ว</p>
                </div>
            </label>

            <div class="p-3 rounded bg-blue-500/10 border border-blue-500/20">
                <p class="text-blue-200 text-xs font-medium mb-2"><i class="fas fa-code mr-1"></i>ตัวแปรที่ใช้ได้ในเนื้อหา (คลิกคัดลอก):</p>
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach ($placeholderGuide as $ph=>$desc): ?>
                    <button type="button" onclick="navigator.clipboard.writeText('<?php echo $ph; ?>');this.classList.add('bg-green-500/30')" class="min-h-[48px] inline-flex items-center px-3 py-0.5 rounded bg-white/10 hover:bg-white/20 text-white/90 text-xs font-mono" title="<?php echo htmlspecialchars($desc); ?>"><?php echo $ph; ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="block text-white/70 text-xs mb-1">เนื้อหา (ไทย)</label>
                <textarea name="template_th" rows="8" class="input-field tp-native-textarea w-full font-mono text-sm leading-relaxed"><?php echo htmlspecialchars($editRow['template_th'] ?? ''); ?></textarea>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">เนื้อหา (English)</label>
                <textarea name="template_en" rows="8" class="input-field tp-native-textarea w-full font-mono text-sm leading-relaxed"><?php echo htmlspecialchars($editRow['template_en'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <!-- Section 4: Signatures -->
    <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] overflow-hidden">
        <div class="p-5 border-b border-white/10 bg-white/5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-indigo-500/30 text-indigo-200 flex items-center justify-center font-bold">4</div>
            <div>
                <h2 class="text-white font-semibold">ส่วนลงนาม (Signatures)</h2>
                <p class="text-white/50 text-xs">เลือกผู้ลงนามและเปิด/ปิดลายเซ็นอิเล็กทรอนิกส์</p>
            </div>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-white/70 text-xs mb-1">ผู้ลงนามคนที่ 1</label>
                    <select name="layout[signatures][signer_1_user_id]" class="input-field tp-native-select w-full">
                        <option value="0">— ใช้ค่าอัตโนมัติ (ประธานบริษัท) —</option>
                        <?php foreach ($signerCandidates as $sc): ?>
                        <option value="<?php echo (int)$sc['id']; ?>" <?php echo (int)$L['signatures']['signer_1_user_id']===(int)$sc['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars(($sc['title'] ?? '') . ($sc['first_name_th'] ?? '') . ' ' . ($sc['last_name_th'] ?? '') . ' — ' . ($sc['position'] ?? '')); ?>
                            <?php echo !empty($sc['signature_image']) ? ' ✓ มีลายเซ็น' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-white/70 text-xs mb-1">ผู้ลงนามคนที่ 2</label>
                    <select name="layout[signatures][signer_2_user_id]" class="input-field tp-native-select w-full">
                        <option value="0">— ใช้ค่าอัตโนมัติ (CEO) —</option>
                        <?php foreach ($signerCandidates as $sc): ?>
                        <option value="<?php echo (int)$sc['id']; ?>" <?php echo (int)$L['signatures']['signer_2_user_id']===(int)$sc['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars(($sc['title'] ?? '') . ($sc['first_name_th'] ?? '') . ' ' . ($sc['last_name_th'] ?? '') . ' — ' . ($sc['position'] ?? '')); ?>
                            <?php echo !empty($sc['signature_image']) ? ' ✓ มีลายเซ็น' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="layout[signatures][show_two_signers]" value="1" <?php echo !empty($L['signatures']['show_two_signers']) ? 'checked' : ''; ?> class="h-4 w-4 accent-indigo-500 rounded">
                    <span class="ml-2 text-sm text-white/80">แสดง 2 ผู้ลงนาม (ประธาน + CEO)</span>
                </label>
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="layout[signatures][show_esignature]" value="1" <?php echo !empty($L['signatures']['show_esignature']) ? 'checked' : ''; ?> class="h-4 w-4 accent-indigo-500 rounded">
                    <span class="ml-2 text-sm text-white/80">แสดงภาพลายเซ็นอิเล็กทรอนิกส์เหนือเส้นลงนาม</span>
                </label>
            </div>
            <div class="p-3 rounded bg-indigo-500/10 border border-indigo-500/20 text-indigo-200 text-xs">
                <i class="fas fa-info-circle mr-1"></i> อัปโหลดภาพลายเซ็นของผู้บริหารได้ที่ส่วน <strong>"ลายเซ็นอิเล็กทรอนิกส์ของผู้บริหาร"</strong> ในหน้าหลัก
            </div>

            <div class="pt-3 border-t border-white/10">
                <p class="text-white/70 text-xs mb-2">หรือกำหนดผู้ลงนามแบบข้อความอิสระ (ไม่ต้องเลือกจากผู้ใช้):</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-white/70 text-xs mb-1">ชื่อผู้ลงนาม</label>
                        <input name="signatory_name" value="<?php echo htmlspecialchars($editRow['signatory_name'] ?? ''); ?>" class="input-field tp-native-input w-full">
                    </div>
                    <div>
                        <label class="block text-white/70 text-xs mb-1">ตำแหน่ง</label>
                        <input name="signatory_position" value="<?php echo htmlspecialchars($editRow['signatory_position'] ?? ''); ?>" class="input-field tp-native-input w-full">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 5: Footer -->
    <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] overflow-hidden">
        <div class="p-5 border-b border-white/10 bg-white/5 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-rose-500/30 text-rose-200 flex items-center justify-center font-bold">5</div>
            <div>
                <h2 class="text-white font-semibold">ส่วนท้าย (Footer)</h2>
                <p class="text-white/50 text-xs">QR ตรวจสอบ พื้นที่ประทับตรา และข้อความเพิ่ม</p>
            </div>
        </div>
        <div class="p-5 space-y-4">
            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="layout[footer][show_qr_verify]" value="1" <?php echo !empty($L['footer']['show_qr_verify']) ? 'checked' : ''; ?> class="h-4 w-4 accent-rose-500 rounded">
                    <span class="ml-2 text-sm text-white/80">แสดง QR Code ตรวจสอบเอกสาร</span>
                </label>
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="layout[footer][show_seal_area]" value="1" <?php echo !empty($L['footer']['show_seal_area']) ? 'checked' : ''; ?> class="h-4 w-4 accent-rose-500 rounded">
                    <span class="ml-2 text-sm text-white/80">แสดงพื้นที่ประทับตราบริษัท</span>
                </label>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">ข้อความท้ายเอกสาร (footer_text)</label>
                <textarea name="footer_text" rows="2" class="input-field tp-native-textarea w-full"><?php echo htmlspecialchars($editRow['footer_text'] ?? ''); ?></textarea>
            </div>
            <div>
                <label class="block text-white/70 text-xs mb-1">หมายเหตุเพิ่มเติมใต้ footer</label>
                <textarea name="layout[footer][extra_note_th]" rows="2" class="input-field tp-native-textarea w-full"><?php echo htmlspecialchars($L['footer']['extra_note_th']); ?></textarea>
            </div>
        </div>
    </div>

    <!-- Action bar -->
    <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] overflow-hidden border border-white/10 p-5 flex items-center justify-between sticky bottom-4 z-20 shadow-lg">
        <div class="text-white/60 text-xs">
            <?php if ($editRow && !empty($editRow['updated_at'])): ?>
                อัปเดตล่าสุด: <?php echo htmlspecialchars($editRow['updated_at']); ?>
            <?php endif; ?>
        </div>
        <div class="flex gap-2">
            <a href="document_templates.php" class="px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-white/10 text-white/80 hover:bg-white/20 text-sm">ยกเลิก</a>
            <?php if ($editRow && $certPreviewReqId > 0): ?>
                <button type="submit" form="hr_doc_tpl_cert_preview" class="px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-violet-500/20 hover:bg-violet-500/30 text-violet-200 text-sm min-h-[48px] inline-flex items-center justify-center">
                    <i class="fas fa-print mr-1"></i> ดูตัวอย่าง
                </button>
            <?php endif; ?>
            <button type="submit" class="px-6 py-2 rounded-[var(--tp-ios-card-radius)] bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium min-h-[48px] inline-flex items-center justify-center">
                <i class="fas fa-save mr-1"></i> บันทึกทั้งหมด
            </button>
        </div>
    </div>
</form>

<?php endif; ?>

</div>

<!-- Confirm delete template -->
<div id="dt-delete-template-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="dt-delete-template-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="dt-delete-template-title" class="text-xl font-bold text-white mb-2">ยืนยันการลบเทมเพลต</h3>
        <p class="text-white/70 text-sm mb-6">ต้องการลบเอกสาร <strong id="dt-delete-template-name" class="text-white font-semibold"></strong> หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้</p>
        <form method="POST" action="document_templates.php">
            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="id" id="dt-delete-template-id" value="">
            <div class="flex flex-wrap gap-2 justify-end">
                <button type="button" class="px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-white/10 text-white/90 hover:bg-white/20 text-sm touch-manipulation min-h-[48px] inline-flex items-center justify-center" onclick="dtCloseDeleteTemplateModal()">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-red-600 hover:bg-red-700 text-white text-sm font-medium touch-manipulation min-h-[48px] inline-flex items-center justify-center">ลบเทมเพลต</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm remove signature -->
<div id="dt-remove-signature-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="dt-remove-signature-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="dt-remove-signature-title" class="text-xl font-bold text-white mb-2">ลบลายเซ็นนี้?</h3>
        <p class="text-white/70 text-sm mb-6">ภาพลายเซ็นจะถูกนำออกจากบัญชีผู้ลงนามรายนี้</p>
        <form method="POST" action="document_templates.php" id="dt-remove-signature-form">
            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="remove_signature">
            <input type="hidden" name="user_id" id="dt-remove-signature-user-id" value="">
            <div class="flex flex-wrap gap-2 justify-end">
                <button type="button" class="px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-white/10 text-white/90 hover:bg-white/20 text-sm touch-manipulation min-h-[48px] inline-flex items-center justify-center" onclick="dtCloseRemoveSignatureModal()">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-red-600 hover:bg-red-700 text-white text-sm font-medium touch-manipulation min-h-[48px] inline-flex items-center justify-center">ลบลายเซ็น</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function showModal(el) {
        if (!el) return;
        if (typeof uiOpenModal === 'function') {
            uiOpenModal(el.id);
            return;
        }
        el.classList.remove('hidden');
        el.classList.add('flex');
        document.documentElement.style.overflow = 'hidden';
    }
    function hideModal(el) {
        if (!el) return;
        if (typeof uiCloseModal === 'function') {
            uiCloseModal(el.id);
            return;
        }
        el.classList.add('hidden');
        el.classList.remove('flex');
        document.documentElement.style.overflow = '';
    }
    window.dtOpenDeleteTemplateModal = function (btn) {
        var id = btn.getAttribute('data-dt-delete-id');
        var name = btn.getAttribute('data-dt-delete-name') || '';
        document.getElementById('dt-delete-template-id').value = id || '';
        document.getElementById('dt-delete-template-name').textContent = name;
        showModal(document.getElementById('dt-delete-template-modal'));
    };
    window.dtCloseDeleteTemplateModal = function () {
        hideModal(document.getElementById('dt-delete-template-modal'));
    };
    window.dtOpenRemoveSignatureModal = function (btn) {
        var uid = btn.getAttribute('data-dt-sig-user');
        document.getElementById('dt-remove-signature-user-id').value = uid || '';
        showModal(document.getElementById('dt-remove-signature-modal'));
    };
    window.dtCloseRemoveSignatureModal = function () {
        hideModal(document.getElementById('dt-remove-signature-modal'));
    };
    ['dt-delete-template-modal', 'dt-remove-signature-modal'].forEach(function (mid) {
        var m = document.getElementById(mid);
        if (m) {
            m.addEventListener('click', function (e) {
                if (e.target === m) hideModal(m);
            });
        }
    });
})();
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
