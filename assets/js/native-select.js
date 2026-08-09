/**
 * TP-HR native select — replaces the browser's <select> popup with an
 * in-app bottom sheet.
 *
 * iOS renders <select> as a system wheel picker anchored to the bottom of the
 * screen. It is unmistakably "a web page in a browser", which breaks the app
 * illusion the rest of the shell works to keep.
 *
 * PROGRESSIVE ENHANCEMENT, on purpose. The real <select> stays in the DOM and
 * in the form; it is only visually hidden. The sheet writes back to it and
 * fires a bubbling `change`, so existing inline handlers — including the
 * onchange="this.form.submit()" filters used across the app — keep working
 * untouched. There are 252 selects in this codebase; none of them had to be
 * edited, and if this script fails to load every one of them still works.
 *
 * Opt out with data-no-sheet on the select.
 */
(function () {
    'use strict';

    var SHEET_ID = 'tpNativeSelectSheet';
    var SEARCH_THRESHOLD = 8; // lists longer than this get a filter box
    var activeSelect = null;
    var lastFocused = null;

    // ------------------------------------------------------------- helpers

    function optionsOf(select) {
        return Array.prototype.slice.call(select.options).map(function (opt, i) {
            return {
                index: i,
                value: opt.value,
                label: opt.textContent.trim(),
                disabled: opt.disabled,
                selected: opt.selected,
                group: opt.parentNode && opt.parentNode.tagName === 'OPTGROUP'
                    ? opt.parentNode.label
                    : null,
            };
        });
    }

    function currentLabel(select) {
        var opt = select.options[select.selectedIndex];
        return opt ? opt.textContent.trim() : '';
    }

    function shouldEnhance(select) {
        if (select.multiple) return false;              // sheet is single-choice
        if (select.dataset.noSheet !== undefined) return false;
        if (select.dataset.tpSheetBound === '1') return false;
        if (select.options.length === 0) return false;
        return true;
    }

    // -------------------------------------------------------------- sheet

    function buildSheet() {
        var existing = document.getElementById(SHEET_ID);
        if (existing) return existing;

        var root = document.createElement('div');
        root.id = SHEET_ID;
        root.className = 'tp-select-sheet';
        root.setAttribute('hidden', '');
        root.innerHTML =
            '<div class="tp-select-sheet__scrim" data-close></div>' +
            '<div class="tp-select-sheet__panel" role="dialog" aria-modal="true">' +
            '  <div class="tp-select-sheet__grip" aria-hidden="true"></div>' +
            '  <div class="tp-select-sheet__head">' +
            '    <h2 class="tp-select-sheet__title"></h2>' +
            '    <button type="button" class="tp-select-sheet__close" data-close aria-label="ปิด">' +
            '      <i class="fas fa-times" aria-hidden="true"></i>' +
            '    </button>' +
            '  </div>' +
            '  <div class="tp-select-sheet__searchwrap" hidden>' +
            '    <input type="search" class="tp-select-sheet__search" placeholder="ค้นหา…" autocomplete="off">' +
            '  </div>' +
            '  <ul class="tp-select-sheet__list" role="listbox" tabindex="-1"></ul>' +
            '</div>';

        document.body.appendChild(root);

        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-close]')) closeSheet();
        });

        root.querySelector('.tp-select-sheet__search').addEventListener('input', function (event) {
            filterList(event.target.value);
        });

        return root;
    }

    function filterList(term) {
        var needle = term.trim().toLowerCase();
        var items = document.querySelectorAll('#' + SHEET_ID + ' .tp-select-sheet__option');
        Array.prototype.forEach.call(items, function (li) {
            var match = needle === '' || li.textContent.toLowerCase().indexOf(needle) !== -1;
            li.hidden = !match;
        });
    }

    function labelFor(select) {
        if (select.getAttribute('aria-label')) return select.getAttribute('aria-label');
        if (select.id) {
            var lab = document.querySelector('label[for="' + CSS.escape(select.id) + '"]');
            if (lab) return lab.textContent.trim();
        }
        var wrapping = select.closest('label');
        if (wrapping) return wrapping.textContent.trim();
        return 'เลือกรายการ';
    }

    function openSheet(select) {
        var sheet = buildSheet();
        activeSelect = select;
        lastFocused = document.activeElement;

        sheet.querySelector('.tp-select-sheet__title').textContent = labelFor(select);

        var list = sheet.querySelector('.tp-select-sheet__list');
        list.innerHTML = '';

        var opts = optionsOf(select);
        var lastGroup = null;

        opts.forEach(function (opt) {
            if (opt.group && opt.group !== lastGroup) {
                var head = document.createElement('li');
                head.className = 'tp-select-sheet__group';
                head.textContent = opt.group;
                list.appendChild(head);
                lastGroup = opt.group;
            }

            var li = document.createElement('li');
            li.className = 'tp-select-sheet__option';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
            if (opt.disabled) li.setAttribute('aria-disabled', 'true');
            li.dataset.index = String(opt.index);
            li.innerHTML =
                '<span class="tp-select-sheet__label"></span>' +
                '<i class="fas fa-check tp-select-sheet__tick" aria-hidden="true"></i>';
            li.querySelector('.tp-select-sheet__label').textContent = opt.label;

            if (!opt.disabled) {
                li.addEventListener('click', function () { choose(opt.index); });
            }
            list.appendChild(li);
        });

        var searchWrap = sheet.querySelector('.tp-select-sheet__searchwrap');
        var search = sheet.querySelector('.tp-select-sheet__search');
        search.value = '';
        searchWrap.hidden = opts.length < SEARCH_THRESHOLD;

        sheet.removeAttribute('hidden');
        // Next frame so the transition has a starting state to animate from.
        requestAnimationFrame(function () { sheet.classList.add('is-open'); });
        document.documentElement.classList.add('tp-select-sheet-open');

        var selected = list.querySelector('[aria-selected="true"]');
        if (selected) selected.scrollIntoView({ block: 'center' });
    }

    function choose(index) {
        var select = activeSelect;
        if (!select) return closeSheet();

        if (select.selectedIndex !== index) {
            select.selectedIndex = index;
            // Bubbling so inline onchange="this.form.submit()" still fires.
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        syncTrigger(select);
        closeSheet();
    }

    function closeSheet() {
        var sheet = document.getElementById(SHEET_ID);
        if (!sheet) return;
        sheet.classList.remove('is-open');
        document.documentElement.classList.remove('tp-select-sheet-open');

        window.setTimeout(function () {
            if (!sheet.classList.contains('is-open')) sheet.setAttribute('hidden', '');
        }, 220);

        if (lastFocused && document.contains(lastFocused)) lastFocused.focus();
        activeSelect = null;
    }

    // ------------------------------------------------------------ trigger

    function syncTrigger(select) {
        var trigger = select.tpSheetTrigger;
        if (!trigger) return;
        var label = currentLabel(select);
        trigger.querySelector('.tp-select-trigger__value').textContent = label;
        trigger.classList.toggle('is-placeholder', select.value === '' || label === '');
        trigger.disabled = select.disabled;
    }

    function enhance(select) {
        if (!shouldEnhance(select)) return;
        select.dataset.tpSheetBound = '1';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'tp-select-trigger ' + (select.className || '');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.innerHTML =
            '<span class="tp-select-trigger__value"></span>' +
            '<i class="fas fa-chevron-down tp-select-trigger__chevron" aria-hidden="true"></i>';

        trigger.addEventListener('click', function () {
            if (!select.disabled) openSheet(select);
        });

        select.classList.add('tp-select-native-hidden');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');
        select.parentNode.insertBefore(trigger, select.nextSibling);

        select.tpSheetTrigger = trigger;
        syncTrigger(select);

        // Something else may set the value programmatically (a reset, a
        // dependent filter); keep the visible label honest.
        select.addEventListener('change', function () {
            syncTrigger(select);
            trigger.classList.remove('is-invalid');
        });

        // Constraint validation still runs on the hidden select, but the
        // browser anchors its message to an element nobody can see. Move the
        // signal to the control the user is actually looking at.
        select.addEventListener('invalid', function () {
            trigger.classList.add('is-invalid');
            trigger.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
    }

    function scan(root) {
        var scope = root || document;
        Array.prototype.forEach.call(scope.querySelectorAll('select'), enhance);
    }

    // ------------------------------------------------------------- wiring

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && activeSelect) {
            closeSheet();
            event.preventDefault();
        }
    });

    function start() {
        scan();

        // Rows added later (education, family, work history) carry selects too.
        if ('MutationObserver' in window) {
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes, function (node) {
                        if (node.nodeType !== 1) return;
                        if (node.tagName === 'SELECT') enhance(node);
                        else scan(node);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    window.tpNativeSelect = { scan: scan, close: closeSheet };
}());
