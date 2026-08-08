/*!
 * Orbitra landing adapter
 *
 *   <script src="https://your-tracker/js/orbitra-adapter.js"
 *           data-postback="https://your-tracker/POSTBACK_KEY/postback"></script>
 *
 * Put it in <head> on every page of the landing that needs to know which click
 * it belongs to. It does three things:
 *
 *   1. Works out the click id and, on a landing hosted elsewhere, the signed
 *      token that proves it — from the {subid}/{token} macros the tracker
 *      substitutes into a local landing, from the query string the tracker
 *      appends when it sends a visitor to an external landing, or from the
 *      cookies it wrote on a previous page.
 *   2. Carries those onto every link and form on the page, so an inner page of
 *      a multi-page landing still knows the click, and so <a href="/?_lp=1">
 *      works from a landing on another domain.
 *   3. Exposes sendPostback() so a thank-you page can report a conversion
 *      without an affiliate network in the middle.
 *
 * Everything degrades quietly: with no click id the page is left exactly as it
 * was rather than rewritten into something broken.
 */
(function () {
    'use strict';

    var script = document.currentScript ||
        (function () {
            var all = document.getElementsByTagName('script');
            return all[all.length - 1];
        })();

    // The tracker's own origin, so a landing on another domain still points back
    // here rather than at itself.
    var trackerOrigin = '';
    try {
        trackerOrigin = new URL(script.src, window.location.href).origin;
    } catch (e) {
        trackerOrigin = '';
    }
    var postbackUrl = (script && script.getAttribute('data-postback')) || '';

    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]*)');
        var v = m ? decodeURIComponent(m[2]) : null;
        return v && v !== 'undefined' ? v : null;
    }

    function setCookie(name, value, days) {
        if (!value) return;
        var d = new Date();
        d.setTime(d.getTime() + 864e5 * (days || 1));
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';path=/;expires=' + d.toUTCString() + ';SameSite=Lax';
    }

    // A macro the tracker did not substitute still looks like "{subid}"; treat
    // that as absent rather than passing the literal braces around.
    function macro(value) {
        return value && value.indexOf('{') === -1 ? value : null;
    }

    var params = new URLSearchParams(window.location.search);

    var subid = macro('{subid}') ||
        params.get('_subid') || params.get('subid') ||
        getCookie('orbitra_click') || getCookie('subid') || null;

    var token = macro('{token}') ||
        params.get('_token') || params.get('token') ||
        getCookie('orbitra_token') || null;

    if (subid) setCookie('subid', subid, 1);
    if (subid) setCookie('orbitra_click', subid, 1);
    if (token) setCookie('orbitra_token', token, 1);

    /**
     * Report a conversion from the landing itself.
     *
     * <a href="{offer}" onclick="orbitraPostback(this, event, 'lead', 10)">Buy</a>
     *
     * The visitor still goes where the link points: navigation is held only until
     * the postback settles, and it proceeds even if that request fails, because
     * losing the sale to a reporting hiccup is worse than losing the report.
     */
    function orbitraPostback(element, event, status, payout, tid) {
        var href = element && element.getAttribute ? element.getAttribute('href') : null;
        if (event && event.preventDefault) event.preventDefault();

        var go = function () {
            if (href && href !== '#') window.location.href = href;
        };

        if (!postbackUrl || !subid) {
            go();
            return false;
        }

        var url = postbackUrl +
            (postbackUrl.indexOf('?') === -1 ? '?' : '&') +
            'subid=' + encodeURIComponent(subid) +
            '&status=' + encodeURIComponent(status || 'lead') +
            '&payout=' + encodeURIComponent(payout || 0) +
            (tid ? '&tid=' + encodeURIComponent(tid) : '');

        if (navigator.sendBeacon && !href) {
            navigator.sendBeacon(url);
            return false;
        }
        fetch(url, { mode: 'no-cors', keepalive: true }).then(go, go);
        return false;
    }

    window.orbitraPostback = orbitraPostback;
    window.orbitra = {
        subid: subid,
        token: token,
        trackerOrigin: trackerOrigin,
        postback: orbitraPostback
    };

    function decorate() {
        if (!subid && !token) return;

        var carry = {};
        if (subid) carry._subid = subid;
        if (token) carry._token = token;

        Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (link) {
            var raw = link.getAttribute('href');
            // Anchors, mailto:, tel: and javascript: are not navigation we can
            // annotate — rewriting them is how adapters break popup forms.
            if (!raw || raw.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(raw)) return;

            var url;
            try {
                url = new URL(raw, window.location.href);
            } catch (e) {
                return;
            }
            // Only the tracker gets the click identity; other domains have no use
            // for it and no business receiving it.
            var isTracker = trackerOrigin && url.origin === trackerOrigin;
            var isSameSite = url.origin === window.location.origin;
            if (!isTracker && !isSameSite) return;

            Object.keys(carry).forEach(function (k) {
                if (!url.searchParams.has(k)) url.searchParams.set(k, carry[k]);
            });
            link.setAttribute('href', url.toString());
        });

        Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
            Object.keys(carry).forEach(function (k) {
                if (form.querySelector('[name="' + k + '"]')) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = carry[k];
                form.appendChild(input);
            });
        });

        // Hidden inputs a landing author wrote as value="{subid}" by hand.
        Array.prototype.forEach.call(document.querySelectorAll('input[type="hidden"]'), function (input) {
            if (subid && input.value === '{subid}') input.value = subid;
            if (token && input.value === '{token}') input.value = token;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', decorate);
    } else {
        decorate();
    }
})();
