<?php
/**
 * Campaign-level integration snippet builders — the PHP mirror of
 * frontend/src/utils/integrationSnippets.js.
 *
 * The panel generates code in the browser from the JS file; api.php's
 * integration_code action (and through it, the MCP tools) generates it here.
 * Two generators in two languages WILL drift unless something forces them not
 * to: tests/integration_snippets_parity_test.php runs both over the same
 * battery of methods and options and fails on any difference. Change one side,
 * run the parity test, change the other.
 *
 * Every builder takes {tracker_url, campaign} where campaign =
 * {id, alias, token, url} (url = the campaign link WITH the traffic-source
 * macros already appended) and an optional per-method options array, and
 * returns a code string.
 */

/** Countdown visual themes — mirror of COUNTDOWN_THEMES. */
const ORBITRA_COUNTDOWN_THEMES = [
    'purple'  => ['gradient' => 'linear-gradient(135deg,#667eea 0%,#764ba2 100%)', 'cta' => '#22c55e'],
    'emerald' => ['gradient' => 'linear-gradient(135deg,#10b981 0%,#065f46 100%)', 'cta' => '#fbbf24'],
    'fire'    => ['gradient' => 'linear-gradient(135deg,#f97316 0%,#dc2626 100%)', 'cta' => '#111827'],
    'dark'    => ['gradient' => 'linear-gradient(135deg,#374151 0%,#111827 100%)', 'cta' => '#f05a3e'],
];

/** Every method id buildIntegrationSnippet() accepts (mirror of INTEGRATION_METHODS,
 *  plus the KClient PHP companion snippets the panel shows alongside kclient_php). */
function orbitraIntegrationMethods(): array
{
    return [
        'kclient_js', 'kclient_php', 'kclient_php_secondary', 'kclient_php_offer_link',
        'kclient_php_get_offer', 'tracking_script', 'banner_script', 'banner_iframe',
        'campaign_url', 'link', 'iframe', 'script', 'pixel', 'countdown',
        'back_button', 'exit_intent', 'wordpress',
    ];
}

/** The panel's option names are camelCase, this file's are snake_case (they
 *  read more naturally in a PHP API query string). Accept both. */
function orbitraIntegrationNormalizeOptions(array $opts): array
{
    $out = [];
    foreach ($opts as $k => $v) {
        $snake = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $k));
        if (!array_key_exists($snake, $out)) {
            $out[$snake] = $v;
        }
    }
    return $out;
}

/** Random DOM id for the snippets that need one (mirror of cid()). */
function orbitraSnippetCid(): string
{
    return 'o' . bin2hex(random_bytes(8));
}

/** Escapes text destined for a double-quoted HTML attribute or innerHTML. */
function orbitraSnippetEsc($s): string
{
    return str_replace(
        ['&', '<', '>', '"', "'"],
        ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'],
        (string) ($s ?? '')
    );
}

