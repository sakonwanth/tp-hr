<?php
/**
 * TP-HR Dashboard - Modern Design
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$isHR = isHR();

$page_title = 'หน้าแรก';
$current_page = 'dashboard';

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

<!-- หัวหน้าแรก: การ์ดสรุปวันที่/ตำแหน่ง — layout จาก CSS ใน header (ไม่ใช้ Tailwind arbitrary grid) -->
<div class="dashboard-hero">
    <div class="dashboard-hero-inner">
        <div class="dashboard-hero-main">
            <h1 class="dashboard-hero-title">
                สวัสดี, <?php echo htmlspecialchars($user['first_name_th'] ?? $user['username']); ?>
            </h1>
            <div class="dashboard-hero-summary">
                <div class="dashboard-hero-row">
                    <div class="dashboard-hero-icon" aria-hidden="true"><i class="fas fa-calendar-day"></i></div>
                    <div class="dashboard-hero-text"><?php echo formatDateThai(date('Y-m-d')); ?></div>
                </div>
                <div class="dashboard-hero-row">
                    <div class="dashboard-hero-icon" aria-hidden="true"><i class="fas fa-briefcase"></i></div>
                    <div class="dashboard-hero-text"><?php echo htmlspecialchars($user['position'] ?? $user['role_name'] ?? 'พนักงาน'); ?></div>
                </div>
            </div>
        </div>
        <div class="dashboard-hero-cta">
            <a href="/checkin.php" class="btn-primary btn-primary-prominent touch-manipulation">
                <i class="fas fa-fingerprint mr-2 text-lg"></i>
                ลงเวลา
            </a>
        </div>
    </div>
</div>

<?php if ($isHR): ?>
<!-- HR Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="stat-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-gradient-to-br from-blue-500/20 to-blue-600/20 group-hover:from-blue-500/30 group-hover:to-blue-600/30 transition-colors">
                <i class="fas fa-users text-blue-400 text-xl"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">พนักงานทั้งหมด</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['total_employees']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="stat-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-gradient-to-br from-emerald-500/20 to-emerald-600/20 group-hover:from-emerald-500/30 group-hover:to-emerald-600/30 transition-colors">
                <i class="fas fa-user-check text-emerald-400 text-xl"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">ลงเวลาวันนี้</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['today_attendance']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="stat-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-gradient-to-br from-amber-500/20 to-amber-600/20 group-hover:from-amber-500/30 group-hover:to-amber-600/30 transition-colors">
                <i class="fas fa-calendar-times text-amber-400 text-xl"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">คำขอลารออนุมัติ</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['pending_leaves']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="stat-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-gradient-to-br from-purple-500/20 to-purple-600/20 group-hover:from-purple-500/30 group-hover:to-purple-600/30 transition-colors">
                <i class="fas fa-file-alt text-purple-400 text-xl"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">คำขอเอกสาร</p>
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
        <div class="glass-card rounded-2xl p-6">
            <h2 class="section-title">
                <i class="fas fa-bolt text-amber-400"></i>
                ทางลัดด่วน
            </h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="/checkin.php" class="quick-action group relative ring-2 ring-emerald-400/35 ring-offset-2 ring-offset-slate-900/80 shadow-lg shadow-emerald-500/10">
                    <div class="quick-action-icon bg-gradient-to-br from-emerald-500/30 to-emerald-600/25 group-hover:from-emerald-500/45 group-hover:to-emerald-600/40">
                        <i class="fas fa-fingerprint text-emerald-300 text-xl" aria-hidden="true"></i>
                    </div>
                    <span class="text-white font-semibold">ลงเวลา</span>
                </a>
                
                <a href="/leave.php?action=request" class="quick-action group">
                    <div class="quick-action-icon bg-gradient-to-br from-blue-500/20 to-blue-600/20 group-hover:from-blue-500/40 group-hover:to-blue-600/40">
                        <i class="fas fa-calendar-plus text-blue-400"></i>
                    </div>
                    <span class="text-white font-medium">ขอลา</span>
                </a>
                
                <a href="/payslip.php" class="quick-action group">
                    <div class="quick-action-icon bg-gradient-to-br from-teal-500/20 to-teal-600/20 group-hover:from-teal-500/40 group-hover:to-teal-600/40">
                        <i class="fas fa-file-invoice-dollar text-teal-400"></i>
                    </div>
                    <span class="text-white font-medium">สลิปเงินเดือน</span>
                </a>
                
                <a href="/certificate.php" class="quick-action group">
                    <div class="quick-action-icon bg-gradient-to-br from-purple-500/20 to-purple-600/20 group-hover:from-purple-500/40 group-hover:to-purple-600/40">
                        <i class="fas fa-file-signature text-purple-400" aria-hidden="true"></i>
                    </div>
                    <span class="text-white font-medium">ขอใบรับรอง</span>
                </a>
            </div>
        </div>
        
        <!-- Today's Attendance -->
        <div class="glass-card rounded-2xl p-6">
            <h2 class="section-title">
                <i class="fas fa-clock text-blue-400"></i>
                การลงเวลาวันนี้
            </h2>
            
            <?php if ($myData['today_attendance']): ?>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-slate-800/50 rounded-xl p-4">
                    <div class="flex items-center justify-center gap-6 sm:gap-8">
                        <div class="text-center px-2 sm:px-4">
                            <p class="text-slate-400 text-xs mb-1">เข้างาน</p>
                            <p class="text-2xl font-bold text-emerald-400 tabular-nums">
                                <?php echo $myData['today_attendance']['check_in_time'] 
                                    ? date('H:i', strtotime($myData['today_attendance']['check_in_time'])) 
                                    : '-'; ?>
                            </p>
                        </div>
                        <div class="flex items-center text-slate-600">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="text-center px-2 sm:px-4">
                            <p class="text-slate-400 text-xs mb-1">ออกงาน</p>
                            <p class="text-2xl font-bold tabular-nums <?php echo $myData['today_attendance']['check_out_time'] ? 'text-blue-400' : 'text-slate-600'; ?>">
                                <?php echo $myData['today_attendance']['check_out_time'] 
                                    ? date('H:i', strtotime($myData['today_attendance']['check_out_time'])) 
                                    : '-'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if (!$myData['today_attendance']['check_out_time']): ?>
                    <a href="/checkin.php?action=out" class="btn-primary w-full sm:w-auto justify-center shrink-0 touch-manipulation">
                        <i class="fas fa-sign-out-alt mr-2"></i>
                        ลงเวลาออก
                    </a>
                    <?php else: ?>
                    <span class="mx-auto inline-flex w-fit max-w-full shrink-0 items-center gap-2 rounded-full border border-emerald-500/35 bg-emerald-500/15 px-4 py-2.5 text-sm font-semibold text-emerald-300 sm:mx-0 sm:self-auto">
                        <i class="fas fa-check-circle text-base" aria-hidden="true"></i>
                        เสร็จสิ้น
                    </span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12 bg-slate-800/30 rounded-xl border border-dashed border-slate-700">
                    <i class="fas fa-clock text-slate-600 text-5xl mb-4"></i>
                    <p class="text-slate-400 mb-4">คุณยังไม่ได้ลงเวลาเข้างานวันนี้</p>
                    <a href="/checkin.php" class="btn-primary">
                        <i class="fas fa-fingerprint mr-2"></i>
                        ลงเวลาเข้างาน
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Leave Balance -->
        <div class="glass-card rounded-2xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="section-title mb-0">
                    <i class="fas fa-umbrella-beach text-orange-400"></i>
                    วันลาคงเหลือ
                </h2>
                <a href="/leave.php" class="text-sm text-primary-400 hover:text-primary-300 font-medium">
                    ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            
            <?php if ($myData['leave_balance']): ?>
                <div class="space-y-4">
                    <?php foreach ($myData['leave_balance'] as $leave): ?>
                    <?php 
                        $percentage = $leave['entitled_days'] > 0 
                            ? ($leave['remaining'] / $leave['entitled_days']) * 100 
                            : 0;
                        $barColor = $percentage > 50 ? 'bg-emerald-500' : ($percentage > 20 ? 'bg-amber-500' : 'bg-red-500');
                    ?>
                    <div class="bg-slate-800/50 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-white font-medium"><?php echo htmlspecialchars($leave['name']); ?></span>
                            <div class="flex items-center gap-1">
                                <span class="text-white font-bold"><?php echo number_format($leave['remaining'], 1); ?></span>
                                <span class="text-slate-500">/ <?php echo number_format($leave['entitled_days'], 1); ?> วัน</span>
                            </div>
                        </div>
                        <div class="w-full h-2 bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full <?php echo $barColor; ?> rounded-full transition-all" style="width: <?php echo min(100, max(0, $percentage)); ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-calendar-times text-slate-600 text-3xl mb-3"></i>
                    <p class="text-slate-400">ยังไม่มีข้อมูลสิทธิ์วันลา</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Right Column -->
    <div class="space-y-6">
        
        <!-- Pending Requests -->
        <?php if ($myData['pending_leaves']): ?>
        <div class="glass-card rounded-2xl p-6">
            <h2 class="section-title">
                <i class="fas fa-hourglass-half text-amber-400"></i>
                คำขอที่รออนุมัติ
            </h2>
            <div class="space-y-3">
                <?php foreach ($myData['pending_leaves'] as $leave): ?>
                <div class="bg-slate-800/50 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-white font-medium"><?php echo htmlspecialchars($leave['leave_type_name']); ?></span>
                        <span class="badge badge-warning">รออนุมัติ</span>
                    </div>
                    <p class="text-slate-400 text-sm">
                        <i class="fas fa-calendar mr-1"></i>
                        <?php echo formatDateThai($leave['start_date']); ?>
                        <?php if ($leave['start_date'] !== $leave['end_date']): ?>
                        - <?php echo formatDateThai($leave['end_date']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Announcements -->
        <div class="glass-card rounded-2xl p-6">
            <h2 class="section-title">
                <i class="fas fa-bullhorn text-red-400"></i>
                ประกาศ
            </h2>
            
            <?php if ($announcements): ?>
                <div class="space-y-3">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="bg-slate-800/50 rounded-xl p-4 hover:bg-slate-800/80 transition-colors cursor-pointer">
                        <?php if ($ann['is_pinned']): ?>
                        <span class="inline-flex items-center text-xs text-red-400 mb-2">
                            <i class="fas fa-thumbtack mr-1"></i>
                            ปักหมุด
                        </span>
                        <?php endif; ?>
                        <h3 class="text-white font-medium mb-1 line-clamp-1"><?php echo htmlspecialchars($ann['title']); ?></h3>
                        <p class="text-slate-400 text-sm line-clamp-2"><?php echo htmlspecialchars(strip_tags($ann['content'])); ?></p>
                        <p class="text-slate-500 text-xs mt-2">
                            <i class="fas fa-clock mr-1"></i>
                            <?php echo formatDateThai($ann['publish_date'] ?? $ann['created_at']); ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-bullhorn text-slate-600 text-3xl mb-3"></i>
                    <p class="text-slate-400">ไม่มีประกาศใหม่</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Links for HR -->
        <?php if ($isHR): ?>
        <div class="glass-card rounded-2xl p-6">
            <h2 class="section-title">
                <i class="fas fa-tasks text-primary-400"></i>
                งานรออนุมัติ
            </h2>
            <div class="space-y-2">
                <?php if ($stats['pending_leaves'] > 0): ?>
                <a href="/hr/leaves.php?status=pending" class="flex items-center justify-between p-3 rounded-xl bg-slate-800/50 hover:bg-primary-500/10 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
                            <i class="fas fa-calendar-times text-amber-400 text-sm"></i>
                        </div>
                        <span class="text-white">คำขอลา</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-amber-400 font-bold"><?php echo $stats['pending_leaves']; ?></span>
                        <i class="fas fa-chevron-right text-slate-600 group-hover:text-primary-400 transition-colors"></i>
                    </div>
                </a>
                <?php endif; ?>
                
                <?php if ($stats['pending_documents'] > 0): ?>
                <a href="/hr/documents.php?status=pending" class="flex items-center justify-between p-3 rounded-xl bg-slate-800/50 hover:bg-primary-500/10 transition-colors group">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-purple-500/20 flex items-center justify-center">
                            <i class="fas fa-file-alt text-purple-400 text-sm"></i>
                        </div>
                        <span class="text-white">คำขอเอกสาร</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-purple-400 font-bold"><?php echo $stats['pending_documents']; ?></span>
                        <i class="fas fa-chevron-right text-slate-600 group-hover:text-primary-400 transition-colors"></i>
                    </div>
                </a>
                <?php endif; ?>
                
                <?php if ($stats['pending_leaves'] == 0 && $stats['pending_documents'] == 0): ?>
                <div class="text-center py-4">
                    <i class="fas fa-check-circle text-emerald-400 text-xl mb-2"></i>
                    <p class="text-slate-400 text-sm">ไม่มีงานรออนุมัติ</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
