<?php
/**
 * Profile Page
 * ข้อมูลส่วนตัว
 */

$page_title = 'ข้อมูลส่วนตัว';
$current_page = 'profile';
require_once __DIR__ . '/bootstrap.php';

Auth::requireLogin();
$user = Auth::user();

$pdo = Database::getInstance()->getConnection();

/** @var array<string,mixed>|false $profile */
$profile = false;
$emergencyContacts = [];
$familyMembers = [];
$educations = [];
$workHistory = [];

try {
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name, u.department as department_name, u.position as position_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('profile.php users query: ' . $e->getMessage());
}

if ($profile) {
    try {
        $stmtEmergency = $pdo->prepare("SELECT * FROM hr_emergency_contacts WHERE user_id = ? ORDER BY is_primary DESC");
        $stmtEmergency->execute([$user['id']]);
        $emergencyContacts = $stmtEmergency->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('profile.php emergency: ' . $e->getMessage());
    }
    try {
        $stmtFamily = $pdo->prepare("SELECT * FROM hr_employee_family WHERE user_id = ? ORDER BY relationship");
        $stmtFamily->execute([$user['id']]);
        $familyMembers = $stmtFamily->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('profile.php family: ' . $e->getMessage());
    }
    try {
        $stmtEducation = $pdo->prepare("SELECT * FROM hr_employee_education WHERE user_id = ? ORDER BY graduation_year DESC");
        $stmtEducation->execute([$user['id']]);
        $educations = $stmtEducation->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('profile.php education: ' . $e->getMessage());
    }
    try {
        $stmtWork = $pdo->prepare("SELECT * FROM hr_employee_work_history WHERE user_id = ? ORDER BY start_date DESC");
        $stmtWork->execute([$user['id']]);
        $workHistory = $stmtWork->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('profile.php work_history: ' . $e->getMessage());
    }
}

$action = $_GET['action'] ?? '';

include 'templates/header.php';
?>

<?php if (!$profile): ?>
<div class="native-card tp-native-card tp-native-data-card p-10 text-center max-w-lg mx-auto">
    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-violet-600/25 flex items-center justify-center">
        <i class="fas fa-user-slash text-violet-300 text-2xl" aria-hidden="true"></i>
    </div>
    <p class="text-white font-medium mb-2">ไม่พบข้อมูลบัญชีในฐานข้อมูล</p>
    <p class="text-white/60 text-sm mb-6">กรุณาออกจากระบบแล้วเข้าใหม่ หรือติดต่อฝ่าย HR หากยังเห็นข้อความนี้</p>
    <form method="post" action="/logout.php" class="inline m-0">
        <?php echo csrfField(); ?>
        <button type="submit" class="inline-flex min-h-[56px] items-center justify-center rounded-[20px] bg-violet-600 px-6 py-3 font-semibold text-white transition-colors hover:bg-violet-700 touch-manipulation border-0 cursor-pointer">ออกจากระบบ</button>
    </form>
</div>
<?php include 'templates/footer.php';
exit;
endif; ?>

<div class="mb-4 md:mb-6 min-w-0">
    <h1 class="text-2xl font-bold text-white tracking-tight">ข้อมูลส่วนตัว</h1>
    <p class="text-slate-300 text-sm mt-1.5 leading-relaxed">ดูและแก้ไขข้อมูลส่วนตัว ผู้ติดต่อฉุกเฉิน ครอบครัว การศึกษา และประวัติการทำงาน</p>
</div>