/** JSON.stringify() semantics for a scalar (no escaped slashes / unicode). */
function orbitraSnippetJson($v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Keitaro-style pass-through of the page's referrer, title and query string.
 *  Emits a JS expression fragment; the trailing ` + '` re-opens the string
 *  literal so callers can continue with their own query params / markup. */
function orbitraPagePassthrough(): string
{
    return '\' + encodeURIComponent(document.referrer) + \'&default_keyword=\' + encodeURIComponent(document.title) + \'&\'+window.location.search.replace(\'?\', \'&\') + \'';
}

function orbitraBuildSnippet(string $methodId, array $ctx, array $opts = []): string
{
    $campaign = $ctx['campaign'] ?? [];
    switch ($methodId) {
        case 'kclient_js': return orbitraKclientJs($ctx, $opts);
        case 'kclient_php': return orbitraKclientPhp($ctx, $opts);
        case 'kclient_php_secondary': return orbitraKclientPhpSecondary($ctx);
        case 'kclient_php_offer_link': return orbitraKclientPhpOfferLink();
        case 'kclient_php_get_offer': return orbitraKclientPhpGetOffer();
        case 'tracking_script': return orbitraTrackingScript($ctx);
        case 'banner_script': return orbitraBannerScript($ctx, $opts);
        case 'banner_iframe': return orbitraBannerIframe($ctx, $opts);
        case 'campaign_url': return (string) ($campaign['url'] ?? '');
        case 'link': return orbitraLinkSnippet($ctx);
        case 'iframe': return orbitraIframeSnippet($ctx);
        case 'script': return orbitraScriptSnippet($ctx);
        case 'pixel': return orbitraPixelSnippet($ctx, $opts);
        case 'countdown': return orbitraCountdownSnippet($ctx, $opts);
        case 'back_button': return orbitraBackButtonSnippet($ctx, $opts);
        case 'exit_intent': return orbitraExitIntentSnippet($ctx, $opts);
        case 'wordpress': return orbitraWordpressSnippet($ctx);
        default: return '';
    }
}

/** Keitaro's base64 obfuscation option (mirror of obfuscateBase64). */
function orbitraObfuscateBase64(string $code): string
{
    return '<script type="application/javascript">eval(atob(\'' . base64_encode($code) . "'));</script>";
}

// ————————————————————————————————————————————————————————————————
// Site integrations (KClient family)
// ————————————————————————————————————————————————————————————————

function orbitraKclientJs(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $token = (string) ($ctx['campaign']['token'] ?? '');
    $code = "<!-- Orbitra Tracking Client JS — put this in the <head> of a site on a third-party host -->\n"
        . "<script>\n"
        . "  var orbitra_db_url = '$trackerUrl';\n"
        . "  var orbitra_campaign_token = '$token';\n"
        . "</script>\n"
        . "<script src=\"{$trackerUrl}/kclient.js\"></script>\n"
        . "<noscript><img src=\"{$trackerUrl}/pixel.gif?token={$token}\" alt=\"\" /></noscript>";
    if (!empty($opts['base64'])) {
        $code = orbitraObfuscateBase64(preg_replace('/<!--.*?-->\n?/s', '', $code));
    }
    return $code;
}

function orbitraKclientPhp(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $token = (string) ($ctx['campaign']['token'] ?? '');
    $sendParams = ($opts['send_params'] ?? true) !== false;
    $paramsLine = $sendParams
        ? '$client->sendAllParams();'
        : '// $client->sendAllParams();   // disabled: UTM/SubID from the page URL are not passed through';

    return "<?php\n"
        . "require_once dirname(__FILE__) . '/kclient.php';\n"
        . "\$client = new KClickClient('{$trackerUrl}', '{$token}');\n"
        . "{$paramsLine}\n"
        . "// \$client->debug();            // show errors while testing\n"
        . "// \$client->execute();          // run, print the output, keep the page going\n"
        . "\$client->executeAndBreak();     // stop the page if the tracker redirects or returns content\n"
        . "?>\n";
}

/** Secondary pages of the same site: pick the click back up instead of
 *  registering a new one. */
function orbitraKclientPhpSecondary(array $ctx): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $token = (string) ($ctx['campaign']['token'] ?? '');
    return "<?php\n"
        . "require_once dirname(__FILE__) . '/kclient.php';\n"
        . "\$client = new KClickClient('{$trackerUrl}', '{$token}');\n"
        . "\$client->restoreFromQuery();\n"
        . "// \$client->restoreFromSession();   // when internal links do not carry _subid\n"
        . "?>\n";
}

/** The offer link, for a button the page draws itself. */
function orbitraKclientPhpOfferLink(): string
{
    return '<a href="<?= $client->getOffer() ?>">link</a>';
}

/** Advanced: the same URL in a variable, when the markup is built elsewhere. */
function orbitraKclientPhpGetOffer(): string
{
    return "<?php\n"
        . "\$offerLink = \$client->getOffer();\n"
        . "// \$offerLink = \$client->getOffer(42);   // a specific offer of the stream\n"
        . "?>\n";
}

function orbitraTrackingScript(array $ctx): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $token = (string) ($ctx['campaign']['token'] ?? '');
    return "<!-- Tracking script — into the <head> of every page of the site -->\n"
        . "<script>\n"
        . "  var orbitra_db_url = '$trackerUrl';\n"
        . "  var orbitra_campaign_token = '$token';\n"
        . "</script>\n"
        . "<script src=\"{$trackerUrl}/tracking.js\"></script>\n"
        . "\n"
        . "<!-- \"Thank you\" page / conversion reporting: -->\n"
        . "<script>\n"
        . "  var revenue = 0;\n"
        . "  var status = 'lead';\n"
        . "  var tid = Math.floor(Math.random() * 1000000000);\n"
        . "  KTracking.reportConversion(revenue, status, { tid: tid });\n"
        . "</script>\n"
        . "\n"
        . "<!-- Offer button (the script fills in the link and the landing time): -->\n"
        . "<!-- <a href=\"{offer}\" data-orbitra-offer>BUY NOW</a> -->";
}

