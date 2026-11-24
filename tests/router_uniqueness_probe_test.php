<?php
// tests/router_uniqueness_probe_test.php
//
// The redirect router (index.php) does not probe click uniqueness itself.
//
// It used to run orbitraFindUniquenessConflict() on every click and store the
// answer in $isUnique — which nothing ever read: no stream filter consumed it,
// and the clicks row gets its uniq_campaign / uniq_stream / uniq_global flags
// from orbitraComputeClickFlags(), the same probes by the campaign's own
// window. One redundant indexed read per click.
//
// This pins both halves: the call is gone from the router, and the flags are
// still on every row.
//
// Run: php tests/router_uniqueness_probe_test.php

require_once __DIR__ . '/lib/http.php';

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

$routerSource = (string) file_get_contents(dirname(__DIR__) . '/index.php');
// The call, not the name: the comment left in its place names the function.
check(substr_count($routerSource, 'orbitraFindUniquenessConflict($') === 0,
    'the router no longer runs orbitraFindUniquenessConflict() itself');
check(str_contains($routerSource, 'orbitraMergeClickFlags(') && str_contains($routerSource, 'orbitraComputeClickFlags('),
    '…and still writes the honesty flags computed before the insert');

$harness = new OrbitraTestHarness(dirname(__DIR__));
$iphone = 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';

try {
    $harness->start();
    $data = $harness->seedCloakTestData();
    $pdo = $harness->getPdo();
    $cid = $data['campaign_id'];
    $harness->setSetting('click_debounce_seconds', '0');

    // The same visitor twice.
    $harness->getWithHeaders('/?campaign_id=' . $cid, [$iphone, 'X-Forwarded-For: 8.8.4.44', 'Accept-Language: en-US']);
    $harness->getWithHeaders('/?campaign_id=' . $cid, [$iphone, 'X-Forwarded-For: 8.8.4.44', 'Accept-Language: en-US']);
    $rows = $pdo->query("SELECT uniq_campaign, uniq_stream, uniq_global FROM clicks WHERE campaign_id = {$cid} AND ip = '8.8.4.44' ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

    check(count($rows) === 2, 'two clicks of one visitor are two rows (debounce off)');
    $flags = static fn (int $i): array => isset($rows[$i])
        ? [(int) $rows[$i]['uniq_campaign'], (int) $rows[$i]['uniq_stream'], (int) $rows[$i]['uniq_global']]
        : ['row missing'];
    check($flags(0) === [1, 1, 1], 'the first click is unique by campaign, by stream and globally — got ' . json_encode($flags(0)));
    check($flags(1) === [0, 0, 0], 'the second is not, on all three — got ' . json_encode($flags(1)));
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    $ok = false;
} finally {
    $harness->stop();
}

exit($ok ? 0 : 1);
