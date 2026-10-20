<?php
/**
 * Secret settings and API-key callers.
 *
 * The `settings` action returns the whole settings table to an admin. A panel
 * session needs the real values (the Settings/Integrations forms edit them),
 * but an API key does not: keys get pasted into scripts, MCP configs and
 * third-party tools, and a leaked key should not also leak the MaxMind
 * licence, the VAPID private key or the bot tokens. So for API-key callers the
 * `settings` read masks every secret-looking key, and `save_settings` ignores a
 * masked value posted back, so a read-modify-write through the API cannot
 * overwrite a real secret with its mask.
 */

const ORBITRA_SECRET_MASK_PREFIX = '********';

/**
 * Whether a settings key holds a credential. Name-based on purpose: new
 * integrations follow the same suffix conventions, so they are covered without
 * touching this list. Public halves (vapid_public_key) and the postback key
 * (it is printed in every postback URL) stay readable.
 */
function orbitraIsSecretSettingKey(string $key): bool
{
    $key = strtolower($key);
    if (in_array($key, ['postback_key', 'vapid_public_key'], true)) {
        return false;
    }
    return (bool) preg_match(
        '/(secret|private|password|passwd|_token$|^token$|_license_key$|_api_key$|_pem$)/',
        $key
    );
}

/** Mask one secret: keep the last 4 chars so the operator can tell keys apart. */
function orbitraMaskSecretValue($value)
{
    if ($value === null || $value === '') {
        return $value; // "not configured" stays visible as such
    }
    $value = (string) $value;
    $tail = strlen($value) > 12 ? substr($value, -4) : '';
    return ORBITRA_SECRET_MASK_PREFIX . $tail;
}

function orbitraIsMaskedSecretValue($value): bool
{
    return is_string($value) && strncmp($value, ORBITRA_SECRET_MASK_PREFIX, strlen(ORBITRA_SECRET_MASK_PREFIX)) === 0;
}

/** Mask every secret key of a settings map (key => value). */
function orbitraMaskSecretSettings(array $settings): array
{
    foreach ($settings as $key => $value) {
        if (is_string($key) && orbitraIsSecretSettingKey($key)) {
            $settings[$key] = orbitraMaskSecretValue($value);
        }
    }
    return $settings;
}

/** Drop secret keys whose posted value is still the mask (nothing was changed). */
function orbitraDropMaskedSecretSettings(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_string($key) && orbitraIsSecretSettingKey($key) && orbitraIsMaskedSecretValue($value)) {
            unset($data[$key]);
        }
    }
    return $data;
}
