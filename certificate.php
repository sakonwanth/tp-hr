<?php
/**
 * Certificate Request Page
 * ขอหนังสือรับรอง
 */

$page_title = 'ขอหนังสือรับรอง';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();
$current_page = 'certificate';

$pdo = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? '';

// Get document templates
$stmtTemplates = $pdo->query("SELECT * FROM hr_document_templates WHERE is_active = 1 ORDER BY sort_order");
$templates = $stmtTemplates->fetchAll();

// Get my requests
$stmt = $pdo->prepare("
    SELECT dr.*, dt.name as template_name, dt.description as template_desc,
           id.document_path as file_path, id.document_date as issued_date, id.issued_by,
           issuer.first_name_th as issuer_first, issuer.last_name_th as issuer_last
    FROM hr_document_requests dr
    JOIN hr_document_templates dt ON dr.template_id = dt.id
    LEFT JOIN hr_issued_documents id ON dr.id = id.request_id
    LEFT JOIN users issuer ON id.issued_by = issuer.id
    WHERE dr.user_id = ?
    ORDER BY dr.created_at DESC
    LIMIT 20
");
$stmt->execute([$user['id']]);
$myRequests = $stmt->fetchAll();

// Count pending
$stmtPending = $pdo->prepare("SELECT COUNT(*) FROM hr_document_requests WHERE user_id = ? AND status = 'PENDING'");
$stmtPending->execute([$user['id']]);
$pendingCount = $stmtPending->fetchColumn();

include 'templates/header.php';
?>

<div class="tp-certificate-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <h1 class="tp-ios-page-title">ขอหนังสือรับรอง</h1>
    <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">ขอเอกสารรับรองการทำงาน หนังสือรับรองเงินเดือน และอื่นๆ ติดตามสถานะและดาวน์โหลดได้จากประวัติด้านล่าง</p>
</header>

<?php if ($action === 'new' || $action === 'request'): ?>
<!-- New Request Form -->
<div class="mb-6 min-w-0">
    <nav class="text-sm text-white/60 mb-1" aria-label="Breadcrumb">
        <a href="certificate.php" class="hover:text-white touch-manipulation">ขอหนังสือรับรอง</a>
        <span class="mx-2">/</span>
        <span class="text-white">ยื่นคำขอใหม่</span>
    </nav>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 min-w-0 max-w-full">
    <div class="xl:col-span-2 min-w-0">
        <form id="certificate-form" class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0" method="POST" action="/api/certificate.php">
            <input type="hidden" name="action" value="create">
            <?php echo csrfField(); ?>
            
            <!-- Document Type -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">ประเภทเอกสาร <span class="text-red-400">*</span></label>
                <select name="template_id" id="template_id" required class="input-field tp-native-select" onchange="updateTemplateInfo()">
                    <option value="">-- เลือกประเภทเอกสาร --</option>
                    <?php foreach ($templates as $tpl): ?>
                    <option value="<?php echo $tpl['id']; ?>" 
                            data-processing="<?php echo $tpl['processing_days']; ?>"
                            data-desc="<?php echo htmlspecialchars($tpl['description'] ?? ''); ?>">
                        <?php echo htmlspecialchars($tpl['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p id="template-info" class="text-white/50 text-sm mt-2 hidden"></p>
            </div>
            
            <!-- Language -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">ภาษา</label>
                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-3">
                    <label class="flex items-center min-h-[48px] cursor-pointer touch-manipulation rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/5 px-4 has-[:checked]:border-violet-500/50 has-[:checked]:bg-violet-500/10">
                        <input type="radio" name="language" value="TH" checked class="mr-2 accent-violet-500">
                        <span class="text-white">ภาษาไทย</span>
                    </label>
                    <label class="flex items-center min-h-[48px] cursor-pointer touch-manipulation rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/5 px-4 has-[:checked]:border-violet-500/50 has-[:checked]:bg-violet-500/10">
                        <input type="radio" name="language" value="EN" class="mr-2 accent-violet-500">
                        <span class="text-white">ภาษาอังกฤษ</span>
                    </label>
                    <label class="flex items-center min-h-[48px] cursor-pointer touch-manipulation rounded-[var(--tp-ios-card-radius)] border border-white/10 bg-white/5 px-4 has-[:checked]:border-violet-500/50 has-[:checked]:bg-violet-500/10">
                        <input type="radio" name="language" value="BOTH" class="mr-2 accent-violet-500">
                        <span class="text-white">ทั้งสองภาษา</span>
                    </label>
                </div>
            </div>
            
            <!-- Number of Copies -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">จำนวนฉบับ</label>
                <input type="number" name="copies" value="1" min="1" max="10" class="input-field tp-native-input w-32">
            </div>
            
            <!-- Purpose -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">วัตถุประสงค์การใช้งาน <span class="text-red-400">*</span></label>
                <select name="purpose" id="purpose" required class="input-field tp-native-select" onchange="toggleCustomPurpose()">
                    <option value="">-- เลือกวัตถุประสงค์ --</option>
                    <option value="VISA">ขอวีซ่า / เดินทางไปต่างประเทศ</option>
                    <option value="BANK">ติดต่อธนาคาร / สินเชื่อ</option>
                    <option value="STUDY">สมัครเรียนต่อ</option>
                    <option value="JOB">สมัครงาน</option>
                    <option value="COURT">ติดต่อราชการ / ศาล</option>
                    <option value="OTHER">อื่นๆ</option>
                </select>
            </div>
            
            <!-- Custom Purpose -->
            <div class="tp-native-form-group hidden" id="custom-purpose-div">
                <label class="block text-white/80 text-sm font-medium mb-2">ระบุวัตถุประสงค์</label>
                <input type="text" name="purpose_detail" class="input-field tp-native-input" placeholder="กรุณาระบุวัตถุประสงค์">
            </div>
            
            <!-- Additional Notes -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">หมายเหตุเพิ่มเติม</label>
                <textarea name="notes" rows="3" class="input-field tp-native-textarea" placeholder="รายละเอียดเพิ่มเติมที่ต้องการระบุในเอกสาร (ถ้ามี)"></textarea>
            </div>
            
            <!-- Rush Request -->
            <div class="tp-native-form-group p-4 rounded-[var(--tp-ios-card-radius)] bg-amber-500/10 border border-amber-400/35">
                <label class="flex items-start gap-3 min-h-[48px] cursor-pointer touch-manipulation">
                    <input type="checkbox" name="is_urgent" value="1" class="mt-1 accent-yellow-500 shrink-0">
                    <div>
                        <span class="text-white font-medium">ขอเร่งด่วน</span>
                        <p class="text-white/60 text-sm">ต้องการใช้เอกสารภายใน 1-2 วันทำการ (อาจมีค่าธรรมเนียมเพิ่มเติม)</p>
                    </div>
                </label>
            </div>
            
            <!-- Buttons -->
            <div class="flex flex-col-reverse sm:flex-row gap-3 sm:gap-4 pt-2">
                <a href="certificate.php" class="touch-manipulation flex-1 min-h-[52px] inline-flex items-center justify-center py-3 bg-white/10 hover:bg-white/20 text-white text-center rounded-[var(--tp-ios-card-radius)] transition-colors font-medium">
                    ยกเลิก
                </a>
                <button type="submit" class="touch-manipulation flex-1 min-h-[56px] inline-flex items-center justify-center gap-2 py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors font-semibold border-0">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i><span>ส่งคำขอ</span>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Info -->
    <div class="space-y-6 min-w-0">
        <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
            <h3 class="section-title mb-4 flex flex-wrap items-center gap-2">
                <i class="fas fa-info-circle text-blue-400 text-2xl" aria-hidden="true"></i>
                ข้อมูลการขอหนังสือรับรอง
            </h3>
            <div class="space-y-3 text-sm text-white/70">
                <p class="flex gap-2"><i class="fas fa-clock text-violet-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>ระยะเวลาดำเนินการ 3-5 วันทำการ</span></p>
                <p class="flex gap-2"><i class="fas fa-file-alt text-green-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>เอกสารจะจัดส่งทาง E-mail หรือรับที่ HR</span></p>
                <p class="flex gap-2"><i class="fas fa-phone text-amber-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>สอบถามเพิ่มเติม ติดต่อ HR</span></p>
            </div>
        </div>
        
        <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
            <h3 class="section-title mb-4 flex flex-wrap items-center gap-2">
                <i class="fas fa-list-alt text-amber-400 text-2xl" aria-hidden="true"></i>
                ประเภทเอกสารที่ขอได้
            </h3>
            <div class="space-y-2">
                <?php foreach ($templates as $tpl): ?>
                <div class="tp-ios-attendance-panel py-3 px-3">
                    <p class="text-white font-medium"><?php echo htmlspecialchars($tpl['name']); ?></p>
                    <?php if ($tpl['description']): ?>
                    <p class="text-white/50 text-sm"><?php echo htmlspecialchars($tpl['description']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function updateTemplateInfo() {
    const select = document.getElementById('template_id');
    const option = select.options[select.selectedIndex];
    const infoEl = document.getElementById('template-info');
    
    if (!option.value) {
        infoEl.classList.add('hidden');
        return;
    }
    
    const processing = option.dataset.processing;
    const desc = option.dataset.desc;
    
    let info = `ระยะเวลาดำเนินการประมาณ ${processing} วันทำการ`;
    if (desc) info += ` | ${desc}`;
    
    infoEl.textContent = info;
    infoEl.classList.remove('hidden');
}

function toggleCustomPurpose() {
    const purpose = document.getElementById('purpose').value;
    const customDiv = document.getElementById('custom-purpose-div');
    
    if (purpose === 'OTHER') {
        customDiv.classList.remove('hidden');
    } else {
        customDiv.classList.add('hidden');
    }
}

// Form submission
document.getElementById('certificate-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังส่งคำขอ...';
    
    try {
        const response = await fetch('/api/certificate.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('ส่งคำขอเรียบร้อยแล้ว', 'success');
            setTimeout(() => window.location.href = 'certificate.php', 1500);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่งคำขอ';
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่งคำขอ';
    }
});
</script>

<?php else: ?>
<!-- Request List View -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 min-w-0 max-w-full">
    <a href="certificate.php?action=new" class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0 hover:bg-white/[0.07] transition-colors group touch-manipulation border-violet-500/20">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-violet-600/20 flex items-center justify-center group-hover:bg-violet-600/30 transition-colors">
                <i class="fas fa-plus text-violet-400 text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <h3 class="text-white font-medium">ขอหนังสือรับรองใหม่</h3>
                <p class="text-white/60 text-sm">ยื่นคำขอเอกสาร</p>
            </div>
        </div>
    </a>
    
    <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-amber-500/20 flex items-center justify-center">
                <i class="fas fa-hourglass-half text-amber-400 text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-white/60 text-sm">รอดำเนินการ</p>
                <p class="text-2xl font-bold text-white"><?php echo $pendingCount; ?> <span class="text-sm font-normal text-white/60">คำขอ</span></p>
            </div>
        </div>
    </div>
    
    <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-emerald-500/20 flex items-center justify-center">
                <i class="fas fa-check-circle text-emerald-400 text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-white/60 text-sm">ดำเนินการแล้ว</p>
                <p class="text-2xl font-bold text-white">
                    <?php
                    $doneDocStatuses = ['READY', 'DELIVERED', 'COMPLETED'];
                    echo count(array_filter($myRequests, fn($r) => in_array($r['status'], $doneDocStatuses, true)));
                    ?>
                    <span class="text-sm font-normal text-white/60">คำขอ</span>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Request History -->
<div class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-hidden">
    <div class="p-4 sm:p-5 border-b border-white/10">
        <h2 class="section-title mb-0 flex flex-wrap items-center gap-2 text-white">
            <i class="fas fa-file-signature text-violet-400 text-2xl" aria-hidden="true"></i>
            ประวัติการขอหนังสือรับรอง
        </h2>
    </div>
    
    <?php if (empty($myRequests)): ?>
    <div class="tp-native-empty-state text-center py-12 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-4 my-4">
        <i class="fas fa-file-signature text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ยังไม่มีประวัติการขอหนังสือรับรอง</p>
        <a href="certificate.php?action=new" class="inline-flex mt-4 min-h-[56px] items-center justify-center px-6 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors touch-manipulation font-semibold">
            ขอหนังสือรับรองใหม่
        </a>
    </div>
    <?php else: ?>
    <?php
    $statusColors = [
        'PENDING' => 'bg-yellow-500/20 text-yellow-400',
        'PROCESSING' => 'bg-blue-500/20 text-blue-400',
        'READY' => 'bg-green-500/20 text-green-400',
        'DELIVERED' => 'bg-emerald-500/20 text-emerald-300',
        'COMPLETED' => 'bg-green-500/20 text-green-400',
        'REJECTED' => 'bg-red-500/20 text-red-400',
        'CANCELLED' => 'bg-gray-500/20 text-gray-400'
    ];
    $statusText = [
        'PENDING' => 'รอดำเนินการ',
        'PROCESSING' => 'กำลังดำเนินการ',
        'READY' => 'จัดทำแล้ว',
        'DELIVERED' => 'รับแล้ว',
        'COMPLETED' => 'เสร็จสิ้น',
        'REJECTED' => 'ไม่อนุมัติ',
        'CANCELLED' => 'ยกเลิก'
    ];
    ?>
    <div class="space-y-6 p-3 md:p-4 min-w-0">
        <?php foreach ($myRequests as $req): ?>
        <div class="tp-ios-attendance-panel p-4 md:p-5 hover:bg-white/[0.07] transition-colors min-w-0">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 min-w-0">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-violet-600/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-alt text-violet-400 text-xl" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-white font-medium"><?php echo htmlspecialchars($req['template_name']); ?></h3>
                        <p class="text-white/60 text-sm break-words">
                            เลขที่: <?php echo htmlspecialchars($req['request_number']); ?> |
                            ขอเมื่อ <?php echo formatDateThai($req['created_at']); ?>
                        </p>
                        <?php if ($req['is_urgent']): ?>
                        <span class="inline-block mt-1 px-2 py-0.5 bg-amber-500/20 text-amber-300 text-xs rounded-[var(--tp-ios-card-radius)] border border-amber-500/30">ด่วน</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex flex-col gap-2 w-full md:w-auto md:items-end md:shrink-0">
                    <span class="px-3 py-1 rounded-[var(--tp-ios-card-radius)] text-xs font-medium border border-white/10 self-start <?php echo $statusColors[$req['status']] ?? 'bg-gray-500/20 text-gray-400'; ?>">
                        <?php echo htmlspecialchars($statusText[$req['status']] ?? $req['status']); ?>
                    </span>
                    <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                    <?php if (in_array($req['status'], ['PROCESSING','READY','DELIVERED','COMPLETED'], true)): ?>
                    <?php
                    tpHrCertificatePrintForm(
                        (int)$req['id'],
                        'inline-flex w-full sm:w-auto min-w-0',
                        'min-h-[56px] inline-flex items-center justify-center px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors text-sm font-semibold touch-manipulation w-full sm:w-auto border-0',
                        '<i class="fas fa-print mr-2" aria-hidden="true"></i>ดู / พิมพ์',
                        true
                    );
                    ?>
                    <?php endif; ?>

                    <?php if (in_array($req['status'], ['COMPLETED', 'READY', 'DELIVERED'], true) && !empty($req['file_path'])): ?>
                    <a href="<?php echo htmlspecialchars($req['file_path']); ?>" target="_blank" rel="noopener noreferrer"
                       class="min-h-[56px] inline-flex items-center justify-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-[var(--tp-ios-card-radius)] transition-colors text-sm font-semibold touch-manipulation w-full sm:w-auto">
                        <i class="fas fa-download mr-2" aria-hidden="true"></i>ดาวน์โหลด
                    </a>
                    <?php elseif ($req['status'] === 'PENDING'): ?>
                    <button type="button" onclick="cancelRequest(<?php echo (int)$req['id']; ?>)"
                            class="min-h-[48px] inline-flex items-center justify-center px-4 py-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-300 rounded-[var(--tp-ios-card-radius)] transition-colors text-sm font-medium touch-manipulation w-full sm:w-auto">
                        <i class="fas fa-times mr-2" aria-hidden="true"></i>ยกเลิก
                    </button>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($req['status'] === 'REJECTED' && $req['reject_reason']): ?>
            <div class="mt-3 p-3 rounded-[var(--tp-ios-card-radius)] bg-red-500/10 border border-red-400/35">
                <p class="text-red-300 text-sm flex gap-2"><i class="fas fa-exclamation-circle shrink-0 mt-0.5" aria-hidden="true"></i><span><?php echo htmlspecialchars($req['reject_reason']); ?></span></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
async function cancelRequest(id) {
    if (!confirm('ต้องการยกเลิกคำขอนี้?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'cancel');
        formData.append('request_id', id);
        formData.append('_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/certificate.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('ยกเลิกคำขอเรียบร้อยแล้ว', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
    }
}
</script>
<?php endif; ?>

</div>

<?php include 'templates/footer.php'; ?>
