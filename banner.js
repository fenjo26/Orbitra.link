/*!
 * Orbitra Banner Block (script) — Keitaro «Блок баннеров» compatible.
 *
 * Renders the campaign's stream content ("Show as HTML" landing with an
 * <a href="{offer}"> inside) directly into a container on your site:
 *
 *   <div id="orbitra-banner" style="width:300px;height:250px"></div>
 *   <script>
 *     var orbitra_db_url = 'https://your-tracker.com';
 *     var orbitra_campaign_token = 'CAMPAIGN_TOKEN';
 *   </script>
 *   <script src="https://your-tracker.com/banner.js"></script>
 *
 * How it works: the script calls the Click API with the campaign token — the
 * campaign's streams pick the banner (action landing "Show as HTML"), filters
 * included ("Do nothing" on a bot stream hides the block), and the payload's
 * {offer} macro is resolved server-side to a signed transition link, so the
 * banner click continues the banner impression's click. A redirect-type stream
 * result is ignored on purpose: a banner placement must never navigate the page.
 */
(function () {
    'use strict';

    if (window.__orbitraBannerLoaded) { return; }
    window.__orbitraBannerLoaded = true;

    var cfg = window.orbitra_db_url || '';
    var token = window.orbitra_campaign_token || '';
    var base = String(cfg).replace(/\/+$/, '');
    if (!base || !token) { return; }

    function findContainer() {
        var explicit = window.orbitra_banner_container || 'orbitra-banner';
        var el = document.getElementById(explicit);
        if (!el) {
            el = document.getElementById('ltt-banner-container'); // legacy snippet id
        }
        if (!el) {
            el = document.createElement('div');
            el.id = 'orbitra-banner';
            (document.body || document.documentElement).appendChild(el);
        }
        return el;
    }

    function render(body, contentType) {
        var host = findContainer();
        if (String(contentType).indexOf('text/plain') === 0) {
            host.textContent = body;
        } else {
            host.innerHTML = body;
        }
    }

    function boot() {
        var params = [];
        if (document.referrer) { params.push('se_referrer=' + encodeURIComponent(document.referrer)); }
        if (document.title) { params.push('keyword=' + encodeURIComponent(document.title)); }
        window.location.search.replace(/^\?/, '').split('&').forEach(function (pair) {
            if (!pair) { return; }
            var kv = pair.split('=');
            var k = decodeURIComponent(kv[0] || '');
            if (!k || k[0] === '_') { return; }
            params.push(encodeURIComponent(k) + '=' + encodeURIComponent(decodeURIComponent(kv.slice(1).join('=') || '')));
        });

        var url = base + '/click_api/v3?token=' + encodeURIComponent(token) + '&info=1' + (params.length ? '&' + params.join('&') : '');

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 8000;
        xhr.onload = function () {
            var decoded = null;
            try { decoded = JSON.parse(xhr.responseText); } catch (e) { decoded = null; }
            // Only content is ever rendered here; Location is deliberately dropped.
            if (decoded && decoded.body !== null && decoded.body !== undefined) {
                render(decoded.body, decoded.contentType);
            }
        };
        try { xhr.send(null); } catch (e) { /* leave the slot empty */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
