<?php
/**
 * HR Document Templates Management
 * ตั้งค่าและแก้ไขเอกสารรับรองต่าง ๆ
 * Access: HR + CEO + Chairman + Admin only (isHR gate)
 */

$page_title = 'ตั้งค่าเอกสารรับรอง';
$current_page = 'hr-document-templates';

require_once dirname(__DIR__) . '/bootstrap.php';
Auth::requireLogin();
$user = Auth::user();

if (!isHR()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();
$success = null; $error = null;

// ------------------------------------------------------------------
// Handle POST actions (create / update / toggle-active / delete)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'โทเค็นความปลอดภัยไม่ถูกต้อง กรุณาลองอีกครั้ง';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save') {
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
                $footer_text = trim($_POST['footer_text'] ?? '');

                if ($code === '' || $name === '') {
                    throw new Exception('กรุณากรอกรหัสและชื่อเอกสาร');
                }
                if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
                    throw new Exception('รหัสเอกสารต้องเป็นตัวอักษรภาษาอังกฤษพิมพ์ใหญ่ ตัวเลข หรือขีดล่างเท่านั้น');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE hr_document_templates
                        SET code=?, name=?, name_en=?, category=?, description=?,
                            processing_days=?, requires_approval=?, is_active=?, sort_order=?, footer_text=?,
                            updated_at=NOW()
                        WHERE id=?");
                    $stmt->execute([$code, $name, $name_en, $category, $description,
                        $processing_days, $requires_approval, $is_active, $sort_order, $footer_text, $id]);
                    $success = 'อัปเดตเอกสารเรียบร้อยแล้ว';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO hr_document_templates
                        (code, name, name_en, category, description, processing_days, requires_approval,
                         is_active, sort_order, footer_text, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
                    $stmt->execute([$code, $name, $name_en, $category, $description,
                        $processing_days, $requires_approval, $is_active, $sort_order, $footer_text]);
                    $success = 'เพิ่มเอกสารใหม่เรียบร้อยแล้ว';
                }
            }
            elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE hr_document_templates SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?")->execute([$id]);
                $success = 'เปลี่ยนสถานะเรียบร้อยแล้ว';
            }
            elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                // Safety: refuse if there are requests using this template
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM hr_document_requests WHERE template_id=?");
                $cnt->execute([$id]);
                if ((int)$cnt->fetchColumn() > 0) {
                    throw new Exception('ไม่สามารถลบได้ เนื่องจากมีคำขอเอกสารที่ใช้เทมเพลตนี้อยู่ — กรุณาปิดใช้งานแทน');
                }
                $pdo->prepare("DELETE FROM hr_document_templates WHERE id=?")->execute([$id]);
                $success = 'ลบเอกสารเรียบร้อยแล้ว';
            }
            elseif ($action === 'reorder') {
                $order = json_decode($_POST['order'] ?? '[]', true);
                if (is_array($order)) {
                    $stmt = $pdo->prepare("UPDATE hr_document_templates SET sort_order=?, updated_at=NOW() WHERE id=?");
                    foreach ($order as $i => $tplId) { $stmt->execute([$i + 1, (int)$tplId]); }
                    $success = 'จัดลำดับใหม่เรียบร้อยแล้ว';
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------
// Fetch list
// ------------------------------------------------------------------
$tpls = $pdo->query("
    SELECT dt.*,
        (SELECT COUNT(*) FROM hr_document_requests dr WHERE dr.template_id = dt.id) AS request_count
    FROM hr_document_templates dt
    ORDER BY dt.is_active DESC, dt.sort_order ASC, dt.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$categories = [
    'CERTIFICATE' => 'หนังสือรับรอง',
    'CONTRACT'    => 'สัญญา',
    'LETTER'      => 'จดหมาย',
    'FORM'        => 'แบบฟอร์ม',
    'OTHER'       => 'อื่น ๆ',
];

// Edit target (?edit=ID)
$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId) {
    foreach ($tpls as $t) { if ((int)$t['id'] === $editId) { $editRow = $t; break; } }
}

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <a href="/hr/documents.php" class="hover:text-white">จัดการเอกสาร</a>
        <span class="mx-2">/</span>
        <span class="text-white">ตั้งค่าเอกสารรับรอง</span>
    </nav>
    <div class="flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-white">ตั้งค่าเอกสารรับรอง</h1>
            <p class="text-white/50 text-sm mt-1">จัดการประเภท ชื่อ รหัส และสถานะของหนังสือรับรองที่ระบบ HR รองรับ</p>
        </div>
        <div class="flex gap-2">
            <a href="/hr/documents.php" class="px-4 py-2 rounded-lg bg-white/10 text-white/80 hover:bg-white/20 text-sm">
                <i class="fas fa-arrow-left mr-1"></i> กลับ
            </a>
            <a href="?edit=new" class="px-4 py-2 rounded-lg bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium">
                <i class="fas fa-plus mr-1"></i> เพิ่มเอกสารใหม่
            </a>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="mb-4 p-3 rounded-lg bg-green-500/15 border border-green-500/30 text-green-300 text-sm">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 p-3 rounded-lg bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($editId > 0 || isset($_GET['edit'])): ?>
<!-- ========== Edit / Create form ========== -->
<div class="glass-card rounded-xl p-6 mb-6 border border-violet-500/30">
    <h2 class="text-lg font-semibold text-white mb-4">
        <i class="fas fa-<?php echo $editRow ? 'edit' : 'plus-circle'; ?> mr-2 text-violet-400"></i>
        <?php echo $editRow ? 'แก้ไขเอกสาร' : 'เพิ่มเอกสารใหม่'; ?>
    </h2>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($editRow['id'] ?? 0); ?>">

        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-white/70 text-sm mb-1">รหัสเอกสาร (Code) <span class="text-red-400">*</span></label>
                <input name="code" required pattern="[A-Z0-9_]+" placeholder="เช่น CERT_WORK_TH"
                    value="<?php echo htmlspecialchars($editRow['code'] ?? ''); ?>"
                    class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-violet-400">
                <p class="text-white/40 text-xs mt-1">A-Z, 0-9, _ เท่านั้น — ระบบใช้รหัสนี้ในการเลือกเทมเพลตพิมพ์</p>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">หมวดหมู่</label>
                <select name="category" class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400">
                    <?php foreach ($categories as $k=>$lbl): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($editRow['category'] ?? 'CERTIFICATE')===$k?'selected':''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-white/70 text-sm mb-1">ลำดับแสดงผล</label>
                <input type="number" name="sort_order" min="0" value="<?php echo (int)($editRow['sort_order'] ?? 0); ?>"
                    class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400">
            </div>
        </div>

        <div>
            <label class="block text-white/70 text-sm mb-1">ชื่อเอกสาร (ภาษาไทย) <span class="text-red-400">*</span></label>
            <input name="name" required value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>"
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400">
        </div>
        <div>
            <label class="block text-white/70 text-sm mb-1">ชื่อเอกสาร (English)</label>
            <input name="name_en" value="<?php echo htmlspecialchars($editRow['name_en'] ?? ''); ?>"
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400">
        </div>

        <div class="md:col-span-2">
            <label class="block text-white/70 text-sm mb-1">คำอธิบาย</label>
            <textarea name="description" rows="2"
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400"><?php echo htmlspecialchars($editRow['description'] ?? ''); ?></textarea>
        </div>

        <div class="md:col-span-2">
            <label class="block text-white/70 text-sm mb-1">ข้อความท้ายเอกสาร (footer_text)</label>
            <textarea name="footer_text" rows="2" placeholder="ข้อความเพิ่มเติมที่ปรากฏที่ด้านท้ายของเอกสาร (ทางเลือก)"
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400"><?php echo htmlspecialchars($editRow['footer_text'] ?? ''); ?></textarea>
        </div>

        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-white/70 text-sm mb-1">ระยะเวลาดำเนินการ (วัน)</label>
                <input type="number" name="processing_days" min="0" value="<?php echo (int)($editRow['processing_days'] ?? 1); ?>"
                    class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-violet-400">
            </div>
            <div class="flex items-center gap-3 pt-5">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="requires_approval" value="1" class="form-checkbox h-4 w-4 text-violet-500 rounded border-white/20 bg-white/5"
                        <?php echo !empty($editRow['requires_approval']) ? 'checked' : ''; ?>>
                    <span class="ml-2 text-sm text-white/80">ต้องขออนุมัติก่อน</span>
                </label>
            </div>
            <div class="flex items-center gap-3 pt-5">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" class="form-checkbox h-4 w-4 text-green-500 rounded border-white/20 bg-white/5"
                        <?php echo (!isset($editRow['is_active']) || !empty($editRow['is_active'])) ? 'checked' : ''; ?>>
                    <span class="ml-2 text-sm text-white/80">เปิดใช้งาน</span>
                </label>
            </div>
        </div>

        <div class="md:col-span-2 flex justify-end gap-2 pt-3 border-t border-white/10">
            <a href="document_templates.php" class="px-4 py-2 rounded-lg bg-white/10 text-white/80 hover:bg-white/20 text-sm">ยกเลิก</a>
            <button type="submit" class="px-4 py-2 rounded-lg bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium">
                <i class="fas fa-save mr-1"></i> บันทึก
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ========== List ========== -->
<div class="glass-card rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ลำดับ</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">รหัส</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อเอกสาร</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">หมวดหมู่</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ระยะเวลา</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ใช้งานแล้ว</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($tpls as $t): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3 text-white/60 text-sm"><?php echo (int)$t['sort_order']; ?></td>
                    <td class="px-4 py-3">
                        <span class="font-mono text-violet-300 text-sm"><?php echo htmlspecialchars($t['code']); ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($t['name']); ?></p>
                        <?php if (!empty($t['name_en'])): ?>
                            <p class="text-white/40 text-xs mt-0.5"><?php echo htmlspecialchars($t['name_en']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white/80 text-sm">
                        <?php echo htmlspecialchars($categories[$t['category']] ?? $t['category']); ?>
                    </td>
                    <td class="px-4 py-3 text-center text-white/80 text-sm">
                        <?php echo (int)$t['processing_days']; ?> วัน
                        <?php if (!empty($t['requires_approval'])): ?>
                            <br><span class="text-amber-300 text-xs"><i class="fas fa-gavel mr-0.5"></i>ต้องอนุมัติ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-sm">
                        <span class="text-white/70"><?php echo (int)$t['request_count']; ?></span>
                        <?php if ((int)$t['request_count'] > 0): ?>
                            <span class="text-white/40 text-xs block">คำขอ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (!empty($t['is_active'])): ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs bg-green-500/20 text-green-300">
                                <i class="fas fa-check-circle mr-1"></i>เปิดใช้งาน
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs bg-gray-500/20 text-gray-400">
                                <i class="fas fa-pause-circle mr-1"></i>ปิดใช้งาน
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <a href="?edit=<?php echo (int)$t['id']; ?>" class="inline-block px-2 py-1 rounded bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 text-xs" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" class="inline-block" onsubmit="return confirm('ต้องการ<?php echo !empty($t['is_active'])?'ปิด':'เปิด'; ?>ใช้งาน \'<?php echo htmlspecialchars(addslashes($t['name'])); ?>\' ใช่หรือไม่?');">
                            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button type="submit" class="px-2 py-1 rounded bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 text-xs" title="<?php echo !empty($t['is_active'])?'ปิดใช้งาน':'เปิดใช้งาน'; ?>">
                                <i class="fas fa-power-off"></i>
                            </button>
                        </form>
                        <?php if ((int)$t['request_count'] === 0): ?>
                        <form method="POST" class="inline-block" onsubmit="return confirm('ต้องการลบเอกสาร \'<?php echo htmlspecialchars(addslashes($t['name'])); ?>\' ใช่หรือไม่? (ลบถาวร)');">
                            <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                            <button type="submit" class="px-2 py-1 rounded bg-red-500/20 hover:bg-red-500/30 text-red-300 text-xs" title="ลบ">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <a href="/certificate_print.php?id=<?php
                            // Pick an existing request using this template to preview
                            $pick = $pdo->prepare("SELECT id FROM hr_document_requests WHERE template_id=? ORDER BY id DESC LIMIT 1");
                            $pick->execute([$t['id']]);
                            echo (int)($pick->fetchColumn() ?: 0);
                        ?>&preview=1" target="_blank"
                           class="inline-block px-2 py-1 rounded bg-violet-500/20 hover:bg-violet-500/30 text-violet-300 text-xs" title="ดูตัวอย่างการพิมพ์">
                            <i class="fas fa-print"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tpls)): ?>
                <tr><td colspan="8" class="px-4 py-10 text-center text-white/50">ยังไม่มีเทมเพลตเอกสาร</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4 p-3 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-300 text-xs leading-relaxed">
    <i class="fas fa-info-circle mr-1"></i>
    <strong>หมายเหตุ:</strong> รหัสเอกสาร (code) เป็นตัวระบุที่ระบบใช้เลือกเค้าโครงการพิมพ์ — เฉพาะรหัสที่อยู่ในรายการต่อไปนี้เท่านั้นที่จะมีเค้าโครงพิมพ์เฉพาะ:
    <span class="font-mono text-white">CERT_WORK_TH, CERT_WORK_EN, CERT_SALARY_TH, CERT_SALARY_EN, CERT_SALARY_BANK, TAX_50TAWI</span>
    หากเพิ่มเอกสารใหม่ด้วยรหัสอื่น ระบบจะไม่สามารถพิมพ์ออกในรูปแบบเฉพาะได้จนกว่าจะมีการเพิ่มเค้าโครงในโค้ด <span class="font-mono text-white">certificate_print.php</span>
</div>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
