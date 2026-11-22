<?php
/**
 * ТЗ 2.1, stage 3 review: retention validation must not block unrelated
 * saves on installs that inherited stats_raw_retention_days < 31; the
 * stats_retention_days alias works for read/write; a CHANGED invalid value
 * is still rejected.
 *
 * Usage: php tests/stats_retention_validation_test.php
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

    // Admin login
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['ret_adm', password_hash('ret_pass_123', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'ret_adm', 'password' => 'ret_pass_123']), ['Content-Type: application/json']);
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        $cookie = 'Cookie: ORBITRASESSID=' . ($m[1] ?? '');
    $csrf = json_decode($resp['body'], true)['data']['csrf_token'] ?? '';
    $post = static function (array $payload) use ($harness, $cookie, $csrf): array {
        $r = $harness->postWithHeaders('/api.php?action=save_settings', json_encode($payload), [
            $cookie, 'X-CSRF-TOKEN: ' . $csrf, 'Content-Type: application/json',
        ]);
        return json_decode((string) $r['body'], true) ?? [];
    };

    // Simulate a pre-2.1 install that had stats_retention_days = 30 (legal then).
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '30')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_converted_retention_days', '365')");

    // 1. Saving an unrelated setting with inherited raw=30 must succeed.
    $r = $post(['audit_retention_days' => '45']);
    check(($r['status'] ?? '') === 'success', 'unrelated save succeeds with inherited raw=30 [' . ($r['status'] ?? 'NO-STATUS') . '] ' . substr((string)($r['message'] ?? ''), 0, 120));

    // 2. Saving the SAME raw=30 (unchanged) with other settings must succeed.
    $r = $post(['stats_raw_retention_days' => '30', 'stats_converted_retention_days' => '365', 'audit_retention_days' => '45']);
    check(($r['status'] ?? '') === 'success', 'unchanged raw=30 passes (not a change)');

    // 3. CHANGING raw to 15 must fail.
    $r = $post(['stats_raw_retention_days' => '15']);
    check(($r['status'] ?? '') === 'error', 'changing raw to 15 rejected');
    check(str_contains($r['message'] ?? '', 'between 31'), 'rejection message mentions the range');

    // 4. CHANGING raw to 60 must succeed.
    $r = $post(['stats_raw_retention_days' => '60']);
    check(($r['status'] ?? '') === 'success', 'changing raw to 60 accepted');
    check((int) $pdo->query("SELECT value FROM settings WHERE key = 'stats_raw_retention_days'")->fetchColumn() === 60, 'value written');

    // 5. Alias: stats_retention_days = 90 writes to stats_raw_retention_days.
    $r = $post(['stats_retention_days' => '90']);
    check(($r['status'] ?? '') === 'success', 'alias write succeeds');
    check((int) $pdo->query("SELECT value FROM settings WHERE key = 'stats_raw_retention_days'")->fetchColumn() === 90, 'alias wrote to stats_raw_retention_days=90');

    // 6. Converted < raw is rejected.
    $r = $post(['stats_converted_retention_days' => '30']);
    check(($r['status'] ?? '') === 'error', 'converted 30 < raw 90 rejected');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
