/**
 * TP-HR Service Worker
 *
 * Path-agnostic: BASE is derived from where this file is served, so the same
 * worker works at the production document root (hr.tp-asset.com/sw.js) and
 * under a local XAMPP prefix (localhost/tp-hr/sw.js).
 *
 * Caching policy (privacy first — this app renders payroll and HR data):
 *   - HTML / navigations  → network only, offline fallback page. NEVER cached,
 *     so no payslip or employee record is written to CacheStorage, and a
 *     logged-out user can never be served a previous user's page.
 *   - assets/*            → stale-while-revalidate (CSS, icons — no PII).
 *   - api/*, POST, auth   → never touched by the worker at all.
 *
 * Bump CACHE_VERSION whenever the precache list or strategy changes.
 */

const CACHE_VERSION = 'v1';
const ASSET_CACHE = `tp-hr-assets-${CACHE_VERSION}`;

/** Directory this worker was served from, e.g. '/' or '/tp-hr/'. */
const BASE = new URL('./', self.location).pathname;
const OFFLINE_URL = BASE + 'offline.html';

/** Same-origin, non-sensitive shell files that must survive a cold offline start. */
const PRECACHE_URLS = [
    OFFLINE_URL,
    BASE + 'assets/css/app.css',
    BASE + 'assets/css/native-shell.css',
    BASE + 'assets/icons/tphr-app-icon.svg',
    BASE + 'assets/icons/icon-192-v2.png',
    BASE + 'assets/icons/icon-512-v2.png',
    BASE + 'assets/icons/apple-touch-icon-v2.png',
];

/**
 * Paths the worker must stay out of entirely: machine endpoints and large
 * binaries. Auth pages are deliberately NOT here — navigations are never
 * cached, so routing them through the worker only buys the offline card.
 */
const BYPASS_PREFIXES = ['api/', 'cron/', 'scripts/', 'storage/'];
const BYPASS_EXACT = ['webhook.php'];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(ASSET_CACHE);
        // addAll() is atomic — one 404 would abort the whole install, so cache
        // each entry independently and let a missing optional icon slide.
        await Promise.all(PRECACHE_URLS.map(async (url) => {
            try {
                await cache.add(new Request(url, { cache: 'reload' }));
            } catch (err) {
                if (url === OFFLINE_URL) throw err; // the fallback is not optional
            }
        }));
        // No skipWaiting() here on purpose: an update waits until the user taps
        // "อัปเดต" in the toast, which posts SKIP_WAITING (see assets/js/pwa.js).
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names
                .filter((name) => name.startsWith('tp-hr-') && name !== ASSET_CACHE)
                .map((name) => caches.delete(name))
        );
        if (self.registration.navigationPreload) {
            await self.registration.navigationPreload.enable();
        }
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/** @param {URL} url */
function isBypassed(url) {
    if (!url.pathname.startsWith(BASE)) return true;
    const rel = url.pathname.slice(BASE.length);
    if (BYPASS_EXACT.includes(rel)) return true;
    return BYPASS_PREFIXES.some((prefix) => rel.startsWith(prefix));
}

/**
 * Navigations: always hit the network so session state and HR data stay live.
 * On failure serve the offline card — never a stale rendered page.
 */
async function handleNavigate(event) {
    try {
        const preloaded = await event.preloadResponse;
        if (preloaded) return preloaded;
        return await fetch(event.request);
    } catch (err) {
        const cache = await caches.open(ASSET_CACHE);
        const fallback = await cache.match(OFFLINE_URL);
        return fallback || Response.error();
    }
}

/**
 * Drop other cached variants of the same file once a new one lands, so a
 * history of `?v=` bumps doesn't accumulate in storage forever.
 */
async function pruneOtherVariants(cache, request) {
    const url = new URL(request.url);
    const keys = await cache.keys();

    await Promise.all(keys.map((key) => {
        const keyUrl = new URL(key.url);
        if (keyUrl.pathname === url.pathname && keyUrl.search !== url.search) {
            return cache.delete(key);
        }
        return Promise.resolve();
    }));
}

/**
 * Assets: stale-while-revalidate, keyed on the FULL url including `?v=`.
 *
 * Matching with ignoreSearch would be tempting — one cache entry per file —
 * but it silently defeats the repo's cache-bust convention: bumping
 * native-shell.css to ?v=23 would keep serving the cached ?v=22 body on the
 * first load after a deploy, which is exactly what the bump exists to
 * prevent (see DEPLOY_CHECKLIST.md). So a bumped version is a cache miss and
 * goes to the network.
 *
 * ignoreSearch survives only as an offline fallback: an old version beats an
 * unstyled page when the network is gone.
 */
async function handleAsset(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then(async (response) => {
            if (response && response.ok && response.type === 'basic') {
                await cache.put(request, response.clone());
                await pruneOtherVariants(cache, request);
            }
            return response;
        })
        .catch(() => null);

    if (cached) return cached;

    const fresh = await network;
    if (fresh) return fresh;

    // Network is down and this exact version was never cached — any previously
    // cached version of the same file is better than nothing.
    const stale = await cache.match(request, { ignoreSearch: true });
    return stale || Response.error();
}

/**
 * Web Push. iOS delivers these only to an installed (home-screen) PWA on
 * 16.4+; on the desktop and Android it works from the browser too.
 *
 * The payload is written by core/Services/PushService.php, which already
 * clamps the fields and forces `url` to a same-origin path.
 */
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (err) {
        payload = {};
    }

    const title = payload.title || 'TP-HR';
    const options = {
        body: payload.body || '',
        icon: BASE + 'assets/icons/icon-192-v2.png',
        badge: BASE + 'assets/icons/icon-192-v2.png',
        tag: payload.tag || 'tp-hr',
        data: { url: payload.url || BASE },
        renotify: true,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(event.notification.data && event.notification.data.url || BASE, self.location.origin);

    event.waitUntil((async () => {
        const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Reuse the open app window when there is one — opening a second
        // window every time a notification is tapped is jarring on iOS.
        for (const client of clientList) {
            if (new URL(client.url).origin === target.origin && 'focus' in client) {
                await client.focus();
                if ('navigate' in client) {
                    await client.navigate(target.href).catch(() => {});
                }
                return;
            }
        }

        if (self.clients.openWindow) {
            await self.clients.openWindow(target.href);
        }
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;
    if (request.headers.has('range')) return;

    let url;
    try {
        url = new URL(request.url);
    } catch (err) {
        return;
    }

    if (url.origin !== self.location.origin) return;
    if (isBypassed(url)) return;

    if (request.mode === 'navigate') {
        event.respondWith(handleNavigate(event));
        return;
    }

    if (url.pathname.startsWith(BASE + 'assets/')) {
        event.respondWith(handleAsset(request));
    }
});