<!-- Profile Tabs — แถวละ 2 ปุ่ม ไอคอนบน ข้อความล่าง (กว้างพอไม่ตกบรรทัด) -->
<nav class="grid grid-cols-2 gap-2 sm:gap-3 mb-4 md:mb-6 min-w-0" aria-label="ส่วนข้อมูลโปรไฟล์">
    <a href="profile.php" class="profile-tab-tile rounded-[20px] min-h-[52px] sm:min-h-[4.5rem] px-2 py-2.5 flex flex-col items-center justify-center gap-1.5 text-center touch-manipulation border border-transparent <?php echo !$action ? 'bg-violet-600 text-white border-violet-500/40' : 'bg-white/10 text-white/80 hover:text-white hover:bg-white/15 border-white/10'; ?> transition-colors">
        <i class="fas fa-user text-2xl opacity-90" aria-hidden="true"></i>
        <span class="text-xs font-semibold leading-tight whitespace-nowrap">ข้อมูลทั่วไป</span>
    </a>
    <a href="profile.php?action=contact" class="profile-tab-tile rounded-[20px] min-h-[52px] sm:min-h-[4.5rem] px-2 py-2.5 flex flex-col items-center justify-center gap-1.5 text-center touch-manipulation border border-transparent <?php echo $action === 'contact' ? 'bg-violet-600 text-white border-violet-500/40' : 'bg-white/10 text-white/80 hover:text-white hover:bg-white/15 border-white/10'; ?> transition-colors">
        <i class="fas fa-phone text-2xl opacity-90" aria-hidden="true"></i>
        <span class="text-xs font-semibold leading-tight whitespace-nowrap">ผู้ติดต่อฉุกเฉิน</span>
    </a>
    <a href="profile.php?action=family" class="profile-tab-tile rounded-[20px] min-h-[52px] sm:min-h-[4.5rem] px-2 py-2.5 flex flex-col items-center justify-center gap-1.5 text-center touch-manipulation border border-transparent <?php echo $action === 'family' ? 'bg-violet-600 text-white border-violet-500/40' : 'bg-white/10 text-white/80 hover:text-white hover:bg-white/15 border-white/10'; ?> transition-colors">
        <i class="fas fa-users text-2xl opacity-90" aria-hidden="true"></i>
        <span class="text-xs font-semibold leading-tight whitespace-nowrap">ครอบครัว</span>
    </a>
    <a href="profile.php?action=education" class="profile-tab-tile rounded-[20px] min-h-[52px] sm:min-h-[4.5rem] px-2 py-2.5 flex flex-col items-center justify-center gap-1.5 text-center touch-manipulation border border-transparent <?php echo $action === 'education' ? 'bg-violet-600 text-white border-violet-500/40' : 'bg-white/10 text-white/80 hover:text-white hover:bg-white/15 border-white/10'; ?> transition-colors">
        <i class="fas fa-graduation-cap text-2xl opacity-90" aria-hidden="true"></i>
        <span class="text-xs font-semibold leading-tight whitespace-nowrap">การศึกษา</span>
    </a>
    <a href="profile.php?action=work" class="profile-tab-tile rounded-[20px] min-h-[52px] sm:min-h-[4.5rem] px-2 py-2.5 flex flex-col items-center justify-center gap-1.5 text-center touch-manipulation col-span-2 max-w-md mx-auto w-full border border-transparent <?php echo $action === 'work' ? 'bg-violet-600 text-white border-violet-500/40' : 'bg-white/10 text-white/80 hover:text-white hover:bg-white/15 border-white/10'; ?> transition-colors">
        <i class="fas fa-briefcase text-2xl opacity-90" aria-hidden="true"></i>
        <span class="text-xs font-semibold leading-tight whitespace-nowrap">ประวัติการทำงาน</span>
    </a>
</nav>

