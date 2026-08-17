<?php
/**
 * tests/tiktok_oauth_test.php
 *
 * Covers the OAuth bookkeeping the 1-click TikTok integration relies on:
 * TikTokAdsEngine::ensureFreshToken() no-op paths, its throw-on-dead-token
 * contract, and — through the test refresh seam — that a refresh propagates
 * the new access token to every sibling connection of the same login and to
 * the pixel profiles / campaign pixels that imported it.
 *
 * Run from the project root:
 *
 *     php tests/tiktok_oauth_test.php
 *
 * No network: the /oauth2/refresh_token/ call is replaced by a handler.
 */

require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';

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

function freshPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, engine TEXT, auth_type TEXT, credentials_json TEXT,
        sync_interval_hours INTEGER DEFAULT 2, is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE pixel_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        traffic_source TEXT, niche TEXT, name TEXT, pixel_id TEXT,
        token TEXT, is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE campaign_pixels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER, pixel_profile_id INTEGER, type TEXT,
        pixel_id TEXT, token TEXT, is_active INTEGER DEFAULT 1
    )");
    return $pdo;
}

function insertConnection(PDO $pdo, string $engine, array $credentials): int
{
    $stmt = $pdo->prepare("INSERT INTO aggregator_connections (name, engine, auth_type, credentials_json) VALUES (?, ?, 'oauth', ?)");
    $stmt->execute(['conn', $engine, json_encode($credentials, JSON_UNESCAPED_SLASHES)]);
    return (int) $pdo->lastInsertId();
}

function connectionCredentials(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT credentials_json FROM aggregator_connections WHERE id = ?");
    $stmt->execute([$id]);
    return json_decode((string) $stmt->fetchColumn(), true);
}

$expiredCreds = [
    'access_token' => 'OLD_TOKEN_A',
    'refresh_token' => 'RT1',
    'token_expires_at' => time() - 60,
    'app_id' => '7001234567890',
    'app_secret' => 'sekret',
    'advertiser_id' => '1234567890',
];

// ---- No-op paths -----------------------------------------------------------

$pdo = freshPdo();
insertConnection($pdo, 'tiktok', $expiredCreds);

$manual = ['access_token' => 'PLAIN', 'advertiser_id' => '1'];
$returned = TikTokAdsEngine::ensureFreshToken($pdo, $manual);
check('manual connection (no refresh_token) is a no-op', $returned === $manual);

$stillValid = $expiredCreds;
$stillValid['token_expires_at'] = time() + 7200;
$returned = TikTokAdsEngine::ensureFreshToken($pdo, $stillValid);
check('token with 2h left is not refreshed', $returned === $stillValid);

// ---- Missing app credentials ----------------------------------------------

$noApp = $expiredCreds;
unset($noApp['app_id']);
try {
    TikTokAdsEngine::ensureFreshToken($pdo, $noApp);
    check('expired token without app_id throws', false);
} catch (RuntimeException $e) {
    check('expired token without app_id throws', strpos($e->getMessage(), 'Re-connect') !== false, $e->getMessage());
}

// ---- Refresh propagates to siblings and pixels ----------------------------

$pdo = freshPdo();
$idA = insertConnection($pdo, 'tiktok', $expiredCreds);              // same login
$idB = insertConnection($pdo, 'tiktok', $expiredCreds);              // same login, second cabinet
$idOther = insertConnection($pdo, 'tiktok', [                        // different login — untouched
    'access_token' => 'OTHER_TOKEN', 'refresh_token' => 'RTX',
    'token_expires_at' => time() - 60, 'app_id' => '7001234567890', 'app_secret' => 's',
]);
insertConnection($pdo, 'facebook', ['token' => 'fb']);               // other engine — untouched

$pdo->exec("INSERT INTO pixel_profiles (traffic_source, niche, name, pixel_id, token) VALUES
    ('tiktok', 'General', 'P1', 'PX1', 'OLD_TOKEN_A'),
    ('tiktok', 'General', 'P2', 'PX2', 'OLD_TOKEN_A'),
    ('facebook', 'General', 'P3', 'PX3', 'OLD_TOKEN_A'),
    ('tiktok', 'General', 'P4', 'PX4', 'OTHER_TOKEN')");
$pdo->exec("INSERT INTO campaign_pixels (campaign_id, type, pixel_id, token) VALUES
    (1, 'tiktok', 'PX1', 'OLD_TOKEN_A'),
    (1, 'facebook', 'PX3', 'OLD_TOKEN_A')");

TikTokAdsEngine::$testRefreshHandler = function (array $creds) {
    return [
        'code' => 0,
        'message' => 'OK',
        'data' => [
            'access_token' => 'NEW_TOKEN_A',
            'refresh_token' => 'RT2',
            'expires_in' => 86400,
            'refresh_token_expires_in' => 31536000,
        ],
    ];
};

try {
    $updated = TikTokAdsEngine::ensureFreshToken($pdo, connectionCredentials($pdo, $idA));
    check('refreshed credentials carry the new access token', $updated['access_token'] === 'NEW_TOKEN_A');
    check('refreshed credentials carry the rotated refresh token', $updated['refresh_token'] === 'RT2');
    check('refreshed credentials carry a future expiry', $updated['token_expires_at'] > time() + 80000);

    $credsB = connectionCredentials($pdo, $idB);
    check('sibling connection of the same login got the new token', $credsB['access_token'] === 'NEW_TOKEN_A', json_encode($credsB));
    check('sibling connection got the rotated refresh token', $credsB['refresh_token'] === 'RT2');

    $credsOther = connectionCredentials($pdo, $idOther);
    check('different login connection untouched', $credsOther['access_token'] === 'OTHER_TOKEN');

    $ttTokens = $pdo->query("SELECT token FROM pixel_profiles WHERE traffic_source = 'tiktok' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    check('tiktok pixel profiles updated by old-token match', $ttTokens === ['NEW_TOKEN_A', 'NEW_TOKEN_A', 'OTHER_TOKEN'], json_encode($ttTokens));

    $fbPixelToken = $pdo->query("SELECT token FROM pixel_profiles WHERE traffic_source = 'facebook'")->fetchColumn();
    check('facebook pixel with the same token value untouched', $fbPixelToken === 'OLD_TOKEN_A');

    $cpTt = $pdo->query("SELECT token FROM campaign_pixels WHERE type = 'tiktok'")->fetchColumn();
    $cpFb = $pdo->query("SELECT token FROM campaign_pixels WHERE type = 'facebook'")->fetchColumn();
    check('campaign_pixels tiktok copy updated', $cpTt === 'NEW_TOKEN_A');
    check('campaign_pixels facebook copy untouched', $cpFb === 'OLD_TOKEN_A');
} finally {
    TikTokAdsEngine::$testRefreshHandler = null;
}

// ---- Refresh failure surfaces as an exception ------------------------------

$pdo = freshPdo();
insertConnection($pdo, 'tiktok', $expiredCreds);
TikTokAdsEngine::$testRefreshHandler = function () {
    return ['code' => 90410, 'message' => 'refresh token has expired'];
};
try {
    TikTokAdsEngine::ensureFreshToken($pdo, $expiredCreds);
    check('dead refresh token throws (so sync logs the real reason)', false);
} catch (RuntimeException $e) {
    check('dead refresh token throws (so sync logs the real reason)', strpos($e->getMessage(), '90410') !== false, $e->getMessage());
} finally {
    TikTokAdsEngine::$testRefreshHandler = null;
}

echo PHP_EOL . ($failed === 0 ? "PASSED: $passed checks" : "FAILED: $failed of " . ($passed + $failed)) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
