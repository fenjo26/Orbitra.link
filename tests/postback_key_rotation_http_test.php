<?php
/**
 * Postback key rotation, wizard error codes, dashboard source uniques.
 *
 *   - setup_first_user rejects a short password with a machine `code` the
 *     frontend can localize (the Russian message is a fallback, not the UI).
 *   - Rotating postback_key via global_settings (panel) or save_settings
 *     (admin API) rewrites the /{key}/postback segment in every stored
 *     affiliate-network Postback URL, leaves look-alike keys alone, and is a
 *     no-op when the key did not change.
 *   - traffic_sources returns unique_clicks (COUNT DISTINCT ip) — the
 *     dashboard Sources block used to render a permanent 0 there.
 *
 * Run with: php tests/postback_key_rotation_http_test.php
 */

$testPassed = true;

function rotOk($message) {
    echo "✓ $message\n";
}
function rotEquals($expected, $actual, string $message): bool {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n  Expected: " . var_export($expected, true)
            . "\n  Actual:   " . var_export($actual, true) . "\n");
        $testPassed = false;
        return false;
    }
    rotOk($message);
    return true;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    // --- setup_first_user error codes (users table still empty) -------------
    $resp = $harness->postWithHeaders('/api.php?action=setup_first_user',
        json_encode(['username' => 'wizard', 'password' => 'short7', 'language' => 'en']),
        ['Content-Type: application/json']);
    $body = json_decode($resp['body'], true);
    rotEquals('error', $body['status'] ?? '', 'short password rejected');
    rotEquals('password_too_short', $body['code'] ?? '', 'rejection carries a machine code');
    rotEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'failed setup created no user');

    // --- admin session for the settings actions ------------------------------
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['rot_admin', password_hash('rot_pass_123', PASSWORD_DEFAULT)]);
    try { $pdo->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
    $resp = $harness->postWithHeaders('/api.php?action=login',
        json_encode(['username' => 'rot_admin', 'password' => 'rot_pass_123']),
        ['Content-Type: application/json']);
    $login = json_decode($resp['body'], true);
    if (($login['status'] ?? '') !== 'success') {
        fwrite(STDERR, "login failed: " . $resp['body'] . "\n");
        exit(1);
    }
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
    $cookie = 'ORBITRASESSID=' . $m[1];
    $csrf = $login['data']['csrf_token'] ?? '';
    $post = static function (string $action, array $payload) use ($harness, $cookie, $csrf): array {
        return $harness->postWithHeaders("/api.php?action=$action", json_encode($payload), [
            'Cookie: ' . $cookie, 'X-CSRF-TOKEN: ' . $csrf, 'Content-Type: application/json',
        ]);
    };
    $get = static function (string $action) use ($harness, $cookie): array {
        return $harness->getWithHeaders("/api.php?action=$action", ['Cookie: ' . $cookie]);
    };

    // --- fixture: key + two affiliate networks --------------------------------
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'rot_old_key')");
    $pdo->exec("INSERT INTO affiliate_networks (name, template, postback_url) VALUES
        ('Rewrite me', 'generic', 'http://track.example/rot_old_key/postback?subid={subid}&status={status}'),
        ('Look-alike key', 'generic', 'http://track.example/rotXoldXkey/postback?subid={subid}&status={status}')");
    $urlOf = static function (string $name) use ($pdo): string {
        $stmt = $pdo->prepare('SELECT postback_url FROM affiliate_networks WHERE name = ?');
        $stmt->execute([$name]);
        return (string) $stmt->fetchColumn();
    };

    // --- global_settings rotation ---------------------------------------------
    $resp = $post('global_settings', ['settings' => ['postback_key' => 'rot_new_key']]);
    rotEquals('success', (json_decode($resp['body'], true)['status'] ?? ''), 'global_settings saves the new key');
    rotEquals('rot_new_key', (string) $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'")->fetchColumn(), 'setting updated');
    rotEquals('http://track.example/rot_new_key/postback?subid={subid}&status={status}', $urlOf('Rewrite me'), 'stored network URL rewritten to the new key');
    rotEquals('http://track.example/rotXoldXkey/postback?subid={subid}&status={status}', $urlOf('Look-alike key'), 'look-alike key (LIKE wildcard trap) untouched');

    // Re-saving the same key must be a no-op.
    $resp = $post('global_settings', ['settings' => ['postback_key' => 'rot_new_key']]);
    rotEquals('success', (json_decode($resp['body'], true)['status'] ?? ''), 'same-key save succeeds');
    rotEquals('http://track.example/rot_new_key/postback?subid={subid}&status={status}', $urlOf('Rewrite me'), 'same-key save rewrites nothing');

    // --- save_settings rotation (the admin API writer) ------------------------
    $resp = $post('save_settings', ['postback_key' => 'rot_api_key']);
    rotEquals('success', (json_decode($resp['body'], true)['status'] ?? ''), 'save_settings saves the new key');
    rotEquals('http://track.example/rot_api_key/postback?subid={subid}&status={status}', $urlOf('Rewrite me'), 'save_settings rotation also rewrites stored URLs');
    rotEquals('http://track.example/rotXoldXkey/postback?subid={subid}&status={status}', $urlOf('Look-alike key'), 'look-alike still untouched');

    // --- traffic_sources uniques ----------------------------------------------
    $today = gmdate('Y-m-d');
    $pdo->exec("INSERT INTO traffic_sources (name) VALUES ('Smiley Push')");
    $sourceId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO campaigns (name, alias, source_id) VALUES ('Rot Camp', 'rot_camp', $sourceId)");
    $campaignId = (int) $pdo->lastInsertId();
    // clicks.id is a TEXT key — the production click writer always supplies
    // one, and a NULL id (legal in SQLite for non-INTEGER PKs) would silently
    // zero out COUNT(cl.id)-style metrics.
    $ins = $pdo->prepare('INSERT INTO clicks (id, campaign_id, ip, cost, created_at) VALUES (?, ?, ?, 0.1, ?)');
    $ins->execute(['rot_c1', $campaignId, '198.51.100.7', "$today 09:00:00"]);
    $ins->execute(['rot_c2', $campaignId, '198.51.100.7', "$today 10:00:00"]);
    $ins->execute(['rot_c3', $campaignId, '198.51.100.9', "$today 11:00:00"]);

    $resp = $get('traffic_sources');
    $body = json_decode($resp['body'], true);
    $row = null;
    foreach (($body['data'] ?? []) as $s) {
        if (($s['name'] ?? '') === 'Smiley Push') { $row = $s; break; }
    }
    rotEquals(3, (int) ($row['clicks'] ?? -1), 'source clicks = 3 raw hits');
    rotEquals(2, (int) ($row['unique_clicks'] ?? -1), 'source unique_clicks = 2 distinct IPs (was always 0)');

    $harness->stop();
    echo $testPassed ? "postback key rotation: OK\n" : "postback key rotation: FAILED\n";
    exit($testPassed ? 0 : 1);
} catch (Throwable $e) {
    $harness->stop();
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n");
    exit(1);
}
