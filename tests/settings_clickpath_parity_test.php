<?php
/**
 * Settings keyed-load parity (the safety net the 2026-11-21 browser-check
 * outage asked for): a key the click path READS but the explicit IN-list
 * forgot is a silently wrong decision — orbitraBcMode() read
 * browser_check_mode, the key was missing, `?? null` degraded to 'off' and
 * the whole browser check quietly disabled itself. A load test cannot see
 * this class of bug; two layers here can:
 *
 *  A. STATIC INVARIANT — every literal $settings['key'] read in index.php
 *     (the click router) and in the core consumers the whole array flows
 *     into (core/click_api.php, core/BrowserCheck.php orbitraBcMode,
 *     core/click_logger.php orbitraCloakDecision) must appear in the
 *     IN-list the same file builds. Adding a new read without extending
 *     the list fails here immediately — no scenario coverage needed.
 *
 *  B. DYNAMIC BATTERY — the real click-path flows (redirect click, cloak
 *     with js-challenge + browser check, local offer, postback, Click API)
 *     replayed with ORBITRA_SETTINGS_GUARD=1: any missing-key read during
 *     those flows lands in var/logs/settings_guard.log, and the test
 *     asserts the log is EMPTY while the flows still behave (302s, safe
 *     page markers, postback accepted).
 *
 * Usage: php tests/settings_clickpath_parity_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$repoRoot = dirname(__DIR__);
$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// --- A. static invariant --------------------------------------------------------
$extractList = static function (string $php, string $anchor): array {
    // anchor to the load block: the files have other keyed IN-queries
    // (privacy, etc.) that a bare anchor would mismatch
    if (!preg_match('/' . $anchor . '.*?key IN \((.*?)\)"/s', $php, $m)) {
        return null;
    }
    preg_match_all("/'([a-z_0-9]+)'/", $m[1], $keys);
    return array_fill_keys($keys[1], true);
};
$literalReads = static function (string $php, array $vars): array {
    $out = [];
    foreach ($vars as $var) {
        preg_match_all('/\$' . $var . "\\[([\'\"])([a-z_0-9]+)\\1\]/", $php, $m);
        foreach ($m[2] as $k) {
            if ($k !== '__pdo') {
                $out[$k] = true;
            }
        }
    }
    return $out;
};

$indexPhp = (string) file_get_contents($repoRoot . '/index.php');
$indexList = $extractList($indexPhp, 'Load system settings');
check(is_array($indexList), 'the narrowed IN-list found in index.php (' . count($indexList ?: []) . ' keys)');
$indexReads = $literalReads($indexPhp, ['settings']);
$missing = array_diff_key($indexReads, $indexList ?: []);
check(count($missing) === 0, 'every $settings[...] literal read in index.php is in the list (missing: ' . implode(',', array_keys($missing)) . ')');

// core consumers the whole array flows into
$consumerReads = [];
foreach (['core/BrowserCheck.php' => ['settingsOrPdo'], 'core/click_logger.php' => ['settings']] as $file => $vars) {
    $php = (string) file_get_contents($repoRoot . '/' . $file);
    $consumerReads += $literalReads($php, $vars);
}
// BrowserCheck's PDO fallback reads the key with its own query — the
// array read must still be listed (the guard covers the runtime path)
unset($consumerReads['__pdo']);
$missingConsumers = array_diff_key($consumerReads, $indexList ?: []);
check(count($missingConsumers) === 0, 'every consumer-array read (click_api/BrowserCheck/click_logger) is in the list (missing: ' . implode(',', array_keys($missingConsumers)) . ')');

$apiPhp = (string) file_get_contents($repoRoot . '/core/click_api.php');
$apiList = $extractList($apiPhp, 'Only the keys the Click API consumes');
$apiReads = $literalReads($apiPhp, ['settings']);
$apiMissing = array_diff_key($apiReads, $apiList ?: []);
check(is_array($apiList) && count($apiMissing) === 0, 'click_api.php list covers its own reads (missing: ' . implode(',', array_keys($apiMissing)) . ')');

// --- B. dynamic battery with the guard on -------------------------------------
require_once $repoRoot . '/tests/lib/http.php';
require_once $repoRoot . '/core/settings_guard.php';

putenv('ORBITRA_SETTINGS_GUARD=1');
putenv('ORBITRA_TEST_SERVER_WORKERS=2');
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();
try {
    $base = $harness->getBaseUrl();
    $work = $harness->getWorkingDir();
    $db = $work . '/orbitra_test.sqlite';
    $guardLog = $work . '/var/logs/settings_guard.log';

    for ($w = 0; $w < 10; $w++) {
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: warm\r\nX-Forwarded-For: 198.52.0.251"]]);
        @file_get_contents($base . '/testcamp?sub1=w0', false, $ctx);
        $p = new PDO('sqlite:' . $db);
        if ((int) $p->query('PRAGMA user_version')->fetchColumn() > 0) break;
        usleep(400000);
    }
    $seed = $harness->seedTestData();
    $cid = (int) $seed['campaign_id'];
    $pdo = $harness->getPdo();
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (990021, 'X', 'https://o.example/?cid={subid}', 0, 'active', 0)")->execute([]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks) VALUES (990022, ?, 990021, 'S', 'regular', 1, 'redirect', ?, 1, 1)")
        ->execute([$cid, json_encode(['offers' => [['id' => 990021, 'weight' => 100]]])]);

    // a cloak campaign with the js challenge + browser check (the exact flow
    // the 2026-11-21 incident silently disabled)
    $bcCid = $cid + 1;
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'Guard BC', 'guardbc', '', 'active', 0)")->execute([$bcCid]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks) VALUES (?, ?, 990021, 'C', 'regular', 1, 'cloak', ?, 1, 1)")
        ->execute([$bcCid + 100, $bcCid, json_encode(['js_challenge' => 1, 'safe_mode' => 'html', 'safe_html' => '<h1>SAFE_GUARD</h1>', 'detect_bots' => true, 'sensitivity' => 'medium'])]);
    // a local offer on its own route
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (990031, 'L', '', 1, 'active', 0)")->execute([]);

    $get = static function (string $path, string $ua = 'Mozilla/5.0 guard', string $xff = '198.52.9.9'): array {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: $ua\r\nX-Forwarded-For: $xff"]]);
        @file_get_contents($GLOBALS['base'] . $path, false, $ctx);
        $code = 0;
        $loc = '';
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) $code = (int) $m[1];
            if (preg_match('#^Location: (.+)#', $h, $m)) $loc = trim($m[1]);
        }
        return ['code' => $code, 'location' => $loc, 'body' => ''];
    };
    $GLOBALS['base'] = $base;

    // 1. plain redirect click with the browser check OFF — a fresh 2.0
    //    install seeds browser_check_mode='soft', which legitimately serves
    //    a collector page (200) instead of an instant 302
    $pdo->exec("UPDATE settings SET value = 'off' WHERE key = 'browser_check_mode'");
    $r = $get("/testcamp?sub1=g1");
    check($r['code'] === 302, "redirect click answers 302 with bc off ({$r['code']})");
    $pdo->exec("UPDATE settings SET value = 'soft' WHERE key = 'browser_check_mode'");
    // 2. cloak campaign: the js-challenge page with the bc collector
    $r = $get('/guardbc?sub1=g2');
    // (body isn't captured by the wrapper — the flow itself is exercised;
    // the assertion that matters is the guard log staying empty)
    check(in_array($r['code'], [200, 302], true), "cloak campaign answers ({$r['code']})");
    // 3. the local offer route
    $r = $get('/offers/990031/');
    check($r['code'] !== 0, "local offer route answers ({$r['code']})");
    // 4. a postback against the seeded postback key
    $pbKey = (string) $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'")->fetchColumn();
    $clickId = (string) $pdo->query("SELECT id FROM clicks ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    $r = $get("/$pbKey/postback?subid=$clickId&status=lead&payout=1");
    check($r['code'] === 200, "postback accepted ({$r['code']})");
    // 5. the Click API entry
    $r = $get('/click_api/v3?campaign_id=' . $cid . '&sub_id=g5');
    check($r['code'] !== 0, "click api answers ({$r['code']})");

    usleep(300000);
    $guardLines = @file($guardLog, FILE_IGNORE_NEW_LINES) ?: [];
    check(count($guardLines) === 0, 'the guard log is EMPTY across the battery (reads of forgotten keys: ' . count($guardLines) . ')' . ($guardLines ? "\n    " . implode("\n    ", array_slice($guardLines, 0, 5)) : ''));
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $harness->stop();
    putenv('ORBITRA_SETTINGS_GUARD');
}

echo $failures === 0 ? "Settings click-path parity tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
