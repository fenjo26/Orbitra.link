<?php
// Browser context only: no Pixel events, no guessed _fbp, no conversion URLs.

function orbitraMetaOpaque($value, int $max = 8192): ?string
{
    if (!is_string($value) || $value === '' || strlen($value) > $max
        || preg_match('/[\x00-\x20\x7f]/', $value)) {
        return null;
    }
    return $value;
}

function orbitraMetaCookie($value): ?string
{
    $value = orbitraMetaOpaque($value);
    // Preserve the complete opaque suffix, including Parameter Builder appendices.
    return $value !== null && preg_match('/^fb\.\d+\.\d{10,13}\..+$/D', $value) ? $value : null;
}

function orbitraMetaFbcMatches(string $fbc, string $fbclid): bool
{
    $parts = explode('.', $fbc, 4);
    $suffix = $parts[3] ?? '';
    return $suffix === $fbclid || str_starts_with($suffix, $fbclid . '.');
}

function orbitraMetaPageUrl($value): ?string
{
    if (!is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value)) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    return explode('#', $value, 2)[0];
}

function orbitraMetaCookiesEnabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'use_cookies' LIMIT 1");
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $value !== '0';
    } catch (Throwable $e) {
        return true; // Older schemas have the same default as config.php.
    }
}

function orbitraMetaCampaignEnabled(PDO $pdo, int $campaignId): bool
{
    if ($campaignId <= 0) { return false; }
    static $cache;
    $cache ??= new WeakMap();
    $campaigns = $cache[$pdo] ?? [];
    if (array_key_exists($campaignId, $campaigns)) { return $campaigns[$campaignId]; }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM campaign_pixels WHERE campaign_id = ? AND type = 'facebook' AND is_active = 1 LIMIT 1");
        $stmt->execute([$campaignId]);
        $enabled = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
    } catch (Throwable $e) { $enabled = false; }
    $campaigns[$campaignId] = $enabled;
    $cache[$pdo] = $campaigns;
    return $enabled;
}

function orbitraMetaCapture(PDO $pdo, array $incoming, array $cookies, bool $browserContext, int $campaignId = 0): array
{
    $params = [];
    $enabled = (string) ($incoming['meta_matching'] ?? '1') !== '0' && orbitraMetaCookiesEnabled($pdo);
    if (!$enabled) {
        $params['meta_matching'] = '0';
        return $params;
    }
    $fbclid = orbitraMetaOpaque($incoming['fbclid'] ?? null, 8000);
    $fbc = orbitraMetaCookie($incoming['fbc'] ?? $incoming['_fbc'] ?? null)
        ?? orbitraMetaCookie($cookies['_fbc'] ?? null);
    $fbp = orbitraMetaCookie($incoming['fbp'] ?? $incoming['_fbp'] ?? null)
        ?? orbitraMetaCookie($cookies['_fbp'] ?? null);
    // Do not introduce Meta cookies/identities on unrelated traffic. Actual
    // Meta evidence or an explicit integration opt-in avoids a pixel lookup.
    if ($fbclid === null && $fbc === null && $fbp === null
        && (string) ($incoming['meta_matching'] ?? '') !== '1'
        && !orbitraMetaCampaignEnabled($pdo, $campaignId)) {
        return ['meta_matching' => '0'];
    }
    if ($fbclid !== null) {
        $params['fbclid'] = $fbclid;
        // A new ad click must not inherit an older ad's cookie. Reuse the
        // original cookie timestamp only when it refers to THIS fbclid.
        if ($fbc === null || !orbitraMetaFbcMatches($fbc, $fbclid)) {
            $fbc = 'fb.1.' . (int) floor(microtime(true) * 1000) . '.' . $fbclid;
        }
    }
    if ($fbc !== null) { $params['fbc'] = $fbc; }
    if ($fbp !== null) { $params['fbp'] = $fbp; }
    $identity = orbitraMetaOpaque($incoming['meta_external_id'] ?? null, 512)
        ?? orbitraMetaOpaque($cookies['orbitra_visitor'] ?? null, 512);
    if ($identity === null && $browserContext && !empty($_SERVER['HTTP_USER_AGENT'])) {
        $identity = bin2hex(random_bytes(16));
    }
    if ($identity !== null) { $params['meta_external_id'] = $identity; }
    $page = orbitraMetaPageUrl($incoming['landing_page_url'] ?? null);
    if ($page !== null) { $params['landing_page_url'] = $page; }
    return $params;
}

