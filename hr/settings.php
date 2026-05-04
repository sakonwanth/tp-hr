<?php
/**
 * HR Settings - System Configuration
 * CEO level only
 */

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Auth::requireHR();

// CEO-level access only
if (!isCEOOrAbove()) {
    flash('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้าตั้งค่าระบบ');
    redirect('/hr/', 302);
}

$pdo = getDB();
$user = Auth::user();
$settingsService = new SettingsService($pdo);
$thaiHolidaySyncService = new ThaiHolidaySyncService($pdo);

$page_title = 'ตั้งค่าระบบ';
$current_page = 'hr-settings';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_token'] ?? ($_POST['csrf_token'] ?? ''))) {
        flash('error', 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect($_SERVER['REQUEST_URI'] ?? '/hr/settings.php', 302);
    }
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_settings':
                foreach ($_POST['settings'] as $key => $value) {
                    $settingsService->set((string)$key, $value, 'STRING', (int)$user['id'], 'HR', 'updated from HR settings page');
                }

                // Keep default shift in line with the canonical settings service.
                try {
                    $s = $_POST['settings'] ?? [];
                    if (isset($s['default_work_start'], $s['default_work_end'])) {
                        $pdo->prepare("UPDATE hr_work_shifts
                                        SET start_time = ?, end_time = ?, grace_period_minutes = COALESCE(?, grace_period_minutes)
                                        WHERE is_default = 1 AND is_active = 1")
                            ->execute([
                                $s['default_work_start'],
                                $s['default_work_end'],
                                isset($s['grace_period_minutes']) && $s['grace_period_minutes'] !== '' ? (int)$s['grace_period_minutes'] : null,
                            ]);
                    }
                } catch (Throwable $e) {
                    error_log('settings.update_settings cross-sync failed: ' . $e->getMessage());
                }

                Auth::log('update_settings', 'hr_settings', null, null, $_POST['settings']);
                $success = 'บันทึกการตั้งค่าเรียบร้อยแล้ว';
                break;
                
            case 'add_holiday':
                $stmt = $pdo->prepare("
                    INSERT INTO hr_holidays (name, date, type, description, created_by) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['holiday_date'],
                    isset($_POST['is_recurring']) ? 'PUBLIC' : 'COMPANY',
                    $_POST['description'] ?? null,
                    $user['id']
                ]);
                Auth::log('add_holiday', 'hr_holidays', $pdo->lastInsertId());
                $success = 'เพิ่มวันหยุดเรียบร้อยแล้ว';
                break;
                
            case 'delete_holiday':
                $stmt = $pdo->prepare("DELETE FROM hr_holidays WHERE id = ?");
                $stmt->execute([$_POST['holiday_id']]);
                Auth::log('delete_holiday', 'hr_holidays', $_POST['holiday_id']);
                $success = 'ลบวันหยุดเรียบร้อยแล้ว';
                break;

            case 'sync_thai_holidays':
                $fromYear = (int)($_POST['from_year'] ?? date('Y'));
                $toYear = (int)($_POST['to_year'] ?? $fromYear);
                $result = $thaiHolidaySyncService->syncRange($fromYear, $toYear);
                Auth::log('sync_thai_holidays', 'hr_thai_holiday_sources', null, null, $result);
                $success = 'อัปเดตวันหยุดประเทศไทยจาก API แล้ว (' . array_sum($result) . ' รายการ)';
                break;

            case 'use_thai_holiday':
                $sourceHolidayId = (int)($_POST['source_holiday_id'] ?? 0);
                if ($thaiHolidaySyncService->addSourceToCompanyHoliday($sourceHolidayId, (int)$user['id'])) {
                    Auth::log('use_thai_holiday', 'hr_thai_holiday_sources', $sourceHolidayId);
                    $success = 'เพิ่มวันหยุดเข้ารายการบริษัทแล้ว';
                } else {
                    $error = 'ไม่พบวันหยุดจาก API ที่เลือก';
                }
                break;

            case 'use_all_thai_holidays_for_year':
                $year = (int)($_POST['holiday_year'] ?? date('Y'));
                $added = $thaiHolidaySyncService->addAllForYear($year, (int)$user['id']);
                Auth::log('use_all_thai_holidays_for_year', 'hr_thai_holiday_sources', null, null, ['year' => $year, 'count' => $added]);
                $success = 'เพิ่ม/อัปเดตวันหยุดบริษัทจาก API ปี ' . ($year + 543) . ' แล้ว ' . $added . ' รายการ';
                break;
                
            case 'update_leave_type':
                $stmt = $pdo->prepare("
                    UPDATE hr_leave_types 
                    SET default_days_per_year = ?, is_paid = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['default_days'],
                    isset($_POST['is_paid']) ? 1 : 0,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['leave_type_id']
                ]);
                Auth::log('update_leave_type', 'hr_leave_types', $_POST['leave_type_id']);
                $success = 'อัพเดทประเภทการลาเรียบร้อยแล้ว';
                break;
                
            case 'update_shift':
                // Sync: name column ถูกเก็บแบบ "กะปกติ (08:30-17:30)" hardcoded
                // ตัด "(...)" ทิ้งเพื่อให้ display layer คุม format ได้ (shift_display_label ใน Helpers.php)
                $cur = $pdo->prepare("SELECT name, is_default FROM hr_work_shifts WHERE id = ? LIMIT 1");
                $cur->execute([$_POST['shift_id']]);
                $curRow = $cur->fetch();
                $newName = null;
                if ($curRow && !empty($curRow['name']) && function_exists('shift_sanitize_name_on_save')) {
                    $newName = shift_sanitize_name_on_save($curRow['name']);
                }

                $stmt = $pdo->prepare("
                    UPDATE hr_work_shifts 
                    SET start_time = ?, end_time = ?, grace_period_minutes = ?, is_active = ?,
                        name = COALESCE(?, name)
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['grace_period'] ?? 15,
                    isset($_POST['is_active']) ? 1 : 0,
                    $newName,
                    $_POST['shift_id']
                ]);
                Auth::log('update_shift', 'hr_work_shifts', $_POST['shift_id']);

                // Default shift is the canonical source for standard work time.
                if ($curRow && (int)($curRow['is_default'] ?? 0) === 1) {
                    try {
                        $startHHMM = substr((string)$_POST['start_time'], 0, 5);
                        $endHHMM   = substr((string)$_POST['end_time'],   0, 5);
                        $grace     = (int)($_POST['grace_period'] ?? 15);

                        $settingsService->set('default_work_start', $startHHMM, 'STRING', Auth::id(), 'HR', 'เวลาเริ่มงานมาตรฐาน');
                        $settingsService->set('default_work_end', $endHHMM, 'STRING', Auth::id(), 'HR', 'เวลาเลิกงานมาตรฐาน');
                        $settingsService->set('grace_period_minutes', (string)$grace, 'NUMBER', Auth::id(), 'HR', 'เวลาผ่อนผันมาสาย (นาที)');
                    } catch (Throwable $e) {
                        error_log('settings.update_shift sync failed: ' . $e->getMessage());
                    }
                }

                $success = 'อัพเดทกะทำงานเรียบร้อยแล้ว';
                break;
        }
    } catch (Throwable $e) {
        tpHrLogException($e, 'hr/settings POST');
        $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ';
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'general';
$holidayYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($holidayYear < 2000 || $holidayYear > 2100) {
    $holidayYear = (int)date('Y');
}

// Fetch data based on tab
$settings = $settingsService->allForHrSettingsPage();

$stmtHolidays = $pdo->prepare("SELECT * FROM hr_holidays WHERE YEAR(date) = ? ORDER BY date");
$stmtHolidays->execute([$holidayYear]);
$holidays = $stmtHolidays->fetchAll();
$holidayCount = count($holidays);
$holidayYearTh = $holidayYear + 543;
$holidayMeetsMinimum = $holidayCount >= 13;
$thaiHolidaySources = [];
try {
    $thaiHolidaySources = $thaiHolidaySyncService->importedForYear($holidayYear);
} catch (Throwable $e) {
    error_log('settings.importedForYear failed: ' . $e->getMessage());
}
$leaveTypes = $pdo->query("SELECT * FROM hr_leave_types ORDER BY sort_order")->fetchAll();
$workShifts = $pdo->query("SELECT * FROM hr_work_shifts ORDER BY id")->fetchAll();

require_once __DIR__ . '/../templates/header.php';
?>

<div class="tp-hr-admin-stack tp-ios-master-screen tp-native-stack--page w-full max-w-[min(960px,100%)] mx-auto min-w-0">
<!-- Page Header -->
<header class="tp-ios-large-title-block mb-6 md:mb-8 min-w-0">
    <nav class="text-sm text-white/60 mb-2" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">ตั้งค่าระบบ</span>
    </nav>
    <h1 class="tp-ios-page-title flex flex-wrap items-center gap-2 mb-2">
        <i class="fas fa-cog text-violet-400 shrink-0" aria-hidden="true"></i>
        <span>ตั้งค่าระบบ</span>
    </h1>
    <p class="tp-ios-caption-muted max-w-[42rem]">จัดการการตั้งค่าระบบ HR</p>
</header>

<?php if (isset($success)): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-emerald-200 text-sm" role="status">
    <i class="fas fa-check-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="mb-4 rounded-[var(--tp-ios-card-radius)] border border-red-500/30 bg-red-500/15 px-4 py-3 text-red-200 text-sm" role="alert">
    <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="mb-6 border-b border-white/10 pb-4">
    <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1 min-w-0" role="tablist" aria-label="หมวดตั้งค่า">
        <a href="?tab=general" role="tab" aria-selected="<?php echo $tab === 'general' ? 'true' : 'false'; ?>"
           class="shrink-0 inline-flex min-h-[48px] items-center gap-2 rounded-[var(--tp-ios-card-radius)] px-4 py-2 whitespace-nowrap transition-colors touch-manipulation <?php echo $tab === 'general' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:bg-white/15 hover:text-white'; ?>">
            <i class="fas fa-sliders-h" aria-hidden="true"></i><span>ทั่วไป</span>
        </a>
        <a href="?tab=holidays" role="tab" aria-selected="<?php echo $tab === 'holidays' ? 'true' : 'false'; ?>"
           class="shrink-0 inline-flex min-h-[48px] items-center gap-2 rounded-[var(--tp-ios-card-radius)] px-4 py-2 whitespace-nowrap transition-colors touch-manipulation <?php echo $tab === 'holidays' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:bg-white/15 hover:text-white'; ?>">
            <i class="fas fa-calendar-day" aria-hidden="true"></i><span>วันหยุด</span>
        </a>
        <a href="?tab=leave-types" role="tab" aria-selected="<?php echo $tab === 'leave-types' ? 'true' : 'false'; ?>"
           class="shrink-0 inline-flex min-h-[48px] items-center gap-2 rounded-[var(--tp-ios-card-radius)] px-4 py-2 whitespace-nowrap transition-colors touch-manipulation <?php echo $tab === 'leave-types' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:bg-white/15 hover:text-white'; ?>">
            <i class="fas fa-umbrella-beach" aria-hidden="true"></i><span>ประเภทการลา</span>
        </a>
        <a href="?tab=shifts" role="tab" aria-selected="<?php echo $tab === 'shifts' ? 'true' : 'false'; ?>"
           class="shrink-0 inline-flex min-h-[48px] items-center gap-2 rounded-[var(--tp-ios-card-radius)] px-4 py-2 whitespace-nowrap transition-colors touch-manipulation <?php echo $tab === 'shifts' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white/10 text-white/70 hover:bg-white/15 hover:text-white'; ?>">
            <i class="fas fa-clock" aria-hidden="true"></i><span>กะทำงาน</span>
        </a>
    </div>
</div>

<?php if ($tab === 'general'): ?>
<?php
// Consistency banner: แสดงค่าปัจจุบันของ default shift เพื่อ admin ตรวจสอบ
$defaultShiftForBanner = null;
foreach ($workShifts as $_ws) {
    if ((int)($_ws['is_default'] ?? 0) === 1) { $defaultShiftForBanner = $_ws; break; }
}
?>
<!-- General Settings -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <h2 class="text-lg font-semibold text-white mb-2">ตั้งค่าทั่วไป</h2>
    <?php if ($defaultShiftForBanner): ?>
    <p class="text-white/55 text-sm mb-6">
        <i class="fas fa-info-circle text-violet-400 mr-1" aria-hidden="true"></i>
        ค่าต่อไปนี้จะ sync กับกะเริ่มต้น
        <strong class="text-white">(<?php echo htmlspecialchars(function_exists('shift_display_label') ? shift_display_label($defaultShiftForBanner) : $defaultShiftForBanner['name']); ?>)</strong>
        ทุกครั้งที่บันทึก
    </p>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="settings-company-name">ชื่อบริษัท</label>
                <input id="settings-company-name" type="text" name="settings[company_name]" class="input-field tp-native-input w-full min-h-[48px]"
                       value="<?php echo htmlspecialchars($settings['company_name'] ?? 'TP Asset Development Co., Ltd.'); ?>">
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="settings-default-work-start">เวลาเริ่มงานมาตรฐาน</label>
                <select data-ios-time-select-for="settings-default-work-start" class="hidden w-full input-field tp-native-select"></select>
                <input type="time" name="settings[default_work_start]" id="settings-default-work-start" class="input-field tp-native-input w-full min-h-[48px]"
                       value="<?php echo htmlspecialchars($settings['default_work_start'] ?? '08:30'); ?>">
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="settings-default-work-end">เวลาเลิกงานมาตรฐาน</label>
                <select data-ios-time-select-for="settings-default-work-end" class="hidden w-full input-field tp-native-select"></select>
                <input type="time" name="settings[default_work_end]" id="settings-default-work-end" class="input-field tp-native-input w-full min-h-[48px]"
                       value="<?php echo htmlspecialchars($settings['default_work_end'] ?? '17:30'); ?>">
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="settings-grace">เวลาผ่อนผัน (นาที)</label>
                <input id="settings-grace" type="number" name="settings[grace_period_minutes]" class="input-field tp-native-input w-full min-h-[48px]" min="0" max="60"
                       value="<?php echo htmlspecialchars($settings['grace_period_minutes'] ?? '15'); ?>">
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="settings-wh-day">ชั่วโมงทำงานต่อวัน</label>
                <input id="settings-wh-day" type="number" name="settings[work_hours_per_day]" class="input-field tp-native-input w-full min-h-[48px]" min="1" max="24" step="0.5"
                       value="<?php echo htmlspecialchars($settings['work_hours_per_day'] ?? '8'); ?>">
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="settings-work-days-week">วันทำงานต่อสัปดาห์</label>
                <select id="settings-work-days-week" name="settings[work_days_per_week]" class="input-field tp-native-select w-full min-h-[48px]">
                    <?php for ($i = 5; $i <= 7; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo ($settings['work_days_per_week'] ?? '5') == $i ? 'selected' : ''; ?>>
                        <?php echo $i; ?> วัน
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        
        <div class="flex flex-col md:flex-row md:justify-end gap-3">
            <button type="submit" class="inline-flex min-h-[48px] w-full md:w-auto items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-6 text-sm font-semibold text-white touch-manipulation gap-2">
                <i class="fas fa-save" aria-hidden="true"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>

<?php elseif ($tab === 'holidays'): ?>
<!-- Holidays -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="space-y-6 min-w-0">
        <!-- Add Holiday Form -->
        <div class="native-card tp-native-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
            <h2 class="text-lg font-semibold text-white mb-4">เพิ่มวันหยุด</h2>
        
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="add_holiday">
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="holiday-add-name">ชื่อวันหยุด</label>
                <input id="holiday-add-name" type="text" name="name" class="input-field tp-native-input w-full min-h-[48px]" required>
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="holiday-add-date">วันที่</label>
                <input id="holiday-add-date" type="date" name="holiday_date" class="input-field tp-native-input w-full min-h-[48px]" required>
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="holiday-add-desc">รายละเอียด</label>
                <input id="holiday-add-desc" type="text" name="description" class="input-field tp-native-input w-full min-h-[48px]">
            </div>
            
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_recurring" id="is_recurring" class="rounded border-white/20 bg-white/5">
                <label for="is_recurring" class="text-white/80 text-sm">วันหยุดประจำปี (ซ้ำทุกปี)</label>
            </div>
            
            <button type="submit" class="inline-flex min-h-[48px] w-full items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 px-4 text-sm font-semibold text-white touch-manipulation gap-2">
                <i class="fas fa-plus" aria-hidden="true"></i>เพิ่มวันหยุด
            </button>
            </form>
        </div>

        <!-- Thailand Holiday API -->
        <div class="native-card tp-native-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
            <h2 class="text-lg font-semibold text-white mb-2">API วันหยุดประเทศไทย</h2>
            <p class="text-white/55 text-sm mb-4">ดึงรายการจาก API แล้วค่อยเลือกวันหยุดที่บริษัทต้องการใช้</p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="sync_thai_holidays">
                <div class="grid grid-cols-2 gap-3">
                    <div class="tp-native-form-group mb-0">
                        <label class="block text-white/70 text-sm mb-2" for="holiday-sync-from">ตั้งแต่ปี</label>
                        <input id="holiday-sync-from" type="number" name="from_year" min="2000" max="2100" value="<?php echo max(2000, (int)$holidayYear - 1); ?>" class="input-field tp-native-input w-full min-h-[48px]">
                    </div>
                    <div class="tp-native-form-group mb-0">
                        <label class="block text-white/70 text-sm mb-2" for="holiday-sync-to">ถึงปี</label>
                        <input id="holiday-sync-to" type="number" name="to_year" min="2000" max="2100" value="<?php echo min(2100, (int)$holidayYear + 1); ?>" class="input-field tp-native-input w-full min-h-[48px]">
                    </div>
                </div>
                <button type="submit" class="inline-flex min-h-[48px] w-full items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-sky-600 hover:bg-sky-700 px-4 text-sm font-semibold text-white touch-manipulation gap-2">
                    <i class="fas fa-cloud-download-alt" aria-hidden="true"></i>อัปเดตจาก API
                </button>
            </form>
        </div>
    </div>
    
    <!-- Holiday List -->
    <div class="xl:col-span-2 native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-white">รายการวันหยุด <?php echo (int)$holidayYearTh; ?></h2>
                <p class="mt-1 text-sm <?php echo $holidayMeetsMinimum ? 'text-emerald-200/85' : 'text-amber-200/85'; ?>">
                    <?php echo (int)$holidayCount; ?>/13 วันขั้นต่ำตามกฎหมาย
                </p>
            </div>
            <form method="GET" class="flex shrink-0 items-center gap-2">
                <input type="hidden" name="tab" value="holidays">
                <label class="sr-only" for="holiday-year-filter">ปี ค.ศ.</label>
                <input id="holiday-year-filter" type="number" name="year" min="2000" max="2100" value="<?php echo (int)$holidayYear; ?>" class="input-field tp-native-input w-28 min-h-[48px]">
                <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-white/10 px-4 text-sm font-semibold text-white hover:bg-white/15 touch-manipulation">
                    ดูปี
                </button>
            </form>
        </div>

        <div class="md:hidden space-y-3">
            <?php foreach ($holidays as $holiday): ?>
            <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($holiday['name']); ?></p>
                        <p class="text-white/50 text-sm mt-1"><?php echo formatDateThai($holiday['date']); ?></p>
                    </div>
                    <?php if ($holiday['type'] === 'PUBLIC'): ?>
                    <span class="inline-flex shrink-0 rounded-[var(--tp-ios-card-radius)] border border-sky-500/30 bg-sky-500/15 px-2 py-0.5 text-xs text-sky-200">ประจำปี</span>
                    <?php else: ?>
                    <span class="inline-flex shrink-0 rounded-[var(--tp-ios-card-radius)] border border-amber-500/30 bg-amber-500/15 px-2 py-0.5 text-xs text-amber-200">พิเศษ</span>
                    <?php endif; ?>
                </div>
                <button type="button"
                    class="mt-4 inline-flex min-h-[48px] w-full items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-red-500/35 bg-red-500/15 text-red-200 hover:bg-red-500/25 text-sm font-medium touch-manipulation gap-2"
                    data-h-del-id="<?php echo (int)$holiday['id']; ?>"
                    data-h-del-name="<?php echo htmlspecialchars($holiday['name'], ENT_QUOTES, 'UTF-8'); ?>"
                    onclick="hrSettingsOpenDelHoliday(this)">
                    <i class="fas fa-trash" aria-hidden="true"></i>ลบวันหยุด
                </button>
            </div>
            <?php endforeach; ?>
            <?php if (empty($holidays)): ?>
            <div class="tp-native-empty-state text-center py-10 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
                <i class="fas fa-calendar-times text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                <p class="text-white/50 text-sm">ยังไม่มีวันหยุดในระบบ</p>
            </div>
            <?php endif; ?>
        </div>

        <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
            <?php if (empty($holidays)): ?>
            <div class="tp-native-empty-state text-center py-10 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
                <i class="fas fa-calendar-times text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                <p class="text-white/50 text-sm">ยังไม่มีวันหยุดในระบบ</p>
            </div>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-white/5">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันที่</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อวันหยุด</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase w-24">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    <?php foreach ($holidays as $holiday): ?>
                    <tr class="hover:bg-white/[0.04]">
                        <td class="px-4 py-3 text-white/80"><?php echo formatDateThai($holiday['date']); ?></td>
                        <td class="px-4 py-3 text-white font-medium"><?php echo htmlspecialchars($holiday['name']); ?></td>
                        <td class="px-4 py-3">
                            <?php if ($holiday['type'] === 'PUBLIC'): ?>
                            <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-sky-500/30 bg-sky-500/15 px-2 py-0.5 text-xs text-sky-200">ประจำปี</span>
                            <?php else: ?>
                            <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-amber-500/30 bg-amber-500/15 px-2 py-0.5 text-xs text-amber-200">พิเศษ</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="button"
                                class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] text-red-300 hover:bg-red-500/15 touch-manipulation"
                                aria-label="ลบวันหยุด"
                                data-h-del-id="<?php echo (int)$holiday['id']; ?>"
                                data-h-del-name="<?php echo htmlspecialchars($holiday['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                onclick="hrSettingsOpenDelHoliday(this)">
                                <i class="fas fa-trash" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="mt-6 border-t border-white/10 pt-5">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-white">รายการจาก API ประเทศไทย</h3>
                    <p class="mt-1 text-sm text-white/55"><?php echo count($thaiHolidaySources); ?> รายการสำหรับปี <?php echo (int)$holidayYearTh; ?></p>
                </div>
                <?php if (!empty($thaiHolidaySources)): ?>
                <form method="POST" class="shrink-0">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="use_all_thai_holidays_for_year">
                    <input type="hidden" name="holiday_year" value="<?php echo (int)$holidayYear; ?>">
                    <button type="submit" class="inline-flex min-h-[48px] w-full sm:w-auto items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-4 text-sm font-semibold text-emerald-100 hover:bg-emerald-500/25 touch-manipulation gap-2">
                        <i class="fas fa-check-double" aria-hidden="true"></i>ใช้ทั้งหมด
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (empty($thaiHolidaySources)): ?>
            <div class="tp-native-empty-state text-center py-8 px-4 rounded-[var(--tp-ios-card-radius)] border border-dashed border-white/15">
                <i class="fas fa-cloud text-slate-500 text-3xl mb-2 block" aria-hidden="true"></i>
                <p class="text-white/50 text-sm">ยังไม่มีข้อมูลจาก API สำหรับปีนี้</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($thaiHolidaySources as $sourceHoliday): ?>
                <div class="flex flex-col gap-3 rounded-[var(--tp-ios-card-radius)] bg-white/[0.04] border border-white/10 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($sourceHoliday['name']); ?></p>
                        <p class="mt-1 text-sm text-white/50 break-words">
                            <?php echo formatDateThai($sourceHoliday['date']); ?>
                            <?php if (!empty($sourceHoliday['name_en'])): ?>
                            · <?php echo htmlspecialchars($sourceHoliday['name_en']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ((int)($sourceHoliday['is_selected'] ?? 0) === 1): ?>
                    <span class="inline-flex min-h-[40px] shrink-0 items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-3 text-sm text-emerald-100">ใช้อยู่</span>
                    <?php else: ?>
                    <form method="POST" class="shrink-0">
                        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                        <input type="hidden" name="action" value="use_thai_holiday">
                        <input type="hidden" name="source_holiday_id" value="<?php echo (int)$sourceHoliday['id']; ?>">
                        <button type="submit" class="inline-flex min-h-[40px] w-full sm:w-auto items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 px-3 text-sm font-semibold text-white hover:bg-violet-700 touch-manipulation gap-2">
                            <i class="fas fa-plus" aria-hidden="true"></i>เลือกใช้
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($tab === 'leave-types'): ?>
<!-- Leave Types -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <h2 class="text-lg font-semibold text-white mb-4">ประเภทการลา</h2>

    <div class="md:hidden space-y-3">
        <?php foreach ($leaveTypes as $lt): ?>
        <?php
        $conditions = [];
        if ($lt['gender_restriction'] !== 'ALL') {
            $conditions[] = $lt['gender_restriction'] === 'MALE' ? 'เฉพาะชาย' : 'เฉพาะหญิง';
        }
        if ($lt['min_months_employed'] > 0) {
            $conditions[] = "ทำงานครบ {$lt['min_months_employed']} เดือน";
        }
        if ($lt['requires_document']) {
            $conditions[] = 'ต้องมีเอกสาร';
        }
        ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="w-3 h-3 rounded-full mt-1.5 shrink-0" style="background: <?php echo $lt['color']; ?>"></div>
                    <div class="min-w-0">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($lt['name']); ?></p>
                        <p class="text-slate-500 text-xs break-words"><?php echo htmlspecialchars($lt['name_en']); ?></p>
                    </div>
                </div>
                <?php if ($lt['is_active']): ?>
                <span class="inline-flex shrink-0 rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-2 py-0.5 text-xs text-emerald-200">เปิด</span>
                <?php else: ?>
                <span class="inline-flex shrink-0 rounded-[var(--tp-ios-card-radius)] border border-red-500/35 bg-red-500/15 px-2 py-0.5 text-xs text-red-200">ปิด</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                <div>
                    <p class="text-slate-500">วันต่อปี</p>
                    <p class="text-white font-medium"><?php echo number_format($lt['default_days_per_year'], 1); ?> วัน</p>
                </div>
                <div>
                    <p class="text-slate-500">ค่าจ้าง</p>
                    <?php if ($lt['is_paid']): ?>
                    <p class="text-emerald-300 font-medium">ได้รับ</p>
                    <?php else: ?>
                    <p class="text-red-300 font-medium">ไม่ได้รับ</p>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-slate-400 text-sm mt-3"><?php echo $conditions ? htmlspecialchars(implode(', ', $conditions)) : '-'; ?></p>
            <button type="button" onclick="editLeaveType(<?php echo htmlspecialchars(json_encode($lt)); ?>)"
                    class="inline-flex min-h-[48px] w-full items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 text-white hover:bg-white/20 text-sm font-medium touch-manipulation gap-2 mt-4">
                <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไข
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 max-w-full overscroll-x-contain -mx-1 px-1 pb-px">
        <table class="w-full text-sm" style="min-width:840px">
            <thead class="bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ประเภท</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">วันต่อปี</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">ได้รับค่าจ้าง</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">เงื่อนไขพิเศษ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">สถานะ</th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase w-20"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($leaveTypes as $lt): ?>
                <tr class="hover:bg-white/[0.04]">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white/10" style="background: <?php echo htmlspecialchars((string)$lt['color']); ?>"></div>
                            <div>
                                <p class="text-white font-medium"><?php echo htmlspecialchars($lt['name']); ?></p>
                                <p class="text-white/50 text-xs"><?php echo htmlspecialchars($lt['name_en']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-white font-medium"><?php echo number_format($lt['default_days_per_year'], 1); ?></span>
                        <span class="text-white/45">วัน</span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($lt['is_paid']): ?>
                        <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-2 py-0.5 text-xs text-emerald-200">ได้รับ</span>
                        <?php else: ?>
                        <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-red-500/35 bg-red-500/15 px-2 py-0.5 text-xs text-red-200">ไม่ได้รับ</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-white/55 text-sm">
                        <?php 
                        $conditions = [];
                        if ($lt['gender_restriction'] !== 'ALL') {
                            $conditions[] = $lt['gender_restriction'] === 'MALE' ? 'เฉพาะชาย' : 'เฉพาะหญิง';
                        }
                        if ($lt['min_months_employed'] > 0) {
                            $conditions[] = "ทำงานครบ {$lt['min_months_employed']} เดือน";
                        }
                        if ($lt['requires_document']) {
                            $conditions[] = 'ต้องมีเอกสาร';
                        }
                        echo $conditions ? implode(', ', $conditions) : '-';
                        ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($lt['is_active']): ?>
                        <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-emerald-500/30 bg-emerald-500/15 px-2 py-0.5 text-xs text-emerald-200">เปิดใช้งาน</span>
                        <?php else: ?>
                        <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-red-500/35 bg-red-500/15 px-2 py-0.5 text-xs text-red-200">ปิดใช้งาน</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button type="button" onclick="editLeaveType(<?php echo htmlspecialchars(json_encode($lt)); ?>)" 
                                class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] text-violet-300 hover:bg-violet-500/20 touch-manipulation"
                                title="แก้ไข">
                            <i class="fas fa-edit" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Leave Type Modal -->
<div id="editLeaveTypeModal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="editLeaveTypeModalTitle">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden rounded-[var(--tp-ios-card-radius)] p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1rem)] border border-white/10">
        <div class="flex items-center justify-between mb-4">
            <h3 id="editLeaveTypeModalTitle" class="text-lg font-semibold text-white">แก้ไขประเภทการลา</h3>
            <button type="button" onclick="closeModal('editLeaveTypeModal')" class="text-white/50 hover:text-white touch-manipulation min-h-[48px] min-w-[48px] inline-flex items-center justify-center rounded-[var(--tp-ios-card-radius)]" aria-label="ปิด">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="update_leave_type">
            <input type="hidden" name="leave_type_id" id="edit_leave_type_id">
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="edit_leave_name">ชื่อประเภท</label>
                <input type="text" id="edit_leave_name" class="input-field tp-native-input w-full min-h-[48px] opacity-80" disabled>
            </div>
            
            <div class="tp-native-form-group mb-0">
                <label class="block text-white/70 text-sm mb-2" for="edit_default_days">จำนวนวันต่อปี</label>
                <input type="number" name="default_days" id="edit_default_days" class="input-field tp-native-input w-full min-h-[48px]" min="0" max="365" step="0.5" required>
            </div>
            
            <div class="flex flex-wrap items-center gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_paid" id="edit_is_paid" class="rounded border-white/20 bg-white/5">
                    <span class="text-white/80 text-sm">ได้รับค่าจ้าง</span>
                </label>
                
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" id="edit_is_active" class="rounded border-white/20 bg-white/5">
                    <span class="text-white/80 text-sm">เปิดใช้งาน</span>
                </label>
            </div>
            
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal('editLeaveTypeModal')" class="inline-flex flex-1 min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 text-white hover:bg-white/20 text-sm font-medium touch-manipulation">
                    ยกเลิก
                </button>
                <button type="submit" class="inline-flex flex-1 min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold touch-manipulation gap-2">
                    <i class="fas fa-save" aria-hidden="true"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editLeaveType(lt) {
    document.getElementById('edit_leave_type_id').value = lt.id;
    document.getElementById('edit_leave_name').value = lt.name;
    document.getElementById('edit_default_days').value = lt.default_days_per_year;
    document.getElementById('edit_is_paid').checked = lt.is_paid == 1;
    document.getElementById('edit_is_active').checked = lt.is_active == 1;
    if (typeof uiOpenModal === 'function') uiOpenModal('editLeaveTypeModal');
    else {
        const m = document.getElementById('editLeaveTypeModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function closeModal(id) {
    if (typeof uiCloseModal === 'function') uiCloseModal(id);
    else {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}
(function(){
    var _el = document.getElementById('editLeaveTypeModal');
    if (_el) _el.addEventListener('click', function(e) {
        if (e.target === this) closeModal('editLeaveTypeModal');
    });
})();
</script>

<?php elseif ($tab === 'shifts'): ?>
<!-- Work Shifts -->
<div class="native-card tp-native-card tp-native-data-card overflow-hidden rounded-[var(--tp-ios-card-radius)] p-5 sm:p-6 min-w-0 border border-white/10">
    <h2 class="text-lg font-semibold text-white mb-4">กะทำงาน</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($workShifts as $shift): ?>
        <?php $shiftRowId = (int)$shift['id']; ?>
        <div class="rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 p-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-white font-medium"><?php echo htmlspecialchars(function_exists('shift_display_label') ? shift_display_label($shift) : $shift['name']); ?></h3>
                    <p class="text-white/50 text-sm"><?php echo htmlspecialchars($shift['code']); ?></p>
                </div>
                <?php if ($shift['is_default']): ?>
                <span class="inline-flex rounded-[var(--tp-ios-card-radius)] border border-sky-500/30 bg-sky-500/15 px-2 py-0.5 text-xs text-sky-200">ค่าเริ่มต้น</span>
                <?php endif; ?>
            </div>
            
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="update_shift">
                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                
                <div class="grid grid-cols-2 gap-3">
                    <div class="tp-native-form-group mb-0">
                        <label class="block text-white/60 text-xs mb-1" for="shift-<?php echo $shiftRowId; ?>-start">เวลาเริ่ม</label>
                        <select data-ios-time-select-for="shift-<?php echo $shiftRowId; ?>-start" class="hidden w-full input-field tp-native-select text-sm"></select>
                        <input type="time" name="start_time" id="shift-<?php echo $shiftRowId; ?>-start" class="input-field tp-native-input w-full min-h-[48px] text-sm"
                               value="<?php echo substr($shift['start_time'], 0, 5); ?>">
                    </div>
                    <div class="tp-native-form-group mb-0">
                        <label class="block text-white/60 text-xs mb-1" for="shift-<?php echo $shiftRowId; ?>-end">เวลาสิ้นสุด</label>
                        <select data-ios-time-select-for="shift-<?php echo $shiftRowId; ?>-end" class="hidden w-full input-field tp-native-select text-sm"></select>
                        <input type="time" name="end_time" id="shift-<?php echo $shiftRowId; ?>-end" class="input-field tp-native-input w-full min-h-[48px] text-sm"
                               value="<?php echo substr($shift['end_time'], 0, 5); ?>">
                    </div>
                </div>
                
                <div class="tp-native-form-group mb-0">
                    <label class="block text-white/60 text-xs mb-1">เวลาผ่อนผัน (นาที)</label>
                    <input type="number" name="grace_period" class="input-field tp-native-input w-full min-h-[48px] text-sm" min="0" max="60"
                           value="<?php echo $shift['grace_period_minutes']; ?>">
                </div>
                
                <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" class="rounded border-white/20 bg-white/5" <?php echo $shift['is_active'] ? 'checked' : ''; ?>>
                        <span class="text-white/80 text-sm">เปิดใช้งาน</span>
                    </label>
                    
                    <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-4 text-sm font-medium text-white hover:bg-white/20 touch-manipulation gap-2">
                        <i class="fas fa-save mr-1" aria-hidden="true"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div>

<!-- Confirm delete holiday (opened from Holidays tab) -->
<div id="hr-settings-del-holiday-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]" role="dialog" aria-modal="true" aria-labelledby="hr-settings-del-holiday-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto rounded-[var(--tp-ios-card-radius)] p-6 border border-white/10 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <h3 id="hr-settings-del-holiday-title" class="text-xl font-bold text-white mb-2">ลบวันหยุด</h3>
        <p class="text-white/70 text-sm mb-6">ยืนยันการลบ <strong id="hr-settings-del-holiday-name" class="text-white font-semibold"></strong> หรือไม่?</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="delete_holiday">
            <input type="hidden" name="holiday_id" id="hr-settings-del-holiday-id" value="">
            <div class="flex flex-wrap gap-2 justify-end">
                <button type="button" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] border border-white/15 bg-white/10 px-4 text-sm font-medium text-white hover:bg-white/20 touch-manipulation" onclick="hrSettingsCloseDelHoliday()">ยกเลิก</button>
                <button type="submit" class="inline-flex min-h-[48px] items-center justify-center rounded-[var(--tp-ios-card-radius)] bg-red-600 hover:bg-red-700 px-4 text-sm font-semibold text-white touch-manipulation">ลบวันหยุด</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    function hrSettingsOpenDelHoliday(btn) {
        var idEl = document.getElementById('hr-settings-del-holiday-id');
        var nameEl = document.getElementById('hr-settings-del-holiday-name');
        if (idEl) idEl.value = btn.getAttribute('data-h-del-id') || '';
        if (nameEl) nameEl.textContent = btn.getAttribute('data-h-del-name') || '';
        var m = document.getElementById('hr-settings-del-holiday-modal');
        if (typeof uiOpenModal === 'function') uiOpenModal('hr-settings-del-holiday-modal');
        else if (m) { m.classList.remove('hidden'); m.classList.add('flex'); }
    }
    function hrSettingsCloseDelHoliday() {
        if (typeof uiCloseModal === 'function') uiCloseModal('hr-settings-del-holiday-modal');
        else {
            var m = document.getElementById('hr-settings-del-holiday-modal');
            if (!m) return;
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
    }
    window.hrSettingsOpenDelHoliday = hrSettingsOpenDelHoliday;
    window.hrSettingsCloseDelHoliday = hrSettingsCloseDelHoliday;
    var hm = document.getElementById('hr-settings-del-holiday-modal');
    if (hm) hm.addEventListener('click', function (e) { if (e.target === hm) hrSettingsCloseDelHoliday(); });
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
