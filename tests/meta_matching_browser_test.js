/* Node's VM supplies a browser fixture; no network and no Pixel events. */
'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert');
const source = name => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');
let checks = 0;
function check(label, assertion) {
    try { assert.ok(assertion, label); checks++; }
    catch (error) { console.error('FAIL ' + label); throw error; }
}
function browser(options = {}) {
    const cookies = Object.assign({}, options.cookies), writes = [], requests = [], beacons = [], timers = [];
    const events = {}, elements = [], xhrRequests = [];
    const location = { href: 'https://landing.example/path?fbclid=FreshCase#fragment', search: '?fbclid=FreshCase', protocol: 'https:' };
    const document = {
        currentScript: { getAttribute: () => options.config ? JSON.stringify(options.config) : null },
        readyState: 'complete', referrer: 'https://ref.example/', title: 'Test page', hidden: false,
        documentElement: { appendChild: element => elements.push(element), scrollHeight: 200, clientHeight: 100 },
        head: { appendChild: element => elements.push(element) }, body: {},
        addEventListener: (event, listener) => (events[event] ||= []).push(listener),
        querySelectorAll: () => [], createElement: () => ({}), getElementById: () => null,
    };
    Object.defineProperty(document, 'cookie', {
        get: () => Object.entries(cookies).map(([key, value]) => key + '=' + encodeURIComponent(value)).join('; '),
        set: value => {
            writes.push(value);
            if (options.blockCookies) { return; }
            const [key, ...rest] = value.split(';')[0].split('=');
            cookies[key] = decodeURIComponent(rest.join('='));
        },
    });
    let responseOk = options.responseOk !== false;
    const window = {
        document, location, crypto: { getRandomValues: array => { array.fill(123); return array; } },
        orbitra_meta_matching: options.disabled ? false : undefined,
        orbitra_db_url: 'https://tracker.example/subpath', orbitra_campaign_token: 'Token',
        addEventListener: document.addEventListener,
        fetch: (url, data) => { requests.push({ url, data }); return Promise.resolve({ ok: responseOk }); },
    };
    window.top = window;
    const navigator = { sendBeacon: (url, data) => { beacons.push({ url, data }); return true; } };
    function XMLHttpRequest() {
        this.open = (method, url) => { this.url = url; xhrRequests.push(url); };
        this.send = () => {
            if (options.xhrFails) { this.onerror(); return; }
            this.responseText = JSON.stringify({ info: { sub_id: 'api-click', matching: config('api-click') }, headers: [] });
            this.onload();
        };
    }
    function Image() { Object.defineProperty(this, 'src', { set: value => requests.push({ image: value }) }); }
    const context = vm.createContext({ window, document, navigator, XMLHttpRequest, Image, Uint8Array, Uint32Array,
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); }, Date, JSON, encodeURIComponent, decodeURIComponent });
    context.fetch = window.fetch;
    return { window, cookies, writes, requests, beacons, timers, events, elements, xhrRequests,
        run: name => vm.runInContext(source(name), context), setResponseOk: value => { responseOk = value; } };
}
function config(subid = 'click-a', extra = {}) {
    return Object.assign({ subid, token: 'fixture-token', endpoint: 'https://tracker.example/pixel.gif?action=matching',
        meta_external_id: 'IdentityCase', fbclid: 'FreshCase', fbc: 'fb.1.1750000000000.FreshCase' }, extra);
}
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    const b = browser({ cookies: { orbitra_visitor: 'StableCase', _fbp: 'fb.1.1750000000000.123', _fbc: 'fb.1.1750000000000.StaleCase' } });
    b.run('meta-matching.js');
    const initial = b.window.OrbitraMatching.getContext();
    check('collects existing matching without changing browser cookies before API authorization', initial.meta_external_id === 'StableCase' && initial.fbp === b.cookies._fbp && b.writes.length === 0);
    check('landing URL is separate and fragmentless', initial.landing_page_url === 'https://landing.example/path?fbclid=FreshCase' && !initial.event_source_url);
    b.window.OrbitraMatching.start(config('click-a', { meta_external_id: 'StableCase' }));
    await tick();
    check('fresh arrival fbc overrides stale browser cookie', b.cookies._fbc === 'fb.1.1750000000000.FreshCase');
    check('already valid fbp is never regenerated', b.cookies._fbp === 'fb.1.1750000000000.123');
    check('updates use acknowledged POST with omitted credentials', b.requests[0].data.method === 'POST' && b.requests[0].data.credentials === 'omit' && b.beacons.length === 0);
    const sent = b.requests.length;
    b.window.OrbitraMatching.flush(); await tick();
    check('unchanged acknowledged context is deduplicated', b.requests.length === sent);
    b.cookies._fbp = 'fb.1.1750000000001.987.Ag';
    b.window.OrbitraMatching.flush(); await tick();
    check('cookie created or replaced later by Pixel is persisted intact', JSON.parse(b.requests.at(-1).data.body).fbp === b.cookies._fbp);
    b.window.OrbitraMatching.start(config('click-b', { fbclid: 'NewerClick', fbc: 'fb.1.1750000000010.NewerClick', meta_external_id: 'StableCase' }));
    await tick();
    b.window.OrbitraMatching.flush(); await tick();
    check('older context cannot overwrite latest browser fbc during flush', b.cookies._fbc === 'fb.1.1750000000010.NewerClick');
    check('no Pixel event is fabricated', b.window.fbq === undefined);

    const generated = browser({ config: config() });
    generated.run('meta-matching.js'); await tick();
    const generatedFbp = generated.cookies._fbp;
    generated.window.OrbitraMatching.flush(); await tick();
    check('actual browser creates a stable fbp once when absent', /^fb\.1\.\d{13}\.\d+$/.test(generatedFbp) && generated.cookies._fbp === generatedFbp);
    check('identity cookie is secure first-party and readable for Pixel setup', generated.writes.some(value => value.startsWith('orbitra_visitor=') && value.includes('SameSite=Lax') && value.includes('Secure') && !value.includes('HttpOnly')));

    const retry = browser({ config: config(), responseOk: false });
    retry.run('meta-matching.js'); await tick();
    retry.window.OrbitraMatching.flush(); await tick();
    check('503 is not remembered as a successful update', retry.requests.length === 2);
    retry.events.pagehide[0]();
    retry.events.pagehide[0]();
    check('beacon queued on navigation is not mistaken for server acknowledgement', retry.beacons.length === 2);
    retry.setResponseOk(true); retry.window.OrbitraMatching.flush(); await tick();
    const success = retry.requests.length;
    retry.window.OrbitraMatching.flush(); await tick();
    check('successful retry settles unchanged context', retry.requests.length === success);

    const optout = browser({ config: config(), disabled: true });
    optout.run('meta-matching.js');
    check('opt-out writes no cookie and sends no context', optout.writes.length === 0 && optout.requests.length === 0 && optout.window.OrbitraMatching.getExternalId() === null);
    check('opt-out explicitly propagates to initial Click API context', optout.window.OrbitraMatching.getContext().meta_matching === '0');

    const blocked = browser({ config: config(), blockCookies: true });
    blocked.run('meta-matching.js'); await tick();
    check('blocked cookies do not send a fabricated rotating fbp', !JSON.parse(blocked.requests[0].data.body).fbp && !blocked.cookies._fbp);
    const fallback = browser({ disabled: true, xhrFails: true });
    fallback.run('tracking.js');
    check('tracking pixel fallback preserves explicit opt-out', new URL(fallback.requests.find(entry => entry.image).image).searchParams.get('meta_matching') === '0');

    for (const client of ['tracking.js', 'kclient.js']) {
        const external = browser({ cookies: { orbitra_visitor: 'BrowserStableCase', _fbp: 'fb.1.1750000000000.42' } });
        external.run('meta-matching.js'); external.run(client); await tick();
        const requested = new URL(external.xhrRequests[0]);
        check(client + ' forwards cookie and stable identity at registration', requested.searchParams.get('fbp') === 'fb.1.1750000000000.42' && requested.searchParams.get('meta_external_id') === 'BrowserStableCase');
        check(client + ' forwards actual external landing URL', requested.searchParams.get('landing_page_url') === 'https://landing.example/path?fbclid=FreshCase');
        check(client + ' late-update endpoint keeps configured tracker base path', external.requests[0].url === 'https://tracker.example/subpath/pixel.gif?action=matching');
        if (client === 'tracking.js') {
            external.window.KTracking.reportConversion(10, 'sale', {});
            const pixel = external.requests.find(entry => entry.image);
            check('reportConversion carries actual invocation URL, not initial acquisition fallback', new URL(pixel.image).searchParams.get('event_source_url') === external.window.location.href);
        }
    }
    console.log('Meta matching browser: ' + checks + '/' + checks + ' passed');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
