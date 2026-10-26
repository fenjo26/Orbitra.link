<?php
// tests/browser_check_test.php
//
// Browser check (core/BrowserCheck.php):
//
//   1. The detector: real phones (iPhone / Android, incl. FB & TikTok in-app
//      browsers) and ordinary desktops raise NO flag; headless Chrome on a
//      server, a phone UA on a Linux VM, an emulator, a phone without touch
//      raise hard flags; VPN-like timezone / language mismatches are soft.
//   2. Modes: observe never acts, soft acts on hard flags only, strict on any.
//   3. Migration 77: a fresh install starts in soft, an upgraded one starts off
//      with the one-time offer pending.
//   4. Landing path: /bc.js is injected, a forged signature is refused, a
//      headless report marks the click as a bot, a second report is ignored,
//      a real phone stays clean, a stream can opt out.
//   5. Cloak JS step: the probe carries the collector; signals of a VM send
//      the visitor to the safe page with a bc_* reason and the verdict lands
//      on the click; a real phone gets the money page.
//
// Run: php tests/browser_check_test.php

$testPassed = true;

function assertTrue($condition, $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n  Expected: " . var_export($expected, true) . "\n  Actual:   " . var_export($actual, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $expected === $actual;
}

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/core/BrowserCheck.php';

// ---------------------------------------------------------------- 1. detector
$UA_IPHONE_FB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.97;FBBV/619000000;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/17.5;FBSS/3;FBID/phone;FBLC/en_US;FBOP/5]';
$UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
$UA_ANDROID_TT = 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.127 Mobile Safari/537.36 trill_360105 JsSdk/1.0 NetType/WIFI Channel/googleplay AppName/musical_ly app_version/36.1.5 ByteLocale/en';
$UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
$UA_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
$UA_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

