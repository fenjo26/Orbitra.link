<?php
// tests/pwa_domain_options_test.php
//
// The domain picker in the PWA editor (migration 47, "domain root = PWA store")
// came up empty for every operator who had already saved the PWA once.
//
// `pwa_domain_options` used to treat `landing_id` as a FILTER — "give me the
// domains already bound to this landing". The editor passes the landing it is
// editing, so the moment the first save assigned an id, the endpoint answered
// with the domains bound to a landing that was bound to nothing: an empty list,
// a select holding only "— not bound —", and no way to ever bind a domain. The
// client does not need the filter: every row carries `pwa_landing_id`, and the
// editor finds its own binding by matching that column itself.
//
// So: the list must be the same whatever `landing_id` says, and it must keep
// carrying the binding columns. Disabled domains stay excluded (they serve 404).
//
// Run: php tests/pwa_domain_options_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}

$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['pwa_domains_admin', password_hash('pass123', PASSWORD_DEFAULT)]);

    // Two PWA landings, so "bound to someone else" is a real state here.
    $pdo->exec("INSERT INTO landings (id, name, type, url, slug) VALUES (41, 'PWA A', 'pwa', '', 'pwa-a')");
    $pdo->exec("INSERT INTO landings (id, name, type, url, slug) VALUES (42, 'PWA B', 'pwa', '', 'pwa-b')");

    $addDomain = static function (string $name, ?int $landingId, string $status) use ($pdo): void {
        $pdo->prepare("INSERT INTO domains (name, status, pwa_landing_id) VALUES (?, ?, ?)")
            ->execute([$name, $status, $landingId]);
    };
    $addDomain('bound-to-a.example', 41, 'OK');
    $addDomain('bound-to-b.example', 42, 'OK');
    $addDomain('free.example', null, 'OK');
    $addDomain('switched-off.example', null, 'Disabled');

    try { $pdo->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
    $loginResp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'pwa_domains_admin', 'password' => 'pass123']), ['Content-Type: application/json']);
    if ((json_decode($loginResp['body'], true)['status'] ?? '') !== 'success') {
        fwrite(STDERR, "login failed: " . $loginResp['body'] . "\n");
        exit(1);
    }
    preg_match('/ORBITRASESSID=([^;]+)/', $loginResp['headers']['Set-Cookie'] ?? '', $m);
    $cookie = 'ORBITRASESSID=' . ($m[1] ?? '');

    /** @return array<int, array<string, mixed>> */
    $options = static function (?int $landingId) use ($harness, $cookie): array {
        $path = '/api.php?action=pwa_domain_options' . ($landingId === null ? '' : '&landing_id=' . $landingId);
        $resp = $harness->getWithHeaders($path, ['Cookie: ' . $cookie]);
        $body = json_decode($resp['body'], true);
        if (($body['status'] ?? '') !== 'success') {
            fwrite(STDERR, "pwa_domain_options failed: " . $resp['body'] . "\n");
            exit(1);
        }
        return $body['data']['domains'] ?? [];
    };
    $names = static function (array $rows): array {
        $out = array_map(static fn(array $r): string => (string) $r['name'], $rows);
        sort($out);
        return $out;
    };

    $expected = ['bound-to-a.example', 'bound-to-b.example', 'free.example'];

    assertTrue($names($options(null)) === $expected, 'without landing_id: every enabled domain is offered');
    assertTrue($names($options(0)) === $expected, 'landing_id=0 (a PWA not saved yet): the same full list');

    // The regression: an editor asking about the landing it is editing.
    assertTrue($names($options(41)) === $expected, 'landing_id of a PWA that already owns a domain: still the full list');
    assertTrue($names($options(42)) === $expected, 'landing_id of a PWA bound elsewhere: still the full list');
    assertTrue($names($options(999)) === $expected, 'landing_id of a freshly saved PWA bound to nothing: still the full list (the reported bug)');

    // The columns the client needs to mark its own binding and prefill the offer.
    $rows = $options(41);
    $byName = [];
    foreach ($rows as $row) {
        $byName[$row['name']] = $row;
    }
    assertTrue(array_key_exists('pwa_landing_id', $byName['bound-to-a.example']), 'rows carry pwa_landing_id');
    assertTrue(array_key_exists('pwa_offer_id', $byName['bound-to-a.example']), 'rows carry pwa_offer_id');
    assertTrue((int) $byName['bound-to-a.example']['pwa_landing_id'] === 41, 'the client can find its own binding by pwa_landing_id');
    assertTrue(($byName['free.example']['pwa_landing_id'] ?? null) === null, 'an unbound domain reports a null binding');

    assertTrue(!array_key_exists('switched-off.example', $byName), 'a Disabled domain is never offered');
} finally {
    $harness->stop();
}

echo "\n";
if (!$testPassed) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
