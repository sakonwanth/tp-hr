<?php
/**
 * TP-HR Header Template - Modern Design
 */
$current_user = Auth::user();
$isHR = isHR();
$isCEO = isCEOOrAbove();
$current_page = $current_page ?? '';
$appIconPath = '/assets/icons/tphr-app-icon.svg';
$appTouchIconPath = '/assets/icons/apple-touch-icon-v2.png';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <title><?php echo htmlspecialchars($page_title ?? 'TP-HR'); ?> - TP-HR</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($appIconPath); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($appTouchIconPath); ?>">
    
    <!-- Tailwind CSS (compiled) -->
    <link rel="stylesheet" href="/assets/css/app.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'IBM Plex Sans Thai', sans-serif;
        }
        
        body {
            background: #0f172a;
            min-height: 100vh;
        }
        
        .sidebar {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.98) 0%, rgba(30, 41, 59, 0.98) 100%);
            border-right: 1px solid rgba(148, 163, 184, 0.1);
        }
        
        .glass-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.9) 100%);
            border: 1px solid rgba(148, 163, 184, 0.1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }
        
        .glass-card:hover {
            border-color: rgba(139, 92, 246, 0.3);
            box-shadow: 0 10px 25px -5px rgba(139, 92, 246, 0.15);
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            color: rgba(148, 163, 184, 1);
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 4px;
        }
        
        .nav-item:hover {
            background: rgba(139, 92, 246, 0.1);
            color: #fff;
        }
        
        .nav-item.active {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
        }
        
        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.95) 100%);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px;
            border-radius: 16px;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s ease;
        }
        
        .quick-action:hover {
            background: rgba(139, 92, 246, 0.15);
            border-color: rgba(139, 92, 246, 0.3);
            transform: translateY(-4px);
        }
        
        .quick-action-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            font-size: 1.5rem;
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #fff;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
        }
        
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            background: rgba(148, 163, 184, 0.1);
            color: #fff;
            border-radius: 10px;
            font-weight: 500;
            border: 1px solid rgba(148, 163, 184, 0.2);
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: rgba(148, 163, 184, 0.2);
        }
        
        .input-field {
            width: 100%;
            padding: 12px 16px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            color: #fff;
            transition: all 0.2s ease;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
        }
        
        .input-field::placeholder {
            color: rgba(148, 163, 184, 0.5);
        }
        
        .content-area {
            margin-left: 280px;
            min-height: 100vh;
        }
        
        @media (max-width: 1024px) {
            .content-area {
                margin-left: 0;
                padding-top: 64px;
                padding-bottom: 88px; /* space for mobile bottom nav */
            }
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.125rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 16px;
        }
        
        .section-title i {
            font-size: 1rem;
        }
        
        /* Badge Styles */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }
        
        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.5);
        }
        
        /* Table Styles */
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .data-table th {
            background: rgba(30, 41, 59, 0.5);
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: rgba(148, 163, 184, 1);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table th:first-child {
            border-radius: 12px 0 0 0;
        }
        
        .data-table th:last-child {
            border-radius: 0 12px 0 0;
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            color: #fff;
        }
        
        .data-table tbody tr:hover {
            background: rgba(139, 92, 246, 0.05);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeInUp 0.3s ease forwards;
        }
    </style>
</head>
<body class="text-slate-300">

<!-- Sidebar -->
<aside class="sidebar fixed left-0 top-0 w-[280px] h-screen overflow-y-auto hidden lg:block z-50">
    <div class="p-6">
        <!-- Logo -->
        <a href="/" class="flex items-center gap-4 mb-8 group">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center shadow-lg shadow-primary-500/30 group-hover:scale-105 transition-transform">
                <i class="fas fa-users text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-white">TP-HR</h1>
                <p class="text-xs text-slate-500">Human Resources</p>
            </div>
        </a>
        
        <!-- User Info -->
        <div class="mb-6 p-4 rounded-2xl bg-slate-800/50 border border-slate-700/50">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white font-bold">
                    <?php echo mb_substr($current_user['first_name_th'] ?? $current_user['username'], 0, 1); ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-white font-medium truncate"><?php echo htmlspecialchars($current_user['first_name_th'] ?? $current_user['username']); ?></p>
                    <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($current_user['position'] ?? $current_user['role_name'] ?? 'พนักงาน'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Main Navigation -->
        <nav class="space-y-1">
            <a href="/" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>หน้าแรก</span>
            </a>
            
            <a href="/checkin.php" class="nav-item <?php echo $current_page === 'checkin' ? 'active' : ''; ?>">
                <i class="fas fa-fingerprint"></i>
                <span>ลงเวลาเข้า-ออก</span>
            </a>
            
            <a href="/leave.php" class="nav-item <?php echo $current_page === 'leave' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>การลา</span>
            </a>
            
            <a href="/payslip.php" class="nav-item <?php echo $current_page === 'payslip' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>สลิปเงินเดือน</span>
            </a>
            
            <a href="/certificate.php" class="nav-item <?php echo $current_page === 'certificate' ? 'active' : ''; ?>">
                <i class="fas fa-file-certificate"></i>
                <span>ขอใบรับรอง</span>
            </a>
            
            <a href="/dayoff_schedule.php" class="nav-item <?php echo $current_page === 'dayoff' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-week"></i>
                <span>วันหยุดประจำสัปดาห์</span>
            </a>
            
            <a href="/profile.php" class="nav-item <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i>
                <span>ข้อมูลส่วนตัว</span>
            </a>
        </nav>
        
        <?php if ($isHR): ?>
        <!-- HR Admin Section -->
        <div class="mt-6 pt-6 border-t border-slate-700/50">
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-3 px-2 font-semibold">HR Admin</p>
            
            <nav class="space-y-1">
                <a href="/hr/employees.php" class="nav-item <?php echo $current_page === 'hr-employees' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>จัดการพนักงาน</span>
                </a>
                
                <a href="/hr/attendance.php" class="nav-item <?php echo $current_page === 'hr-attendance' ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock"></i>
                    <span>จัดการลงเวลา</span>
                </a>
                
                <a href="/hr/leaves.php" class="nav-item <?php echo $current_page === 'hr-leaves' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>อนุมัติการลา</span>
                </a>
                
                <?php if ($isCEO): ?>
                <a href="/hr/dayoff_approvals.php" class="nav-item <?php echo $current_page === 'hr-dayoff' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-day"></i>
                    <span>อนุมัติเปลี่ยนวันหยุด</span>
                </a>
                <?php endif; ?>
                
                <a href="/hr/documents.php" class="nav-item <?php echo $current_page === 'hr-documents' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i>
                    <span>จัดการเอกสาร</span>
                </a>

                <a href="/hr/document_templates.php" class="nav-item <?php echo $current_page === 'hr-document-templates' ? 'active' : ''; ?>">
                    <i class="fas fa-file-signature"></i>
                    <span>ตั้งค่าเอกสารรับรอง</span>
                </a>
                
                <?php if ($isCEO): ?>
                <a href="/hr/reports.php" class="nav-item <?php echo $current_page === 'hr-reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>รายงาน</span>
                </a>
                
                <a href="/hr/settings.php" class="nav-item <?php echo $current_page === 'hr-settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>ตั้งค่าระบบ</span>
                </a>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
        
        <!-- Logout -->
        <div class="mt-6 pt-6 border-t border-slate-700/50">
            <a href="/logout.php" class="nav-item text-red-400 hover:bg-red-500/10 hover:text-red-300">
                <i class="fas fa-sign-out-alt"></i>
                <span>ออกจากระบบ</span>
            </a>
        </div>
    </div>
</aside>

<!-- Mobile Header -->
<header class="lg:hidden fixed top-0 left-0 right-0 h-16 bg-slate-900/95 backdrop-blur-lg border-b border-slate-800 z-40 flex items-center justify-between px-4">
    <button id="mobileMenuBtn" class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center text-white">
        <i class="fas fa-bars"></i>
    </button>
    
    <a href="/" class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center">
            <i class="fas fa-users text-white text-sm"></i>
        </div>
        <span class="font-bold text-white">TP-HR</span>
    </a>
    
    <a href="/profile.php" class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center text-white">
        <i class="fas fa-user"></i>
    </a>
</header>

<!-- Mobile Sidebar -->
<div id="mobileSidebar" class="lg:hidden fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeMobileMenu()"></div>
    <aside class="sidebar absolute left-0 top-0 w-[280px] h-full overflow-y-auto transform transition-transform">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <a href="/" class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center">
                        <i class="fas fa-users text-white"></i>
                    </div>
                    <span class="text-lg font-bold text-white">TP-HR</span>
                </a>
                <button onclick="closeMobileMenu()" class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <nav class="space-y-1">
                <a href="/" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>หน้าแรก</span>
                </a>
                <a href="/checkin.php" class="nav-item <?php echo $current_page === 'checkin' ? 'active' : ''; ?>">
                    <i class="fas fa-fingerprint"></i>
                    <span>ลงเวลาเข้า-ออก</span>
                </a>
                <a href="/leave.php" class="nav-item <?php echo $current_page === 'leave' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>การลา</span>
                </a>
                <a href="/payslip.php" class="nav-item <?php echo $current_page === 'payslip' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>สลิปเงินเดือน</span>
                </a>
                <a href="/certificate.php" class="nav-item <?php echo $current_page === 'certificate' ? 'active' : ''; ?>">
                    <i class="fas fa-file-certificate"></i>
                    <span>ขอใบรับรอง</span>
                </a>
                <a href="/profile.php" class="nav-item <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i>
                    <span>ข้อมูลส่วนตัว</span>
                </a>
                
                <?php if ($isHR): ?>
                <div class="mt-4 pt-4 border-t border-slate-700/50">
                    <p class="text-xs text-slate-500 uppercase tracking-wider mb-2 font-semibold">HR Admin</p>
                    <a href="/hr/employees.php" class="nav-item">
                        <i class="fas fa-users-cog"></i>
                        <span>จัดการพนักงาน</span>
                    </a>
                    <a href="/hr/leaves.php" class="nav-item">
                        <i class="fas fa-calendar-check"></i>
                        <span>อนุมัติการลา</span>
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="mt-4 pt-4 border-t border-slate-700/50">
                    <a href="/logout.php" class="nav-item text-red-400">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>ออกจากระบบ</span>
                    </a>
                </div>
            </nav>
        </div>
    </aside>
</div>

<script>
function openMobileMenu() {
    document.getElementById('mobileSidebar').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeMobileMenu() {
    document.getElementById('mobileSidebar').classList.add('hidden');
    document.body.style.overflow = '';
}

document.getElementById('mobileMenuBtn')?.addEventListener('click', openMobileMenu);
</script>

<!-- Main Content -->
<div class="content-area p-6">
