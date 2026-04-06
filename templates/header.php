<?php
/**
 * TP-HR Header Template
 */
$current_user = Auth::user();
$isHR = isHR();
$current_page = $current_page ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <title><?php echo htmlspecialchars($page_title ?? 'TP-HR'); ?> - TP-HR</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts - Sarabun -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Sarabun', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(20px);
        }
        
        .nav-link {
            @apply flex items-center gap-3 px-4 py-3 text-white/70 hover:text-white hover:bg-white/10 rounded-lg transition-all;
        }
        
        .nav-link.active {
            @apply text-white bg-violet-600/50;
        }
        
        .btn-primary {
            @apply inline-flex items-center justify-center px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors;
        }
        
        .btn-secondary {
            @apply inline-flex items-center justify-center px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors;
        }
        
        .input-field {
            @apply w-full px-4 py-2 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:border-violet-500;
        }
        
        .content-area {
            margin-left: 260px;
            min-height: 100vh;
        }
        
        @media (max-width: 1024px) {
            .content-area {
                margin-left: 0;
            }
        }
    </style>
</head>
<body class="text-white">

<!-- Sidebar -->
<aside class="sidebar fixed left-0 top-0 w-[260px] h-screen overflow-y-auto hidden lg:block z-50">
    <div class="p-6">
        <!-- Logo -->
        <a href="/" class="flex items-center gap-3 mb-8">
            <div class="w-10 h-10 rounded-lg bg-violet-600 flex items-center justify-center">
                <i class="fas fa-users text-white"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-white">TP-HR</h1>
                <p class="text-xs text-white/60">Human Resources</p>
            </div>
        </a>
        
        <!-- Navigation -->
        <nav class="space-y-2">
            <a href="/" class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home w-5"></i>
                <span>หน้าแรก</span>
            </a>
            
            <a href="/checkin.php" class="nav-link <?php echo $current_page === 'checkin' ? 'active' : ''; ?>">
                <i class="fas fa-fingerprint w-5"></i>
                <span>ลงเวลาเข้า-ออก</span>
            </a>
            
            <a href="/leave.php" class="nav-link <?php echo $current_page === 'leave' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt w-5"></i>
                <span>การลา</span>
            </a>
            
            <a href="/payslip.php" class="nav-link <?php echo $current_page === 'payslip' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar w-5"></i>
                <span>สลิปเงินเดือน</span>
            </a>
            
            <a href="/document.php" class="nav-link <?php echo $current_page === 'document' ? 'active' : ''; ?>">
                <i class="fas fa-file-certificate w-5"></i>
                <span>ขอใบรับรอง</span>
            </a>
            
            <a href="/profile.php" class="nav-link <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user w-5"></i>
                <span>ข้อมูลส่วนตัว</span>
            </a>
            
            <?php if ($isHR): ?>
            <div class="pt-4 mt-4 border-t border-white/10">
                <p class="text-xs text-white/40 uppercase tracking-wider mb-2 px-4">HR Admin</p>
                
                <a href="/admin/employees.php" class="nav-link <?php echo $current_page === 'admin-employees' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog w-5"></i>
                    <span>จัดการพนักงาน</span>
                </a>
                
                <a href="/admin/attendance.php" class="nav-link <?php echo $current_page === 'admin-attendance' ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock w-5"></i>
                    <span>จัดการลงเวลา</span>
                </a>
                
                <a href="/admin/leaves.php" class="nav-link <?php echo $current_page === 'admin-leaves' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check w-5"></i>
                    <span>อนุมัติการลา</span>
                </a>
                
                <a href="/admin/documents.php" class="nav-link <?php echo $current_page === 'admin-documents' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt w-5"></i>
                    <span>จัดการเอกสาร</span>
                </a>
                
                <a href="/admin/reports.php" class="nav-link <?php echo $current_page === 'admin-reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar w-5"></i>
                    <span>รายงาน</span>
                </a>
                
                <a href="/admin/settings.php" class="nav-link <?php echo $current_page === 'admin-settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog w-5"></i>
                    <span>ตั้งค่าระบบ</span>
                </a>
            </div>
            <?php endif; ?>
        </nav>
        
        <!-- TP-CRM Link -->
        <?php if (TP_CRM_AVAILABLE): ?>
        <div class="mt-6 pt-4 border-t border-white/10">
            <a href="/tp-crm/" class="nav-link">
                <i class="fas fa-exchange-alt w-5"></i>
                <span>ไป TP-CRM</span>
            </a>
        </div>
        <?php endif; ?>
    </div>
</aside>

<!-- Top Bar (Mobile) -->
<header class="lg:hidden fixed top-0 left-0 right-0 h-16 bg-black/30 backdrop-blur-lg z-40 flex items-center justify-between px-4">
    <button id="mobileMenuBtn" class="text-white">
        <i class="fas fa-bars text-xl"></i>
    </button>
    
    <a href="/" class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-violet-600 flex items-center justify-center">
            <i class="fas fa-users text-white text-sm"></i>
        </div>
        <span class="font-bold text-white">TP-HR</span>
    </a>
    
    <a href="/profile.php" class="text-white">
        <i class="fas fa-user-circle text-xl"></i>
    </a>
</header>

<!-- User Info Bar -->
<div class="content-area">
    <div class="bg-black/20 backdrop-blur-sm py-3 px-6 flex items-center justify-between">
        <div class="hidden lg:flex items-center gap-4 text-sm">
            <span class="text-white/60">
                <i class="fas fa-user-circle mr-1"></i>
                <?php echo htmlspecialchars(getUserFullName($current_user)); ?>
            </span>
            <span class="text-white/40">|</span>
            <span class="text-white/60">
                <?php echo htmlspecialchars($current_user['position'] ?? $current_user['department'] ?? '-'); ?>
            </span>
        </div>
        
        <div class="flex items-center gap-4">
            <a href="/notifications.php" class="text-white/60 hover:text-white relative">
                <i class="fas fa-bell"></i>
                <!-- <span class="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full"></span> -->
            </a>
            
            <a href="/logout.php" class="text-white/60 hover:text-white">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
