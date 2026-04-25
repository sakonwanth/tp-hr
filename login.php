<?php
/**
 * TP-HR Login Page
 * หน้าเข้าสู่ระบบ - รูปแบบเดียวกับ CRM
 */

require_once __DIR__ . '/bootstrap.php';

// If already logged in, redirect
if (Auth::check()) {
    $redirect = $_GET['redirect'] ?? '/';
    redirect($redirect);
}

$pdo = Database::getInstance()->getConnection();

// System settings
$system_name = 'TP-HR';
$system_tagline = 'Human Resource Management';
$system_tagline_th = 'ระบบบริหารทรัพยากรบุคคล';
$company_name = 'TP-Asset Development Co., Ltd.';
// CRM repo ships "LOGO TP-ASSET - 6.png" under asset/logo/ (tp-logo.png does not exist → broken image on login).
$hr_login_logo_fallback = '/assets/icons/tphr-app-icon.svg';
$crm_brand_logo_path = rtrim(CRM_BASE_URL, '/') . '/asset/logo/' . rawurlencode('LOGO TP-ASSET - 6.png');
$company_logo = !empty($_ENV['HR_LOGIN_LOGO']) ? $_ENV['HR_LOGIN_LOGO'] : $crm_brand_logo_path;

$error = '';
$success = '';

// Check for logout message
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $success = 'ออกจากระบบเรียบร้อยแล้ว';
}

// Handle LINE Login errors
if (isset($_GET['error'])) {
    $lineErrors = [
        'line_denied' => 'คุณยกเลิกการเข้าสู่ระบบด้วย LINE',
        'line_invalid' => 'ข้อมูลการเข้าสู่ระบบ LINE ไม่ถูกต้อง',
        'line_csrf' => 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง',
        'line_token' => 'ไม่สามารถเชื่อมต่อ LINE ได้ กรุณาลองใหม่',
        'line_profile' => 'ไม่สามารถดึงข้อมูล LINE ได้',
        'line_not_linked' => 'ไม่พบบัญชีพนักงานที่เชื่อมต่อกับ LINE นี้' . (isset($_GET['line_name']) ? ' (' . htmlspecialchars($_GET['line_name']) . ')' : '') . ' กรุณาล็อกอินด้วยรหัสผ่านแล้วเชื่อมต่อ LINE ที่ระบบ CRM',
        'line_error' => 'เกิดข้อผิดพลาดในการเข้าสู่ระบบด้วย LINE',
    ];
    $errorKey = $_GET['error'];
    if (isset($lineErrors[$errorKey])) {
        $error = $lineErrors[$errorKey];
    }
}

// Check LINE Login availability - use CRM's LINE config
$lineLoginEnabled = false;
$lineChannelId = '';

