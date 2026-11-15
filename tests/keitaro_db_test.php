<?php
/**
 * Operator-uploaded databases in Keitaro's formats, and the mobile operator /
 * connection type they feed (core/KeitaroDb.php, core/geo_databases.php,
 * core/StreamFilters.php).
 *
 * The repository ships no such database, so the test BUILDS small files in
 * the documented layout and reads them back:
 *
 *  - GDBC geo lookups, IPv4 and IPv6, hits, gaps and out-of-range addresses;
 *  - DBCA lists (operators, bots), a range that spans several buckets;
 *  - a truncated / foreign file is "not found", never an error;
 *  - upload classification by content (a list called *.dat is not Sypex, the
 *    geo file has no extension), installation into its own slot;
 *  - orbitraLookupNetwork(): operator → mobile, IPv4 outside every operator
 *    range → wifi, IPv6 → undetermined, IP2Location fields and their
 *    "unavailable" placeholders;
 *  - the Connection / Operator matchers and their synonyms;
 *  - over real HTTP: an Operator stream filter routes a visitor, the click
 *    stores carrier + connection_type, and with no source the filter abstains.
 *
 * Usage: php tests/keitaro_db_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/geo_databases.php';
require_once __DIR__ . '/../core/StreamFilters.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

/** DBCA list: $ranges = [[startIp, endIp, label], ...] (sorted). */
function buildKeitaroList(array $ranges, int $step = 4096): string
{
    $labelOffsets = [];
    $body = '';
    foreach ($ranges as [, , $label]) {
        if (!isset($labelOffsets[$label])) {
            $labelOffsets[$label] = 20 + strlen($body);
            $body .= pack('V', strlen($label)) . $label;
        }
    }
    $min = ip2long($ranges[0][0]);
    $top = 0;
    foreach ($ranges as [, $end]) {
        $top = max($top, ip2long($end));
    }
    $buckets = intdiv($top - $min, $step) + 1;
    $bucketData = '';
    $table = '';
    $base = 20 + strlen($body);
    for ($b = 0; $b < $buckets; $b++) {
        $from = $min + $b * $step;
        $to = $from + $step - 1;
        $entries = '';
        $count = 0;
        foreach ($ranges as [$start, $end, $label]) {
            if (ip2long($start) <= $to && ip2long($end) >= $from) {
                $entries .= pack('VVV', ip2long($start), ip2long($end), $labelOffsets[$label]);
                $count++;
            }
        }
        $table .= pack('V', $base + strlen($bucketData));
        $bucketData .= pack('V', $count) . $entries;
    }
    return 'DBCA' . pack('VVVV', 1, 1, time(), count($labelOffsets)) . $body . $bucketData . $table
        . pack('VVV', $min, $min + $buckets * $step, $step);
}

/**
 * GDBC geo file. $v4 = [[startIp, endIp, cc, region, city, isp], ...];
 * $v6 = the same with the low 32 bits of 2001:db8::/96 as integers.
 */