<?php if (!$action): ?>
<!-- General Info -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 md:gap-6 min-w-0 max-w-full">
    <!-- Profile Card -->
    <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
        <div class="text-center mb-6">
            <div class="w-24 h-24 rounded-full bg-violet-600 flex items-center justify-center mx-auto mb-4">
                <?php if (!empty($profile['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($profile['profile_image']); ?>" alt="" class="w-full h-full rounded-full object-cover">
                <?php else: ?>
                <span class="text-3xl text-white font-bold"><?php echo mb_substr($profile['first_name_th'] ?? 'U', 0, 1); ?></span>
                <?php endif; ?>
            </div>
            <h2 class="text-xl font-bold text-white"><?php echo getUserFullName($profile); ?></h2>
            <p class="text-white/60"><?php echo htmlspecialchars($profile['position_name'] ?? $profile['position'] ?? '-'); ?></p>
            <p class="text-violet-400 text-sm mt-1"><?php echo htmlspecialchars($profile['employee_code'] ?? '-'); ?></p>
        </div>
        
        <div class="space-y-3 border-t border-white/10 pt-4">
            <div class="flex items-center gap-3 text-white/70">
                <i class="fas fa-building w-5 text-center"></i>
                <span><?php echo htmlspecialchars($profile['department_name'] ?? $profile['department'] ?? '-'); ?></span>
            </div>
            <div class="flex items-center gap-3 text-white/70">
                <i class="fas fa-envelope w-5 text-center"></i>
                <span><?php echo htmlspecialchars($profile['email'] ?? '-'); ?></span>
            </div>
            <div class="flex items-center gap-3 text-white/70">
                <i class="fas fa-phone w-5 text-center"></i>
                <span><?php echo htmlspecialchars($profile['phone'] ?? '-'); ?></span>
            </div>
            <div class="flex items-center gap-3 text-white/70">
                <i class="fas fa-calendar w-5 text-center"></i>
                <span>เริ่มงาน <?php echo $profile['hire_date'] ? formatDateThai($profile['hire_date']) : '-'; ?></span>
            </div>
            <?php
            $myDaysOff = ['อาทิตย์'];
            $dayNamesFull = THAI_DAY_NAMES;
            try {
                $stmtMySchedule = $pdo->prepare("SELECT day_off FROM hr_employee_schedules WHERE user_id = ?");
                $stmtMySchedule->execute([$user['id']]);
                $mySchedule = $stmtMySchedule->fetch(PDO::FETCH_ASSOC);
                if ($mySchedule && isset($dayNamesFull[(int)$mySchedule['day_off']])) {
                    $myDaysOff = [$dayNamesFull[(int)$mySchedule['day_off']]];
                }
            } catch (Throwable $e) {
                error_log('profile.php hr_employee_schedules: ' . $e->getMessage());
            }
            ?>
            <div class="flex items-center gap-3 text-white/70">
                <i class="fas fa-calendar-minus w-5 text-center text-blue-400"></i>
                <span>วันหยุด: <?php echo implode(', ', $myDaysOff); ?></span>
            </div>
        </div>
        
        <button type="button" onclick="openEditModal('profile')" class="w-full mt-6 min-h-[48px] bg-white/10 hover:bg-white/20 text-white rounded-[20px] transition-colors touch-manipulation font-medium border-0">
            <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไขข้อมูลติดต่อ
        </button>
    </div>
    
    <!-- Personal Info -->
    <div class="xl:col-span-2 space-y-4 md:space-y-6">
        <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
            <h3 class="section-title mb-4 flex flex-wrap items-center gap-2">
                <i class="fas fa-id-card text-violet-400 text-2xl" aria-hidden="true"></i>
                ข้อมูลส่วนตัว
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-white/50 text-sm">ชื่อ-นามสกุล (ไทย)</p>
                    <p class="text-white"><?php echo htmlspecialchars(($profile['first_name_th'] ?? '') . ' ' . ($profile['last_name_th'] ?? '')); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">ชื่อ-นามสกุล (อังกฤษ)</p>
                    <p class="text-white"><?php echo htmlspecialchars(($profile['first_name_en'] ?? '') . ' ' . ($profile['last_name_en'] ?? '')); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">ชื่อเล่น</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['nickname'] ?? '-'); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">เลขบัตรประชาชน</p>
                    <p class="text-white"><?php echo $profile['id_card'] ? substr($profile['id_card'], 0, 4) . '-XXXX-XXXXX-XX-X' : '-'; ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">วันเกิด</p>
                    <p class="text-white"><?php echo $profile['birth_date'] ? formatDateThai($profile['birth_date']) : '-'; ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">เพศ</p>
                    <p class="text-white">
                        <?php 
                        $genders = ['M' => 'ชาย', 'F' => 'หญิง'];
                        echo $genders[$profile['gender'] ?? ''] ?? '-';
                        ?>
                    </p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">สัญชาติ</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['nationality'] ?? 'ไทย'); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">ศาสนา</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['religion'] ?? '-'); ?></p>
                </div>
            </div>
        </div>
        
        <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
            <h3 class="section-title mb-4 flex flex-wrap items-center gap-2">
                <i class="fas fa-briefcase text-emerald-400 text-2xl" aria-hidden="true"></i>
                ข้อมูลการจ้างงาน
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-white/50 text-sm">รหัสพนักงาน</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['employee_code'] ?? '-'); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">ตำแหน่ง</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['position_name'] ?? $profile['position'] ?? '-'); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">แผนก</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['department_name'] ?? $profile['department'] ?? '-'); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">วันที่เริ่มงาน</p>
                    <p class="text-white"><?php echo $profile['hire_date'] ? formatDateThai($profile['hire_date']) : '-'; ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">ประเภทพนักงาน</p>
                    <p class="text-white">
                        <?php 
                        $types = ['PROBATION' => 'ทดลองงาน', 'PERMANENT' => 'ประจำ', 'CONTRACT' => 'สัญญาจ้าง', 'PARTTIME' => 'พาร์ทไทม์'];
                        echo $types[$profile['employment_type'] ?? ''] ?? '-';
                        ?>
                    </p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">สถานะ</p>
                    <p class="text-white">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-[20px] text-xs font-medium border border-white/10 <?php echo ($profile['is_active'] ?? 0) ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                            <?php echo ($profile['is_active'] ?? 0) ? 'ใช้งาน' : 'ไม่ใช้งาน'; ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
            <h3 class="section-title mb-4 flex flex-wrap items-center gap-2">
                <i class="fas fa-map-marker-alt text-sky-400 text-2xl" aria-hidden="true"></i>
                ที่อยู่
            </h3>
            <div class="space-y-4">
                <div>
                    <p class="text-white/50 text-sm">ที่อยู่ปัจจุบัน</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['address'] ?? '-'); ?></p>
                </div>
                <div>
                    <p class="text-white/50 text-sm">ที่อยู่ตามทะเบียนบ้าน</p>
                    <p class="text-white"><?php echo htmlspecialchars($profile['permanent_address'] ?? $profile['address'] ?? '-'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'contact'): ?>