// ————————————————————————————————————————————————————————————————
// Banner blocks
// ————————————————————————————————————————————————————————————————

function orbitraBannerScript(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $token = (string) ($ctx['campaign']['token'] ?? '');
    $w = (int) ($opts['width'] ?? 0) ?: 300;
    $h = (int) ($opts['height'] ?? 0) ?: 250;
    return "<!-- Banner block (script): stream content in \"Show as HTML\" mode -->\n"
        . "<div id=\"orbitra-banner\" style=\"width:{$w}px;height:{$h}px;overflow:hidden\"></div>\n"
        . "<script>\n"
        . "  var orbitra_db_url = '$trackerUrl';\n"
        . "  var orbitra_campaign_token = '$token';\n"
        . "</script>\n"
        . "<script src=\"{$trackerUrl}/banner.js\"></script>";
}

function orbitraBannerIframe(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $alias = (string) ($ctx['campaign']['alias'] ?? '');
    $w = (int) ($opts['width'] ?? 0) ?: 300;
    $h = (int) ($opts['height'] ?? 0) ?: 250;
    return "<!-- Banner block (iframe): the campaign in a frame, compatible with RTB codes -->\n"
        . "<iframe src=\"{$trackerUrl}/{$alias}\" width=\"{$w}\" height=\"{$h}\" frameborder=\"0\" scrolling=\"no\" marginwidth=\"0\" marginheight=\"0\"></iframe>";
}

// ————————————————————————————————————————————————————————————————
// Ad-network snippets
// ————————————————————————————————————————————————————————————————

function orbitraLinkSnippet(array $ctx): string
{
    $id = orbitraSnippetCid();
    $url = (string) ($ctx['campaign']['url'] ?? '');
    $pt = orbitraPagePassthrough();
    return <<<HTML
<span id="$id"></span>
<script type="application/javascript">
document.getElementById('$id').innerHTML = '<a href="$url?se_referrer=$pt">Link</a>';
</script>
HTML;
}

function orbitraIframeSnippet(array $ctx): string
{
    $id = orbitraSnippetCid();
    $url = (string) ($ctx['campaign']['url'] ?? '');
    $pt = orbitraPagePassthrough();
    return <<<HTML
<div id="$id"></div>
<script type="application/javascript">
document.getElementById('$id').innerHTML = '<iframe sandbox="allow-top-navigation allow-scripts allow-popups allow-forms" frameborder="0" width="100%" height="100%" src="$url?se_referrer=$pt&frm=frame"></iframe>';
</script>
HTML;
}

function orbitraScriptSnippet(array $ctx): string
{
    $id = orbitraSnippetCid();
    $url = (string) ($ctx['campaign']['url'] ?? '');
    $pt = orbitraPagePassthrough();
    return <<<HTML
<span id="$id"></span><script type="application/javascript">
var d=document;var s=d.createElement('script');
s.src='$url?se_referrer=$pt&frm=script&_cid=$id';
if (document.currentScript) { document.currentScript.parentNode.insertBefore(s, document.currentScript); } else { d.getElementsByTagName('head')[0].appendChild(s); }
</script>
HTML;
}

// ————————————————————————————————————————————————————————————————
// Misc widgets
// ————————————————————————————————————————————————————————————————

function orbitraPixelSnippet(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $campaignId = (string) ($ctx['campaign']['id'] ?? '');
    if (($opts['pixel_type'] ?? '') === 'conversion') {
        $status = ((string) ($opts['conv_status'] ?? '')) ?: 'lead';
        $payout = (float) ($opts['payout'] ?? 0);
        $payoutPart = $payout > 0 ? '&payout=' . $payout : '';
        $subid = ((string) ($opts['subid_param'] ?? '')) ?: '{subid}';
        return "<!-- Conversion pixel: on the \"Thank you\" page, after the form/order.\n"
            . '     ' . orbitraSnippetEsc($subid) . " is substituted by your mailing platform / CRM (click id). -->\n"
            . '<img src="' . $trackerUrl . '/pixel.gif?action=conversion&subid=' . orbitraSnippetEsc($subid) . '&status=' . orbitraSnippetEsc($status) . $payoutPart . '" width="1" height="1" border="0" alt="" />';
    }
    return "<!-- Click pixel: email-open and banner-impression tracking.\n"
        . "     Click parameters (sub_id_1 etc.) can be added to the query string. -->\n"
        . '<img src="' . $trackerUrl . '/pixel.gif?campaign_id=' . $campaignId . '" width="1" height="1" border="0" alt="" />';
}

