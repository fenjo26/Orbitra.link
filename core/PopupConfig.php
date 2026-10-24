<?php
/**
 * core/PopupConfig.php — conversion popups: the single place that decides
 * WHETHER a click gets a popup and WHAT its client config looks like.
 *
 * Consumers:
 *  - index.php (orbitraInjectPopup) — local landings, /lander/, show_html;
 *    the popup rides the same injection the landing timer uses.
 *  - core/click_api.php — puts the payload into info.popup for tracking.js /
 *    kclient clients on external sites (no extra request).
 *
 * Gating, server-side (the client adds the behavioural half): the click must
 * exist, not be a known bot, not have gone to a safe page, and its campaign
 * must have the popup switched on (campaigns.popup_mode = 'on'). What the
 * popup looks like is the campaign's own popup_config merged over the
 * template in Settings → Plugins.
 *
 * Everything here is read-only; the funnel itself is written by
 * /pixel.gif?action=popup beacons (MAX semantics, same as the lp timer).
 */

/**
 * Every setting a popup has. The same keys live in two places:
 *  - settings rows (popup_*)       — the TEMPLATE a campaign starts from;
 *  - campaigns.popup_config (JSON) — that campaign's own popup.
 * A campaign's popup = template merged with its own config, so a key the
 * campaign never stored (an older row, a new setting added later) still
 * resolves to something sane.
 */
const ORBITRA_POPUP_KEYS = [
    'popup_vertical', 'popup_delay', 'popup_require_activity', 'popup_frequency_hours',
    'popup_scroll_depth', 'popup_exit_intent', 'popup_designs', 'popup_image', 'popup_amount',
    'popup_languages', 'popup_animation', 'popup_position', 'popup_accent', 'popup_lang_mode',
    'popup_lang', 'popup_custom_texts',
];

/** The template (Settings → Plugins) as raw popup_* strings. */
function orbitraPopupTemplateRaw(PDO $pdo): array
{
    $raw = [];
    try {
        $stmt = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'popup\\_%' ESCAPE '\\'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array($row['key'], ORBITRA_POPUP_KEYS, true)) {
                $raw[$row['key']] = $row['value'];
            }
        }
    } catch (\Throwable $e) {
        // Every key below has a default; an unreadable table = the defaults.
    }
    return $raw;
}

/** The template, normalized (kept for callers that only need the defaults). */
function orbitraPopupGlobalSettings(PDO $pdo): array
{
    return orbitraPopupNormalize(orbitraPopupTemplateRaw($pdo));
}

/**
 * Write-side validation for one popup config (a campaign's, from the editor
 * or the API): only known keys survive, every value becomes the string the
 * settings table would hold, out-of-range numbers are clamped. Unknown or
 * malformed values are dropped rather than refused — the read side falls back
 * to the template for anything missing.
 */
