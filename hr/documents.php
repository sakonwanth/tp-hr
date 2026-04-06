<?php
/**
 * HR Document Management
 * จัดการเอกสาร - สำหรับ HR
 */

$page_title = 'จัดการคำขอเอกสาร';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

if (!isHR()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Filters
$status = $_GET['status'] ?? 'PENDING';
$type = (int)($_GET['type'] ?? 0);
$month = $_GET['month'] ?? date('Y-m');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Get document templates
$stmtTemplates = $pdo->query("SELECT id, name FROM hr_document_templates WHERE is_active = 1 ORDER BY sort_order");
$templates = $stmtTemplates->fetchAll();

// Build query
$sql = "
    SELECT dr.*, dt.name as template_name,
           u.first_name_th, u.last_name_th, u.employee_code, u.department,
           processor.first_name_th as processor_first, processor.last_name_th as processor_last
    FROM hr_document_requests dr
    JOIN hr_document_templates dt ON dr.template_id = dt.id
    JOIN users u ON dr.user_id = u.id
    LEFT JOIN users processor ON dr.processed_by = processor.id
    WHERE 1=1
";
$params = [];

if ($status && $status !== 'ALL') {
    $sql .= " AND dr.status = ?";
    $params[] = $status;
}

if ($type > 0) {
    $sql .= " AND dr.template_id = ?";
    $params[] = $type;
}

if ($month) {
    $sql .= " AND DATE_FORMAT(dr.created_at, '%Y-%m') = ?";
    $params[] = $month;
}

// Count
$countSql = "SELECT COUNT(*) FROM (" . str_replace("dr.*, dt.name as template_name,\n           u.first_name_th, u.last_name_th, u.employee_code, u.department,\n           processor.first_name_th as processor_first, processor.last_name_th as processor_last", "1", $sql) . ") t";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get records
$sql .= " ORDER BY " . ($status === 'PENDING' ? "dr.created_at ASC" : "dr.created_at DESC");
$sql .= " LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Stats
$stmtStats = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
        COUNT(*) as total
    FROM hr_document_requests
    WHERE DATE_FORMAT(created_at, '%Y-%m') = '" . ($month ?: date('Y-m')) . "'
");
$stats = $stmtStats->fetch();

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <nav class="text-sm text-white/60 mb-1">
        <a href="/hr/" class="hover:text-white">HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">จัดการคำขอเอกสาร</span>
    </nav>
    <h1 class="text-2xl font-bold text-white">จัดการคำขอเอกสาร</h1>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="?status=PENDING&month=<?php echo $month; ?>" 
       class="glass-card rounded-xl p-4 <?php echo $status === 'PENDING' ? 'ring-2 ring-yellow-400' : ''; ?>">
        <p class="text-white/50 text-sm">รอดำเนินการ</p>
        <p class="text-2xl font-bold text-yellow-400"><?php echo $stats['pending'] ?? 0; ?></p>
    </a>
    <a href="?status=PROCESSING&month=<?php echo $month; ?>"
       class="glass-card rounded-xl p-4 <?php echo $status === 'PROCESSING' ? 'ring-2 ring-blue-400' : ''; ?>">
        <p class="text-white/50 text-sm">กำลังจัดทำ</p>
        <p class="text-2xl font-bold text-blue-400"><?php echo $stats['processing'] ?? 0; ?></p>
    </a>
    <a href="?status=COMPLETED&month=<?php echo $month; ?>"
       class="glass-card rounded-xl p-4 <?php echo $status === 'COMPLETED' ? 'ring-2 ring-green-400' : ''; ?>">
        <p class="text-white/50 text-sm">จัดทำแล้ว</p>
        <p class="text-2xl font-bold text-green-400"><?php echo $stats['completed'] ?? 0; ?></p>
    </a>
    <a href="?status=ALL&month=<?php echo $month; ?>"
       class="glass-card rounded-xl p-4 <?php echo $status === 'ALL' ? 'ring-2 ring-violet-400' : ''; ?>">
        <p class="text-white/50 text-sm">ทั้งหมด</p>
        <p class="text-2xl font-bold text-violet-400"><?php echo $stats['total'] ?? 0; ?></p>
    </a>
</div>

<!-- Filters -->
<div class="glass-card rounded-xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <div>
            <label class="block text-white/60 text-xs mb-1">เดือน</label>
            <input type="month" name="month" value="<?php echo $month; ?>" class="input-field" onchange="this.form.submit()">
        </div>
        <div>
            <label class="block text-white/60 text-xs mb-1">ประเภทเอกสาร</label>
            <select name="type" class="input-field" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?php echo $t['id']; ?>" <?php echo $type == $t['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($t['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2 flex items-end gap-2">
            <a href="documents.php" class="flex-1 py-2.5 bg-white/10 hover:bg-white/20 text-white text-center rounded-lg transition-colors">
                <i class="fas fa-redo mr-2"></i>รีเซ็ต
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="glass-card rounded-xl overflow-hidden">
    <?php if (empty($requests)): ?>
    <div class="p-12 text-center">
        <i class="fas fa-file-alt text-4xl text-white/20 mb-4"></i>
        <p class="text-white/60">ไม่พบคำขอเอกสาร</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">รหัสคำขอ</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภทเอกสาร</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วัตถุประสงค์</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ขอ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($requests as $req): ?>
                <?php
                $statusColors = [
                    'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                    'PROCESSING' => 'bg-blue-500/20 text-blue-400',
                    'COMPLETED' => 'bg-green-500/20 text-green-400',
                    'REJECTED' => 'bg-red-500/20 text-red-400',
                    'CANCELLED' => 'bg-gray-500/20 text-gray-400'
                ];
                $statusText = [
                    'PENDING' => 'รอดำเนินการ',
                    'PROCESSING' => 'กำลังจัดทำ',
                    'COMPLETED' => 'จัดทำแล้ว',
                    'REJECTED' => 'ปฏิเสธ',
                    'CANCELLED' => 'ยกเลิก'
                ];
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3 text-white font-mono text-sm">
                        #<?php echo str_pad($req['id'], 6, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($req['first_name_th'] . ' ' . $req['last_name_th']); ?></p>
                        <p class="text-white/50 text-xs"><?php echo htmlspecialchars($req['employee_code'] ?? ''); ?></p>
                    </td>
                    <td class="px-4 py-3 text-white">
                        <?php echo htmlspecialchars($req['template_name']); ?>
                        <?php if ($req['language']): ?>
                        <span class="text-white/50 text-xs">(<?php echo $req['language'] === 'TH' ? 'ไทย' : 'อังกฤษ'; ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-white/70 text-sm max-w-xs">
                        <p class="truncate" title="<?php echo htmlspecialchars($req['purpose'] ?? ''); ?>">
                            <?php echo htmlspecialchars($req['purpose'] ?? '-'); ?>
                        </p>
                    </td>
                    <td class="px-4 py-3 text-white/80 text-sm">
                        <?php echo formatDateThai($req['created_at']); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-3 py-1 rounded-full text-xs <?php echo $statusColors[$req['status']] ?? ''; ?>">
                            <?php echo $statusText[$req['status']] ?? $req['status']; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($req['status'] === 'PENDING'): ?>
                        <button onclick="updateDocStatus(<?php echo $req['id']; ?>, 'PROCESSING')" 
                                class="px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded transition-colors mr-1" title="เริ่มจัดทำ">
                            <i class="fas fa-play"></i>
                        </button>
                        <button onclick="rejectDoc(<?php echo $req['id']; ?>)"
                                class="px-2 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-xs rounded transition-colors" title="ปฏิเสธ">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php elseif ($req['status'] === 'PROCESSING'): ?>
                        <button onclick="completeDoc(<?php echo $req['id']; ?>)" 
                                class="px-2 py-1 bg-green-600 hover:bg-green-700 text-white text-xs rounded transition-colors" title="จัดทำเสร็จ">
                            <i class="fas fa-check"></i>
                        </button>
                        <?php elseif ($req['status'] === 'COMPLETED' && $req['document_url']): ?>
                        <a href="<?php echo htmlspecialchars($req['document_url']); ?>" target="_blank" 
                           class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors inline-block" title="ดาวน์โหลด">
                            <i class="fas fa-download"></i>
                        </a>
                        <?php else: ?>
                        <button onclick="viewDocDetail(<?php echo $req['id']; ?>)" class="px-2 py-1 bg-white/10 hover:bg-white/20 text-white text-xs rounded transition-colors" title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-white/10 flex items-center justify-between">
        <p class="text-white/60 text-sm">
            แสดง <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> 
            จาก <?php echo $totalRecords; ?> รายการ
        </p>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
               class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
               class="px-3 py-1 <?php echo $i === $page ? 'bg-violet-600 text-white' : 'bg-white/10 hover:bg-white/20 text-white'; ?> rounded transition-colors">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
               class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded transition-colors">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Complete Modal -->
<div id="complete-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="complete-form" class="p-6" enctype="multipart/form-data">
            <h3 class="text-xl font-bold text-white mb-4">จัดทำเอกสารเสร็จสิ้น</h3>
            <input type="hidden" name="request_id" id="complete-request-id">
            
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">ไฟล์เอกสาร (PDF)</label>
                <input type="file" name="document" id="complete-file" accept=".pdf" class="input-field !py-2">
                <p class="text-white/50 text-xs mt-1">อัปโหลดไฟล์เอกสารที่จัดทำแล้ว</p>
            </div>
            
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">หมายเหตุ</label>
                <textarea name="note" id="complete-note" rows="2" class="input-field" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
            </div>
            
            <div class="flex gap-4">
                <button type="button" onclick="closeCompleteModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="reject-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="reject-form" class="p-6">
            <h3 class="text-xl font-bold text-white mb-4">ปฏิเสธคำขอเอกสาร</h3>
            <input type="hidden" name="request_id" id="reject-request-id">
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">เหตุผล <span class="text-red-400">*</span></label>
                <textarea name="reason" id="reject-reason" required rows="3" class="input-field"></textarea>
            </div>
            <div class="flex gap-4">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">ปฏิเสธ</button>
            </div>
        </form>
    </div>
</div>

<script>
async function updateDocStatus(id, status) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('request_id', id);
    formData.append('status', status);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/certificate.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('อัปเดตสถานะสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
}

function completeDoc(id) {
    document.getElementById('complete-request-id').value = id;
    document.getElementById('complete-file').value = '';
    document.getElementById('complete-note').value = '';
    document.getElementById('complete-modal').classList.remove('hidden');
}

function closeCompleteModal() {
    document.getElementById('complete-modal').classList.add('hidden');
}

document.getElementById('complete-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'complete');
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/certificate.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

function rejectDoc(id) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-reason').value = '';
    document.getElementById('reject-modal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('reject-modal').classList.add('hidden');
}

document.getElementById('reject-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'reject');
    formData.append('request_id', document.getElementById('reject-request-id').value);
    formData.append('reason', document.getElementById('reject-reason').value);
    formData.append('_token', '<?php echo csrfToken(); ?>');
    
    const response = await fetch('/api/certificate.php', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        showToast('บันทึกสำเร็จ', 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
    }
});

async function viewDocDetail(id) {
    // Placeholder - implement detail view
    alert('ดูรายละเอียดคำขอ #' + id);
}

document.getElementById('complete-modal').addEventListener('click', e => { if (e.target === document.getElementById('complete-modal')) closeCompleteModal(); });
document.getElementById('reject-modal').addEventListener('click', e => { if (e.target === document.getElementById('reject-modal')) closeRejectModal(); });
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
