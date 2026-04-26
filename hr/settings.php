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
    } catch (Exception $e) {
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'general';

// Fetch data based on tab
$settings = $settingsService->allForHrSettingsPage();

$holidays = $pdo->query("SELECT * FROM hr_holidays ORDER BY date")->fetchAll();
$leaveTypes = $pdo->query("SELECT * FROM hr_leave_types ORDER BY sort_order")->fetchAll();
$workShifts = $pdo->query("SELECT * FROM hr_work_shifts ORDER BY id")->fetchAll();

require_once __DIR__ . '/../templates/header.php';
?>

<!-- Page Header -->
<div class="mb-6 min-w-0">
    <nav class="text-sm text-white/60 mb-3" aria-label="Breadcrumb">
        <a href="/hr/index.php" class="hover:text-white touch-manipulation">แดชบอร์ด HR</a>
        <span class="mx-2">/</span>
        <span class="text-white">ตั้งค่าระบบ</span>
    </nav>
    <h1 class="text-2xl font-bold text-white tracking-tight mb-2">
        <i class="fas fa-cog text-primary-400 mr-2"></i>
        ตั้งค่าระบบ
    </h1>
    <p class="text-slate-300 text-sm leading-relaxed">จัดการการตั้งค่าระบบ HR</p>
</div>

<?php if (isset($success)): ?>
<div class="mb-4 p-4 rounded-xl bg-emerald-500/20 border border-emerald-500/30 text-emerald-400">
    <i class="fas fa-check-circle mr-2"></i>
    <?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="mb-4 p-4 rounded-xl bg-red-500/20 border border-red-500/30 text-red-400">
    <i class="fas fa-exclamation-circle mr-2"></i>
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="mb-6 border-b border-slate-700 pb-4">
    <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1">
        <a href="?tab=general"
           class="shrink-0 px-4 py-2 rounded-xl whitespace-nowrap min-h-[44px] flex items-center gap-2 <?php echo $tab === 'general' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white'; ?>">
            <i class="fas fa-sliders-h"></i><span>ทั่วไป</span>
        </a>
        <a href="?tab=holidays"
           class="shrink-0 px-4 py-2 rounded-xl whitespace-nowrap min-h-[44px] flex items-center gap-2 <?php echo $tab === 'holidays' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white'; ?>">
            <i class="fas fa-calendar-day"></i><span>วันหยุด</span>
        </a>
        <a href="?tab=leave-types"
           class="shrink-0 px-4 py-2 rounded-xl whitespace-nowrap min-h-[44px] flex items-center gap-2 <?php echo $tab === 'leave-types' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white'; ?>">
            <i class="fas fa-umbrella-beach"></i><span>ประเภทการลา</span>
        </a>
        <a href="?tab=shifts"
           class="shrink-0 px-4 py-2 rounded-xl whitespace-nowrap min-h-[44px] flex items-center gap-2 <?php echo $tab === 'shifts' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white'; ?>">
            <i class="fas fa-clock"></i><span>กะทำงาน</span>
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
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-2">ตั้งค่าทั่วไป</h2>
    <?php if ($defaultShiftForBanner): ?>
    <p class="text-slate-400 text-sm mb-6">
        <i class="fas fa-info-circle text-primary-400 mr-1"></i>
        ค่าต่อไปนี้จะ sync กับกะเริ่มต้น
        <strong class="text-white">(<?php echo htmlspecialchars(function_exists('shift_display_label') ? shift_display_label($defaultShiftForBanner) : $defaultShiftForBanner['name']); ?>)</strong>
        ทุกครั้งที่บันทึก
    </p>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
            <div>
                <label class="block text-slate-400 text-sm mb-2">ชื่อบริษัท</label>
                <input type="text" name="settings[company_name]" class="input-field" 
                       value="<?php echo htmlspecialchars($settings['company_name'] ?? 'TP Asset Development Co., Ltd.'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">เวลาเริ่มงานมาตรฐาน</label>
                <select data-ios-time-select-for="settings-default-work-start" class="hidden w-full input-field"></select>
                <input type="time" name="settings[default_work_start]" id="settings-default-work-start" class="input-field"
                       value="<?php echo htmlspecialchars($settings['default_work_start'] ?? '08:30'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">เวลาเลิกงานมาตรฐาน</label>
                <select data-ios-time-select-for="settings-default-work-end" class="hidden w-full input-field"></select>
                <input type="time" name="settings[default_work_end]" id="settings-default-work-end" class="input-field"
                       value="<?php echo htmlspecialchars($settings['default_work_end'] ?? '17:30'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">เวลาผ่อนผัน (นาที)</label>
                <input type="number" name="settings[grace_period_minutes]" class="input-field" min="0" max="60"
                       value="<?php echo htmlspecialchars($settings['grace_period_minutes'] ?? '15'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">ชั่วโมงทำงานต่อวัน</label>
                <input type="number" name="settings[work_hours_per_day]" class="input-field" min="1" max="24" step="0.5"
                       value="<?php echo htmlspecialchars($settings['work_hours_per_day'] ?? '8'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">วันทำงานต่อสัปดาห์</label>
                <select name="settings[work_days_per_week]" class="input-field">
                    <?php for ($i = 5; $i <= 7; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo ($settings['work_days_per_week'] ?? '5') == $i ? 'selected' : ''; ?>>
                        <?php echo $i; ?> วัน
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        
        <div class="flex flex-col md:flex-row md:justify-end gap-3">
            <button type="submit" class="btn-primary w-full md:w-auto min-h-[44px]">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>

<?php elseif ($tab === 'holidays'): ?>
<!-- Holidays -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <!-- Add Holiday Form -->
    <div class="glass-card rounded-2xl p-6">
        <h2 class="text-lg font-semibold text-white mb-4">เพิ่มวันหยุด</h2>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="add_holiday">
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">ชื่อวันหยุด</label>
                <input type="text" name="name" class="input-field" required>
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">วันที่</label>
                <input type="date" name="holiday_date" class="input-field" required>
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">รายละเอียด</label>
                <input type="text" name="description" class="input-field">
            </div>
            
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_recurring" id="is_recurring" class="rounded">
                <label for="is_recurring" class="text-slate-300">วันหยุดประจำปี (ซ้ำทุกปี)</label>
            </div>
            
            <button type="submit" class="btn-primary w-full">
                <i class="fas fa-plus mr-2"></i>เพิ่มวันหยุด
            </button>
        </form>
    </div>
    
    <!-- Holiday List -->
    <div class="xl:col-span-2 glass-card rounded-2xl p-6">
        <h2 class="text-lg font-semibold text-white mb-4">รายการวันหยุด <?php echo date('Y') + 543; ?></h2>

        <div class="md:hidden space-y-3">
            <?php foreach ($holidays as $holiday): ?>
            <div class="rounded-xl bg-slate-800/50 border border-white/10 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($holiday['name']); ?></p>
                        <p class="text-slate-400 text-sm mt-1"><?php echo formatDateThai($holiday['date']); ?></p>
                    </div>
                    <?php if ($holiday['type'] === 'PUBLIC'): ?>
                    <span class="badge badge-info shrink-0">ประจำปี</span>
                    <?php else: ?>
                    <span class="badge badge-warning shrink-0">พิเศษ</span>
                    <?php endif; ?>
                </div>
                <form method="POST" class="mt-4" onsubmit="return confirm('ยืนยันการลบ?')">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="delete_holiday">
                    <input type="hidden" name="holiday_id" value="<?php echo $holiday['id']; ?>">
                    <button type="submit" class="btn-secondary w-full min-h-[44px] text-red-300 border-red-500/30">
                        <i class="fas fa-trash mr-2"></i>ลบวันหยุด
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="hidden md:block overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>ชื่อวันหยุด</th>
                        <th>ประเภท</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidays as $holiday): ?>
                    <tr>
                        <td><?php echo formatDateThai($holiday['date']); ?></td>
                        <td><?php echo htmlspecialchars($holiday['name']); ?></td>
                        <td>
                            <?php if ($holiday['type'] === 'PUBLIC'): ?>
                            <span class="badge badge-info">ประจำปี</span>
                            <?php else: ?>
                            <span class="badge badge-warning">พิเศษ</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="inline" onsubmit="return confirm('ยืนยันการลบ?')">
                                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                <input type="hidden" name="action" value="delete_holiday">
                                <input type="hidden" name="holiday_id" value="<?php echo $holiday['id']; ?>">
                                <button type="submit" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center text-red-400 hover:text-red-300 touch-manipulation" aria-label="ลบวันหยุด">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'leave-types'): ?>
<!-- Leave Types -->
<div class="glass-card rounded-2xl p-6">
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
        <div class="rounded-xl bg-slate-800/50 border border-white/10 p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="w-3 h-3 rounded-full mt-1.5 shrink-0" style="background: <?php echo $lt['color']; ?>"></div>
                    <div class="min-w-0">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($lt['name']); ?></p>
                        <p class="text-slate-500 text-xs break-words"><?php echo htmlspecialchars($lt['name_en']); ?></p>
                    </div>
                </div>
                <?php if ($lt['is_active']): ?>
                <span class="badge badge-success shrink-0">เปิด</span>
                <?php else: ?>
                <span class="badge badge-danger shrink-0">ปิด</span>
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
            <button onclick="editLeaveType(<?php echo htmlspecialchars(json_encode($lt)); ?>)"
                    class="btn-secondary w-full min-h-[44px] mt-4">
                <i class="fas fa-edit mr-2"></i>แก้ไข
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ประเภท</th>
                    <th>วันต่อปี</th>
                    <th>ได้รับค่าจ้าง</th>
                    <th>เงื่อนไขพิเศษ</th>
                    <th>สถานะ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaveTypes as $lt): ?>
                <tr>
                    <td>
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 rounded-full" style="background: <?php echo $lt['color']; ?>"></div>
                            <div>
                                <p class="text-white font-medium"><?php echo htmlspecialchars($lt['name']); ?></p>
                                <p class="text-slate-500 text-xs"><?php echo htmlspecialchars($lt['name_en']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="text-white font-medium"><?php echo number_format($lt['default_days_per_year'], 1); ?></span>
                        <span class="text-slate-500">วัน</span>
                    </td>
                    <td>
                        <?php if ($lt['is_paid']): ?>
                        <span class="badge badge-success">ได้รับ</span>
                        <?php else: ?>
                        <span class="badge badge-danger">ไม่ได้รับ</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-slate-400 text-sm">
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
                    <td>
                        <?php if ($lt['is_active']): ?>
                        <span class="badge badge-success">เปิดใช้งาน</span>
                        <?php else: ?>
                        <span class="badge badge-danger">ปิดใช้งาน</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button onclick="editLeaveType(<?php echo htmlspecialchars(json_encode($lt)); ?>)" 
                                class="text-primary-400 hover:text-primary-300">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Leave Type Modal -->
<div id="editLeaveTypeModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="glass-card rounded-2xl p-6 w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-white">แก้ไขประเภทการลา</h3>
            <button onclick="closeModal('editLeaveTypeModal')" class="text-slate-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="update_leave_type">
            <input type="hidden" name="leave_type_id" id="edit_leave_type_id">
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">ชื่อประเภท</label>
                <input type="text" id="edit_leave_name" class="input-field" disabled>
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">จำนวนวันต่อปี</label>
                <input type="number" name="default_days" id="edit_default_days" class="input-field" min="0" max="365" step="0.5" required>
            </div>
            
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_paid" id="edit_is_paid" class="rounded">
                    <span class="text-slate-300">ได้รับค่าจ้าง</span>
                </label>
                
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="edit_is_active" class="rounded">
                    <span class="text-slate-300">เปิดใช้งาน</span>
                </label>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeModal('editLeaveTypeModal')" class="btn-secondary flex-1">
                    ยกเลิก
                </button>
                <button type="submit" class="btn-primary flex-1">
                    <i class="fas fa-save mr-2"></i>บันทึก
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
</script>

<?php elseif ($tab === 'shifts'): ?>
<!-- Work Shifts -->
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-4">กะทำงาน</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($workShifts as $shift): ?>
        <?php $shiftRowId = (int)$shift['id']; ?>
        <div class="bg-slate-800/50 border border-white/10 rounded-xl p-4">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-white font-medium"><?php echo htmlspecialchars(function_exists('shift_display_label') ? shift_display_label($shift) : $shift['name']); ?></h3>
                    <p class="text-slate-500 text-sm"><?php echo htmlspecialchars($shift['code']); ?></p>
                </div>
                <?php if ($shift['is_default']): ?>
                <span class="badge badge-info">ค่าเริ่มต้น</span>
                <?php endif; ?>
            </div>
            
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="update_shift">
                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-slate-500 text-xs mb-1">เวลาเริ่ม</label>
                        <select data-ios-time-select-for="shift-<?php echo $shiftRowId; ?>-start" class="hidden w-full input-field text-sm"></select>
                        <input type="time" name="start_time" id="shift-<?php echo $shiftRowId; ?>-start" class="input-field text-sm"
                               value="<?php echo substr($shift['start_time'], 0, 5); ?>">
                    </div>
                    <div>
                        <label class="block text-slate-500 text-xs mb-1">เวลาสิ้นสุด</label>
                        <select data-ios-time-select-for="shift-<?php echo $shiftRowId; ?>-end" class="hidden w-full input-field text-sm"></select>
                        <input type="time" name="end_time" id="shift-<?php echo $shiftRowId; ?>-end" class="input-field text-sm"
                               value="<?php echo substr($shift['end_time'], 0, 5); ?>">
                    </div>
                </div>
                
                <div>
                    <label class="block text-slate-500 text-xs mb-1">เวลาผ่อนผัน (นาที)</label>
                    <input type="number" name="grace_period" class="input-field text-sm" min="0" max="60"
                           value="<?php echo $shift['grace_period_minutes']; ?>">
                </div>
                
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="is_active" class="rounded" <?php echo $shift['is_active'] ? 'checked' : ''; ?>>
                        <span class="text-slate-300 text-sm">เปิดใช้งาน</span>
                    </label>
                    
                    <button type="submit" class="btn-secondary text-sm min-h-[44px] px-4">
                        <i class="fas fa-save mr-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
