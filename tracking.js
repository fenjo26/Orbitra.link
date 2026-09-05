/*!
 * Orbitra Tracking Script — Keitaro "Скрипт отслеживания" compatible.
 *
 * Put the code from the campaign's Tracking tab between <head> and </head> of
 * your site (hosted elsewhere — for a landing uploaded into the tracker this
 * is not needed, the tracker tracks it natively):
 *
 *   <script>
 *     var orbitra_db_url = 'https://your-tracker.com';
 *     var orbitra_campaign_token = 'CAMPAIGN_TOKEN';
 *   </script>
 *   <script src="https://your-tracker.com/tracking.js"></script>
 *
 * API (Keitaro-compatible):
 *   KTracking.ready(function (subid, token) { … })         — click is registered
 *   KTracking.reportConversion(revenue, 'lead', params, cb)— postback to the tracker
 *   KTracking.update({ sub_id_1: 'value' })                — merge params onto the click
 *
 * The offer link macro: an element with data-orbitra-offer (or an <a> with
 * href="{offer}") gets the stream's offer URL once the click is registered:
 *   <a href="{offer}" data-orbitra-offer>BUY NOW</a>
 */
(function () {
    'use strict';

    if (window.KTracking) { return; }

    var cfg = window.orbitra_db_url || '';
    var token = window.orbitra_campaign_token || '';
    var base = String(cfg).replace(/\/+$/, '');

    var state = { subid: null, offerUrl: null, ready: false, cbs: [], loadTs: Date.now() };

    function readCookie(name) {
        try {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
            return m ? decodeURIComponent(m[1]) : null;
        } catch (e) { return null; }
    }

    function fireReady() {
        state.ready = true;
        state.cbs.forEach(function (cb) {
            try { cb(state.subid, token); } catch (e) { /* user callback */ }
        });
        state.cbs = [];
        applyOfferLinks();
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

    // {offer} macro: any link carrying it (or data-orbitra-offer) points to the
    // stream's offer — via the tracker's signed transition, so the click and its
    // LP CTR are counted on the tracker side.
    function applyOfferLinks() {
        if (!state.offerUrl) { return; }
        var url = withLandingTime(state.offerUrl);
        var links = document.querySelectorAll('a[href="{offer}"], [data-orbitra-offer]');
        for (var i = 0; i < links.length; i++) {
            links[i].setAttribute('href', url);
        }
    }

    function registerClick() {
        if (!base || !token) {
            fireReady();
            return;
        }
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

        var url = base + '/click_api/v3?token=' + encodeURIComponent(token) + '&info=1' + (params.length ? '&' + params.join('&') : '');

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 8000;
        xhr.onload = function () {
            var decoded = null;
            try { decoded = JSON.parse(xhr.responseText); } catch (e) { decoded = null; }
            if (decoded && decoded.info) {
                if (decoded.info.sub_id) { state.subid = String(decoded.info.sub_id); }
                // offer_link is the signed tracker-side transition (continues the
                // click); url is the raw offer template — signed wins.
                if (decoded.info.offer_link) { state.offerUrl = String(decoded.info.offer_link); }
                else if (decoded.info.url) { state.offerUrl = String(decoded.info.url); }
            }
            fireReady();
        };
        xhr.onerror = xhr.ontimeout = function () {
            // CSP or offline: the click may still land via the pixel fallback.
            if (!readCookie('orbitra_subid')) {
                new Image().src = base + '/pixel.gif?token=' + encodeURIComponent(token) + '&js=0';
            }
            fireReady();
        };
        try { xhr.send(null); } catch (e) { fireReady(); }
    }

    function toQueryString(obj) {
        var parts = [];
        Object.keys(obj || {}).forEach(function (key) {
            var value = obj[key];
            if (value === null || value === undefined) { return; }
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
        });
        return parts.join('&');
    }

    window.KTracking = {
        ready: function (cb) {
            if (state.ready) { cb(state.subid, token); }
            else { state.cbs.push(cb); }
        },

        // KTracking.reportConversion(revenue, status [, params [, cb]])
        reportConversion: function (revenue, status, params, cb) {
            var query = {
                action: 'conversion',
                status: status,
                payout: revenue || 0
            };
            if (state.subid) { query.subid = state.subid; }
            if (params && typeof params === 'object') {
                if (params.tid) { query.tid = params.tid; }
                for (var i = 1; i <= 30; i++) {
                    if (params['sub_id_' + i] !== undefined) { query['sub_id_' + i] = params['sub_id_' + i]; }
                }
            }
            var url = base + '/pixel.gif?' + toQueryString(query);
            var img = new Image();
            var fired = false;
            function done() { if (!fired) { fired = true; if (typeof cb === 'function') { cb(); } } }
            img.onload = done;
            img.onerror = done;
            img.src = url;
            setTimeout(done, 3000); // mail-client style safety net
        },

        // Merge parameters (sub_id_1 … sub_id_30) onto the current click.
        update: function (params) {
            if (!state.subid || !params) { return; }
            var query = { action: 'update', subid: state.subid };
            for (var i = 1; i <= 30; i++) {
                if (params['sub_id_' + i] !== undefined) { query['sub_id_' + i] = params['sub_id_' + i]; }
            }
            new Image().src = base + '/pixel.gif?' + toQueryString(query);
        },

        getSubid: function () { return state.subid; }
    };

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

    function boot() { try { initDwell(); } catch (e) {} try { registerClick(); } catch (e) { fireReady(); } }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
