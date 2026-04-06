<?php
/**
 * TP-HR Check-in/Check-out
 * ลงเวลาเข้า-ออกงาน
 */

require_once __DIR__ . '/bootstrap.php';
Auth::requireLogin();

$pdo = getDB();
$user = Auth::user();
$page_title = 'ลงเวลาเข้า-ออก';
$current_page = 'checkin';

$action = $_GET['action'] ?? '';
$message = '';
$error = '';

// Get today's attendance
$stmt = $pdo->prepare("SELECT * FROM hr_attendances WHERE user_id = ? AND attendance_date = CURDATE()");
$stmt->execute([$user['id']]);
$today_attendance = $stmt->fetch();

// Get user's shift
$stmt = $pdo->prepare("SELECT * FROM hr_work_shifts WHERE is_default = 1 AND is_active = 1 LIMIT 1");
$stmt->execute();
$shift = $stmt->fetch();

// Get allowed check-in locations
$stmt = $pdo->query("SELECT * FROM hr_checkin_locations WHERE is_active = 1");
$locations = $stmt->fetchAll();

// Get attendance history (last 7 days)
$stmt = $pdo->prepare("
    SELECT a.*, s.name as shift_name 
    FROM hr_attendances a
    LEFT JOIN hr_work_shifts s ON a.shift_id = s.id
    WHERE a.user_id = ? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY a.attendance_date DESC
");
$stmt->execute([$user['id']]);
$attendance_history = $stmt->fetchAll();

// Calculate this month's summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status IN ('PRESENT', 'LATE') THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'LATE' THEN 1 END) as late_days,
        COUNT(CASE WHEN status = 'ABSENT' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'LEAVE' THEN 1 END) as leave_days,
        SUM(COALESCE(work_minutes, 0)) as total_work_minutes,
        SUM(COALESCE(ot_minutes, 0)) as total_ot_minutes,
        SUM(COALESCE(late_minutes, 0)) as total_late_minutes
    FROM hr_attendances 
    WHERE user_id = ? AND MONTH(attendance_date) = MONTH(CURDATE()) AND YEAR(attendance_date) = YEAR(CURDATE())
");
$stmt->execute([$user['id']]);
$monthly_summary = $stmt->fetch();

require_once __DIR__ . '/templates/header.php';
?>

<main class="content-area p-6">
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">ลงเวลาเข้า-ออกงาน</h1>
        <p class="text-white/60 mt-1"><?php echo formatDateThai(date('Y-m-d')); ?></p>
    </div>
    
    <?php if ($error): ?>
    <div class="bg-red-500/20 border border-red-500/50 text-red-300 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
    <div class="bg-green-500/20 border border-green-500/50 text-green-300 px-4 py-3 rounded-lg mb-6">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Check-in Card -->
        <div class="lg:col-span-2">
            <div class="glass-card rounded-xl p-6">
                <!-- Current Time Display -->
                <div class="text-center mb-8">
                    <div class="text-6xl font-bold text-white mb-2" id="current-time">--:--:--</div>
                    <p class="text-white/60"><?php echo formatDateThai(date('Y-m-d')); ?></p>
                    
                    <?php if ($shift): ?>
                    <div class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/10">
                        <i class="fas fa-clock text-violet-400"></i>
                        <span class="text-white"><?php echo htmlspecialchars($shift['name']); ?></span>
                        <span class="text-white/60">
                            (<?php echo substr($shift['start_time'], 0, 5); ?> - <?php echo substr($shift['end_time'], 0, 5); ?>)
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Today's Status -->
                <div class="grid grid-cols-2 gap-4 mb-8">
                    <div class="p-4 rounded-lg bg-white/5 text-center">
                        <p class="text-white/60 text-sm mb-1">เวลาเข้างาน</p>
                        <p class="text-2xl font-bold <?php echo $today_attendance && $today_attendance['check_in_time'] ? 'text-green-400' : 'text-white/30'; ?>">
                            <?php 
                            if ($today_attendance && $today_attendance['check_in_time']) {
                                echo date('H:i', strtotime($today_attendance['check_in_time']));
                            } else {
                                echo '--:--';
                            }
                            ?>
                        </p>
                        <?php if ($today_attendance && $today_attendance['late_minutes'] > 0): ?>
                        <p class="text-red-400 text-sm mt-1">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            สาย <?php echo $today_attendance['late_minutes']; ?> นาที
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="p-4 rounded-lg bg-white/5 text-center">
                        <p class="text-white/60 text-sm mb-1">เวลาออกงาน</p>
                        <p class="text-2xl font-bold <?php echo $today_attendance && $today_attendance['check_out_time'] ? 'text-blue-400' : 'text-white/30'; ?>">
                            <?php 
                            if ($today_attendance && $today_attendance['check_out_time']) {
                                echo date('H:i', strtotime($today_attendance['check_out_time']));
                            } else {
                                echo '--:--';
                            }
                            ?>
                        </p>
                        <?php if ($today_attendance && $today_attendance['work_minutes'] > 0): ?>
                        <p class="text-white/60 text-sm mt-1">
                            ทำงาน <?php echo floor($today_attendance['work_minutes'] / 60); ?> ชม. <?php echo $today_attendance['work_minutes'] % 60; ?> น.
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Check-in/Check-out Buttons -->
                <div class="flex flex-col items-center gap-4">
                    <?php if (!$today_attendance || !$today_attendance['check_in_time']): ?>
                        <!-- Check-in Button -->
                        <button id="btn-checkin" 
                                onclick="startCheckin('in')"
                                class="w-48 h-48 rounded-full bg-gradient-to-br from-green-500 to-green-600 hover:from-green-400 hover:to-green-500 text-white shadow-lg shadow-green-500/30 transition-all hover:scale-105 flex flex-col items-center justify-center">
                            <i class="fas fa-fingerprint text-5xl mb-2"></i>
                            <span class="text-xl font-bold">ลงเวลาเข้า</span>
                        </button>
                        
                    <?php elseif (!$today_attendance['check_out_time']): ?>
                        <!-- Check-out Button -->
                        <button id="btn-checkout"
                                onclick="startCheckin('out')"
                                class="w-48 h-48 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 hover:from-blue-400 hover:to-blue-500 text-white shadow-lg shadow-blue-500/30 transition-all hover:scale-105 flex flex-col items-center justify-center">
                            <i class="fas fa-sign-out-alt text-5xl mb-2"></i>
                            <span class="text-xl font-bold">ลงเวลาออก</span>
                        </button>
                        
                    <?php else: ?>
                        <!-- All Done -->
                        <div class="w-48 h-48 rounded-full bg-gradient-to-br from-gray-600 to-gray-700 text-white flex flex-col items-center justify-center">
                            <i class="fas fa-check-circle text-5xl mb-2 text-green-400"></i>
                            <span class="text-lg font-medium">ลงเวลาครบแล้ว</span>
                        </div>
                    <?php endif; ?>
                    
                    <p class="text-white/50 text-sm" id="location-status">
                        <i class="fas fa-location-arrow mr-1"></i>
                        กำลังตรวจสอบตำแหน่ง...
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Summary & History -->
        <div class="space-y-6">
            <!-- Monthly Summary -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-chart-pie text-violet-400 mr-2"></i>
                    สรุปเดือนนี้
                </h2>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">มาทำงาน</span>
                        <span class="text-green-400 font-medium"><?php echo $monthly_summary['present_days'] ?? 0; ?> วัน</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">มาสาย</span>
                        <span class="text-yellow-400 font-medium"><?php echo $monthly_summary['late_days'] ?? 0; ?> ครั้ง</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">ลา</span>
                        <span class="text-blue-400 font-medium"><?php echo $monthly_summary['leave_days'] ?? 0; ?> วัน</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-white/70">ขาดงาน</span>
                        <span class="text-red-400 font-medium"><?php echo $monthly_summary['absent_days'] ?? 0; ?> วัน</span>
                    </div>
                    
                    <div class="pt-3 mt-3 border-t border-white/10">
                        <div class="flex justify-between items-center">
                            <span class="text-white/70">ชั่วโมงทำงาน</span>
                            <span class="text-white font-medium">
                                <?php 
                                $totalMinutes = $monthly_summary['total_work_minutes'] ?? 0;
                                echo floor($totalMinutes / 60) . ' ชม.';
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-white/70">OT</span>
                            <span class="text-emerald-400 font-medium">
                                <?php 
                                $otMinutes = $monthly_summary['total_ot_minutes'] ?? 0;
                                echo floor($otMinutes / 60) . ' ชม. ' . ($otMinutes % 60) . ' น.';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent History -->
            <div class="glass-card rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-history text-blue-400 mr-2"></i>
                    ประวัติ 7 วันล่าสุด
                </h2>
                
                <?php if ($attendance_history): ?>
                <div class="space-y-2">
                    <?php foreach ($attendance_history as $att): ?>
                    <div class="p-3 rounded-lg bg-white/5 flex items-center justify-between">
                        <div>
                            <p class="text-white text-sm">
                                <?php echo formatDateThai($att['attendance_date']); ?>
                            </p>
                            <p class="text-white/50 text-xs">
                                <?php 
                                if ($att['check_in_time']) {
                                    echo date('H:i', strtotime($att['check_in_time']));
                                } else {
                                    echo '--:--';
                                }
                                echo ' - ';
                                if ($att['check_out_time']) {
                                    echo date('H:i', strtotime($att['check_out_time']));
                                } else {
                                    echo '--:--';
                                }
                                ?>
                            </p>
                        </div>
                        <span class="px-2 py-1 text-xs rounded <?php 
                            echo match($att['status']) {
                                'PRESENT' => 'bg-green-500/20 text-green-400',
                                'LATE' => 'bg-yellow-500/20 text-yellow-400',
                                'ABSENT' => 'bg-red-500/20 text-red-400',
                                'LEAVE' => 'bg-blue-500/20 text-blue-400',
                                'HOLIDAY' => 'bg-gray-500/20 text-gray-400',
                                default => 'bg-gray-500/20 text-gray-400'
                            };
                        ?>">
                            <?php echo ATTENDANCE_STATUS[$att['status']] ?? $att['status']; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-white/50 text-center py-4">ยังไม่มีประวัติ</p>
                <?php endif; ?>
                
                <a href="attendance_history.php" class="block text-center text-violet-400 hover:text-violet-300 text-sm mt-4">
                    ดูประวัติทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>
</main>

<!-- Check-in Modal -->
<div id="checkin-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="glass-card rounded-2xl p-6 w-full max-w-md">
        <div class="text-center mb-6">
            <div class="w-20 h-20 rounded-full bg-violet-600/20 flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-camera text-violet-400 text-3xl" id="modal-icon"></i>
            </div>
            <h3 class="text-xl font-bold text-white" id="modal-title">ลงเวลาเข้างาน</h3>
            <p class="text-white/60 text-sm mt-1" id="modal-subtitle">ถ่ายรูปเพื่อยืนยันตัวตน</p>
        </div>
        
        <!-- Camera Preview -->
        <div class="relative mb-6 rounded-xl overflow-hidden bg-black aspect-video" id="camera-container">
            <video id="camera-preview" class="w-full h-full object-cover" autoplay playsinline></video>
            <canvas id="camera-canvas" class="hidden"></canvas>
            <img id="captured-photo" class="w-full h-full object-cover hidden">
            
            <div id="camera-loading" class="absolute inset-0 flex items-center justify-center bg-black/80">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin text-white text-2xl mb-2"></i>
                    <p class="text-white/60 text-sm">กำลังเปิดกล้อง...</p>
                </div>
            </div>
        </div>
        
        <!-- Location Info -->
        <div class="p-3 rounded-lg bg-white/5 mb-6" id="location-info">
            <div class="flex items-center gap-2 text-white/70">
                <i class="fas fa-map-marker-alt text-red-400"></i>
                <span id="location-text">กำลังระบุตำแหน่ง...</span>
            </div>
            <input type="hidden" id="latitude" value="">
            <input type="hidden" id="longitude" value="">
        </div>
        
        <!-- Buttons -->
        <div class="flex gap-3">
            <button type="button" onclick="closeCheckinModal()" class="flex-1 py-3 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors">
                ยกเลิก
            </button>
            <button type="button" id="btn-capture" onclick="capturePhoto()" class="flex-1 py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition-colors">
                <i class="fas fa-camera mr-2"></i>ถ่ายรูป
            </button>
            <button type="button" id="btn-confirm" onclick="confirmCheckin()" class="flex-1 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors hidden">
                <i class="fas fa-check mr-2"></i>ยืนยัน
            </button>
        </div>
    </div>
</div>

<script>
// Current time display
function updateClock() {
    const now = new Date();
    const time = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    document.getElementById('current-time').textContent = time;
}
setInterval(updateClock, 1000);
updateClock();

// Variables
let checkinType = '';
let stream = null;
let photoData = null;
let userLatitude = null;
let userLongitude = null;

// Get user location on page load
navigator.geolocation.getCurrentPosition(
    (position) => {
        userLatitude = position.coords.latitude;
        userLongitude = position.coords.longitude;
        document.getElementById('location-status').innerHTML = '<i class="fas fa-check-circle text-green-400 mr-1"></i> พร้อมลงเวลา';
    },
    (error) => {
        document.getElementById('location-status').innerHTML = '<i class="fas fa-exclamation-triangle text-yellow-400 mr-1"></i> ไม่สามารถระบุตำแหน่งได้';
    },
    { enableHighAccuracy: true }
);

// Start check-in process
function startCheckin(type) {
    checkinType = type;
    
    // Update modal content
    const modal = document.getElementById('checkin-modal');
    const title = document.getElementById('modal-title');
    const icon = document.getElementById('modal-icon');
    
    if (type === 'in') {
        title.textContent = 'ลงเวลาเข้างาน';
        icon.className = 'fas fa-sign-in-alt text-green-400 text-3xl';
    } else {
        title.textContent = 'ลงเวลาออกงาน';
        icon.className = 'fas fa-sign-out-alt text-blue-400 text-3xl';
    }
    
    modal.classList.remove('hidden');
    startCamera();
    getLocation();
}

// Close modal
function closeCheckinModal() {
    const modal = document.getElementById('checkin-modal');
    modal.classList.add('hidden');
    stopCamera();
    resetModal();
}

// Reset modal state
function resetModal() {
    photoData = null;
    document.getElementById('captured-photo').classList.add('hidden');
    document.getElementById('camera-preview').classList.remove('hidden');
    document.getElementById('btn-capture').classList.remove('hidden');
    document.getElementById('btn-confirm').classList.add('hidden');
}

// Start camera
async function startCamera() {
    const video = document.getElementById('camera-preview');
    const loading = document.getElementById('camera-loading');
    
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        video.srcObject = stream;
        loading.style.display = 'none';
    } catch (err) {
        console.error('Camera error:', err);
        loading.innerHTML = '<div class="text-center"><i class="fas fa-exclamation-circle text-red-400 text-2xl mb-2"></i><p class="text-white/60 text-sm">ไม่สามารถเปิดกล้องได้</p></div>';
    }
}

