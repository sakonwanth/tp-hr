<?php
/**
 * HR Settings - System Configuration
 */

require_once __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Auth::requireHR();

$pdo = getDB();
$user = Auth::user();

$page_title = 'ตั้งค่าระบบ';
$current_page = 'hr-settings';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_settings':
                foreach ($_POST['settings'] as $key => $value) {
                    $stmt = $pdo->prepare("
                        INSERT INTO hr_settings (`key`, `value`, updated_by) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by), updated_at = NOW()
                    ");
                    $stmt->execute([$key, $value, $user['id']]);
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
                $stmt = $pdo->prepare("
                    UPDATE hr_work_shifts 
                    SET start_time = ?, end_time = ?, grace_period_minutes = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['start_time'],
                    $_POST['end_time'],
                    $_POST['grace_period'] ?? 15,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['shift_id']
                ]);
                Auth::log('update_shift', 'hr_work_shifts', $_POST['shift_id']);
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
$settings = [];
$stmt = $pdo->query("SELECT `key`, `value` FROM hr_settings");
while ($row = $stmt->fetch()) {
    $settings[$row['key']] = $row['value'];
}

$holidays = $pdo->query("SELECT * FROM hr_holidays ORDER BY date")->fetchAll();
$leaveTypes = $pdo->query("SELECT * FROM hr_leave_types ORDER BY sort_order")->fetchAll();
$workShifts = $pdo->query("SELECT * FROM hr_work_shifts ORDER BY id")->fetchAll();

require_once __DIR__ . '/../templates/header.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-white mb-2">
        <i class="fas fa-cog text-primary-400 mr-2"></i>
        ตั้งค่าระบบ
    </h1>
    <p class="text-slate-400">จัดการการตั้งค่าระบบ HR</p>
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
<div class="flex gap-2 mb-6 border-b border-slate-700 pb-4">
    <a href="?tab=general" class="px-4 py-2 rounded-lg <?php echo $tab === 'general' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-sliders-h mr-2"></i>ทั่วไป
    </a>
    <a href="?tab=holidays" class="px-4 py-2 rounded-lg <?php echo $tab === 'holidays' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-calendar-day mr-2"></i>วันหยุด
    </a>
    <a href="?tab=leave-types" class="px-4 py-2 rounded-lg <?php echo $tab === 'leave-types' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-umbrella-beach mr-2"></i>ประเภทการลา
    </a>
    <a href="?tab=shifts" class="px-4 py-2 rounded-lg <?php echo $tab === 'shifts' ? 'bg-primary-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'; ?>">
        <i class="fas fa-clock mr-2"></i>กะทำงาน
    </a>
</div>

<?php if ($tab === 'general'): ?>
<!-- General Settings -->
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-6">ตั้งค่าทั่วไป</h2>
    
    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="action" value="update_settings">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-slate-400 text-sm mb-2">ชื่อบริษัท</label>
                <input type="text" name="settings[company_name]" class="input-field" 
                       value="<?php echo htmlspecialchars($settings['company_name'] ?? 'TP Asset Development Co., Ltd.'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">เวลาเริ่มงานมาตรฐาน</label>
                <input type="time" name="settings[default_work_start]" class="input-field" 
                       value="<?php echo htmlspecialchars($settings['default_work_start'] ?? '08:30'); ?>">
            </div>
            
            <div>
                <label class="block text-slate-400 text-sm mb-2">เวลาเลิกงานมาตรฐาน</label>
                <input type="time" name="settings[default_work_end]" class="input-field" 
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
        
        <div class="flex justify-end">
            <button type="submit" class="btn-primary">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>

<?php elseif ($tab === 'holidays'): ?>
<!-- Holidays -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
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
    <div class="lg:col-span-2 glass-card rounded-2xl p-6">
        <h2 class="text-lg font-semibold text-white mb-4">รายการวันหยุด <?php echo date('Y') + 543; ?></h2>
        
        <div class="overflow-x-auto">
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
                                <button type="submit" class="text-red-400 hover:text-red-300">
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
    
    <div class="overflow-x-auto">
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
<div id="editLeaveTypeModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center">
    <div class="glass-card rounded-2xl p-6 w-full max-w-md m-4">
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
    document.getElementById('editLeaveTypeModal').classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
</script>

<?php elseif ($tab === 'shifts'): ?>
<!-- Work Shifts -->
<div class="glass-card rounded-2xl p-6">
    <h2 class="text-lg font-semibold text-white mb-4">กะทำงาน</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($workShifts as $shift): ?>
        <div class="bg-slate-800/50 rounded-xl p-4">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-white font-medium"><?php echo htmlspecialchars($shift['name']); ?></h3>
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
                        <input type="time" name="start_time" class="input-field text-sm" 
                               value="<?php echo substr($shift['start_time'], 0, 5); ?>">
                    </div>
                    <div>
                        <label class="block text-slate-500 text-xs mb-1">เวลาสิ้นสุด</label>
                        <input type="time" name="end_time" class="input-field text-sm" 
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
                    
                    <button type="submit" class="btn-secondary text-sm py-1">
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
