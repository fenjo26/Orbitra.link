<?php
// core/BrowserCheck.php
//
// Browser check: bot / moderator detection from what the browser itself
// reports (Settings → Bot protection → "Browser check").
//
// What it is NOT: a visitor ID. Fingerprints of real phones collide (every
// iPhone of one model and iOS version looks the same, in-app browsers of FB /
// TikTok even more so), so the fingerprint is never used for uniqueness and
// never glues visitors together. It is stored only as a diagnostic.
//
// What it IS: a contradiction detector. Real phones have a GPU, a touch screen
// and a platform matching their User-Agent. Virtual machines of moderation
// farms, spy services, emulators and anti-detect browsers leak contradictions:
// a software WebGL renderer, "iPhone" in the UA with a Linux platform, a
// mobile UA without touch support, and so on. Each finding is a flag with a
// level:
//
//   hard — never seen on a real visitor's device (soft + strict modes act);
//   soft — happens to real people too (VPN, travel, unusual setups): only the
//          strict mode acts on it.
//
// Modes (settings.browser_check_mode):
//   off      — nothing is collected;
//   observe  — flags are stored and shown, nothing else changes;
//   soft     — a hard flag marks the click as a bot (clicks.is_bot = 1); in a
//              cloak stream with the JS check on, the visitor gets the safe
//              page right away;
//   strict   — the same for any flag.
//
// Where the signals come from:
//   1. the cloak JS check (renderCloakJsChallenge in index.php): the probe
//      runs /bc.js inline and appends the signals as `_ocb` to the next URL,
//      so the verdict is known BEFORE the money page is served;
//   2. everywhere else: /bc.js on the landing posts them to
//      /pixel.gif?action=bc after the page loads — the click is marked, the
//      visit itself is not stopped (there is no extra hop to stop it in).
//
// Fresh installs start in soft mode; installs upgraded to schema 77 start
// "off" with a one-time offer in the panel (browser_check_offer_pending=1),
// so an update never changes routing on its own.

const ORBITRA_BC_MODES = ['off', 'observe', 'soft', 'strict'];
const ORBITRA_BC_MAX_SIGNAL_BYTES = 4096;

/** Idempotent DDL — called by migration 77 and by the tests. */
function orbitraBcEnsureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS click_browser_checks (
        click_id TEXT PRIMARY KEY,
        verdict TEXT NOT NULL DEFAULT 'clean',
        flags TEXT NOT NULL DEFAULT '',
        fp TEXT,
        source TEXT NOT NULL DEFAULT 'landing',
        mode TEXT NOT NULL DEFAULT '',
        signals TEXT,
        created_at DATETIME NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_click_browser_checks_created ON click_browser_checks(created_at)");
}

/**
 * The global mode. Accepts the settings array the click path already holds
 * (key browser_check_mode) or a PDO to read it.
 */
function orbitraBcMode($settingsOrPdo): string
{
    $mode = null;
    if (is_array($settingsOrPdo)) {
        $mode = $settingsOrPdo['browser_check_mode'] ?? null;
        if ($mode === null && isset($settingsOrPdo['__pdo']) && $settingsOrPdo['__pdo'] instanceof PDO) {
            $settingsOrPdo = $settingsOrPdo['__pdo'];
        }
    }
    if ($mode === null && $settingsOrPdo instanceof PDO) {
        static $cache = null;
        if ($cache === null) {
            try {
                $v = $settingsOrPdo->query("SELECT value FROM settings WHERE key = 'browser_check_mode' LIMIT 1")->fetchColumn();
                $cache = is_string($v) ? $v : 'off';
            } catch (\Throwable $e) {
                $cache = 'off';
            }
        }
        $mode = $cache;
    }
    $mode = (string) ($mode ?? 'off');
    return in_array($mode, ORBITRA_BC_MODES, true) ? $mode : 'off';
}

/** A stream may opt out ("browser_check": "off" in its cloak settings). */
function orbitraBcStreamAllows(array $customSchema): bool
{
    return ($customSchema['browser_check'] ?? 'inherit') !== 'off';
}

