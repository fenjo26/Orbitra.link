<?php
/**
 * ТЗ 2.1: the seam paths never interpolate request values. Hostile
 * date/campaign/custom payloads against the seam-reading actions — the
 * server must answer JSON (not an SQL error), and the tables must survive.
 *
 * Usage: php tests/stats_seam_hostile_http_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
try {
    $pdo = $harness->getPdo();
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/stats_freeze_cron.php', $work . '/cli/stats_freeze_cron.php');

    // A frozen hour so the seam branches actually run, plus one live campaign.
    // The sandbox seeds campaign id 1; anything present works for the seam.
    $campId = (int) $pdo->query('SELECT id FROM campaigns ORDER BY id LIMIT 1')->fetchColumn();
    $old = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
    $pdo->exec("INSERT INTO clicks (id, campaign_id, ip, country_code, uniq_campaign, uniq_global, cost, created_at)
                VALUES ('hv1', $campId, '203.0.113.77', 'DE', 1, 1, 0.5, '$old')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '3')");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/stats_freeze_cron.php') . ' --quiet --budget=10 2>/dev/null');
    $wm = (int) $pdo->query("SELECT value FROM settings WHERE key='stats_frozen_through_hour'")->fetchColumn();
    check($wm > 0, 'an hour is frozen, the seam branches are live');

    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['hv_adm', password_hash('hv_pass_123', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'hv_adm', 'password' => 'hv_pass_123']), ['Content-Type: application/json']);
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $cm);
    $cookie = 'Cookie: ORBITRASESSID=' . ($cm[1] ?? '');

    // Encoded URL, hostile VALUES: what matters is what the server does with
    // the value, not whether a raw quote breaks the HTTP layer.
    $payloads = [
        ['campaigns', 'date_from', "'; DROP TABLE campaigns;--"],
        ['campaigns', 'date_from', "2026-01-01' OR 1=1--"],
        ['campaigns', 'date_to', "2026-01-01'; INSERT INTO campaigns (id, name, alias) VALUES (99, 'evil', 'evil');--"],
        ['campaigns', 'campaign_id', '1 OR 1=1'],
        ['campaigns', 'campaign_id', "' UNION SELECT 1"],
        ['metrics', 'custom_from', '"><script>alert(1)</script>'],
        ['metrics', 'custom_to', "2026-01-01' AND 1=1 --"],
        ['chart', 'custom_from', "x' OR 'a'='a"],
    ];
    foreach ($payloads as [$action, $key, $value]) {
        $qs = http_build_query(['action' => $action, 'date_range' => 'all', $key => $value]);
        $r = $harness->getWithHeaders('/api.php?' . $qs, [$cookie]);
        $body = (string) $r['body'];
        $j = json_decode($body, true);
        $status = is_array($j) ? ($j['status'] ?? '') : '';
        $tablesAlive = (int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn() >= 1
            && (int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn() >= 1
            && (int) $pdo->query('SELECT COUNT(*) FROM stats_place_country')->fetchColumn() >= 1;
        $noLeak = !str_contains($body, 'DROP TABLE') && !str_contains($body, 'SQLSTATE');
        check($status !== '' && $tablesAlive && $noLeak,
            "$action $key payload survives bound (status=$status, tables intact)");
    }
    // The INSERT payload must not have created its row.
    $evilRows = (int) $pdo->query("SELECT COUNT(*) FROM campaigns WHERE alias = 'evil'")->fetchColumn();
    check($evilRows === 0, 'the INSERT payload created nothing');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
