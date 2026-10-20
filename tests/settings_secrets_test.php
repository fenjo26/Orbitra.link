<?php
// The `settings` read masks credentials for API-key callers, and save_settings
// never stores a mask posted back over the real value.

require_once __DIR__ . '/../core/settings_secrets.php';

$failures = [];
$check = static function (string $name, $expected, $actual) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

// Keys seen in real installs.
$secret = [
    'maxmind_license_key', 'ip2location_token', 'vapid_private_key', 'vapid_private_pem',
    'telegram_bot_token', 'telegram_webhook_secret', 'recaptcha_v2_secret_key',
    'recaptcha_v3_secret_key', 'turnstile_secret_key', 'cf_api_token', 'crm_ingest_secret',
    'nc_api_key',
];
$public = [
    'postback_key', 'vapid_public_key', 'landing_token_ttl', 'privacy_enabled',
    'recaptcha_v2_site_key', 'turnstile_site_key', 'tiktok_mcp_client_id', 'maxmind_account_id',
    'telegram_webhook_set', 'telegram_notify_conversions', 'currency', 'push_vapid_sub',
];
foreach ($secret as $k) { $check("secret: $k", true, orbitraIsSecretSettingKey($k)); }
foreach ($public as $k) { $check("public: $k", false, orbitraIsSecretSettingKey($k)); }

// Masking keeps "not configured" visible and only a 4-char tail of long values.
$masked = orbitraMaskSecretSettings([
    'maxmind_license_key' => 'E7aaGM_SpQvAB4uzVAgrrWhx',
    'telegram_bot_token'  => '',
    'turnstile_secret_key' => null,
    'ip2location_token'   => 'short',
    'postback_key'        => '6e6fb1629e603da114703ebf',
    'postback_aliases'    => ['clickid' => 'subid'],
]);
$check('tail is last 4', '********' . substr('E7aaGM_SpQvAB4uzVAgrrWhx', -4), $masked['maxmind_license_key']);
$check('empty stays empty', '', $masked['telegram_bot_token']);
$check('null stays null', null, $masked['turnstile_secret_key']);
$check('short value fully masked', '********', $masked['ip2location_token']);
$check('postback_key untouched', '6e6fb1629e603da114703ebf', $masked['postback_key']);
$check('arrays untouched', ['clickid' => 'subid'], $masked['postback_aliases']);
$check('no secret plaintext anywhere', false, strpos(json_encode($masked), 'E7aaGM') !== false);

// Round trip: what the API read returns, posted back, writes no secret.
$posted = orbitraDropMaskedSecretSettings($masked);
$check('masked secret dropped', false, array_key_exists('maxmind_license_key', $posted));
$check('short masked secret dropped', false, array_key_exists('ip2location_token', $posted));
$check('empty secret kept (explicit clear)', true, array_key_exists('telegram_bot_token', $posted));
$check('non-secret kept', '6e6fb1629e603da114703ebf', $posted['postback_key']);
$new = orbitraDropMaskedSecretSettings(['maxmind_license_key' => 'NEWKEY123']);
$check('a real new secret is saved', 'NEWKEY123', $new['maxmind_license_key'] ?? null);

// api.php wiring: the read masks for api_key auth, the save drops masks.
$api = file_get_contents(__DIR__ . '/../api.php');
$check('helper required', true, strpos($api, "require_once __DIR__ . '/core/settings_secrets.php';") !== false);
$sPos = strpos($api, "case 'settings':");
$sEnd = strpos($api, "case 'campaign_creatives':", $sPos);
$settingsCase = substr($api, $sPos, $sEnd - $sPos);
$check('settings masks for api_key', true,
    strpos($settingsCase, "auth_via") !== false && strpos($settingsCase, 'orbitraMaskSecretSettings(') !== false);
$vPos = strpos($api, "case 'save_settings':");
$saveCase = substr($api, $vPos, 1500);
$check('save_settings drops masks', true, strpos($saveCase, 'orbitraDropMaskedSecretSettings(') !== false);

if ($failures) {
    echo "FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "settings_secrets_test: OK\n";
