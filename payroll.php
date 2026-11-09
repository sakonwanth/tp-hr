<?php
/**
 * TP-CRM Payroll - Thin Controller
 * เงินเดือนพนักงาน (เข้าถึงได้เฉพาะ HR / CEO ขึ้นไป)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/./core/LegacyHrUi.php';
Auth::requireLogin();

$pdo = getDB();
$session_user = Auth::user();
$stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->execute([$session_user['id']]);
$current_user = $stmt->fetch();
if (!$current_user) { header('Location: login.php'); exit; }

if (!function_exists('crm_user_can_access_payroll_web') || !crm_user_can_access_payroll_web()) {
    header('Location: index.php?error=' . urlencode('คุณไม่มีสิทธิ์เข้าถึงโมดูลเงินเดือน'));
    exit;
}

$page_title = 'เงินเดือนพนักงาน';
$current_page = 'payroll';
$message = '';
$error = '';
$action = $_GET['action'] ?? '';
$run_id = (int)($_GET['run_id'] ?? 0);
$user_id_setup = (int)($_GET['user_id'] ?? 0);
$for_month = trim($_GET['for_month'] ?? '');
if ($action === 'employees' && !empty($_GET['saved'])) {
    $message = 'บันทึกกำหนดเงินเดือนแล้ว';
}
if ($run_id > 0 && !empty($_GET['saved'])) {
    $message = 'บันทึกเงินเดือนเฉพาะเดือนนี้และอัปเดตสลิปแล้ว';
}

if ($run_id > 0 && !empty($_GET['payment_date_saved'])) {
    $message = 'บันทึกวันที่จ่ายรายบุคคลแล้ว สลิปและการส่ง LINE ครั้งถัดไปจะใช้วันที่นี้';
}

// Load helper functions and DB queries
require_once HR_OWNER_PATH . '/modules/payroll/queries.php';

// Handle POST requests
require_once HR_OWNER_PATH . '/modules/payroll/actions.php';

// Load data for current action
$data = payroll_load_data($pdo, $action, $run_id, $user_id_setup, $for_month);
extract($data);

$salary_setup_form_post = null;
if (!empty($error) && ($_POST['action'] ?? '') === 'save_salary_setup' && $action === 'salary_setup') {
    $salary_setup_form_post = $_POST;
}

// Handle salary_setup user not found
if ($action === 'salary_setup' && $user_id_setup > 0 && !$setup_user) {
    $error = 'ไม่พบพนักงานที่เลือก หรือพนักงานปิดการใช้งานแล้ว';
    $action = 'employees';
}

// Determine current tab
$dept_filter = trim($_GET['department'] ?? '');
$payroll_tab = 'home';
if ($action === 'salary_setup') $payroll_tab = 'salary_setup';
elseif ($run_id > 0) $payroll_tab = 'run_detail';
elseif ($action === 'runs') $payroll_tab = 'runs';
elseif ($action === 'employees') $payroll_tab = 'employees';
elseif ($action === 'ytd_report') $payroll_tab = 'ytd_report';

$_default_pay_day = function_exists('payroll_get_default_pay_day') ? payroll_get_default_pay_day() : 25;
$_cutoff_day = function_exists('payroll_get_cutoff_day') ? payroll_get_cutoff_day() : 25;
$page_subtitles = [
    'home' => 'ภาพรวมและทางลัด',
    'runs' => 'ตัดรอบ ' . ((int)$_cutoff_day + 1) . '→' . (int)$_cutoff_day . ' · จ่ายวันที่ ' . (int)$_default_pay_day . ' (ตั้งค่าได้)',
    'run_detail' => 'รายการสลิปเงินเดือน',
    'employees' => 'กำหนดฐานเงินเดือนและรายการหักต่อพนักงาน',
    'salary_setup' => 'แก้ไขกำหนดเงินเดือน',
    'ytd_report' => 'รายงานรายได้สะสมรายปี',
];
$payroll_breadcrumb_tab_labels = [
    'home' => 'หน้าหลัก',
    'runs' => 'รอบเงินเดือน',
    'run_detail' => 'รายละเอียดรอบ',
    'employees' => 'กำหนดเงินเดือน',
    'salary_setup' => 'แก้ไขกำหนดเงินเดือน',
    'ytd_report' => 'รายงานรายได้สะสม',
];
$payroll_crumb_tail = $payroll_breadcrumb_tab_labels[$payroll_tab] ?? $payroll_tab;

$tp_ios26_list_shell = true;

require_once __DIR__ . '/templates/header.php';
?>
<main class="content-area p-6">
    <div class="mb-6">
        <nav class="text-sm text-white/60 mb-2 min-w-0" aria-label="Breadcrumb">
            <a href="index.php" class="hover:text-white touch-manipulation">หน้าแรก</a>
            <span class="mx-2" aria-hidden="true">/</span>
            <a href="payroll.php" class="hover:text-white touch-manipulation">เงินเดือน</a>
            <?php if ($payroll_tab !== 'home'): ?>
            <span class="mx-2" aria-hidden="true">/</span>
            <span class="text-white"><?php echo htmlspecialchars($payroll_crumb_tail, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </nav>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-white"><?php echo $payroll_tab === 'home' ? 'เงินเดือนพนักงาน' : $page_subtitles[$payroll_tab]; ?></h1>
                <?php if ($payroll_tab === 'home'): ?>
                <p class="text-sm text-white/60 mt-0.5">สร้างรอบจ่าย, กำหนดเงินเดือน, พิมพ์สลิป</p>
                <?php endif; ?>
            </div>
            <div class="payroll-tabs-container overflow-x-auto -mx-1 px-1">
                <div class="payroll-tabs flex rounded-xl bg-white/5 border border-white/10 p-1" role="tablist">
                    <a href="payroll.php" role="tab" aria-selected="<?php echo $payroll_tab === 'home' ? 'true' : 'false'; ?>" class="touch-manipulation inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors min-h-[48px] <?php echo $payroll_tab === 'home' ? 'bg-violet-600 text-white' : 'text-white/70 hover:text-white hover:bg-white/10'; ?>">
                        <i class="fas fa-home"></i><span class="tab-text hidden sm:inline">หน้าหลัก</span>
                    </a>
                    <a href="payroll.php?action=runs" role="tab" aria-selected="<?php echo $payroll_tab === 'runs' || $payroll_tab === 'run_detail' ? 'true' : 'false'; ?>" class="touch-manipulation inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors min-h-[48px] <?php echo $payroll_tab === 'runs' || $payroll_tab === 'run_detail' ? 'bg-violet-600 text-white' : 'text-white/70 hover:text-white hover:bg-white/10'; ?>">
                        <i class="fas fa-calendar-alt"></i><span class="tab-text hidden sm:inline">รอบเงินเดือน</span>
                    </a>
                    <a href="payroll.php?action=employees" role="tab" aria-selected="<?php echo $payroll_tab === 'employees' || $payroll_tab === 'salary_setup' ? 'true' : 'false'; ?>" class="touch-manipulation inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors min-h-[48px] <?php echo $payroll_tab === 'employees' || $payroll_tab === 'salary_setup' ? 'bg-violet-600 text-white' : 'text-white/70 hover:text-white hover:bg-white/10'; ?>">
                        <i class="fas fa-user-tie"></i><span class="tab-text hidden sm:inline">กำหนดเงินเดือน</span>
                    </a>
                    <a href="payroll.php?action=ytd_report" role="tab" aria-selected="<?php echo $payroll_tab === 'ytd_report' ? 'true' : 'false'; ?>" class="touch-manipulation inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors min-h-[48px] <?php echo $payroll_tab === 'ytd_report' ? 'bg-violet-600 text-white' : 'text-white/70 hover:text-white hover:bg-white/10'; ?>">
                        <i class="fas fa-chart-bar"></i><span class="tab-text hidden sm:inline">รายได้สะสม</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-xl bg-emerald-500/20 border border-emerald-500/30 text-emerald-200 flex items-center gap-3"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-500/20 border border-rose-500/30 text-rose-200 flex items-center gap-3"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($action === 'salary_setup' && $user_id_setup > 0 && $setup_user): ?>
        <?php require HR_OWNER_PATH . '/modules/payroll/form.view.php'; ?>
    <?php elseif ($run_id > 0 && $run): ?>
        <?php require HR_OWNER_PATH . '/modules/payroll/detail.view.php'; ?>
    <?php elseif ($action === 'ytd_report'): ?>
        <?php require HR_OWNER_PATH . '/modules/payroll/ytd_report.view.php'; ?>
    <?php else: ?>
        <?php require HR_OWNER_PATH . '/modules/payroll/list.view.php'; ?>
    <?php endif; ?>
</main>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