function orbitraPopupSanitize(array $in): array
{
    $out = [];
    foreach (ORBITRA_POPUP_KEYS as $key) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $v = $in[$key];
        if ($key === 'popup_custom_texts') {
            if (is_string($v)) {
                $v = trim($v) === '' ? [] : json_decode($v, true);
            }
            $texts = [];
            if (is_array($v)) {
                foreach (['title', 'text', 'button'] as $f) {
                    if (isset($v[$f]) && is_string($v[$f]) && trim($v[$f]) !== '') {
                        $texts[$f] = mb_substr(trim($v[$f]), 0, 300);
                    }
                }
            }
            $out[$key] = $texts ? json_encode($texts, JSON_UNESCAPED_UNICODE) : '';
            continue;
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        }
        $v = is_scalar($v) ? trim((string) $v) : '';
        switch ($key) {
            case 'popup_require_activity':
            case 'popup_exit_intent':
                $v = $v === '1' ? '1' : '0';
                break;
            case 'popup_delay':
                $v = (string) (is_numeric($v) ? max(1, min(300, (int) $v)) : 12);
                break;
            case 'popup_frequency_hours':
                $v = (string) (is_numeric($v) ? max(0, min(720, (int) $v)) : 24);
                break;
            case 'popup_scroll_depth':
                $v = (string) (is_numeric($v) ? max(0, min(90, (int) $v)) : 0);
                break;
            case 'popup_vertical':
                $v = in_array($v, ['', 'nutra', 'gambling', 'betting', 'ecom', 'finance', 'crypto'], true) ? $v : '';
                break;
            case 'popup_animation':
                $v = in_array($v, ['none', 'fade', 'zoom', 'slide'], true) ? $v : 'zoom';
                break;
            case 'popup_position':
                $v = in_array($v, ['center', 'bottom'], true) ? $v : 'center';
                break;
            case 'popup_lang_mode':
                $v = in_array($v, ['auto', 'forced'], true) ? $v : 'auto';
                break;
            case 'popup_designs':
                $kept = [];
                foreach (explode(',', $v) as $d) {
                    $d = trim($d);
                    if (in_array($d, ['card', 'gift', 'alert'], true) && !in_array($d, $kept, true)) {
                        $kept[] = $d;
                    }
                }
                $v = $kept ? implode(',', $kept) : 'card';
                break;
            case 'popup_image':
                // A Gallery URL (/uploads/media/...), a preset or an absolute
                // http(s) URL — nothing that could break out of an attribute.
                $v = (strlen($v) <= 500 && preg_match('#^(/|https?://)[^"\'<>\s]*$#i', $v)) ? $v : '';
                break;
            case 'popup_amount':
                $v = is_numeric($v) ? (string) (0 + $v) : '';
                break;
            case 'popup_languages':
                $v = trim(preg_replace('/[^a-z,\-]/', '', strtolower($v)), ',');
                break;
            case 'popup_accent':
                $v = preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '';
                break;
            case 'popup_lang':
                $v = preg_replace('/[^a-z\-]/', '', strtolower($v)) ?: 'en';
                break;
        }
        $out[$key] = $v;
    }
    return $out;
}

/**
 * One campaign's popup switch and its own config.
 * mode: 'on' shows the popup; anything else ('off', and the pre-75 'inherit')
 * shows nothing. config: the sanitized popup_* map, or null = template.
 */