<!-- Emergency Contacts -->
<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h3 class="section-title mb-0 flex flex-wrap items-center gap-2">
            <i class="fas fa-phone-alt text-rose-400 text-2xl" aria-hidden="true"></i>
            ผู้ติดต่อฉุกเฉิน
        </h3>
        <button type="button" onclick="openAddModal('emergency')" class="inline-flex items-center justify-center gap-2 px-4 py-2 min-h-[56px] bg-violet-600 hover:bg-violet-700 text-white rounded-[20px] transition-colors font-semibold touch-manipulation border-0 w-full sm:w-auto">
            <i class="fas fa-plus" aria-hidden="true"></i>เพิ่มผู้ติดต่อ
        </button>
    </div>
    
    <?php if (empty($emergencyContacts)): ?>
    <div class="tp-native-empty-state text-center py-10 rounded-[20px] border border-dashed border-white/15 max-w-none mx-0">
        <i class="fas fa-users text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ยังไม่มีข้อมูลผู้ติดต่อฉุกเฉิน</p>
        <p class="text-slate-500 text-xs mt-2">กรุณาเพิ่มผู้ติดต่อฉุกเฉินอย่างน้อย 1 คน</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($emergencyContacts as $contact): ?>
        <div class="p-4 rounded-[20px] bg-white/5 border border-white/8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-start sm:items-center gap-4 min-w-0">
                <div class="w-12 h-12 rounded-full bg-violet-600/25 flex items-center justify-center shrink-0">
                    <i class="fas fa-user text-violet-400 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-white font-medium break-words"><?php echo htmlspecialchars($contact['name']); ?></p>
                        <?php if ($contact['is_primary']): ?>
                        <span class="px-2 py-0.5 bg-green-500/20 text-green-400 text-xs rounded-[20px] border border-green-500/30">หลัก</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-white/60 text-sm break-words"><?php echo htmlspecialchars($contact['relationship']); ?></p>
                    <p class="text-white/70 break-words"><?php echo htmlspecialchars($contact['phone']); ?></p>
                </div>
            </div>
            <div class="flex gap-2 sm:justify-end">
                <button type="button" onclick="editEmergency(<?php echo $contact['id']; ?>)" class="min-h-[48px] min-w-[48px] p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-[20px] touch-manipulation" aria-label="แก้ไข">
                    <i class="fas fa-edit" aria-hidden="true"></i>
                </button>
                <button type="button" onclick="deleteEmergency(<?php echo $contact['id']; ?>)" class="min-h-[48px] min-w-[48px] p-2 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-[20px] touch-manipulation" aria-label="ลบ">
                    <i class="fas fa-trash" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'family'): ?>