function orbitraCountdownSnippet(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $campaignId = (string) ($ctx['campaign']['id'] ?? '');
    $hours = max(0, (int) ($opts['hours'] ?? 0));
    $minutes = max(0, (int) ($opts['minutes'] ?? 0));
    $totalSeconds = max(60, $hours * 3600 + $minutes * 60);
    // JS `opts.x || default` treats '' as unset — mirror with ?:.
    $headerText = ((string) ($opts['header_text'] ?? '')) ?: 'OFFER EXPIRES IN';
    $buttonText = ((string) ($opts['button_text'] ?? '')) ?: 'GET SPECIAL OFFER';
    $offerUrl = ((string) ($opts['offer_url'] ?? '')) ?: 'https://your-offer.com';
    $theme = ORBITRA_COUNTDOWN_THEMES[$opts['theme'] ?? ''] ?? ORBITRA_COUNTDOWN_THEMES['purple'];
    $expireRedirect = ($opts['expire_action'] ?? '') === 'redirect'
        ? (((string) ($opts['expire_url'] ?? '')) ?: $offerUrl)
        : '';
    if ($expireRedirect === '') {
        $onExpire = "document.getElementById('ltt-countdown').innerHTML = '<h2>OFFER EXPIRED</h2>';";
    } else {
        $onExpire = 'window.location.href = ' . orbitraSnippetJson($expireRedirect) . ';';
    }

    return "<!-- Orbitra Countdown Widget — insert before the landing's closing </body> -->\n"
        . '<div id="ltt-countdown" style="font-family:sans-serif;text-align:center;padding:20px;' . "\n"
        . '    background:' . $theme['gradient'] . ';color:white;border-radius:12px;max-width:400px;' . "\n"
        . '    margin:0 auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">' . "\n"
        . '    <div style="font-size:14px;text-transform:uppercase;letter-spacing:2px;margin-bottom:10px;">' . "\n"
        . '        ' . orbitraSnippetEsc($headerText) . "\n"
        . '    </div>' . "\n"
        . '    <div id="ltt-timer" style="font-size:48px;font-weight:bold;">' . "\n"
        . '        <span id="ltt-hours">00</span>:<span id="ltt-minutes">00</span>:<span id="ltt-seconds">00</span>' . "\n"
        . '    </div>' . "\n"
        . '    <a id="ltt-cta" href="#" style="display:inline-block;margin-top:20px;padding:14px 40px;' . "\n"
        . '        background:' . $theme['cta'] . ';color:white;text-decoration:none;border-radius:8px;font-weight:600;' . "\n"
        . '        font-size:16px;transition:transform 0.2s;">' . "\n"
        . '        ' . orbitraSnippetEsc($buttonText) . "\n"
        . '    </a>' . "\n"
        . "</div>\n"
        . "\n"
        . "<script>\n"
        . "(function() {\n"
        . "    var trackerUrl = '$trackerUrl';\n"
        . "    var campaignId = '$campaignId';\n"
        . '    var redirectUrl = ' . orbitraSnippetJson($offerUrl) . ";\n"
        . "    var totalSeconds = $totalSeconds;\n"
        . "\n"
        . "    var endTime = new Date().getTime() + totalSeconds * 1000;\n"
        . "\n"
        . "    document.getElementById('ltt-cta').href = trackerUrl + '/click.php?campaign_id=' + campaignId + '&url=' + encodeURIComponent(redirectUrl);\n"
        . "\n"
        . "    function updateTimer() {\n"
        . "        var distance = endTime - new Date().getTime();\n"
        . "        if (distance < 0) {\n"
        . "            $onExpire\n"
        . "            return;\n"
        . "        }\n"
        . "        document.getElementById('ltt-hours').textContent = String(Math.floor(distance / 3600000)).padStart(2, '0');\n"
        . "        document.getElementById('ltt-minutes').textContent = String(Math.floor((distance % 3600000) / 60000)).padStart(2, '0');\n"
        . "        document.getElementById('ltt-seconds').textContent = String(Math.floor((distance % 60000) / 1000)).padStart(2, '0');\n"
        . "    }\n"
        . "\n"
        . "    updateTimer();\n"
        . "    setInterval(updateTimer, 1000);\n"
        . "})();\n"
        . "</script>";
}

