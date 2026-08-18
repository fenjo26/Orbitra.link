<?php
/**
 * tests/oauth_preflight_test.php
 *
 * Covers the 1-Click preflight endpoints (facebook/tiktok/google_ads
 * _oauth_status): the credential helpers they call — extracted from the real
 * api.php, not copied — and the wiring of the three case blocks themselves.
 *
 * The duplicate-case regression matters here: a parallel edit once added a
 * second google_ads_oauth_status case (dead code, first match wins), so the
 * test pins each case to exactly one occurrence.
 *
 * Run from the project root:
 *
 *     php tests/oauth_preflight_test.php
 *
 * No network: only env var / settings / connection-row precedence is tested.
 */

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** Extract a full function definition from a PHP source string by brace matching. */
function extractFunction(string $source, string $name): string
{
    $start = strpos($source, "function $name(");
    if ($start === false) {
        throw new RuntimeException("function $name() not found in source");
    }
    $braceStart = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $braceStart, $len = strlen($source); $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("unbalanced braces in $name()");
}

$apiSource = file_get_contents(__DIR__ . '/../api.php');
if ($apiSource === false) {
    echo "FAIL: api.php is unreadable\n";
    exit(1);
}

eval(extractFunction($apiSource, 'orbitraTikTokOAuthCredentials'));
eval(extractFunction($apiSource, 'orbitraGoogleAdsOAuthCredentials'));

function freshPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
    $pdo->exec('CREATE TABLE aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        engine TEXT,
        credentials_json TEXT
    )');
    return $pdo;
}

// Force a clean environment so a developer VPS with real env vars does not
// flip the "nothing configured" expectations; restored on shutdown.
$envKeys = [
    'ORBITRA_TIKTOK_APP_ID', 'ORBITRA_TIKTOK_APP_SECRET',
    'ORBITRA_GOOGLE_CLIENT_ID', 'ORBITRA_GOOGLE_CLIENT_SECRET', 'ORBITRA_GOOGLE_DEVELOPER_TOKEN',
];
$envBackup = [];
foreach ($envKeys as $key) {
    $envBackup[$key] = getenv($key);
    putenv("$key=");
}
register_shutdown_function(static function () use ($envBackup): void {
    foreach ($envBackup as $key => $value) {
        putenv($value === false ? "$key=" : "$key=$value");
    }
});

$ttConfigured = static function (array $creds): bool {
    return $creds['app_id'] !== '' && $creds['app_secret'] !== '';
};
$gaConfigured = static function (array $creds): bool {
    return $creds['client_id'] !== '' && $creds['client_secret'] !== '' && $creds['developer_token'] !== '';
};

// ---- Nothing stored → both preflights report not configured ----------------

$pdo = freshPdo();
check('tiktok: empty install is not configured', !$ttConfigured(orbitraTikTokOAuthCredentials($pdo)));
check('google: empty install is not configured', !$gaConfigured(orbitraGoogleAdsOAuthCredentials($pdo)));

// ---- Settings rows bootstrap the preflight ---------------------------------

$pdo = freshPdo();
$stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
$stmt->execute(['tiktok_app_id', '7001234567890']);
$stmt->execute(['tiktok_app_secret', 'sekret']);
check('tiktok: settings rows make 1-Click configured', $ttConfigured(orbitraTikTokOAuthCredentials($pdo)));

// A secret without an app id must stay unconfigured.
$pdo = freshPdo();
$pdo->exec("INSERT INTO settings (key, value) VALUES ('tiktok_app_secret', 'sekret')");
check('tiktok: secret without app id is not configured', !$ttConfigured(orbitraTikTokOAuthCredentials($pdo)));

// ---- A manual connection's app credentials are reused ----------------------

$pdo = freshPdo();
$stmt = $pdo->prepare("INSERT INTO aggregator_connections (engine, credentials_json) VALUES ('tiktok', ?)");
$stmt->execute([json_encode(['access_token' => 'PLAIN', 'advertiser_id' => '1', 'app_id' => '7001234567890', 'app_secret' => 'sekret'])]);
check('tiktok: manual connection app_id/secret enable 1-Click', $ttConfigured(orbitraTikTokOAuthCredentials($pdo)));

// TikTok app ids are digits-only; a junk id is dropped → not configured.
$pdo = freshPdo();
$stmt = $pdo->prepare("INSERT INTO aggregator_connections (engine, credentials_json) VALUES ('tiktok', ?)");
$stmt->execute([json_encode(['app_id' => 'my-app', 'app_secret' => 'sekret'])]);
check('tiktok: non-numeric app id is rejected', !$ttConfigured(orbitraTikTokOAuthCredentials($pdo)));

// ---- Google: env vars, then manual-connection fallback ---------------------

$pdo = freshPdo();
putenv('ORBITRA_GOOGLE_CLIENT_ID=cid-1');
putenv('ORBITRA_GOOGLE_CLIENT_SECRET=csecret-1');
putenv('ORBITRA_GOOGLE_DEVELOPER_TOKEN=devtok-1');
check('google: env vars make 1-Click configured', $gaConfigured(orbitraGoogleAdsOAuthCredentials($pdo)));

// Missing one of three env vars drops back to not configured.
putenv('ORBITRA_GOOGLE_DEVELOPER_TOKEN=');
$pdo = freshPdo();
$stmt = $pdo->prepare("INSERT INTO aggregator_connections (engine, credentials_json) VALUES ('google_ads', ?)");
$stmt->execute([json_encode(['developer_token' => 'devtok-2'])]);
$creds = orbitraGoogleAdsOAuthCredentials($pdo);
check('google: partial env + connection row fills the gap (hybrid configured)', $gaConfigured($creds));
check('google: connection row did not override the env client id', $creds['client_id'] === 'cid-1');
putenv('ORBITRA_GOOGLE_CLIENT_ID=');
putenv('ORBITRA_GOOGLE_CLIENT_SECRET=');

$pdo = freshPdo();
$stmt = $pdo->prepare("INSERT INTO aggregator_connections (engine, credentials_json) VALUES ('google_ads', ?)");
$stmt->execute([json_encode(['client_id' => 'cid-3', 'client_secret' => 'cs-3', 'developer_token' => 'dt-3', 'refresh_token' => 'rt'])]);
check('google: manual connection alone enables 1-Click', $gaConfigured(orbitraGoogleAdsOAuthCredentials($pdo)));

// ---- The three preflight endpoints exist exactly once ----------------------

foreach (['facebook_oauth_status', 'tiktok_oauth_status', 'google_ads_oauth_status'] as $action) {
    $count = substr_count($apiSource, "case '$action':");
    check("api.php wires case '$action' exactly once", $count === 1, "found $count occurrences");
}

echo PHP_EOL . ($failed === 0 ? "PASSED: $passed checks" : "FAILED: $failed of " . ($passed + $failed)) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
