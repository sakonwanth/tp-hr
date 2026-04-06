<?php
/**
 * TP-HR Footer Template
 */
?>

</div><!-- End content-area -->

<!-- Toast Notification -->
<div id="toast" class="fixed bottom-4 right-4 z-50 hidden">
    <div class="bg-slate-800 border border-slate-700 rounded-xl shadow-xl p-4 flex items-center gap-3 min-w-[300px]">
        <div id="toastIcon" class="w-8 h-8 rounded-lg flex items-center justify-center"></div>
        <div class="flex-1">
            <p id="toastTitle" class="text-white font-medium"></p>
            <p id="toastMessage" class="text-slate-400 text-sm"></p>
        </div>
        <button onclick="hideToast()" class="text-slate-500 hover:text-white">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<script>
// Toast functions
function showToast(type, title, message) {
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
</script>

</body>
</html>
