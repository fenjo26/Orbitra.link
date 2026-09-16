/* Orbitra browser matching. No Pixel events are fired. Load after consent where required. */
(function () {
    'use strict';
    var script = document.currentScript;
    var initial = null;
    try { initial = JSON.parse(script && script.getAttribute('data-orbitra-matching') || 'null'); } catch (e) {}
    if (window.OrbitraMatching) {
        if (initial) { window.OrbitraMatching.start(initial); }
        return;
    }
    var identity = null, contexts = {}, active = null;
    function allowed() { return window.orbitra_meta_matching !== false; }
    function read(name) {
        try {
            var found = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
            return found ? decodeURIComponent(found[1]) : null;
        } catch (e) { return null; }
    }
    function opaque(value, max) {
        return typeof value === 'string' && value.length > 0 && value.length <= (max || 8192)
            && !/[\x00-\x20\x7f]/.test(value) ? value : null;
    }
    function metaCookie(value) { return opaque(value) && /^fb\.\d+\.\d{10,13}\..+$/.test(value) ? value : null; }
    function write(name, value) {
        try {
            document.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=7776000; Path=/; SameSite=Lax'
                + (window.location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {}
    }
    function randomIdentity() {
        try {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        } catch (e) { return null; }
    }
    function getExternalId() {
        if (!allowed()) { return null; }
        if (active && active.meta_external_id) { return active.meta_external_id; }
        if (!identity) { identity = opaque(read('orbitra_visitor'), 512) || randomIdentity(); }
        return identity;
    }
    function getContext() {
        if (!allowed()) { return { meta_matching: '0' }; }
        var data = { landing_page_url: String(window.location.href).split('#')[0] };
        var id = getExternalId(), fbp = metaCookie(read('_fbp')), fbc = metaCookie(read('_fbc'));
        if (id) { data.meta_external_id = id; }
        if (fbp) { data.fbp = fbp; }
        if (fbc) { data.fbc = fbc; }
        return data;
    }
    function matches(fbc, fbclid) {
        var suffix = fbc.split('.').slice(3).join('.');
        return suffix === fbclid || suffix.indexOf(fbclid + '.') === 0;
    }
    function capture(config, leaving) {
        if (!allowed()) { return; }
        // The server returns a capability only for a persisted click with
        // cookies enabled. Do not create new cookies before that decision.
        if (config === active && config.meta_external_id) { write('orbitra_visitor', config.meta_external_id); }
        var fbp = metaCookie(read('_fbp'));
        if (!fbp) {
            try {
                var number = new Uint32Array(1);
                window.crypto.getRandomValues(number);
                fbp = 'fb.1.' + Date.now() + '.' + number[0];
                write('_fbp', fbp); // Once per browser, not once per conversion.
                // A blocked cookie jar is not a persistent browser identifier.
                fbp = metaCookie(read('_fbp'));
            } catch (e) {} // No weak/random server-side fallback.
        }
        var fbc = metaCookie(read('_fbc'));
        if (config.fbclid && (!fbc || !matches(fbc, config.fbclid))) {
            fbc = metaCookie(config.fbc);
            if (fbc && config === active) { write('_fbc', fbc); }
        }
        var body = { subid: config.subid, token: config.token,
            landing_page_url: String(window.location.href).split('#')[0] };
        if (fbp) { body.fbp = fbp; }
        if (fbc && (!config.fbclid || matches(fbc, config.fbclid))) { body.fbc = fbc; }
        var serialized = JSON.stringify(body);
        if (config.last === serialized) { return; }
        if (leaving) {
            try {
                // Queue acceptance is NOT a server acknowledgement. Keep this
                // unacknowledged so a later flush can retry a 503/lost request.
                if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, serialized)) { return; }
            } catch (e) {}
        }
        try {
            if (window.fetch) {
                window.fetch(config.endpoint, { method: 'POST', body: serialized, keepalive: true,
                    mode: 'cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
                    .then(function (response) { if (response.ok) { config.last = serialized; } }).catch(function () {});
            }
        } catch (e) {}
    }
    function flush(leaving) { Object.keys(contexts).forEach(function (key) { capture(contexts[key], leaving === true); }); }
    function start(config) {
        if (!allowed() || !config || !config.subid || !config.token || !config.endpoint) { return; }
        if (contexts[config.subid]) { return; }
        active = config;
        contexts[config.subid] = config;
        capture(config);
        // Observe cookies the Pixel creates AFTER the first request; the final
        // flush precedes outbound clicks/navigation, without inventing events.
        [250, 1000, 3000, 10000, 30000, 60000].forEach(function (delay) { setTimeout(flush, delay); });
    }
    window.OrbitraMatching = { getExternalId: getExternalId, getContext: getContext, start: start, flush: flush };
    document.addEventListener('click', function () { flush(true); }, true);
    document.addEventListener('visibilitychange', function () { if (document.hidden) { flush(true); } });
    window.addEventListener('pagehide', function () { flush(true); });
    if (initial) { start(initial); }
})();
