<?php
/**
 * เอกสารออกแบบโมดูลเงินเดือน (แสดงในระบบ แทนการเปิดไฟล์ docs/ โดยตรง)
 * สิทธิ์: HR, CEO ขึ้นไป เท่านั้น
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/./core/LegacyHrUi.php';
Auth::requireLogin();

$pdo = getDB();
$stmt = $pdo->prepare("SELECT u.id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id'] ?? 0]);
$current = $stmt->fetch();
if (!$current || !function_exists('crm_user_can_access_payroll_web') || !crm_user_can_access_payroll_web()) {
    header('Location: index.php?error=' . urlencode('ไม่มีสิทธิ์เข้าถึง'));
    exit;
}

$page_title = 'เอกสารออกแบบโมดูลเงินเดือน';
$current_page = 'hr-payroll';

$path = __DIR__ . '/docs/PAYROLL-MODULE-DESIGN.md';
$raw = file_exists($path) ? file_get_contents($path) : '';

// แปลง Markdown แบบง่ายเป็น HTML
function simple_md_to_html($text) {
    $h = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('/^### (.+)$/m', '<h3 class="text-lg font-semibold mt-6 mb-2">$1</h3>', $h);
    $h = preg_replace('/^## (.+)$/m', '<h2 class="text-xl font-bold mt-8 mb-3 border-b border-white/20 pb-2">$1</h2>', $h);
    $h = preg_replace('/^# (.+)$/m', '<h1 class="text-2xl font-bold mb-4">$1</h1>', $h);
    $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
    $h = preg_replace('/^---\s*$/m', '<hr class="border-white/10 my-4">', $h);
    $h = preg_replace_callback('/^\|(.+)\|$/m', function ($m) {
        $cells = array_map('trim', explode('|', trim($m[1], '|')));
        $tds = implode('', array_map(function ($c) {
            return '<td class="px-3 py-1.5 border border-white/20">' . $c . '</td>';
        }, $cells));
        return '<tr>' . $tds . '</tr>';
    }, $h);
    $lines = explode("\n", $h);
    $in_table = false;
    $out = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (strpos($line, '<tr>') === 0) {
            if (!$in_table) { $out[] = '<table class="w-full border border-white/20 border-collapse my-3 text-sm">'; $in_table = true; }
            $out[] = $line;
        } else {
            if ($in_table) { $out[] = '</table>'; $in_table = false; }
            if ($trimmed === '' || preg_match('/^</', $trimmed)) {
                $out[] = $line;
            } else {
                $out[] = '<p class="my-2 text-white/90">' . $line . '</p>';
            }
        }
    }
    if ($in_table) $out[] = '</table>';
    return implode("\n", $out);
}

$body_html = $raw !== '' ? simple_md_to_html($raw) : '<p class="text-white/60">ไม่พบเอกสาร</p>';

require_once __DIR__ . '/templates/header.php';
?>
<section class="tp-ios-master-screen min-w-0">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <nav class="text-sm text-white/60 mb-2 min-w-0" aria-label="Breadcrumb">
                <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <a href="payroll.php" class="hover:text-white touch-manipulation">เงินเดือน</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <span class="text-white">เอกสารออกแบบ</span>
            </nav>
            <h1 class="text-xl font-bold text-white">เอกสารออกแบบโมดูลเงินเดือน</h1>
            <p class="text-sm text-white/60 mt-0.5">วัตถุประสงค์, สิทธิ์, ฟังก์ชัน, ฐานข้อมูล, ภาษี, API</p>
        </div>
        <a href="payroll.php" class="inline-flex items-center justify-center gap-2 min-h-[48px] px-4 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm touch-manipulation"><i class="fas fa-arrow-left"></i> กลับเงินเดือน</a>
    </div>
    <div class="glass-card rounded-2xl p-6 border border-white/10 prose prose-invert max-w-4xl">
        <div class="payroll-doc-content text-white/90 text-sm leading-relaxed">
            <?php echo $body_html; ?>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
