(function (root, factory) {
    'use strict';
    const viewer = factory();
    if (typeof module === 'object' && module.exports) module.exports = viewer;
    if (root.document) viewer.init(root.document);
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';
    function init(doc) {
        const dialog = doc.getElementById('attendance-photo-dialog');
        if (!dialog || typeof dialog.showModal !== 'function') return null;
        const title = doc.getElementById('attendance-photo-title');
        const caption = doc.getElementById('attendance-photo-caption');
        const slot = doc.getElementById('attendance-photo-image-slot');
        const status = doc.getElementById('attendance-photo-status');
        const original = doc.getElementById('attendance-photo-original');
        const closeButton = doc.getElementById('attendance-photo-close');
        if (!title || !caption || !slot || !status || !original || !closeButton) return null;
        let generation = 0;
        let opener = null;
        let activeImage = null;

        function clearImage() {
            generation++;
            if (activeImage) activeImage.removeAttribute('src');
            activeImage = null;
            slot.replaceChildren();
        }
        function finishClose() {
            clearImage();
            original.removeAttribute('href');
            const previous = opener;
            opener = null;
            if (previous && previous.isConnected) previous.focus({ preventScroll: true });
        }
        function close() {
            if (dialog.open) {
                dialog.close();
                finishClose();
            }
        }
        function open(anchor) {
            clearImage();
            opener = anchor;
            title.textContent = anchor.dataset.photoTitle || 'รูปยืนยันการลงเวลา';
            caption.textContent = anchor.dataset.photoCaption || '';
            original.href = anchor.href;
            status.hidden = false;
            status.textContent = 'กำลังโหลดรูป…';
            status.setAttribute('role', 'status');
            try {
                if (!dialog.open) dialog.showModal();
            } catch (error) {
                opener = null;
                return false;
            }
            const request = generation;
            const image = doc.createElement('img');
            activeImage = image;
            image.alt = title.textContent;
            image.className = 'max-w-full w-auto h-auto object-contain rounded-lg';
            image.style.maxHeight = '65dvh';
            image.hidden = true;
            image.addEventListener('load', function () {
                if (request !== generation || activeImage !== image || !dialog.open) return;
                image.hidden = false;
                status.hidden = true;
                status.textContent = '';
            });
            image.addEventListener('error', function () {
                if (request !== generation || activeImage !== image || !dialog.open) return;
                image.hidden = true;
                status.textContent = 'ไม่สามารถเปิดรูปนี้ได้ กรุณาลองเปิดรูปต้นฉบับ';
                status.hidden = false;
                status.setAttribute('role', 'alert');
            });
            slot.replaceChildren(image);
            image.src = anchor.href;
            closeButton.focus();
            return true;
        }

        doc.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const anchor = event.target.closest && event.target.closest('a[data-attendance-photo]');
            if (anchor && open(anchor)) event.preventDefault();
        });
        closeButton.addEventListener('click', close);
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
        dialog.addEventListener('click', function (event) {
            if (event.target !== dialog) return;
            const box = dialog.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
        });
        // A queued close event from a previous photo must not clear a newly opened one.
        dialog.addEventListener('close', function () { if (!dialog.open) finishClose(); });
        return { open, close };
    }
    return { init };
});
