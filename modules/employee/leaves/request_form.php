<?php
/**
 * Leave Request Form
 * ฟอร์มยื่นขอลา
 */

$selected_type = (int)($_GET['type'] ?? 0);

// Get leave types with user's entitlements
$stmt = $pdo->prepare("
    SELECT lt.*, 
           COALESCE(le.entitled_days, lt.default_days_per_year) as entitled_days,
           COALESCE(le.carried_over_days, 0) as carried_over,
           COALESCE(le.used_days, 0) as used_days,
           COALESCE(le.pending_days, 0) as pending_days
    FROM hr_leave_types lt
    LEFT JOIN hr_leave_entitlements le ON lt.id = le.leave_type_id 
        AND le.user_id = ? AND le.year = YEAR(CURDATE())
    WHERE lt.is_active = 1
    ORDER BY lt.sort_order
");
$stmt->execute([$user['id']]);
$leave_types_form = $stmt->fetchAll();

// อนุญาตยื่นลาย้อนหลังได้ (สูงสุด 1 ปี) — กฎขอล่วงหน้าใช้เฉพาะวันลาในอนาคต
$min_leave_date = date('Y-m-d', strtotime('-365 days'));
?>

<div class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
        <span class="mx-2">/</span>
        <a href="leave.php" class="hover:text-white touch-manipulation">การลา</a>
        <span class="mx-2">/</span>
        <span class="text-white">ยื่นขอลา</span>
    </nav>
    <h1 class="tp-ios-page-title">ยื่นขอลา</h1>
    <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">กรอกช่วงวันที่และเหตุผล ระบบจะคำนวณจำนวนวันลาให้ — สามารถยื่นลาย้อนหลังได้ (ภายใน 1 ปี)</p>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 md:gap-8 min-w-0 max-w-full">
    <div class="xl:col-span-2 min-w-0">
        <form id="leave-form" class="native-card tp-native-card tp-native-data-card min-w-0 max-w-full overflow-x-clip p-5 sm:p-7" method="POST" action="/api/leave.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            <?php echo csrfField(); ?>
            
            <!-- Leave Type -->
            <div class="tp-native-form-group min-w-0 max-w-full">
                <label class="block text-white/80 text-sm font-medium mb-2">ประเภทการลา <span class="text-red-400">*</span></label>
                <select name="leave_type_id" id="leave_type_id" required class="input-field tp-native-select" onchange="updateLeaveInfo()">
                    <option value="">-- เลือกประเภทการลา --</option>
                    <?php foreach ($leave_types_form as $type): ?>
                    <option value="<?php echo $type['id']; ?>" 
                            data-remaining="<?php echo $type['entitled_days'] + $type['carried_over'] - $type['used_days'] - $type['pending_days']; ?>"
                            data-min-advance="<?php echo $type['min_days_advance']; ?>"
                            data-max-days="<?php echo $type['max_consecutive_days'] ?? 999; ?>"
                            data-requires-doc="<?php echo $type['requires_document'] ? '1' : '0'; ?>"
                            data-doc-after="<?php echo $type['document_after_days'] ?? 0; ?>"
                            <?php echo $selected_type == $type['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['name']); ?> 
                        (คงเหลือ <?php echo number_format($type['entitled_days'] + $type['carried_over'] - $type['used_days'] - $type['pending_days'], 1); ?> วัน)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p id="leave-info" class="text-white/50 text-sm mt-2 hidden"></p>
            </div>
            
            <!-- Date Range -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-6 tp-native-form-group min-w-0 max-w-full">
                <div class="min-w-0 max-w-full">
                    <label class="block text-white/80 text-sm font-medium mb-2">วันที่เริ่มต้น <span class="text-red-400">*</span></label>
                    <div class="input-date-shell">
                        <input type="date" name="start_date" id="start_date" required class="input-field tp-native-input"
                               min="<?php echo $min_leave_date; ?>" onchange="calculateDays()">
                    </div>
                </div>
                <div class="min-w-0 max-w-full">
                    <label class="block text-white/80 text-sm font-medium mb-2">ช่วงเวลา</label>
                    <select name="start_period" id="start_period" class="input-field tp-native-select" onchange="calculateDays()">
                        <option value="FULL">ทั้งวัน</option>
                        <option value="AM">ครึ่งวันเช้า</option>
                        <option value="PM">ครึ่งวันบ่าย</option>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-6 tp-native-form-group min-w-0">
                <div class="min-w-0 max-w-full">
                    <label class="block text-white/80 text-sm font-medium mb-2">วันที่สิ้นสุด <span class="text-red-400">*</span></label>
                    <div class="input-date-shell">
                        <input type="date" name="end_date" id="end_date" required class="input-field tp-native-input"
                               min="<?php echo $min_leave_date; ?>" onchange="calculateDays()">
                    </div>
                </div>
                <div class="min-w-0 max-w-full">
                    <label class="block text-white/80 text-sm font-medium mb-2">ช่วงเวลา</label>
                    <select name="end_period" id="end_period" class="input-field tp-native-select" onchange="calculateDays()">
                        <option value="FULL">ทั้งวัน</option>
                        <option value="AM">ครึ่งวันเช้า</option>
                        <option value="PM">ครึ่งวันบ่าย</option>
                    </select>
                </div>
            </div>
            
            <!-- Days Summary -->
            <div class="p-5 md:p-6 rounded-[var(--tp-ios-card-radius)] bg-violet-500/10 border border-violet-400/35 tp-native-form-group">
                <div class="flex items-center justify-between">
                    <span class="text-white/70">จำนวนวันลา:</span>
                    <span class="text-2xl font-bold text-violet-400" id="total-days">0</span>
                </div>
                <input type="hidden" name="total_days" id="total_days_input" value="0">
            </div>
            
            <!-- Reason -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">เหตุผลการลา <span class="text-red-400">*</span></label>
                <textarea name="reason" id="reason" required rows="3" class="input-field tp-native-textarea" 
                          placeholder="ระบุเหตุผลการลา..."></textarea>
            </div>
            
            <!-- Contact Number -->
            <div class="tp-native-form-group">
                <label class="block text-white/80 text-sm font-medium mb-2">เบอร์ติดต่อระหว่างลา</label>
                <input type="tel" name="contact_number" class="input-field tp-native-input" 
                       placeholder="เบอร์โทรที่สามารถติดต่อได้" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
            </div>
            
            <!-- Document Upload -->
            <div class="tp-native-form-group" id="document-section">
                <label class="block text-white/80 text-sm font-medium mb-2">
                    เอกสารประกอบ
                    <span class="text-white/50 text-xs" id="doc-required-label">(ถ้ามี)</span>
                </label>
                <div class="border-2 border-dashed border-white/20 rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 text-center hover:border-violet-500/50 transition-colors bg-white/[0.03]">
                    <input type="file" name="document" id="document" class="hidden" accept=".pdf,.jpg,.jpeg,.png">
                    <label for="document" class="touch-manipulation cursor-pointer flex flex-col items-center justify-center min-h-[52px] py-2">
                        <i class="fas fa-cloud-upload-alt text-3xl text-white/30 mb-2" aria-hidden="true"></i>
                        <p class="text-white/60 text-sm">คลิกเพื่ออัปโหลดเอกสาร</p>
                        <p class="text-white/40 text-xs mt-1">PDF, JPG, PNG (ไม่เกิน 5MB)</p>
                    </label>
                    <p id="file-name" class="text-green-400 text-sm mt-2 hidden"></p>
                </div>
            </div>
            
            <!-- Buttons -->
            <div class="flex flex-col-reverse md:flex-row gap-5 md:gap-6 pt-2">
                <a href="leave.php" class="touch-manipulation flex-1 min-h-[54px] inline-flex items-center justify-center py-3 bg-white/10 hover:bg-white/20 text-white text-center rounded-[var(--tp-radius-button)] transition-colors font-medium border-0">
                    ยกเลิก
                </a>
                <button type="submit" class="btn-primary touch-manipulation flex-1 inline-flex items-center justify-center gap-2 min-h-[58px] py-3 rounded-[var(--tp-radius-button)] font-semibold border-0">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i><span>ส่งคำขอลา</span>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Right Column: Info -->
    <div class="space-y-6 min-w-0">
        <!-- Leave Balance -->
        <div class="native-card tp-native-card tp-native-data-card min-w-0">
            <h3 class="section-title mb-4">
                <i class="fas fa-info-circle text-blue-400 text-2xl" aria-hidden="true"></i>
                สิทธิ์วันลาคงเหลือ
            </h3>
            
            <div class="space-y-3" id="leave-balance-list">
                <?php foreach ($leave_types_form as $type): ?>
                <?php $remaining = $type['entitled_days'] + $type['carried_over'] - $type['used_days'] - $type['pending_days']; ?>
                <div class="flex items-center justify-between gap-2 py-2 border-b border-white/10 last:border-0 min-w-0"
                     data-type-id="<?php echo $type['id']; ?>">
                    <span class="text-white/70 text-sm truncate min-w-0"><?php echo htmlspecialchars($type['name']); ?></span>
                    <span class="text-white font-medium <?php echo $remaining <= 0 ? 'text-red-400' : ''; ?>">
                        <?php echo number_format($remaining, 1); ?> วัน
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Leave Policy -->
        <div class="native-card tp-native-card tp-native-data-card min-w-0">
            <h3 class="section-title mb-4">
                <i class="fas fa-clipboard-list text-yellow-400 text-2xl" aria-hidden="true"></i>
                ระเบียบการลา
            </h3>
            
            <div class="space-y-3 text-sm text-white/70">
                <p class="flex gap-2"><i class="fas fa-check text-green-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>ลาป่วยเกิน 3 วันต้องมีใบรับรองแพทย์</span></p>
                <p class="flex gap-2"><i class="fas fa-check text-green-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>ลากิจต้องขอล่วงหน้าอย่างน้อย 3 วัน</span></p>
                <p class="flex gap-2"><i class="fas fa-check text-green-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>ลาพักร้อนต้องขอล่วงหน้าอย่างน้อย 7 วัน</span></p>
                <p class="flex gap-2"><i class="fas fa-check text-green-400 shrink-0 mt-0.5" aria-hidden="true"></i><span>คำขอลาต้องได้รับอนุมัติจากหัวหน้างาน</span></p>
            </div>
        </div>
    </div>
</div>

<script>
// File upload preview
document.getElementById('document').addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name;
    const fileNameEl = document.getElementById('file-name');
    
    if (fileName) {
        fileNameEl.textContent = fileName;
        fileNameEl.classList.remove('hidden');
    } else {
        fileNameEl.classList.add('hidden');
    }
});