/** Decode the `_ocb` parameter / beacon payload: base64url(JSON) or JSON. */
function orbitraBcDecode(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > ORBITRA_BC_MAX_SIGNAL_BYTES * 2) {
        return null;
    }
    $json = $raw;
    if ($raw[0] !== '{') {
        $b64 = strtr($raw, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        if (!is_string($json)) {
            return null;
        }
    }
    if (strlen($json) > ORBITRA_BC_MAX_SIGNAL_BYTES) {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** Country (ISO-2) a browser timezone belongs to, or ''. */
function orbitraBcTimezoneCountry(string $tz): string
{
    if ($tz === '' || strlen($tz) > 64) {
        return '';
    }
    try {
        $loc = (new DateTimeZone($tz))->getLocation();
        $cc = is_array($loc) ? strtoupper((string) ($loc['country_code'] ?? '')) : '';
        return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== '??' ? $cc : '';
    } catch (\Throwable $e) {
        return '';
    }
}

/** Current UTC offsets (minutes) used anywhere in a country. */
function orbitraBcCountryOffsets(string $cc): array
{
    static $cache = [];
    if (isset($cache[$cc])) {
        return $cache[$cc];
    }
    $offsets = [];
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $cc) as $id) {
            $offsets[(int) ((new DateTimeZone($id))->getOffset($now) / 60)] = true;
        }
    } catch (\Throwable $e) {
    }
    return $cache[$cc] = array_keys($offsets);
}

/**
 * The detector. $sig is what /bc.js collected; $ua and $acceptLanguage come
 * from the request headers; $ipCountry from geo. Returns
 *   ['flags' => [code => 'hard'|'soft', …], 'fp' => string, 'details' => [code => text]]
 * Unknown / missing signals never produce a flag: absence is not evidence.
 */
