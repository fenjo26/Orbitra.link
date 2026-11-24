<?php
// tests/unique_os_version_filter_test.php
//
// Two stream filters: "Unique" (unique / non-unique click) and "OS version".
//
// An operator moving over from Keitaro asked for both: Keitaro's uniqueness
// filter ("a unique click sees the stream, the rest go to the white page")
// and a cut by OS version against the fake old-iPhone bots ("iOS 3–8 in the
// log is never a real phone in 2026").
//
// Both live in the shared matcher (core/stream_match.php), so every entry
// point and the simulator get them at once. The uniqueness is read from the
// clicks table only when a stream of the campaign filters on it.
//
// Run: php tests/unique_os_version_filter_test.php

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/stream_match.php';

$ok = true;
function check(bool $cond, string $msg): void
{
    global $ok;
    if ($cond) {
        echo "✓ $msg\n";
    } else {
        fwrite(STDERR, "FAILED: $msg\n");
        $ok = false;
    }
}

$fails = 0;
$eq = function (string $label, $got, $expected) use (&$fails): void {
    if ($got !== $expected) {
        $fails++;
        fwrite(STDERR, "FAILED: {$label} — got " . var_export($got, true) . ', expected ' . var_export($expected, true) . "\n");
        return;
    }
    echo "✓ {$label}\n";
};

$pdo = new PDO('sqlite::memory:');
$ios17 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
$ios8 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 8_3 like Mac OS X) AppleWebKit/600.1.4 (KHTML, like Gecko) Version/8.3 Mobile/12F70 Safari/600.1.4';
$ios3 = 'Mozilla/5.0 (iPhone; U; CPU iPhone OS 3_0 like Mac OS X; en-us) AppleWebKit/528.18 (KHTML, like Gecko) Version/4.0 Mobile/7A341 Safari/528.16';
$android = 'Mozilla/5.0 (Linux; Android 10; SM-A105F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36';
$windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

// --- "OS version": the value against a reported OS and version ---------------------
foreach ([
    ['iOS 16', 'ios', '16.5', true], ['ios 16', 'ios', '16.5', true], ['iOS 16', 'ios', '17.0', false],
    ['iOS 15-18', 'ios', '16.5', true], ['iOS 15-18', 'ios', '18.6', true], ['iOS 15-18', 'ios', '19.0', false], ['iOS 15-18', 'ios', '14.8', false],
    ['iOS <=14', 'ios', '14.8', true], ['iOS <=14', 'ios', '15.0', false], ['iOS <15', 'ios', '14.9', true],
    ['iOS >=15', 'ios', '15.0', true], ['iOS >=15', 'ios', '14.9', false], ['iOS >16', 'ios', '16.4', false], ['iOS >16', 'ios', '17.0', true],
    ['iOS 16.4', 'ios', '16.4', true], ['iOS 16.4', 'ios', '16.5', false], ['iOS 16.4', 'ios', '16', false],
    ['iOS <=9', 'ios', '8.3', true], ['iOS <=9', 'ios', '3.0', true], ['iOS <=9', 'ios', '17.5', false],
    ['Android 10', 'android', '10', true], ['Android 13', 'android', '10', false],
    ['iOS >14', 'ios', '14.8', false], ['iOS >14', 'ios', '15.0', true], ['iOS <=16.4', 'ios', '16.4', true], ['iOS <=16.4', 'ios', '16.5', false],
    ['macOS 10.15', 'macos', '10.15.7', true], ['mac 10.15', 'macos', '10.15.7', true],
    ['iOS 16', 'android', '16', false],            // another OS never matches
    ['iOS', 'ios', '16.5', false], ['iOS 9-', 'ios', '16.5', false], ['garbage', 'ios', '16.5', false],
    ['iOS 16', 'ios', '', false],                  // no version reported
] as [$token, $os, $version, $expected]) {
    $eq("'{$token}' vs {$os} {$version}", orbitraOsVersionTokenMatches($token, $os, $version), $expected);
}

// --- the stream filter itself ----------------------------------------------------------
$matches = static function (array $filters, string $ua, ?bool $isUnique = null) use ($pdo): array {
    $visitor = ['ip' => '73.1.2.3', 'country' => 'US', 'device' => 'Mobile', 'os' => 'iOS', 'browser' => 'Safari', 'languageCodes' => ['en'],
        'userAgent' => $ua, 'referer' => '', 'params' => [], 'rawParams' => [], 'timezone' => 'UTC', 'isp' => '', 'asn' => '',
        'acceptLanguage' => 'en-US,en;q=0.9', 'isUnique' => $isUnique];
    $trace = [];
    $stream = ['id' => 9, 'filters_json' => json_encode($filters), 'filters_logic' => 'and'];
    $result = streamMatchesFilters($stream, $visitor, $pdo, $trace);
    return [$result, $trace[0]['result'] ?? null];
};
$f = static fn (string $name, array $payload, string $mode = 'include'): array => ['name' => $name, 'mode' => $mode, 'payload' => $payload];