// Try to get LINE config from CRM (resolve path dynamically)
$crmConfigPath = defined('TP_CRM_PATH') && TP_CRM_PATH ? TP_CRM_PATH . '/config/line.php' : null;
if ($crmConfigPath && file_exists($crmConfigPath)) {
    $crmConfig = file_get_contents($crmConfigPath);
    if (preg_match("/define\s*\(\s*['\"]LINE_CHANNEL_ID['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $crmConfig, $matches)) {
        $lineChannelId = $matches[1];
        $lineLoginEnabled = !empty($lineChannelId) && $lineChannelId !== 'your_channel_id_here';
    }
}

// Also check CRM .env for TP_CRM_LINE_LOGIN_CHANNEL_ID
if (!$lineLoginEnabled) {
    $crmEnvPath = defined('TP_CRM_PATH') && TP_CRM_PATH ? TP_CRM_PATH . '/.env' : null;
    if ($crmEnvPath && file_exists($crmEnvPath)) {
        $envContent = file_get_contents($crmEnvPath);
        if (preg_match("/TP_CRM_LINE_LOGIN_CHANNEL_ID\s*=\s*['\"]?([^'\"\n]+)['\"]?/", $envContent, $matches)) {
            $lineChannelId = trim($matches[1]);
            $lineLoginEnabled = !empty($lineChannelId);
        }
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $result = Auth::login($username, $password);
        
        if ($result['success']) {
            $redirect = $_GET['redirect'] ?? '/';
            redirect($redirect);
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php $appIconPath = '/assets/icons/tphr-app-icon.svg'; ?>
    <?php $appTouchIconPath = '/assets/icons/apple-touch-icon-v2.png'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>เข้าสู่ระบบ | <?php echo htmlspecialchars($system_name); ?></title>
    
    <!-- PWA Meta (aligned with tp-checkin) -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($system_name); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#7c3aed">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($appIconPath); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($appTouchIconPath); ?>">
    
    <!-- IBM Plex Sans Thai — same stack as tp-checkin / logged-in TP-HR -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons & Tailwind -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
    
    <style>
        * {
            font-family: 'IBM Plex Sans Thai', sans-serif;
            -webkit-tap-highlight-color: transparent;
            box-sizing: border-box;
        }
        
        body {
            min-height: 100vh;
            min-height: 100dvh;
            position: relative;
            overflow-x: hidden;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding-top: max(12px, env(safe-area-inset-top, 0px));
            padding-right: max(12px, env(safe-area-inset-right, 0px));
            padding-bottom: max(12px, env(safe-area-inset-bottom, 0px));
            padding-left: max(12px, env(safe-area-inset-left, 0px));
        }

        @media (min-width: 640px) {
            body {
                padding-top: max(16px, env(safe-area-inset-top, 0px));
                padding-right: max(16px, env(safe-area-inset-right, 0px));
                padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
                padding-left: max(16px, env(safe-area-inset-left, 0px));
            }
        }

        @media (min-width: 1024px) {
            body {
                padding-top: max(24px, env(safe-area-inset-top, 0px));
                padding-right: max(24px, env(safe-area-inset-right, 0px));
                padding-bottom: max(24px, env(safe-area-inset-bottom, 0px));
                padding-left: max(24px, env(safe-area-inset-left, 0px));
            }
        }

        .login-card {
            background: rgba(30, 41, 59, 0.64);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            position: relative;
        }

        .input-field {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            color: white;
            transition: all 0.3s ease;
            font-size: 16px !important;
            touch-action: manipulation;
        }

        .input-field:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
            outline: none;
            background: rgba(255, 255, 255, 0.12);
        }

        .input-field::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .btn-login {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 600;
            min-height: 48px;
            touch-action: manipulation;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-line {
            background: #06C755;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            min-height: 48px;
            touch-action: manipulation;
        }

        .login-password-toggle {
            touch-action: manipulation;
            min-width: 44px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .touch-manipulation {
            touch-action: manipulation;
        }

        .btn-line:hover {
            background: #05B34C;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(6, 199, 85, 0.3);
        }

        .btn-line:active {
            transform: translateY(0);
        }

        .logo-glow {
            filter: drop-shadow(0 0 20px rgba(139, 92, 246, 0.5));
            transition: all 0.3s ease;
        }

        .logo-glow:hover {
            filter: drop-shadow(0 0 30px rgba(139, 92, 246, 0.7));
            transform: scale(1.05);
        }

        .tagline {
            color: rgba(196, 181, 253, 0.95);
        }

        @media (max-width: 640px) {
            .login-card {
                border-radius: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- Login Card -->
    <div class="login-card w-full max-w-md p-4 sm:p-6 lg:p-8">
        <!-- Logo -->
        <div class="text-center mb-6 sm:mb-8">
            <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="<?php echo htmlspecialchars($system_name); ?>" width="96" height="96" decoding="async" fetchpriority="high" class="w-24 h-24 mx-auto mb-4 logo-glow object-contain" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($hr_login_logo_fallback, ENT_QUOTES, 'UTF-8'); ?>';">
            <h1 class="text-white text-xl sm:text-2xl font-bold"><?php echo htmlspecialchars($system_name); ?></h1>
            <p class="tagline text-sm mt-1 font-medium"><?php echo htmlspecialchars($system_tagline); ?></p>
            <p class="text-white text-opacity-50 text-xs mt-1"><?php echo htmlspecialchars($system_tagline_th); ?></p>
        </div>
        
        <!-- Success Message -->
        <?php if ($success): ?>
        <div class="bg-green-500 bg-opacity-20 border border-green-500 text-green-300 px-4 py-3 rounded-xl mb-6 flex items-center">
            <i class="fas fa-check-circle mr-3"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Error Message -->
        <?php if ($error): ?>
        <div class="bg-red-500 bg-opacity-20 border border-red-500 text-red-300 px-4 py-3 rounded-xl mb-6 flex items-center">
            <i class="fas fa-exclamation-circle mr-3"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Login Form: field stack tighter than meta→submit so the button does not feel "floating" far below -->
        <form method="POST" action="">
            <div class="space-y-4">
            <!-- Username -->
            <div>
                <label class="block text-white text-opacity-80 text-sm font-medium mb-2">
                    <i class="fas fa-user mr-2"></i>ชื่อผู้ใช้หรืออีเมล
                </label>
                <input 
                    type="text" 
                    name="username" 
                    class="input-field w-full px-4 py-3"
                    placeholder="กรอกชื่อผู้ใช้หรืออีเมล"
                    value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                    required
                    autofocus
                >
            </div>
            
            <!-- Password -->
            <div>
                <label class="block text-white text-opacity-80 text-sm font-medium mb-2">
                    <i class="fas fa-lock mr-2"></i>รหัสผ่าน
                </label>
                <div class="relative">
                    <input 
                        type="password" 
                        name="password" 
                        id="password"
                        class="input-field w-full px-4 py-3 pr-14"
                        placeholder="กรอกรหัสผ่าน"
                        required
                    >
                    <button 
                        type="button" 
                        onclick="togglePassword()"
                        class="login-password-toggle absolute right-1 top-1/2 -translate-y-1/2 rounded-lg text-white text-opacity-60 hover:text-opacity-100 transition-opacity"
                        aria-label="แสดงหรือซ่อนรหัสผ่าน"
                    >
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>
            </div>
            
            <!-- Remember Me -->
            <div class="mt-3 flex min-h-[44px] items-center justify-between gap-3">
                <label class="flex cursor-pointer select-none items-center gap-2 text-sm text-white text-opacity-80">
                    <input type="checkbox" name="remember" class="h-4 w-4 shrink-0 rounded border-white/30 bg-white/10 text-purple-500 focus:ring-purple-500 focus:ring-offset-0">
                    จดจำฉัน
                </label>
                <a href="<?php echo htmlspecialchars(CRM_BASE_URL); ?>/login.php" class="inline-flex min-h-[44px] shrink-0 items-center text-purple-400 hover:text-purple-300 text-sm transition-colors touch-manipulation">
                    ลืมรหัสผ่าน?
                </a>
            </div>
            
            <!-- Login Button -->
            <div class="mt-4">
            <button type="submit" class="btn-login w-full py-3 text-white font-semibold flex items-center justify-center">
                <i class="fas fa-sign-in-alt mr-2"></i>
                เข้าสู่ระบบ
            </button>
            </div>
        </form>
        
        <?php if ($lineLoginEnabled): ?>
        <!-- LINE Login Divider -->
        <div class="flex items-center my-6">
            <div class="flex-1 h-px bg-white/10"></div>
            <span class="px-4 text-white/40 text-xs">หรือเข้าสู่ระบบด้วย</span>
            <div class="flex-1 h-px bg-white/10"></div>
        </div>
        
        <!-- LINE Login Button -->
        <button onclick="loginWithLINE()" class="btn-line w-full py-3 text-white font-semibold flex items-center justify-center rounded-xl transition-all hover:shadow-lg active:scale-[0.98]">
            <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
            </svg>
            เข้าสู่ระบบด้วย LINE
        </button>
        <?php endif; ?>
        
        <!-- CRM Link -->
        <div class="text-center mt-6">
            <a href="<?php echo htmlspecialchars(CRM_BASE_URL); ?>/" class="inline-flex min-h-[44px] items-center justify-center text-sm text-white/60 transition-colors hover:text-white/90 touch-manipulation">
                <i class="fas fa-arrow-left mr-1"></i>
                กลับไป TP-CRM
            </a>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-xs mt-6 text-white/50">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name); ?>. All rights reserved.
        </p>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        <?php if ($lineLoginEnabled): ?>
        function loginWithLINE() {
            // Use LINE login callback from HR API
            window.location.href = 'api/line_login.php?action=login';
        }
        <?php endif; ?>
        
        // Auto-focus on username field
        document.querySelector('input[name="username"]')?.focus();
    </script>
</body>
</html>
