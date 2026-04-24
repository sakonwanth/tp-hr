<?php
/**
 * TP-HR Login Page
 */

require_once __DIR__ . '/bootstrap.php';

// Already logged in
if (Auth::check()) {
    redirect('/');
}

$error = '';
$username = '';

// Handle login
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>เข้าสู่ระบบ - TP-HR</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($appIconPath); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($appTouchIconPath); ?>">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Sarabun', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding-top: max(16px, env(safe-area-inset-top, 0px));
            padding-right: max(16px, env(safe-area-inset-right, 0px));
            padding-bottom: max(16px, env(safe-area-inset-bottom, 0px));
            padding-left: max(16px, env(safe-area-inset-left, 0px));
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-20 h-20 rounded-2xl bg-violet-600 flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-users text-white text-3xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-white">TP-HR</h1>
            <p class="text-white/60 mt-1">Human Resource Management System</p>
        </div>
        
        <!-- Login Form -->
        <div class="glass-card rounded-2xl p-8">
            <h2 class="text-xl font-semibold text-white text-center mb-6">เข้าสู่ระบบ</h2>
            
            <?php if ($error): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-300 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-white/80 text-sm mb-2">ชื่อผู้ใช้ / อีเมล</label>
                    <div class="relative">
                        <input type="text" 
                               name="username" 
                               value="<?php echo htmlspecialchars($username); ?>"
                               class="w-full px-4 py-3 pl-12 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:border-violet-500"
                               placeholder="กรอกชื่อผู้ใช้หรืออีเมล"
                               required>
                        <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-white/50"></i>
                    </div>
                </div>
                
                <div>
                    <label class="block text-white/80 text-sm mb-2">รหัสผ่าน</label>
                    <div class="relative">
                        <input type="password" 
                               name="password" 
                               id="password"
                               class="w-full px-4 py-3 pl-12 pr-12 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:border-violet-500"
                               placeholder="กรอกรหัสผ่าน"
                               required>
                        <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-white/50"></i>
                        <button type="button" onclick="togglePassword()" class="absolute right-4 top-1/2 -translate-y-1/2 text-white/50 hover:text-white">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="flex items-center justify-between text-sm">
                    <label class="flex items-center gap-2 text-white/70 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded border-white/30 bg-white/10 text-violet-600">
                        <span>จดจำการเข้าสู่ระบบ</span>
                    </label>
                    
                    <a href="/tp-crm/forgot-password.php" class="text-violet-400 hover:text-violet-300">
                        ลืมรหัสผ่าน?
                    </a>
                </div>
                
                <button type="submit" class="w-full min-h-[48px] py-3 bg-violet-600 hover:bg-violet-700 text-white font-medium rounded-lg transition-colors">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>
        
        <!-- TP-CRM Link -->
        <div class="text-center mt-6">
            <a href="/tp-crm/" class="text-white/60 hover:text-white text-sm">
                <i class="fas fa-arrow-left mr-1"></i>
                กลับไป TP-CRM
            </a>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-white/40 text-sm mt-8">
            &copy; <?php echo date('Y'); ?> TP Asset Development Co., Ltd.
        </p>
    </div>
    
    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