$eq('OS version "iOS <=9" takes an iOS 8 visit', $matches([$f('OS version', ['iOS <=9'])], $ios8), [true, 'match']);
$eq('OS version "iOS <=9" takes an iOS 3 visit', $matches([$f('OS version', ['iOS <=9'])], $ios3), [true, 'match']);
$eq('OS version "iOS <=9" lets iOS 17 through', $matches([$f('OS version', ['iOS <=9'])], $ios17), [false, 'no-match']);
$eq('OS version "iOS 15-18" takes iOS 17', $matches([$f('OS version', ['iOS 15-18'])], $ios17), [true, 'match']);
$eq('OS version "iOS 15-18" lets Android 10 through', $matches([$f('OS version', ['iOS 15-18'])], $android), [false, 'no-match']);
$eq('OS version "Android 10" takes the Android UA', $matches([$f('OS version', ['Android 10'])], $android), [true, 'match']);
$eq('OS version abstains for Windows, whose two systems share one version', $matches([$f('OS version', ['iOS 16'])], $windows)[1], 'abstain');
$eq('several values, any is enough', $matches([$f('OS version', ['iOS <=9', 'iOS 17'])], $ios17), [true, 'match']);
$eq('exclude flips it', $matches([$f('OS version', ['iOS <=9'], 'exclude')], $ios8), [false, 'match']);
$eq('with the OS filter beside it: iOS 8 passes "OS iOS" too',
    streamMatchesFilters(['id' => 9, 'filters_json' => json_encode([$f('OS', ['iOS']), $f('OS version', ['iOS <=9'])]), 'filters_logic' => 'and'],
        ['ip' => '1.2.3.4', 'country' => 'US', 'device' => 'Mobile', 'os' => 'iOS', 'browser' => 'Safari', 'languageCodes' => [], 'userAgent' => $ios8,
         'referer' => '', 'params' => [], 'rawParams' => [], 'timezone' => '', 'isp' => '', 'asn' => '', 'acceptLanguage' => 'en'], $pdo), true);

$eq('Unique "unique" takes a first click', $matches([$f('Unique', ['unique'])], $ios17, true), [true, 'match']);
$eq('Unique "unique" refuses a returning click', $matches([$f('Unique', ['unique'])], $ios17, false), [false, 'no-match']);
$eq('Unique "non-unique" takes a returning click', $matches([$f('Unique', ['non-unique'])], $ios17, false), [true, 'match']);
$eq('Unique "non-unique" refuses a first click', $matches([$f('Unique', ['non-unique'])], $ios17, true), [false, 'no-match']);
$eq('Unique abstains when uniqueness was not evaluated', $matches([$f('Unique', ['unique'])], $ios17, null), [true, 'abstain']);
$eq('Unique with a value nobody knows abstains', $matches([$f('Unique', ['perhaps'])], $ios17, true), [true, 'abstain']);
$eq('Unique "yes" reads as unique', $matches([$f('Unique', ['yes'])], $ios17, true)[0], true);
$eq('Unique exclude flips it', $matches([$f('Unique', ['unique'], 'exclude')], $ios17, true), [false, 'match']);
$eq('"Uniqueness" (another spelling) is still an unknown filter and abstains', $matches([$f('Uniqueness', ['yes'])], $ios17, false), [true, 'abstain']);

$eq('campaign without the filter does not need the probe', orbitraStreamsNeedUniqueness([
    ['filters_json' => '[{"name":"OS","payload":["iOS"]}]'], ['filters_json' => '[]'], ['filters_json' => 'broken'],
]), false);
$eq('a stream with the filter does', orbitraStreamsNeedUniqueness([
    ['filters_json' => '[{"name":"OS","payload":["iOS"]}]'], ['filters_json' => '[{"name":"Unique","payload":["unique"]}]'],
]), true);
// --- the Keitaro import maps its own filters onto these two ------------------------
require_once __DIR__ . '/../core/keitaro_import.php';
$dropped = [];
$eq('Keitaro "uniqueness: unique" → Unique unique',
    orbitraKeitaroBuildOrbitraFilters([['name' => 'uniqueness', 'payload' => 'unique']], $dropped),
    [['name' => 'Unique', 'mode' => 'include', 'payload' => ['unique']]]);
