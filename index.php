<?php
/**
 * TP-HR Dashboard - Modern Design
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$isHR = hr_can_access_hr_dashboard();

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

<div class="tp-dashboard-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">

<!-- หัวหน้าแรก: การ์ดสรุปวันที่/ตำแหน่ง — layout จาก CSS ใน header (ไม่ใช้ Tailwind arbitrary grid) -->
<div class="dashboard-hero tp-ios-large-title-block">
    <div class="dashboard-hero-inner">
        <div class="dashboard-hero-main">
            <h1 class="dashboard-hero-title tp-ios-page-title">
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
            <a href="/checkin.php" class="btn-primary btn-primary-prominent touch-manipulation whitespace-nowrap">
                <i class="fas fa-fingerprint mr-2 text-2xl shrink-0" aria-hidden="true"></i>
                ลงเวลา
            </a>
        </div>
    </div>
</div>

<?php if ($isHR): ?>
<!-- HR Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 mb-6 md:mb-10">
    <div class="stat-card tp-native-summary-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-blue-500/15 border border-blue-400/25 transition-colors">
                <i class="fas fa-users text-blue-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">พนักงานทั้งหมด</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['total_employees']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="stat-card tp-native-summary-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-emerald-500/15 border border-emerald-400/25 transition-colors">
                <i class="fas fa-user-check text-emerald-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">ลงเวลาวันนี้</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['today_attendance']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="stat-card tp-native-summary-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-amber-500/15 border border-amber-400/25 transition-colors">
                <i class="fas fa-calendar-times text-amber-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">คำขอลารออนุมัติ</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['pending_leaves']); ?></p>
            </div>
        </div>
    </div>
    
    <div class="stat-card tp-native-summary-card group">
        <div class="flex items-center gap-4">
            <div class="stat-icon bg-violet-500/15 border border-violet-400/25 transition-colors">
                <i class="fas fa-file-alt text-purple-400 text-2xl" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-slate-300 text-sm">คำขอเอกสาร</p>
                <p class="text-2xl font-bold text-white"><?php echo number_format($stats['pending_documents']); ?></p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 md:gap-8">
    <!-- Left Column -->
    <div class="xl:col-span-2 space-y-6">
        
        <!-- Quick Actions -->
        <div class="native-card tp-native-card tp-native-data-card">
            <h2 class="section-title">
                <i class="fas fa-bolt text-amber-400 text-2xl" aria-hidden="true"></i>
                ทางลัดด่วน
            </h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                <a href="/checkin.php" class="quick-action tp-native-quick-action-card group relative border-2 border-emerald-400/35 bg-emerald-500/5">
                    <div class="quick-action-icon bg-emerald-500/20 border border-emerald-400/30">
                        <i class="fas fa-fingerprint text-emerald-300 text-2xl" aria-hidden="true"></i>
                    </div>
                    <span class="text-white font-semibold whitespace-nowrap">ลงเวลา</span>
                </a>
                
                <a href="/leave.php?action=request" class="quick-action tp-native-quick-action-card group">
                    <div class="quick-action-icon bg-blue-500/15 border border-blue-400/25">
                        <i class="fas fa-calendar-plus text-blue-400 text-2xl" aria-hidden="true"></i>
                    </div>
                    <span class="text-white font-medium whitespace-nowrap">ขอลา</span>
                </a>
                
                <a href="/payslip.php" class="quick-action tp-native-quick-action-card group">
                    <div class="quick-action-icon bg-teal-500/15 border border-teal-400/25">
                        <i class="fas fa-file-invoice-dollar text-teal-400 text-2xl" aria-hidden="true"></i>
                    </div>
                    <span class="text-white font-medium whitespace-nowrap">สลิปเงินเดือน</span>
                </a>
                
                <a href="/certificate.php" class="quick-action tp-native-quick-action-card group">
                    <div class="quick-action-icon bg-violet-500/15 border border-violet-400/25">
                        <i class="fas fa-file-signature text-purple-400 text-2xl" aria-hidden="true"></i>
                    </div>
                    <span class="text-white font-medium whitespace-nowrap">ขอใบรับรอง</span>
                </a>
            </div>
        </div>
        
        <!-- Today's Attendance -->
        <div class="native-card tp-native-card tp-native-data-card">
            <h2 class="section-title">
                <i class="fas fa-clock text-blue-400 text-2xl" aria-hidden="true"></i>
                การลงเวลาวันนี้
            </h2>
            
            <?php if ($myData['today_attendance']): ?>
                <div class="tp-ios-attendance-panel flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between p-5 md:p-6">
                    <div class="flex items-center justify-center gap-6 sm:gap-8">
                        <div class="text-center px-2 sm:px-4">
                            <p class="tp-ios-caption-muted text-xs mb-1">เข้างาน</p>
                            <p class="tp-ios-hero-number text-emerald-400">
                                <?php echo $myData['today_attendance']['check_in_time'] 
                                    ? date('H:i', strtotime($myData['today_attendance']['check_in_time'])) 
                                    : '-'; ?>
                            </p>
                        </div>
                        <div class="flex items-center text-slate-600">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="text-center px-2 sm:px-4">
                            <p class="tp-ios-caption-muted text-xs mb-1">ออกงาน</p>
                            <p class="tp-ios-hero-number <?php echo $myData['today_attendance']['check_out_time'] ? 'text-blue-400' : 'text-slate-600'; ?>">
                                <?php echo $myData['today_attendance']['check_out_time'] 
                                    ? date('H:i', strtotime($myData['today_attendance']['check_out_time'])) 
                                    : '-'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if (!$myData['today_attendance']['check_out_time']): ?>
                    <a href="/checkin.php?action=out" class="btn-primary w-full sm:w-auto justify-center shrink-0 touch-manipulation whitespace-nowrap">
                        <i class="fas fa-sign-out-alt mr-2 text-xl" aria-hidden="true"></i>
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
                <div class="tp-native-empty-state text-center py-12 bg-slate-800/30 rounded-[var(--tp-ios-card-radius)] border border-dashed border-slate-600/80">
                    <i class="fas fa-clock text-slate-500 text-4xl mb-4" aria-hidden="true"></i>
                    <p class="text-slate-300 mb-2 text-base">คุณยังไม่ได้ลงเวลาเข้างานวันนี้</p>
                    <p class="text-slate-500 text-sm mb-4">แตะปุ่มด้านล่างหรือทางลัดเพื่อลงเวลา</p>
                    <a href="/checkin.php" class="btn-primary inline-flex whitespace-nowrap">
                        <i class="fas fa-fingerprint mr-2 text-xl" aria-hidden="true"></i>
                        ลงเวลาเข้างาน
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Leave Balance -->
        <div class="native-card tp-native-card tp-native-data-card">
            <div class="flex items-center justify-between mb-4 gap-3">
                <h2 class="section-title mb-0">
                    <i class="fas fa-umbrella-beach text-orange-400 text-2xl" aria-hidden="true"></i>
                    วันลาคงเหลือ
                </h2>
                <a href="/leave.php" class="inline-flex min-h-[48px] items-center text-sm text-primary-400 hover:text-primary-300 font-medium whitespace-nowrap touch-manipulation shrink-0">
                    ดูทั้งหมด <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i>
                </a>
            </div>
            
            <?php if ($myData['leave_balance']): ?>
                <div class="space-y-6">
                    <?php foreach ($myData['leave_balance'] as $leave): ?>
                    <?php 
                        $percentage = $leave['entitled_days'] > 0 
                            ? ($leave['remaining'] / $leave['entitled_days']) * 100 
                            : 0;
                        $barColor = $percentage > 50 ? 'bg-emerald-500' : ($percentage > 20 ? 'bg-amber-500' : 'bg-red-500');
                    ?>
                    <div class="bg-slate-800/50 rounded-[var(--tp-ios-card-radius)] p-5 border border-white/6">
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
                <div class="tp-native-empty-state text-center py-10 rounded-[var(--tp-ios-card-radius)] border border-dashed border-slate-600/60 bg-slate-800/20">
                    <i class="fas fa-calendar-times text-slate-500 text-4xl mb-3" aria-hidden="true"></i>
                    <p class="text-slate-300 text-base">ยังไม่มีข้อมูลสิทธิ์วันลา</p>
                    <p class="text-slate-500 text-sm mt-1">ติดต่อ HR หากควรมีสิทธิ์แต่ไม่แสดง</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Right Column -->
    <div class="space-y-6">
        
        <!-- Pending Requests -->
        <?php if ($myData['pending_leaves']): ?>
        <div class="native-card tp-native-card tp-native-data-card">
            <h2 class="section-title">
                <i class="fas fa-hourglass-half text-amber-400 text-2xl" aria-hidden="true"></i>
                คำขอที่รออนุมัติ
            </h2>
            <div class="space-y-3">
                <?php foreach ($myData['pending_leaves'] as $leave): ?>
                <div class="bg-slate-800/50 rounded-[var(--tp-ios-card-radius)] p-5 border border-white/6">
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
        <div class="native-card tp-native-card tp-native-data-card">
            <h2 class="section-title">
                <i class="fas fa-bullhorn text-red-400 text-2xl" aria-hidden="true"></i>
                ประกาศ
            </h2>
            
            <?php if ($announcements): ?>
                <div class="space-y-3">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="bg-slate-800/50 rounded-[var(--tp-ios-card-radius)] p-5 border border-white/6 hover:bg-slate-800/80 transition-colors cursor-pointer">
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
                <div class="tp-native-empty-state text-center py-10 rounded-[var(--tp-ios-card-radius)] border border-dashed border-slate-600/60 bg-slate-800/20">
                    <i class="fas fa-bullhorn text-slate-500 text-4xl mb-3" aria-hidden="true"></i>
                    <p class="text-slate-300 text-base">ไม่มีประกาศใหม่</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Links for HR -->
        <?php if ($isHR): ?>
        <div class="native-card tp-native-card tp-native-data-card">
            <h2 class="section-title">
                <i class="fas fa-tasks text-primary-400 text-2xl" aria-hidden="true"></i>
                งานรออนุมัติ
            </h2>
            <div class="space-y-2">
                <?php if ($stats['pending_leaves'] > 0): ?>
                <a href="/hr/leaves.php?status=pending" class="flex items-center justify-between min-h-[48px] p-3 rounded-[var(--tp-ios-card-radius)] bg-slate-800/50 hover:bg-primary-500/10 transition-colors group touch-manipulation border border-white/6">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-amber-500/20 border border-amber-400/25 flex items-center justify-center shrink-0">
                            <i class="fas fa-calendar-times text-amber-400 text-xl" aria-hidden="true"></i>
                        </div>
                        <span class="text-white font-medium">คำขอลา</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-amber-400 font-bold"><?php echo $stats['pending_leaves']; ?></span>
                        <i class="fas fa-chevron-right text-slate-600 group-hover:text-primary-400 transition-colors"></i>
                    </div>
                </a>
                <?php endif; ?>
                
                <?php if ($stats['pending_documents'] > 0): ?>
                <a href="/hr/documents.php?status=pending" class="flex items-center justify-between min-h-[48px] p-3 rounded-[var(--tp-ios-card-radius)] bg-slate-800/50 hover:bg-primary-500/10 transition-colors group touch-manipulation border border-white/6">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-purple-500/20 border border-purple-400/25 flex items-center justify-center shrink-0">
                            <i class="fas fa-file-alt text-purple-400 text-xl" aria-hidden="true"></i>
                        </div>
                        <span class="text-white font-medium">คำขอเอกสาร</span>
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

                <a href="/hr/employee_summaries.php" class="flex items-center justify-between min-h-[48px] p-3 rounded-[var(--tp-ios-card-radius)] bg-slate-800/50 hover:bg-primary-500/10 transition-colors group touch-manipulation border border-white/6 mt-2">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-12 h-12 rounded-[var(--tp-ios-card-radius)] bg-blue-500/20 border border-blue-400/25 flex items-center justify-center shrink-0">
                            <i class="fas fa-chart-bar text-blue-400 text-xl" aria-hidden="true"></i>
                        </div>
                        <span class="text-white font-medium">สรุปรายพนักงาน</span>
                    </div>
                    <i class="fas fa-chevron-right text-slate-600 group-hover:text-primary-400 transition-colors"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Primary CTA แบบ sticky เหนือแถบแท็บ — เฉพาะมือถือ/แท็บเล็ต (ซ่อนที่ desktop มีแถบข้าง) -->
<div class="app-shell-mobile-only home-sticky-cta tp-sticky-primary-action print:hidden" role="region" aria-label="ลงเวลาด่วน">
    <div class="home-sticky-cta-inner max-w-lg mx-auto w-full px-0">
        <div class="tp-ios-sticky-cta-slab">
            <a href="/checkin.php" class="tp-native-btn-primary w-full justify-center shadow-lg no-underline text-white gap-2">
                <i class="fas fa-fingerprint" aria-hidden="true"></i>
                <span class="whitespace-nowrap">ลงเวลา</span>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
