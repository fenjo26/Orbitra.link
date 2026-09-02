<?php
// tests/domain_root_campaign_test.php
//
// The domain-overrides block at the top of index.php, which in production is
// the ONLY router a request meets (nginx hands "/" and every unknown path to
// this file; router.php only does that job for the php -S dev server):
//   - domains.index_campaign_id — "Campaign to serve on the root path" in the
//     domain UI — now resolves here. It used to live only in router.php, so a
//     parked domain's root answered "Campaign not specified." in production
//     while the same install served the campaign fine under the dev server
//     (GitHub issue #4).
//   - catch_404 extends the same campaign to the host's dead paths (unknown
//     single-segment aliases and deeper paths), mirroring router.php.
//   - A domain with status=Disabled 404s the whole host before any routing —
//     the verdict router.php gives on the dev server.
//   - An explicit ?campaign_id= in the URL still wins over the domain setting
//     (the operator's own link, and the workaround from the bug report), and
//     the routing id is written to $directCampaignId only — never into $_GET —
//     so it cannot leak into the click's captured ad parameters.
//
// Two layers:
//   1. The block extracted from index.php by its comment anchors and run in
//      child processes — precise over every branch without booting a server.
//   2. The real index.php behind OrbitraTestHarness (php -S + sandbox DB) —
//      the bug report's actual reproduction, against the migrated schema.
//
// Run: php tests/domain_root_campaign_test.php

$repoRoot = dirname(__DIR__);

// ------------------------------------------------------------ child scenarios
if (getenv('ORBITRA_TEST_SPEC') !== false) {
    [$scenario, $domainJson] = explode('|', getenv('ORBITRA_TEST_SPEC'), 2);
    $domain = json_decode($domainJson, true);

    foreach (json_decode((string) getenv('ORBITRA_TEST_SERVER'), true) ?: [] as $k => $v) {
        $_SERVER[$k] = $v;
    }
    foreach (json_decode((string) getenv('ORBITRA_TEST_GET'), true) ?: [] as $k => $v) {
        $_GET[$k] = $v;
    }

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE domains (
        id INTEGER PRIMARY KEY,
        name TEXT,
        is_noindex INTEGER DEFAULT 0,
        https_only INTEGER DEFAULT 0,
        index_campaign_id INTEGER,
        catch_404 INTEGER DEFAULT 0,
        pwa_landing_id INTEGER,
        pwa_offer_id INTEGER,
        status TEXT DEFAULT "OK"
    )');
    $pdo->prepare('INSERT INTO domains (name, is_noindex, https_only, index_campaign_id, catch_404, status) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $domain['name'],
            $domain['is_noindex'] ?? 0,
            $domain['https_only'] ?? 0,
            $domain['index_campaign_id'] ?? null,
            $domain['catch_404'] ?? 0,
            $domain['status'] ?? 'OK',
        ]);

    // The routing preamble index.php runs before the block, verbatim.
    $alias = $_GET['campaign'] ?? '';
    $directCampaignId = $_GET['campaign_id'] ?? null;
    $fallbackCampaignId = $_GET['fallback_campaign_id'] ?? null;
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';

    /** Extract the domain-overrides block from index.php by its comment anchors. */
    $extractBlock = static function () use ($repoRoot): string {
        $src = file_get_contents($repoRoot . '/index.php');
        $start = strpos($src, '// === DOMAIN OVERRIDES & SECURITY ===');
        $end = strpos($src, "// ===================================\n", $start);
        if ($start === false || $end === false || $end <= $start) {
            fwrite(STDERR, "could not locate the domain-overrides block in index.php\n");
            exit(1);
        }
        return substr($src, $start, $end - $start + strlen('// ==================================='));
    };

    eval($extractBlock());

    // The block exited the process for 404/redirect scenarios; reaching this
    // line means it passed through.
    echo 'DIRECT=' . ($directCampaignId ?? 'NOT_SET');
    exit(0);
}

