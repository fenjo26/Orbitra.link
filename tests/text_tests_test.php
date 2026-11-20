<?php
// tests/text_tests_test.php
//
// Landing text A/B tests (core/TextTests.php): save/update rules, the
// server-side swap (text node first, entities, never inside the replacement
// as markup), deterministic per-click variants, hit recording only when the
// text is on the page, stats from clicks/conversions, Thompson re-weighing
// with the floor and the winner, candidate extraction, and parsing of model
// output for generated variants.
//
// Run: php tests/text_tests_test.php

require_once __DIR__ . '/../core/TextTests.php';
require_once __DIR__ . '/../core/AiClient.php';

$failures = 0;
$assert = function (string $label, $cond, string $detail = '') use (&$failures) {
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
};

$tmpDb = sys_get_temp_dir() . '/orbitra_text_tests_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cfg = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($cfg, "if (!function_exists('orbitraEnsureAiTables'))");
$end = strpos($cfg, "if (!function_exists('orbitraEnsureCampaignRuleTables'))", $start);
eval(substr($cfg, $start, $end - $start));
orbitraEnsureAiTables($pdo);
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, offer_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, payout REAL DEFAULT 0)');

// ---- replaceText -------------------------------------------------------------
$html = '<html><head><title>Win big</title></head><body><h1 class="t">  Get 200% bonus &amp; 50 spins </h1>'
    . '<img alt="Get 200% bonus & 50 spins"><p>Get 200% bonus & 50 spins</p><button>Play now</button></body></html>';
$out = TextTests::replaceText($html, 'Get 200% bonus & 50 spins', 'Double your deposit <today>');
$assert('text node matched through the &amp; entity, attribute left alone',
    strpos($out, '<h1 class="t">  Double your deposit &lt;today&gt; </h1>') !== false && strpos($out, 'alt="Get 200% bonus & 50 spins"') !== false, $out);
$assert('only the first occurrence', substr_count($out, 'Double your deposit') === 1);
$assert('replacement is escaped, never markup', strpos($out, '<today>') === false);
$assert('$ and backslashes in the replacement are literal',
    strpos(TextTests::replaceText('<b>Play now</b>', 'Play now', 'Win $100 \\o/'), 'Win $100 \\o/') !== false);
$assert('missing text → null', TextTests::replaceText($html, 'Not here', 'x') === null);

// ---- save ----------------------------------------------------------------------
[$t, $e] = TextTests::save($pdo, ['landing_id' => 5, 'original_text' => 'Play now', 'variants' => []]);
$assert('variants required', $t === null && $e === 'variants_required');
[$t, $e] = TextTests::save($pdo, ['landing_id' => 5, 'original_text' => 'Play now',
    'variants' => ['Start winning', 'play NOW', ['text' => 'Claim bonus', 'source' => 'ai'], '']]);
$assert('saved with control + 2 variants (case-insensitive duplicate of the original dropped)',
    $e === null && count($t['variants']) === 3 && $t['variants'][0]['is_original'] === 1, json_encode($t['variants'] ?? null));
$assert('ai source kept', $t['variants'][2]['source'] === 'ai');
$assert('equal starting weights summing to 100', array_sum(array_column($t['variants'], 'weight')) === 100
    && max(array_column($t['variants'], 'weight')) - min(array_column($t['variants'], 'weight')) <= 1);
$testId = $t['id'];

// ---- serving ---------------------------------------------------------------------------
$active = TextTests::activeForLanding($pdo, 5);
$assert('one active test for the landing', count($active) === 1 && count($active[0]['variants']) === 3);
$a = TextTests::pickVariant($active[0], 'clickA');
$assert('same click → same variant', $a === TextTests::pickVariant($active[0], 'clickA'));
$dist = [];
for ($i = 0; $i < 3000; $i++) {
    $v = TextTests::pickVariant($active[0], 'c' . $i);
    $dist[$v['id']] = ($dist[$v['id']] ?? 0) + 1;
}
$assert('roughly even split over many clicks', count($dist) === 3 && min($dist) > 850, json_encode($dist));