<!-- Family Members -->
<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h3 class="section-title mb-0 flex flex-wrap items-center gap-2">
            <i class="fas fa-users text-amber-400 text-2xl" aria-hidden="true"></i>
            ข้อมูลครอบครัว
        </h3>
        <button type="button" onclick="openAddModal('family')" class="inline-flex items-center justify-center gap-2 px-4 py-2 min-h-[56px] bg-violet-600 hover:bg-violet-700 text-white rounded-[20px] transition-colors font-semibold touch-manipulation border-0 w-full sm:w-auto">
            <i class="fas fa-plus" aria-hidden="true"></i>เพิ่มสมาชิก
        </button>
    </div>
    
    <?php if (empty($familyMembers)): ?>
    <div class="tp-native-empty-state text-center py-10 rounded-[20px] border border-dashed border-white/15 max-w-none mx-0">
        <i class="fas fa-home text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ยังไม่มีข้อมูลครอบครัว</p>
    </div>
    <?php else: ?>
    <div class="md:hidden space-y-3">
        <?php foreach ($familyMembers as $member): ?>
        <?php
        $relations = [
            'FATHER' => 'บิดา', 'MOTHER' => 'มารดา', 'SPOUSE' => 'คู่สมรส',
            'CHILD' => 'บุตร', 'SIBLING' => 'พี่น้อง'
        ];
        $age = $member['birth_date'] ? (new DateTime())->diff(new DateTime($member['birth_date']))->y : '-';
        ?>
        <div class="rounded-[20px] bg-white/5 border border-white/8 p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-white font-medium break-words"><?php echo htmlspecialchars($member['name']); ?></p>
                    <p class="text-white/60 text-sm"><?php echo htmlspecialchars($relations[$member['relationship']] ?? $member['relationship']); ?></p>
                </div>
                <span class="px-2 py-1 rounded bg-white/10 text-white/70 text-xs shrink-0"><?php echo $age; ?> ปี</span>
            </div>
            <p class="text-white/70 text-sm mt-3"><?php echo htmlspecialchars($member['occupation'] ?? '-'); ?></p>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button type="button" onclick="editFamily(<?php echo $member['id']; ?>)" class="min-h-[48px] rounded-[20px] bg-white/10 text-white/70 hover:text-white hover:bg-white/20 touch-manipulation font-medium">
                    <i class="fas fa-edit mr-2" aria-hidden="true"></i>แก้ไข
                </button>
                <button type="button" onclick="deleteFamily(<?php echo $member['id']; ?>)" class="min-h-[48px] rounded-[20px] bg-red-500/15 border border-red-500/25 text-red-300 hover:bg-red-500/25 touch-manipulation font-medium">
                    <i class="fas fa-trash mr-2" aria-hidden="true"></i>ลบ
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="hidden md:block tp-native-table-shell overflow-x-auto min-w-0 -mx-1 px-1">
        <table class="w-full min-w-0">
            <thead class="bg-white/5">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ความสัมพันธ์</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">ชื่อ-นามสกุล</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">อายุ</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-white/60 uppercase">อาชีพ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-white/60 uppercase">การดำเนินการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <?php foreach ($familyMembers as $member): ?>
                <?php
                $relations = [
                    'FATHER' => 'บิดา', 'MOTHER' => 'มารดา', 'SPOUSE' => 'คู่สมรส',
                    'CHILD' => 'บุตร', 'SIBLING' => 'พี่น้อง'
                ];
                $age = $member['birth_date'] ? (new DateTime())->diff(new DateTime($member['birth_date']))->y : '-';
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3 text-white"><?php echo $relations[$member['relationship']] ?? $member['relationship']; ?></td>
                    <td class="px-4 py-3 text-white"><?php echo htmlspecialchars($member['name']); ?></td>
                    <td class="px-4 py-3 text-white/70"><?php echo $age; ?> ปี</td>
                    <td class="px-4 py-3 text-white/70"><?php echo htmlspecialchars($member['occupation'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-center">
                        <button type="button" onclick="editFamily(<?php echo $member['id']; ?>)" class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-[20px] touch-manipulation" aria-label="แก้ไข">
                            <i class="fas fa-edit" aria-hidden="true"></i>
                        </button>
                        <button type="button" onclick="deleteFamily(<?php echo $member['id']; ?>)" class="inline-flex min-h-[48px] min-w-[48px] items-center justify-center p-2 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-[20px] touch-manipulation" aria-label="ลบ">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'education'): ?>
<!-- Education -->
<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h3 class="section-title mb-0 flex flex-wrap items-center gap-2">
            <i class="fas fa-graduation-cap text-blue-400 text-2xl" aria-hidden="true"></i>
            ประวัติการศึกษา
        </h3>
        <button type="button" onclick="openAddModal('education')" class="inline-flex items-center justify-center gap-2 px-4 py-2 min-h-[56px] bg-violet-600 hover:bg-violet-700 text-white rounded-[20px] transition-colors font-semibold touch-manipulation border-0 w-full sm:w-auto">
            <i class="fas fa-plus" aria-hidden="true"></i>เพิ่มประวัติ
        </button>
    </div>
    
    <?php if (empty($educations)): ?>
    <div class="tp-native-empty-state text-center py-10 rounded-[20px] border border-dashed border-white/15 max-w-none mx-0">
        <i class="fas fa-graduation-cap text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ยังไม่มีข้อมูลการศึกษา</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($educations as $edu): ?>
        <div class="p-4 rounded-[20px] bg-white/5 border border-white/8 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="flex gap-4 min-w-0">
                <div class="w-12 h-12 rounded-[20px] bg-blue-500/20 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-graduation-cap text-blue-400 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-white font-medium break-words"><?php echo htmlspecialchars($edu['degree']); ?></p>
                    <p class="text-white/70 break-words"><?php echo htmlspecialchars($edu['institution']); ?></p>
                    <p class="text-white/50 text-sm">
                        <?php echo htmlspecialchars($edu['field_of_study'] ?? ''); ?>
                        <?php if ($edu['graduation_year']): ?>
                        | จบปี <?php echo $edu['graduation_year'] + 543; ?>
                        <?php endif; ?>
                        <?php if ($edu['gpa']): ?>
                        | GPA <?php echo number_format($edu['gpa'], 2); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="editEducation(<?php echo $edu['id']; ?>)" class="min-h-[48px] min-w-[48px] p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-[20px] touch-manipulation" aria-label="แก้ไข">
                    <i class="fas fa-edit" aria-hidden="true"></i>
                </button>
                <button type="button" onclick="deleteEducation(<?php echo $edu['id']; ?>)" class="min-h-[48px] min-w-[48px] p-2 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-[20px] touch-manipulation" aria-label="ลบ">
                    <i class="fas fa-trash" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'work'): ?>
