<?php
/**
 * "จดจำฉัน" contract.
 *
 *   php scripts/qa_remember_me_contract.php
 *
 * READ ONLY. Reads source files, evaluates the same expression bootstrap.php
 * uses, and touches nothing else.
 *
 * The box sat on the login form for a long time with nothing reading it —
 * ticking it and leaving it clear did exactly the same thing. These checks
 * exist so that cannot happen again quietly.
 *
 * What the box now means: it picks tp-hr's idle window, and only tp-hr's. The
 * session cookie is shared with tp-crm and the others, so shortening it here
 * would sign the user out of those too. SharedSession tracks idle time per
 * project since tp-common 66bf7f0; this sets the tp-hr entry.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function check(string $id, string $what, bool $ok, string $why): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  \033[32mPASS\033[0m  %-4s %s\n", $id, $what);
        return;
    }
    $fail++;
    printf("  \033[31mFAIL\033[0m  %-4s %s\n         → %s\n", $id, $what, $why);
}

echo "TP-HR — สัญญาของปุ่ม \"จดจำฉัน\" (read only)\n";
echo str_repeat('=', 68) . "\n\n";

/**
 * Source with comments removed.
 *
 * "does this file call Auth::login()" has to ignore the comment in unlock.php
 * that explains why it must not — the first version of that check failed on
 * its own documentation.
 */
function codeOnly(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
    return preg_replace('#(^|\s)//.*$#m', '', $src) ?? $src;
}

$login = (string)file_get_contents($root . '/login.php');
$boot = (string)file_get_contents($root . '/bootstrap.php');
$conf = (string)file_get_contents($root . '/config/app.php');

check('R1', 'ฟอร์มล็อกอินยังมี checkbox ชื่อ remember',
    preg_match('/<input[^>]*name="remember"/', $login) === 1,
    'ไม่พบ checkbox — ถ้าตั้งใจเอาออก ให้ลบสคริปต์นี้ด้วย');

check('R2', 'checkbox ติ๊กมาให้ตั้งแต่แรก (พฤติกรรมเดิมคือจำไว้)',
    preg_match('/<input[^>]*name="remember"[^>]*\bchecked\b/s', $login) === 1
        || preg_match('/\bchecked\b[^>]*name="remember"/s', $login) === 1,
    'ไม่มี checked — คนที่ไม่สังเกตปุ่มจะโดนลดเวลาลงเงียบ ๆ');

check('R3', 'login.php อ่านค่าที่ผู้ใช้เลือกจริง',
    strpos($login, "\$_POST['remember']") !== false,
    'ไม่มีใครอ่าน $_POST[\'remember\'] — ปุ่มกลับไปเป็นของหลอกอีกแล้ว');

check('R4', 'บันทึกตัวเลือกลงคุกกี้ของตัวเอง ไม่ปนกับคุกกี้ session ที่ใช้ร่วมกัน',
    strpos($login, 'REMEMBER_CHOICE_COOKIE') !== false
        && strpos($login, "'httponly' => true") !== false,
    'ไม่ได้ตั้งคุกกี้ หรือไม่ได้ตั้ง httponly');

check('R5', 'bootstrap.php เอาค่าที่ผู้ใช้เลือกไปกำหนดพฤติกรรมจริง',
    strpos($boot, 'REMEMBER_CHOICE_COOKIE') !== false
        && strpos($boot, '$tpHrRemember') !== false,
    'bootstrap ไม่ได้ใช้ค่านั้นเลย');

check('R6', 'คุกกี้ session ยาวเท่าเดิมทั้งสองกรณี',
    preg_match('/\x27cookie_lifetime\x27\s*=>\s*\$tpHrLong/', $boot) === 1,
    'ไป (ย่อ|ยืด) คุกกี้ที่ใช้ร่วมกัน — จะลากโปรเจกต์อื่นหลุดตาม');

check('R7', 'มีค่าคงที่ของช่วงเวลาแบบไม่จำ และสั้นกว่าแบบจำ',
    strpos($conf, 'SESSION_LIFETIME_NOT_REMEMBERED') !== false,
    'ไม่พบ SESSION_LIFETIME_NOT_REMEMBERED');

// The mapping itself — same expression bootstrap.php evaluates.
require_once $root . '/config/app.php';

$window = static function (?string $cookie): int {
    $remember = !(($cookie ?? '1') === '0');
    return $remember ? (int)PWA_SESSION_LIFETIME : (int)SESSION_LIFETIME_NOT_REMEMBERED;
};

check('R8', 'ติ๊กไว้ -> ได้ช่วงเวลายาว',
    $window('1') === (int)PWA_SESSION_LIFETIME,
    'ได้ ' . $window('1') . ' วินาที');

