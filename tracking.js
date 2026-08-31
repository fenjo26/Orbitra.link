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

    function boot() { try { registerClick(); } catch (e) { fireReady(); } }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
