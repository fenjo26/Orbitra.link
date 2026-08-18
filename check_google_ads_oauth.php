#!/usr/bin/env php
<?php
/**
 * check_google_ads_oauth.php - Verify Google Ads OAuth Configuration
 *
 * Run this script to check if your server has the necessary environment
 * variables configured for the 1-Click Google Ads OAuth flow.
 *
 * Usage: php check_google_ads_oauth.php
 */

echo "\n=== Orbitra Google Ads OAuth Configuration Check ===\n\n";

// Check environment variables
$envVars = [
    'ORBITRA_GOOGLE_CLIENT_ID' => false,
    'ORBITRA_GOOGLE_CLIENT_SECRET' => false,
    'ORBITRA_GOOGLE_DEVELOPER_TOKEN' => false,
];

foreach ($envVars as $var => &$found) {
    $value = trim((string) getenv($var));
    if ($value !== '') {
        $found = true;
        $masked = $var === 'ORBITRA_GOOGLE_DEVELOPER_TOKEN'
            ? substr($value, 0, 8) . '...' . substr($value, -4)
            : ($var === 'ORBITRA_GOOGLE_CLIENT_SECRET'
                ? substr($value, 0, 12) . '...'
                : $value);
        echo "✓ $var = $masked\n";
    } else {
        echo "✗ $var (not set)\n";
    }
}
unset($found);

// Check if config.php exists and can be loaded
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    echo "\n✓ config.php found\n";

    // Try to load config without running migrations
    try {
        require_once $configFile;
        echo "✓ config.php loaded successfully\n";

        // Check if credentials are configured in database
        if (isset($pdo)) {
            echo "✓ Database connection available\n";

            $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('google_ads_client_id','google_ads_client_secret','google_ads_developer_token')");
            $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if ($dbSettings) {
                echo "\nDatabase settings:\n";
                foreach (['google_ads_client_id', 'google_ads_client_secret', 'google_ads_developer_token'] as $key) {
                    if (isset($dbSettings[$key]) && trim($dbSettings[$key]) !== '') {
                        $masked = $key === 'google_ads_developer_token'
                            ? substr($dbSettings[$key], 0, 8) . '...'
                            : ($key === 'google_ads_client_secret'
                                ? substr($dbSettings[$key], 0, 12) . '...'
                                : $dbSettings[$key]);
                        echo "✓ $key = $masked\n";
                    }
                }
            } else {
                echo "ℹ No Google Ads credentials in database\n";
            }
        }
    } catch (Throwable $e) {
        echo "✗ Error loading config.php: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗ config.php not found\n";
}

// Summary
$allSet = $envVars['ORBITRA_GOOGLE_CLIENT_ID'] &&
          $envVars['ORBITRA_GOOGLE_CLIENT_SECRET'] &&
          $envVars['ORBITRA_GOOGLE_DEVELOPER_TOKEN'];

echo "\n" . str_repeat('=', 50) . "\n";
if ($allSet) {
    echo "✓ Google Ads OAuth is CONFIGURED\n";
    echo "✓ Users can use 1-Click \"Sign in with Google\" button\n";
} else {
    echo "✗ Google Ads OAuth is NOT CONFIGURED\n";
    echo "ℹ Set the missing environment variables on your server\n";
    echo "ℹ See .env.example or README.md for instructions\n";
}
echo str_repeat('=', 50) . "\n\n";