$eq('Keitaro "uniqueness: non_unique" → Unique non-unique',
    orbitraKeitaroBuildOrbitraFilters([['name' => 'uniqueness', 'payload' => 'non_unique']], $dropped),
    [['name' => 'Unique', 'mode' => 'include', 'payload' => ['non-unique']]]);
$eq('Keitaro "os_version: iOS 15-18" → OS version',
    orbitraKeitaroBuildOrbitraFilters([['name' => 'os_version', 'payload' => 'iOS 15-18']], $dropped),
    [['name' => 'OS version', 'mode' => 'include', 'payload' => ['iOS 15-18']]]);

$routerSource = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$eq('the router keeps the probe behind that condition (one call, guarded)',
    [substr_count($routerSource, 'orbitraFindUniquenessConflict($'), substr_count($routerSource, 'orbitraStreamsNeedUniqueness(')], [1, 1]);

if ($fails) {
    echo "\n{$fails} failure(s)\n";
    $ok = false;
}

// --- end to end: a first click and a returning one land in different streams ---------
$harness = new OrbitraTestHarness(dirname(__DIR__));
try {
    $harness->start();
    $data = $harness->seedCloakTestData();
    $pdo = $harness->getPdo();
    $cid = $data['campaign_id'];
    $harness->setSetting('click_debounce_seconds', '0');
    $pdo->prepare("UPDATE campaigns SET uniqueness_hours = 24, uniqueness_method = 'IP' WHERE id = ?")->execute([$cid]);

    $offerNew = random_int(300000, 399999);
    $offerBack = random_int(400000, 499999);
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'New', 'https://example.com/first-click', 0, 'active', 0)")->execute([$offerNew]);
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'Back', 'https://example.com/returning', 0, 'active', 0)")->execute([$offerBack]);

    // [0] fake old iPhones get a 404; [1] first clicks; [2] everyone back for more.
    $pdo->prepare("DELETE FROM streams WHERE campaign_id = ?")->execute([$cid]);
    $ins = $pdo->prepare("INSERT INTO streams (campaign_id, offer_id, name, type, position, schema_type, action_payload, schema_custom_json, filters_json, filters_logic, is_active, collect_clicks)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'and', 1, 1)");
    $ins->execute([$cid, null, 'Old iPhones', 'intercepting', 0, 'action', 'not_found', '[]', json_encode([['name' => 'OS version', 'mode' => 'include', 'payload' => ['iOS <=9']]])]);
    $ins->execute([$cid, $offerNew, 'First click', 'regular', 1, 'redirect', '', json_encode(['offers' => [['id' => $offerNew, 'weight' => 100]]]), json_encode([['name' => 'Unique', 'mode' => 'include', 'payload' => ['unique']]])]);
    $ins->execute([$cid, $offerBack, 'Returning', 'regular', 2, 'redirect', '', json_encode(['offers' => [['id' => $offerBack, 'weight' => 100]]]), json_encode([['name' => 'Unique', 'mode' => 'include', 'payload' => ['non-unique']]])]);
    $ins->execute([$cid, null, 'Nobody', 'fallback', 1000001, 'action', 'do_nothing', '[]', '[]']);

    $visit = static function (string $ua, string $ip) use ($harness, $cid): string {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "User-Agent: {$ua}\r\nX-Forwarded-For: {$ip}\r\nAccept-Language: en-US,en;q=0.9"]]);
        $body = (string) @file_get_contents($harness->getBaseUrl() . '/?campaign_id=' . $cid, false, $ctx);
        $status = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        if ($status === 404) return '404';
        if (str_contains($location, '/first-click')) return 'first-click offer';
        if (str_contains($location, '/returning')) return 'returning offer';
        return "other({$status})";
    };

    check($visit($ios8, '73.5.5.1') === '404', 'an iPhone on iOS 8 gets the intercepting stream\'s 404');
    check($visit($ios3, '73.5.5.2') === '404', 'an iPhone on iOS 3 gets the intercepting stream\'s 404');
    check($visit($ios17, '73.5.5.3') === 'first-click offer', 'an iPhone on iOS 17 is not stopped by "iOS <=9"');
    check($visit($ios17, '73.5.5.3') === 'returning offer', '…and its second click from the same IP goes to the returning stream');
    check($visit($ios17, '73.5.5.4') === 'first-click offer', 'another visitor\'s first click goes to the first-click stream');
    check($visit($android, '73.5.5.4') === 'returning offer', 'the same IP with another browser is still a returning click (this campaign counts uniqueness by IP)');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    $ok = false;
} finally {
    $harness->stop();
}

exit($ok ? 0 : 1);
