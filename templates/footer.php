<?php
/**
 * TP-HR Footer Template
 */
?>

</div><!-- End tp-native-stack--page -->
</main><!-- End main content-area / tp-native-page -->

<?php
$cp = $current_page ?? '';
$isAdminPage = is_string($cp) && strncmp($cp, 'hr-', 3) === 0;
?>

<?php if (!$isAdminPage): ?>
<!-- Mobile Bottom Navigation (employee-first) — visuals locked in assets/css/native-shell.css v14 -->
<nav id="tpHrMobileBottomTab"
     class="app-shell-mobile-only tp-native-bottom-tab-nav fixed bottom-0 left-0 right-0 touch-manipulation overscroll-contain transition-opacity transition-transform duration-200 ease-out"
     role="navigation"
     aria-label="เมนูหลักมือถือ">
    <?php
    /* Solid FA icons with similar silhouette weight — mobile readability first */
    $items = [
        ['key' => 'dashboard', 'href' => '/',               'icon' => 'fa-house',                 'label' => 'หน้าแรก', 'aria' => 'หน้าแรก'],
        ['key' => 'checkin',   'href' => '/checkin.php',   'icon' => 'fa-fingerprint',          'label' => 'ลงเวลา', 'aria' => 'ลงเวลาเข้า-ออกงาน'],
        ['key' => 'leave',     'href' => '/leave.php',     'icon' => 'fa-calendar-days',         'label' => 'ลา', 'aria' => 'การลา'],
        ['key' => 'payslip',   'href' => '/payslip.php',   'icon' => 'fa-file-invoice-dollar',   'label' => 'สลิป', 'aria' => 'สลิปเงินเดือน'],
        ['key' => 'profile',   'href' => '/profile.php',   'icon' => 'fa-circle-user',          'label' => 'ฉัน', 'aria' => 'โปรไฟล์และตั้งค่า'],
    ];
    ?>
    <div class="tp-native-bottom-tab-strip">
        <?php foreach ($items as $it):
            $isHere = ($cp === $it['key']);
            ?>
        <a href="<?php echo htmlspecialchars($it['href']); ?>"
           class="tp-native-bottom-tab-link"
           aria-label="<?php echo htmlspecialchars($it['aria']); ?>"
           <?php if ($isHere): ?>aria-current="page"<?php endif; ?>>
            <i class="fas <?php echo htmlspecialchars($it['icon']); ?> fa-fw tp-native-bottom-tab-ic" aria-hidden="true"></i>
            <span class="tp-native-bottom-tab-label"><?php echo htmlspecialchars($it['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>

<!-- Toast Notification -->
<div id="toast" class="fixed left-4 right-4 z-50 hidden bottom-[calc(6rem+env(safe-area-inset-bottom,0px))]">
    <div class="toast-panel bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-xl shadow-xl p-4 flex items-center gap-3 w-full">
        <div id="toastIcon" class="w-8 h-8 rounded-lg flex items-center justify-center"></div>
        <div class="flex-1">
            <p id="toastTitle" class="text-white font-medium"></p>
            <p id="toastMessage" class="text-slate-400 text-sm"></p>
        </div>
        <button type="button" onclick="hideToast()" aria-label="ปิดการแจ้งเตือน" class="touch-manipulation inline-flex min-h-[48px] min-w-[48px] shrink-0 items-center justify-center rounded-lg text-slate-500 hover:text-white hover:bg-white/10">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<script>
// Toast functions
function showToast(type, title, message) {
    const knownTypes = ['success', 'error', 'warning', 'info'];
    if (!knownTypes.includes(type)) {
        const legacyMessage = type || '';
        const legacyType = knownTypes.includes(title) ? title : 'info';
        type = legacyType;
        title = legacyType === 'success' ? 'สำเร็จ'
            : legacyType === 'error' ? 'ผิดพลาด'
            : legacyType === 'warning' ? 'แจ้งเตือน'
            : 'ข้อมูล';
        message = legacyMessage;
    } else if (message === undefined) {
        message = title || '';
        title = type === 'success' ? 'สำเร็จ'
            : type === 'error' ? 'ผิดพลาด'
            : type === 'warning' ? 'แจ้งเตือน'
            : 'ข้อมูล';
    }

    const toast = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    const titleEl = document.getElementById('toastTitle');
    const messageEl = document.getElementById('toastMessage');
    
    const types = {
        success: { bg: 'bg-emerald-500/20', icon: 'fa-check', color: 'text-emerald-400' },
        error: { bg: 'bg-red-500/20', icon: 'fa-times', color: 'text-red-400' },
        warning: { bg: 'bg-amber-500/20', icon: 'fa-exclamation', color: 'text-amber-400' },
        info: { bg: 'bg-blue-500/20', icon: 'fa-info', color: 'text-blue-400' }
    };
    
    const t = types[type] || types.info;
    
    icon.className = 'w-8 h-8 rounded-lg flex items-center justify-center ' + t.bg;
    icon.innerHTML = '<i class="fas ' + t.icon + ' ' + t.color + '"></i>';
    titleEl.textContent = title;
    messageEl.textContent = message;
    
    toast.classList.remove('hidden');
    
    setTimeout(hideToast, 5000);
}

function hideToast() {
    document.getElementById('toast').classList.add('hidden');
}

// CSRF Token helper
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

/** Accessible loading HTML for HR async modals (spinner + sr-only label). */
function tpHrNativeLoadingHtml() {
    return '<div class="tp-native-loading-state py-8" role="status" aria-live="polite" aria-busy="true"><i class="fas fa-spinner fa-spin text-2xl text-white/30" aria-hidden="true"></i><span class="tp-visually-hidden">กำลังโหลด</span></div>';
}

// API helper
async function apiCall(url, method, data) {
    method = method || 'GET';
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        }
    };
    
    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch(url, options);
        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.message || 'เกิดข้อผิดพลาด');
        }
        
        return result;
    } catch (error) {
        showToast('error', 'ผิดพลาด', error.message);
        throw error;
    }
}