<!-- Work History -->
<div class="native-card tp-native-card tp-native-data-card p-4 sm:p-6 min-w-0">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h3 class="section-title mb-0 flex flex-wrap items-center gap-2">
            <i class="fas fa-briefcase text-emerald-400 text-2xl" aria-hidden="true"></i>
            ประวัติการทำงาน (ก่อนเข้าบริษัท)
        </h3>
        <button type="button" onclick="openAddModal('work')" class="inline-flex items-center justify-center gap-2 px-4 py-2 min-h-[56px] bg-violet-600 hover:bg-violet-700 text-white rounded-[20px] transition-colors font-semibold touch-manipulation border-0 w-full sm:w-auto">
            <i class="fas fa-plus" aria-hidden="true"></i>เพิ่มประวัติ
        </button>
    </div>
    
    <?php if (empty($workHistory)): ?>
    <div class="tp-native-empty-state text-center py-10 rounded-[20px] border border-dashed border-white/15 max-w-none mx-0">
        <i class="fas fa-briefcase text-slate-500 text-4xl mb-3 block" aria-hidden="true"></i>
        <p class="text-slate-400 text-sm">ยังไม่มีข้อมูลประวัติการทำงาน</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($workHistory as $work): ?>
        <div class="p-4 rounded-[20px] bg-white/5 border border-white/8 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="flex gap-4 min-w-0">
                <div class="w-12 h-12 rounded-[20px] bg-emerald-500/20 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-building text-emerald-400 text-xl" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-white font-medium break-words"><?php echo htmlspecialchars($work['position']); ?></p>
                    <p class="text-white/70 break-words"><?php echo htmlspecialchars($work['company_name']); ?></p>
                    <p class="text-white/50 text-sm">
                        <?php echo formatDateThai($work['start_date']); ?>
                        - <?php echo $work['end_date'] ? formatDateThai($work['end_date']) : 'ปัจจุบัน'; ?>
                    </p>
                    <?php if ($work['responsibilities']): ?>
                    <p class="text-white/60 text-sm mt-1"><?php echo htmlspecialchars($work['responsibilities']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="editWork(<?php echo $work['id']; ?>)" class="min-h-[48px] min-w-[48px] p-2 text-white/60 hover:text-white hover:bg-white/10 rounded-[20px] touch-manipulation" aria-label="แก้ไข">
                    <i class="fas fa-edit" aria-hidden="true"></i>
                </button>
                <button type="button" onclick="deleteWork(<?php echo $work['id']; ?>)" class="min-h-[48px] min-w-[48px] p-2 text-red-400 hover:text-red-300 hover:bg-red-500/10 rounded-[20px] touch-manipulation" aria-label="ลบ">
                    <i class="fas fa-trash" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Edit Contact Modal -->
<div id="edit-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden flex items-center justify-center p-4 overflow-y-auto overscroll-contain">
    <div class="native-card tp-native-card w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden pb-[calc(env(safe-area-inset-bottom,0px)+1rem)]">
        <form id="edit-form" class="p-6">
            <div class="flex items-center justify-between mb-6 gap-2">
                <h3 class="text-xl font-bold text-white" id="modal-title">แก้ไขข้อมูล</h3>
                <button type="button" onclick="closeModal()" class="min-h-[48px] min-w-[48px] inline-flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 rounded-[20px] touch-manipulation" aria-label="ปิด">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div id="modal-content">
                <!-- Dynamic content -->
            </div>
            <div class="flex flex-col sm:flex-row gap-3 mt-6">
                <button type="button" onclick="closeModal()" class="flex-1 min-h-[52px] py-3 bg-white/10 hover:bg-white/20 text-white rounded-[20px] transition-colors font-semibold border-0 touch-manipulation">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 min-h-[56px] py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-[20px] transition-colors font-semibold border-0 touch-manipulation">
                    บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(type) {
    const modal = document.getElementById('edit-modal');
    const title = document.getElementById('modal-title');
    const content = document.getElementById('modal-content');
    
    if (type === 'profile') {
        title.textContent = 'แก้ไขข้อมูลติดต่อ';
        content.innerHTML = `
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_contact">
            <div class="space-y-4">
                <div>
                    <label class="block text-white/80 text-sm mb-2">อีเมล</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>" class="input-field">
                </div>
                <div>
                    <label class="block text-white/80 text-sm mb-2">เบอร์โทรศัพท์</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>" class="input-field">
                </div>
                <div>
                    <label class="block text-white/80 text-sm mb-2">ที่อยู่ปัจจุบัน</label>
                    <textarea name="address" rows="3" class="input-field"><?php echo htmlspecialchars($profile['address'] ?? ''); ?></textarea>
                </div>
            </div>
        `;
    }
    
    if (typeof uiOpenModal === 'function') uiOpenModal('edit-modal');
    else modal.classList.remove('hidden');
}

function openAddModal(type) {
    const modal = document.getElementById('edit-modal');
    const title = document.getElementById('modal-title');
    const content = document.getElementById('modal-content');
    
    const templates = {
        emergency: {
            title: 'เพิ่มผู้ติดต่อฉุกเฉิน',
            content: `
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_emergency">
                <div class="space-y-4">
                    <div>
                        <label class="block text-white/80 text-sm mb-2">ชื่อ-นามสกุล *</label>
                        <input type="text" name="name" required class="input-field">
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">ความสัมพันธ์ *</label>
                        <select name="relationship" required class="input-field">
                            <option value="">-- เลือก --</option>
                            <option value="บิดา">บิดา</option>
                            <option value="มารดา">มารดา</option>
                            <option value="คู่สมรส">คู่สมรส</option>
                            <option value="บุตร">บุตร</option>
                            <option value="พี่น้อง">พี่น้อง</option>
                            <option value="ญาติ">ญาติ</option>
                            <option value="เพื่อน">เพื่อน</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">เบอร์โทรศัพท์ *</label>
                        <input type="tel" name="phone" required class="input-field">
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_primary" value="1" class="mr-2 accent-violet-500">
                            <span class="text-white">ผู้ติดต่อหลัก</span>
                        </label>
                    </div>
                </div>
            `
        },
        family: {
            title: 'เพิ่มสมาชิกครอบครัว',
            content: `
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_family">
                <div class="space-y-4">
                    <div>
                        <label class="block text-white/80 text-sm mb-2">ความสัมพันธ์ *</label>
                        <select name="relationship" required class="input-field">
                            <option value="">-- เลือก --</option>
                            <option value="FATHER">บิดา</option>
                            <option value="MOTHER">มารดา</option>
                            <option value="SPOUSE">คู่สมรส</option>
                            <option value="CHILD">บุตร</option>
                            <option value="SIBLING">พี่น้อง</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">ชื่อ-นามสกุล *</label>
                        <input type="text" name="name" required class="input-field">
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">วันเกิด</label>
                        <input type="date" name="birth_date" class="input-field">
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">อาชีพ</label>
                        <input type="text" name="occupation" class="input-field">
                    </div>
                </div>
            `
        },
        education: {
            title: 'เพิ่มประวัติการศึกษา',
            content: `
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_education">
                <div class="space-y-4">
                    <div>
                        <label class="block text-white/80 text-sm mb-2">ระดับการศึกษา *</label>
                        <select name="degree" required class="input-field">
                            <option value="">-- เลือก --</option>
                            <option value="มัธยมศึกษาตอนต้น">มัธยมศึกษาตอนต้น</option>
                            <option value="มัธยมศึกษาตอนปลาย">มัธยมศึกษาตอนปลาย</option>
                            <option value="ปวช.">ปวช.</option>
                            <option value="ปวส.">ปวส.</option>
                            <option value="ปริญญาตรี">ปริญญาตรี</option>
                            <option value="ปริญญาโท">ปริญญาโท</option>
                            <option value="ปริญญาเอก">ปริญญาเอก</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">สถาบันการศึกษา *</label>
                        <input type="text" name="institution" required class="input-field">
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">สาขาวิชา</label>
                        <input type="text" name="field_of_study" class="input-field">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-white/80 text-sm mb-2">ปีที่จบ (พ.ศ.)</label>
                            <input type="number" name="graduation_year" class="input-field" min="2500" max="2600">
                        </div>
                        <div>
                            <label class="block text-white/80 text-sm mb-2">เกรดเฉลี่ย</label>
                            <input type="number" name="gpa" step="0.01" min="0" max="4" class="input-field">
                        </div>
                    </div>
                </div>
            `
        },
        work: {
            title: 'เพิ่มประวัติการทำงาน',
            content: `
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_work">
                <div class="space-y-4">
                    <div>
                        <label class="block text-white/80 text-sm mb-2">บริษัท/องค์กร *</label>
                        <input type="text" name="company_name" required class="input-field">
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">ตำแหน่ง *</label>
                        <input type="text" name="position" required class="input-field">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-white/80 text-sm mb-2">วันที่เริ่มงาน *</label>
                            <input type="date" name="start_date" required class="input-field">
                        </div>
                        <div>
                            <label class="block text-white/80 text-sm mb-2">วันที่สิ้นสุด</label>
                            <input type="date" name="end_date" class="input-field">
                        </div>
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">หน้าที่ความรับผิดชอบ</label>
                        <textarea name="responsibilities" rows="3" class="input-field"></textarea>
                    </div>
                    <div>
                        <label class="block text-white/80 text-sm mb-2">เหตุผลที่ลาออก</label>
                        <input type="text" name="leaving_reason" class="input-field">
                    </div>
                </div>
            `
        }
    };
    
    if (templates[type]) {
        title.textContent = templates[type].title;
        content.innerHTML = templates[type].content;
        if (typeof uiOpenModal === 'function') uiOpenModal('edit-modal');
        else modal.classList.remove('hidden');
    }
}

function closeModal() {
    if (typeof uiCloseModal === 'function') uiCloseModal('edit-modal');
    else document.getElementById('edit-modal').classList.add('hidden');
}

// Form submission
document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    try {
        const response = await fetch('/api/profile.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('บันทึกข้อมูลสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'บันทึก';
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'บันทึก';
    }
});

// Delete functions
async function deleteEmergency(id) {
    if (!confirm('ต้องการลบผู้ติดต่อนี้?')) return;
    await deleteRecord('delete_emergency', id);
}

async function deleteFamily(id) {
    if (!confirm('ต้องการลบข้อมูลนี้?')) return;
    await deleteRecord('delete_family', id);
}

async function deleteEducation(id) {
    if (!confirm('ต้องการลบข้อมูลนี้?')) return;
    await deleteRecord('delete_education', id);
}

async function deleteWork(id) {
    if (!confirm('ต้องการลบข้อมูลนี้?')) return;
    await deleteRecord('delete_work', id);
}

async function deleteRecord(action, id) {
    try {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('id', id);
        formData.append('_token', '<?php echo csrfToken(); ?>');
        
        const response = await fetch('/api/profile.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('ลบข้อมูลสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// Close modal on click outside
document.getElementById('edit-modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include 'templates/footer.php'; ?>
