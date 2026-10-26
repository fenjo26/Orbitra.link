/*
 * Orbitra browser check — signal collector (core/BrowserCheck.php).
 *
 * Collects a handful of things the browser reports about itself and leaves
 * the verdict to the server. Nothing here identifies a person: no canvas
 * hashing, no fonts, no audio — just the facts that expose virtual machines,
 * emulators and spoofed User-Agents (GPU renderer, platform, touch support,
 * timezone, language, screen).
 *
 * Two ways in:
 *   - inlined by the cloak JS step: OrbitraBC.encode() → the `_ocb` parameter;
 *   - as <script src="/bc.js" data-orbitra-bc='{"subid","k","endpoint"}'> on a
 *     landing: posts the signals once to endpoint?action=bc after load.
 */
(function () {
    var w = window, n = navigator;

    function gpu() {
        try {
            var c = document.createElement('canvas');
            var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
            if (!gl) return '';
            var ext = gl.getExtension('WEBGL_debug_renderer_info');
            var r = ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
            return String(r || '').slice(0, 200);
        } catch (e) { return ''; }
    }

    function collect() {
        var s = {};
        try { s.wd = n.webdriver === true; } catch (e) { /* skip */ }
        try { s.jua = String(n.userAgent || '').slice(0, 400); } catch (e) { /* skip */ }
        try { s.pf = String(n.platform || '').slice(0, 64); } catch (e) { /* skip */ }
        try { if (n.userAgentData) { s.chm = !!n.userAgentData.mobile; s.chp = String(n.userAgentData.platform || '').slice(0, 32); } } catch (e) { /* skip */ }
        try { s.tp = typeof n.maxTouchPoints === 'number' ? n.maxTouchPoints : null; } catch (e) { /* skip */ }
        try { s.ts = ('ontouchstart' in w) || (typeof w.TouchEvent !== 'undefined'); } catch (e) { /* skip */ }
        try { s.tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* skip */ }
        try { s.tzo = new Date().getTimezoneOffset(); } catch (e) { /* skip */ }
        try { s.lng = (n.languages && n.languages.length ? n.languages.slice(0, 3).join(',') : (n.language || '')).slice(0, 64); } catch (e) { /* skip */ }
        try { s.sw = screen.width; s.sh = screen.height; s.dpr = Math.round((w.devicePixelRatio || 1) * 100) / 100; } catch (e) { /* skip */ }
        try { s.ow = w.outerWidth; s.oh = w.outerHeight; } catch (e) { /* skip */ }
        try { s.hc = n.hardwareConcurrency || null; s.dm = n.deviceMemory || null; } catch (e) { /* skip */ }
        s.gl = gpu();
        return s;
    }

    function encode() {
        try {
            var json = JSON.stringify(collect());
            var b = btoa(unescape(encodeURIComponent(json)));
            return b.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        } catch (e) { return ''; }
    }

    w.OrbitraBC = { collect: collect, encode: encode };

    var tag = document.currentScript;
    if (!tag || !tag.getAttribute || !tag.getAttribute('data-orbitra-bc')) return;
    var cfg;
    try { cfg = JSON.parse(tag.getAttribute('data-orbitra-bc')); } catch (e) { return; }
    if (!cfg || !cfg.subid || !cfg.k || !cfg.endpoint || w.__orbitraBcSent) return;
    w.__orbitraBcSent = true;

    function send() {
        var url = String(cfg.endpoint) + '?action=bc&subid=' + encodeURIComponent(cfg.subid) + '&k=' + encodeURIComponent(cfg.k);
        var body = JSON.stringify(collect());
        try {
            if (n.sendBeacon && n.sendBeacon(url, new Blob([body], { type: 'text/plain' }))) return;
        } catch (e) { /* fall through */ }
        try { fetch(url, { method: 'POST', body: body, mode: 'no-cors', keepalive: true, credentials: 'omit' }).catch(function () {}); } catch (e) { /* ignore */ }
    }
    // After load: in-app browsers report their final window size late.
    if (document.readyState === 'complete') setTimeout(send, 800);
    else w.addEventListener('load', function () { setTimeout(send, 800); });
})();