/** Appends sub1=back_button so returns show up as their own row in reports.
 *  Leaves the URL alone when it already carries a sub1 or is empty. */
function orbitraTagBackButtonUrl($url): string
{
    $u = trim((string) ($url ?? ''));
    if ($u === '' || preg_match('/[?&]sub1=/', $u)) {
        return $u;
    }
    $hashAt = strpos($u, '#');
    $base = $hashAt !== false ? substr($u, 0, $hashAt) : $u;
    $hash = $hashAt !== false ? substr($u, $hashAt) : '';
    return $base . (strpos($base, '?') !== false ? '&' : '?') . 'sub1=back_button' . $hash;
}

/** Back Button Trap. See the JS twin for the full rationale. */
function orbitraBackButtonSnippet(array $ctx, array $opts = []): string
{
    $rawUrl = trim((string) ($opts['trap_url'] ?? '')) ?: (string) ($ctx['campaign']['url'] ?? '') ?: 'https://your-special-offer.com';
    $trapUrl = ($opts['log_click'] ?? true) !== false ? orbitraTagBackButtonUrl($rawUrl) : $rawUrl;
    $delay = max(0, (int) ($opts['delay'] ?? 0));
    $delayLines = $delay > 0
        ? "\n    var readyAt = Date.now() + " . ($delay * 1000) . "; // activation delay: {$delay} s"
        : '';
    $delayCheck = $delay > 0 ? ' || Date.now() < readyAt' : '';

    return "<!-- Orbitra Back Button Trap — paste before the landing's closing </body> -->\n"
        . "<!-- Works after the visitor has clicked or tapped the page once (browser rule).\n"
        . "     To test: open the landing, click anywhere on it, then press Back. -->\n"
        . "<script>\n"
        . "(function () {\n"
        . "    // Where to send a visitor who presses Back:\n"
        . '    var trapUrl = ' . orbitraSnippetJson($trapUrl) . ';' . $delayLines . "\n"
        . "\n"
        . "    var armed = false, fired = false;\n"
        . "\n"
        . "    function arm() {\n"
        . "        if (armed{$delayCheck}) return;\n"
        . "        armed = true;\n"
        . "        try {\n"
        . "            var st = history.state && typeof history.state === 'object' ? history.state : {};\n"
        . "            history.replaceState(Object.assign({}, st, { orbitraTrap: 'base' }), '', location.href);\n"
        . "            history.pushState({ orbitraTrap: 'guard' }, '', location.href);\n"
        . "        } catch (e) {}\n"
        . "    }\n"
        . "\n"
        . "    ['pointerdown', 'touchend', 'keydown', 'click'].forEach(function (type) {\n"
        . "        window.addEventListener(type, arm, { capture: true, passive: true });\n"
        . "    });\n"
        . "\n"
        . "    window.addEventListener('popstate', function (e) {\n"
        . "        if (fired || !e.state || e.state.orbitraTrap !== 'base') return;\n"
        . "        fired = true;\n"
        . "        location.replace(trapUrl);\n"
        . "    });\n"
        . "})();\n"
        . "</script>";
}

