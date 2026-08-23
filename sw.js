/*
 * Orbitra panel service worker.
 *
 * Strategies:
 *  - Panel document (admin.php / the secret admin path): network-first. The
 *    shell is served no-store, so a normal reload always picks up a new
 *    build; the cached copy exists only as an offline fallback for a
 *    standalone PWA launch without a network.
 *  - /frontend/dist/* build output: stale-while-revalidate. Filenames are
 *    content-hashed, so every entry is immutable — a new release means new
 *    URLs, and the old URLs keep working for sessions still on the old shell.
 *  - Everything else (api.php, click.php, landers, postbacks) is not
 *    intercepted at all. This worker only ever runs in an admin's browser,
 *    but it still stays strictly inside the panel's own URLs.
 *
 * New versions do NOT skip waiting: the app shows a "new version available"
 * prompt and swaps the bundle only when the user reloads — never mid-session.
 */

const DIST_PREFIX = '/frontend/dist/';
const HTML_CACHE = 'orbitra-panel-html-v1';
const ASSET_CACHE = 'orbitra-assets-v1';

// The panel entry path (/admin.php or the secret admin path) is carried on
// the registration URL as ?panel=…; the SW script URL persists for the life
// of the registration, so this survives worker restarts. A plain /sw.js
// registration falls back to /admin.php.
const PANEL_PATH = (() => {
    try {
        const p = new URL(self.location.href).searchParams.get('panel');
        return p && p.startsWith('/') ? p : '/admin.php';
    } catch (e) {
        return '/admin.php';
    }
})();

self.addEventListener('install', () => {
    // No precache: hashed assets are filled in at runtime by
    // stale-while-revalidate, and the shell only caches after a live load.
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keep = new Set([HTML_CACHE, ASSET_CACHE]);
        const names = await caches.keys();
        await Promise.all([...names].filter(n => !keep.has(n)).map(n => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

const networkFirstHtml = async (request) => {
    try {
        const response = await fetch(request);
        if (response && response.ok) {
            const cache = await caches.open(HTML_CACHE);
            cache.put(PANEL_PATH, response.clone());
        }
        return response;
    } catch (e) {
        const cached = await caches.match(PANEL_PATH);
        if (cached) return cached;
        return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }
};

const staleWhileRevalidate = async (request) => {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);
    const refresh = fetch(request)
        .then((response) => {
            if (response && response.ok) cache.put(request, response.clone());
            return response;
        })
        .catch(() => undefined);
    return cached || (await refresh) || Response.error();
};

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    let url;
    try {
        url = new URL(request.url);
    } catch (e) {
        return;
    }
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && url.pathname === PANEL_PATH) {
        event.respondWith(networkFirstHtml(request));
        return;
    }
    if (url.pathname.startsWith(DIST_PREFIX)) {
        event.respondWith(staleWhileRevalidate(request));
    }
    // Anything else: no respondWith — the browser handles it directly.
});