function orbitraPopupCampaignState(PDO $pdo, $campaignId): array
{
    $state = ['mode' => 'off', 'config' => null];
    $campaignId = (int) $campaignId;
    if ($campaignId <= 0) {
        return $state;
    }
    try {
        $stmt = $pdo->prepare("SELECT popup_mode, popup_config FROM campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // A schema without popup_config (an install mid-upgrade): mode only.
        try {
            $stmt = $pdo->prepare("SELECT popup_mode FROM campaigns WHERE id = ? LIMIT 1");
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e2) {
            return $state;
        }
    }
    if (!$row) {
        return $state;
    }
    $state['mode'] = trim((string) ($row['popup_mode'] ?? '')) === 'on' ? 'on' : 'off';
    $cfg = isset($row['popup_config']) ? json_decode((string) $row['popup_config'], true) : null;
    $state['config'] = is_array($cfg) ? orbitraPopupSanitize($cfg) : null;
    return $state;
}

/** Normalized popup settings, clamped to sane ranges; every key defaulted. */
function orbitraPopupNormalize(array $raw): array
{

    $designsAvailable = ['card', 'gift', 'alert'];
    $designs = [];
    foreach (explode(',', (string) ($raw['popup_designs'] ?? 'card,gift,alert')) as $d) {
        $d = trim($d);
        if ($d !== '' && in_array($d, $designsAvailable, true) && !in_array($d, $designs, true)) {
            $designs[] = $d;
        }
    }
    if (!$designs) {
        $designs = ['card'];
    }

    $verticals = ['', 'nutra', 'gambling', 'betting', 'ecom', 'finance', 'crypto'];
    $vertical = trim((string) ($raw['popup_vertical'] ?? ''));
    if (!in_array($vertical, $verticals, true)) {
        $vertical = '';
    }

    $animation = (string) ($raw['popup_animation'] ?? 'zoom');
    if (!in_array($animation, ['none', 'fade', 'zoom', 'slide'], true)) {
        $animation = 'zoom';
    }
    $position = (string) ($raw['popup_position'] ?? 'center');
    if (!in_array($position, ['center', 'bottom'], true)) {
        $position = 'center';
    }
    $langMode = (string) ($raw['popup_lang_mode'] ?? 'auto');
    if (!in_array($langMode, ['auto', 'forced'], true)) {
        $langMode = 'auto';
    }

    $customTexts = null;
    $customRaw = trim((string) ($raw['popup_custom_texts'] ?? ''));
    if ($customRaw !== '') {
        $decoded = json_decode($customRaw, true);
        if (is_array($decoded)) {
            $customTexts = [];
            foreach (['title', 'text', 'button'] as $field) {
                if (isset($decoded[$field]) && is_string($decoded[$field]) && trim($decoded[$field]) !== '') {
                    $customTexts[$field] = trim($decoded[$field]);
                }
            }
            if (!$customTexts) {
                $customTexts = null;
            }
        }
    }

    $amount = trim((string) ($raw['popup_amount'] ?? ''));
    if ($amount !== '' && !is_numeric($amount)) {
        $amount = '';
    }

    return [
        'enabled'          => ((int) ($raw['popup_enabled'] ?? 0)) === 1,
        'vertical'         => $vertical,
        'delay'            => max(1, min(300, (int) ($raw['popup_delay'] ?? 12))),
        'require_activity' => ((int) ($raw['popup_require_activity'] ?? 1)) === 1,
        'frequency_hours'  => max(0, min(24 * 30, (int) ($raw['popup_frequency_hours'] ?? 24))),
        'scroll_depth'     => max(0, min(90, (int) ($raw['popup_scroll_depth'] ?? 0))),
        'exit_intent'      => ((int) ($raw['popup_exit_intent'] ?? 0)) === 1,
        'designs'          => $designs,
        'image'            => trim((string) ($raw['popup_image'] ?? '')),
        'amount'           => $amount,
        'languages'        => trim((string) ($raw['popup_languages'] ?? '')),
        'animation'        => $animation,
        'position'         => $position,
        'accent'           => trim((string) ($raw['popup_accent'] ?? '')),
        'lang_mode'        => $langMode,
        'lang'             => preg_replace('/[^a-z-]/', '', strtolower((string) ($raw['popup_lang'] ?? 'en'))) ?: 'en',
        'custom_texts'     => $customTexts,
    ];
}

/** Is the popup on for this campaign? Only the campaign's own switch counts. */
function orbitraPopupEnabledForCampaign(PDO $pdo, array $global, $campaignId): bool
{
    return orbitraPopupCampaignState($pdo, $campaignId)['mode'] === 'on';
}

/**
 * The client payload for one click, or null when this click must not see a
 * popup (no click row, known bot, safe page, popup off in the campaign).
 * The caller adds `offer` (the signed transition URL) and `endpoint`.
 */
function orbitraPopupPayloadForClick(PDO $pdo, string $clickId): ?array
{
    $clickId = trim($clickId);
    if ($clickId === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT is_bot, is_safe_page, campaign_id, country FROM clicks WHERE id = ? LIMIT 1");
        $stmt->execute([$clickId]);
        $click = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$click) {
        return null;
    }
    // The server already knows the verdict — a bot or a safe-page visitor must
    // not even load popup.js (no layout shift, no signal that a popup exists).
    if ((int) ($click['is_bot'] ?? 0) === 1 || (int) ($click['is_safe_page'] ?? 0) === 1) {
        return null;
    }

    // The campaign decides: its own switch, its own popup (template-merged).
    $state = orbitraPopupCampaignState($pdo, $click['campaign_id'] ?? null);
    if ($state['mode'] !== 'on') {
        return null;
    }
    $global = orbitraPopupNormalize(array_merge(orbitraPopupTemplateRaw($pdo), $state['config'] ?? []));

    $country = strtoupper(trim((string) ($click['country'] ?? '')));
    $currency = orbitraPopupCurrencyForCountry($country);

    return [
        'v'               => 1,
        'subid'           => $clickId,
        'designs'         => $global['designs'],
        'vertical'        => $global['vertical'],
        'delay'           => $global['delay'],
        'requireActivity' => $global['require_activity'],
        'frequencyHours'  => $global['frequency_hours'],
        'scrollDepth'     => $global['scroll_depth'],
        'exitIntent'      => $global['exit_intent'],
        'animation'       => $global['animation'],
        'position'        => $global['position'],
        'accent'          => $global['accent'],
        'langMode'        => $global['lang_mode'],
        'lang'            => $global['lang'],
        'languages'       => $global['languages'],
        // The operator's Gallery pick wins; otherwise the vertical's own
        // photo from assets/popup-presets (the same files the "Popup images"
        // Gallery folder is seeded from). '' only if the file is missing —
        // popup.js then falls back to its inline SVG illustration.
        'image'           => $global['image'] !== '' ? $global['image'] : orbitraPopupPresetImage($global['vertical']),
        'amount'          => $global['amount'],
        'customTexts'     => $global['custom_texts'],
        'geo'             => [
            'country'       => $country,
            'currency'      => $currency['symbol'],
            'currencyCode'  => $currency['code'],
            // Local units per 1 USD. "money" text packs quote {amount} in
            // dollars; popup.js multiplies and rounds to a printable figure.
            'rate'          => orbitraPopupUsdRate($currency['code']),
        ],
    ];
}

/** The script tag every consumer injects. Identical markup everywhere so the
 *  browser-side bootstrap in popup.js has exactly one entry point. */
function orbitraPopupScriptTag(array $payload, string $scriptSrc): string
{
    // data-orbitra-popup is double-quoted JSON in a double-quoted attribute:
    // htmlspecialchars with ENT_QUOTES escapes both quote kinds safely.
    // NOT JSON_UNESCAPED_UNICODE: the attribute is decoded with the landing's
    // own charset, so "€" / "₫" / custom Cyrillic texts must travel as \uXXXX
    // escapes or a windows-1251/1252 landing shows mojibake.
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        return '';
    }
    return '<script src="' . htmlspecialchars($scriptSrc, ENT_QUOTES, 'UTF-8')
        . '" data-orbitra-popup="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8')
        . '" defer charset="utf-8"></script>';
}