function orbitraExitIntentSnippet(array $ctx, array $opts = []): string
{
    $trackerUrl = (string) ($ctx['tracker_url'] ?? '');
    $campaignId = (string) ($ctx['campaign']['id'] ?? '');
    $offerUrl = ((string) ($opts['offer_url'] ?? '')) ?: 'https://your-offer.com';
    $heading = ((string) ($opts['heading'] ?? '')) ?: 'Wait! Special Offer!';
    $text = ((string) ($opts['text'] ?? '')) ?: "Don't miss this exclusive deal just for you!";
    $buttonText = ((string) ($opts['popup_button_text'] ?? '')) ?: 'CLAIM 50% OFF';
    $buttonColor = ((string) ($opts['button_color'] ?? '')) ?: '#22c55e';
    $showDelay = max(0, (int) ($opts['show_delay'] ?? 0));
    $closeOnBackdrop = ($opts['close_on_backdrop'] ?? true) !== false;
    $activateLine = $showDelay > 0
        ? 'var activateAt = new Date().getTime() + ' . ($showDelay * 1000) . ';'
        : 'var activateAt = 0;';
    $backdropClose = $closeOnBackdrop
        ? "\n    document.getElementById('ltt-exit-popup').addEventListener('click', function(e) {\n        if (e.target === this) { this.classList.remove('show'); }\n    });"
        : '';

    return "<!-- Orbitra Exit Intent Popup — insert before the landing's closing </body> -->\n"
        . "<style>\n"
        . ".ltt-exit-popup { display:none; position:fixed; top:0; left:0; width:100%; height:100%;\n"
        . "    background:rgba(0,0,0,0.7); z-index:99999; justify-content:center; align-items:center; }\n"
        . ".ltt-exit-popup.show { display:flex; }\n"
        . ".ltt-exit-content { background:white; padding:40px; border-radius:16px; max-width:500px;\n"
        . "    text-align:center; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.3); }\n"
        . ".ltt-exit-close { position:absolute; top:15px; right:20px; font-size:24px; cursor:pointer;\n"
        . "    color:#999; border:none; background:none; }\n"
        . ".ltt-exit-btn { display:inline-block; margin-top:20px; padding:16px 40px; background:{$buttonColor};\n"
        . "    color:white; text-decoration:none; border-radius:8px; font-weight:600; font-size:18px; }\n"
        . "</style>\n"
        . "\n"
        . '<div id="ltt-exit-popup" class="ltt-exit-popup">' . "\n"
        . '    <div class="ltt-exit-content">' . "\n"
        . '        <button class="ltt-exit-close" onclick="document.getElementById(\'ltt-exit-popup\').classList.remove(\'show\')">&times;</button>' . "\n"
        . '        <h2 style="margin:0 0 15px;font-size:28px;">' . orbitraSnippetEsc($heading) . "</h2>\n"
        . '        <p style="font-size:16px;color:#666;margin-bottom:10px;">' . orbitraSnippetEsc($text) . "</p>\n"
        . '        <a id="ltt-exit-cta" href="#" class="ltt-exit-btn">' . orbitraSnippetEsc($buttonText) . "</a>\n"
        . "    </div>\n"
        . "</div>\n"
        . "\n"
        . "<script>\n"
        . "(function() {\n"
        . "    var trackerUrl = '$trackerUrl';\n"
        . "    var campaignId = '$campaignId';\n"
        . '    var offerUrl = ' . orbitraSnippetJson($offerUrl) . ";\n"
        . "    var shown = false;\n"
        . "    {$activateLine}\n"
        . "\n"
        . "    document.getElementById('ltt-exit-cta').href = trackerUrl + '/click.php?campaign_id=' + campaignId + '&url=' + encodeURIComponent(offerUrl);\n"
        . "{$backdropClose}\n"
        . "\n"
        . "    document.addEventListener('mouseout', function(e) {\n"
        . "        if (shown) return;\n"
        . "        if (new Date().getTime() < activateAt) return;\n"
        . "        if (e.clientY < 10 && e.relatedTarget === null) {\n"
        . "            shown = true;\n"
        . "            document.getElementById('ltt-exit-popup').classList.add('show');\n"
        . "            fetch(trackerUrl + '/click.php?campaign_id=' + campaignId + '&sub1=exit_popup&redirect=0');\n"
        . "        }\n"
        . "    });\n"
        . "})();\n"
        . "</script>";
}

function orbitraWordpressSnippet(array $ctx): string
{
    $campaignId = (string) ($ctx['campaign']['id'] ?? '');
    return "<!-- WordPress: tracker shortcodes (Orbitra plugin) -->\n"
        . "[orbitra_link campaign_id=\"{$campaignId}\" text=\"Click Here\"]\n"
        . "\n"
        . "<!-- Multiple offers / geo redirect: -->\n"
        . "[orbitra_link campaign_id=\"{$campaignId}\" text=\"Buy\" text_ru=\"Купить\" geo_redirect=\"RU:https://offer1.com,DE:https://offer2.com\"]\n"
        . "\n"
        . "<!-- Conversion from the \"Thank you\" page (Contact Form 7 etc.): -->\n"
        . "[send_postback]";
}
