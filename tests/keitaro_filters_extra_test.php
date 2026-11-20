<?php
/**
 * tests/keitaro_filters_extra_test.php
 *
 * The Keitaro filters that used to arrive dropped or broken (client report
 * 2026-11-20: "33 filter(s) not migrated: source ×27, user_agent ×3,
 * search_engine ×2, isp ×1", plus a Language filter showing "empty"):
 *
 * - "@empty" in the Language filter means "browser sent no language" and
 *   survives the import (it used to be stripped to a fake language "empty",
 *   which matched nobody); a bare "empty" saved by older imports still works;
 * - source / isp / user_agent / referrer / empty_referrer / search_engine /
 *   keyword / os / browser / ip / connection_type / operator map to Orbitra
 *   filters instead of being dropped;
 * - Referer and UserAgent: contains, "*" mask, "/regex/", "@empty";
 * - IP filter: CIDR and a-b ranges.
 *
 *     php tests/keitaro_filters_extra_test.php
 */

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/core/StreamFilters.php';
require_once $repoRoot . '/core/keitaro_import.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ip helpers live in index.php; extract them rather than copy them.
$src = file_get_contents($repoRoot . '/index.php');
foreach (['orbitraIpInCidrOrRange', 'ipMatchesToken'] as $fn) {
    if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?\n\}/ms', $src, $m)) {
        fwrite(STDERR, "could not extract {$fn} from index.php\n");
        exit(1);
    }
    eval($m[0]);
}

echo "\n== Language @empty (engine) ==\n";
check('@empty matches a missing header', orbitraLanguageFilterMatches(['@empty', 'zh'], ''));
check('@empty matches a "*"-only header', orbitraLanguageFilterMatches(['@empty'], '*'));
check('bare "empty" (pre-1.9.8 import) matches a missing header', orbitraLanguageFilterMatches(['empty', 'zh'], ''));
check('zh still matches with @empty listed', orbitraLanguageFilterMatches(['@empty', 'zh'], 'zh-CN,zh;q=0.9'));
check('@empty does not match a real language', !orbitraLanguageFilterMatches(['@empty'], 'en-US'));
check('without @empty a missing header still does not match', !orbitraLanguageFilterMatches(['zh'], ''));

echo "\n== Referer / UserAgent patterns ==\n";
check('referer contains', orbitraRefererFilterMatches(['google.'], 'https://www.google.com/search?q=x'));
check('referer case-insensitive', orbitraRefererFilterMatches(['Google.COM'], 'https://www.google.com/'));
check('referer mask', orbitraRefererFilterMatches(['*facebook.com*'], 'https://m.facebook.com/x'));
check('referer mask is whole-value', !orbitraRefererFilterMatches(['facebook.com*'], 'https://m.facebook.com/x'));
check('referer @empty on direct visit', orbitraRefererFilterMatches(['@empty'], ''));
check('referer @empty does not match a real referer', !orbitraRefererFilterMatches(['@empty'], 'https://a.com/'));
check('referer non-empty token never matches empty referer', !orbitraRefererFilterMatches(['google'], ''));
check('a path-like token is text, not a broken regex', orbitraRefererFilterMatches(['/promo/'], 'https://a.com/promo/x'));
$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
check('UA contains', orbitraUserAgentFilterMatches(['iphone'], $ua));
check('UA mask', orbitraUserAgentFilterMatches(['Mozilla/5.0 (iPhone*'], $ua));
check('UA regex', orbitraUserAgentFilterMatches(['/OS 1[6-9]_\d/'], $ua));
check('UA regex miss', !orbitraUserAgentFilterMatches(['/Android \d+/'], $ua));
check('UA @empty on missing header', orbitraUserAgentFilterMatches(['@empty'], ''));
check('UA exact full string still matches', orbitraUserAgentFilterMatches([$ua], $ua));

echo "\n== IP CIDR / range ==\n";
check('CIDR hit', ipMatchesToken('10.1.0.0/16', '10.1.200.3'));
check('CIDR miss', !ipMatchesToken('10.1.0.0/16', '10.2.0.1'));
check('CIDR /0 style edge (/25)', ipMatchesToken('192.168.1.128/25', '192.168.1.200') && !ipMatchesToken('192.168.1.128/25', '192.168.1.5'));
check('IPv6 CIDR', ipMatchesToken('2001:db8::/32', '2001:db8:1::5'));
check('range hit', ipMatchesToken('1.2.3.4-1.2.3.99', '1.2.3.50'));
check('range miss', !ipMatchesToken('1.2.3.4-1.2.3.99', '1.2.3.100'));
check('mask still works', ipMatchesToken('10.0.0.*', '10.0.0.7'));
check('exact still works', ipMatchesToken('8.8.8.8', '8.8.8.8') && !ipMatchesToken('8.8.8.8', '8.8.4.4'));
check('IPv4 CIDR vs IPv6 visitor is a miss', !ipMatchesToken('10.0.0.0/8', '2001:db8::1'));