function orbitraBcEvaluate(array $sig, string $ua, string $acceptLanguage = '', string $ipCountry = ''): array
{
    $flags = [];
    $details = [];
    $str = function ($k, $max = 200) use ($sig) {
        return isset($sig[$k]) && is_scalar($sig[$k]) ? substr((string) $sig[$k], 0, $max) : '';
    };
    $num = function ($k) use ($sig) {
        return isset($sig[$k]) && is_numeric($sig[$k]) ? (float) $sig[$k] : null;
    };

    $isIphone = (bool) preg_match('/\b(iPhone|iPod)\b/', $ua);
    $isAndroid = stripos($ua, 'Android') !== false;
    $isAndroidPhone = $isAndroid && stripos($ua, 'Mobile') !== false;
    $isMobileUa = $isIphone || $isAndroidPhone;
    $platform = $str('pf', 64);
    $gl = $str('gl', 200);

    // --- hard: automation markers the browser admits to ---------------------
    if (!empty($sig['wd']) || stripos($ua, 'HeadlessChrome') !== false || stripos($str('jua', 400), 'HeadlessChrome') !== false) {
        $flags['automation'] = 'hard';
        $details['automation'] = 'navigator.webdriver / HeadlessChrome';
    }

    // --- hard: a software or virtual-machine GPU ------------------------------
    // Real phones always have a hardware GPU; real desktops almost always do.
    // SwiftShader / llvmpipe / "Microsoft Basic Render Driver" / VirtualBox /
    // VMware renderers are what server farms and VMs report.
    if ($gl !== '' && preg_match('/swiftshader|llvmpipe|softpipe|microsoft basic render|virtualbox|vmware|parallels display|mesa offscreen|gallium.*software/i', $gl)) {
        $flags['software_gpu'] = 'hard';
        $details['software_gpu'] = $gl;
    }

    // --- hard: a phone User-Agent on a desktop platform -----------------------
    if ($platform !== '') {
        if ($isIphone && !preg_match('/^(iPhone|iPod)/i', $platform)) {
            $flags['ua_platform'] = 'hard';
            $details['ua_platform'] = 'UA iPhone, platform ' . $platform;
        } elseif ($isAndroid && preg_match('/^(Win|Mac)/i', $platform)) {
            $flags['ua_platform'] = 'hard';
            $details['ua_platform'] = 'UA Android, platform ' . $platform;
        } elseif ($isAndroid && preg_match('/x86|i686/i', $platform)) {
            // Android on x86: emulators (BlueStacks, AVD) — and a few Chromebooks.
            $flags['android_x86'] = 'soft';
            $details['android_x86'] = 'UA Android, platform ' . $platform;
        } elseif (!$isMobileUa && stripos($ua, 'Windows') !== false && preg_match('/^(Mac|Linux)/i', $platform)) {
            $flags['desktop_platform'] = 'soft';
            $details['desktop_platform'] = 'UA Windows, platform ' . $platform;
        } elseif (!$isMobileUa && stripos($ua, 'Macintosh') !== false && preg_match('/^Win/i', $platform)) {
            $flags['desktop_platform'] = 'soft';
            $details['desktop_platform'] = 'UA Mac, platform ' . $platform;
        }
    }

    // --- hard: a phone User-Agent without any touch support -------------------
    $tp = $num('tp');
    if ($isMobileUa && $tp !== null && $tp <= 0 && isset($sig['ts']) && empty($sig['ts'])) {
        $flags['mobile_no_touch'] = 'hard';
        $details['mobile_no_touch'] = 'maxTouchPoints 0, no touch events';
    }

    // --- hard: Client Hints say "not mobile" for an Android phone UA ----------
    if ($isAndroidPhone && array_key_exists('chm', $sig) && $sig['chm'] === false) {
        $flags['client_hints'] = 'hard';
        $details['client_hints'] = 'userAgentData.mobile = false';
    }

    // --- soft: timezone from another country ----------------------------------
    $tz = $str('tz', 64);
    $tzCountry = orbitraBcTimezoneCountry($tz);
    $ipCountry = strtoupper(trim($ipCountry));
    if ($tzCountry !== '' && preg_match('/^[A-Z]{2}$/', $ipCountry) && $tzCountry !== $ipCountry) {
        $tzo = $num('tzo');
        $browserOffset = $tzo !== null ? (int) -$tzo : null;
        $countryOffsets = orbitraBcCountryOffsets($ipCountry);
        if ($browserOffset === null || !$countryOffsets || !in_array($browserOffset, $countryOffsets, true)) {
            $flags['timezone'] = 'soft';
            $details['timezone'] = $tz . ' vs IP ' . $ipCountry;
        }
    }

    // --- soft: JS language disagrees with the Accept-Language header ----------
    $lang = strtolower(substr($str('lng', 64), 0, 2));
    $hdr = strtolower(substr(trim(explode(',', $acceptLanguage)[0] ?? ''), 0, 2));
    if ($lang !== '' && $hdr !== '' && preg_match('/^[a-z]{2}$/', $lang) && preg_match('/^[a-z]{2}$/', $hdr) && $lang !== $hdr) {
        $flags['language'] = 'soft';
        $details['language'] = 'JS ' . $lang . ', header ' . $hdr;
    }

    // --- soft: a zero-size outer window (old headless builds) -----------------
    // Desktop only: Android WebViews (TikTok, Instagram, FB in-app browsers)
    // legitimately report outerWidth/outerHeight 0.
    if (!$isMobileUa && !$isAndroid && $num('ow') === 0.0 && $num('oh') === 0.0) {
        $flags['zero_window'] = 'soft';
        $details['zero_window'] = 'outerWidth/outerHeight 0';
    }

    // Diagnostic fingerprint — stable signals only, NOT used for uniqueness.
    $fpSource = implode('|', [$gl, $platform, $str('sw', 8) . 'x' . $str('sh', 8), $str('dpr', 8), $str('hc', 4), $str('dm', 4), $tz, $str('lng', 64), $str('tp', 4)]);
    return ['flags' => $flags, 'fp' => substr(sha1($fpSource), 0, 16), 'details' => $details];
}

/** 'bot' when the mode acts on a flag, 'suspect' when flags exist but the mode does not act, else 'clean'. */
function orbitraBcVerdict(array $flags, string $mode): string
{
    if (!$flags) {
        return 'clean';
    }
    if ($mode === 'strict') {
        return 'bot';
    }
    if ($mode === 'soft' && in_array('hard', $flags, true)) {
        return 'bot';
    }
    return 'suspect';
}

