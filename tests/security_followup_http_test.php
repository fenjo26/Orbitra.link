<?php
/**
 * Follow-up hardening after the v1.5.15 security audit (independent review):
 *
 *  - global_settings POST is admin-only (a restricted user could set the
 *    retention windows to 1 day and let the cleanup cron wipe statistics);
 *  - save_pixel_profile: non-admins may create, never edit or duplicate a
 *    shared profile;
 *  - totp_setup cannot silently switch an ENABLED second factor off;
 *  - a TOTP code is accepted once;
 *  - login throttling counts failures only, successes do not spend budget,
 *    and one IP cannot lock an account.
 *
 * Usage: php tests/security_followup_http_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/Totp.php';
date_default_timezone_set('UTC');

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
$jars = [];
try {
    $pdo = $harness->getPdo();
    $base = $harness->getBaseUrl();
    $secret = Totp::generateSecret();

    $pdo->exec("DELETE FROM users WHERE username IN ('sec_admin', 'sec_user')");
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json, timezone) VALUES (?, ?, 'admin', 1, '{}', 'UTC')")
        ->execute(['sec_admin', password_hash('AdminPass123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json, timezone) VALUES (?, ?, 'user', 1, ?, 'UTC')")
        ->execute(['sec_user', password_hash('UserPass123', PASSWORD_DEFAULT), json_encode(['campaigns' => ['access' => 'read']])]);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_retention_days', '256')");
    $pdo->exec("INSERT INTO pixel_profiles (traffic_source, niche, name, pixel_id, token, event_url, events, is_active)
                VALUES ('facebook', 'General', 'Shared', '111', 'ADMIN_TOKEN', '', 'Lead', 1)");
    $pixelId = (int) $pdo->lastInsertId();

    // $call: [code, json] with a per-name cookie jar and the session's CSRF token.
    $csrf = [];
    $call = static function (string $who, string $action, ?array $body = null) use ($base, &$jars, &$csrf): array {
        $jars[$who] = $jars[$who] ?? tempnam(sys_get_temp_dir(), 'secjar');
        $ch = curl_init($base . '/api.php?action=' . $action);
        $headers = ['Content-Type: application/json'];
        if (!empty($csrf[$who])) {
            $headers[] = 'X-CSRF-Token: ' . $csrf[$who];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $jars[$who],
            CURLOPT_COOKIEFILE => $jars[$who],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($raw, true);
        if ($action === 'login' && is_array($json) && !empty($json['data']['csrf_token'])) {
            $csrf[$who] = $json['data']['csrf_token'];
        }
        return [$code, is_array($json) ? $json : []];
    };
    $clearThrottle = static function () use ($pdo): void {
        $pdo->exec("DELETE FROM rate_limits");
    };

    // --- restricted user -----------------------------------------------------
    [$c] = $call('user', 'login', ['username' => 'sec_user', 'password' => 'UserPass123']);
    check($c === 200, 'user logs in');
    [$c, $j] = $call('user', 'global_settings', ['settings' => ['stats_retention_days' => '1', 'stats_enabled' => '0']]);
    check($c === 403, 'user cannot POST global_settings');
    check($pdo->query("SELECT value FROM settings WHERE key = 'stats_retention_days'")->fetchColumn() === '256', 'retention window unchanged');
    [$c, $j] = $call('user', 'global_settings');
    check($c === 200 && ($j['status'] ?? '') === 'success' && !array_key_exists('postback_key', $j['data'] ?? []),
        'user still reads global_settings without secrets');
    [$c] = $call('user', 'save_pixel_profile', ['id' => $pixelId, 'traffic_source' => 'facebook', 'name' => 'Shared', 'pixel_id' => '666']);
    check($c === 403, 'user cannot edit a shared pixel profile');
    [$c] = $call('user', 'save_pixel_profile', ['duplicate_from_id' => $pixelId]);
    check($c === 403, 'user cannot duplicate a pixel profile (token copy)');
    check($pdo->query("SELECT pixel_id FROM pixel_profiles WHERE id = $pixelId")->fetchColumn() === '111', 'shared profile unchanged');
    [$c, $j] = $call('user', 'save_pixel_profile', ['traffic_source' => 'facebook', 'name' => 'Mine', 'pixel_id' => '777', 'token' => 't']);
    check($c === 200 && ($j['status'] ?? '') === 'success', 'user can still create a new pixel profile');

    // --- admin ---------------------------------------------------------------
    $clearThrottle();
    [$c] = $call('admin', 'login', ['username' => 'sec_admin', 'password' => 'AdminPass123']);
    check($c === 200, 'admin logs in');
    [$c, $j] = $call('admin', 'global_settings', ['settings' => ['currency' => 'EUR']]);
    check($c === 200 && ($j['status'] ?? '') === 'success', 'admin can POST global_settings');
    [$c, $j] = $call('admin', 'save_pixel_profile', ['id' => $pixelId, 'traffic_source' => 'facebook', 'name' => 'Shared', 'pixel_id' => '112']);
    check($c === 200 && ($j['status'] ?? '') === 'success', 'admin can edit a pixel profile');

    // --- TOTP ----------------------------------------------------------------
    $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE username = 'sec_admin'")->execute([$secret]);
    [$c, $j] = $call('admin', 'totp_setup', []);
    check(($j['code'] ?? '') === 'already_enabled', 'totp_setup refuses while 2FA is enabled');
    check((int) $pdo->query("SELECT totp_enabled FROM users WHERE username = 'sec_admin'")->fetchColumn() === 1, '2FA still enabled after totp_setup');

    $clearThrottle();
    $codeNow = Totp::codeAtCounter($secret, intdiv(time(), 30));
    [$c, $j] = $call('fresh1', 'login', ['username' => 'sec_admin', 'password' => 'AdminPass123']);
    check($c === 401 && ($j['code'] ?? '') === 'totp_required', 'password alone -> totp_required');
    [$c] = $call('fresh1', 'login', ['username' => 'sec_admin', 'password' => 'AdminPass123', 'totp_code' => $codeNow]);
    check($c === 200, 'password + code -> signed in');
    [$c, $j] = $call('fresh2', 'login', ['username' => 'sec_admin', 'password' => 'AdminPass123', 'totp_code' => $codeNow]);
    check($c === 401 && ($j['code'] ?? '') === 'totp_invalid', 'the same code cannot be used twice');
    $pdo->exec("UPDATE users SET totp_enabled = 0 WHERE username = 'sec_admin'");

    // --- throttling ----------------------------------------------------------
    $clearThrottle();
    $ok = true;
    for ($i = 0; $i < 7; $i++) {
        [$c] = $call('loop', 'login', ['username' => 'sec_admin', 'password' => 'AdminPass123']);
        $ok = $ok && $c === 200;
    }
    check($ok, 'seven successful logins in a row are not throttled');
    $codes = [];
    for ($i = 0; $i < 6; $i++) {
        [$c] = $call('bad', 'login', ['username' => 'sec_admin', 'password' => 'wrong']);
        $codes[] = $c;
    }
    check(array_slice($codes, 0, 5) === [401, 401, 401, 401, 401] && $codes[5] === 429, 'five failures from one IP -> 429');
    $userFails = (int) $pdo->query("SELECT count FROM rate_limits WHERE key = 'loginfail:user:sec_admin'")->fetchColumn();
    check($userFails === 5, 'account counter holds the five failures');
    $pdo->exec("DELETE FROM rate_limits WHERE key LIKE 'loginfail:ip:%'"); // the owner, from another address
    [$c] = $call('owner', 'login', ['username' => 'sec_admin', 'password' => 'AdminPass123']);
    check($c === 200, 'one source cannot lock the account: owner still signs in');
    check((int) $pdo->query("SELECT COUNT(*) FROM rate_limits WHERE key = 'loginfail:user:sec_admin'")->fetchColumn() === 0,
        'a successful login clears the account counter');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
    foreach ($jars as $jar) {
        @unlink($jar);
    }
}

echo $failures === 0 ? "Security follow-up tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
