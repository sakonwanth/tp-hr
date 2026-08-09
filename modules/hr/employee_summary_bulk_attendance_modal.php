<?php
/**
 * Modal + JS: แก้ไขเวลาหลายวันพร้อมกัน (employee_summaries / employee_view)
 */
if (!empty($GLOBALS['tp_hr_bulk_att_modal_loaded'])) {
    return;
}
$GLOBALS['tp_hr_bulk_att_modal_loaded'] = true;

$bulkDefaultCheckIn = $bulkDefaultCheckIn ?? '08:45';
$bulkDefaultCheckOut = $bulkDefaultCheckOut ?? '17:30';
if (strlen($bulkDefaultCheckIn) > 5) {
    $bulkDefaultCheckIn = substr($bulkDefaultCheckIn, 0, 5);
}
if (strlen($bulkDefaultCheckOut) > 5) {
    $bulkDefaultCheckOut = substr($bulkDefaultCheckOut, 0, 5);
}
$bulkReloadBase = $bulkReloadBase ?? '';
?>
<div id="bulk-att-modal" class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-[60] flex items-center justify-center p-5 overflow-y-auto overscroll-contain" role="dialog" aria-modal="true" aria-labelledby="bulk-att-modal-title">
    <div class="native-card tp-native-card w-full max-w-lg my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain rounded-[var(--tp-ios-card-radius)]">
        <form id="bulk-att-form" class="p-6">
            <h3 id="bulk-att-modal-title" class="text-xl font-bold text-white mb-1">แก้ไขเวลาหลายวัน</h3>
            <p id="bulk-att-subtitle" class="text-white/55 text-sm mb-4"></p>

            <div class="mb-4 rounded-[var(--tp-ios-card-radius)] bg-white/5 border border-white/10 px-4 py-3 max-h-36 overflow-y-auto">
                <p class="text-white/50 text-xs mb-2">วันที่เลือก (<span id="bulk-att-count">0</span> วัน)</p>
                <ul id="bulk-att-date-list" class="text-white/80 text-sm space-y-1 tabular-nums"></ul>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="bulk-att-check-in" class="block text-white/80 text-sm mb-2">เวลาเข้างาน</label>
                    <input type="time" id="bulk-att-check-in" class="input-field tp-native-input w-full" value="<?php echo htmlspecialchars($bulkDefaultCheckIn); ?>" required>
                </div>
                <div>
                    <label for="bulk-att-check-out" class="block text-white/80 text-sm mb-2">เวลาออกงาน</label>
                    <input type="time" id="bulk-att-check-out" class="input-field tp-native-input w-full" value="<?php echo htmlspecialchars($bulkDefaultCheckOut); ?>" required>
                </div>
            </div>

            <div class="mb-4">
                <label for="bulk-att-note" class="block text-white/80 text-sm mb-2">เหตุผลการแก้ไข <span class="text-red-400">*</span></label>
                <textarea id="bulk-att-note" rows="2" class="input-field tp-native-textarea w-full" placeholder="เช่น อนุมัติให้ลงเวลาย้อนหลัง — ไม่ได้ลงเวลาจริง" required></textarea>
                <p class="text-white/45 text-xs mt-1">ใช้เวลาเข้า-ออกเดียวกันกับทุกวันที่เลือก · บันทึก audit ทุกวัน</p>
            </div>

            <input type="hidden" id="bulk-att-user-id" value="">

            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" id="bulk-att-cancel" class="flex-1 min-h-[48px] py-2 bg-white/10 hover:bg-white/20 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation whitespace-nowrap">ยกเลิก</button>
                <button type="submit" id="bulk-att-submit" class="flex-1 min-h-[56px] py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-[var(--tp-ios-card-radius)] touch-manipulation font-semibold whitespace-nowrap">บันทึกทั้งหมด</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var reloadBase = <?php echo json_encode($bulkReloadBase, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var modal = document.getElementById('bulk-att-modal');
    if (!modal) return;

    var pendingDates = [];
    var pendingUserId = 0;
    var pendingLabel = '';

    function formatDateThaiShort(ymd) {
        if (!ymd || ymd.length < 10) return ymd;
        var p = ymd.split('-');
        var months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        return parseInt(p[2], 10) + ' ' + (months[parseInt(p[1], 10)] || p[1]) + ' ' + (parseInt(p[0], 10) + 543);
    }

    function openBulkModal(userId, dates, label) {
        pendingUserId = userId;
        pendingDates = dates.slice().sort();
        pendingLabel = label || '';
        document.getElementById('bulk-att-user-id').value = String(userId);
        document.getElementById('bulk-att-subtitle').textContent = pendingLabel;
        document.getElementById('bulk-att-count').textContent = String(pendingDates.length);
        document.getElementById('bulk-att-date-list').innerHTML = pendingDates.map(function (d) {
            return '<li>' + formatDateThaiShort(d) + '</li>';
        }).join('');
        document.getElementById('bulk-att-note').value = '';
        if (typeof uiOpenModal === 'function') uiOpenModal('bulk-att-modal');
        else { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    }

    function closeBulkModal() {
        if (typeof uiCloseModal === 'function') uiCloseModal('bulk-att-modal');
        else { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    }

    function getSelectedInGroup(groupKey) {
        return Array.from(document.querySelectorAll('.emp-bulk-day-cb[data-group="' + groupKey + '"]:checked'))
            .map(function (cb) { return cb.getAttribute('data-date'); })
            .filter(Boolean);
    }

    function updateGroupCount(groupKey) {
        var n = document.querySelectorAll('.emp-bulk-day-cb[data-group="' + groupKey + '"]:checked').length;
        document.querySelectorAll('.emp-bulk-count[data-group="' + groupKey + '"]').forEach(function (el) {
            el.textContent = String(n);
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('emp-bulk-day-cb')) {
            updateGroupCount(e.target.getAttribute('data-group'));
        }
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-bulk-action]');
        if (!t) return;
        var action = t.getAttribute('data-bulk-action');
        var group = t.getAttribute('data-group');
        var userId = parseInt(t.getAttribute('data-user-id') || '0', 10);
        var label = t.getAttribute('data-label') || '';

        if (action === 'select-all' && group) {
            document.querySelectorAll('.emp-bulk-day-cb[data-group="' + group + '"]').forEach(function (cb) { cb.checked = true; });
            updateGroupCount(group);
            return;
        }
        if (action === 'clear-all' && group) {
            document.querySelectorAll('.emp-bulk-day-cb[data-group="' + group + '"]').forEach(function (cb) { cb.checked = false; });
            updateGroupCount(group);
            return;
        }
        if (action === 'edit-group' && group && userId > 0) {
            document.querySelectorAll('.emp-bulk-day-cb[data-group="' + group + '"]').forEach(function (cb) { cb.checked = true; });
            updateGroupCount(group);
            var dates = getSelectedInGroup(group);
            if (!dates.length) return;
            openBulkModal(userId, dates, label);
            return;
        }
        if (action === 'edit-selected' && group && userId > 0) {
            var datesSel = getSelectedInGroup(group);
            if (!datesSel.length) {
                if (typeof showToast === 'function') showToast('กรุณาเลือกอย่างน้อย 1 วัน', 'error');
                return;
            }
            openBulkModal(userId, datesSel, label);
        }
    });

    document.getElementById('bulk-att-cancel').addEventListener('click', closeBulkModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeBulkModal(); });

    document.getElementById('bulk-att-form').addEventListener('submit', async function (e) {
        e.preventDefault();
        var note = document.getElementById('bulk-att-note').value.trim();
        var ci = document.getElementById('bulk-att-check-in').value;
        var co = document.getElementById('bulk-att-check-out').value;
        if (!note) {
            if (typeof showToast === 'function') showToast('กรุณาระบุเหตุผล', 'error');
            return;
        }
        if (!ci && !co) {
            if (typeof showToast === 'function') showToast('กรุณาระบุเวลาเข้าหรือออก', 'error');
            return;
        }
        var btn = document.getElementById('bulk-att-submit');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'bulk_adjust');
        fd.append('user_id', String(pendingUserId));
        fd.append('note', note);
        fd.append('check_in_time', ci);
        fd.append('check_out_time', co);
        fd.append('_token', '<?php echo csrfToken(); ?>');
        pendingDates.forEach(function (d) { fd.append('dates[]', d); });

        try {
            var res = await fetch('/api/attendance.php', { method: 'POST', body: fd });
            var result = await res.json();
            if (result.success) {
                if (typeof showToast === 'function') showToast(result.message || 'บันทึกสำเร็จ', 'success');
                closeBulkModal();
                setTimeout(function () {
                    if (reloadBase) {
                        var url = reloadBase;
                        if (pendingUserId > 0) {
                            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'expand=' + pendingUserId;
                        }
                        window.location.href = url;
                    } else {
                        window.location.reload();
                    }
                }, 700);
            } else {
                if (typeof showToast === 'function') showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            }
        } catch (err) {
            if (typeof showToast === 'function') showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