function buildKeitaroGeo(array $v4, array $v6, int $step4 = 65536, int $step6 = 0x1000): string
{
    $sections = ['region' => '', 'city' => '', 'isp' => ''];
    $offsets = ['region' => [], 'city' => [], 'isp' => []];
    $intern = static function (string $section, string $value) use (&$sections, &$offsets): int {
        if (!isset($offsets[$section][$value])) {
            $offsets[$section][$value] = strlen($sections[$section]);
            $sections[$section] .= chr(strlen($value)) . $value;
        }
        return $offsets[$section][$value];
    };
    $records = '';
    $pointer = [];
    foreach (array_merge($v4, $v6) as $i => [, , $cc, $region, $city, $isp]) {
        $pointer[$i] = strlen($records);
        $records .= $cc . pack('VVV', $intern('region', $region), $intern('city', $city), $intern('isp', $isp));
    }
    $v6prefix = inet_pton('2001:db8::') ;
    $v6addr = static fn(int $low): string => substr($v6prefix, 0, 12) . pack('N', $low);

    $min4 = ip2long($v4[0][0]);
    $max4 = 0;
    foreach ($v4 as [, $end]) {
        $max4 = max($max4, ip2long($end));
    }
    $buckets4 = intdiv($max4 - $min4, $step4) + 1;
    $index4 = '';
    $table4 = '';
    for ($b = 0; $b < $buckets4; $b++) {
        $from = $min4 + $b * $step4;
        $to = $from + $step4 - 1;
        $entries = '';
        foreach ($v4 as $i => [$start, $end]) {
            if (ip2long($start) <= $to && ip2long($end) >= $from) {
                $entries .= pack('VVV', ip2long($start), ip2long($end), $pointer[$i]);
            }
        }
        $table4 .= pack('V', strlen($index4));
        $index4 .= pack('V', strlen($entries)) . $entries;
    }

    $min6 = $v6[0][0];
    $max6 = 0;
    foreach ($v6 as [, $end]) {
        $max6 = max($max6, $end);
    }
    $buckets6 = intdiv($max6 - $min6, $step6) + 1;
    $index6 = '';
    $table6 = '';
    for ($b = 0; $b < $buckets6; $b++) {
        $from = $min6 + $b * $step6;
        $to = $from + $step6 - 1;
        $entries = '';
        foreach ($v6 as $i => [$start, $end]) {
            if ($start <= $to && $end >= $from) {
                $entries .= $v6addr($start) . $v6addr($end) . pack('V', $pointer[count($v4) + $i]);
            }
        }
        $table6 .= pack('V', strlen($index6));
        $index6 .= pack('V', strlen($entries)) . $entries;
    }

    $headerSize = 112;
    $regions = $headerSize + strlen($records);
    $cities = $regions + strlen($sections['region']);
    $isps = $cities + strlen($sections['city']);
    $index4At = $isps + strlen($sections['isp']);
    $index6At = $index4At + strlen($index4);
    $header = 'GDBC' . pack('VV', 1, 1)
        . pack('VVV', count($v4), count($v6), $headerSize)
        . pack('VVVV', $regions, $cities, $isps, $index4At)
        . pack('VVVV', $min4, $max4, $step4, $index6At)
        . $v6addr($min6) . $v6addr($max6) . str_repeat("\0", 12) . pack('N', $step6)
        . str_repeat("\0", 8);
    return $header . $records . $sections['region'] . $sections['city'] . $sections['isp']
        . $index4 . $index6 . $table4 . $table6;
}

$tmp = sys_get_temp_dir() . '/orbitra_keitaro_' . bin2hex(random_bytes(4));
mkdir($tmp . '/geo', 0777, true);

$geoBytes = buildKeitaroGeo(
    [
        ['1.0.0.0', '1.0.0.255', 'AU', 'Queensland', 'Brisbane', 'Test Resolver'],
        ['1.0.1.0', '1.0.3.255', 'CN', 'Fujian', 'Fuzhou', 'ChinaNet'],
        ['5.5.0.0', '5.9.255.255', 'DE', 'Bayern', 'München', 'Wide Range GmbH'], // spans buckets
        ['8.8.8.0', '8.8.8.255', 'US', 'California', 'Mountain View', 'Google LLC'],
    ],
    [
        [0x0000, 0x00ff, 'NL', 'Noord-Holland', 'Amsterdam', 'V6 Net'],
        [0x2000, 0x5fff, 'FR', 'Île-de-France', 'Paris', 'V6 Wide'], // spans buckets
        [0xff00, 0xffff, 'JP', 'Tokyo', 'Tokyo', 'V6 Last'],
    ]
);
$carrierBytes = buildKeitaroList([
    ['31.173.0.0', '31.173.7.255', 'megafon_ru'],
    ['31.173.64.0', '31.173.127.255', 'vodafone_de'], // spans buckets
    ['31.174.0.5', '31.174.0.5', 't_mobile_us'],
]);
$botBytes = buildKeitaroList([
    ['20.0.0.1', '20.0.0.1', '1,myipms'],
    ['20.0.9.0', '20.0.9.255', '3,myipms'],
    ['20.1.0.0', '20.1.0.9', '1,radb_whois'],
]);
file_put_contents($tmp . '/output', $geoBytes);
file_put_contents($tmp . '/carriers.dat', $carrierBytes);
file_put_contents($tmp . '/bots.dat', $botBytes);