check('R9', 'เอาติ๊กออก -> ได้ช่วงเวลาสั้น และสั้นกว่าจริง',
    $window('0') === (int)SESSION_LIFETIME_NOT_REMEMBERED
        && (int)SESSION_LIFETIME_NOT_REMEMBERED < (int)PWA_SESSION_LIFETIME,
    'ได้ ' . $window('0') . ' วินาที เทียบกับแบบจำ ' . (int)PWA_SESSION_LIFETIME);

check('R10', 'ยังไม่มีคุกกี้ (ผู้ใช้เดิม) -> ได้ช่วงเวลายาวเหมือนก่อนแก้',
    $window(null) === (int)PWA_SESSION_LIFETIME,
    'ผู้ใช้ที่ล็อกอินค้างอยู่จะโดนลดเวลาโดยไม่ได้เลือกเอง');

// ------------------------------------------------------- per-project unlock

echo "\nหน้าปลดล็อก — ล็อกเฉพาะ tp-hr ไม่ลากโปรเจกต์อื่นออก\n\n";

$unlock = (string)@file_get_contents($root . '/unlock.php');
$auth = (string)file_get_contents($root . '/core/Auth.php');

check('U1', 'มีหน้า unlock.php',
    $unlock !== '',
    'ไม่พบไฟล์ — ผู้ใช้ที่โดนล็อกจะไม่มีทางกลับเข้าระบบ');

check('U2', 'ต้องล็อกอินอยู่ก่อนถึงจะเข้าหน้านี้ได้',
    strpos($unlock, 'Auth::check()') !== false,
    'ไม่ได้ตรวจว่าล็อกอินอยู่');

check('U3', 'ตรวจ CSRF ก่อนรับรหัสผ่าน',
    strpos($unlock, 'verifyCsrfToken') !== false,
    'ไม่ตรวจ CSRF');

check('U4', 'ตรวจรหัสผ่านของผู้ใช้ที่ล็อกอินอยู่ ไม่ใช่รับ username มาใหม่',
    strpos($unlock, 'password_verify') !== false
        && strpos($unlock, 'WHERE id = ?') !== false,
    'ไม่ได้ยืนยันรหัสผ่านกับ user id ของ session ปัจจุบัน');

check('U5', 'ปลดล็อกด้วย markReauthenticated ไม่ใช่ล็อกอินใหม่',
    strpos($unlock, "markReauthenticated('tp-hr')") !== false
        && strpos(codeOnly($unlock), 'Auth::login(') === false,
    'ถ้าเรียก Auth::login จะสร้าง session id ใหม่ และเตะโปรเจกต์อื่นออกทั้งหมด');

// Look for the call, not the name: the method's own definition contains the
// name too, so an earlier version of this check stayed green after the call
// site was deleted.
check('U6', 'requireLogin เรียกตัวเช็คล็อกจริง ไม่ใช่แค่มี method อยู่',
    preg_match('/self::requireUnlockIfNeeded\(\);/', $auth) === 1
        && strpos($auth, "needsReauth('tp-hr')") !== false,
    'guard ไม่ได้เรียกตัวเช็ค — คนที่โดนล็อกจะเข้าใช้งานได้ตามปกติ');

check('U7', 'หน้า unlock / logout / login ได้รับการยกเว้น ไม่งั้นวนลูป',
    strpos($auth, "'/unlock.php', '/logout.php', '/login.php'") !== false,
    'ไม่มี allowlist — ผู้ใช้จะติดลูป redirect');

check('U8', 'คำขอแบบ AJAX ได้ 401 พร้อมทางไปปลดล็อก ไม่ใช่ HTML',
    strpos($auth, "'unlock' => '/unlock.php'") !== false,
    'AJAX จะได้หน้า HTML กลับไปแทน JSON');

check('U9', 'bootstrap ขอ reauth_after เฉพาะตอนไม่ได้ติ๊กจำ',
    preg_match('/if \(!\$tpHrRemember\) \{\s*\$tpHrOptions\[\x27reauth_after\x27\]/', $boot) === 1,
    'ประกาศ reauth_after ตลอดเวลา หรือไม่ประกาศเลย');

check('U10', 'ติ๊กจำไว้ -> ไม่ล็อก (idle window ยาวทั้งคู่)',
    strpos($boot, "'idle_timeout'    => \$tpHrLong,") !== false,
    'idle window ไม่ได้ยาวเท่ากันทั้งสองกรณี — session อาจถูกทำลายแทนที่จะล็อก');

echo "\n" . str_repeat('=', 68) . "\n";
printf("ผ่าน %d · ไม่ผ่าน %d\n", $pass, $fail);
printf("\nจำไว้ %d วิ (%.0f วัน) · ไม่จำ %d วิ (%.0f ชม.)\n",
    (int)PWA_SESSION_LIFETIME, PWA_SESSION_LIFETIME / 86400,
    (int)SESSION_LIFETIME_NOT_REMEMBERED, SESSION_LIFETIME_NOT_REMEMBERED / 3600);

exit($fail > 0 ? 1 : 0);
