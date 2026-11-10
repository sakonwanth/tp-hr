// Execute the shipped viewer against a small DOM/event fixture. No sessions,
// network requests, attendance mutations or production image files are used.
const assert = require('node:assert/strict');
const { init } = require('../assets/js/attendance-photo-viewer.js');

class Element {
    constructor(tag, doc) {
        this.tagName = tag.toUpperCase(); this.ownerDocument = doc;
        this.attributes = new Map(); this.dataset = {}; this.children = [];
        this.parentElement = null; this.textContent = ''; this.hidden = false;
        this.style = {}; this.listeners = new Map(); this.classSet = new Set();
        this.classList = {
            add: (...names) => names.forEach(n => this.classSet.add(n)),
            remove: (...names) => names.forEach(n => this.classSet.delete(n)),
            contains: name => this.classSet.has(name),
            toggle: (name, force) => { const next = force ?? !this.classSet.has(name); next ? this.classSet.add(name) : this.classSet.delete(name); return next; },
        };
        this.complete = false; this.naturalWidth = 0; this.naturalHeight = 0;
    }
    setAttribute(name, value) {
        this.attributes.set(name, String(value));
        if (name.startsWith('data-')) this.dataset[name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = String(value);
    }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    get href() { return new URL(this.getAttribute('href') || '', 'https://hr.example.test/').href; }
    set href(value) { this.setAttribute('href', value); }
    get src() { return this.getAttribute('src') || ''; }
    set src(value) { this.setAttribute('src', value); }
    get id() { return this.getAttribute('id') || ''; }
    set id(value) { this.setAttribute('id', value); }
    get firstElementChild() { return this.children[0] || null; }
    get childElementCount() { return this.children.length; }
    get isConnected() { return this === this.ownerDocument.body || !!this.parentElement?.isConnected; }
    contains(node) { return this === node || this.children.some(c => c.contains(node)); }
    appendChild(node) { node.parentElement = this; this.children.push(node); return node; }
    append(...nodes) { nodes.forEach(n => this.appendChild(n)); }
    replaceChildren(...nodes) { this.children.forEach(c => { c.parentElement = null; }); this.children = []; this.append(...nodes); }
    remove() { if (this.parentElement) { this.parentElement.children = this.parentElement.children.filter(c => c !== this); this.parentElement = null; } }
    focus() { this.ownerDocument.activeElement = this; }
    closest(selector) {
        if (selector === 'a[data-attendance-photo]' && this.tagName === 'A' && this.hasAttribute('data-attendance-photo')) return this;
        if (selector === '[data-attendance-photo]' && this.hasAttribute('data-attendance-photo')) return this;
        return this.parentElement?.closest(selector) || null;
    }
    addEventListener(type, fn) { const list = this.listeners.get(type) || []; list.push(fn); this.listeners.set(type, list); }
    removeEventListener(type, fn) { this.listeners.set(type, (this.listeners.get(type) || []).filter(f => f !== fn)); }
    dispatch(type, props = {}) {
        const event = { type, target: this, currentTarget: this, button: 0, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...props };
        for (const fn of this.listeners.get(type) || []) fn(event);
        if (typeof this['on' + type] === 'function') this['on' + type](event);
        return event;
    }
    getBoundingClientRect() { return { left: 100, top: 100, right: 700, bottom: 600, width: 600, height: 500 }; }
}

function fixture({ native = true, queuedClose = false } = {}) {
    const doc = { elements: new Map(), listeners: new Map(), readyState: 'complete' };
    doc.body = new Element('body', doc); doc.activeElement = doc.body;
    doc.createElement = tag => new Element(tag, doc);
    doc.getElementById = id => doc.elements.get(id) || null;
    doc.contains = node => doc.body.contains(node);
    doc.addEventListener = Element.prototype.addEventListener;
    doc.removeEventListener = Element.prototype.removeEventListener;
    doc.dispatch = Element.prototype.dispatch;
    const dialog = new Element('dialog', doc); dialog.open = false;
    dialog.showModal = native ? () => { dialog.open = true; } : undefined;
    const pendingCloseEvents = [];
    dialog.close = () => {
        if (!dialog.open) return;
        dialog.open = false;
        if (queuedClose) pendingCloseEvents.push(() => dialog.dispatch('close'));
        else dialog.dispatch('close');
    };
    dialog.cancel = () => { const e = dialog.dispatch('cancel'); if (!e.defaultPrevented) dialog.close(); return e; };
    for (const suffix of ['dialog','title','caption','image-slot','status','original','close']) {
        const el = suffix === 'dialog' ? dialog : new Element(suffix === 'original' ? 'a' : suffix === 'close' ? 'button' : 'div', doc);
        el.id = 'attendance-photo-' + suffix; doc.elements.set(el.id, el);
        (suffix === 'dialog' ? doc.body : dialog).appendChild(el);
    }
    const link = (url, title, caption) => {
        const a = new Element('a', doc); a.href = url; a.setAttribute('data-attendance-photo', '');
        a.setAttribute('data-photo-title', title); a.setAttribute('data-photo-caption', caption);
        a.appendChild(new Element('span', doc)); doc.body.appendChild(a); return a;
    };
    const click = (a, props = {}) => doc.dispatch('click', { target: a.children[0] || a, ...props });
    return { doc, dialog, link, click,
        flushCloseEvents: () => { while (pendingCloseEvents.length) pendingCloseEvents.shift()(); },
        get: suffix => doc.getElementById('attendance-photo-' + suffix) };
}

let scenarios = 0;
function test(label, fn) { fn(); scenarios++; console.log('PASS ' + label); }
const entry = 'https://checkin.example.test/storage/photos/check_in_outside/2026/11/check_in_outside_77.jpg';
const exit = 'https://hr.example.test/storage/uploads/attendance/2026/11/checkout_77.jpg';

test('entry and exit use their actual original URLs and safe text captions', () => {
    const f = fixture(); const viewer = init(f.doc);
    const a = f.link(entry, 'รูปเข้างาน', '<img src=x onerror=unsafe>');
    const e = f.click(a); assert.equal(e.defaultPrevented, true); assert.equal(f.dialog.open, true);
    assert.equal(f.get('image-slot').children[0].src, entry); assert.equal(f.get('original').href, entry);
    assert.equal(f.get('title').textContent, 'รูปเข้างาน');
    assert.equal(f.get('caption').textContent, '<img src=x onerror=unsafe>'); assert.equal(f.get('caption').children.length, 0);
    assert.match(f.get('status').textContent, /กำลังโหลด/);
    const image = f.get('image-slot').children[0]; image.naturalWidth = 800; image.dispatch('load');
    assert.equal(f.get('status').hidden, true); assert.equal(image.hidden, false);
    const b = f.link(exit, 'รูปออกงาน', 'QA / ออก'); viewer.open(b);
    assert.equal(f.get('image-slot').children[0].src, exit); assert.equal(f.get('original').href, exit);
    assert.equal(f.get('title').textContent, 'รูปออกงาน');
});

test('error retains original link and a new open creates a clean retry', () => {
    const f = fixture(); const viewer = init(f.doc); const a = f.link(entry, 'เข้า', 'QA'); viewer.open(a);
    const failed = f.get('image-slot').children[0]; failed.dispatch('error');
    assert.match(f.get('status').textContent, /ไม่สามารถเปิดรูปนี้ได้/); assert.equal(f.get('original').href, entry);
    viewer.close(); viewer.open(a);
    assert.notEqual(f.get('image-slot').children[0], failed); assert.match(f.get('status').textContent, /กำลังโหลด/);
});

test('old load/error events cannot replace the latest photo state', () => {
    const f = fixture(); const viewer = init(f.doc); const a = f.link(entry, 'เข้า', 'QA'); viewer.open(a);
    const old = f.get('image-slot').children[0]; const b = f.link(exit, 'ออก', 'QA'); viewer.open(b);
    const current = f.get('image-slot').children[0]; current.dispatch('load'); const status = f.get('status').textContent;
    old.dispatch('error'); old.dispatch('load');
    assert.equal(f.get('status').textContent, status); assert.equal(f.get('image-slot').children[0], current);
    assert.equal(f.get('title').textContent, 'ออก'); assert.equal(current.src, exit);
});

test('queued native close event cannot clear a newly reopened photo', () => {
    const f = fixture({ queuedClose: true }); const viewer = init(f.doc);
    const a = f.link(entry, 'เข้า', 'QA / เข้า'); viewer.open(a);
    const old = f.get('image-slot').children[0]; viewer.close();
    assert.equal(f.get('image-slot').children.length, 0); assert.equal(old.src, '');
    assert.equal(f.doc.activeElement, a);
    const b = f.link(exit, 'ออก', 'QA / ออก'); viewer.open(b);
    const current = f.get('image-slot').children[0]; f.flushCloseEvents();
    assert.equal(f.dialog.open, true); assert.equal(f.get('image-slot').children[0], current);
    assert.equal(f.get('original').href, exit); assert.equal(f.get('title').textContent, 'ออก');
    assert.equal(f.doc.activeElement, f.get('close'));
    old.dispatch('error'); old.dispatch('load'); current.dispatch('load');
    assert.equal(current.hidden, false); assert.equal(f.get('status').hidden, true);
    viewer.close(); f.flushCloseEvents();
    assert.equal(f.get('image-slot').children.length, 0); assert.equal(f.doc.activeElement, b);
});

test('close and Escape clear image requests and restore opening-control focus', () => {
    for (const mode of ['close-button','cancel']) {
        const f = fixture(); const viewer = init(f.doc); const a = f.link(entry, 'เข้า', 'QA'); a.focus(); viewer.open(a);
        const old = f.get('image-slot').children[0];
        if (mode === 'cancel') f.dialog.cancel(); else f.get('close').dispatch('click');
        assert.equal(f.dialog.open, false); assert.equal(f.get('image-slot').children.length, 0);
        assert.equal(f.doc.activeElement, a); assert.equal(old.src, '');
        const status = f.get('status').textContent; old.dispatch('error'); assert.equal(f.get('status').textContent, status);
    }
});

test('backdrop closes but dialog interior and image clicks remain open', () => {
    const f = fixture(); const viewer = init(f.doc); const a = f.link(entry, 'เข้า', 'QA'); viewer.open(a);
    f.dialog.dispatch('click', { clientX: 200, clientY: 200 }); assert.equal(f.dialog.open, true);
    f.dialog.dispatch('click', { target: f.get('image-slot').children[0], clientX: 200, clientY: 200 }); assert.equal(f.dialog.open, true);
    f.dialog.dispatch('click', { clientX: 50, clientY: 50 }); assert.equal(f.dialog.open, false);
});

test('modified clicks and non-left buttons preserve native link behavior', () => {
    for (const modifier of [{ ctrlKey: true },{ metaKey: true },{ shiftKey: true },{ altKey: true },{ button: 1 },{ button: 2 }]) {
        const f = fixture(); init(f.doc); const a = f.link(entry, 'เข้า', 'QA');
        const e = f.click(a, modifier); assert.equal(e.defaultPrevented, false); assert.equal(f.dialog.open, false);
    }
});

test('unsupported native dialog preserves normal original-image navigation', () => {
    const f = fixture({ native: false }); init(f.doc); const a = f.link(entry, 'เข้า', 'QA');
    const e = f.click(a); assert.equal(e.defaultPrevented, false); assert.equal(f.dialog.open, false); assert.equal(a.href, entry);
});

test('closing after opening control is removed does not focus a detached node', () => {
    const f = fixture(); const viewer = init(f.doc); const a = f.link(entry, 'เข้า', 'QA'); viewer.open(a);
    a.remove(); f.doc.activeElement = f.doc.body; viewer.close(); assert.notEqual(f.doc.activeElement, a);
});

console.log(`Attendance photo viewer: ${scenarios} behavior scenarios passed.`);