// --- 1. the readers ---------------------------------------------------------------
$geo = static fn(string $ip) => KeitaroDb::geoLookup($tmp . '/output', $ip);
check(KeitaroDb::detect($tmp . '/output') === 'geo' && KeitaroDb::detect($tmp . '/carriers.dat') === 'list', 'containers recognised by content');
check(KeitaroDb::listKind($tmp . '/carriers.dat') === 'carriers' && KeitaroDb::listKind($tmp . '/bots.dat') === 'bots', 'operator list and bot list told apart');
check($geo('8.8.8.8') === ['country_code' => 'US', 'region' => 'California', 'city' => 'Mountain View', 'isp' => 'Google LLC'], 'IPv4 geo lookup');
check(($geo('1.0.0.0')['country_code'] ?? '') === 'AU' && ($geo('1.0.3.255')['city'] ?? '') === 'Fuzhou', 'range edges, first and last address');
check(($geo('5.5.0.0')['city'] ?? '') === 'München' && ($geo('5.7.200.1')['isp'] ?? '') === 'Wide Range GmbH' && ($geo('5.9.255.255')['country_code'] ?? '') === 'DE', 'a range spanning several buckets, UTF-8 names');
check($geo('1.0.4.0') === null && $geo('5.4.255.255') === null && $geo('7.7.7.7') === null, 'gaps between ranges are not found');
check($geo('0.9.9.9') === null && $geo('9.9.9.9') === null && $geo('not-an-ip') === null, 'out of range and garbage are not found');
check(($geo('::ffff:8.8.8.8')['country_code'] ?? '') === 'US', 'IPv4-mapped IPv6 is an IPv4 visitor');
check(($geo('2001:db8::1')['city'] ?? '') === 'Amsterdam' && ($geo('2001:db8::ffff')['country_code'] ?? '') === 'JP', 'IPv6 geo lookup');
check(($geo('2001:db8::2000')['city'] ?? '') === 'Paris' && ($geo('2001:db8::4abc')['city'] ?? '') === 'Paris' && ($geo('2001:db8::5fff')['region'] ?? '') === 'Île-de-France', 'IPv6 range spanning several buckets');
check($geo('2001:db8::1000') === null && $geo('2001:db9::1') === null && $geo('2001:db7::1') === null, 'IPv6 gaps and out of range are not found');

$carrier = static fn(string $ip) => KeitaroDb::listLookup($tmp . '/carriers.dat', $ip);
check($carrier('31.173.0.0') === 'megafon_ru' && $carrier('31.173.7.255') === 'megafon_ru' && $carrier('31.173.8.0') === null, 'operator list lookup with edges');
check($carrier('31.173.64.0') === 'vodafone_de' && $carrier('31.173.100.7') === 'vodafone_de' && $carrier('31.173.127.255') === 'vodafone_de' && $carrier('31.173.128.0') === null, 'operator range spanning several buckets');
check($carrier('31.174.0.5') === 't_mobile_us' && $carrier('31.174.0.4') === null && $carrier('31.172.255.255') === null && $carrier('31.175.0.0') === null, 'single-address range, below and above the list');
check($carrier('2001:db8::1') === null, 'lists carry IPv4 only');
check(KeitaroDb::listLookup($tmp . '/bots.dat', '20.0.9.77') === '3,myipms' && KeitaroDb::listLookup($tmp . '/bots.dat', '20.0.0.2') === null, 'bot list lookup');

// --- 2. damaged and foreign files ----------------------------------------------------
file_put_contents($tmp . '/cut-geo', substr($geoBytes, 0, (int) (strlen($geoBytes) * 0.6)));
file_put_contents($tmp . '/cut-list', substr($carrierBytes, 0, strlen($carrierBytes) - 30));
file_put_contents($tmp . '/foreign.dat', str_repeat('SxG-not-a-keitaro-file', 100));
$warnings = 0;
set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
    echo "  warning: $message\n";
    $warnings++;
    return true;
});
$cutGeo = KeitaroDb::geoLookup($tmp . '/cut-geo', '8.8.8.8');
$cutList = KeitaroDb::listLookup($tmp . '/cut-list', '31.173.0.1');
$foreign = KeitaroDb::geoLookup($tmp . '/foreign.dat', '8.8.8.8') ?? KeitaroDb::listLookup($tmp . '/foreign.dat', '31.173.0.1');
$missing = KeitaroDb::geoLookup($tmp . '/nope', '8.8.8.8');
restore_error_handler();
check($cutGeo === null && $cutList === null && $foreign === null && $missing === null && $warnings === 0, 'truncated, foreign and missing files: not found, no warnings ' . json_encode([$cutGeo, $cutList, $foreign, $missing, $warnings]));
check(KeitaroDb::detect($tmp . '/foreign.dat') === null, 'a foreign .dat is not taken for a Keitaro file');