function orbitraMetaPersistBrowserIdentity(array $params): void
{
    $identity = orbitraMetaOpaque($params['meta_external_id'] ?? null, 512);
    if ($identity === null || ($params['meta_matching'] ?? '') === '0' || headers_sent()) { return; }
    setcookie('orbitra_visitor', $identity, [
        'expires' => time() + 90 * 86400, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false, 'samesite' => 'Lax',
    ]);
    $_COOKIE['orbitra_visitor'] = $identity;
}

/** A click-id cookie alone is not authority to mint a late-update capability. */
function orbitraMetaRememberBrowserClick(PDO $pdo, string $clickId, array $params): void
{
    if (($params['meta_matching'] ?? '') === '0' || headers_sent()) { return; }
    orbitraMetaPersistBrowserIdentity($params);
    try {
        $value = $clickId . '|' . orbitraMetaMatchingToken($pdo, $clickId);
        setcookie('orbitra_meta_context', $value, [
            'expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        $_COOKIE['orbitra_meta_context'] = $value;
    } catch (Throwable $e) {
        // Fail closed if migration/key creation is unavailable.
    }
}

/** Separate random signing key; never use the shipped public postback key. */
function orbitraMetaMatchingSecret(PDO $pdo): string
{
    static $keys;
    $keys ??= new WeakMap();
    if (isset($keys[$pdo])) { return $keys[$pdo]; }
    $read = static function () use ($pdo): string {
        $stmt = $pdo->query('SELECT secret FROM meta_matching_keys WHERE id = 1');
        $value = (string) $stmt->fetchColumn();
        $stmt->closeCursor();
        return $value;
    };
    $key = $read();
    if ($key === '') {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO meta_matching_keys (id, secret) VALUES (1, ?)');
        $stmt->execute([bin2hex(random_bytes(32))]);
        $stmt->closeCursor();
        $key = $read(); // Concurrent first requests must use the winning key.
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
        throw new RuntimeException('Invalid Meta matching signing key');
    }
    return $keys[$pdo] = $key;
}

function orbitraMetaMatchingToken(PDO $pdo, string $clickId, ?int $expires = null): string
{
    $expires ??= time() + 86400;
    return $expires . '.' . hash_hmac('sha256', "orbitra:meta-context:v1\n" . $clickId . "\n" . $expires, orbitraMetaMatchingSecret($pdo));
}

function orbitraMetaVerifyMatchingToken(PDO $pdo, string $clickId, $token): bool
{
    if (!is_string($token) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/D', $token, $match)) { return false; }
    $expires = (int) $match[1];
    if ($expires < time() || $expires > time() + 86400) { return false; }
    return hash_equals(orbitraMetaMatchingToken($pdo, $clickId, $expires), $token);
}

/** Only issue capabilities for a click that was actually saved. */
function orbitraMetaMatchingConfig(PDO $pdo, string $clickId, string $base = ''): ?array
{
    if ($clickId === '' || !orbitraMetaCookiesEnabled($pdo)) { return null; }
    try {
        $stmt = $pdo->prepare('SELECT parameters_json FROM clicks WHERE id = ? LIMIT 1');
        $stmt->execute([$clickId]);
        $raw = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($raw === false) { return null; }
        $params = json_decode((string) $raw, true) ?: [];
        if (($params['meta_matching'] ?? '') === '0') { return null; }
        return [
            'subid' => $clickId, 'token' => orbitraMetaMatchingToken($pdo, $clickId),
            'endpoint' => rtrim($base, '/') . '/pixel.gif?action=matching',
            'meta_external_id' => $params['meta_external_id'] ?? null,
            'fbclid' => $params['fbclid'] ?? null, 'fbc' => $params['fbc'] ?? null,
        ];
    } catch (Throwable $e) {
        return null; // Context enrichment must not take a landing offline.
    }
}

function orbitraMetaMatchingScript(PDO $pdo, string $clickId): string
{
    $proof = explode('|', (string) ($_COOKIE['orbitra_meta_context'] ?? ''), 2);
    try {
        if (($proof[0] ?? '') !== $clickId || !orbitraMetaVerifyMatchingToken($pdo, $clickId, $proof[1] ?? null)) { return ''; }
    } catch (Throwable $e) { return ''; }
    $config = orbitraMetaMatchingConfig($pdo, $clickId);
    if ($config === null) { return ''; }
    return '<script defer src="/meta-matching.js" data-orbitra-matching="'
        . htmlspecialchars(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8')
        . '"></script>';
}

function orbitraInjectMetaMatching(string $html, PDO $pdo, string $clickId): string
{
    $script = orbitraMetaMatchingScript($pdo, $clickId);
    if ($script === '') { return $html; }
    // Fetch early but defer execution until page-level consent/configuration
    // scripts have run. The initial request already captured fbclid/identity.
    if (preg_match('/<head\b[^>]*>/i', $html)) {
        return preg_replace_callback('/<head\b[^>]*>/i', static fn($m) => $m[0] . $script, $html, 1);
    }
    return $script . $html;
}

/** Bearer capability, POST only, no ambient-cookie authentication and no PII updates. */
function orbitraHandleMetaMatching(PDO $pdo): void
{
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); return; }
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if (!is_string($raw) || strlen($raw) > 32768) { http_response_code(413); return; }
    $input = json_decode($raw, true);
    if (!is_array($input)) { http_response_code(400); return; }
    $clickId = orbitraMetaOpaque($input['subid'] ?? null, 128);
    try {
        if ($clickId === null || !orbitraMetaVerifyMatchingToken($pdo, $clickId, $input['token'] ?? null)) {
            http_response_code(403); return;
        }
        if (!orbitraMetaCookiesEnabled($pdo)) { http_response_code(204); return; }
        // Optimistic compare-and-swap preserves parallel dwell/sub_id updates.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stmt = $pdo->prepare('SELECT parameters_json FROM clicks WHERE id = ? LIMIT 1');
            $stmt->execute([$clickId]);
            $previous = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($previous === false) { http_response_code(404); return; }
            $params = json_decode((string) $previous, true) ?: [];
            if (($params['meta_matching'] ?? '') === '0') { http_response_code(204); return; }
            $fbp = orbitraMetaCookie($input['fbp'] ?? null);
            $fbc = orbitraMetaCookie($input['fbc'] ?? null);
            if ($fbp !== null) { $params['fbp'] = $fbp; }
            // A late stale cookie must never erase the fbclid captured on arrival.
            if ($fbc !== null && (empty($params['fbclid']) || orbitraMetaFbcMatches($fbc, $params['fbclid']))) {
                $params['fbc'] = $fbc;
            }
            $page = orbitraMetaPageUrl($input['landing_page_url'] ?? null);
            if ($page !== null && empty($params['landing_page_url'])) { $params['landing_page_url'] = $page; }
            $next = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($next === $previous) { http_response_code(204); return; }
            $stmt = $pdo->prepare('UPDATE clicks SET parameters_json = ? WHERE id = ? AND parameters_json IS ?');
            $stmt->execute([$next, $clickId, $previous]);
            $updated = $stmt->rowCount();
            $stmt->closeCursor();
            if ($updated > 0) { http_response_code(204); return; }
        }
        http_response_code(409);
    } catch (Throwable $e) {
        http_response_code(503);
    }
}