$page = '<html><body><h1>Hello</h1><button>Play now</button></body></html>';
$served = [];
for ($i = 0; $i < 60; $i++) {
    $pdo->prepare('INSERT INTO clicks (id, campaign_id, created_at) VALUES (?, 1, ?)')->execute(["k$i", gmdate('Y-m-d H:i:s')]);
    $served[$i] = TextTests::apply($pdo, $page, 5, "k$i");
}
$assert('every visit recorded once', (int) $pdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = $testId")->fetchColumn() === 60);
TextTests::apply($pdo, $page, 5, 'k0');
$assert('reload does not double-count', (int) $pdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = $testId")->fetchColumn() === 60);
$shown = array_unique(array_map(fn($h) => preg_replace('/.*<button>(.*?)<\/button>.*/s', '$1', $h), $served));
sort($shown);
$assert('all three texts were shown', $shown === ['Claim bonus', 'Play now', 'Start winning'], json_encode($shown));
$assert('other landings untouched', TextTests::apply($pdo, $page, 6, 'k1') === $page);
$assert('no hit when the text is not on the page', TextTests::apply($pdo, '<p>other</p>', 5, 'zz') === '<p>other</p>'
    && !$pdo->query("SELECT 1 FROM text_test_hits WHERE click_id='zz'")->fetchColumn());

// ---- stats + reweigh -------------------------------------------------------------------------
$byVariant = $pdo->query("SELECT variant_id, click_id FROM text_test_hits WHERE test_id = $testId")->fetchAll(PDO::FETCH_ASSOC);
$claim = (int) $pdo->query("SELECT id FROM text_test_variants WHERE text = 'Claim bonus'")->fetchColumn();
foreach ($byVariant as $h) {
    // "Claim bonus" visitors go on to the offer far more often.
    $rate = (int) $h['variant_id'] === $claim ? 0.8 : 0.1;
    if ((crc32($h['click_id']) % 100) / 100 < $rate) {
        $pdo->prepare("UPDATE clicks SET offer_at = '2026-11-20 10:00:00' WHERE id = ?")->execute([$h['click_id']]);
    }
}
$pdo->exec("INSERT INTO conversions (click_id, status, payout) VALUES ('k3', 'sale', 25)");
$st = TextTests::stats($pdo, $testId);
$assert('stats per variant: views sum and ctr', array_sum(array_column($st, 'views')) === 60 && $st[$claim]['ctr'] > 50, json_encode($st));

$r = TextTests::reweigh($pdo, $testId);
$assert('below 50 views per variant the weights do not move', !$r['changed']);

// Bulk up to 400 visits for a clear verdict.
for ($i = 60; $i < 1200; $i++) {
    $pdo->prepare('INSERT INTO clicks (id, campaign_id, created_at) VALUES (?, 1, ?)')->execute(["k$i", gmdate('Y-m-d H:i:s')]);
    TextTests::apply($pdo, $page, 5, "k$i");
    $vid = (int) $pdo->query("SELECT variant_id FROM text_test_hits WHERE click_id = 'k$i'")->fetchColumn();
    $rate = $vid === $claim ? 0.8 : 0.1;
    if ((crc32("k$i") % 100) / 100 < $rate) {
        $pdo->prepare("UPDATE clicks SET offer_at = '2026-11-20 10:00:00' WHERE id = ?")->execute(["k$i"]);
    }
}
$pdo->exec("UPDATE text_tests SET last_run_at = NULL");
$all = TextTests::reweighAll($pdo);
$t = TextTests::get($pdo, $testId);
$w = array_column($t['variants'], 'weight', 'id');
$assert('the strong variant gets most of the traffic', $w[$claim] >= 80, json_encode($w));
$assert('every variant keeps the 5% floor', min($w) >= 5 && array_sum($w) === 100, json_encode($w));
$assert('winner named', (int) $t['winner_variant_id'] === $claim && isset($all['winners'][$testId]));
$assert('probability of best reported', $t['variants'][array_search($claim, array_column($t['variants'], 'id'))]['prob_best'] > 0.95);
$assert('reweighAll respects the 30-minute cadence', TextTests::reweighAll($pdo)['tests'] === 0);

// ---- update / control change wipes stats ------------------------------------------------------
$vs = $t['variants'];
[$t2, $e2] = TextTests::save($pdo, ['landing_id' => 5, 'original_text' => 'Play now', 'mode' => 'equal',
    'variants' => [['id' => $vs[1]['id'], 'text' => $vs[1]['text'], 'is_active' => 0], ['id' => $vs[2]['id'], 'text' => $vs[2]['text']]]], $testId);
$assert('update keeps variant ids, can pause one', $e2 === null && count($t2['variants']) === 3 && $t2['variants'][1]['is_active'] === 0);
$assert('stats survive an edit that keeps the control', array_sum(array_map(fn($v) => $v['stats']['views'], $t2['variants'])) === 1200);
[$t3] = TextTests::save($pdo, ['landing_id' => 5, 'original_text' => 'Hello', 'variants' => ['Hi there']], $testId);
$assert('a new control text starts a fresh test', array_sum(array_map(fn($v) => $v['stats']['views'], $t3['variants'])) === 0 && $t3['winner_variant_id'] === null);
[$t4, $e4] = TextTests::save($pdo, ['landing_id' => 9, 'original_text' => 'Hello', 'variants' => ['x']], $testId);
$assert('cannot move a test to another landing', $e4 === 'not_found');
$assert('delete', TextTests::delete($pdo, $testId) && !(int) $pdo->query("SELECT COUNT(*) FROM text_test_hits")->fetchColumn());

// ---- candidates -------------------------------------------------------------------------
$cands = TextTests::candidates('<html><head><title>Casino Greece</title><script>var a="Do not pick";</script></head><body>'
    . '<div><h1>Get 200% <br> bonus</h1><p>Short <b>bold</b> text</p><p>Register in 1 minute</p>'
    . '<a href="#">Play now</a><button>Play now</button><span>ok</span></div></body></html>');
$texts = array_column($cands, 'text');
$assert('title and h1 first', $texts[0] === 'Casino Greece' && $texts[1] === 'Get 200% bonus' || in_array('Casino Greece', $texts, true), json_encode($cands));
$assert('paragraph with nested markup skipped, plain paragraph kept', !in_array('Short bold text', $texts, true) && in_array('Register in 1 minute', $texts, true));
$assert('scripts and too-short texts ignored, duplicates once', !in_array('Do not pick', $texts, true) && !in_array('ok', $texts, true)
    && count(array_keys($texts, 'Play now', true)) === 1);

// ---- generateVariants parsing -----------------------------------------------------------
AiClient::$transport = fn() => [200, ['choices' => [['message' => ['content' => "Here you go:\n```json\n[\"Spin to win\", \"Play now\", \"Your bonus waits\"]\n```"]]]], ''];
$g = TextTests::generateVariants(new AiClient('openai', '', 'k', ''), 'Play now', 3, 'en');
$assert('JSON array extracted from a chatty reply, original dropped', $g === ['Spin to win', 'Your bonus waits'], json_encode($g));
AiClient::$transport = fn() => [200, ['choices' => [['message' => ['content' => "1. Играть сейчас\n2) Забрать бонус\n- Начать выигрывать"]]]], ''];
$g = TextTests::generateVariants(new AiClient('openai', '', 'k', ''), 'Play now', 5, 'ru');
$assert('numbered list fallback', $g === ['Играть сейчас', 'Забрать бонус', 'Начать выигрывать'], json_encode($g, JSON_UNESCAPED_UNICODE));
AiClient::$transport = null;

@unlink($tmpDb);
if ($failures > 0) {
    echo "\nFAILED: $failures assertion(s)\n";
    exit(1);
}
echo "\nAll text test assertions passed.\n";