// ------------------------------------------------------------------- parent
$failures = [];
$passed = 0;
$check = static function (string $name, string $needle, string $output) use (&$failures, &$passed): void {
    if (preg_match('/Fatal error|PHP Parse error|PHP Warning|PHP Notice/', $output)) {
        $failures[] = "$name: child crashed: " . trim($output);
        return;
    }
    if (strpos($output, $needle) === false) {
        $failures[] = "$name: expected " . var_export($needle, true) . ' in: ' . trim($output);
    } else {
        $passed++;
        echo "ok  $name\n";
    }
};
$run = static function (string $scenario, array $domain, array $server = [], array $get = []): string {
    // shell_exec() has no env argument, so the state rides on env-style
    // prefixes; escapeshellarg() makes the JSON payloads shell-safe.
    $prefix = 'ORBITRA_TEST_SPEC=' . escapeshellarg($scenario . '|' . json_encode($domain))
        . ' ORBITRA_TEST_SERVER=' . escapeshellarg(json_encode($server))
        . ' ORBITRA_TEST_GET=' . escapeshellarg(json_encode($get));
    return (string) shell_exec($prefix . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1');
};

$domain = ['name' => 'parked.test', 'index_campaign_id' => 42];

// --- The reported bug: the parked root serves the domain's campaign.
$check('parked root serves index_campaign_id (was "Campaign not specified.")',
    'DIRECT=42',
    $run('root', $domain, ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/']));

$check('root without index_campaign_id still falls through unchanged',
    'DIRECT=NOT_SET',
    $run('root-no-index', ['name' => 'parked.test'], ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/']));

$check('an unparked host is untouched',
    'DIRECT=NOT_SET',
    $run('unparked', $domain, ['HTTP_HOST' => 'elsewhere.test', 'REQUEST_URI' => '/']));

// --- Explicit campaign id keeps winning (the bug report's workaround).
$check('explicit ?campaign_id= beats the domain setting',
    'DIRECT=9',
    $run('explicit', $domain, ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/?campaign_id=9'], ['campaign_id' => '9']));

$check('an empty ?campaign_id= counts as absent',
    'DIRECT=42',
    $run('explicit-empty', $domain, ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/?campaign_id='], ['campaign_id' => '']));

// --- catch_404 extends the campaign to the host's dead paths.
$catch = ['name' => 'parked.test', 'index_campaign_id' => 42, 'catch_404' => 1];
$check('catch_404 covers an unknown single-segment path',
    'DIRECT=42',
    $run('catch-alias', $catch, ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/no-such-alias']));
$check('catch_404 covers deeper paths',
    'DIRECT=42',
    $run('catch-deep', $catch, ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/a/b/c']));
$check('without catch_404 a subpath gets no campaign',
    'DIRECT=NOT_SET',
    $run('no-catch', $domain, ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/no-such-alias']));

// --- Host:port still matches the domain row (port stripped before lookup).
$check('a host with a port still resolves the domain',
    'DIRECT=42',
    $run('port', $domain, ['HTTP_HOST' => 'parked.test:8080', 'REQUEST_URI' => '/']));

// --- Disabled domain: whole host 404 before any routing (router.php parity).
$check('a Disabled domain 404s the whole host',
    '404',
    $run('disabled', ['name' => 'parked.test', 'status' => 'Disabled', 'index_campaign_id' => 42],
        ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/']));

// --- The pre-existing branches still behave with the new code among them.
$check('is_noindex robots.txt interception still works',
    'Disallow: /',
    $run('noindex-robots', ['name' => 'parked.test', 'is_noindex' => 1],
        ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/robots.txt']));

// --- Statuses other than Disabled never trigger the host 404.
foreach (['OK', 'Active', ''] as $status) {
    $check("status " . var_export($status, true) . " keeps serving",
        'DIRECT=42',
        $run('status-ok', ['name' => 'parked.test', 'status' => $status, 'index_campaign_id' => 42],
            ['HTTP_HOST' => 'parked.test', 'REQUEST_URI' => '/']));
}

// ------------------------------------------------------- end-to-end over HTTP
// The bug report's reproduction, against the real migrated schema: install on
// nginx (here: index.php as the router script, the way the harness's docroot
// plays it), park a domain, give it an index campaign, visit the root.
// https_only needs a real response to assert its 301, which CLI header() calls
// cannot produce — that is why it lives here and not in the child suite above.
require_once __DIR__ . '/lib/http.php';

$harness = new OrbitraTestHarness($repoRoot);
$harness->start();

/**
 * GET without following redirects: the offers here point at unreachable
 * example hosts on purpose, and the thing under test is the FIRST response —
 * its status line and Location. The harness's get() follows and dies.
 */
$getNoFollow = static function (string $path) use ($harness): array {
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
    ]]);
    $body = @file_get_contents($harness->getBaseUrl() . $path, false, $ctx);
    $headers = $http_response_header ?? [];
    $code = 0;
    $flat = [];
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
            $code = (int) $m[1];
        }
        if (strpos($h, ':') !== false) {
            [$k, $v] = explode(':', $h, 2);
            $flat[trim($k)] = trim($v);
        }
    }
    return ['code' => $code, 'body' => (string) $body, 'headers' => $flat];
};

try {
    $pdo = $harness->getPdo();
    $host = parse_url($harness->getBaseUrl(), PHP_URL_HOST); // 127.0.0.1 — the row the lookup must find

    $seed = static function (int $cid, string $alias, int $oid, string $offerUrl, int $sid) use ($pdo): void {
        $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, ?, ?, ?, 'active', 0)")
            ->execute([$cid, "Camp $cid", $alias, "tok$cid"]);
        $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, ?, ?, 0, 'active', 0)")
            ->execute([$oid, "Offer $oid", $offerUrl]);
        $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks) VALUES (?, ?, ?, ?, 'regular', 1, 'redirect', ?, 1, 1)")
            ->execute([$sid, $cid, $oid, "Stream $sid", json_encode(['offers' => [['id' => $oid, 'weight' => 100]]])]);
    };
    $seed(42, 'rootcamp', 4200, 'https://offers.example.com/root-landing', 420);
    $seed(9, 'othercamp', 900, 'https://offers.example.com/other-landing', 90);
    foreach ([['postback_key', 'testkey42'], ['ignore_prefetch', '0'], ['stats_enabled', '1']] as [$k, $v]) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]);
    }
    $pdo->prepare("INSERT INTO domains (name, index_campaign_id, catch_404, status) VALUES (?, 42, 0, 'OK')")
        ->execute([$host]);

    $assert = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$passed): void {
        if ($ok) {
            $passed++;
            echo "ok  $name\n";
        } else {
            $failures[] = $name . ($detail !== '' ? ": $detail" : '');
        }
    };

    // THE bug: the root of a parked domain serves its campaign, not
    // "Campaign not specified."
    $resp = $getNoFollow('/');
    $assert('http: parked root redirects to the index campaign offer',
        $resp['code'] === 302 && strpos($resp['headers']['Location'] ?? '', 'offers.example.com/root-landing') !== false,
        "code={$resp['code']} body=" . substr($resp['body'], 0, 120));

    // The workaround keeps working: an explicit campaign id in the URL wins.
    $resp = $getNoFollow('/?campaign_id=9');
    $assert('http: explicit ?campaign_id= still wins over the domain setting',
        $resp['code'] === 302 && strpos($resp['headers']['Location'] ?? '', 'offers.example.com/other-landing') !== false,
        "code={$resp['code']}");

    // Valid aliases keep winning over catch_404.
    $resp = $getNoFollow('/rootcamp');
    $assert('http: a live alias still routes to its own campaign',
        $resp['code'] === 302 && strpos($resp['headers']['Location'] ?? '', 'offers.example.com/root-landing') !== false,
        "code={$resp['code']}");

    // Without catch_404 a dead path is not hijacked into the campaign.
    $resp = $getNoFollow('/no-such-alias');
    $assert('http: without catch_404 a dead path does not serve the domain campaign',
        $resp['code'] !== 302 || strpos($resp['headers']['Location'] ?? '', 'offers.example.com') === false,
        "code={$resp['code']}");

    // With catch_404 the domain campaign takes the dead paths (router parity).
    $pdo->prepare("UPDATE domains SET catch_404 = 1 WHERE name = ?")->execute([$host]);
    $resp = $getNoFollow('/no-such-alias');
    $assert('http: catch_404 sends a dead path to the domain campaign',
        $resp['code'] === 302 && strpos($resp['headers']['Location'] ?? '', 'offers.example.com/root-landing') !== false,
        "code={$resp['code']}");

    // A Disabled domain serves nothing at all.
    $pdo->prepare("UPDATE domains SET status = 'Disabled' WHERE name = ?")->execute([$host]);
    $resp = $getNoFollow('/no-such-alias');
    $assert('http: a Disabled domain 404s the whole host',
        $resp['code'] === 404 && strpos($resp['body'], '404') !== false,
        "code={$resp['code']}");

    // https_only: 301 to the https equivalent before any routing happens.
    $pdo->prepare("UPDATE domains SET status = 'OK', https_only = 1 WHERE name = ?")->execute([$host]);
    $resp = $getNoFollow('/?campaign_id=9');
    $assert('http: https_only 301s to the https equivalent',
        $resp['code'] === 301 && ($resp['headers']['Location'] ?? '') === 'https://127.0.0.1:' . parse_url($harness->getBaseUrl(), PHP_URL_PORT) . '/?campaign_id=9',
        "code={$resp['code']} loc=" . ($resp['headers']['Location'] ?? ''));
} finally {
    $harness->stop();
}

if ($failures) {
    echo "DOMAIN ROOT CAMPAIGN TEST FAILED\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "DOMAIN ROOT CAMPAIGN TEST PASSED ({$passed} checks)\n";
