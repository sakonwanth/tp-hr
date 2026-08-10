<?php
/**
 * Role and route regression checks — the five items left unticked in
 * UI_REFACTOR_TODO.md.
 *
 *   php scripts/qa_role_regression.php                  source + guest HTTP
 *   php scripts/qa_role_regression.php --no-http        source only, offline
 *   php scripts/qa_role_regression.php --base=http://localhost/tp-hr
 *   php scripts/qa_role_regression.php --cookie="tp_session=…"   see below
 *
 * READ ONLY. Sends GET requests and reads files. Never writes, never logs in,
 * never touches the database.
 *
 * WHAT IT CANNOT DO
 *
 * Three of the five items are about what a signed-in person sees, and no
 * amount of source reading proves that. Those print as a manual checklist at
 * the end rather than a fake pass.
 *
 * --cookie converts two of them into real checks: paste your own session
 * cookie from a browser signed in as ordinary staff (DevTools → Application →
 * Cookies → tp_session) and the script asks the server what that session is
 * actually allowed to see. Optional. Nothing is stored; use a staff account,
 * not an admin one, and the point is to confirm the staff account is BLOCKED.
 */

$root = dirname(__DIR__);
$opts = getopt('', ['base::', 'cookie::', 'no-http', 'verbose']);
$base = rtrim((string)($opts['base'] ?? 'https://hr.tp-asset.com'), '/');
$cookie = (string)($opts['cookie'] ?? '');
$doHttp = !isset($opts['no-http']);
$verbose = isset($opts['verbose']);

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

function ok(string $id, string $what): void
{
    global $pass;
    $pass++;
    printf("  \033[32mPASS\033[0m  %-5s %s\n", $id, $what);
}

function bad(string $id, string $what, string $why): void
{
    global $fail, $failures;
    $fail++;
    $failures[] = "$id  $what\n         → $why";
    printf("  \033[31mFAIL\033[0m  %-5s %s\n         → %s\n", $id, $what, $why);
}

function skipped(string $id, string $what, string $why): void
{
    global $skip;
    $skip++;
    printf("  \033[33mSKIP\033[0m  %-5s %s (%s)\n", $id, $what, $why);
}

function check(string $id, string $what, bool $condition, string $why): void
{
    $condition ? ok($id, $what) : bad($id, $what, $why);
}

/** @return array{status:int,body:string,location:string} */
function fetch(string $url, string $cookie = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'tp-hr-role-regression/1.0',
    ]);
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

/**
 * A guarded page must not send its own content to someone who may not see it.
 * The app answers with an SSO redirect stub — a short page whose only job is
 * to bounce the browser — so the test is "none of this page's own markers are
 * present", not "the status code was 302".
 */
function looksLikeItRendered(string $body, string $marker): bool
{
    return mb_strpos($body, $marker) !== false;
}

echo "TP-HR — role & route regression (read only)\n";
echo "base: " . ($doHttp ? $base : '— HTTP checks off —') . "\n";
echo str_repeat('=', 72) . "\n\n";

// ---------------------------------------------------------------- A. source

echo "A. โครงสร้างในซอร์ส — สิทธิ์ถูกประกาศไว้ครบไหม\n\n";

$hrPages = glob($root . '/hr/*.php') ?: [];
$missingLogin = [];
$missingGuard = [];
foreach ($hrPages as $p) {
    $src = (string)file_get_contents($p);
    $rel = 'hr/' . basename($p);
    if (strpos($src, 'Auth::requireLogin()') === false) {
        $missingLogin[] = $rel;
    }
    $guarded = strpos($src, 'hr_can_access_hr_dashboard()') !== false
        || strpos($src, 'isCEOOrAbove()') !== false
        || strpos($src, 'hr_can_manage_attendance()') !== false;
    if (!$guarded) {
        $missingGuard[] = $rel;
    }
}

check('A1', 'ทุกหน้าใน hr/ เรียก Auth::requireLogin()',
    $missingLogin === [],
    'ไม่มีใน: ' . implode(', ', $missingLogin));

