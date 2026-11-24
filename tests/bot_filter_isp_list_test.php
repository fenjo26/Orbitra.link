<?php
// tests/bot_filter_isp_list_test.php
//
// The "Bot: yes" stream filter reads the global Bot ISP list
// (Settings → Bots, settings.bot_isp_list).
//
// Field report (2026-11-24): an operator pasted providers from the click log
// into that list and the intercepting "bot" stream still let them through.
// The list was consulted by the cloak card alone ("Block bot & datacenter
// ISPs"); detectBotFilter() — what the stream filter runs — never saw it. The
// list sits in the panel next to the bot IP and signature lists, which do feed
// the filter, so "a listed provider is a bot at every strictness" is what it
// promises. A hit is logged as bot_isp:<provider>, the same reason code the
// cloak card uses.
//
// Run: php tests/bot_filter_isp_list_test.php

require_once __DIR__ . '/../core/stream_match.php';

$fails = 0;
$check = function (string $label, $got, $expected) use (&$fails): void {
    if ($got !== $expected) {
        $fails++;
        echo "FAIL {$label}: got " . var_export($got, true) . ', expected ' . var_export($expected, true) . "\n";
        return;
    }
    echo "ok  {$label}\n";
};
$reasonCodes = static function (array $reasons): array {
    return array_map([CloakDetector::class, 'reasonCode'], $reasons);
};

$desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$visitor = static function (array $extra = []) use ($desktop): array {
    return array_merge([
        // AS36352 is not on the ASN blocklist and the ISP name has no hosting
        // keyword: without the list this visitor carries no signal at all.
        'ip' => '152.232.53.89',
        'user_agent' => $desktop,
        'asn' => 'AS36352',
        'isp' => 'B2 Net Solutions Inc.',
        'accept_language' => 'en-US,en;q=0.9',
    ], $extra);
};
$pdo = new PDO('sqlite::memory:');

// --- the verdict ----------------------------------------------------------------
$noList = CloakDetector::detectBotFilter($visitor(), ['bot_isp_list' => '']);
$check('no list: no signal, not a bot even at "high"', [$noList['is_suspicious'], $noList['reasons']], [false, []]);

foreach (['low', 'medium', 'high'] as $sensitivity) {
    $v = CloakDetector::detectBotFilter($visitor(), ['bot_isp_list' => "Hetzner\nB2 Net Solutions", 'sensitivity' => $sensitivity]);
    $check("listed provider at strictness {$sensitivity}: a bot", [$v['is_suspicious'], $reasonCodes($v['reasons'])], [true, ['bot_isp']]);
    $check("…the reason names the list entry ({$sensitivity})", $v['reasons'], ['bot_isp:B2 Net Solutions']);
}

$v = CloakDetector::detectBotFilter($visitor(), ['bot_isp_list' => 'hetzner, ovh', 'sensitivity' => 'low']);
$check('a provider that is not on the list stays clean', [$v['is_suspicious'], $v['reasons']], [false, []]);

// Whole phrases, as on the cloak card: a cloud vendor's name inside a
// residential ISP's name is not a hit.
// One provider per line: a comma inside a name ("..., Inc.") does not split
// it. The geo databases write the same provider without the comma ("B2 Net
// Solutions Inc.") — a comma is formatting, not part of the name. The evidence
// drops it too: a comma separates reasons in the click log.
$v = CloakDetector::detectBotFilter($visitor(), ['bot_isp_list' => "B2 Net Solutions, Inc.\nHetzner"]);
$check('a Keitaro-style entry with a comma matches the geo database\'s name', $v['reasons'], ['bot_isp:B2 Net Solutions Inc.']);
// Next to the heuristics: the same visitor is flagged twice, each layer names its own reason.
$v = CloakDetector::detectBotFilter($visitor(['isp' => 'AWS EC2']), ['bot_isp_list' => 'aws']);
$check('a one-word entry matches as a whole word, beside the hosting keyword', $reasonCodes($v['reasons']), ['hosting_isp', 'bot_isp']);
$v = CloakDetector::detectBotFilter($visitor(['isp' => '', 'asn' => '']), ['bot_isp_list' => 'B2 Net Solutions']);
$check('no network data: no hit', $v['is_suspicious'], false);
$v = CloakDetector::detectBotFilter($visitor(['asn' => 'AS64500']), ['bot_isp_list' => 'AS64500']);
$check('an entry can name the AS number', $v['reasons'], ['bot_isp:AS64500']);
$v = CloakDetector::detectBotFilter($visitor(), ['bot_isp_list' => "B2 Net Solutions\nLtd\nInc"]);
$check('bare corporate suffixes on the list are ignored', $v['reasons'], ['bot_isp:B2 Net Solutions']);

