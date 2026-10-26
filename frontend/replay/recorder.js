// Orbitra session recorder — the source of /replay.js.
//
// Build: `npm run build:replay` (frontend/vite.replay.config.js) writes the
// bundled, minified file to the repo root as replay.js. The built file is
// committed: servers update from a GitHub zip and have no Node.
//
// The tracker injects this script ONLY for clicks it decided to record
// (core/SessionReplay.php → orbitraReplayArm): landing switched on, sample
// hit, not a bot, not the safe page. Its config rides the tag:
//   <script src="/replay.js" data-orbitra-replay='{"subid":…,"k":…,"endpoint":…,"maxSec":…}'>
//
// Privacy: every form field is masked in the browser (the typed value never
// leaves the device), password fields always; an element with the class
// "orbitra-no-record" is blocked entirely (shown as an empty box), one with
// "orbitra-mask" has its text masked.
import { record } from '@rrweb/record';

(function () {
    var w = window;
    if (w.__orbitraReplay) return;
    var tag = document.currentScript || document.querySelector('script[data-orbitra-replay]');
    if (!tag) return;
    var cfg;
    try { cfg = JSON.parse(tag.getAttribute('data-orbitra-replay') || '{}'); } catch { return; }
    if (!cfg || !cfg.subid || !cfg.k || !cfg.endpoint) return;
    w.__orbitraReplay = true;

    var url = String(cfg.endpoint) + '?action=rr&subid=' + encodeURIComponent(cfg.subid) + '&k=' + encodeURIComponent(cfg.k);
    var maxMs = Math.max(60, Number(cfg.maxSec) || 1800) * 1000;
    var started = Date.now();
    var events = [];
    var clicks = [];
    var seq = 0;
    var stopFn = null;
    var stopped = false;
    var lastClick = null;
    var BEACON_MAX = 60000; // sendBeacon / keepalive bodies are capped at 64 KB

    function post(body, gz, keepalive) {
        seq++;
        var u = url + '&n=' + seq + (gz ? '&z=1' : '');
        try {
            if (keepalive && navigator.sendBeacon) {
                if (navigator.sendBeacon(u, body)) return;
            }
        } catch { /* fall through */ }
        try {
            fetch(u, { method: 'POST', body: body, mode: 'no-cors', keepalive: !!keepalive, credentials: 'omit' })
                .catch(function () {});
        } catch { /* the visit goes on */ }
    }

    // Normal batches: gzip in the browser when it can (5-10x smaller uploads
    // on mobile data), plain JSON otherwise.
    function sendBatch(json) {
        if (typeof w.CompressionStream === 'function' && typeof w.Response === 'function') {
            try {
                var stream = new Blob([json]).stream().pipeThrough(new w.CompressionStream('gzip'));
                new w.Response(stream).blob().then(function (blob) {
                    post(blob, true, blob.size < BEACON_MAX);
                }, function () { post(new Blob([json], { type: 'text/plain' }), false, false); });
                return;
            } catch { /* plain below */ }
        }
        post(new Blob([json], { type: 'text/plain' }), false, false);
    }

    // The exit flush cannot wait for async compression: the page is going
    // away. Plain JSON, split into beacon-sized pieces.
    function sendFinal(evs, cls) {
        var cur = [];
        var size = 0;
        function emit() {
            if (!cur.length && !cls.length) return;
            post(new Blob([JSON.stringify({ e: cur, c: cls })], { type: 'text/plain' }), false, true);
            cls = [];
            cur = [];
            size = 0;
        }
        for (var i = 0; i < evs.length; i++) {
            var s = JSON.stringify(evs[i]).length;
            if (size + s > BEACON_MAX - 2000 && cur.length) emit();
            cur.push(evs[i]);
            size += s;
        }
        emit();
    }

    function flush(final) {
        if (!events.length && !clicks.length) return;
        var evs = events;
        var cls = clicks;
        events = [];
        clicks = [];
        if (final) {
            sendFinal(evs, cls);
        } else {
            sendBatch(JSON.stringify({ e: evs, c: cls }));
        }
    }

    function stop() {
        if (stopped) return;
        stopped = true;
        try { if (stopFn) stopFn(); } catch { /* ignore */ }
        flush(true);
    }

    // Short CSS path for the heatmap tooltip ("button.cta", "#order > a").
    function selector(el) {
        try {
            var parts = [];
            for (var i = 0; el && el.nodeType === 1 && i < 3; i++) {
                var p = el.tagName.toLowerCase();
                if (el.id) { parts.unshift(p + '#' + el.id); break; }
                var cls = (typeof el.className === 'string' ? el.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
                if (cls.length) p += '.' + cls.join('.');
                parts.unshift(p);
                el = el.parentElement;
            }
            return parts.join(' > ').slice(0, 160);
        } catch { return ''; }
    }

    // Heatmap point: x as a share of the page width (layouts reflow with the
    // viewport), y in page pixels. Three clicks within 700 ms and 40 px of
    // each other are a "rage click" — the visitor hammering something that
    // does not respond.
    function onClick(ev) {
        try {
            var d = document.documentElement;
            var b = document.body || d;
            var docW = Math.max(d.scrollWidth, b.scrollWidth, d.clientWidth) || 1;
            var docH = Math.max(d.scrollHeight, b.scrollHeight, d.clientHeight) || 1;
            var px = ev.pageX;
            var py = ev.pageY;
            if (typeof px !== 'number' || typeof py !== 'number') return;
            var now = Date.now();
            var rage = 0;
            if (lastClick && now - lastClick.t < 700 && Math.abs(px - lastClick.x) < 40 && Math.abs(py - lastClick.y) < 40) {
                lastClick.n++;
                lastClick.t = now;
                if (lastClick.n === 3) rage = 1;
            } else {
                lastClick = { t: now, x: px, y: py, n: 1 };
            }
            if (clicks.length < 300) {
                clicks.push({ x: Math.round((px / docW) * 10000) / 10000, y: Math.round(py), w: docW, h: docH, r: rage, s: selector(ev.target) });
            }
        } catch { /* ignore */ }
    }

    try {
        stopFn = record({
            emit: function (event) {
                if (stopped) return;
                events.push(event);
                if (Date.now() - started > maxMs) { stop(); return; }
                if (events.length >= 400) flush(false);
            },
            maskAllInputs: true,
            maskInputOptions: { password: true },
            blockClass: 'orbitra-no-record',
            maskTextClass: 'orbitra-mask',
            recordCanvas: false,
            collectFonts: false,
            inlineImages: false,
            inlineStylesheet: true,
            slimDOMOptions: 'all',
            sampling: { mousemove: 50, scroll: 150, media: 800, input: 'last' }
        });
    } catch {
        return;
    }

    document.addEventListener('click', onClick, { capture: true, passive: true });
    setInterval(function () { if (!stopped) flush(false); }, 5000);
    document.addEventListener('visibilitychange', function () { if (document.hidden) flush(true); });
    w.addEventListener('pagehide', function () { flush(true); });
    w.addEventListener('beforeunload', function () { flush(true); });
})();
