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

check('R5', 'bootstrap.php เอาค่าไปเลือก idle window',
    strpos($boot, 'REMEMBER_CHOICE_COOKIE') !== false
        && strpos($boot, "'idle_timeout'    => \$tpHrIdle") !== false,
    'bootstrap ไม่ได้ใช้ค่านั้นเลือกเวลา');

check('R6', 'คุกกี้ session ยังยาวเท่าเดิมทั้งสองกรณี',
    strpos($boot, "'cookie_lifetime' => defined('PWA_SESSION_LIFETIME')") !== false,
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

echo "\n" . str_repeat('=', 68) . "\n";
printf("ผ่าน %d · ไม่ผ่าน %d\n", $pass, $fail);
printf("\nจำไว้ %d วิ (%.0f วัน) · ไม่จำ %d วิ (%.0f ชม.)\n",
    (int)PWA_SESSION_LIFETIME, PWA_SESSION_LIFETIME / 86400,
    (int)SESSION_LIFETIME_NOT_REMEMBERED, SESSION_LIFETIME_NOT_REMEMBERED / 3600);

exit($fail > 0 ? 1 : 0);
