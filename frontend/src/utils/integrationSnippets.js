/**
 * Campaign-level integration snippet builders — pure functions, no React.
 *
 * Single source of truth for the code the campaign editor's Tracking tab shows,
 * so the pieces can never drift apart. Every builder takes
 *   { trackerUrl, campaign }  where campaign = { id, alias, token, url }
 * (url = the campaign link WITH the traffic-source macros already appended)
 * and an optional per-method options object, and returns a code string.
 */

const cid = () => (window.crypto && crypto.randomUUID)
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2) + Date.now().toString(36);

/** Keitaro-style pass-through of the page's referrer, title and query string. */
const pagePassthrough = () =>
    `' + encodeURIComponent(document.referrer) + '&default_keyword=' + encodeURIComponent(document.title) + '&'+window.location.search.replace('?', '&')`;

export const INTEGRATION_METHODS = [
    { id: 'kclient_js', group: 'site' },
    { id: 'kclient_php', group: 'site' },
    { id: 'tracking_script', group: 'site' },
    { id: 'banner_script', group: 'banner' },
    { id: 'banner_iframe', group: 'banner' },
    { id: 'campaign_url', group: 'ads' },
    { id: 'link', group: 'ads' },
    { id: 'iframe', group: 'ads' },
    { id: 'script', group: 'ads' },
    { id: 'pixel', group: 'misc' },
    { id: 'countdown', group: 'misc' },
    { id: 'back_button', group: 'misc' },
    { id: 'exit_intent', group: 'misc' },
    { id: 'wordpress', group: 'misc' },
];

export function buildSnippet(methodId, ctx, opts = {}) {
    switch (methodId) {
        case 'kclient_js': return kclientJs(ctx, opts);
        case 'kclient_php': return kclientPhp(ctx, opts);
        case 'tracking_script': return trackingScript(ctx, opts);
        case 'banner_script': return bannerScript(ctx, opts);
        case 'banner_iframe': return bannerIframe(ctx, opts);
        case 'campaign_url': return ctx.campaign.url || '';
        case 'link': return linkSnippet(ctx, opts);
        case 'iframe': return iframeSnippet(ctx, opts);
        case 'script': return scriptSnippet(ctx, opts);
        case 'pixel': return pixelSnippet(ctx, opts);
        case 'countdown': return countdownSnippet(ctx, opts);
        case 'back_button': return backButtonSnippet(ctx, opts);
        case 'exit_intent': return exitIntentSnippet(ctx, opts);
        case 'wordpress': return wordpressSnippet(ctx, opts);
        default: return '';
    }
}

/** Keitaro's base64 obfuscation option: eval(atob(...)) hides tracker keywords
 *  from ad-blocker heuristics. Returns the original when btoa is unavailable. */
export function obfuscateBase64(code) {
    try {
        // btoa chokes on non-ASCII; tracker URLs and hex tokens are ASCII-only.
        return `<script type="application/javascript">eval(atob('${window.btoa(code)}'));</script>`;
    } catch (e) {
        return code;
    }
}

// ————————————————————————————————————————————————————————————————
// Site integrations (KClient family)
// ————————————————————————————————————————————————————————————————

function kclientJs({ trackerUrl, campaign }, opts = {}) {
    let code = `<!-- Orbitra KClient JS — в <head> сайта на стороннем хосте -->\n<script>\n  var orbitra_db_url = '${trackerUrl}';\n  var orbitra_campaign_token = '${campaign.token}';\n</script>\n<script src="${trackerUrl}/kclient.js"></script>\n<noscript><img src="${trackerUrl}/pixel.gif?token=${campaign.token}" alt="" /></noscript>`;
    if (opts.base64) {
        code = obfuscateBase64(code.replace(/<!--.*?-->\n?/gs, ''));
    }
    return code;
}

function kclientPhp({ trackerUrl, campaign }) {
    return `<?php\n// Первые строки index.php сайта, до DOCTYPE.\n// Файл kclient.php скачайте кнопкой выше и положите рядом с index.php.\nrequire_once dirname(__FILE__) . '/kclient.php';\n\n$client = new KClickClient('${trackerUrl}', '${campaign.token}');\n$client->sendAllParams();\n$client->execute();\n// $client->executeAndBreak(); — останавливать страницу при редиректе\n// echo $client->getContent();   — HTML из потока («Показать как HTML»)\n// echo $client->getOffer();     — ссылка на оффер для своей кнопки\n`;
}