// --- 3. upload classification and installation ---------------------------------------
check(orbitraGeoClassifyFile($tmp . '/output', 'output')['kind'] === 'keitaro_geo', 'extensionless geo file classified by content');
check(orbitraGeoClassifyFile($tmp . '/carriers.dat', 'carriers.dat')['kind'] === 'keitaro_carriers', 'carriers.dat is an operator list, not Sypex');
check(orbitraGeoClassifyFile($tmp . '/bots.dat', 'bots.dat')['kind'] === 'keitaro_bots', 'bots.dat is a bot list');
check(orbitraGeoClassifyFile($tmp . '/foreign.dat', 'SxGeoCity.dat')['kind'] === 'sypex_city', 'an ordinary .dat is still Sypex');
$rejected = false;
try {
    orbitraGeoValidateFile($tmp . '/cut-geo', orbitraGeoClassifyFile($tmp . '/cut-geo', 'output'));
} catch (Throwable $e) {
    $rejected = true;
}
check($rejected, 'a truncated geo file is refused at upload');

$root = $tmp . '/install';
mkdir($root, 0777, true);
check(orbitraLookupNetwork('31.173.0.1', $root) === ['carrier' => '', 'connection_type' => ''] && !orbitraNetworkSources($root)['carrier'], 'no source installed: nothing is claimed');
$root = $tmp . '/install2'; // orbitraNetworkSources() is memoised per root
mkdir($root, 0777, true);
foreach (['output' => 'keitaro-geo.dat', 'carriers.dat' => 'keitaro-carriers.dat', 'bots.dat' => 'keitaro-bots.dat'] as $src => $slot) {
    $installed = orbitraGeoInstallFile($tmp . '/' . $src, $src, $root);
    check(basename($installed['path']) === $slot && is_file($root . '/geo/' . $slot), $src . ' installed into its own slot ' . $slot);
}
check((orbitraLookupKeitaroGeo('8.8.8.8', $root)['city'] ?? '') === 'Mountain View' && orbitraLookupKeitaroGeo('7.7.7.7', $root) === [], 'installed geo file answers lookups');
check(orbitraLookupKeitaroBot('20.1.0.3', $root) === '1,radb_whois' && orbitraLookupKeitaroBot('20.1.0.10', $root) === null, 'installed bot list answers lookups');

// --- 4. operator and connection type ---------------------------------------------------
check(orbitraNetworkSources($root)['carrier'] && orbitraNetworkSources($root)['connection'], 'an installed operator list is a carrier and connection source');
check(orbitraLookupNetwork('31.173.3.3', $root) === ['carrier' => 'megafon_ru', 'connection_type' => 'mobile'], 'address in an operator range: operator + mobile');
check(orbitraLookupNetwork('8.8.8.8', $root) === ['carrier' => '', 'connection_type' => 'wifi'], 'IPv4 outside every operator range: wifi');
check(orbitraLookupNetwork('2001:db8::1', $root) === ['carrier' => '', 'connection_type' => ''], 'IPv6 with an IPv4-only list: undetermined');