check('A2', 'ทุกหน้าใน hr/ มีการตรวจ role',
    $missingGuard === [],
    'ไม่มีใน: ' . implode(', ', $missingGuard));

// The three routes the TODO names as CEO-only.
$ceoOnly = ['hr/reports.php', 'hr/settings.php', 'hr/dayoff_approvals.php'];
$ceoBad = [];
foreach ($ceoOnly as $rel) {
    $src = (string)@file_get_contents($root . '/' . $rel);
    if ($src === '') {
        $ceoBad[] = "$rel (อ่านไฟล์ไม่ได้)";
        continue;
    }
    // guard must both test isCEOOrAbove() and bounce on failure
    if (!preg_match('/if\s*\(\s*!\s*isCEOOrAbove\(\)\s*\)\s*\{[^}]*redirect\(/s', $src)) {
        $ceoBad[] = $rel;
    }
}
check('A3', 'reports / settings / dayoff_approvals กันไว้ที่ระดับ CEO และ redirect ออก',
    $ceoBad === [],
    'ยังไม่ครบ: ' . implode(', ', $ceoBad));

// Sidebar parity: every /hr/ link belongs inside the HR block, with one
// deliberate exception — a manager who is not HR still approves outside
// attendance, and that link is gated by `$canApproveOutsideAttendance && !$isHR`.
$header = (string)file_get_contents($root . '/templates/header.php');
$hrBlockStart = strpos($header, '<?php if ($isHR): ?>');
$strayLinks = [];
if ($hrBlockStart !== false && preg_match_all('/href="(\/hr\/[a-z_]*\.php|\/hr\/)"/', $header, $m, PREG_OFFSET_CAPTURE)) {
    foreach ($m[0] as $i => $hit) {
        if ($hit[1] >= $hrBlockStart) continue;
        $context = substr($header, max(0, $hit[1] - 300), 300);
        if (strpos($context, '$canApproveOutsideAttendance && !$isHR') !== false) continue;
        $strayLinks[] = $m[1][$i][0] . ' (บรรทัด ' . (substr_count(substr($header, 0, $hit[1]), "\n") + 1) . ')';
    }
}
check('A4', 'เมนู /hr/* อยู่ในบล็อก $isHR ทั้งหมด (ยกเว้นลิงก์อนุมัตินอกสถานที่ของหัวหน้า)',
    $hrBlockStart !== false && $strayLinks === [],
    $hrBlockStart === false ? 'หาบล็อก $isHR ไม่เจอ' : 'หลุดออกมา: ' . implode(', ', $strayLinks));

$ceoLinks = ['/hr/reports.php', '/hr/settings.php', '/hr/dayoff_approvals.php'];
$ungatedCeo = [];
foreach ($ceoLinks as $href) {
    $at = 0;
    while (($at = strpos($header, 'href="' . $href . '"', $at)) !== false) {
        $before = substr($header, 0, $at);
        $lastIsCeo = strrpos($before, '<?php if ($isCEO): ?>');
        $lastEndIf = strrpos($before, '<?php endif; ?>');
        if ($lastIsCeo === false || ($lastEndIf !== false && $lastEndIf > $lastIsCeo)) {
            $ungatedCeo[] = $href . ' (บรรทัด ' . (substr_count($before, "\n") + 1) . ')';
        }
        $at++;
    }
}
check('A5', 'เมนูเฉพาะ CEO อยู่ในบล็อก $isCEO',
    $ungatedCeo === [],
    'ไม่ได้กั้น: ' . implode(', ', $ungatedCeo));

$lineLogin = (string)@file_get_contents($root . '/api/line_login.php');
check('A6', 'api/line_login.php ยังอยู่ และยังตรวจ token ก่อนสร้าง session',
    $lineLogin !== '' && strpos($lineLogin, 'session_regenerate_id(true)') !== false,
    $lineLogin === '' ? 'ไม่พบไฟล์' : 'ไม่พบการ regenerate session id — session fixation');

