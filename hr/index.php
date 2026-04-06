<?php
/**
 * HR Admin Dashboard
 * แดชบอร์ด HR
 */

$page_title = 'HR Dashboard';
require_once dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

// Check HR permission
if (!isHR()) {
    redirect('/', 302);
}

$pdo = Database::getInstance()->getConnection();

// Get today's stats
$today = date('Y-m-d');
$currentMonth = date('Y-m');

// Attendance today
$stmtAttendance = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT a.user_id) as checked_in,
        SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END) as late_count,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_employees
    FROM hr_attendances a
    WHERE DATE(a.check_in_time) = ?
");
$stmtAttendance->execute([$today]);
$attendanceStats = $stmtAttendance->fetch();

// Pending leave requests
$stmtLeave = $pdo->prepare("SELECT COUNT(*) FROM hr_leave_requests WHERE status = 'PENDING'");
$stmtLeave->execute();
$pendingLeaves = $stmtLeave->fetchColumn();

// Pending document requests
$stmtDoc = $pdo->prepare("SELECT COUNT(*) FROM hr_document_requests WHERE status IN ('PENDING', 'PROCESSING')");
$stmtDoc->execute();
$pendingDocs = $stmtDoc->fetchColumn();

// Recent leaves to approve
$stmtRecentLeaves = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, lt.color_code,
           u.first_name_th, u.last_name_th, u.employee_code, u.department
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    JOIN users u ON lr.user_id = u.id
    WHERE lr.status = 'PENDING'
    ORDER BY lr.created_at ASC
    LIMIT 5
");
$stmtRecentLeaves->execute();
$recentLeaves = $stmtRecentLeaves->fetchAll();

// Recent document requests
$stmtRecentDocs = $pdo->prepare("
    SELECT dr.*, dt.name as template_name,
           u.first_name_th, u.last_name_th, u.employee_code
    FROM hr_document_requests dr
    JOIN hr_document_templates dt ON dr.template_id = dt.id
    JOIN users u ON dr.user_id = u.id
    WHERE dr.status IN ('PENDING', 'PROCESSING')
    ORDER BY dr.created_at ASC
    LIMIT 5
");
$stmtRecentDocs->execute();
$recentDocs = $stmtRecentDocs->fetchAll();

// Employees on leave today
$stmtOnLeave = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, lt.color_code,
           u.first_name_th, u.last_name_th, u.department
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    JOIN users u ON lr.user_id = u.id
    WHERE lr.status = 'APPROVED'
    AND ? BETWEEN lr.start_date AND lr.end_date
");
$stmtOnLeave->execute([$today]);
$onLeaveToday = $stmtOnLeave->fetchAll();

// Monthly attendance summary
$stmtMonthly = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT DATE(check_in_time)) as working_days,
        COUNT(DISTINCT user_id) as unique_employees,
        AVG(CASE 
            WHEN status = 'ON_TIME' THEN 1 
            WHEN status = 'LATE' THEN 0.5 
            ELSE 0 
        END) * 100 as attendance_rate
    FROM hr_attendances
    WHERE DATE_FORMAT(check_in_time, '%Y-%m') = ?
");
$stmtMonthly->execute([$currentMonth]);
$monthlyStats = $stmtMonthly->fetch();

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-white">HR Dashboard</h1>
    <p class="text-white/60">ภาพรวมระบบ HR วันที่ <?php echo formatDateThai($today); ?></p>
</div>

