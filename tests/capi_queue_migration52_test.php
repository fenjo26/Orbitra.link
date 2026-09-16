<?php
// Run the real config.php bootstrap directly in a disposable directory, with
// explicit upgrade/rerun fixtures. No assets, user databases or network are accessed.
$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/orbitra-capi-schema-' . bin2hex(random_bytes(8));
mkdir($fixture . '/core', 0700, true);
foreach (['config.php', 'core/landing_path.php', 'core/admin_path.php', 'core/StreamFilters.php', 'core/ConversionAttribution.php'] as $file) {
    if (!copy($root . '/' . $file, $fixture . '/' . $file)) {
        throw new RuntimeException('Could not stage bootstrap fixture');
    }
}
$runConfig = static function () use ($fixture): string {
    $process = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec',
        '-r', 'require ' . var_export($fixture . '/config.php', true) . '; echo "BOOTSTRAP_OK";'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start isolated bootstrap');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $output;
};
$failures = [];
$checks = 0;
$check = static function (string $name, bool $ok) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $name;
    }
};
$open = static function () use ($fixture): PDO {
    return new PDO('sqlite:' . $fixture . '/orbitra_db.sqlite', null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};

$check('fresh bootstrap completes', $runConfig() === 'BOOTSTRAP_OK');
$pdo = $open();
$check('fresh schema is 52', (int) $pdo->query('PRAGMA user_version')->fetchColumn() === 52);
$check('fresh index covers conversion_id',
    $pdo->query("PRAGMA index_info('idx_s2s_postbacks_conversion')")->fetchAll(PDO::FETCH_COLUMN, 2) === ['conversion_id']);
$check('fresh private key table exists without generating an unused secret',
    (int) $pdo->query('SELECT count(*) FROM meta_matching_keys')->fetchColumn() === 0);
$plan = $pdo->query('EXPLAIN QUERY PLAN SELECT url, payload_json FROM s2s_postbacks_log WHERE conversion_id=1 AND postback_id IS NULL')->fetchAll();
$check('delivery lookup uses index', str_contains(json_encode($plan), 'idx_s2s_postbacks_conversion'));

// Removing the two additive objects restores the real schema51 produced by
// config.php, retaining every other table/index for upgrade tests.
$pdo->exec('DROP INDEX idx_s2s_postbacks_conversion');
$pdo->exec('DROP TABLE meta_matching_keys');
$pdo->exec('PRAGMA user_version = 51');
$pdo->exec("INSERT INTO campaigns (id, name, alias) VALUES (500, 'Fixture', 'schema-fixture')");
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip) VALUES ('schema-click', 500, '192.0.2.1')");
$pdo->exec("INSERT INTO conversions (id, click_id, status) VALUES (500, 'schema-click', 'lead')");
$pdo->exec("INSERT INTO s2s_postbacks_log (conversion_id, url, status, attempts, response)
    VALUES (500, 'https://graph.facebook.com/v25.0/123/events', 'delivered', 1, '{\"events_received\":1}')");
$pdo->exec("INSERT INTO s2s_postbacks_log (conversion_id, url, status, attempts, next_retry_at)
    VALUES (500, 'https://partner.example/postback', 'failed', 6, NULL)");
$before = $pdo->query('SELECT * FROM s2s_postbacks_log ORDER BY id')->fetchAll();
$pdo = null;
$check('schema51 upgrade completes', $runConfig() === 'BOOTSTRAP_OK');
$pdo = $open();
$check('upgrade stamps52', (int) $pdo->query('PRAGMA user_version')->fetchColumn() === 52);
$check('upgrade preserves all queue data without replay', $pdo->query('SELECT * FROM s2s_postbacks_log ORDER BY id')->fetchAll() === $before);
$check('conversion remains unchanged', (int) $pdo->query('SELECT count(*) FROM conversions WHERE id=500')->fetchColumn() === 1);
$pdo->prepare('INSERT INTO meta_matching_keys (id,secret) VALUES (1,?)')->execute([str_repeat('a', 64)]);
$check('signing key never enters settings', (int) $pdo->query("SELECT count(*) FROM settings WHERE key='meta_matching_secret'")->fetchColumn() === 0);
$pdo = null;
$check('repeat bootstrap completes', $runConfig() === 'BOOTSTRAP_OK');
$pdo = $open();
$check('repeat preserves all delivery rows', $pdo->query('SELECT * FROM s2s_postbacks_log ORDER BY id')->fetchAll() === $before);
$check('exactly one conversion lookup index', (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='index' AND name='idx_s2s_postbacks_conversion'")->fetchColumn() === 1);
$check('repeat preserves the signing key', $pdo->query('SELECT secret FROM meta_matching_keys WHERE id=1')->fetchColumn() === str_repeat('a', 64));

// A failed DDL must not mark the upgrade complete and hide the missing index.
$pdo->exec('DROP INDEX idx_s2s_postbacks_conversion');
$pdo->exec('CREATE TABLE idx_s2s_postbacks_conversion (id INTEGER)');
$pdo->exec('PRAGMA user_version = 51');
$pdo = null;
$check('failed index creation is not acknowledged as successful bootstrap', !str_contains($runConfig(), 'BOOTSTRAP_OK'));
$pdo = $open();
$check('failed migration does not stamp52', (int) $pdo->query('PRAGMA user_version')->fetchColumn() === 51);
$pdo->exec('DROP TABLE idx_s2s_postbacks_conversion');
$pdo = null;
$check('upgrade can retry after DDL conflict is removed', $runConfig() === 'BOOTSTRAP_OK');

foreach ($failures as $failure) {
    echo "FAIL: $failure\n";
}
echo 'CAPI schema52: ' . ($checks - count($failures)) . "/$checks passed\n";
echo "Disposable fixture: $fixture\n";
exit($failures ? 1 : 0);