// The hosting-keyword heuristic used to look for "aws" as a substring, so
// "Lawson Networks" (l-AWS-on) read as a hosting provider.
$v = CloakDetector::detectBotFilter($visitor(['isp' => 'Lawson Networks']), ['bot_isp_list' => '']);
$check('"aws" inside "Lawson Networks" is not a hosting keyword', [$v['is_suspicious'], $v['reasons']], [false, []]);
$v = CloakDetector::detectBotFilter($visitor(['isp' => 'Some AWS customer', 'asn' => 'AS64500']), ['bot_isp_list' => '']);
$check('"AWS" as a word still is one', $reasonCodes($v['reasons']), ['hosting_isp']);

// A visitor that is a bot anyway keeps its own reasons and gains the list's.
$v = CloakDetector::detectBotFilter($visitor(['user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']),
    ['bot_isp_list' => 'B2 Net Solutions', 'sensitivity' => 'low']);
$check('other signals are kept, the list is added', $reasonCodes($v['reasons']), ['crawler_or_tool_ua', 'bot_isp']);

// The list is read from the settings row when the caller does not hand it over.
$settingsPdo = new PDO('sqlite::memory:');
$settingsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$settingsPdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$settingsPdo->exec("INSERT INTO settings (key, value) VALUES ('bot_isp_list', 'B2 Net Solutions')");
$v = CloakDetector::detectBotFilter($visitor(['pdo' => $settingsPdo]), ['sensitivity' => 'low']);
$check('without an explicit list the settings row is read', [$v['is_suspicious'], $v['reasons']], [true, ['bot_isp:B2 Net Solutions']]);

// --- the stream filter ------------------------------------------------------------
$routerVisitor = static function (array $extra = []) use ($desktop): array {
    return array_merge([
        'ip' => '152.232.53.89', 'country' => 'DE', 'device' => 'Desktop', 'os' => 'Windows', 'browser' => 'Chrome',
        'languageCodes' => ['en'], 'userAgent' => $desktop, 'referer' => '', 'params' => [], 'rawParams' => [],
        'timezone' => 'UTC', 'isp' => 'B2 Net Solutions Inc.', 'asn' => 'AS36352', 'botIspList' => 'B2 Net Solutions',
        'acceptLanguage' => 'en-US,en;q=0.9',
    ], $extra);
};
$stream = static fn (string $mode, string $sensitivity): array => [
    'id' => 77, 'filters_logic' => 'and',
    'filters_json' => json_encode([['name' => 'Bot', 'mode' => $mode, 'payload' => ['yes'], 'sensitivity' => $sensitivity]]),
];

$GLOBALS['orbitraBotFilterHits'] = [];
$check('stream "Bot = yes" catches a listed provider at "low"', streamMatchesFilters($stream('include', 'low'), $routerVisitor(), $pdo), true);
$check('stream "Bot = yes" lets an unlisted provider through', streamMatchesFilters($stream('include', 'low'), $routerVisitor(['isp' => 'Comcast Cable', 'asn' => 'AS7922']), $pdo), false);
$check('stream "Bot != yes" is the mirror image', streamMatchesFilters($stream('exclude', 'low'), $routerVisitor(), $pdo), false);
$hit = $GLOBALS['orbitraBotFilterHits'][77] ?? null;
$check('the click log learns why: the list entry and the strictness',
    [$hit['reasons'] ?? null, $hit['sensitivity'] ?? null], [['bot_isp:B2 Net Solutions'], 'low']);

$t = [];
streamMatchesFilters($stream('include', 'medium'), $routerVisitor(), $pdo, $t);
$check('the ?_dbg=1 trace carries the signal', [$t[0]['result'], strpos($t[0]['note'], 'bot_isp:B2 Net Solutions') !== false], ['match', true]);

// The cloak card and the stream filter share one matcher for the list.
$card = CloakDetector::targetingReasons(['block_bot_isps' => true], 'DE', 'Desktop', 'B2 Net Solutions Inc. AS36352', 'B2 Net Solutions', true);
$check('the cloak card names the same reason for the same visitor', $card, ['bot_isp:B2 Net Solutions']);
$card = CloakDetector::targetingReasons(['block_bot_isps' => true, 'custom_bot_isps' => 'Hetzner'], 'DE', 'Desktop', 'B2 Net Solutions Inc. AS36352', 'B2 Net Solutions', true);
$check('…and the stream\'s own list still wins over the global one there', $card, []);

if ($fails) {
    echo "\n{$fails} failure(s)\n";
    exit(1);
}
echo "\nall passed\n";
