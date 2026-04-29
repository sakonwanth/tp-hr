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

<div class="tp-leave-stack tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
    <?php if ($action === 'request'): ?>
    <!-- Leave Request Form -->
    <?php include __DIR__ . '/modules/employee/leaves/request_form.php'; ?>
    
    <?php else: ?>
    <!-- Leave Dashboard -->
    <div class="mb-5 md:mb-8 min-w-0">
        <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between gap-y-4">
            <div class="min-w-0 flex-1">
                <h1 class="tp-ios-page-title">การลา</h1>
                <p class="tp-ios-caption-muted mt-2 max-w-[42rem]">จัดการวันลา ติดตามคำขอ และดูสิทธิ์คงเหลือของคุณ</p>
            </div>
            <a href="?action=request" class="btn-primary btn-primary-prominent w-full sm:w-auto shrink-0 inline-flex items-center justify-center touch-manipulation">
                <i class="fas fa-plus mr-2"></i>ยื่นขอลา
            </a>
        </div>
    </div>
    
    <?php if ($message): ?>
    <div class="tp-native-success-state bg-emerald-500/15 border border-emerald-400/40 text-emerald-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] mb-5 md:mb-6 flex items-start gap-3 max-w-none mx-0 w-full" role="status">
        <i class="fas fa-check-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
        <span class="text-base leading-snug"><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="tp-native-error-state bg-red-500/15 border border-red-400/45 text-red-200 px-4 py-3 rounded-[var(--tp-ios-card-radius)] mb-5 md:mb-6 flex items-start gap-3 max-w-none mx-0 w-full" role="alert">
        <i class="fas fa-exclamation-circle text-2xl shrink-0 mt-0.5" aria-hidden="true"></i>
        <span class="text-base leading-snug"><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 md:gap-8 min-w-0 max-w-full">
        <!-- Left Column -->
        <div class="xl:col-span-2 space-y-5 md:space-y-6 min-w-0">
            <!-- Leave Balance -->
            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <h2 class="section-title mb-4">
                    <i class="fas fa-calendar-check text-green-400 text-2xl" aria-hidden="true"></i>
                    สิทธิ์วันลาคงเหลือ <?php echo date('Y') + 543; ?>
                </h2>
                
                <?php if ($entitlements): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-5 min-w-0">
                    <?php foreach ($entitlements as $ent): ?>
                    <div class="p-4 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/8 min-w-0">
                        <div class="flex items-center justify-between gap-2 mb-2 min-w-0">
                            <span class="text-white font-medium truncate min-w-0"><?php echo htmlspecialchars($ent['name']); ?></span>
                            <span class="text-xs px-2 py-1 rounded-full" style="background-color: <?php echo $ent['color'] ?? '#6B7280'; ?>20; color: <?php echo $ent['color'] ?? '#6B7280'; ?>">
                                <?php echo $ent['code']; ?>
                            </span>
                        </div>
                        
                        <div class="flex items-end justify-between gap-2 min-w-0">
                            <div class="min-w-0">
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
                <div class="tp-native-empty-state text-center py-8 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
                    <i class="fas fa-info-circle text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                    <p class="text-slate-400 text-sm px-4">ยังไม่มีข้อมูลสิทธิ์วันลา กรุณาติดต่อฝ่าย HR</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Leave History -->
            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4 min-w-0">
                    <h2 class="section-title mb-0">
                        <i class="fas fa-history text-blue-400 text-2xl" aria-hidden="true"></i>
                        ประวัติการลา
                    </h2>
                    <a href="leave_history.php" class="inline-flex min-h-[48px] items-center justify-center sm:justify-start text-violet-400 hover:text-violet-300 text-sm font-medium touch-manipulation shrink-0">
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
                    <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/8 p-4">
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
                            <div class="rounded-[var(--tp-ios-card-radius)] bg-black/20 border border-white/8 px-3 py-2">
                                <div class="text-[11px] text-white/50">จำนวนวัน</div>
                                <div class="text-white font-semibold"><?php echo number_format($leave['total_days'], 1); ?> วัน</div>
                            </div>
                            <a href="leave_history.php"
                               class="min-h-[48px] rounded-[var(--tp-ios-card-radius)] bg-white/10 hover:bg-white/20 text-white text-sm font-semibold flex items-center justify-center touch-manipulation">
                                <i class="fas fa-eye mr-2" aria-hidden="true"></i>รายละเอียด
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full -mx-1 px-1 overscroll-x-contain">
                    <table class="w-full min-w-0">
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
                <div class="tp-native-empty-state text-center py-8 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
                    <i class="fas fa-calendar-times text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                    <p class="text-slate-400 text-sm">ยังไม่มีประวัติการลา</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Column -->
        <div class="space-y-5 md:space-y-6 min-w-0">
            <!-- Quick Request -->
            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <h2 class="section-title mb-4">
                    <i class="fas fa-bolt text-yellow-400 text-2xl" aria-hidden="true"></i>
                    ขอลาด่วน
                </h2>
                
                <div class="space-y-3">
                    <?php foreach (array_slice($leave_types, 0, 4) as $type): ?>
                    <a href="?action=request&type=<?php echo $type['id']; ?>" 
                       class="flex min-h-[48px] items-center gap-3 p-3 rounded-[var(--tp-ios-card-radius)] bg-white/5 hover:bg-white/10 border border-white/8 transition-colors touch-manipulation">
                        <div class="w-10 h-10 rounded-[var(--tp-ios-card-radius)] flex items-center justify-center shrink-0" 
                             style="background-color: <?php echo $type['color'] ?? '#6B7280'; ?>20">
                            <i class="fas fa-<?php echo $type['icon'] ?? 'calendar'; ?> text-xl" aria-hidden="true"
                               style="color: <?php echo $type['color'] ?? '#6B7280'; ?>"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-white font-medium truncate"><?php echo htmlspecialchars($type['name']); ?></p>
                            <p class="text-white/50 text-xs truncate"><?php echo htmlspecialchars($type['name_en'] ?? ''); ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Pending Requests -->
            <?php if ($pending_requests): ?>
            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <h2 class="section-title mb-4">
                    <i class="fas fa-hourglass-half text-orange-400 text-2xl" aria-hidden="true"></i>
                    รออนุมัติ
                </h2>
                
                <div class="space-y-3">
                    <?php foreach ($pending_requests as $req): ?>
                    <div class="p-3 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/8">
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
                            <button type="button" onclick="cancelRequest(<?php echo $req['id']; ?>)"
                                    class="w-full md:w-auto md:ml-auto min-h-[48px] px-4 py-2 rounded-[var(--tp-ios-card-radius)] bg-red-500/15 hover:bg-red-500/25 border border-red-500/30 text-red-200 text-sm font-semibold transition-colors touch-manipulation">
                                <i class="fas fa-times mr-2" aria-hidden="true"></i>ยกเลิกคำขอ
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Company Calendar -->
            <div class="native-card tp-native-card tp-native-data-card min-w-0">
                <h2 class="section-title mb-4">
                    <i class="fas fa-calendar text-violet-400 text-2xl" aria-hidden="true"></i>
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
                    <div class="flex items-center gap-3 p-2 rounded-[var(--tp-ios-card-radius)] hover:bg-white/5 border border-transparent hover:border-white/8">
                        <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-red-500/20 flex flex-col items-center justify-center shrink-0">
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
                <div class="tp-native-empty-state text-center py-6 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15 max-w-none mx-0">
                    <p class="text-slate-400 text-sm">ไม่มีวันหยุดที่จะถึง</p>
                </div>
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
            showToast('success', 'สำเร็จ', 'ยกเลิกคำขอลาเรียบร้อยแล้ว');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('error', 'ผิดพลาด', result.error || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        showToast('error', 'ผิดพลาด', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
    }
}
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
