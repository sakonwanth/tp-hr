/**
 * TP-HR PWA bootstrap — service worker registration, standalone detection,
 * and the iOS "Add to Home Screen" hint.
 *
 * Loaded from templates/header.php and login.php. No dependencies; runs before
 * DOMContentLoaded is safe because every DOM touch is deferred.
 *
 * The worker URL is derived from this script's own location so the same code
 * works at the production document root and under a local /tp-hr/ prefix.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var base = '/';
    if (script && script.src) {
        // .../assets/js/pwa.js → strip the three known trailing segments.
        base = new URL('../../', script.src).pathname;
    }

    var STORAGE_KEY = 'tp-hr:install-hint-dismissed';
    var HINT_COOLDOWN_MS = 30 * 24 * 60 * 60 * 1000; // 30 days

    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    var updateRequested = false;
    var reloading = false;

    if (isStandalone) {
        document.documentElement.classList.add('tp-pwa-standalone');
    }

    // ---------------------------------------------------------------- worker

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;

        navigator.serviceWorker.register(base + 'sw.js', { scope: base })
            .then(function (registration) {
                registration.addEventListener('updatefound', function () {
                    var incoming = registration.installing;
                    if (!incoming) return;
                    incoming.addEventListener('statechange', function () {
                        // A previous worker was controlling the page, so this is
                        // an update rather than a first install.
                        if (incoming.state === 'installed' && navigator.serviceWorker.controller) {
                            showUpdateToast(incoming);
                        }
                    });
                });
            })
            .catch(function () {
                /* HTTP origin, private mode, or blocked — the app still works. */
            });

        // clients.claim() in the worker fires controllerchange on the very first
        // install too. Only reload when the user actually asked for the update,
        // otherwise every first visit after a deploy would reload unprompted.
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            if (!updateRequested || reloading) return;
            reloading = true;
            window.location.reload();
        });
    }

    function showUpdateToast(worker) {
        if (document.getElementById('tpPwaUpdate')) return;

        var bar = document.createElement('div');
        bar.id = 'tpPwaUpdate';
        bar.setAttribute('role', 'status');
        bar.style.cssText = [
            'position:fixed',
            'left:16px',
            'right:16px',
            'bottom:calc(16px + env(safe-area-inset-bottom, 0px))',
            'z-index:9999',
            'display:flex',
            'align-items:center',
            'gap:12px',
            'padding:14px 16px',
            'border-radius:20px',
            'background:rgba(15,23,42,0.95)',
            'border:1px solid rgba(139,92,246,0.35)',
            'box-shadow:0 18px 40px rgba(0,0,0,0.35)',
            'backdrop-filter:blur(20px)',
            '-webkit-backdrop-filter:blur(20px)',
            'color:#e2e8f0',
            'font-size:14px',
        ].join(';');

        var label = document.createElement('span');
        label.style.cssText = 'flex:1;line-height:1.5';
        label.textContent = 'มีเวอร์ชันใหม่ของ TP-HR';

        var action = document.createElement('button');
        action.type = 'button';
        action.textContent = 'อัปเดต';
        action.style.cssText = [
            'flex:none',
            'min-height:48px',
            'padding:0 18px',
            'border:0',
            'border-radius:16px',
            'background:linear-gradient(135deg,#7c3aed,#8b5cf6)',
            'color:#fff',
            'font-family:inherit',
            'font-size:14px',
            'font-weight:600',
            'cursor:pointer',
        ].join(';');
        action.addEventListener('click', function () {
            // controllerchange (above) reloads the page once the new worker takes over.
            updateRequested = true;
            action.disabled = true;
            worker.postMessage({ type: 'SKIP_WAITING' });
        });

        bar.appendChild(label);
        bar.appendChild(action);
        document.body.appendChild(bar);
    }

    // --------------------------------------------------------- install hint

    function hintDismissedRecently() {
        try {
            var at = parseInt(window.localStorage.getItem(STORAGE_KEY) || '0', 10);
            return at > 0 && (Date.now() - at) < HINT_COOLDOWN_MS;
        } catch (err) {
            return false; // private mode — showing the hint once is harmless
        }
    }

    function rememberDismissal() {
        try {
            window.localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch (err) {
            /* ignore */
        }
    }

    function isIosSafari() {
        var ua = window.navigator.userAgent;
        var iosDevice = /iPad|iPhone|iPod/.test(ua)
            // iPadOS 13+ reports as Macintosh; touch points give it away.
            || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
        if (!iosDevice) return false;
        // CriOS / FxiOS / EdgiOS cannot add to the home screen at all.
        return !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
    }

    function showIosInstallHint() {
        if (document.getElementById('tpPwaInstallHint')) return;

        var card = document.createElement('div');
        card.id = 'tpPwaInstallHint';
        card.style.cssText = [
            'position:fixed',
            'left:16px',
            'right:16px',
            'bottom:calc(16px + env(safe-area-inset-bottom, 0px))',
            'z-index:9998',
            'padding:16px',
            'border-radius:22px',
            'background:rgba(15,23,42,0.95)',
            'border:1px solid rgba(148,163,184,0.18)',
            'box-shadow:0 18px 40px rgba(0,0,0,0.35)',
            'backdrop-filter:blur(20px)',
            '-webkit-backdrop-filter:blur(20px)',
            'color:#e2e8f0',
            'font-size:14px',
            'line-height:1.6',
        ].join(';');

        var text = document.createElement('div');
        text.innerHTML = 'ติดตั้ง <strong>TP-HR</strong> ลงหน้าโฮมเพื่อเปิดแบบเต็มจอ '
            + '— แตะปุ่ม <strong>แชร์</strong> แล้วเลือก <strong>เพิ่มไปยังหน้าจอโฮม</strong>';

        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:10px;margin-top:14px';

        var guide = document.createElement('a');
        guide.href = base + 'install.html';
        guide.textContent = 'ดูวิธีติดตั้ง';
        guide.style.cssText = [
            'flex:1',
            'min-height:48px',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'border-radius:16px',
            'background:linear-gradient(135deg,#7c3aed,#8b5cf6)',
            'color:#fff',
            'font-weight:600',
            'text-decoration:none',
        ].join(';');

        var close = document.createElement('button');
        close.type = 'button';
        close.textContent = 'ไม่ต้องแสดงอีก';
        close.style.cssText = [
            'flex:none',
            'min-height:48px',
            'padding:0 16px',
            'border:1px solid rgba(148,163,184,0.24)',
            'border-radius:16px',
            'background:transparent',
            'color:#94a3b8',
            'font-family:inherit',
            'font-size:14px',
            'cursor:pointer',
        ].join(';');
        close.addEventListener('click', function () {
            rememberDismissal();
            card.remove();
        });

        row.appendChild(guide);
        row.appendChild(close);
        card.appendChild(text);
        card.appendChild(row);
        document.body.appendChild(card);
    }

    function maybeShowInstallHint() {
        if (isStandalone) return;
        if (hintDismissedRecently()) return;
        if (!isIosSafari()) return;
        if (window.matchMedia('(min-width: 768px)').matches) return;
        // Let the page settle first so the hint never fights the initial paint.
        window.setTimeout(showIosInstallHint, 2500);
    }

    // ------------------------------------------------------------ Android

    function wireAndroidInstallPrompt() {
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            window.tpHrDeferredInstallPrompt = event;
        });
    }

    // ------------------------------------------------------------- kickoff

    registerServiceWorker();
    wireAndroidInstallPrompt();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maybeShowInstallHint);
    } else {
        maybeShowInstallHint();
    }
}());