$payslip = (string)@file_get_contents($root . '/payslip.php');
check('A7', 'ทางดาวน์โหลดสลิปยังต้องมาจากปุ่มในหน้า (POST + CSRF) ไม่ใช่ GET ตรง ๆ',
    strpos($payslip, "\$_GET['action'] ?? ''") !== false
        && strpos($payslip, "verifyCsrfToken(\$_POST['_token'] ?? null)") !== false,
    'branch GET action=download หรือการตรวจ CSRF หายไป');

$printTpl = (string)@file_get_contents($root . '/modules/employee/payslip/print_template.php');
check('A8', 'แถบเครื่องมือของสลิปยังถูกซ่อนตอนพิมพ์ (.no-print)',
    strpos($printTpl, '.no-print { display: none !important; }') !== false
        && strpos($printTpl, 'class="no-print"') !== false,
    'กฎ .no-print ใน @media print หรือ container หายไป — แถบปุ่มจะติดไปในเอกสารที่พิมพ์');

// ------------------------------------------------------------ B. guest HTTP

echo "\nB. ยิงจริงแบบยังไม่ล็อกอิน — หน้าที่กันไว้ ต้องไม่ส่งเนื้อหาออกมา\n\n";

$guarded = [
    'hr/index.php'            => 'HR Dashboard',
    'hr/employees.php'        => 'จัดการพนักงาน',
    'hr/leaves.php'           => 'จัดการการลา',
    'hr/reports.php'          => 'รายงาน',
    'hr/settings.php'         => 'ตั้งค่าระบบ',
    'hr/dayoff_approvals.php' => 'อนุมัติวันหยุด',
];

if (!$doHttp) {
    skipped('B*', 'การตรวจผ่าน HTTP', 'ใช้ --no-http');
} else {
    $i = 0;
    foreach ($guarded as $path => $marker) {
        $i++;
        $r = fetch("$base/$path");
        $leaked = looksLikeItRendered($r['body'], $marker);
        $big = strlen($r['body']) > 4000;   // the redirect stub is under 1 KB
        check('B' . $i, "guest เปิด /$path แล้วไม่เห็นเนื้อหาหน้านั้น",
            !$leaked && !$big,
            $leaked
                ? "พบข้อความ \"$marker\" ในผลลัพธ์ — หน้าถูกเรนเดอร์ให้คนที่ยังไม่ล็อกอิน"
                : 'ตอบกลับมา ' . strlen($r['body']) . ' bytes ซึ่งใหญ่เกินกว่าจะเป็นหน้า redirect');
        if ($verbose) {
            printf("           status %d, %d bytes\n", $r['status'], strlen($r['body']));
        }
    }

    $r = fetch("$base/api/line_login.php");
    check('B7', 'api/line_login.php ยังตอบสนอง (ไม่ 5xx)',
        $r['status'] >= 200 && $r['status'] < 400,
        'ได้ status ' . $r['status'] . ' — endpoint ล็อกอิน LINE พัง');

    $r = fetch("$base/payslip.php?action=download&slip_id=1");
    $isFile = stripos($r['body'], '%PDF') === 0 || stripos($r['body'], 'PK') === 0;
    check('B8', 'guest ขอโหลดสลิปตรง ๆ ผ่าน GET แล้วไม่ได้ไฟล์',
        !$isFile,
        'ได้ไฟล์กลับมาโดยยังไม่ล็อกอิน');
}

// -------------------------------------------------- C. signed-in (optional)

echo "\nC. ตรวจด้วย session จริง (ใส่ --cookie ถึงจะทำงาน)\n\n";

