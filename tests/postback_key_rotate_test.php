<?php
/**
 * The postback key without the CLI: the wizard, the Postback page's button,
 * and what a hand-typed key may be.
 *
 * The key the repository ships with is public, so an install that keeps it
 * accepts forged conversions. install.sh replaces it; every other way an
 * install starts left it in place, and the panel could only tell the operator
 * to run a PHP script. Covered here:
 *
 *  - setup_first_user replaces the public key on its own, and the affiliate
 *    networks stored before that moment move to the new key;
 *  - a key that is not the public one is left alone by the wizard step;
 *  - rotate_postback_key: admin + POST only, a new 24-hex key, every stored
 *    network URL rewritten, look-alike keys untouched, the old route dead and
 *    the new one live; a stale current_key changes nothing;
 *  - postback_url no longer hands the key to a non-admin session;
 *  - a hand-typed key: empty or malformed is refused by both settings writers
 *    and writes none of the payload; an unchanged odd legacy key still saves;
 *  - cli/generate_postback_key.php mints the same key format and keeps an
 *    operator's key.
 *
 * Usage: php tests/postback_key_rotate_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/postback_key.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

check(orbitraPublicPostbackKey() === 'fd12e72', 'the public key is the one the repository ships with');
check(preg_match('/^[0-9a-f]{24}$/', orbitraGeneratePostbackKey()) === 1, 'a generated key is 24 hex characters');
check(orbitraGeneratePostbackKey() !== orbitraGeneratePostbackKey(), 'and different every time');

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
try {
    $seed = $harness->seedTestData();
    $clickId = $seed['click_id'];
    $pdo = $harness->getPdo();
    $setting = static function (string $key) use ($pdo): string {
        return (string) $pdo->query('SELECT value FROM settings WHERE key = ' . $pdo->quote($key))->fetchColumn();
    };
    $storedKey = static function () use ($setting): string {
        return $setting('postback_key');
    };
    $networkUrl = static function (string $name) use ($pdo): string {
        $stmt = $pdo->prepare('SELECT postback_url FROM affiliate_networks WHERE name = ?');
        $stmt->execute([$name]);
        return (string) $stmt->fetchColumn();
    };
    $postbackCode = static function (string $key, string $click) use ($harness): int {
        return $harness->get("/{$key}/postback?subid={$click}&status=lead&payout=1")['code'];
    };
    $conversionsOf = static function (string $click) use ($pdo): int {
        return (int) $pdo->query('SELECT COUNT(*) FROM conversions WHERE click_id = ' . $pdo->quote($click))->fetchColumn();
    };

    // --- the wizard: an install that starts here must not keep the public key
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'fd12e72')");
    $pdo->exec("INSERT INTO affiliate_networks (name, template, postback_url) VALUES
        ('Kept from import', 'generic', 'http://track.example/fd12e72/postback?subid={subid}&status={status}'),
        ('Look-alike key', 'generic', 'http://track.example/fd12e7X/postback?subid={subid}&status={status}')");
    $resp = $harness->postWithHeaders('/api.php?action=setup_first_user',
        json_encode(['username' => 'key_adm', 'password' => 'key_pass_12345', 'language' => 'en']),
        ['Content-Type: application/json']);
    check((json_decode($resp['body'], true)['status'] ?? '') === 'success', 'the first user is created');
    $wizardKey = $storedKey();
    check(preg_match('/^[0-9a-f]{24}$/', $wizardKey) === 1 && $wizardKey !== 'fd12e72', 'and the public key is replaced by a random one (' . $wizardKey . ')');
    check($networkUrl('Kept from import') === "http://track.example/{$wizardKey}/postback?subid={subid}&status={status}", 'a network stored before the wizard moved to the new key');
    check($networkUrl('Look-alike key') === 'http://track.example/fd12e7X/postback?subid={subid}&status={status}', 'a look-alike key is left alone');
    check($postbackCode('fd12e72', $clickId) === 404, 'the public address no longer accepts postbacks');
    check($postbackCode($wizardKey, $clickId) === 200 && $conversionsOf($clickId) === 1, 'the new address does');
    // The panel's catch-all used to answer such a request with 200: the network
    // marked the conversion delivered and never retried.
    $stale = $pdo->query("SELECT result, error, params_json FROM incoming_postbacks_log WHERE error = 'Invalid postback key'")->fetchAll(PDO::FETCH_ASSOC);
    check(count($stale) === 1 && $stale[0]['result'] === 'rejected' && str_contains((string) $stale[0]['params_json'], $clickId), 'and the Postbacks log names the reason (' . json_encode($stale) . ')');

    // The wizard step only ever replaces the public key: an operator's key stays.
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'operators_own_key')");
    $wizardAttempt = orbitraRotatePostbackKey($pdo, orbitraPublicPostbackKey());
    check($wizardAttempt['rotated'] === false && $storedKey() === 'operators_own_key', 'a key that is not the public one is kept');

    // --- admin session
    $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'key_adm', 'password' => 'key_pass_12345']), ['Content-Type: application/json']);
    $login = json_decode($resp['body'], true);
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
    $adminCookie = 'ORBITRASESSID=' . ($m[1] ?? '');
    $csrf = $login['data']['csrf_token'] ?? '';
    check(($login['status'] ?? '') === 'success' && $csrf !== '', 'admin session');
    $call = static function (string $method, string $action, array $payload, string $cookie, string $csrfToken, bool $withCsrf = true) use ($harness): array {
        $headers = ['Content-Type: application/json'];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if ($withCsrf) {
            $headers[] = 'X-CSRF-TOKEN: ' . $csrfToken;
        }
        $r = $method === 'GET'
            ? $harness->getWithHeaders("/api.php?action=$action", $headers)
            : $harness->postWithHeaders("/api.php?action=$action", json_encode($payload), $headers);
        return ['code' => $r['code'], 'body' => json_decode((string) $r['body'], true) ?? []];
    };

    // --- rotate_postback_key
    $before = $storedKey();
    $r = $call('GET', 'rotate_postback_key', [], $adminCookie, $csrf);
    check(($r['body']['message'] ?? '') === 'Method not allowed', 'GET is refused');
    check($storedKey() === $before, 'and changes nothing');

    $r = $call('POST', 'rotate_postback_key', ['current_key' => $before], $adminCookie, '', false);
    check(($r['body']['message'] ?? '') === 'CSRF token mismatch', 'a POST without the CSRF token is refused');
    check($storedKey() === $before, 'and changes nothing');

    $pdo->prepare("INSERT INTO affiliate_networks (name, template, postback_url) VALUES ('Live network', 'generic', ?)")
        ->execute(["http://track.example/{$before}/postback?subid={subid}"]);
    $r = $call('POST', 'rotate_postback_key', ['current_key' => $before], $adminCookie, $csrf);
    $newKey = (string) ($r['body']['data']['postback_key'] ?? '');
    check(($r['body']['status'] ?? '') === 'success' && preg_match('/^[0-9a-f]{24}$/', $newKey) === 1, 'the button creates a new 24-hex key (' . json_encode($r['body']) . ')');
    check((int) ($r['body']['data']['rewritten'] ?? -1) === 1, 'and reports how many network links it rewrote');
    check($storedKey() === $newKey, 'the new key is stored');
    check($networkUrl('Live network') === "http://track.example/{$newKey}/postback?subid={subid}", 'the stored network URL is on the new key');
    check($postbackCode($before, $clickId) === 404 && $postbackCode($newKey, $clickId) === 200, 'the old address is dead, the new one live');

    $r = $call('POST', 'rotate_postback_key', ['current_key' => $before], $adminCookie, $csrf);
    check(($r['body']['code'] ?? '') === 'postback_key_changed' && $storedKey() === $newKey, 'a page still showing the previous key is told the key changed, and nothing rotates twice');
    $r = $call('POST', 'rotate_postback_key', [], $adminCookie, $csrf);
    check(($r['body']['status'] ?? '') === 'success' && $storedKey() !== $newKey, 'without current_key the rotation is unguarded');
    $current = $storedKey();

    $r = $call('GET', 'postback_url', [], $adminCookie, $csrf);
    check(($r['body']['data']['postback_url'] ?? '') === $harness->getBaseUrl() . "/{$current}/postback", 'postback_url shows the current address (' . json_encode($r['body']['data'] ?? null) . ')');

    // --- a non-admin session: neither the key nor the rotation
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'user', 1, '{}')")
        ->execute(['key_usr', password_hash('key_pass_67890', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'key_usr', 'password' => 'key_pass_67890']), ['Content-Type: application/json']);
    $userLogin = json_decode($resp['body'], true);
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $um);
    $userCookie = 'ORBITRASESSID=' . ($um[1] ?? '');
    check(($userLogin['status'] ?? '') === 'success', 'a non-admin session');
    $r = $call('GET', 'postback_url', [], $userCookie, $userLogin['data']['csrf_token'] ?? '');
    check(($r['body']['message'] ?? '') === 'Forbidden' && strpos($r['body']['data']['postback_url'] ?? '', $current) === false, 'postback_url does not hand the key to a non-admin');
    $r = $call('POST', 'rotate_postback_key', ['current_key' => $current], $userCookie, $userLogin['data']['csrf_token'] ?? '');
    check(($r['body']['message'] ?? '') === 'Forbidden' && $storedKey() === $current, 'a non-admin cannot rotate the key');

    // --- a hand-typed key
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'operators_own_key'), ('currency', 'USD'), ('ignore_prefetch', '1')");
    foreach (['', 'short', 'has space', 'a/b', 'ключ'] as $bad) {
        $r = $call('POST', 'save_settings', ['postback_key' => $bad, 'currency' => 'EUR'], $adminCookie, $csrf);
        check(($r['body']['code'] ?? '') === 'postback_key_invalid' && $storedKey() === 'operators_own_key', 'save_settings refuses the key ' . json_encode($bad));
    }
    check($setting('currency') === 'USD', 'and a refused key writes none of the payload');
    $r = $call('POST', 'save_settings', ['postback_key' => '  my-own-key-42  '], $adminCookie, $csrf);
    check(($r['body']['status'] ?? '') === 'success' && $storedKey() === 'my-own-key-42', 'a proper hand-typed key is saved, trimmed');
    $r = $call('POST', 'save_settings', ['postback_key' => 'my-own-key-42', 'currency' => 'EUR'], $adminCookie, $csrf);
    check(($r['body']['status'] ?? '') === 'success' && $setting('currency') === 'EUR', 'the same key posted again is not a change');

    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'legacy odd!key')");
    $r = $call('POST', 'save_settings', ['postback_key' => 'legacy odd!key', 'currency' => 'USD'], $adminCookie, $csrf);
    check(($r['body']['status'] ?? '') === 'success' && $setting('currency') === 'USD', 'an odd legacy key that is not being changed does not block the save');

    $r = $call('POST', 'global_settings', ['settings' => ['postback_key' => '', 'ignore_prefetch' => '0']], $adminCookie, $csrf);
    check(($r['body']['code'] ?? '') === 'postback_key_invalid' && $setting('ignore_prefetch') === '1', 'global_settings refuses an empty key and writes none of the payload');
    $r = $call('POST', 'global_settings', ['settings' => ['postback_key' => 'panel-typed-key']], $adminCookie, $csrf);
    check(($r['body']['status'] ?? '') === 'success' && $storedKey() === 'panel-typed-key', 'and saves a proper one');
    $r = $call('POST', 'global_settings', ['settings' => ['postback_key' => 'legacy odd!key', 'ignore_prefetch' => '0']], $adminCookie, $csrf);
    check(($r['body']['code'] ?? '') === 'postback_key_invalid', 'a change back to a malformed value is refused');

    // --- the installer's script: the same key format, an operator's key kept
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/generate_postback_key.php', $work . '/cli/generate_postback_key.php');
    $cliErr = $work . '/key_cli_stderr.log';
    $cli = static function () use ($work, $cliErr): array {
        @unlink($cliErr);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/generate_postback_key.php') . ' 2>' . escapeshellarg($cliErr), $out, $code);
        return ['code' => $code, 'stdout' => implode('', $out), 'stderr' => (string) @file_get_contents($cliErr)];
    };
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'fd12e72')");
    $r = $cli();
    check($r['code'] === 0 && preg_match('/^[0-9a-f]{24}$/', $r['stdout']) === 1 && $storedKey() === $r['stdout'], 'the installer script replaces the public key and prints the new one (' . json_encode($r) . ')');
    $installerKey = $storedKey();
    $r = $cli();
    check($r['code'] === 0 && $r['stdout'] === '' && str_contains($r['stderr'], 'left untouched') && $storedKey() === $installerKey, 'a second run keeps the key and prints nothing');
    check($postbackCode("{$installerKey}/", $clickId) === 200, 'a trailing slash on the postback path is accepted (router.php)');
    check($postbackCode($installerKey, 'no-such-click') === 404, 'the current key with an unknown click is the postback handler\'s own 404');
} finally {
    $harness->stop();
}

// --- the other front controller: nginx hands every path to index.php
$index = new OrbitraTestHarness(dirname(__DIR__));
$index->start();
try {
    $seed = $index->seedTestData();
    $indexPdo = $index->getPdo();
    $indexPdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'indexkey12345')");
    $r = $index->get('/stalekey12345/postback?subid=' . $seed['click_id'] . '&status=lead&payout=1');
    $logged = (int) $indexPdo->query("SELECT COUNT(*) FROM incoming_postbacks_log WHERE result = 'rejected' AND error = 'Invalid postback key'")->fetchColumn();
    check($r['code'] === 404 && trim($r['body']) === 'Invalid postback key', 'index.php answers a stale key with a plain 404 (got ' . $r['code'] . ' ' . substr(trim(strip_tags($r['body'])), 0, 60) . ')');
    check($logged === 1 && (int) $indexPdo->query('SELECT COUNT(*) FROM conversions')->fetchColumn() === 0, 'and logs it as rejected, with nothing recorded');
    $r = $index->get('/indexkey12345/postback/?subid=' . $seed['click_id'] . '&status=lead&payout=1');
    check($r['code'] === 200 && (int) $indexPdo->query('SELECT COUNT(*) FROM conversions')->fetchColumn() === 1, 'the current key, trailing slash included, still records');
} finally {
    $index->stop();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
