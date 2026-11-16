<?php
// tests/check_update_commit_test.php
//
// check_update reports the local git HEAD (current_commit) alongside
// current_version — both are local facts answered fresh even from the
// one-hour cache. The Update page needs it to confirm an update that landed
// behind a proxy timeout (Cloudflare 524) when the push carried no version
// bump: the version is identical before and after, the commit is not.
//
// The harness sandbox has no .git, so current_commit must be null there and
// must stay present (not vanish) in the cached answer. When git is available
// a throwaway repository is created in the sandbox to prove the probe really
// reads HEAD.
//
// Run: php tests/check_update_commit_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';
require_once $repoRoot . '/version.php';

$failures = [];
$checks = 0;
$check = static function (string $name, $expected, $actual) use (&$failures, &$checks) {
    $checks++;
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['admin', password_hash('pass123', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders(
        '/api.php?action=login',
        json_encode(['username' => 'admin', 'password' => 'pass123']),
        ['Content-Type: application/json']
    );
    $body = json_decode($resp['body'], true);
    $check('login', 'success', $body['status'] ?? '');
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
    $cookie = 'Cookie: ORBITRASESSID=' . $m[1];

    // `$x ?? 'missing'` swallows a present null (isset semantics), and a
    // present null is exactly the expected value here — read the key by hand.
    $commitOf = static function (array $data) {
        return array_key_exists('current_commit', $data) ? $data['current_commit'] : 'missing';
    };

    // --- live answer: no .git in the sandbox → null, never a garbage string --
    $live = json_decode($harness->getWithHeaders('/api.php?action=check_update', [$cookie])['body'], true);
    $check('live status', 'success', $live['status'] ?? '');
    $check('live current_version', ORBITRA_VERSION, $live['data']['current_version'] ?? null);
    $check('live current_commit is null without .git', null, $commitOf($live['data'] ?? []));

    // --- cached answer: the local facts stay fresh, the commit included ------
    $cached = json_decode($harness->getWithHeaders('/api.php?action=check_update', [$cookie])['body'], true);
    $check('cached flag', true, $cached['data']['cached'] ?? false);
    $check('cached current_version', ORBITRA_VERSION, $cached['data']['current_version'] ?? null);
    $check('cached current_commit is null without .git', null, $commitOf($cached['data'] ?? []));

    // --- with a real repository in the sandbox, the probe reads HEAD ---------
    $work = $harness->getWorkingDir();
    $canGit = false;
    if (is_string($work) && function_exists('exec')) {
        exec('git -C ' . escapeshellarg($work) . ' init -q 2>/dev/null', $o, $rc1);
        exec('git -C ' . escapeshellarg($work)
            . ' -c user.email=test@test -c user.name=test commit -q --allow-empty -m x 2>/dev/null', $o, $rc2);
        $canGit = ($rc1 === 0 && $rc2 === 0);
    }
    if ($canGit) {
        $withGit = json_decode($harness->getWithHeaders('/api.php?action=check_update&force=1', [$cookie])['body'], true);
        $commit = $commitOf($withGit['data'] ?? []);
        $check('current_commit present with a repo', true, is_string($commit) && (bool) preg_match('/^[0-9a-f]{7,40}$/', $commit));
    }
} finally {
    $harness->stop();
}

if ($failures) {
    fwrite(STDERR, "check_update_commit_test: FAILURES:\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "check_update_commit_test: $checks checks, 0 failures\n";
exit(0);