/**
 * ISO-3166 alpha-2 → ISO-4217 {code, symbol} for the GEOs affiliate traffic
 * actually buys. Unknown country → USD: the {currency} macro must always
 * resolve to something printable.
 */
function orbitraPopupCurrencyForCountry(string $country): array
{
    static $map = null;
    if ($map === null) {
        // code => [symbol, iso]
        $map = [
            'US' => ['$', 'USD'], 'CA' => ['C$', 'CAD'], 'GB' => ['£', 'GBP'], 'IE' => ['€', 'EUR'],
            'DE' => ['€', 'EUR'], 'FR' => ['€', 'EUR'], 'ES' => ['€', 'EUR'], 'IT' => ['€', 'EUR'],
            'PT' => ['€', 'EUR'], 'NL' => ['€', 'EUR'], 'BE' => ['€', 'EUR'], 'AT' => ['€', 'EUR'],
            'GR' => ['€', 'EUR'], 'FI' => ['€', 'EUR'], 'SK' => ['€', 'EUR'], 'SI' => ['€', 'EUR'],
            'LT' => ['€', 'EUR'], 'LV' => ['€', 'EUR'], 'EE' => ['€', 'EUR'], 'CY' => ['€', 'EUR'],
            'MT' => ['€', 'EUR'], 'HR' => ['€', 'EUR'], 'PL' => ['zł', 'PLN'], 'CZ' => ['Kč', 'CZK'],
            'HU' => ['Ft', 'HUF'], 'RO' => ['lei', 'RON'], 'BG' => ['€', 'EUR'], 'RS' => ['дин', 'RSD'],
            'UA' => ['₴', 'UAH'], 'TR' => ['₺', 'TRY'], 'RU' => ['₽', 'RUB'], 'KZ' => ['₸', 'KZT'],
            'UZ' => ['сум', 'UZS'], 'AZ' => ['₼', 'AZN'], 'AM' => ['֏', 'AMD'], 'GE' => ['₾', 'GEL'],
            'IL' => ['₪', 'ILS'], 'AE' => ['د.إ', 'AED'], 'SA' => ['ر.س', 'SAR'], 'QA' => ['ر.ق', 'QAR'],
            'KW' => ['د.ك', 'KWD'], 'EG' => ['ج.م', 'EGP'], 'MA' => ['د.م.', 'MAD'], 'DZ' => ['د.ج', 'DZD'],
            'TN' => ['د.ت', 'TND'], 'ZA' => ['R', 'ZAR'], 'NG' => ['₦', 'NGN'], 'KE' => ['KSh', 'KES'],
            'GH' => ['₵', 'GHS'], 'BR' => ['R$', 'BRL'], 'MX' => ['MX$', 'MXN'], 'AR' => ['AR$', 'ARS'],
            'CL' => ['CL$', 'CLP'], 'CO' => ['COL$', 'COP'], 'PE' => ['S/', 'PEN'], 'VE' => ['Bs', 'VES'],
            'AU' => ['A$', 'AUD'], 'NZ' => ['NZ$', 'NZD'], 'JP' => ['¥', 'JPY'], 'KR' => ['₩', 'KRW'],
            'CN' => ['¥', 'CNY'], 'HK' => ['HK$', 'HKD'], 'TW' => ['NT$', 'TWD'], 'SG' => ['S$', 'SGD'],
            'MY' => ['RM', 'MYR'], 'TH' => ['฿', 'THB'], 'VN' => ['₫', 'VND'], 'ID' => ['Rp', 'IDR'],
            'PH' => ['₱', 'PHP'], 'IN' => ['₹', 'INR'], 'PK' => ['₨', 'PKR'], 'BD' => ['৳', 'BDT'],
            'LK' => ['Rs', 'LKR'], 'NP' => ['रू', 'NPR'], 'MM' => ['K', 'MMK'], 'KH' => ['៛', 'KHR'],
            'LA' => ['₭', 'LAK'], 'MN' => ['₮', 'MNT'], 'SE' => ['kr', 'SEK'], 'NO' => ['kr', 'NOK'],
            'DK' => ['kr', 'DKK'], 'IS' => ['kr', 'ISK'], 'CH' => ['CHF', 'CHF'],
        ];
    }
    $c = strtoupper($country);
    if ($c !== '' && isset($map[$c])) {
        return ['symbol' => $map[$c][0], 'code' => $map[$c][1]];
    }
    return ['symbol' => '$', 'code' => 'USD'];
}