// Stop camera
function stopCamera() {
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
}

// Get location
function getLocation() {
    const locationText = document.getElementById('location-text');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    
    navigator.geolocation.getCurrentPosition(
        (position) => {
            userLatitude = position.coords.latitude;
            userLongitude = position.coords.longitude;
            latInput.value = userLatitude;
            lngInput.value = userLongitude;
            locationText.innerHTML = `<span class="text-green-400"><i class="fas fa-check-circle mr-1"></i>ระบุตำแหน่งสำเร็จ</span>`;
        },
        (error) => {
            locationText.innerHTML = `<span class="text-yellow-400"><i class="fas fa-exclamation-triangle mr-1"></i>ไม่สามารถระบุตำแหน่งได้</span>`;
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

// Capture photo
function capturePhoto() {
    const video = document.getElementById('camera-preview');
    const canvas = document.getElementById('camera-canvas');
    const photo = document.getElementById('captured-photo');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);
    
    // Add timestamp overlay
    ctx.fillStyle = 'rgba(0,0,0,0.5)';
    ctx.fillRect(0, canvas.height - 40, canvas.width, 40);
    ctx.fillStyle = 'white';
    ctx.font = '16px Sarabun';
    ctx.fillText(new Date().toLocaleString('th-TH'), 10, canvas.height - 15);
    
    photoData = canvas.toDataURL('image/jpeg', 0.8);
    photo.src = photoData;
    
    // Show photo, hide video
    video.classList.add('hidden');
    photo.classList.remove('hidden');
    
    // Switch buttons
    document.getElementById('btn-capture').classList.add('hidden');
    document.getElementById('btn-confirm').classList.remove('hidden');
    
    stopCamera();
}

// Confirm check-in
async function confirmCheckin() {
    const btn = document.getElementById('btn-confirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    try {
        const response = await fetch('/api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: checkinType === 'in' ? 'check_in' : 'check_out',
                latitude: userLatitude,
                longitude: userLongitude,
                photo: photoData
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeCheckinModal();
            showToast(checkinType === 'in' ? 'ลงเวลาเข้างานสำเร็จ' : 'ลงเวลาออกงานสำเร็จ', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check mr-2"></i>ยืนยัน';
        }
    } catch (err) {
        console.error('Check-in error:', err);
        showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-2"></i>ยืนยัน';
    }
}
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
