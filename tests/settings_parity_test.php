<?php
/**
 * Settings parity: what the panel's system pages send is what the server keeps.
 *
 * The 2.1 retention fields were added to save_settings (the API/MCP writer)
 * and to the panel form, but the panel posts to global_settings, whose lists
 * never got the two keys — "Unknown settings keys were not saved", nothing
 * persisted. The same rename left two readers on the pre-2.1
 * stats_retention_days row (the cleanup cron's postback-log window and the
 * browser-check GC).
 *
 * Static, no server:
 *  - every form field (name="…") and every key inside a `settings: {…}`
 *    payload of a component that posts to global_settings is on that
 *    handler's lists;
 *  - the handler's GET list and POST whitelist are the same set;
 *  - no runtime code reads the legacy key by its own name, outside the
 *    places that alias it, and the two former stragglers use the shared reader.
 *
 * Over HTTP and CLI:
 *  - the System Settings payload saves, reads back, validates like
 *    save_settings, and the old name still writes the new key;
 *  - the shared reader follows the new key, and the cleanup cron with it.
 *
 * Usage: php tests/settings_parity_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/stats_retention.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$repo = dirname(__DIR__);

// --- global_settings: the two server-side lists ----------------------------------
$api = (string) file_get_contents($repo . '/api.php');
$start = strpos($api, "case 'global_settings':");
$end = strpos($api, "case 'save_settings':", $start);
check($start !== false && $end !== false, 'global_settings and save_settings cases found in api.php');
$block = substr($api, $start, $end - $start);
$getKeys = $postKeys = [];
if (preg_match('/SELECT key, value FROM settings WHERE key IN \((.*?)\)/s', $block, $m)) {
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $km);
    $getKeys = $km[1];
}
if (preg_match('/\$whitelist = \[(.*?)\];/s', $block, $m)) {
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $km);
    $postKeys = $km[1];
}
sort($getKeys);
sort($postKeys);
check(count($getKeys) > 20 && count($postKeys) > 20, 'both lists were read (' . count($getKeys) . ' GET / ' . count($postKeys) . ' POST keys)');
check($getKeys === $postKeys, 'what GET returns and POST accepts is one set of keys (' . json_encode(array_values(array_unique(array_merge(array_diff($getKeys, $postKeys), array_diff($postKeys, $getKeys))))) . ' differ)');
$known = array_flip($getKeys);
check(isset($known['stats_raw_retention_days'], $known['stats_converted_retention_days']), 'the 2.1 retention keys are on the lists');

// --- the panel: every field and payload key it posts to global_settings ----------
$components = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/frontend/src/components', FilesystemIterator::SKIP_DOTS));
$missing = [];
$posters = [];
foreach ($components as $file) {
    if ($file->getExtension() !== 'jsx') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    $posts = false;
    $offset = 0;
    while (($p = strpos($src, 'global_settings', $offset)) !== false) {
        $window = substr($src, $p, 900);
        $posts = $posts
            || preg_match("/method:\s*['\"]POST['\"]/", $window) === 1
            || strpos(substr($src, max(0, $p - 60), 60), 'axios.post(') !== false;
        $offset = $p + 1;
    }
    if (!$posts) {
        continue;
    }
    $posters[] = $file->getFilename();
    $name = $file->getFilename();
    preg_match_all('/\bname="([A-Za-z0-9_]+)"/', $src, $nm);
    foreach (array_unique($nm[1]) as $field) {
        if (!isset($known[$field])) {
            $missing[] = "$name: field name=\"$field\"";
        }
    }
    // `settings: { key: value, … }` — the payload wrapper every caller uses.
    $offset = 0;
    while (preg_match('/\bsettings:\s*\{/', $src, $sm, PREG_OFFSET_CAPTURE, $offset)) {
        $open = $sm[0][1] + strlen($sm[0][0]) - 1;
        $depth = 0;
        $close = $open;
        for ($i = $open, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}' && --$depth === 0) {
                $close = $i;
                break;
            }
        }
        $payload = substr($src, $open, $close - $open + 1);
        preg_match_all('/(?:^|[,{\n])\s*([a-z][a-z0-9_]*)\s*:/', $payload, $pm);
        foreach (array_unique($pm[1]) as $key) {
            if (!isset($known[$key])) {
                $missing[] = "$name: payload key \"$key\"";
            }
        }
        $offset = $close;
    }
}
sort($posters);
check(in_array('SystemSettings.jsx', $posters, true), 'SystemSettings.jsx is among the global_settings writers (' . implode(', ', $posters) . ')');
check($missing === [], 'every field and payload key the panel posts is accepted by global_settings (' . implode('; ', $missing) . ')');

// --- the legacy retention key ----------------------------------------------------
// Runtime code may name stats_retention_days only where the old name is
// aliased to the new one, seeded, or used as a database fallback.
$allowed = [
    'config.php' => 'seed + the migration copy into stats_raw_retention_days',
    'api.php' => 'read/write alias',
    'core/stats_retention.php' => 'the fallback reader',
];
$legacyReaders = [];
$codeDirs = ['core', 'cli', 'mcp', 'aggregator_engines'];
$phpFiles = glob($repo . '/*.php') ?: [];
foreach ($codeDirs as $dir) {
    if (!is_dir($repo . '/' . $dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
foreach ($phpFiles as $path) {
    $rel = ltrim(str_replace('\\', '/', substr($path, strlen($repo))), '/');
    foreach (explode("\n", (string) file_get_contents($path)) as $no => $line) {
        if (strpos($line, 'stats_retention_days') !== false && strpos($line, 'stats_raw_retention_days') === false && !isset($allowed[$rel])) {
            $legacyReaders[] = "$rel:" . ($no + 1);
        }
    }
}
check($legacyReaders === [], 'no runtime code reads the legacy stats_retention_days on its own (' . implode(', ', $legacyReaders) . ')');
check(strpos((string) file_get_contents($repo . '/cli/cleanup_cron.php'), 'orbitraStatsRawRetentionDays($pdo)') !== false, 'the cleanup cron takes the raw window from the shared reader');
check(strpos((string) file_get_contents($repo . '/core/BrowserCheck.php'), 'orbitraStatsRawRetentionDays($pdo)') !== false, 'the browser-check GC takes it from the shared reader');

// --- over HTTP -------------------------------------------------------------------
$harness = new OrbitraTestHarness($repo);
$harness->useProductionRouter();
$harness->start();
try {
    $pdo = $harness->getPdo();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $setting = static function (string $key) use ($pdo): string {
        return (string) $pdo->query('SELECT value FROM settings WHERE key = ' . $pdo->quote($key))->fetchColumn();
    };

    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['par_adm', password_hash('par_pass_123', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'par_adm', 'password' => 'par_pass_123']), ['Content-Type: application/json']);
    $login = json_decode($resp['body'], true);
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
    $cookie = 'ORBITRASESSID=' . ($m[1] ?? '');
    $csrf = $login['data']['csrf_token'] ?? '';
    check(($login['status'] ?? '') === 'success' && $csrf !== '', 'admin session');
    $postGlobal = static function (array $settings) use ($harness, $cookie, $csrf): array {
        $r = $harness->postWithHeaders('/api.php?action=global_settings', json_encode(['settings' => $settings]), [
            'Cookie: ' . $cookie, 'X-CSRF-TOKEN: ' . $csrf, 'Content-Type: application/json',
        ]);
        return json_decode((string) $r['body'], true) ?? [];
    };
    $getGlobal = static function () use ($harness, $cookie): array {
        $r = $harness->getWithHeaders('/api.php?action=global_settings', ['Cookie: ' . $cookie]);
        return json_decode((string) $r['body'], true)['data'] ?? [];
    };

    // --- GET: the form shows what is stored
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '90'), ('stats_converted_retention_days', '180'), ('stats_retention_days', '256')");
    $data = $getGlobal();
    check(($data['stats_raw_retention_days'] ?? '') === '90' && ($data['stats_converted_retention_days'] ?? '') === '180', 'GET returns both windows as stored (' . json_encode([$data['stats_raw_retention_days'] ?? null, $data['stats_converted_retention_days'] ?? null]) . ')');
    check(($data['stats_retention_days'] ?? '') === '90', 'the old name reads as the raw window, not as its stale row');

    // --- the System Settings payload, key for key with handleSave()
    $panel = [
        'stats_enabled' => '1',
        'stats_raw_retention_days' => '60',
        'stats_converted_retention_days' => '400',
        'archive_retention_days' => '30',
        'admin_ip_access' => '',
        'ignore_prefetch' => '1',
        'click_debounce_seconds' => '2',
        'admin_path' => '',
    ];
    $r = $postGlobal($panel);
    check(($r['status'] ?? '') === 'success', 'the System Settings payload is accepted (' . json_encode($r) . ')');
    check($setting('stats_raw_retention_days') === '60' && $setting('stats_converted_retention_days') === '400', 'both windows persisted');
    $data = $getGlobal();
    check(($data['stats_raw_retention_days'] ?? '') === '60' && ($data['stats_converted_retention_days'] ?? '') === '400', 'and the form reads them back');
    check($setting('ignore_prefetch') === '1' && $setting('click_debounce_seconds') === '2', 'the rest of the payload saved with them');

    // --- validation: the same rules as save_settings
    $r = $postGlobal(['stats_raw_retention_days' => '15']);
    check(($r['status'] ?? '') === 'error' && ($r['code'] ?? '') === 'retention_raw_range' && str_contains((string) ($r['message'] ?? ''), 'between 31'), 'a raw window below 31 is refused, with the code the panel translates (' . json_encode($r) . ')');
    check($setting('stats_raw_retention_days') === '60', 'and not written');
    $r = $postGlobal(['stats_converted_retention_days' => '30']);
    check(($r['status'] ?? '') === 'error' && ($r['code'] ?? '') === 'retention_converted_short' && str_contains((string) ($r['message'] ?? ''), 'at least as long'), 'a converted window shorter than the raw one is refused');
    $r = $postGlobal(['stats_raw_retention_days' => '120', 'stats_converted_retention_days' => '100']);
    check(($r['status'] ?? '') === 'error', 'also when both are posted together');
    $r = $postGlobal(['stats_raw_retention_days' => '10', 'ignore_prefetch' => '0']);
    check(($r['status'] ?? '') === 'error' && $setting('ignore_prefetch') === '1' && $setting('stats_raw_retention_days') === '60', 'a refused payload writes none of its keys');
    $r = $postGlobal(['stats_raw_retention_days' => 'abc']);
    check(($r['status'] ?? '') === 'error', 'a non-numeric window is refused');

    // An inherited pre-2.1 value below 31 does not block unrelated saves.
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '30')");
    $r = $postGlobal(['stats_raw_retention_days' => '30', 'stats_converted_retention_days' => '365', 'ignore_prefetch' => '0']);
    check(($r['status'] ?? '') === 'success' && $setting('ignore_prefetch') === '0', 'an unchanged inherited raw window of 30 passes');

    // --- the old name still writes the new key (API/MCP clients)
    $r = $postGlobal(['stats_retention_days' => '200', 'stats_converted_retention_days' => '300']);
    check(($r['status'] ?? '') === 'success' && $setting('stats_raw_retention_days') === '200', 'stats_retention_days posted = stats_raw_retention_days written (' . json_encode($r) . ')');
    check($setting('stats_retention_days') === '256', 'the stale pre-2.1 row itself is left alone');

    // --- the shared reader
    check(orbitraStatsRawRetentionDays($pdo) === 200, 'the reader follows stats_raw_retention_days');
    $pdo->exec("DELETE FROM settings WHERE key = 'stats_raw_retention_days'");
    check(orbitraStatsRawRetentionDays($pdo) === 256, 'without the 2.1 row it falls back to the old name');
    $pdo->exec("DELETE FROM settings WHERE key = 'stats_retention_days'");
    check(orbitraStatsRawRetentionDays($pdo) === 256, 'and without either: 256');
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '77'), ('stats_converted_retention_days', '365'), ('stats_retention_days', '256')");

    // --- the cleanup cron, on the same database
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy($repo . '/cli/cleanup_cron.php', $work . '/cli/cleanup_cron.php');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/cleanup_cron.php') . ' 2>&1', $cronOut, $cronCode);
    $cronLog = implode("\n", $cronOut);
    check($cronCode === 0 && str_contains($cronLog, 'Cleanup done'), 'the cleanup cron runs to the end (exit ' . $cronCode . ')');
    check(str_contains($cronLog, 'clicks retention=77d'), 'with the raw window from stats_raw_retention_days, not the stale 256 (' . trim(strtok($cronLog, "\n") ?: '') . ')');
    check(str_contains($cronLog, 'older than 77 days'), 'and purges the postback log by the same window');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