echo "\n== import mapping ==\n";
$dropped = [];
$approx = [];
$out = orbitraKeitaroBuildOrbitraFilters([
    ['name' => 'language', 'mode' => 'accept', 'payload' => '["@empty","zh"]'],
    ['name' => 'source', 'mode' => 'accept', 'payload' => '["site1.com","@empty"]'],
    ['name' => 'isp', 'mode' => 'reject', 'payload' => '["Google LLC"]'],
    ['name' => 'user_agent', 'mode' => 'reject', 'payload' => '["*HeadlessChrome*"]'],
    ['name' => 'search_engine', 'mode' => 'accept', 'payload' => '["google","Yandex"]'],
    ['name' => 'referrer', 'mode' => 'accept', 'payload' => '["*tiktok*"]'],
    ['name' => 'empty_referrer', 'mode' => 'accept', 'payload' => '1'],
    ['name' => 'keyword', 'mode' => 'accept', 'payload' => '["@empty"]'],
    ['name' => 'os', 'mode' => 'accept', 'payload' => '["Mac OS X","Android"]'],
    ['name' => 'ip', 'mode' => 'reject', 'payload' => "1.2.3.0/24;5.6.7.8\n9.9.9.9"],
    ['name' => 'connection_type', 'mode' => 'accept', 'payload' => '["cellular"]'],
    ['name' => 'uniqueness', 'mode' => 'accept', 'payload' => 'ip'],
], $dropped, $approx);
$by = [];
foreach ($out as $f) {
    $by[$f['name'] . ($f['param'] ?? '') . '#' . count($by)] = $f;
}
$find = function (string $name, ?string $param = null) use ($out) {
    foreach ($out as $f) {
        if ($f['name'] === $name && ($param === null || ($f['param'] ?? null) === $param)) {
            return $f;
        }
    }
    return null;
};
$lang = $find('Language');
check('language keeps @empty', $lang && $lang['payload'] === ['@empty', 'zh'], json_encode($lang));
$src = $find('Parameter', 'source');
check('source → Parameter source, @empty kept', $src && $src['payload'] === ['site1.com', '@empty'] && $src['mode'] === 'include', json_encode($src));
$isp = $find('ISP');
check('isp → ISP exclude', $isp && $isp['payload'] === ['Google LLC'] && $isp['mode'] === 'exclude', json_encode($isp));
$uaF = $find('UserAgent');
check('user_agent → UserAgent', $uaF && $uaF['payload'] === ['*HeadlessChrome*'] && $uaF['mode'] === 'exclude', json_encode($uaF));
$refs = array_values(array_filter($out, fn($f) => $f['name'] === 'Referer'));
check('search_engine → Referer google./yandex.', ($refs[0]['payload'] ?? null) === ['google.', 'yandex.'], json_encode($refs));
check('referrer → Referer verbatim', ($refs[1]['payload'] ?? null) === ['*tiktok*'], json_encode($refs));
check('empty_referrer → Referer @empty include', ($refs[2]['payload'] ?? null) === ['@empty'] && ($refs[2]['mode'] ?? '') === 'include', json_encode($refs));
$kw = $find('Parameter', 'keyword');
check('keyword with @empty → Parameter keyword', $kw && $kw['payload'] === ['@empty'], json_encode($kw));
$os = $find('OS');
check('os normalised (Mac OS X → macOS)', $os && $os['payload'] === ['macOS', 'Android'], json_encode($os));
$ip = $find('IP');
check('ip split on ; and new lines', $ip && $ip['payload'] === ['1.2.3.0/24', '5.6.7.8', '9.9.9.9'], json_encode($ip));
check('connection_type → Connection', ($find('Connection')['payload'] ?? null) === ['cellular']);
check('only uniqueness dropped', $dropped === ['uniqueness' => 1], json_encode($dropped));
check('search_engine reported as approximate', ($approx['search_engine → Referer'] ?? 0) === 1, json_encode($approx));

// The client's exact dump line: everything except none should now travel.
$dropped = [];
orbitraKeitaroBuildOrbitraFilters([
    ['name' => 'source', 'mode' => 'accept', 'payload' => '["a"]'],
    ['name' => 'user_agent', 'mode' => 'accept', 'payload' => '["b"]'],
    ['name' => 'search_engine', 'mode' => 'accept', 'payload' => '["google"]'],
    ['name' => 'isp', 'mode' => 'accept', 'payload' => '["c"]'],
], $dropped);
check('source/user_agent/search_engine/isp no longer dropped', $dropped === [], json_encode($dropped));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
