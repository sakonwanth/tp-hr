<?php
/**
 * Unlock screen — tp-hr asking a signed-in person for their password again.
 *
 * Reached when tp-hr has been left alone longer than it allows and
 * SharedSession locked it. The session is intact: the user is still signed in
 * here and everywhere else, and tp-crm and the rest are untouched. Only tp-hr
 * wants proof before it shows anything.
 *
 * That is why this is not the login page. Sending the user there would mean
 * Auth::login(), a new session id, and every other project signed out with it.
 */

require_once __DIR__ . '/bootstrap.php';

// Locked or not, you have to be signed in to be here at all.
if (!Auth::check()) {
    redirect('/login.php?redirect=' . urlencode('/unlock.php'));
}

$user = Auth::user();
$sharedSession = 'TpCommon\Session\SharedSession';
// method_exists too — the server can be running an older tp-common if its
// pull failed during a deploy, and this page must not fatal in that case.
$locked = defined('TP_COMMON_AVAILABLE') && TP_COMMON_AVAILABLE
    && class_exists($sharedSession)
    && method_exists($sharedSession, 'needsReauth')
    && $sharedSession::needsReauth('tp-hr');

$target = safeRedirectTarget($_GET['redirect'] ?? $_POST['redirect'] ?? null, '/');

// Nothing to unlock — don't leave the user staring at a password box.
if (!$locked) {
    redirect($target);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? null)) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } elseif (($_POST['password'] ?? '') === '') {
        $error = 'กรุณากรอกรหัสผ่าน';
    } else {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([(int)$user['id']]);
        $hash = (string)$stmt->fetchColumn();

        if ($hash !== '' && password_verify((string)$_POST['password'], $hash)) {
            // Unlocks tp-hr only. Any other locked project still wants its own.
            $sharedSession::markReauthenticated('tp-hr');
            Auth::log('session_unlock', 'users', (int)$user['id']);
            redirect($target);
        }

        // Deliberately vague, and logged: the username is not in question here,
        // so the only thing a wrong answer reveals is that it was wrong.
        Auth::log('session_unlock_failed', 'users', (int)$user['id']);
        $error = 'รหัสผ่านไม่ถูกต้อง';
    }
}

$page_title = 'ยืนยันตัวตนอีกครั้ง';
$displayName = trim(($user['first_name_th'] ?? '') . ' ' . ($user['last_name_th'] ?? ''));
if ($displayName === '') {
    $displayName = (string)($user['username'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#b79168">
    <title><?php echo htmlspecialchars($page_title); ?> - TP-HR</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=33">
    <link rel="stylesheet" href="/assets/css/native-shell.css?v=33">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'IBM Plex Sans Thai', sans-serif; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #0f172a 0%, #2b2119 50%, #0f172a 100%);
            min-height: 100vh; min-height: 100dvh; margin: 0;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
    </style>
</head>
<body>
    <main class="w-full max-w-[min(560px,100%)]">
        <div class="native-card tp-native-card rounded-[var(--tp-ios-card-radius)] p-6">
            <div class="text-center">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white/10">
                    <i class="fas fa-lock text-2xl text-white/80" aria-hidden="true"></i>
                </div>
                <h1 class="text-white text-xl font-semibold">ยืนยันตัวตนอีกครั้ง</h1>
                <p class="text-white/60 text-sm mt-2">
                    คุณไม่ได้ใช้งานมาสักพัก กรุณากรอกรหัสผ่านเพื่อใช้งานต่อ
                </p>
                <p class="text-white/45 text-xs mt-3">
                    ยังไม่ได้ออกจากระบบ — แค่หน้านี้ขอให้ยืนยันก่อน
                </p>
            </div>

            <?php if ($error !== ''): ?>
            <div class="mt-5 rounded-[var(--tp-ios-card-radius)] border border-red-500/35 bg-red-500/15 px-4 py-3 text-red-200 text-sm" role="alert">
                <i class="fas fa-circle-exclamation mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="mt-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($target); ?>">

                <div class="tp-native-form-group">
                    <label for="unlock-password" class="text-white/70 text-sm font-medium">
                        รหัสผ่านของ <?php echo htmlspecialchars($displayName); ?>
                    </label>
                    <input type="password" id="unlock-password" name="password" required autofocus
                           autocomplete="current-password"
                           class="input-field tp-native-input w-full">
                </div>

                <button type="submit"
                        class="btn-primary w-full mt-2 touch-manipulation whitespace-nowrap">
                    <i class="fas fa-unlock mr-2" aria-hidden="true"></i>ปลดล็อก
                </button>
            </form>

            <div class="mt-5 text-center">
                <a href="/logout.php"
                   class="tp-tap-48 inline-flex items-center text-white/55 hover:text-white/80 text-sm touch-manipulation whitespace-nowrap">
                    ออกจากระบบทั้งหมด
                </a>
            </div>
        </div>
    </main>
</body>
</html>