// Format number
function formatNumber(num) {
    return new Intl.NumberFormat('th-TH').format(num);
}

// Format date
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

/* =========================================================
 * Mobile UI Utilities (employee-first, native-like)
 * ========================================================= */
function uiIsIOS() {
    const ua = navigator.userAgent || '';
    const isApple = /iPad|iPhone|iPod/.test(ua);
    const isIpadOS13Plus = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    return isApple || isIpadOS13Plus;
}

function uiLockBodyScroll(lock) {
    if (lock) {
        document.documentElement.classList.add('overflow-hidden');
        document.body.classList.add('overflow-hidden');
    } else {
        document.documentElement.classList.remove('overflow-hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

function uiOpenModal(modalId) {
    const m = document.getElementById(modalId);
    if (!m) return;
    m.classList.remove('hidden');
    // most modals use flex overlay
    m.classList.add('flex');
    uiLockBodyScroll(true);
}

function uiCloseModal(modalId) {
    const m = document.getElementById(modalId);
    if (!m) return;
    m.classList.add('hidden');
    m.classList.remove('flex');
    uiLockBodyScroll(false);
}

function uiBuildTimeOptions(stepMinutes) {
    const out = [];
    for (let h = 0; h < 24; h++) {
        for (let m = 0; m < 60; m += stepMinutes) {
            out.push(String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0'));
        }
    }
    return out;
}

/**
 * iOS-safe time picker: swap <input type="time"> with a <select>.
 * Usage:
 *   <select data-ios-time-select-for="myTimeInput" class="hidden ..."></select>
 *   <input type="time" id="myTimeInput" ...>
 */
function uiInitIOSTimePickerFallback(stepMinutes = 15) {
    if (!uiIsIOS()) return;
    const selects = document.querySelectorAll('select[data-ios-time-select-for]');
    selects.forEach(sel => {
        const inputId = sel.getAttribute('data-ios-time-select-for');
        if (!inputId) return;
        const input = document.getElementById(inputId);
        if (!input) return;

        const current = input.value || input.getAttribute('value') || '08:30';
        const opts = uiBuildTimeOptions(stepMinutes);
        sel.innerHTML = opts.map(t => `<option value="${t}">${t}</option>`).join('');
        sel.value = current;
        input.value = sel.value || '';

        input.classList.add('hidden');
        sel.classList.remove('hidden');

        sel.addEventListener('change', () => {
            input.value = sel.value || '';
        });
    });
}

/** Sync iOS fallback selects into their target time inputs before native form POST. */
function uiSyncIOSTimeSelectsToInputs() {
    document.querySelectorAll('select[data-ios-time-select-for]').forEach(sel => {
        if (sel.classList.contains('hidden')) return;
        const inputId = sel.getAttribute('data-ios-time-select-for');
        const input = inputId && document.getElementById(inputId);
        if (input) input.value = sel.value || '';
    });
}

document.addEventListener('submit', () => {
    uiSyncIOSTimeSelectsToInputs();
}, true);

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const sidebar = document.getElementById('mobileSidebar');
    if (sidebar && !sidebar.classList.contains('hidden')) {
        if (typeof closeMobileMenu === 'function') closeMobileMenu();
        e.preventDefault();
        return;
    }
    const toast = document.getElementById('toast');
    if (toast && !toast.classList.contains('hidden')) {
        if (typeof hideToast === 'function') hideToast();
        e.preventDefault();
        return;
    }
    if (typeof uiCloseModal !== 'function') return;
    let closed = false;
    document.querySelectorAll('div[id].fixed.inset-0').forEach((el) => {
        if (el.id === 'mobileSidebar') return;
        if (el.classList.contains('hidden')) return;
        uiCloseModal(el.id);
        closed = true;
    });
    if (closed) e.preventDefault();
});

document.addEventListener('DOMContentLoaded', () => {
    uiInitIOSTimePickerFallback(15);
});
</script>

</body>
</html>
