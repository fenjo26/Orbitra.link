<?php
/**
 * Postback parameter aliases: the list on the Postback page is what the
 * handler reads.
 *
 * The page used to keep its aliases in a state of its own that nothing
 * loaded or saved ("Save" showed a copy-to-clipboard toast), and postback.php
 * never read the setting — the whole feature was a facade.
 *
 * Covered here:
 *  - PostbackAliases::normalize(): what is kept, dropped and rejected, and
 *    that the untouched default seed stays inert;
 *  - save_settings stores the map and reports entries that cannot work;
 *  - the settings response carries the normalised map and the built-ins;
 *  - a configured alias resolves on the live route: subid, status, payout, tid;
 *  - the parameter's own name wins, then the built-in aliases;
 *  - the pending-replay "superseded" check sees tid under an alias.
 *
 * Usage: php tests/postback_aliases_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/PostbackAliases.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// --- normalize() -----------------------------------------------------------------
$rejected = ['stale' => 'x'];
check(PostbackAliases::normalize(null, $rejected) === [] && $rejected === [], 'no setting: no aliases, nothing rejected');
check(PostbackAliases::normalize('{broken', $rejected) === [] && $rejected === [], 'unreadable JSON: no aliases');
check(PostbackAliases::normalize('"clickid"', $rejected) === [] && $rejected === ['' => 'map'], 'a bare string is not a map');

$rejected = [];
$clean = PostbackAliases::normalize(json_encode([
    'cid' => 'subid',        // kept
    'clickid' => 'subid',    // a built-in of the same parameter: redundant
    'st' => 'status',        // kept
    'foo' => 'campaign',     // not one of the four parameters
    'subid' => 'status',     // a parameter's own name
    'revenue' => 'status',   // a built-in of ANOTHER parameter
    'a-b' => 'status',       // not a parameter name
]), $rejected);
check($clean === ['cid' => 'subid', 'st' => 'status'], 'custom aliases kept, the redundant built-in dropped');
check($rejected === ['foo' => 'target', 'subid' => 'self', 'revenue' => 'builtin', 'a-b' => 'name'], 'the rest rejected by name and reason (' . json_encode($rejected) . ')');

// The row config.php seeds. It was never read, so its tid entry must not
// switch repeat-deposit accounting on by itself.
$seed = ['clickid' => 'subid', 'transaction_id' => 'tid', 'revenue' => 'payout', 'profit' => 'payout', 'type' => 'status'];
$rejected = [];
check(PostbackAliases::normalize($seed, $rejected) === [] && $rejected === [], 'the untouched default seed configures nothing');
check(PostbackAliases::normalize($seed + ['x' => 'tid'], $rejected) === ['transaction_id' => 'tid', 'x' => 'tid'] && $rejected === [], 'a seed the operator added to is honoured as configured');

// --- resolution order ------------------------------------------------------------
$aliases = ['cid' => 'subid', 'tx' => 'tid'];
check(PostbackAliases::param(['cid' => 'C1'], $aliases, 'subid') === 'C1', 'a configured alias resolves');
check(PostbackAliases::param(['subid' => 'C0', 'cid' => 'C1'], $aliases, 'subid') === 'C0', "the parameter's own name wins");
check(PostbackAliases::param(['clickid' => 'C2', 'cid' => 'C1'], $aliases, 'subid') === 'C2', 'a built-in alias wins over a configured one');
check(PostbackAliases::param(['cid' => 'C1'], $aliases, 'status') === null, 'nothing present: null');
check(PostbackAliases::builtinByParam() === [
    'subid' => ['clickid', 'click_id', 'sub_id'], 'status' => ['type'],
    'payout' => ['revenue', 'profit'], 'tid' => [],
], 'the built-ins are exactly what postback.php accepted before the setting was read');

// --- over HTTP -------------------------------------------------------------------
$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
try {
    $seedData = $harness->seedTestData();
    $key = $seedData['postback_key'];
    $clickId = $seedData['click_id'];
    $pdo = $harness->getPdo();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['alias_adm', password_hash('alias_pass_123', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'alias_adm', 'password' => 'alias_pass_123']), ['Content-Type: application/json']);
    $login = json_decode($resp['body'], true);
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
    $cookie = 'ORBITRASESSID=' . ($m[1] ?? '');
    $csrf = $login['data']['csrf_token'] ?? '';
    check(($login['status'] ?? '') === 'success' && $csrf !== '', 'admin session for the settings actions');
    $post = static function (string $action, array $payload) use ($harness, $cookie, $csrf): array {
        $r = $harness->postWithHeaders("/api.php?action=$action", json_encode($payload), [
            'Cookie: ' . $cookie, 'X-CSRF-TOKEN: ' . $csrf, 'Content-Type: application/json',
        ]);
        return json_decode((string) $r['body'], true) ?? [];
    };
    $get = static function (string $action) use ($harness, $cookie): array {
        $r = $harness->getWithHeaders("/api.php?action=$action", ['Cookie: ' . $cookie]);
        return json_decode((string) $r['body'], true) ?? [];
    };
    $postback = static function (string $query) use ($harness, $key): array {
        return $harness->get("/{$key}/postback?" . $query);
    };
    // fetchAll everywhere: an open cursor pins this connection to an old WAL
    // snapshot and the next write here fails with SQLITE_BUSY_SNAPSHOT.
    $conversions = static function (string $click) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM conversions WHERE click_id = ? ORDER BY id');
        $stmt->execute([$click]);
        return $stmt->fetchAll();
    };
    $logResults = static function (string $click) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT result FROM incoming_postbacks_log WHERE click_id = ? ORDER BY id');
        $stmt->execute([$click]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    $storedAliases = static function () use ($pdo): array {
        return json_decode((string) $pdo->query("SELECT value FROM settings WHERE key = 'postback_aliases'")->fetchColumn(), true);
    };
    $addClick = static function (string $id) use ($pdo, $seedData): void {
        $pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent) VALUES (?, ?, ?, '203.0.113.9', 'Alias-Agent/1.0')")
            ->execute([$id, $seedData['campaign_id'], $seedData['offer_id']]);
    };

    // --- the settings response: what the page renders from
    $body = $get('settings');
    check(($body['data']['postback_aliases'] ?? 'missing') === [], 'no aliases configured yet: an empty map (got ' . json_encode($body['data']['postback_aliases'] ?? null) . ')');
    check(($body['data']['postback_builtin_aliases'] ?? null) === PostbackAliases::builtinByParam(), 'the built-ins arrive grouped by parameter');

    // --- save_settings: the page's POST
    $r = $post('save_settings', [
        'postback_key' => $key, 'currency' => 'USD',
        // clickid repeats a built-in of the same parameter: redundant, not an error.
        'postback_aliases' => ['cid' => 'subid', 'st' => 'status', 'amt' => 'payout', 'tx' => 'tid', 'clickid' => 'subid'],
    ]);
    check(($r['status'] ?? '') === 'success', 'the page payload is saved (' . json_encode($r) . ')');
    $valid = ['cid' => 'subid', 'st' => 'status', 'amt' => 'payout', 'tx' => 'tid'];
    check($storedAliases() === $valid, 'stored without the redundant built-in (' . json_encode($storedAliases()) . ')');
    $body = $get('settings');
    check(($body['data']['postback_aliases'] ?? null) === $valid, 'and read back the same');

    $r = $post('save_settings', ['postback_aliases' => ['ok' => 'status', 'a-b' => 'status', 'profit' => 'status', 'x' => 'nope']]);
    check(($r['status'] ?? '') === 'error' && ($r['code'] ?? '') === 'invalid_postback_aliases', 'aliases that cannot work are refused (' . json_encode($r) . ')');
    check(($r['data']['rejected'] ?? null) === ['a-b' => 'name', 'profit' => 'builtin', 'x' => 'target'], 'each by name and reason (' . json_encode($r['data']['rejected'] ?? null) . ')');
    check($storedAliases() === $valid, 'a refused save changes nothing');

    // Removing the last alias stores an empty map, which stays an object.
    check(($post('save_settings', ['postback_aliases' => []])['status'] ?? '') === 'success', 'an empty map is saved');
    check($storedAliases() === [] && substr((string) $pdo->query("SELECT value FROM settings WHERE key = 'postback_aliases'")->fetchColumn(), 0, 1) === '{', 'stored as {}');
    $r = $post('save_settings', ['postback_aliases' => $valid]);
    check(($r['status'] ?? '') === 'success' && $storedAliases() === $valid, 'aliases restored for the live checks');

    // --- the live route: every parameter under a configured alias
    $r = $postback("cid={$clickId}&st=sale&amt=12.5&tx=T1");
    check($r['code'] === 200 && stripos($r['body'], 'recorded') !== false, 'a postback with aliases only is recorded (got ' . $r['code'] . ' ' . $r['body'] . ')');
    $conv = $conversions($clickId);
    check(count($conv) === 1 && ($conv[0]['status'] ?? '') === 'sale' && abs((float) ($conv[0]['payout'] ?? 0) - 12.5) < 0.001, 'status and payout came from the aliases');
    check(($conv[0]['original_status'] ?? '') === 'sale' && ($conv[0]['currency'] ?? '') === 'USD', 'with the network status word and the default currency');
    check(($conv[0]['tid'] ?? 'missing') === 'T1', 'tid came from its alias');

    $r = $postback("cid={$clickId}&st=sale&amt=7&tx=T2");
    $conv = $conversions($clickId);
    check($r['code'] === 200 && count($conv) === 2, 'a second tid under the alias is a second conversion (repeat deposits)');
    check($logResults($clickId) === ['recorded', 'recorded'], 'both logged as recorded');

    // --- precedence
    $addClick('alias-own');
    $r = $postback('subid=alias-own&status=lead&st=rejected&payout=3&amt=99');
    $conv = $conversions('alias-own');
    check($r['code'] === 200 && count($conv) === 1 && ($conv[0]['status'] ?? '') === 'lead' && abs((float) ($conv[0]['payout'] ?? 0) - 3) < 0.001, "the parameter's own name beats a configured alias");

    $addClick('alias-builtin');
    $r = $postback('clickid=alias-builtin&cid=alias-own&status=lead&payout=1');
    check($r['code'] === 200 && count($conversions('alias-builtin')) === 1 && count($conversions('alias-own')) === 1, 'a built-in alias beats a configured one');

    $addClick('alias-plain');
    $r = $postback('click_id=alias-plain&type=sale&revenue=5.5');
    $conv = $conversions('alias-plain');
    check($r['code'] === 200 && count($conv) === 1 && ($conv[0]['status'] ?? '') === 'sale' && abs((float) ($conv[0]['payout'] ?? 0) - 5.5) < 0.001, 'the built-ins alone work as before');

    $r = $postback("cid=no-such-click&st=sale&amt=1");
    check($r['code'] === 404, 'an unknown id under an alias is still 404 (got ' . $r['code'] . ')');
    $r = $postback("cid={$clickId}&amt=1");
    check($r['code'] === 400 && stripos($r['body'], 'status') !== false, 'no status under any name: still 400 (got ' . $r['code'] . ' ' . $r['body'] . ')');

    // --- the untouched default seed stays inert
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_aliases', ?)")->execute([json_encode($seed)]);
    $addClick('alias-seed');
    $r = $postback('subid=alias-seed&status=sale&payout=5&transaction_id=ZZ');
    $conv = $conversions('alias-seed');
    check($r['code'] === 200 && count($conv) === 1 && $conv[0]['tid'] === null, 'transaction_id is not tid until the operator adds the alias (' . json_encode($conv[0]['tid'] ?? null) . ')');
    $body = $get('settings');
    check(($body['data']['postback_aliases'] ?? 'missing') === [], 'and the page shows the untouched seed as no custom aliases');
    // The same entry, once the operator configured it: active.
    $r = $post('save_settings', ['postback_aliases' => ['transaction_id' => 'tid']]);
    check(($r['status'] ?? '') === 'success' && $storedAliases() === ['transaction_id' => 'tid'], 'the operator can add transaction_id => tid');
    $r = $postback('subid=alias-seed&status=sale&payout=6&transaction_id=YY');
    $conv = $conversions('alias-seed');
    check(count($conv) === 2 && $conv[1]['tid'] === 'YY', 'and then it is honoured: a conversion per transaction id (' . json_encode(array_column($conv, 'tid')) . ')');

    // --- pending replay: "superseded" compares tid under an alias
    $r = $post('save_settings', ['postback_aliases' => $valid]);
    check(($r['status'] ?? '') === 'success' && $storedAliases() === $valid, 'aliases back for the replay check');
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    copy(__DIR__ . '/../cli/postback_replay.php', $work . '/cli/postback_replay.php');
    require_once $work . '/core/click_logger.php';
    check(orbitraSpoolClick([
        'id' => 'alias-spooled', 'campaign_id' => $seedData['campaign_id'], 'offer_id' => $seedData['offer_id'],
        'ip' => '203.0.113.9', 'user_agent' => 'Alias-Agent/1.0', 'parameters_json' => '{}',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]), 'a click is sitting in the spool');
    $r = $postback('cid=alias-spooled&st=lead&amt=0&tx=T9');
    check($r['code'] === 200 && stripos($r['body'], 'waiting') !== false, 'the lead for it is parked (got ' . $r['code'] . ' ' . $r['body'] . ')');
    check($logResults('alias-spooled') === ['pending_click'] && $conversions('alias-spooled') === [], 'as pending_click, no conversion yet');
    // The click lands, and the sale (same tid, same alias) arrives live before the replay.
    $addClick('alias-spooled');
    $r = $postback('cid=alias-spooled&st=sale&amt=40&tx=T9');
    check($r['code'] === 200 && ($conversions('alias-spooled')[0]['status'] ?? '') === 'sale', 'the live sale is recorded');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet --checkpoint-budget=0 2>/dev/null');
    $conv = $conversions('alias-spooled');
    check(count($conv) === 1 && ($conv[0]['status'] ?? '') === 'sale' && abs((float) ($conv[0]['payout'] ?? 0) - 40) < 0.001, 'the delayed lead did not roll the sale back (' . json_encode(array_map(static fn($c) => [$c['status'], $c['payout'], $c['tid']], $conv)) . ')');
    check($logResults('alias-spooled') === ['superseded', 'recorded'], 'closed as superseded: tid under an alias is what got compared (' . json_encode($logResults('alias-spooled')) . ')');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