// Update leave info based on selection
function updateLeaveInfo() {
    const select = document.getElementById('leave_type_id');
    const option = select.options[select.selectedIndex];
    const infoEl = document.getElementById('leave-info');
    const docLabel = document.getElementById('doc-required-label');
    
    if (!option.value) {
        infoEl.classList.add('hidden');
        return;
    }
    
    const remaining = parseFloat(option.dataset.remaining);
    const minAdvance = parseInt(option.dataset.minAdvance);
    const maxDays = parseInt(option.dataset.maxDays);
    const requiresDoc = option.dataset.requiresDoc === '1';
    
    let info = [];
    if (minAdvance > 0) {
        info.push(`ต้องขอล่วงหน้าอย่างน้อย ${minAdvance} วัน`);
    }
    if (maxDays < 999) {
        info.push(`ลาติดต่อกันได้ไม่เกิน ${maxDays} วัน`);
    }
    
    if (info.length > 0) {
        infoEl.textContent = info.join(' | ');
        infoEl.classList.remove('hidden');
    } else {
        infoEl.classList.add('hidden');
    }
    
    // Update document requirement
    if (requiresDoc) {
        docLabel.textContent = '(จำเป็น)';
        docLabel.classList.add('text-red-400');
        docLabel.classList.remove('text-white/50');
    } else {
        docLabel.textContent = '(ถ้ามี)';
        docLabel.classList.remove('text-red-400');
        docLabel.classList.add('text-white/50');
    }
    
    // อนุญาตย้อนหลัง — กฎล่วงหน้าตรวจที่ API เฉพาะวันลาในอนาคต
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    startDateInput.min = '<?php echo $min_leave_date; ?>';
    endDateInput.min = startDateInput.value || '<?php echo $min_leave_date; ?>';
}

