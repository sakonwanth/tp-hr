<?php
/**
 * TP-HR Leave Management
 * ระบบการลา
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$page_title = 'การลา';
$current_page = 'leave';

$action = $_GET['action'] ?? '';
$message = flash('success');
$error = flash('error');

// Get leave types
$stmt = $pdo->query("SELECT * FROM hr_leave_types WHERE is_active = 1 ORDER BY sort_order");
$leave_types = $stmt->fetchAll();

// Get leave entitlements for current year
$stmt = $pdo->prepare("
    SELECT le.*, lt.name, lt.code, lt.color, lt.icon
    FROM hr_leave_entitlements le
    JOIN hr_leave_types lt ON le.leave_type_id = lt.id
    WHERE le.user_id = ? AND le.year = YEAR(CURDATE())
    ORDER BY lt.sort_order
");
$stmt->execute([$user['id']]);
$entitlements = $stmt->fetchAll();

// Get pending leave requests
$stmt = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, lt.color
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.user_id = ? AND lr.status = 'PENDING'
    ORDER BY lr.created_at DESC
");
$stmt->execute([$user['id']]);
$pending_requests = $stmt->fetchAll();

// Get recent leave history
$stmt = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name, lt.color
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.user_id = ? AND lr.status != 'DRAFT'
    ORDER BY lr.created_at DESC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$leave_history = $stmt->fetchAll();

// Handle leave request form
if ($action === 'request') {
    $page_title = 'ยื่นขอลา';
}

require_once __DIR__ . '/templates/header.php';
?>

<div>
    <?php if ($action === 'request'): ?>
    <!-- Leave Request Form -->
    <?php include __DIR__ . '/modules/employee/leaves/request_form.php'; ?>
    
    <?php else: ?>
    <!-- Leave Dashboard -->
    <div class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">การลา</h1>
                <p class="text-white/60 mt-1">จัดการวันลาและติดตามคำขอลา</p>
            </div>
            
            <a href="?action=request" class="btn-primary">
                <i class="fas fa-plus mr-2"></i>ยื่นขอลา
            </a>
        </div>
    </div>
    
    <?php if ($message): ?>
    <div class="bg-green-500/20 border border-green-500/50 text-green-300 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="bg-red-500/20 border border-red-500/50 text-red-300 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <!-- Left Column -->
        <div class="xl:col-span-2 space-y-6">
            <!-- Leave Balance -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-calendar-check text-green-400 mr-2"></i>
                    สิทธิ์วันลาคงเหลือ <?php echo date('Y') + 543; ?>
                </h2>
                
                <?php if ($entitlements): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($entitlements as $ent): ?>
                    <div class="p-4 rounded-lg bg-white/5 border border-white/10">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-white font-medium"><?php echo htmlspecialchars($ent['name']); ?></span>
                            <span class="text-xs px-2 py-1 rounded-full" style="background-color: <?php echo $ent['color'] ?? '#6B7280'; ?>20; color: <?php echo $ent['color'] ?? '#6B7280'; ?>">
                                <?php echo $ent['code']; ?>
                            </span>
                        </div>
                        
                        <div class="flex items-end justify-between">
                            <div>
                                <span class="text-3xl font-bold text-white">
                                    <?php 
                                    $remaining = $ent['entitled_days'] + $ent['carried_over_days'] + ($ent['additional_days'] ?? 0) - $ent['used_days'] - $ent['pending_days'];
                                    echo number_format($remaining, 1);
                                    ?>
                                </span>
                                <span class="text-white/50 text-sm"> / <?php echo number_format($ent['entitled_days'] + $ent['carried_over_days'], 1); ?> วัน</span>
                            </div>
                            
                            <?php if ($ent['pending_days'] > 0): ?>
                            <span class="text-yellow-400 text-xs">
                                <i class="fas fa-clock mr-1"></i>
                                รออนุมัติ <?php echo number_format($ent['pending_days'], 1); ?> วัน
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Progress bar -->
                        <div class="mt-3 h-2 bg-white/10 rounded-full overflow-hidden">
                            <?php 
                            $total = $ent['entitled_days'] + $ent['carried_over_days'];
                            $usedPercent = $total > 0 ? min(100, ($ent['used_days'] / $total) * 100) : 0;
                            $pendingPercent = $total > 0 ? min(100 - $usedPercent, ($ent['pending_days'] / $total) * 100) : 0;
                            ?>
                            <div class="h-full flex">
                                <div class="h-full bg-white/40" style="width: <?php echo $usedPercent; ?>%"></div>
                                <div class="h-full bg-yellow-500/50" style="width: <?php echo $pendingPercent; ?>%"></div>
                            </div>
                        </div>
                        <div class="flex justify-between text-xs text-white/50 mt-1">
                            <span>ใช้ไป <?php echo number_format($ent['used_days'], 1); ?> วัน</span>
                            <span>คงเหลือ <?php echo number_format($remaining, 1); ?> วัน</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-white/60 text-center py-8">
                    <i class="fas fa-info-circle mr-2"></i>
                    ยังไม่มีข้อมูลสิทธิ์วันลา กรุณาติดต่อฝ่าย HR
                </p>
                <?php endif; ?>
            </div>
            
            <!-- Leave History -->
            <div class="glass-card rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-white">
                        <i class="fas fa-history text-blue-400 mr-2"></i>
                        ประวัติการลา
                    </h2>
                    <a href="leave_history.php" class="inline-flex min-h-[44px] items-center text-violet-400 hover:text-violet-300 text-sm touch-manipulation">
                        ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                
                <?php if ($leave_history): ?>
                <div class="md:hidden space-y-3">
                    <?php foreach ($leave_history as $leave): ?>
                    <?php
                    $leaveDateLabel = $leave['start_date'] === $leave['end_date']
                        ? formatDateThai($leave['start_date'])
                        : formatDateThai($leave['start_date']) . ' - ' . formatDateThai($leave['end_date']);
                    $statusClass = match($leave['status']) {
                        'PENDING' => 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30',
                        'APPROVED' => 'bg-green-500/20 text-green-300 border border-green-500/30',
                        'REJECTED' => 'bg-red-500/20 text-red-300 border border-red-500/30',
                        'CANCELLED' => 'bg-gray-500/20 text-gray-300 border border-gray-500/30',
                        default => 'bg-gray-500/20 text-gray-300 border border-gray-500/30'
                    };
                    ?>
                    <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <span class="inline-flex px-2.5 py-1 text-xs rounded-full"
                                      style="background-color: <?php echo $leave['color'] ?? '#6B7280'; ?>20; color: <?php echo $leave['color'] ?? '#6B7280'; ?>">
                                    <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                </span>
                                <p class="mt-2 text-white font-semibold leading-tight"><?php echo htmlspecialchars($leaveDateLabel); ?></p>
                            </div>
                            <span class="shrink-0 px-2.5 py-1 text-xs rounded-full <?php echo $statusClass; ?>">
                                <?php echo LEAVE_STATUS[$leave['status']] ?? $leave['status']; ?>
                            </span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-black/20 border border-white/10 px-3 py-2">
                                <div class="text-[11px] text-white/50">จำนวนวัน</div>
                                <div class="text-white font-semibold"><?php echo number_format($leave['total_days'], 1); ?> วัน</div>
                            </div>
                            <a href="leave_history.php"
                               class="min-h-[44px] rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold flex items-center justify-center touch-manipulation">
                                <i class="fas fa-eye mr-2"></i>รายละเอียด
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="pb-3 text-left text-white/60 text-sm font-medium">ประเภท</th>
                                <th class="pb-3 text-center text-white/60 text-sm font-medium">วันที่</th>
                                <th class="pb-3 text-center text-white/60 text-sm font-medium">จำนวน</th>
                                <th class="pb-3 text-center text-white/60 text-sm font-medium">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leave_history as $leave): ?>
                            <tr class="border-b border-white/5">
                                <td class="py-3">
                                    <span class="px-2 py-1 text-xs rounded" style="background-color: <?php echo $leave['color'] ?? '#6B7280'; ?>20; color: <?php echo $leave['color'] ?? '#6B7280'; ?>">
                                        <?php echo htmlspecialchars($leave['leave_type_name']); ?>
                                    </span>
                                </td>
                                <td class="py-3 text-center text-white/80 text-sm">
                                    <?php 
                                    if ($leave['start_date'] === $leave['end_date']) {
                                        echo formatDateThai($leave['start_date']);
                                    } else {
                                        echo formatDateThai($leave['start_date']) . ' - ' . formatDateThai($leave['end_date']);
                                    }
                                    ?>
                                </td>
                                <td class="py-3 text-center text-white">
                                    <?php echo number_format($leave['total_days'], 1); ?> วัน
                                </td>
                                <td class="py-3 text-center">
                                    <span class="px-2 py-1 text-xs rounded <?php
                                        echo match($leave['status']) {
                                            'PENDING' => 'bg-yellow-500/20 text-yellow-400',
                                            'APPROVED' => 'bg-green-500/20 text-green-400',
                                            'REJECTED' => 'bg-red-500/20 text-red-400',
                                            'CANCELLED' => 'bg-gray-500/20 text-gray-400',
                                            default => 'bg-gray-500/20 text-gray-400'
                                        };
                                    ?>">
                                        <?php echo LEAVE_STATUS[$leave['status']] ?? $leave['status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-white/50 text-center py-8">ยังไม่มีประวัติการลา</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Column -->
        <div class="space-y-6">
            <!-- Quick Request -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-bolt text-yellow-400 mr-2"></i>
                    ขอลาด่วน
                </h2>
                
                <div class="space-y-3">
                    <?php foreach (array_slice($leave_types, 0, 4) as $type): ?>
                    <a href="?action=request&type=<?php echo $type['id']; ?>" 
                       class="flex items-center gap-3 p-3 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center" 
                             style="background-color: <?php echo $type['color'] ?? '#6B7280'; ?>20">
                            <i class="fas fa-<?php echo $type['icon'] ?? 'calendar'; ?>" 
                               style="color: <?php echo $type['color'] ?? '#6B7280'; ?>"></i>
                        </div>
                        <div>
                            <p class="text-white font-medium"><?php echo htmlspecialchars($type['name']); ?></p>
                            <p class="text-white/50 text-xs"><?php echo htmlspecialchars($type['name_en'] ?? ''); ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Pending Requests -->
            <?php if ($pending_requests): ?>
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-hourglass-half text-orange-400 mr-2"></i>
                    รออนุมัติ
                </h2>
                
                <div class="space-y-3">
                    <?php foreach ($pending_requests as $req): ?>
                    <div class="p-3 rounded-lg bg-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <span class="px-2 py-1 text-xs rounded" style="background-color: <?php echo $req['color'] ?? '#6B7280'; ?>20; color: <?php echo $req['color'] ?? '#6B7280'; ?>">
                                <?php echo htmlspecialchars($req['leave_type_name']); ?>
                            </span>
                            <span class="text-white"><?php echo number_format($req['total_days'], 1); ?> วัน</span>
                        </div>
                        <p class="text-white/60 text-sm">
                            <?php echo formatDateThai($req['start_date']); ?>
                            <?php if ($req['start_date'] !== $req['end_date']): ?>
                            - <?php echo formatDateThai($req['end_date']); ?>
                            <?php endif; ?>
                        </p>
                        <div class="mt-3 flex">
                            <button onclick="cancelRequest(<?php echo $req['id']; ?>)"
                                    class="w-full md:w-auto md:ml-auto min-h-[44px] px-4 py-2 rounded-xl bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-200 text-sm font-semibold transition-colors">
                                <i class="fas fa-times mr-2"></i>ยกเลิกคำขอ
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Company Calendar -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-calendar text-violet-400 mr-2"></i>
                    วันหยุดที่จะถึง
                </h2>
                
                <?php
                $stmt = $pdo->query("
                    SELECT * FROM hr_holidays 
                    WHERE date >= CURDATE() AND is_active = 1 
                    ORDER BY date LIMIT 5
                ");
                $holidays = $stmt->fetchAll();
                ?>
                
                <?php if ($holidays): ?>
                <div class="space-y-2">
                    <?php foreach ($holidays as $h): ?>
                    <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-white/5">
                        <div class="w-12 h-12 rounded-lg bg-red-500/20 flex flex-col items-center justify-center">
                            <span class="text-red-400 text-xs"><?php echo date('M', strtotime($h['date'])); ?></span>
                            <span class="text-white font-bold"><?php echo date('j', strtotime($h['date'])); ?></span>
                        </div>
                        <div>
                            <p class="text-white text-sm"><?php echo htmlspecialchars($h['name']); ?></p>
                            <p class="text-white/50 text-xs"><?php echo formatDateThai($h['date']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-white/50 text-center py-4">ไม่มีวันหยุดที่จะถึง</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
async function cancelRequest(id) {
    if (!confirm('ต้องการยกเลิกคำขอลานี้หรือไม่?')) return;
    
    try {
        const response = await fetch('/api/leave.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel', request_id: id, _token: '<?php echo csrfToken(); ?>' })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('ยกเลิกคำขอลาสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
    }
}
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
