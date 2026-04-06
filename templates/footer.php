<?php
/**
 * TP-HR Footer Template
 */
?>
</div><!-- End content-area -->

<!-- Mobile Menu Overlay -->
<div id="mobileMenuOverlay" class="lg:hidden fixed inset-0 bg-black/50 z-40 hidden"></div>

<!-- Mobile Menu -->
<div id="mobileMenu" class="lg:hidden fixed left-0 top-0 w-[280px] h-screen bg-[#1a1a2e] z-50 transform -translate-x-full transition-transform">
    <div class="p-6">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-violet-600 flex items-center justify-center">
                    <i class="fas fa-users text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">TP-HR</h1>
                </div>
            </div>
            <button id="closeMobileMenu" class="text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Mobile Navigation -->
        <nav class="space-y-2">
            <a href="/tp-hr/" class="nav-link">
                <i class="fas fa-home w-5"></i>
                <span>หน้าแรก</span>
            </a>
            
            <a href="/tp-hr/checkin.php" class="nav-link">
                <i class="fas fa-fingerprint w-5"></i>
                <span>ลงเวลาเข้า-ออก</span>
            </a>
            
            <a href="/tp-hr/leave.php" class="nav-link">
                <i class="fas fa-calendar-alt w-5"></i>
                <span>การลา</span>
            </a>
            
            <a href="/tp-hr/payslip.php" class="nav-link">
                <i class="fas fa-file-invoice-dollar w-5"></i>
                <span>สลิปเงินเดือน</span>
            </a>
            
            <a href="/tp-hr/document.php" class="nav-link">
                <i class="fas fa-file-certificate w-5"></i>
                <span>ขอใบรับรอง</span>
            </a>
            
            <a href="/tp-hr/profile.php" class="nav-link">
                <i class="fas fa-user w-5"></i>
                <span>ข้อมูลส่วนตัว</span>
            </a>
            
            <div class="pt-4 mt-4 border-t border-white/10">
                <a href="/tp-hr/logout.php" class="nav-link text-red-400">
                    <i class="fas fa-sign-out-alt w-5"></i>
                    <span>ออกจากระบบ</span>
                </a>
            </div>
        </nav>
    </div>
</div>

<script>
// Mobile Menu Toggle
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileMenu = document.getElementById('mobileMenu');
const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
const closeMobileMenu = document.getElementById('closeMobileMenu');

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', () => {
        mobileMenu.classList.remove('-translate-x-full');
        mobileMenuOverlay.classList.remove('hidden');
    });
}

if (closeMobileMenu) {
    closeMobileMenu.addEventListener('click', () => {
        mobileMenu.classList.add('-translate-x-full');
        mobileMenuOverlay.classList.add('hidden');
    });
}

if (mobileMenuOverlay) {
    mobileMenuOverlay.addEventListener('click', () => {
        mobileMenu.classList.add('-translate-x-full');
        mobileMenuOverlay.classList.add('hidden');
    });
}

// Flash Messages
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    const colors = {
        success: 'bg-green-600',
        error: 'bg-red-600',
        warning: 'bg-yellow-600',
        info: 'bg-blue-600'
    };
    
    toast.className = `fixed bottom-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in`;
    toast.innerHTML = `
        <div class="flex items-center gap-3">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// CSRF Token helper
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

// Format number with commas
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Confirm dialog
async function confirmDialog(message, title = 'ยืนยัน') {
    return new Promise((resolve) => {
        const confirmed = confirm(message);
        resolve(confirmed);
    });
}
</script>

<?php
// Flash messages
if ($msg = flash('success')): ?>
<script>showToast('<?php echo addslashes($msg); ?>', 'success');</script>
<?php endif; ?>

<?php if ($msg = flash('error')): ?>
<script>showToast('<?php echo addslashes($msg); ?>', 'error');</script>
<?php endif; ?>

</body>
</html>