// --- 5. the stream filter matchers ------------------------------------------------------
check(orbitraConnectionFilterMatches(['mobile'], 'mobile') && orbitraConnectionFilterMatches(['Cellular'], 'mobile') && orbitraConnectionFilterMatches([' LTE '], 'mobile'), 'Connection: mobile and its synonyms');
check(orbitraConnectionFilterMatches(['wifi'], 'wifi') && orbitraConnectionFilterMatches(['cable'], 'wifi') && orbitraConnectionFilterMatches(['Wi-Fi', 'dsl'], 'wifi'), 'Connection: wifi and its synonyms');
check(!orbitraConnectionFilterMatches(['wifi'], 'mobile') && !orbitraConnectionFilterMatches(['mobile'], '') && !orbitraConnectionFilterMatches([''], 'mobile'), 'Connection: no cross-match, nothing matches an unknown type');
check(orbitraConnectionFilterMatches(['dial-up'], 'dialup') && orbitraConnectionFilterMatches(['corp'], 'corporate'), 'Connection: dialup and corporate');
check(orbitraOperatorFilterMatches(['megafon'], 'megafon_ru') && orbitraOperatorFilterMatches(['MegaFon'], 'megafon_ru') && orbitraOperatorFilterMatches(['megafon_ru'], 'MegaFon RU'), 'Operator: case and punctuation folded');
check(orbitraOperatorFilterMatches(['T-Mobile'], 't_mobile_us') && orbitraOperatorFilterMatches(['beeline', 'vodafone'], 'Vodafone GmbH'), 'Operator: hyphenated names, any of several');
check(!orbitraOperatorFilterMatches(['megafon'], '') && !orbitraOperatorFilterMatches(['megafon'], 'beeline_ru') && !orbitraOperatorFilterMatches(['', '  '], 'megafon_ru'), 'Operator: no operator, another operator, empty payload');

