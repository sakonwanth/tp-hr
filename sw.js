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

/** Assets: serve the cached copy instantly, refresh in the background. */
async function handleAsset(request) {
    const cache = await caches.open(ASSET_CACHE);
    // ignoreSearch so the `?v=` cache-buster on native-shell.css still matches.
    const cached = await cache.match(request, { ignoreSearch: true });

    const network = fetch(request)
        .then((response) => {
            if (response && response.ok && response.type === 'basic') {
                cache.put(request, response.clone());
            }
            return response;
        })
        .catch(() => null);

    if (cached) return cached;

    const fresh = await network;
    return fresh || Response.error();
}

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