$real = [
    'iPhone in the Facebook in-app browser' => [$UA_IPHONE_FB, ['wd' => false, 'pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'tz' => 'Europe/Berlin', 'tzo' => -60, 'lng' => 'de-DE', 'sw' => 393, 'sh' => 852, 'dpr' => 3, 'ow' => 393, 'oh' => 759, 'hc' => 4, 'gl' => 'Apple GPU'], 'de-DE,de;q=0.9', 'DE'],
    'iPhone Safari' => [$UA_IPHONE, ['pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'tz' => 'Europe/Athens', 'tzo' => -120, 'lng' => 'el-GR', 'gl' => 'Apple GPU'], 'el-GR,el;q=0.9', 'GR'],
    'Android in the TikTok in-app browser' => [$UA_ANDROID_TT, ['pf' => 'Linux armv8l', 'chm' => true, 'tp' => 5, 'ts' => true, 'tz' => 'America/Sao_Paulo', 'tzo' => 180, 'lng' => 'pt-BR', 'gl' => 'Mali-G715', 'ow' => 0, 'oh' => 0], 'pt-BR,pt;q=0.9', 'BR'],
    'Android Chrome' => [$UA_ANDROID, ['pf' => 'Linux aarch64', 'chm' => true, 'tp' => 5, 'ts' => true, 'tz' => 'Asia/Kolkata', 'tzo' => -330, 'lng' => 'en-IN', 'gl' => 'Adreno (TM) 740'], 'en-IN,en;q=0.9', 'IN'],
    'Windows Chrome with a GPU' => [$UA_WIN, ['pf' => 'Win32', 'chm' => false, 'tp' => 0, 'ts' => false, 'tz' => 'America/New_York', 'tzo' => 240, 'lng' => 'en-US', 'gl' => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0, D3D11)', 'ow' => 1920, 'oh' => 1040], 'en-US,en;q=0.9', 'US'],
    'Mac Safari' => [$UA_MAC, ['pf' => 'MacIntel', 'tp' => 0, 'ts' => false, 'tz' => 'Europe/London', 'tzo' => 0, 'lng' => 'en-GB', 'gl' => 'Apple M2'], 'en-GB,en;q=0.9', 'GB'],
    'iPhone with WebGL off (Lockdown) and unknown geo' => [$UA_IPHONE, ['pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'tz' => 'Europe/Kyiv', 'lng' => 'uk-UA', 'gl' => ''], '', ''],
    'US multi-timezone country: Los Angeles visitor on a US IP' => [$UA_IPHONE, ['pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'tz' => 'America/Los_Angeles', 'tzo' => 420, 'lng' => 'en-US', 'gl' => 'Apple GPU'], 'en-US', 'US'],
];
foreach ($real as $name => [$ua, $sig, $al, $cc]) {
    $e = orbitraBcEvaluate($sig, $ua, $al, $cc);
    assertEquals([], $e['flags'], "Real device — no flag: $name");
}
// The TikTok case above reports outerWidth/Height 0 — Android WebViews do that
// for real, so zero_window must never fire for a phone (asserted above). The
// rule still exists for a desktop headless build.
$e = orbitraBcEvaluate(['pf' => 'Win32', 'ow' => 0, 'oh' => 0, 'gl' => 'ANGLE (Intel)'], $UA_WIN);
assertEquals(['zero_window' => 'soft'], $e['flags'], 'Zero outer window on desktop is a soft sign');

$bots = [
    'Headless Chrome on a server' => [str_replace('Chrome/', 'HeadlessChrome/', $UA_WIN), ['wd' => true, 'pf' => 'Linux x86_64', 'gl' => 'Google SwiftShader'], ['automation', 'software_gpu']],
    'Moderator VM with a spoofed iPhone UA' => [$UA_IPHONE, ['pf' => 'Linux x86_64', 'tp' => 0, 'ts' => false, 'gl' => 'llvmpipe (LLVM 15.0.7, 256 bits)'], ['software_gpu', 'ua_platform', 'mobile_no_touch']],
    'Windows VM in VMware' => [$UA_WIN, ['pf' => 'Win32', 'gl' => 'VMware SVGA 3D'], ['software_gpu']],
    'Android UA on a Windows desktop' => [$UA_ANDROID, ['pf' => 'Win32', 'chm' => false, 'tp' => 0, 'ts' => false, 'gl' => 'ANGLE (Intel)'], ['ua_platform', 'mobile_no_touch', 'client_hints']],
];
foreach ($bots as $name => [$ua, $sig, $codes]) {
    $e = orbitraBcEvaluate($sig, $ua);
    $hard = array_keys(array_filter($e['flags'], fn($l) => $l === 'hard'));
    sort($hard);
    sort($codes);
    assertEquals($codes, $hard, "Hard flags: $name");
}
$e = orbitraBcEvaluate(['pf' => 'Linux i686', 'chm' => true, 'tp' => 5, 'ts' => true, 'gl' => 'Adreno'], $UA_ANDROID);
assertEquals(['android_x86' => 'soft'], $e['flags'], 'Android on x86 (emulator) is a soft sign');
$e = orbitraBcEvaluate(['pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'tz' => 'Asia/Bangkok', 'tzo' => -420, 'lng' => 'en-US', 'gl' => 'Apple GPU'], $UA_IPHONE, 'en-US', 'DE');
assertEquals(['timezone' => 'soft'], $e['flags'], 'VPN-like timezone from another country is a soft sign');
$e = orbitraBcEvaluate(['pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'lng' => 'ru-RU', 'gl' => 'Apple GPU'], $UA_IPHONE, 'en-US,en;q=0.9', '');
assertEquals(['language' => 'soft'], $e['flags'], 'JS language vs header mismatch is a soft sign');
$e = orbitraBcEvaluate(['pf' => 'MacIntel', 'gl' => 'Apple M1'], $UA_WIN);
assertEquals(['desktop_platform' => 'soft'], $e['flags'], 'Windows UA on a Mac platform is a soft sign');
assertEquals([], orbitraBcEvaluate([], $UA_IPHONE)['flags'], 'No signals at all → no flag (absence is not evidence)');
assertTrue(orbitraBcEvaluate($real['iPhone Safari'][1], $UA_IPHONE)['fp'] === orbitraBcEvaluate($real['iPhone Safari'][1], $UA_IPHONE)['fp'], 'Fingerprint is stable for the same signals');

// ---------------------------------------------------------------- 2. modes
$hardF = ['software_gpu' => 'hard'];
$softF = ['timezone' => 'soft'];
assertEquals('clean', orbitraBcVerdict([], 'strict'), 'No flags → clean in any mode');
assertEquals('suspect', orbitraBcVerdict($hardF, 'observe'), 'Observe never acts, even on a hard flag');
assertEquals('bot', orbitraBcVerdict($hardF, 'soft'), 'Soft acts on a hard flag');
assertEquals('suspect', orbitraBcVerdict($softF, 'soft'), 'Soft ignores a soft-only flag');
assertEquals('bot', orbitraBcVerdict($softF, 'strict'), 'Strict acts on a soft flag');
assertEquals(['software_gpu'], orbitraBcActingFlags($hardF + $softF, 'soft'), 'Soft reports only the hard flags as reasons');
assertEquals('off', orbitraBcMode(['browser_check_mode' => 'nonsense']), 'Unknown mode reads as off');
assertTrue(!orbitraBcStreamAllows(['browser_check' => 'off']) && orbitraBcStreamAllows([]), 'Stream opt-out');
assertEquals(['a' => 1], orbitraBcDecode(rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '=')), 'base64url payload decodes');
assertTrue(orbitraBcDecode(str_repeat('A', 20000)) === null, 'Oversized payload is refused');

// ---------------------------------------------------------------- 3. migration
$tmp = sys_get_temp_dir() . '/bc_mig_' . bin2hex(random_bytes(4));
@mkdir($tmp);
foreach (['fresh' => false, 'upgrade' => true] as $label => $withUser) {
    $db = "$tmp/$label.sqlite";
    $p = new PDO('sqlite:' . $db);
    $p->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
    $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)");
    if ($withUser) {
        $p->exec("INSERT INTO users (username) VALUES ('admin')");
    }
    // The migration block itself, extracted from config.php.
    $cfg = file_get_contents($repoRoot . '/config.php');
    $start = strpos($cfg, 'if ($schemaVersion < 77');
    $end = strpos($cfg, '// Mark schema as up-to-date', $start);
    $block = substr($cfg, $start, $end - $start);
    $fn = function (PDO $pdo, int $schemaVersion) use ($block, $repoRoot) {
        $__DIR__ = $repoRoot;
        eval(str_replace('__DIR__', var_export($repoRoot, true), $block));
    };
    $fn($p, 76);
    $mode = $p->query("SELECT value FROM settings WHERE key = 'browser_check_mode'")->fetchColumn();
    $offer = $p->query("SELECT value FROM settings WHERE key = 'browser_check_offer_pending'")->fetchColumn();
    if ($withUser) {
        assertEquals(['off', '1'], [$mode, $offer], 'Upgraded install: off + one-time offer');
    } else {
        assertEquals(['soft', '0'], [$mode, $offer], 'Fresh install: soft from the start, no offer');
    }
    $p->exec("UPDATE settings SET value = 'strict' WHERE key = 'browser_check_mode'");
    $fn($p, 76);
    assertEquals('strict', $p->query("SELECT value FROM settings WHERE key = 'browser_check_mode'")->fetchColumn(), "Re-running migration 77 keeps the operator's choice ($label)");
    assertTrue((bool) $p->query("SELECT 1 FROM sqlite_master WHERE name = 'click_browser_checks'")->fetchColumn(), "Table created ($label)");
}

// ---------------------------------------------------------------- 4/5. HTTP
require_once __DIR__ . '/lib/http.php';
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

$request = function (string $path, array $headers = [], string $method = 'GET', ?string $body = null) use ($harness): array {
    $opts = ['timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0, 'method' => $method, 'header' => implode("\r\n", $headers)];
    if ($body !== null) {
        $opts['content'] = $body;
    }
    $resp = @file_get_contents($harness->getBaseUrl() . '/' . ltrim($path, '/'), false, stream_context_create(['http' => $opts]));
    $lines = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []);
    $code = 0;
    $loc = '';
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d{3})#', (string) $h, $m)) {
            $code = (int) $m[1];
        } elseif (stripos($h, 'Location:') === 0) {
            $loc = trim(substr($h, 9));
        }
    }
    return ['code' => $code, 'body' => (string) $resp, 'location' => $loc];
};
$hdr = fn(string $ua, string $ip) => ["User-Agent: $ua", "X-Forwarded-For: $ip", 'Accept-Language: en-US,en;q=0.9'];
$b64 = fn(array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
$vmSig = ['pf' => 'Linux x86_64', 'tp' => 0, 'ts' => false, 'gl' => 'Google SwiftShader', 'tz' => 'UTC', 'lng' => 'en-US'];
$phoneSig = ['pf' => 'iPhone', 'tp' => 5, 'ts' => true, 'gl' => 'Apple GPU', 'lng' => 'en-US'];

try {
    $pdo = $harness->getPdo();
    $request('/', $hdr($UA_IPHONE, '203.0.113.1')); // first request runs the migrations
    assertEquals('soft', $pdo->query("SELECT value FROM settings WHERE key = 'browser_check_mode'")->fetchColumn(), 'Harness (fresh install, no users) starts in soft');

    $cid = random_int(100000, 899990);
    $lid = $cid + 1;
    $oid = $cid + 2;
    $sid = $cid + 3;
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'BC Offer', 'https://money.example/offer', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived) VALUES (?, 'BC LP', '', 'action', 'show_html', '<html><body>BC_LP <a href=\"/?_lp=1\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'BC Camp', 'bccamp$cid', '', 'active', 0)")->execute([$cid]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection) VALUES (?, ?, NULL, 'LP', 'regular', 1, 'landing_offer', ?, 1, 1, 'after')")
        ->execute([$sid, $cid, json_encode(['landings' => [['id' => $lid, 'weight' => 100]], 'offers' => [['id' => $oid, 'weight' => 100]]])]);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'bc_key')");

    // --- 4. landing path
    $r = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.10'));
    assertTrue(strpos($r['body'], 'src="/bc.js"') !== false, 'Landing carries /bc.js');
    $click = (string) $pdo->query("SELECT id FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    preg_match('/data-orbitra-bc="([^"]+)"/', $r['body'], $m);
    $p = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
    assertEquals($click, $p['subid'] ?? null, 'The collector is bound to this click');

    $bad = $request('/pixel.gif?action=bc&subid=' . $click . '&k=forged', [], 'POST', json_encode($vmSig));
    assertEquals(403, $bad['code'], 'Forged signature refused');
    $ok = $request('/pixel.gif?action=bc&subid=' . $click . '&k=' . $p['k'], [], 'POST', json_encode($vmSig));
    assertEquals(204, $ok['code'], 'Signed report accepted');
    $row = $pdo->query("SELECT * FROM click_browser_checks WHERE click_id = " . $pdo->quote($click))->fetch(PDO::FETCH_ASSOC);
    assertEquals('bot', $row['verdict'] ?? null, 'VM signals on an iPhone UA → bot (soft)');
    assertEquals('landing', $row['source'] ?? null, 'Source: landing');
    assertEquals(1, (int) $pdo->query("SELECT is_bot FROM clicks WHERE id = " . $pdo->quote($click))->fetchColumn(), 'The click is marked as a bot');
    $request('/pixel.gif?action=bc&subid=' . $click . '&k=' . $p['k'], [], 'POST', json_encode($phoneSig));
    assertEquals('bot', $pdo->query("SELECT verdict FROM click_browser_checks WHERE click_id = " . $pdo->quote($click))->fetchColumn(), 'A second report cannot whitewash the verdict');

    usleep(2100000);
    $r = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.11'));
    $click2 = (string) $pdo->query("SELECT id FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    $request('/pixel.gif?action=bc&subid=' . $click2 . '&k=' . orbitraBcToken($pdo, $click2), [], 'POST', json_encode($phoneSig));
    assertEquals('clean', $pdo->query("SELECT verdict FROM click_browser_checks WHERE click_id = " . $pdo->quote($click2))->fetchColumn(), 'A real iPhone stays clean');
    assertEquals(0, (int) $pdo->query("SELECT COALESCE(is_bot,0) FROM clicks WHERE id = " . $pdo->quote($click2))->fetchColumn(), 'and is not a bot');

    $pdo->prepare("UPDATE streams SET schema_custom_json = ? WHERE id = ?")
        ->execute([json_encode(['landings' => [['id' => $lid, 'weight' => 100]], 'offers' => [['id' => $oid, 'weight' => 100]], 'browser_check' => 'off']), $sid]);
    usleep(2100000);
    $r = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.12'));
    assertTrue(strpos($r['body'], 'BC_LP') !== false && strpos($r['body'], 'data-orbitra-bc') === false, 'Stream opt-out: no collector');

    $pdo->exec("UPDATE settings SET value = 'off' WHERE key = 'browser_check_mode'");
    $pdo->prepare("UPDATE streams SET schema_custom_json = ? WHERE id = ?")
        ->execute([json_encode(['landings' => [['id' => $lid, 'weight' => 100]], 'offers' => [['id' => $oid, 'weight' => 100]]]), $sid]);
    usleep(2100000);
    $r = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.13'));
    assertTrue(strpos($r['body'], 'data-orbitra-bc') === false, 'Mode off: no collector');
    $pdo->exec("UPDATE settings SET value = 'soft' WHERE key = 'browser_check_mode'");

    // --- 5. cloak JS step
    $cloak = [
        'detect_datacenter' => false, 'detect_vpn' => false, 'detect_bots' => false, 'detect_ua' => false,
        'js_challenge' => true, 'safe_mode' => 'html',
        'safe_html' => '<!DOCTYPE html><html><body>SAFE_PAGE_BC</body></html>',
        'offers' => [['id' => $oid, 'weight' => 100]],
    ];
    $pdo->prepare("UPDATE streams SET schema_type = 'cloak', schema_custom_json = ? WHERE id = ?")->execute([json_encode($cloak), $sid]);

    usleep(2100000);
    $r1 = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.20'));
    assertTrue(strpos($r1['body'], 'SAFE_PAGE_BC') !== false && strpos($r1['body'], 'OrbitraBC') !== false, 'JS step: safe page + inlined collector');
    assertTrue(strpos($r1['body'], 'withSignals(') !== false, 'JS step: the next URL is built with the signals');
    preg_match('/withSignals\(("(?:[^"\\\\]|\\\\.)*")\)/', $r1['body'], $m);
    $next = json_decode($m[1] ?? '""');
    assertTrue(is_string($next) && strpos($next, '_ocj=') !== false, 'JS step: signed next URL found');

    $r2 = $request("/bccamp$cid" . $next . '&_ocb=' . $b64($vmSig), $hdr($UA_IPHONE, '198.51.100.20'));
    assertTrue(strpos($r2['body'], 'SAFE_PAGE_BC') !== false && $r2['location'] === '', 'VM signals → safe page, no money redirect');
    $c = $pdo->query("SELECT id, is_safe_page, cloak_reasons, is_bot FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTrue(strpos((string) $c['cloak_reasons'], 'bc_software_gpu') !== false, 'Cloak reason carries bc_software_gpu (' . $c['cloak_reasons'] . ')');
    assertEquals(1, (int) $c['is_safe_page'], 'Logged as a safe-page click');
    assertEquals(1, (int) $c['is_bot'], 'and as a bot');
    $bcRow = $pdo->query("SELECT verdict, source FROM click_browser_checks WHERE click_id = " . $pdo->quote($c['id']))->fetch(PDO::FETCH_ASSOC);
    assertEquals(['verdict' => 'bot', 'source' => 'js_step'], $bcRow ?: null, 'Verdict stored for the click, source js_step');

    usleep(2100000);
    $r1 = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.21'));
    preg_match('/withSignals\(("(?:[^"\\\\]|\\\\.)*")\)/', $r1['body'], $m);
    $next = json_decode($m[1] ?? '""');
    $r3 = $request("/bccamp$cid" . $next . '&_ocb=' . $b64($phoneSig), $hdr($UA_IPHONE, '198.51.100.21'));
    assertTrue(strpos($r3['location'], 'money.example/offer') !== false, 'A real iPhone gets the money page (' . $r3['code'] . ' ' . $r3['location'] . ')');
    $c3 = (string) $pdo->query("SELECT id FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    assertEquals('clean', $pdo->query("SELECT verdict FROM click_browser_checks WHERE click_id = " . $pdo->quote($c3))->fetchColumn(), 'and its check is stored as clean');

    // Observe mode in the JS step: nothing is blocked.
    $pdo->exec("UPDATE settings SET value = 'observe' WHERE key = 'browser_check_mode'");
    usleep(2100000);
    $r1 = $request("/bccamp$cid", $hdr($UA_IPHONE, '198.51.100.22'));
    preg_match('/withSignals\(("(?:[^"\\\\]|\\\\.)*")\)/', $r1['body'], $m);
    $next = json_decode($m[1] ?? '""');
    $r4 = $request("/bccamp$cid" . $next . '&_ocb=' . $b64($vmSig), $hdr($UA_IPHONE, '198.51.100.22'));
    assertTrue(strpos($r4['location'], 'money.example/offer') !== false, 'Observe mode: even VM signals pass to the money page');
    $c4 = (string) $pdo->query("SELECT id FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    assertEquals('suspect', $pdo->query("SELECT verdict FROM click_browser_checks WHERE click_id = " . $pdo->quote($c4))->fetchColumn(), 'but the click is stored as suspect');
} finally {
    $harness->stop();
}

if ($testPassed) {
    echo "\nAll browser check tests passed.\n";
    exit(0);
}
fwrite(STDERR, "\nBrowser check tests FAILED.\n");
exit(1);