<!-- Quick Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="glass-card rounded-xl p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-white/50 text-sm">เข้างานวันนี้</p>
                <p class="text-2xl font-bold text-green-400">
                    <?php echo $attendanceStats['checked_in'] ?? 0; ?>/<?php echo $attendanceStats['total_employees'] ?? 0; ?>
                </p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-green-500/20 flex items-center justify-center">
                <i class="fas fa-user-check text-green-400 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="glass-card rounded-xl p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-white/50 text-sm">มาสาย</p>
                <p class="text-2xl font-bold text-yellow-400"><?php echo $attendanceStats['late_count'] ?? 0; ?></p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-yellow-500/20 flex items-center justify-center">
                <i class="fas fa-clock text-yellow-400 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="glass-card rounded-xl p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-white/50 text-sm">รอลาอนุมัติ</p>
                <p class="text-2xl font-bold text-violet-400"><?php echo $pendingLeaves; ?></p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-violet-500/20 flex items-center justify-center">
                <i class="fas fa-calendar-check text-violet-400 text-xl"></i>
            </div>
        </div>
        <a href="leaves.php" class="text-violet-400 text-sm hover:underline mt-2 block">ดูทั้งหมด →</a>
    </div>
    
    <div class="glass-card rounded-xl p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-white/50 text-sm">รอออกเอกสาร</p>
                <p class="text-2xl font-bold text-blue-400"><?php echo $pendingDocs; ?></p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-blue-500/20 flex items-center justify-center">
                <i class="fas fa-file-alt text-blue-400 text-xl"></i>
            </div>
        </div>
        <a href="documents.php" class="text-blue-400 text-sm hover:underline mt-2 block">ดูทั้งหมด →</a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Pending Leave Requests -->
    <div class="glass-card rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-white">
                <i class="fas fa-calendar-alt text-violet-400 mr-2"></i>คำขอลารออนุมัติ
            </h2>
            <a href="leaves.php" class="text-violet-400 text-sm hover:underline">ดูทั้งหมด</a>
        </div>
        
        <?php if (empty($recentLeaves)): ?>
        <div class="p-8 text-center">
            <i class="fas fa-check-circle text-3xl text-green-400 mb-2"></i>
            <p class="text-white/60">ไม่มีคำขอลารออนุมัติ</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-white/10">
            <?php foreach ($recentLeaves as $leave): ?>
            <div class="p-4 hover:bg-white/5 transition-colors">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-2 h-2 rounded-full" style="background-color: <?php echo $leave['color_code']; ?>"></span>
                            <span class="text-white font-medium"><?php echo htmlspecialchars($leave['first_name_th'] . ' ' . $leave['last_name_th']); ?></span>
                        </div>
                        <p class="text-white/70 text-sm"><?php echo htmlspecialchars($leave['leave_type_name']); ?></p>
                        <p class="text-white/50 text-sm">
                            <?php echo formatDateThai($leave['start_date']); ?> 
                            <?php if ($leave['start_date'] !== $leave['end_date']): ?>
                            - <?php echo formatDateThai($leave['end_date']); ?>
                            <?php endif; ?>
                            (<?php echo number_format($leave['total_days'], 1); ?> วัน)
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="approveLeave(<?php echo $leave['id']; ?>)" 
                                class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-sm rounded transition-colors">
                            อนุมัติ
                        </button>
                        <button onclick="rejectLeave(<?php echo $leave['id']; ?>)"
                                class="px-3 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 text-sm rounded transition-colors">
                            ไม่อนุมัติ
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- On Leave Today -->
    <div class="glass-card rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/10">
            <h2 class="text-lg font-semibold text-white">
                <i class="fas fa-user-minus text-orange-400 mr-2"></i>ลาวันนี้ (<?php echo count($onLeaveToday); ?> คน)
            </h2>
        </div>
        
        <?php if (empty($onLeaveToday)): ?>
        <div class="p-8 text-center">
            <i class="fas fa-users text-3xl text-green-400 mb-2"></i>
            <p class="text-white/60">ไม่มีพนักงานลาวันนี้</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-white/10 max-h-80 overflow-y-auto">
            <?php foreach ($onLeaveToday as $emp): ?>
            <div class="p-3 flex items-center gap-3">
                <div class="w-2 h-2 rounded-full" style="background-color: <?php echo $emp['color_code']; ?>"></div>
                <div class="flex-1">
                    <p class="text-white text-sm"><?php echo htmlspecialchars($emp['first_name_th'] . ' ' . $emp['last_name_th']); ?></p>
                    <p class="text-white/50 text-xs"><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></p>
                </div>
                <span class="text-white/60 text-xs"><?php echo htmlspecialchars($emp['leave_type_name']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="leaves.php" class="glass-card rounded-xl p-4 hover:bg-white/10 transition-colors group">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-violet-600/20 flex items-center justify-center group-hover:bg-violet-600/30">
                <i class="fas fa-calendar-check text-violet-400"></i>
            </div>
            <span class="text-white">จัดการการลา</span>
        </div>
    </a>
    
    <a href="attendance.php" class="glass-card rounded-xl p-4 hover:bg-white/10 transition-colors group">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-green-600/20 flex items-center justify-center group-hover:bg-green-600/30">
                <i class="fas fa-user-clock text-green-400"></i>
            </div>
            <span class="text-white">ตรวจสอบการเข้างาน</span>
        </div>
    </a>
    
    <a href="documents.php" class="glass-card rounded-xl p-4 hover:bg-white/10 transition-colors group">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-blue-600/20 flex items-center justify-center group-hover:bg-blue-600/30">
                <i class="fas fa-file-alt text-blue-400"></i>
            </div>
            <span class="text-white">ออกเอกสาร</span>
        </div>
    </a>
    
    <a href="employees.php" class="glass-card rounded-xl p-4 hover:bg-white/10 transition-colors group">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-yellow-600/20 flex items-center justify-center group-hover:bg-yellow-600/30">
                <i class="fas fa-users text-yellow-400"></i>
            </div>
            <span class="text-white">พนักงาน</span>
        </div>
    </a>
</div>

<!-- Pending Documents -->
<?php if (!empty($recentDocs)): ?>
<div class="glass-card rounded-xl overflow-hidden">
    <div class="p-4 border-b border-white/10 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-white">
            <i class="fas fa-file-signature text-blue-400 mr-2"></i>คำขอเอกสารรอดำเนินการ
        </h2>
        <a href="documents.php" class="text-blue-400 text-sm hover:underline">ดูทั้งหมด</a>
    </div>
    
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เลขที่</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">พนักงาน</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภทเอกสาร</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่ขอ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">การดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($recentDocs as $doc): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3 text-white/70 text-sm"><?php echo htmlspecialchars($doc['request_number']); ?></td>
                    <td class="px-4 py-3 text-white"><?php echo htmlspecialchars($doc['first_name_th'] . ' ' . $doc['last_name_th']); ?></td>
                    <td class="px-4 py-3 text-white"><?php echo htmlspecialchars($doc['template_name']); ?></td>
                    <td class="px-4 py-3 text-white/70 text-sm"><?php echo formatDateThai($doc['created_at']); ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($doc['status'] === 'PENDING'): ?>
                        <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded">รอดำเนินการ</span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-blue-500/20 text-blue-400 text-xs rounded">กำลังดำเนินการ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="documents.php?action=process&id=<?php echo $doc['id']; ?>" 
                           class="px-3 py-1 bg-violet-600 hover:bg-violet-700 text-white text-sm rounded transition-colors">
                            ดำเนินการ
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Reject Modal -->
<div id="reject-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl w-full max-w-md">
        <form id="reject-form" class="p-6">
            <h3 class="text-xl font-bold text-white mb-4">ไม่อนุมัติคำขอลา</h3>
            <input type="hidden" name="request_id" id="reject-request-id">
            <div class="mb-4">
                <label class="block text-white/80 text-sm mb-2">เหตุผล <span class="text-red-400">*</span></label>
                <textarea name="reason" id="reject-reason" required rows="3" class="input-field" 
                          placeholder="ระบุเหตุผลที่ไม่อนุมัติ..."></textarea>
            </div>
            <div class="flex gap-4">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">
                    ไม่อนุมัติ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
async function approveLeave(id) {
    if (!confirm('อนุมัติคำขอลานี้?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'approve');
        formData.append('request_id', id);
        formData.append('_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('อนุมัติคำขอลาสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

function rejectLeave(id) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-reason').value = '';
    document.getElementById('reject-modal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('reject-modal').classList.add('hidden');
}

document.getElementById('reject-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const id = document.getElementById('reject-request-id').value;
    const reason = document.getElementById('reject-reason').value;
    
    try {
        const formData = new FormData();
        formData.append('action', 'reject');
        formData.append('request_id', id);
        formData.append('reason', reason);
        formData.append('_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('บันทึกผลสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
});

document.getElementById('reject-modal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
