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
 */
(function () {
    'use strict';

    if (window.__orbitraKClientLoaded) { return; }
    window.__orbitraKClientLoaded = true;

    var cfg = window.orbitra_db_url || '';
    var token = window.orbitra_campaign_token || '';
    if (!cfg || !token) { return; }
    var base = String(cfg).replace(/\/+$/, '');

    var state = { subid: null, info: null, done: false };

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

    function boot() { try { request(); } catch (e) { /* never break the host page */ } }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