// --- 6. end to end: an Operator filter routes a visitor ----------------------------------
$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter(); // section 7 talks to api.php; router.php knows that path
$harness->start();
try {
    $seed = $harness->seedTestData();
    $pdo = $harness->getPdo();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $work = $harness->getWorkingDir();
    $campaignId = $seed['campaign_id'];
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'Megafon offer', 'https://megafon-offer.example/land', 0, 'active', 0)")->execute([700001]);
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'Everyone else', 'https://fallback-offer.example/land', 0, 'active', 0)")->execute([700002]);
    $addStream = $pdo->prepare("
        INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, filters_json, is_active, collect_clicks)
        VALUES (?, ?, ?, ?, 'regular', ?, 'redirect', ?, ?, 1, 1)
    ");
    $addStream->execute([800001, $campaignId, 700001, 'Megafon only', 1,
        json_encode(['offers' => [['id' => 700001, 'weight' => 100]]]),
        json_encode([['name' => 'Operator', 'mode' => 'include', 'payload' => ['MegaFon']]])]);
    $addStream->execute([800002, $campaignId, 700002, 'Fallback', 2,
        json_encode(['offers' => [['id' => 700002, 'weight' => 100]]]), '[]']);
    $harness->setSetting('ignore_prefetch', '0');

    $visit = static function (string $ip) use ($harness, $campaignId, $pdo): array {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchAll(PDO::FETCH_COLUMN)[0];
        // Own request: the harness helper follows the redirect to the offer.
        $ctx = stream_context_create(['http' => [
            'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36 ' . bin2hex(random_bytes(3)),
                'X-Forwarded-For: ' . $ip,
                'Accept-Language: ru-RU,ru;q=0.9',
            ]),
        ]]);
        @file_get_contents($harness->getBaseUrl() . "/testcamp", false, $ctx);
        $location = '';
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $code = (int) $m[1];
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        $resp = ['code' => $code];
        $rows = $pdo->query('SELECT * FROM clicks ORDER BY rowid DESC LIMIT 1')->fetchAll();
        $after = (int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchAll(PDO::FETCH_COLUMN)[0];
        return ['location' => $location, 'click' => $after > $before ? ($rows[0] ?? []) : [], 'code' => $resp['code'] ?? 0];
    };

    // No operator source yet: the filter abstains, the first stream serves everyone.
    $r = $visit('31.173.3.3');
    check(strpos($r['location'], 'megafon-offer.example') !== false, 'no operator database: the Operator filter abstains (got ' . $r['code'] . ' ' . $r['location'] . ')');
    check(!isset($r['click']['carrier']) || $r['click']['carrier'] === null, 'and the click stores no operator');

    mkdir($work . '/geo', 0777, true);
    copy($tmp . '/carriers.dat', $work . '/geo/keitaro-carriers.dat');
    $r = $visit('31.173.3.3');
    check(strpos($r['location'], 'megafon-offer.example') !== false, 'MegaFon visitor goes to the operator stream (' . $r['location'] . ')');
    check(($r['click']['carrier'] ?? '') === 'megafon_ru' && ($r['click']['connection_type'] ?? '') === 'mobile' && (int) ($r['click']['stream_id'] ?? 0) === 800001, 'click stores operator, connection type and the stream');
    $r = $visit('31.173.100.7');
    check(strpos($r['location'], 'fallback-offer.example') !== false && ($r['click']['carrier'] ?? '') === 'vodafone_de', 'another operator falls through to the next stream');
    $r = $visit('93.184.216.34');
    check(strpos($r['location'], 'fallback-offer.example') !== false && ($r['click']['connection_type'] ?? '') === 'wifi' && empty($r['click']['carrier']), 'a fixed-line visitor falls through, stored as wifi');

    $pdo->prepare('UPDATE streams SET filters_json = ? WHERE id = 800001')
        ->execute([json_encode([['name' => 'Connection', 'mode' => 'include', 'payload' => ['cellular']]])]);
    $r = $visit('31.174.0.5');
    check(strpos($r['location'], 'megafon-offer.example') !== false, 'Connection filter: a mobile visitor matches "cellular"');
    $r = $visit('93.184.216.35');
    check(strpos($r['location'], 'fallback-offer.example') !== false, 'Connection filter: a fixed-line visitor does not');
    $pdo->prepare('UPDATE streams SET filters_json = ? WHERE id = 800001')
        ->execute([json_encode([['name' => 'Operator', 'mode' => 'exclude', 'payload' => ['megafon']]])]);
    $r = $visit('31.173.3.4');
    check(strpos($r['location'], 'fallback-offer.example') !== false, 'Operator exclude: the excluded operator is sent on');
    $r = $visit('93.184.216.36');
    check(strpos($r['location'], 'megafon-offer.example') !== false, 'Operator exclude: a visitor with no operator passes');

    // Report dimension over the stored column.
    $dim = $pdo->query("SELECT carrier, COUNT(*) AS n FROM clicks WHERE carrier IS NOT NULL GROUP BY carrier ORDER BY carrier")->fetchAll();
    check(array_column($dim, 'carrier') === ['megafon_ru', 't_mobile_us', 'vodafone_de'], 'clicks group by the stored operator');

    // --- 7. install from a URL (the panel's "Install from link" button) ------
    // A tiny static server stands in for wherever the operator hosts the file.
    $apiKey = 'test_key_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
        ->execute(['geo_url_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $apiKey, 'keitaro-url-test', 'full']);
    $apiJson = static function (string $path, array $body = null) use ($harness, $apiKey): array {
        $headers = ['Authorization: Bearer ' . $apiKey];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $resp = $body === null
            ? $harness->getWithHeaders($path, $headers)
            : $harness->postWithHeaders($path, json_encode($body), $headers);
        $json = json_decode((string) $resp['body'], true);
        return is_array($json) ? $json : ['status' => 'unparsable', 'raw' => $resp['body']];
    };

    $serveDir = $tmp . '/serve';
    mkdir($serveDir, 0777, true);
    file_put_contents($serveDir . '/my-operators.dat', $carrierBytes);
    file_put_contents($serveDir . '/broken.dat', 'definitely-not-a-keitaro-container');
    $servePort = 18000 + random_int(1000, 20000);
    $servePipes = [];
    $urlServer = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $servePort, '-t', $serveDir],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $servePipes
    );
    $serveUp = false;
    if (is_resource($urlServer)) {
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents("http://127.0.0.1:$servePort/my-operators.dat") !== false) {
                $serveUp = true;
                break;
            }
            usleep(100000);
        }
    }
    check($serveUp, 'local file server for the URL install is up');
    $workGeoCarriers = $work . '/geo/keitaro-carriers.dat';
    try {
        // Section 6 left a copy here; the checks below must watch THIS install.
        @unlink($workGeoCarriers);
        KeitaroDb::reset();
        $res = $apiJson('/api.php?action=geo_db_update', [
            'id' => 'keitaro_carriers',
            'url' => "http://127.0.0.1:$servePort/my-operators.dat",
        ]);
        check(($res['status'] ?? '') === 'success', 'install from URL succeeds (' . substr((string) ($res['message'] ?? $res['raw'] ?? ''), 0, 120) . ')');
        check(is_file($workGeoCarriers) && filesize($workGeoCarriers) === strlen($carrierBytes), 'the file landed in its slot');
        $savedUrl = $pdo->query("SELECT value FROM settings WHERE key = 'keitaro_carriers_source_url'")->fetchAll(PDO::FETCH_COLUMN);
        check(($savedUrl[0] ?? '') === "http://127.0.0.1:$servePort/my-operators.dat", 'the URL is remembered per database');

        // No url in the request: the saved one is used.
        @unlink($workGeoCarriers);
        KeitaroDb::reset();
        $res = $apiJson('/api.php?action=geo_db_update', ['id' => 'keitaro_carriers']);
        check(($res['status'] ?? '') === 'success' && is_file($workGeoCarriers), 'a repeat request without a URL re-installs from the saved one');

        // The real files are packed: a .gz of a list, a .zip of the geo base
        // (whose inner file is extensionless and travels with a README).
        file_put_contents($serveDir . '/operators.gz', gzencode($carrierBytes));
        $zipPath = $serveDir . '/geo.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('output', $geoBytes);
        $zip->addFromString('README.txt', 'not a database');
        $zip->close();
        $workGeoGeo = $work . '/geo/keitaro-geo.dat';
        $res = $apiJson('/api.php?action=geo_db_update', [
            'id' => 'keitaro_auto',
            'url' => "http://127.0.0.1:$servePort/geo.zip",
        ]);
        clearstatcache(true, $workGeoGeo);
        check(($res['status'] ?? '') === 'success' && is_file($workGeoGeo),
            'a .zip link installs the geo base inside, no id given (' . substr((string) ($res['message'] ?? $res['raw'] ?? ''), 0, 120) . ')');
        $geoUrl = $pdo->query("SELECT value FROM settings WHERE key = 'keitaro_geo_source_url'")->fetchAll(PDO::FETCH_COLUMN);
        check(($geoUrl[0] ?? '') === "http://127.0.0.1:$servePort/geo.zip", 'the auto install remembers the URL for the database it turned out to be');

        @unlink($workGeoCarriers);
        KeitaroDb::reset();
        $res = $apiJson('/api.php?action=geo_db_update', [
            'id' => 'keitaro_carriers',
            'url' => "http://127.0.0.1:$servePort/operators.gz",
        ]);
        clearstatcache(true, $workGeoCarriers);
        check(($res['status'] ?? '') === 'success' && is_file($workGeoCarriers) && filesize($workGeoCarriers) === strlen($carrierBytes),
            'a .gz link installs the list inside (' . substr((string) ($res['message'] ?? $res['raw'] ?? ''), 0, 120) . ')');

        $res = $apiJson('/api.php?action=geo_db_update', [
            'id' => 'keitaro_bots',
            'url' => "http://127.0.0.1:$servePort/broken.dat",
        ]);
        check(($res['status'] ?? '') === 'error', 'a URL that is not a Keitaro file is refused');
        check(!is_file($work . '/geo/keitaro-bots.dat'), 'and installs nothing');

        $res = $apiJson('/api.php?action=geo_dbs');
        $row = null;
        foreach (($res['data'] ?? []) as $dbRow) {
            if (($dbRow['id'] ?? '') === 'keitaro_carriers') {
                $row = $dbRow;
            }
        }
        check($row !== null && ($row['source_url'] ?? '') !== '' && strpos($row['source_hint'] ?? '', 'https://') === 0, 'the database list carries the saved URL and the hint');

        $res = $apiJson('/api.php?action=geo_db_update', ['id' => 'keitaro_carriers', 'remove' => true]);
        clearstatcache(true, $workGeoCarriers); // the API process unlinked it; this process cached the stat
        check(($res['status'] ?? '') === 'success' && !is_file($workGeoCarriers),
            'remove deletes the installed file (' . substr((string) ($res['message'] ?? $res['raw'] ?? ''), 0, 120) . ')');
        $left = $pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'keitaro_carriers_source_url'")->fetchAll(PDO::FETCH_COLUMN);
        check((int) ($left[0] ?? 1) === 0, 'and forgets the saved URL');
    } finally {
        if (is_resource($urlServer)) {
            proc_terminate($urlServer);
            proc_close($urlServer);
        }
    }
} finally {
    $harness->stop();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