function trackingScript({ trackerUrl, campaign }) {
    return `<!-- Скрипт отслеживания — в <head> каждой страницы сайта -->\n<script>\n  var orbitra_db_url = '${trackerUrl}';\n  var orbitra_campaign_token = '${campaign.token}';\n</script>\n<script src="${trackerUrl}/tracking.js"></script>\n\n<!-- Страница «Спасибо» / отправка конверсии: -->\n<script>\n  var revenue = 0;\n  var status = 'lead';\n  var tid = Math.floor(Math.random() * 1000000000);\n  KTracking.reportConversion(revenue, status, { tid: tid });\n</script>\n\n<!-- Кнопка на оффер (ссылку подставит скрипт): -->\n<!-- <a href="{offer}" data-orbitra-offer>BUY NOW</a> -->`;
}

// ————————————————————————————————————————————————————————————————
// Banner blocks
// ————————————————————————————————————————————————————————————————

function bannerScript({ trackerUrl, campaign }, opts = {}) {
    const w = opts.width || 300;
    const h = opts.height || 250;
    return `<!-- Блок баннеров (script): контент потока «Показать как HTML» -->\n<div id="orbitra-banner" style="width:${w}px;height:${h}px;overflow:hidden"></div>\n<script>\n  var orbitra_db_url = '${trackerUrl}';\n  var orbitra_campaign_token = '${campaign.token}';\n</script>\n<script src="${trackerUrl}/banner.js"></script>`;
}

function bannerIframe({ trackerUrl, campaign }, opts = {}) {
    const w = opts.width || 300;
    const h = opts.height || 250;
    return `<!-- Блок баннеров (iframe): кампания во фрейме, совместим с RTB-кодами -->\n<iframe src="${trackerUrl}/${campaign.alias}" width="${w}" height="${h}" frameborder="0" scrolling="no" marginwidth="0" marginheight="0"></iframe>`;
}

// ————————————————————————————————————————————————————————————————
// Ad-network snippets
// ————————————————————————————————————————————————————————————————

function linkSnippet({ campaign }) {
    const id = cid();
    return `<span id="${id}"></span>\n<script type="application/javascript">\ndocument.getElementById('${id}').innerHTML = '<a href="${campaign.url}?se_referrer=${pagePassthrough()}">Link</a>';\n</script>`;
}

function iframeSnippet({ campaign }) {
    const id = cid();
    return `<div id="${id}"></div>\n<script type="application/javascript">\ndocument.getElementById('${id}').innerHTML = '<iframe sandbox="allow-top-navigation allow-scripts allow-popups allow-forms" frameborder="0" width="100%" height="100%" src="${campaign.url}?se_referrer=${pagePassthrough()}&frm=frame"></iframe>';\n</script>`;
}

function scriptSnippet({ campaign }) {
    const id = cid();
    return `<span id="${id}"></span><script type="application/javascript">\nvar d=document;var s=d.createElement('script');\ns.src='${campaign.url}?se_referrer=${pagePassthrough()}&frm=script&_cid=${id}';\nif (document.currentScript) { document.currentScript.parentNode.insertBefore(s, document.currentScript); } else { d.getElementsByTagName('head')[0].appendChild(s); }\n</script>`;
}

// ————————————————————————————————————————————————————————————————
// Misc widgets
// ————————————————————————————————————————————————————————————————

function pixelSnippet({ trackerUrl, campaign }) {
    return `<!-- Пиксель (клики, email-рассылки): -->\n<img src="${trackerUrl}/pixel.gif?campaign_id=${campaign.id}" width="1" height="1" border="0" alt="" />\n\n<!-- Конверсия на странице «Спасибо» (subid со страницы/формы): -->\n<img src="${trackerUrl}/pixel.gif?action=conversion&subid={subid}&status=lead" width="1" height="1" border="0" alt="" />`;
}

function countdownSnippet({ trackerUrl, campaign }, opts = {}) {
    const hours = opts.hours || 2;
    const offerUrl = opts.offerUrl || 'https://your-offer.com';
    return `<div id="ltt-countdown" style="font-family:sans-serif;text-align:center;padding:20px;\n    background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border-radius:12px;max-width:400px;\n    margin:0 auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">\n    <div style="font-size:14px;text-transform:uppercase;letter-spacing:2px;margin-bottom:10px;">\n        OFFER EXPIRES IN\n    </div>\n    <div id="ltt-timer" style="font-size:48px;font-weight:bold;">\n        <span id="ltt-hours">00</span>:<span id="ltt-minutes">00</span>:<span id="ltt-seconds">00</span>\n    </div>\n    <a id="ltt-cta" href="#" style="display:inline-block;margin-top:20px;padding:14px 40px;\n        background:#22c55e;color:white;text-decoration:none;border-radius:8px;font-weight:600;\n        font-size:16px;transition:transform 0.2s;">\n        GET OFFER NOW\n    </a>\n</div>\n\n<script>\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = '${campaign.id}';\n    var redirectUrl = '${offerUrl}';\n    var hoursFromNow = ${hours};\n\n    var endTime = new Date().getTime() + (hoursFromNow * 60 * 60 * 1000);\n\n    document.getElementById('ltt-cta').href = trackerUrl + '/click.php?campaign_id=' + campaignId + '&url=' + encodeURIComponent(redirectUrl);\n\n    function updateTimer() {\n        var distance = endTime - new Date().getTime();\n        if (distance < 0) {\n            document.getElementById('ltt-countdown').innerHTML = '<h2>OFFER EXPIRED</h2>';\n            return;\n        }\n        document.getElementById('ltt-hours').textContent = String(Math.floor(distance / 3600000)).padStart(2, '0');\n        document.getElementById('ltt-minutes').textContent = String(Math.floor((distance % 3600000) / 60000)).padStart(2, '0');\n        document.getElementById('ltt-seconds').textContent = String(Math.floor((distance % 60000) / 1000)).padStart(2, '0');\n    }\n\n    updateTimer();\n    setInterval(updateTimer, 1000);\n})();\n</script>`;
}