/**
 * Approximate local units per 1 USD, for marketing copy only ("bonus 200 €",
 * "up to 7 500 000 ₫") — never for money math. Rounded on purpose; popup.js
 * snaps the product to a "nice" number anyway, so a few percent of drift
 * between releases changes nothing on screen. Unknown code → 1 (shown as is).
 */
function orbitraPopupUsdRate(string $code): float
{
    static $rates = [
        'USD' => 1, 'EUR' => 0.88, 'GBP' => 0.76, 'CAD' => 1.37, 'CHF' => 0.81,
        'PLN' => 3.8, 'CZK' => 22, 'HUF' => 360, 'RON' => 4.5, 'RSD' => 103,
        'UAH' => 41, 'TRY' => 40, 'RUB' => 80, 'KZT' => 520, 'UZS' => 12500,
        'AZN' => 1.7, 'AMD' => 390, 'GEL' => 2.7, 'ILS' => 3.6, 'AED' => 3.67,
        'SAR' => 3.75, 'QAR' => 3.64, 'KWD' => 0.31, 'EGP' => 49, 'MAD' => 9.2,
        'DZD' => 132, 'TND' => 3, 'ZAR' => 18, 'NGN' => 1550, 'KES' => 129,
        'GHS' => 12, 'BRL' => 5.5, 'MXN' => 18.5, 'ARS' => 1200, 'CLP' => 950,
        'COP' => 4000, 'PEN' => 3.6, 'VES' => 120, 'AUD' => 1.53, 'NZD' => 1.7,
        'JPY' => 150, 'KRW' => 1400, 'CNY' => 7.2, 'HKD' => 7.8, 'TWD' => 31,
        'SGD' => 1.3, 'MYR' => 4.3, 'THB' => 33, 'VND' => 26000, 'IDR' => 16500,
        'PHP' => 57, 'INR' => 87, 'PKR' => 283, 'BDT' => 122, 'LKR' => 300,
        'NPR' => 139, 'MMK' => 2100, 'KHR' => 4000, 'LAK' => 21500, 'MNT' => 3550,
        'SEK' => 9.6, 'NOK' => 10.2, 'DKK' => 6.6, 'ISK' => 125,
    ];
    return (float) ($rates[strtoupper($code)] ?? 1);
}

/** Root-relative URL of the vertical's preset photo, or '' when absent.
 *  '' (the neutral vertical) maps to neutral.webp. */
function orbitraPopupPresetImage(string $vertical): string
{
    $name = $vertical !== '' ? $vertical : 'neutral';
    if (!preg_match('/^[a-z]+$/', $name)) {
        return '';
    }
    $file = __DIR__ . '/../assets/popup-presets/' . $name . '.webp';
    return is_file($file) ? '/assets/popup-presets/' . $name . '.webp' : '';
}