/** The flags the current mode acts on (cloak reason codes). */
function orbitraBcActingFlags(array $flags, string $mode): array
{
    $out = [];
    foreach ($flags as $code => $level) {
        if ($mode === 'strict' || ($mode === 'soft' && $level === 'hard')) {
            $out[] = $code;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Per-request memory: a verdict computed in the cloak step before the click
// row exists is written when the click is (orbitraWriteClickFlags).
// ---------------------------------------------------------------------------

function orbitraBcRemember(?array $result = null, bool $read = false)
{
    static $pending = null;
    if ($read) {
        return $pending;
    }
    $pending = $result;
    return null;
}

/** Evaluate the `_ocb` signals of a cloak JS step. null when absent / off. */
function orbitraBcFromCloakRequest(array $customSchema, array $settings, array $visitorCtx, string $countryCode): ?array
{
    $mode = orbitraBcMode($settings + (isset($visitorCtx['pdo']) ? ['__pdo' => $visitorCtx['pdo']] : []));
    if ($mode === 'off' || !orbitraBcStreamAllows($customSchema) || !isset($_GET['_ocb'])) {
        return null;
    }
    $sig = orbitraBcDecode((string) $_GET['_ocb']);
    if ($sig === null) {
        return null;
    }
    $eval = orbitraBcEvaluate($sig, (string) ($visitorCtx['user_agent'] ?? ''), (string) ($visitorCtx['accept_language'] ?? ''), $countryCode);
    $result = [
        'mode' => $mode,
        'verdict' => orbitraBcVerdict($eval['flags'], $mode),
        'flags' => $eval['flags'],
        'acting' => orbitraBcActingFlags($eval['flags'], $mode),
        'details' => $eval['details'],
        'fp' => $eval['fp'],
        'signals' => $sig,
        'source' => 'js_step',
    ];
    orbitraBcRemember($result);
    return $result;
}

function orbitraBcStore(PDO $pdo, string $clickId, array $result): bool
{
    try {
        $flags = [];
        foreach ($result['flags'] as $code => $level) {
            $flags[] = $code . ':' . $level;
        }
        $signals = $result['signals'] ?? [];
        $signals['_details'] = $result['details'] ?? [];
        $json = json_encode($signals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $stmt = $pdo->prepare(
            "INSERT OR IGNORE INTO click_browser_checks (click_id, verdict, flags, fp, source, mode, signals, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $clickId, $result['verdict'], implode(',', $flags), $result['fp'], $result['source'], $result['mode'],
            is_string($json) ? substr($json, 0, ORBITRA_BC_MAX_SIGNAL_BYTES) : null, gmdate('Y-m-d H:i:s'),
        ]);
        $inserted = $stmt->rowCount() > 0;
        if ($inserted && $result['verdict'] === 'bot') {
            $pdo->prepare('UPDATE clicks SET is_bot = 1 WHERE id = ?')->execute([$clickId]);
        }
        orbitraBcMaybeGc($pdo);
        return $inserted;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Called by orbitraWriteClickFlags once the click row exists. */
function orbitraBcPersistPending(PDO $pdo, string $clickId): ?array
{
    $pending = orbitraBcRemember(null, true);
    if (!is_array($pending) || $clickId === '') {
        return null;
    }
    orbitraBcRemember(null);
    orbitraBcStore($pdo, $clickId, $pending);
    return $pending;
}

/** Rows follow the click statistics retention; checked at most hourly. */
function orbitraBcMaybeGc(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $last = (int) ($pdo->query("SELECT value FROM settings WHERE key = 'browser_check_gc_at'")->fetchColumn() ?: 0);
        if (time() - $last < 3600) {
            return;
        }
        $pdo->prepare("INSERT INTO settings (key, value) VALUES ('browser_check_gc_at', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([(string) time()]);
        $days = (int) ($pdo->query("SELECT value FROM settings WHERE key = 'stats_retention_days'")->fetchColumn() ?: 256);
        $days = max(7, min($days, 3650));
        $pdo->prepare('DELETE FROM click_browser_checks WHERE created_at < ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    } catch (\Throwable $e) {
    }
}

// ---------------------------------------------------------------------------
// Landing path: /bc.js posts to /pixel.gif?action=bc
// ---------------------------------------------------------------------------

function orbitraBcToken(PDO $pdo, string $clickId): string
{
    static $secret = null;
    if ($secret === null) {
        try {
            $secret = (string) ($pdo->query("SELECT value FROM settings WHERE key = 'postback_key' LIMIT 1")->fetchColumn() ?: 'orbitra_secret');
        } catch (\Throwable $e) {
            $secret = 'orbitra_secret';
        }
    }
    return substr(hash_hmac('sha256', 'bc|' . $clickId, $secret), 0, 20);
}

/**
 * Payload for /bc.js on a landing, or null: mode off, click unknown, already
 * a bot / safe-page visitor, already checked, or the stream opted out.
 */
function orbitraBcArm(PDO $pdo, string $clickId): ?array
{
    $clickId = trim($clickId);
    if ($clickId === '' || strlen($clickId) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $clickId)) {
        return null;
    }
    if (orbitraBcMode($pdo) === 'off') {
        return null;
    }
    $pending = orbitraBcRemember(null, true);
    if (is_array($pending)) {
        return null; // the cloak step already checked this visit
    }
    try {
        $stmt = $pdo->prepare("SELECT c.is_bot, c.is_safe_page, s.schema_custom_json,
                                      (SELECT 1 FROM click_browser_checks b WHERE b.click_id = c.id) AS checked
                                 FROM clicks c LEFT JOIN streams s ON s.id = c.stream_id
                                WHERE c.id = ? LIMIT 1");
        $stmt->execute([$clickId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$row || !empty($row['checked']) || (int) $row['is_safe_page'] === 1 || (int) $row['is_bot'] === 1) {
        return null;
    }
    $schema = json_decode((string) ($row['schema_custom_json'] ?? ''), true);
    if (is_array($schema) && !orbitraBcStreamAllows($schema)) {
        return null;
    }
    return ['subid' => $clickId, 'k' => orbitraBcToken($pdo, $clickId)];
}

function orbitraBcScriptTag(array $payload, string $src): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        return '';
    }
    return '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" data-orbitra-bc="'
        . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '" defer charset="utf-8"></script>';
}

/** Append /bc.js to a landing served by this tracker (no-op when not armed). */
function orbitraInjectBrowserCheck($html, $clickId)
{
    if (!is_string($html) || $html === '' || !isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        return $html;
    }
    if (stripos($html, 'data-orbitra-bc') !== false) {
        return $html;
    }
    $payload = orbitraBcArm($GLOBALS['pdo'], (string) $clickId);
    if ($payload === null) {
        return $html;
    }
    $payload['endpoint'] = '/pixel.gif';
    $tag = orbitraBcScriptTag($payload, '/bc.js');
    if ($tag === '') {
        return $html;
    }
    if (preg_match('/<\/body>/i', $html)) {
        return preg_replace('/<\/body>/i', $tag . '</body>', $html, 1);
    }
    return $html . $tag;
}

/** POST /pixel.gif?action=bc&subid=S&k=TOKEN, body = JSON signals. */
function orbitraBcIngest(PDO $pdo, ?string $rawBody = null): array
{
    $respond = function (int $code) {
        if (!headers_sent()) {
            http_response_code($code);
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
        }
    };
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        $respond(204);
        return ['ok' => false, 'reason' => 'preflight'];
    }
    $clickId = trim((string) ($_GET['subid'] ?? ''));
    if ($clickId === '' || strlen($clickId) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $clickId)
        || !hash_equals(orbitraBcToken($pdo, $clickId), (string) ($_GET['k'] ?? ''))) {
        $respond(403);
        return ['ok' => false, 'reason' => 'signature'];
    }
    $mode = orbitraBcMode($pdo);
    if ($mode === 'off') {
        $respond(204);
        return ['ok' => false, 'reason' => 'off'];
    }
    if ($rawBody === null) {
        $rawBody = (string) file_get_contents('php://input', false, null, 0, ORBITRA_BC_MAX_SIGNAL_BYTES + 1);
    }
    $sig = orbitraBcDecode($rawBody);
    if ($sig === null) {
        $respond(400);
        return ['ok' => false, 'reason' => 'payload'];
    }
    try {
        $stmt = $pdo->prepare("SELECT user_agent, accept_language_raw, country_code, country FROM clicks WHERE id = ? LIMIT 1");
        $stmt->execute([$clickId]);
        $click = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $respond(503);
        return ['ok' => false, 'reason' => 'db'];
    }
    if (!$click) {
        $respond(404);
        return ['ok' => false, 'reason' => 'click'];
    }
    $cc = (string) ($click['country_code'] ?: '');
    if (!preg_match('/^[A-Za-z]{2}$/', $cc)) {
        $cc = preg_match('/^[A-Za-z]{2}$/', (string) $click['country']) ? (string) $click['country'] : '';
    }
    $eval = orbitraBcEvaluate($sig, (string) $click['user_agent'], (string) ($click['accept_language_raw'] ?? ''), $cc);
    $result = [
        'mode' => $mode,
        'verdict' => orbitraBcVerdict($eval['flags'], $mode),
        'flags' => $eval['flags'],
        'acting' => orbitraBcActingFlags($eval['flags'], $mode),
        'details' => $eval['details'],
        'fp' => $eval['fp'],
        'signals' => $sig,
        'source' => 'landing',
    ];
    $stored = orbitraBcStore($pdo, $clickId, $result);
    $respond(204);
    return ['ok' => $stored, 'verdict' => $result['verdict'], 'flags' => $eval['flags']];
}

/** The collector, inlined into the cloak JS step (single source: /bc.js). */
function orbitraBcCollectorSource(): string
{
    static $src = null;
    if ($src === null) {
        $src = (string) @file_get_contents(dirname(__DIR__) . '/bc.js');
    }
    return $src;
}

// ---------------------------------------------------------------------------
// Panel API (admin-only: not in the resource map)
// ---------------------------------------------------------------------------

function orbitraBcStats(PDO $pdo, int $days = 7): array
{
    $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $out = ['days' => $days, 'checked' => 0, 'clean' => 0, 'suspect' => 0, 'bot' => 0, 'by_flag' => []];
    try {
        $st = $pdo->prepare("SELECT verdict, COUNT(*) AS n FROM click_browser_checks WHERE created_at >= ? GROUP BY verdict");
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['verdict']] = (int) $r['n'];
            $out['checked'] += (int) $r['n'];
        }
        $st = $pdo->prepare("SELECT flags FROM click_browser_checks WHERE created_at >= ? AND flags != '' LIMIT 50000");
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
            foreach (explode(',', (string) $f) as $pair) {
                $code = explode(':', $pair)[0];
                if ($code !== '') {
                    $out['by_flag'][$code] = ($out['by_flag'][$code] ?? 0) + 1;
                }
            }
        }
        arsort($out['by_flag']);
    } catch (\Throwable $e) {
    }
    return $out;
}

function orbitraBcApi(PDO $pdo, string $action): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        orbitraBcEnsureSchema($pdo);
    } catch (\Throwable $e) {
    }
    $get = function (string $k, $d = null) use ($pdo) {
        try {
            $st = $pdo->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
            $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v === false ? $d : $v;
        } catch (\Throwable $e) {
            return $d;
        }
    };
    $set = function (string $k, string $v) use ($pdo) {
        $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$k, $v]);
    };
    $state = function () use ($pdo, $get) {
        $mode = (string) $get('browser_check_mode', 'off');
        return [
            'mode' => in_array($mode, ORBITRA_BC_MODES, true) ? $mode : 'off',
            'offer_pending' => (string) $get('browser_check_offer_pending', '0') === '1',
            'stats' => orbitraBcStats($pdo),
        ];
    };

    try {
        if ($action === 'browser_check_settings') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $raw = function_exists('orbitraRequestBody') ? orbitraRequestBody() : (string) file_get_contents('php://input');
                $body = json_decode($raw, true);
                $body = is_array($body) ? $body : [];
                if (isset($body['mode'])) {
                    $mode = (string) $body['mode'];
                    if (!in_array($mode, ORBITRA_BC_MODES, true)) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Unknown mode']);
                        return;
                    }
                    $set('browser_check_mode', $mode);
                    $set('browser_check_offer_pending', '0');
                }
                if (!empty($body['dismiss_offer'])) {
                    $set('browser_check_offer_pending', '0');
                }
            }
            echo json_encode(['status' => 'success', 'data' => $state()], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($action === 'browser_check_click') {
            $id = trim((string) ($_GET['id'] ?? ''));
            $st = $pdo->prepare('SELECT verdict, flags, fp, source, mode, signals, created_at FROM click_browser_checks WHERE click_id = ? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $sig = json_decode((string) $row['signals'], true);
                $row['details'] = is_array($sig) ? ($sig['_details'] ?? []) : [];
                $row['gpu'] = is_array($sig) ? (string) ($sig['gl'] ?? '') : '';
                $row['platform'] = is_array($sig) ? (string) ($sig['pf'] ?? '') : '';
                $row['timezone'] = is_array($sig) ? (string) ($sig['tz'] ?? '') : '';
                unset($row['signals']);
                $flags = [];
                foreach (array_filter(explode(',', (string) $row['flags'])) as $pair) {
                    [$code, $level] = array_pad(explode(':', $pair), 2, '');
                    $flags[] = ['code' => $code, 'level' => $level, 'detail' => $row['details'][$code] ?? ''];
                }
                $row['flags'] = $flags;
                unset($row['details']);
            }
            echo json_encode(['status' => 'success', 'data' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