function backButtonSnippet({ trackerUrl, campaign }, opts = {}) {
    const trapUrl = opts.trapUrl || 'https://your-special-offer.com';
    return `<script>\n// Back Button Trap — перехват кнопки «назад» браузера\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = '${campaign.id}';\n    var trapUrl = '${trapUrl}';\n\n    history.pushState({ trap: true }, '', location.href);\n\n    window.addEventListener('popstate', function(e) {\n        if (e.state && e.state.trap) {\n            var clickUrl = trackerUrl + '/click.php?campaign_id=' + campaignId + '&sub1=back_button&redirect=0';\n            fetch(clickUrl).finally(function() {\n                window.location.href = trapUrl;\n            });\n        }\n    });\n})();\n</script>`;
}

function exitIntentSnippet({ trackerUrl, campaign }, opts = {}) {
    const offerUrl = opts.offerUrl || 'https://your-offer.com';
    const heading = opts.heading || 'Wait! Special Offer!';
    const text = opts.text || "Don't miss this exclusive deal just for you!";
    return `<style>\n.ltt-exit-popup { display:none; position:fixed; top:0; left:0; width:100%; height:100%;\n    background:rgba(0,0,0,0.7); z-index:99999; justify-content:center; align-items:center; }\n.ltt-exit-popup.show { display:flex; }\n.ltt-exit-content { background:white; padding:40px; border-radius:16px; max-width:500px;\n    text-align:center; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.3); }\n.ltt-exit-close { position:absolute; top:15px; right:20px; font-size:24px; cursor:pointer;\n    color:#999; border:none; background:none; }\n.ltt-exit-btn { display:inline-block; margin-top:20px; padding:16px 40px; background:#22c55e;\n    color:white; text-decoration:none; border-radius:8px; font-weight:600; font-size:18px; }\n</style>\n\n<div id="ltt-exit-popup" class="ltt-exit-popup">\n    <div class="ltt-exit-content">\n        <button class="ltt-exit-close" onclick="document.getElementById('ltt-exit-popup').classList.remove('show')">&times;</button>\n        <h2 style="margin:0 0 15px;font-size:28px;">${heading}</h2>\n        <p style="font-size:16px;color:#666;margin-bottom:10px;">${text}</p>\n        <a id="ltt-exit-cta" href="#" class="ltt-exit-btn">CLAIM OFFER</a>\n    </div>\n</div>\n\n<script>\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = '${campaign.id}';\n    var offerUrl = '${offerUrl}';\n    var shown = false;\n\n    document.getElementById('ltt-exit-cta').href = trackerUrl + '/click.php?campaign_id=' + campaignId + '&url=' + encodeURIComponent(offerUrl);\n\n    document.addEventListener('mouseout', function(e) {\n        if (shown) return;\n        if (e.clientY < 10 && e.relatedTarget === null) {\n            shown = true;\n            document.getElementById('ltt-exit-popup').classList.add('show');\n            fetch(trackerUrl + '/click.php?campaign_id=' + campaignId + '&sub1=exit_popup&redirect=0');\n        }\n    });\n})();\n</script>`;
}

function wordpressSnippet({ campaign }) {
    return `<!-- WordPress: шорткод трекера (плагин Orbitra) -->\n[orbitra_link campaign_id="${campaign.id}" text="Click Here"]\n\n<!-- Несколько офферов / гео-редирект: -->\n[orbitra_link campaign_id="${campaign.id}" text="Buy" text_ru="Купить" geo_redirect="RU:https://offer1.com,DE:https://offer2.com"]\n\n<!-- Конверсия со страницы «Спасибо» (Contact Form 7 и т.п.): -->\n[send_postback]`;
}
