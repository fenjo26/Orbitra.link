/*!
 * Orbitra KClient JS — Keitaro-compatible client-side integration.
 *
 * Put the code from the campaign's Tracking tab into <head> of a site hosted
 * elsewhere (NOT on a landing uploaded to the tracker):
 *
 *   <script>
 *     var orbitra_db_url = 'https://your-tracker.com';
 *     var orbitra_campaign_token = 'CAMPAIGN_TOKEN';
 *   </script>
 *   <script src="https://your-tracker.com/kclient.js"></script>
 *
 * On every visit it sends the click to the tracker's Click API v3 and carries
 * out the campaign's instructions:
 *   - stream resolved to a redirect → the visitor is redirected
 *   - stream content ("Show as HTML")  → injected into #orbitra-content
 *                                       (or appended to <body> if absent)
 *   - "Do nothing" stream (e.g. bot filter) → the site stays untouched
 *
 * The subid lands in a first-party cookie so secondary pages and thank-you
 * pages can post a conversion back via /tracking.js.
 *
 * Offer links: a link with data-orbitra-offer (or href="{offer}") is pointed
 * at the stream's signed transition once the click is registered — the
 * landing→offer time is then measured on the tracker side.
 */
(function () {
    'use strict';

    if (window.__orbitraKClientLoaded) { return; }
    window.__orbitraKClientLoaded = true;

    var cfg = window.orbitra_db_url || '';
    var token = window.orbitra_campaign_token || '';
    if (!cfg || !token) { return; }
    var base = String(cfg).replace(/\/+$/, '');

    var state = { subid: null, info: null, done: false, loadTs: Date.now() };

    function cookie(name, value, ttlSeconds) {
        try {
            var expires = new Date(Date.now() + ttlSeconds * 1000).toUTCString();
            document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/';
        } catch (e) { /* private mode */ }
    }

    function readCookie(name) {
        try {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
            return m ? decodeURIComponent(m[1]) : null;
        } catch (e) { return null; }
    }

    function collectParams() {
        var params = [];
        var skip = { _subid: 1, _token: 1, _new: 1, _reset: 1 };
        window.location.search.replace(/^\?/, '').split('&').forEach(function (pair) {
            if (!pair) { return; }
            var kv = pair.split('=');
            var k = decodeURIComponent(kv[0] || '');
            if (!k || skip[k]) { return; }
            params.push(encodeURIComponent(k) + '=' + encodeURIComponent(decodeURIComponent(kv.slice(1).join('=') || '')));
        });
        if (document.referrer) { params.push('se_referrer=' + encodeURIComponent(document.referrer)); }
        if (document.title) { params.push('keyword=' + encodeURIComponent(document.title)); }
        return params.join('&');
    }

    function topRedirect(url) {
        try {
            window.top.location.href = url; // site builders often wrap pages in frames
        } catch (e) {
            window.location.href = url;
        }
    }

    function injectBody(body, contentType) {
        var host = document.getElementById('orbitra-content');
        if (!host) {
            host = document.createElement('div');
            host.id = 'orbitra-content';
            (document.body || document.documentElement).appendChild(host);
        }
        if (String(contentType).indexOf('text/plain') === 0) {
            host.textContent = body;
        } else {
            host.innerHTML = body;
        }
    }

    // Landing time for the tracker's Time-since-LP-click metric: appended to
    // the tracker's own transition link only — a raw offer URL would pass the
    // parameter straight through to the affiliate network.
    function withLandingTime(url) {
        if (String(url).indexOf('_lp=1') === -1) { return url; }
        var elapsed = Math.round((Date.now() - state.loadTs) / 1000);
        if (elapsed <= 0) { return url; }
        return url + (String(url).indexOf('?') === -1 ? '?' : '&') + '_lt=' + Math.min(elapsed, 604800);
    }

    // Same offer-link contract as tracking.js: a link with data-orbitra-offer
    // (or href="{offer}") points at the stream's offer through the signed
    // transition, so the LP→offer transition is counted on the tracker side.
    function fillOfferLinks() {
        var info = state.info || {};
        if (!info.offer_link) { return; }
        var url = withLandingTime(String(info.offer_link));
        var links = document.querySelectorAll('a[href="{offer}"], [data-orbitra-offer]');
        for (var i = 0; i < links.length; i++) {
            links[i].setAttribute('href', url);
        }
    }

    function request() {
        var url = base + '/click_api/v3?token=' + encodeURIComponent(token) + '&info=1';
        var extra = collectParams();
        if (extra) { url += '&' + extra; }

        var settled = false;
        function finish() {
            if (!settled) { settled = true; fireReady(); }
        }

        // Image fallback keeps the click flowing even where fetch is blocked
        // (some site builders' CSPs), but no instructions can come back that way.
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 8000;
        xhr.onload = function () {
            var decoded = null;
            try { decoded = JSON.parse(xhr.responseText); } catch (e) { decoded = null; }
            if (decoded) { apply(decoded); }
            finish();
        };
        xhr.onerror = xhr.ontimeout = function () {
            if (!readCookie('orbitra_subid')) {
                // fetch blocked (CSP) — at least log the click through the pixel
                new Image().src = base + '/pixel.gif?token=' + encodeURIComponent(token) + '&js=0' + (extra ? '&' + extra : '');
            }
            finish();
        };
        try { xhr.send(null); } catch (e) { finish(); }
    }

    function apply(decoded) {
        state.done = true;
        var info = decoded.info || {};
        state.info = info;
        if (info.sub_id) {
            state.subid = String(info.sub_id);
            cookie('orbitra_subid', state.subid, 86400);
        }

        var location = null;
        (decoded.headers || []).forEach(function (header) {
            if (!location && String(header).toLowerCase().indexOf('location:') === 0) {
                location = String(header).substr(9).trim();
            }
        });

        if (location) {
            topRedirect(location);
            return;
        }
        if (decoded.body !== null && decoded.body !== undefined) {
            injectBody(decoded.body, decoded.contentType);
        }
        // After content injection so links inside the injected body are filled too.
        fillOfferLinks();
        // Neither → "Do nothing": leave the site as is.
    }

    // Minimal public surface for thank-you pages (see /tracking.js for the
    // full conversion API — include that script instead when you only track).
    window.KClient = {
        getSubid: function () { return state.subid; },
        getInfo: function () { return state.info; },
        ready: function (cb) {
            if (state.done || state.subid) { cb(state.subid, token); }
            else { (window.KClient._cbs = window.KClient._cbs || []).push(cb); }
        }
    };

    function fireReady() {
        ((window.KClient && window.KClient._cbs) || []).forEach(function (cb) {
            try { cb(state.subid, token); } catch (e) { /* user callback */ }
        });
        window.KClient._cbs = [];
    }


    // === Time on the landing, for every visitor ===
    // The offer-link _lt only measures visitors who clicked through, so a page
    // that bores everyone away reports nothing — precisely the page you need to
    // find. This heartbeats to /pixel.gif?action=lp while the page is open and
    // flushes once more as it goes away, so a bounce leaves a number too.
    // Only VISIBLE time accumulates: a tab parked in the background all morning
    // is not engagement. The endpoint stores MAX(), so duplicate and
    // out-of-order beacons are harmless.
    var dwell = { ms: 0, mark: state.loadTs, off: document.hidden === true, depth: 0, sent: -1, sentDepth: -1 };

    function dwellAcc() {
        var now = Date.now();
        if (!dwell.off) { dwell.ms += now - dwell.mark; }
        dwell.mark = now;
    }

    function dwellDeep() {
        try {
            var d = document.documentElement, b = document.body || {};
            var h = Math.max(d.scrollHeight || 0, b.scrollHeight || 0, d.offsetHeight || 0);
            var v = window.innerHeight || d.clientHeight || 0;
            var y = window.pageYOffset || d.scrollTop || 0;
            var p = h > v ? Math.round(((y + v) / h) * 100) : 100;
            if (p > dwell.depth) { dwell.depth = p < 0 ? 0 : (p > 100 ? 100 : p); }
        } catch (e) { /* exotic document */ }
    }

    function dwellSend() {
        var subid = state.subid || readCookie('orbitra_subid');
        if (!subid) { return; }   // nothing to attribute the time to yet
        dwellAcc();
        var t = Math.round(dwell.ms / 1000);
        // Only a CHANGED reading is worth a request: pagehide, beforeunload and
        // visibilitychange all fire on the same exit, and three identical
        // beacons tell the tracker nothing it does not already have.
        if (t <= dwell.sent && dwell.depth <= dwell.sentDepth) { return; }
        if (t < dwell.sent) { t = dwell.sent; }
        dwell.sent = t;
        dwell.sentDepth = dwell.depth;
        var url = base + '/pixel.gif?action=lp&subid=' + encodeURIComponent(subid)
            + '&t=' + t + '&s=' + dwell.depth + '&_=' + Date.now();
        // sendBeacon, not Image(): the last flush races the navigation to the
        // offer, and a request that has not left the browser dies with the page.
        try { if (navigator.sendBeacon && navigator.sendBeacon(url)) { return; } } catch (e) {}
        try { if (window.fetch) { fetch(url, { keepalive: true, mode: 'no-cors' }).catch(function () {}); return; } } catch (e) {}
        var img = new Image();
        img.src = url;
    }

    function initDwell() {
        document.addEventListener('visibilitychange', function () {
            dwellAcc();
            dwell.off = document.hidden === true;
            dwell.mark = Date.now();
            if (dwell.off) { dwellDeep(); dwellSend(); }
        });
        window.addEventListener('pagehide', function () { dwellDeep(); dwellSend(); });
        window.addEventListener('beforeunload', function () { dwellDeep(); dwellSend(); });
        try { window.addEventListener('scroll', dwellDeep, { passive: true }); }
        catch (e) { window.addEventListener('scroll', dwellDeep); }
        dwellDeep();
        // 5s / 15s / 30s / 1m, then every minute: a visitor who kills the
        // browser still leaves the last checkpoint behind.
        var steps = [5, 15, 30, 60], step = 0;
        (function next() {
            var prev = step > 0 ? steps[step - 1] : 0;
            var wait = step < steps.length ? (steps[step] - prev) : 60;
            setTimeout(function () { step++; dwellDeep(); dwellSend(); next(); }, wait * 1000);
        })();
    }

    function boot() { try { initDwell(); } catch (e) {} try { request(); } catch (e) { /* never break the host page */ } }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
