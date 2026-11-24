<?php
// core/cloak_gate.php
//
// Gate mode of a cloak stream ("Send clean visitors to the next stream", the
// schema key pass_clean_to_next): the stream acts as a filter in front of the
// streams below it. A suspicious visitor is caught and gets its Safe Page; a
// clean one is treated as if the stream did not match, so selection carries on
// below it. The verdict has to be taken at selection time — the only point
// where "the next stream" still exists — which is why it lives apart from
// orbitraCloakDecision().
//
// Shared by the redirect router (index.php), click.php and the campaign
// simulator. The challenge-token helpers ride along: the router insists on a
// token when the gate's JS challenge is on.

require_once __DIR__ . '/click_logger.php';

if (!function_exists('generateCloakJsToken')) {
    function generateCloakJsToken($campaignId, $secret)
    {
        $payload = base64_encode(json_encode(['cid' => (int) $campaignId, 'ts' => time()]));
        return [$payload, hash_hmac('sha256', $payload, $secret)];
    }
}

if (!function_exists('validateCloakJsToken')) {
    function validateCloakJsToken($token, $sig, $secret, $campaignId)
    {
        if (empty($token) || empty($sig)) {
            return false;
        }
        if (!hash_equals(hash_hmac('sha256', $token, $secret), $sig)) {
            return false;
        }
        $payload = json_decode(base64_decode($token), true);
        if (!is_array($payload) || !isset($payload['ts'], $payload['cid'])) {
            return false;
        }
        // Short TTL: the probe redirect happens within a second or two.
        if ((time() - (int) $payload['ts']) > 300) {
            return false;
        }
        return (int) $payload['cid'] === (int) $campaignId;
    }
}

if (!function_exists('orbitraCloakGateDecision')) {
    /**
     * The cloak verdict a gate stream gives this visitor, or null when the
     * stream is not a gate (not a cloak stream, or pass_clean_to_next off).
     *
     * The array is orbitraCloakDecision()'s: show_safe true means the visitor
     * is caught by the gate, anything else means they are clean.
     *
     * Entry points that cannot run the JS challenge round trip (click.php, the
     * simulator) stop here and read "clean" as "passes the gate".
     */
    function orbitraCloakGateDecision(array $stream, array $ctx, array $settings, string $countryCode, string $deviceType, string $jsFailure = ''): ?array
    {
        if (($stream['schema_type'] ?? '') !== 'cloak') {
            return null;
        }
        $schema = json_decode((string) ($stream['schema_custom_json'] ?? '{}'), true);
        if (!is_array($schema) || !filter_var($schema['pass_clean_to_next'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }
        return orbitraCloakDecision($schema, $ctx, $settings, $countryCode, $deviceType, $jsFailure);
    }
}

if (!function_exists('orbitraCloakGatePassesThrough')) {
    /**
     * Is this a cloak "gate" stream that lets the current visitor through?
     *
     * True only for a cloak stream with pass_clean_to_next on, when the detector
     * finds the visitor clean AND — if the stream has the JS challenge on — the
     * visitor already holds a valid challenge token. Anything else returns false,
     * so the stream is selected as usual and its serving branch shows the safe
     * page or runs the challenge; after the challenge the visitor comes back with
     * the token and passes through here.
     */
    function orbitraCloakGatePassesThrough(array $stream, array $ctx, array $settings, string $countryCode, string $deviceType, $campaignId): bool
    {
        $decision = orbitraCloakGateDecision($stream, $ctx, $settings, $countryCode, $deviceType, (string) ($_GET['_ocjf'] ?? ''));
        if ($decision === null || !empty($decision['show_safe'])) {
            return false;
        }
        $schema = json_decode((string) ($stream['schema_custom_json'] ?? '{}'), true);
        if (filter_var($schema['js_challenge'] ?? false, FILTER_VALIDATE_BOOL)) {
            $secret = $settings['postback_key'] ?? 'orbitra_secret';
            if (!validateCloakJsToken($_GET['_ocj'] ?? '', $_GET['_ocs'] ?? '', $secret, $campaignId)) {
                return false;
            }
        }
        return true;
    }
}