if ($cookie === '' || !$doHttp) {
    skipped('C1', 'บัญชีพนักงานทั่วไปเปิดหน้าเฉพาะ CEO ไม่ได้', 'ไม่ได้ใส่ --cookie');
    skipped('C2', 'บัญชีพนักงานทั่วไปไม่เห็นเมนูเฉพาะ CEO', 'ไม่ได้ใส่ --cookie');
} else {
    $blocked = [];
    foreach ($ceoOnly as $rel) {
        $marker = $guarded[$rel] ?? '';
        $r = fetch("$base/$rel", $cookie);
        if ($marker !== '' && looksLikeItRendered($r['body'], $marker)) {
            $blocked[] = $rel;
        }
    }
    check('C1', 'บัญชีที่ใช้ทดสอบเปิดหน้าเฉพาะ CEO ไม่ได้',
        $blocked === [],
        'เปิดได้: ' . implode(', ', $blocked) . ' — ถ้าคุกกี้นี้เป็นของ CEO ผลนี้ถูกแล้ว ให้ใช้คุกกี้ของพนักงานทั่วไป');

    $home = fetch("$base/index.php", $cookie);
    $seen = [];
    foreach ($ceoLinks as $href) {
        if (strpos($home['body'], 'href="' . $href . '"') !== false) {
            $seen[] = $href;
        }
    }
    check('C2', 'บัญชีที่ใช้ทดสอบไม่เห็นเมนูเฉพาะ CEO',
        $seen === [],
        'ยังเห็น: ' . implode(', ', $seen) . ' — ถ้าคุกกี้นี้เป็นของ CEO ผลนี้ถูกแล้ว');
}

// ------------------------------------------------------------------ summary

echo "\n" . str_repeat('=', 72) . "\n";
printf("ผ่าน %d · ไม่ผ่าน %d · ข้าม %d\n", $pass, $fail, $skip);

if ($failures) {
    echo "\nที่ไม่ผ่าน:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
}

echo <<<'MANUAL'

------------------------------------------------------------------------
ต้องทำมือ — สคริปต์พิสูจน์แทนไม่ได้ ต้องมีบัญชีจริงสองใบ
------------------------------------------------------------------------

M1  ยื่นใบลา แล้วต้องโผล่ในคิวอนุมัติ
    ล็อกอินด้วยบัญชีพนักงานทั่วไป → /leave.php?action=request → ยื่น 1 วัน
    ออก แล้วล็อกอินด้วยบัญชี HR → /hr/leaves.php
    ต้องเห็นใบลานั้นสถานะ PENDING และตัวเลข "รออนุมัติ" เพิ่มขึ้น 1
    กดอนุมัติ แล้วกลับไปดูฝั่งพนักงานว่าสถานะเปลี่ยนตาม

M2  ดาวน์โหลดสลิปเงินเดือน
    บัญชีพนักงาน → /payslip.php → กดปุ่มดาวน์โหลดในหน้า (ห้ามแก้ URL เอง)
    ต้องได้ไฟล์จริง และเปิดแล้วเนื้อหาตรงกับที่แสดงบนจอ
    สั่งพิมพ์อีกครั้ง แล้วดูว่าแถบปุ่มด้านบนไม่ติดไปในกระดาษ

M3  เข้าสู่ระบบด้วย LINE
    ออกจากระบบให้หมด → /login.php → กดปุ่ม LINE
    ต้องเด้งไป CRM แล้วกลับมาที่ TP-HR พร้อมล็อกอินสำเร็จ
    ถ้าปุ่มไม่ขึ้น แปลว่าปิดฟีเจอร์ไว้ที่ config ไม่ใช่ของเสีย

M4  เมนูของพนักงานทั่วไปเทียบกับ HR
    เปิดสองเบราว์เซอร์ (หรือหน้าต่างส่วนตัว) ล็อกอินคนละบัญชี
    บัญชีพนักงาน ต้องไม่เห็นหัวข้อ "HR ADMIN" ในแถบข้างเลย
    บัญชี HR ที่ไม่ใช่ CEO ต้องเห็น HR ADMIN แต่ไม่เห็น รายงาน / ตั้งค่าระบบ /
    อนุมัติเปลี่ยนวันหยุด

M5  ลองพิมพ์ URL ตรง ๆ ด้วยบัญชีพนักงาน
    วาง /hr/settings.php, /hr/reports.php, /hr/dayoff_approvals.php บน address bar
    ทุกอันต้องเด้งกลับ พร้อมข้อความว่าไม่มีสิทธิ์ — ไม่ใช่หน้าเปล่าหรือ error
    (ข้อนี้ทำอัตโนมัติได้ด้วย --cookie ของบัญชีพนักงาน)

MANUAL;

exit($fail > 0 ? 1 : 0);
