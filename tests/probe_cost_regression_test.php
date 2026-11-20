<?php
/**
 * Regression: the ip-probe cost must not grow with the table (stand
 * incident 2026-11-21, DIAG_STEP1_FAIL). One campaign, unique IPs — the
 * CPA.RIP shape. Without the INDEXED BY pin the planner (no sqlite_stat1 on
 * real installs — nothing runs ANALYZE) picks idx_clicks_campaign_created,
 * and every probe range-scans ALL clicks of the campaign inside the
 * uniqueness window: 0 successful responses out of 144 k at 480 rps.
 * With the pin the probe seeks the ip prefix of idx_clicks_ip_ua_created
 * and the cost is flat in the row count.
 *
 * Measured through the REAL probe functions (hint included): 200 probes at
 * the 5k mark vs 200 at 50k; per-probe time must stay flat (ratio < 3×,
 * absolute < 1 ms — generous for a laptop). Deliberately NO ANALYZE: the
 * deployed shape.
 *
 * Usage: php tests/probe_cost_regression_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/core/click_logger.php';
require_once $repoRoot . '/core/ClickFlags.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$work = sys_get_temp_dir() . '/orbitra_probe_cost_' . getmypid();
@mkdir($work, 0777, true);
register_shutdown_function(static function () use ($work): void {
    foreach (glob($work . '/*') ?: [] as $f) @unlink($f);
    foreach (glob($work . '/var/cache/*') ?: [] as $f) @unlink($f);
    @rmdir($work . '/var/cache');
    @rmdir($work . '/var');
    @rmdir($work);
});
$configSrc = preg_replace(
    '/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/',
    '$db_file = __DIR__ . "/test.sqlite";',
    (string) file_get_contents($repoRoot . '/config.php')
);
file_put_contents($work . '/config.php', $configSrc);
exec('cp -R ' . escapeshellarg($repoRoot . '/core') . ' ' . escapeshellarg($work . '/core') . ' 2>/dev/null');

try {
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($work . '/config.php', true) . ';') . ' 2>/dev/null');
    $db = $work . '/test.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    // the stand end-state: ip_ua present, ip_created retired
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_ip_ua_created ON clicks(ip, ua_hash, created_at)');
    $pdo->exec('DROP INDEX IF EXISTS idx_clicks_ip_created');

    $ins = $pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_id, stream_id, ip, user_agent, ua_hash, created_at, uniq_campaign) VALUES (?,?,?,?,?,?,?,?,1)');
    mt_srand(7);
    $ipOf = fn(int $i) => sprintf('%d.%d.%d.%d', mt_rand(1, 223), mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254));
    $seed = function (int $from, int $to) use ($ins, $ipOf): void {
        for ($i = $from; $i < $to; $i++) {
            $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/130 Mobile ' . ($i % 41);
            $ins->execute([sprintf('%012x-aaaa-7%03x-8aaa-%s', (int) (microtime(true) * 1000) + $i, $i % 4096, substr(sha1("r$i"), 0, 12)), 2, 990021, 990022, $ipOf($i), $ua, crc32($ua), gmdate('Y-m-d H:i:s')]);
        }
    };
    $timeProbes = function () use ($pdo, $ipOf): float {
        // the real click-path probes: uniqueness (IP), ClickFlags campaign
        // and global — half misses (fresh IPs), half hits (seeded IPs)
        $t0 = microtime(true);
        for ($i = 0; $i < 200; $i++) {
            $ip = $ipOf($i);
            orbitraFindUniquenessConflict($pdo, 2, $ip, 'ua', 24, 'IP');
            orbitraClickIsUnique($pdo, $ip, 'Mozilla/5.0 x', false, 24, ['campaign_id' => 2]);
            orbitraClickIsUnique($pdo, $ip, 'Mozilla/5.0 x', false, 24, []);
        }
        return (microtime(true) - $t0) * 1000.0 / 200.0; // ms per click-triple
    };

    $seed(0, 5000);
    $ms5k = $timeProbes();
    $seed(5000, 50000);
    $ms50k = $timeProbes();
    printf("per-click probe triple: 5k rows = %.3f ms, 50k rows = %.3f ms (ratio %.2fx)\n", $ms5k, $ms50k, $ms50k / max(0.0001, $ms5k));
    check($ms50k < max(3.0 * $ms5k, 1.0), sprintf('probe cost is flat in the row count (5k %.3f ms -> 50k %.3f ms, ratio %.2fx)', $ms5k, $ms50k, $ms50k / max(0.0001, $ms5k)));

    // and the plans stay pinned (no ANALYZE anywhere — the deployed shape)
    $hint = orbitraClicksIpIndexHint($pdo);
    $st = $pdo->prepare('EXPLAIN QUERY PLAN SELECT id FROM clicks' . $hint . ' WHERE ip = ? AND created_at >= ? AND campaign_id = ? LIMIT 1');
    $st->execute(['9.9.9.9', '2026-01-01 00:00:00', 2]);
    $plan = implode(' | ', $st->fetchAll(PDO::FETCH_COLUMN, 3));
    check(strpos($plan, 'idx_clicks_ip_ua_created') !== false && strpos($plan, 'SCAN') === false, "the uniqueness probe seeks ip_ua ({$plan})");
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo $failures === 0 ? "Probe cost regression tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