// Calculate total days (server-side workday rules via API)
let leaveCountTimer = null;
async function calculateDays() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const startPeriod = document.getElementById('start_period').value;
    const endPeriod = document.getElementById('end_period').value;
    const totalEl = document.getElementById('total-days');
    const totalInput = document.getElementById('total_days_input');

    if (!startDate || !endDate) {
        totalEl.textContent = '0';
        totalInput.value = '0';
        return;
    }

    const start = new Date(startDate);
    const end = new Date(endDate);
    if (end < start) {
        document.getElementById('end_date').value = startDate;
        return calculateDays();
    }
    document.getElementById('end_date').min = startDate;

    clearTimeout(leaveCountTimer);
    leaveCountTimer = setTimeout(async () => {
        try {
            const params = new URLSearchParams({
                action: 'count_days',
                start_date: startDate,
                end_date: endDate,
                start_period: startPeriod,
                end_period: endPeriod,
            });
            const response = await fetch('/api/leave.php?' + params.toString());
            const result = await response.json();
            const days = result.success ? parseFloat(result.total_days) || 0 : 0;
            totalEl.textContent = days.toFixed(1);
            totalInput.value = String(days);
        } catch (err) {
            console.error('count_days failed', err);
            totalEl.textContent = '0';
            totalInput.value = '0';
        }
    }, 200);
}

// Form submission
document.getElementById('leave-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    
    // Validate
    const totalDays = parseFloat(document.getElementById('total_days_input').value);
    if (totalDays <= 0) {
        showToast('กรุณาเลือกวันที่ลาให้ถูกต้อง', 'error');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังส่งคำขอ...';
    
    try {
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('ส่งคำขอลาสำเร็จ', 'success');
            setTimeout(() => window.location.href = 'leave.php', 1500);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่งคำขอลา';
        }
    } catch (err) {
        console.error('Submit error:', err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่งคำขอลา';
    }
});

// Initialize
updateLeaveInfo();
</script>
