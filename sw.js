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

// --- Web push: notifications to the operator's own devices -----------------
//
// Notifications → "Push на телефон" (its own page) subscribes this
// worker; delivery is the same RFC 8291 wire format as the landing push
// base, so the payload JSON here carries title/body/icon and data.url for
// the tap-through. data.url may be a bare query ('?tab=conversions') — the
// server cannot know the panel path from a cron context, but this worker
// always does (PANEL_PATH above), so the query resolves against it.

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'Orbitra', body: event.data ? event.data.text() : '' };
    }
    const icon = payload.icon || '/frontend/dist/icons/icon-192.png';
    const options = {
        body: payload.body || '',
        icon,
        badge: payload.badge || icon,
        tag: payload.tag || undefined,
        renotify: !!(payload.renotify && payload.tag),
        data: payload.data || {},
    };
    event.waitUntil(self.registration.showNotification(payload.title || 'Orbitra', options));
});

// Resolve the tap-through target: a bare query joins the panel path, an
// absolute URL stays same-origin only.
const notificationTarget = (raw) => {
    if (raw && raw.startsWith('?')) {
        return PANEL_PATH + raw;
    }
    if (raw) {
        try {
            const url = new URL(raw, self.location.origin);
            if (url.origin === self.location.origin && url.pathname.startsWith('/')) {
                return url.pathname + url.search;
            }
        } catch (e) { /* fall through to the panel path */ }
    }
    return PANEL_PATH;
};

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const path = notificationTarget(event.notification.data && event.notification.data.url);
    event.waitUntil((async () => {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of clients) {
            try {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    // An open panel switches tabs without a reload; the app
                    // listens for this message. A client that predates the
                    // listener (stale bundle) ignores it and just focuses.
                    if (client.postMessage) {
                        client.postMessage({ orbitraNavigate: path });
                    }
                    return client.focus();
                }
            } catch (e) { /* a chrome-extension:// client, skip it */ }
        }
        if (self.clients.openWindow) {
            return self.clients.openWindow(path);
        }
        return undefined;
    })());
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
