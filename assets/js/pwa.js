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

        // Tapping a notification asks the worker to open a page. iOS Safari
        // does not support WindowClient.navigate(), so the worker falls back
        // to handing us the destination — see notificationclick in sw.js.
        navigator.serviceWorker.addEventListener('message', function (event) {
            var data = event.data;
            if (!data || data.type !== 'TP_HR_NAVIGATE' || typeof data.url !== 'string') return;

            // Same-origin only: never let a message move the app off-site.
            var target;
            try {
                target = new URL(data.url, window.location.origin);
            } catch (err) {
                return;
            }
            if (target.origin !== window.location.origin) return;

            window.location.href = target.href;
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

    // ---------------------------------------------------------- web push

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /** VAPID keys travel as base64url; PushManager wants raw bytes. */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    function postPush(body) {
        return fetch(base + 'api/push.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ _token: csrfToken() }, body)),
        }).then(function (res) { return res.json(); });
    }

    function pushConfig() {
        return fetch(base + 'api/push.php?action=config', { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : null; })
            .catch(function () { return null; });
    }

    function pushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    }

    /** Subscribe and hand the subscription to the server. Permission must already be granted. */
    function subscribeWith(config) {
        return navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription().then(function (existing) {
                if (existing) return existing;
                return registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(config.public_key),
                });
            });
        }).then(function (subscription) {
            return postPush({ action: 'subscribe', subscription: subscription.toJSON() });
        });
    }

    /**
     * Must be called from a user gesture. Safari ties requestPermission() to
     * user activation, and activation does not survive an await — so when the
     * caller already fetched the config (the opt-in card does), it passes it
     * in and the prompt fires synchronously on the tap. Fetching first would
     * make the prompt silently never appear on iOS.
     */
    function enablePush(preloadedConfig) {
        if (!pushSupported()) {
            return Promise.resolve({ success: false, reason: 'unsupported' });
        }

        function withConfig(config) {
            if (!config || !config.enabled || !config.public_key) {
                return Promise.resolve({ success: false, reason: 'not-configured' });
            }
            return Notification.requestPermission().then(function (permission) {
                if (permission !== 'granted') {
                    return { success: false, reason: permission };
                }
                return subscribeWith(config);
            });
        }

        var run = preloadedConfig
            ? withConfig(preloadedConfig)
            : pushConfig().then(withConfig);

        return run.catch(function () {
            return { success: false, reason: 'error' };
        });
    }

    /**
     * Permission already granted, but the server has no subscription for this
     * install — the row was pruned after repeated delivery failures, or the
     * browser rotated the endpoint. Nothing prompts the user again in that
     * state, so notifications would stay silently dead forever. Re-subscribe
     * quietly; no permission prompt is involved.
     */
    function repairPushSubscription() {
        if (!pushSupported() || Notification.permission !== 'granted') return;

        // Once per tab session — this runs on every page view otherwise.
        try {
            if (window.sessionStorage.getItem('tp-hr:push-checked')) return;
            window.sessionStorage.setItem('tp-hr:push-checked', '1');
        } catch (err) {
            /* private mode — checking every view is still harmless */
        }

        pushConfig().then(function (config) {
            if (!config || !config.enabled || !config.public_key || config.subscribed) return;
            return subscribeWith(config);
        }).catch(function () { /* best effort */ });
    }

    function disablePush() {
        if (!pushSupported()) return Promise.resolve({ success: false });

        return navigator.serviceWorker.ready
            .then(function (registration) { return registration.pushManager.getSubscription(); })
            .then(function (subscription) {
                if (!subscription) return { success: true };
                var endpoint = subscription.endpoint;
                return subscription.unsubscribe().then(function () {
                    return postPush({ action: 'unsubscribe', endpoint: endpoint });
                });
            })
            .catch(function () { return { success: false }; });
    }

    window.tpHrPush = {
        supported: pushSupported,
        enable: enablePush,
        repair: repairPushSubscription,
        disable: disablePush,
        config: pushConfig,
    };

    /**
     * Opt-in card. Only shown inside the installed app: iOS refuses push for a
     * plain Safari tab, so prompting there would just fail.
     */
    function maybeOfferPush() {
        if (!isStandalone || !pushSupported()) return;
        if (Notification.permission !== 'default') return;
        if (document.getElementById('tpPwaPushOptIn')) return;

        pushConfig().then(function (config) {
            if (!config || !config.enabled || config.subscribed) return;

            var card = document.createElement('div');
            card.id = 'tpPwaPushOptIn';
            card.style.cssText = [
                'position:fixed',
                'left:16px',
                'right:16px',
                'bottom:calc(16px + env(safe-area-inset-bottom, 0px))',
                'z-index:9997',
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
            text.textContent = 'เปิดการแจ้งเตือน เพื่อรู้ผลอนุมัติการลาและสลิปเงินเดือนทันที';

            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:10px;margin-top:14px';

            var allow = document.createElement('button');
            allow.type = 'button';
            allow.textContent = 'เปิดการแจ้งเตือน';
            allow.style.cssText = [
                'flex:1',
                'min-height:48px',
                'border:0',
                'border-radius:16px',
                'background:linear-gradient(135deg,#7c3aed,#8b5cf6)',
                'color:#fff',
                'font-family:inherit',
                'font-size:14px',
                'font-weight:600',
                'cursor:pointer',
            ].join(';');
            allow.addEventListener('click', function () {
                allow.disabled = true;
                allow.textContent = 'กำลังเปิด…';
                // config is already in hand, so requestPermission() runs on the
                // tap itself — see enablePush().
                enablePush(config).then(function () { card.remove(); });
            });

            var later = document.createElement('button');
            later.type = 'button';
            later.textContent = 'ไว้ก่อน';
            later.style.cssText = [
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
            later.addEventListener('click', function () { card.remove(); });

            row.appendChild(allow);
            row.appendChild(later);
            card.appendChild(text);
            card.appendChild(row);
            document.body.appendChild(card);
        });
    }

    // ------------------------------------------------------------- kickoff

    registerServiceWorker();
    wireAndroidInstallPrompt();

    function onReady() {
        maybeShowInstallHint();
        // Only on a logged-in page — the meta tag is rendered by templates/header.php.
        if (csrfToken()) {
            window.setTimeout(maybeOfferPush, 4000);
            window.setTimeout(repairPushSubscription, 6000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
}());
