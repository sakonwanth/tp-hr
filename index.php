<?php
/**
 * TP-HR - Human Resource Management System
 * หน้าแรก - Dashboard
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$isHR = isHR();

$page_title = 'หน้าแรก';

// Get employee stats for HR
if ($isHR) {
    $stats = [];
    
    // Total employees
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $stats['total_employees'] = $stmt->fetchColumn();
    
    // Today's attendance
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM hr_attendances WHERE attendance_date = CURDATE()");
    $stmt->execute();
    $stats['today_attendance'] = $stmt->fetchColumn();
    
    // Pending leave requests
    $stmt = $pdo->query("SELECT COUNT(*) FROM hr_leave_requests WHERE status = 'PENDING'");
    $stats['pending_leaves'] = $stmt->fetchColumn();
    
    // Pending document requests
    $stmt = $pdo->query("SELECT COUNT(*) FROM hr_document_requests WHERE status = 'PENDING'");
    $stats['pending_documents'] = $stmt->fetchColumn();
}

// Get employee's own data
$myData = [];

// Today's attendance
$stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
$stmt->execute([$user['id']]);
$myData['today_attendance'] = $stmt->fetch();

// Leave balance
$stmt = $pdo->prepare("
    SELECT lt.name, le.entitled_days, le.used_days, 
           (le.entitled_days + le.carried_over_days - le.used_days - le.pending_days) as remaining
    FROM hr_leave_entitlements le
    JOIN hr_leave_types lt ON le.leave_type_id = lt.id
    WHERE le.user_id = ? AND le.year = YEAR(CURDATE())
    ORDER BY lt.sort_order
    LIMIT 5
");
$stmt->execute([$user['id']]);
$myData['leave_balance'] = $stmt->fetchAll();

// Pending leave requests
$stmt = $pdo->prepare("
    SELECT lr.*, lt.name as leave_type_name
    FROM hr_leave_requests lr
    JOIN hr_leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.user_id = ? AND lr.status = 'PENDING'
    ORDER BY lr.created_at DESC
    LIMIT 3
");
$stmt->execute([$user['id']]);
$myData['pending_leaves'] = $stmt->fetchAll();

// Recent announcements
$stmt = $pdo->query("
    SELECT * FROM hr_announcements 
    WHERE is_active = 1 
      AND (publish_date IS NULL OR publish_date <= NOW())
      AND (expire_date IS NULL OR expire_date >= NOW())
    ORDER BY is_pinned DESC, publish_date DESC
    LIMIT 5
");
$announcements = $stmt->fetchAll();

require_once __DIR__ . '/templates/header.php';
?>

<main class="content-area p-6">
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">
            สวัสดี<?php echo $user['title'] ?? ''; ?><?php echo $user['first_name_th'] ?? $user['username']; ?>
        </h1>
        <p class="text-white/60 mt-1">
            <?php echo formatDateThai(date('Y-m-d')); ?> | 
            <?php echo $user['position'] ?? $user['department'] ?? 'พนักงาน'; ?>
        </p>
    </div>
    
    <?php if ($isHR): ?>
    <!-- HR Dashboard Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="glass-card p-4 rounded-xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-blue-500/20 flex items-center justify-center">
                    <i class="fas fa-users text-blue-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-white/60 text-sm">พนักงานทั้งหมด</p>
                    <p class="text-2xl font-bold text-white"><?php echo number_format($stats['total_employees']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="glass-card p-4 rounded-xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-green-500/20 flex items-center justify-center">
                    <i class="fas fa-user-check text-green-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-white/60 text-sm">ลงเวลาวันนี้</p>
                    <p class="text-2xl font-bold text-white"><?php echo number_format($stats['today_attendance']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="glass-card p-4 rounded-xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-yellow-500/20 flex items-center justify-center">
                    <i class="fas fa-calendar-times text-yellow-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-white/60 text-sm">คำขอลารออนุมัติ</p>
                    <p class="text-2xl font-bold text-white"><?php echo number_format($stats['pending_leaves']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="glass-card p-4 rounded-xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-lg bg-purple-500/20 flex items-center justify-center">
                    <i class="fas fa-file-alt text-purple-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-white/60 text-sm">คำขอเอกสาร</p>
                    <p class="text-2xl font-bold text-white"><?php echo number_format($stats['pending_documents']); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Quick Actions -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-bolt text-yellow-400 mr-2"></i>
                    ทางลัดด่วน
                </h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <a href="checkin.php" class="flex flex-col items-center p-4 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-12 h-12 rounded-full bg-green-500/20 flex items-center justify-center mb-2">
                            <i class="fas fa-fingerprint text-green-400 text-xl"></i>
                        </div>
                        <span class="text-white text-sm text-center">ลงเวลา</span>
                    </a>
                    
                    <a href="leave.php?action=request" class="flex flex-col items-center p-4 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-12 h-12 rounded-full bg-blue-500/20 flex items-center justify-center mb-2">
                            <i class="fas fa-calendar-plus text-blue-400 text-xl"></i>
                        </div>
                        <span class="text-white text-sm text-center">ขอลา</span>
                    </a>
                    
                    <a href="payslip.php" class="flex flex-col items-center p-4 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center mb-2">
                            <i class="fas fa-file-invoice-dollar text-emerald-400 text-xl"></i>
                        </div>
                        <span class="text-white text-sm text-center">สลิปเงินเดือน</span>
                    </a>
                    
                    <a href="document.php?action=request" class="flex flex-col items-center p-4 rounded-lg bg-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-12 h-12 rounded-full bg-purple-500/20 flex items-center justify-center mb-2">
                            <i class="fas fa-file-certificate text-purple-400 text-xl"></i>
                        </div>
                        <span class="text-white text-sm text-center">ขอใบรับรอง</span>
                    </a>
                </div>
            </div>
            
            <!-- Today's Attendance -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-clock text-blue-400 mr-2"></i>
                    การลงเวลาวันนี้
                </h2>
                
                <?php if ($myData['today_attendance']): ?>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="text-center">
                                <p class="text-white/60 text-xs">เข้างาน</p>
                                <p class="text-xl font-bold text-green-400">
                                    <?php echo $myData['today_attendance']['check_in_time'] 
                                        ? date('H:i', strtotime($myData['today_attendance']['check_in_time'])) 
                                        : '-'; ?>
                                </p>
                            </div>
                            <i class="fas fa-arrow-right text-white/30"></i>
                            <div class="text-center">
                                <p class="text-white/60 text-xs">ออกงาน</p>
                                <p class="text-xl font-bold <?php echo $myData['today_attendance']['check_out_time'] ? 'text-blue-400' : 'text-white/30'; ?>">
                                    <?php echo $myData['today_attendance']['check_out_time'] 
                                        ? date('H:i', strtotime($myData['today_attendance']['check_out_time'])) 
                                        : '-'; ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if (!$myData['today_attendance']['check_out_time']): ?>
                        <a href="checkin.php?action=out" class="btn-primary">
                            <i class="fas fa-sign-out-alt mr-1"></i> ลงเวลาออก
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-6">
                        <i class="fas fa-clock text-white/20 text-4xl mb-3"></i>
                        <p class="text-white/60 mb-4">คุณยังไม่ได้ลงเวลาเข้างานวันนี้</p>
                        <a href="checkin.php" class="btn-primary">
                            <i class="fas fa-fingerprint mr-1"></i> ลงเวลาเข้างาน
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Leave Balance -->
            <div class="glass-card rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-white">
                        <i class="fas fa-umbrella-beach text-orange-400 mr-2"></i>
                        วันลาคงเหลือ
                    </h2>
                    <a href="leave.php" class="text-sm text-violet-400 hover:text-violet-300">
                        ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                
                <?php if ($myData['leave_balance']): ?>
                    <div class="space-y-3">
                        <?php foreach ($myData['leave_balance'] as $leave): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-white/80"><?php echo htmlspecialchars($leave['name']); ?></span>
                            <div class="flex items-center gap-2">
                                <span class="text-white font-medium"><?php echo number_format($leave['remaining'], 1); ?></span>
                                <span class="text-white/50">/ <?php echo number_format($leave['entitled_days'], 1); ?> วัน</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-white/60 text-center py-4">ยังไม่มีข้อมูลสิทธิ์วันลา</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Column -->
        <div class="space-y-6">
            <!-- Pending Requests -->
            <?php if ($myData['pending_leaves']): ?>
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-hourglass-half text-yellow-400 mr-2"></i>
                    คำขอที่รอดำเนินการ
                </h2>
                <div class="space-y-3">
                    <?php foreach ($myData['pending_leaves'] as $leave): ?>
                    <div class="p-3 rounded-lg bg-white/5">
                        <div class="flex items-center justify-between">
                            <span class="text-white"><?php echo htmlspecialchars($leave['leave_type_name']); ?></span>
                            <span class="px-2 py-1 text-xs rounded bg-yellow-500/20 text-yellow-400">รออนุมัติ</span>
                        </div>
                        <p class="text-white/60 text-sm mt-1">
                            <?php echo formatDateThai($leave['start_date']); ?> - <?php echo formatDateThai($leave['end_date']); ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Announcements -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-bullhorn text-red-400 mr-2"></i>
                    ประกาศ
                </h2>
                
                <?php if ($announcements): ?>
                    <div class="space-y-3">
                        <?php foreach ($announcements as $ann): ?>
                        <div class="p-3 rounded-lg bg-white/5 hover:bg-white/10 transition-colors cursor-pointer">
                            <div class="flex items-start gap-2">
                                <?php if ($ann['is_pinned']): ?>
                                <i class="fas fa-thumbtack text-red-400 mt-1"></i>
                                <?php endif; ?>
                                <div>
                                    <h3 class="text-white font-medium"><?php echo htmlspecialchars($ann['title']); ?></h3>
                                    <p class="text-white/60 text-sm mt-1">
                                        <?php echo formatDateThai($ann['publish_date'] ?? $ann['created_at']); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-white/60 text-center py-4">ไม่มีประกาศใหม่</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
