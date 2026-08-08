<?php
/**
 * Secret admin path.
 *
 * By default the panel lives at /admin.php, which every scanner that walks an IP
 * range knows to try. Setting a secret path moves it to /<your-path> and makes
 * /admin.php answer 404, so the login form is not sitting at a guessable URL on
 * the bare server IP.
 *
 * This hides the panel; it does not replace the password. /api.php still answers
 * (it has to — the panel talks to it), and it enforces its own authentication.
 */

const ORBITRA_ADMIN_PATH_RESERVED = [
    'admin', 'admin.php', 'api', 'api.php', 'index', 'index.php',
    'click', 'click.php', 'postback', 'postback.php', 'router', 'router.php',
    'mcp', 'mcp.php', 'robots.txt', 'favicon.ico', 'frontend', 'landings',
    'assets', 'core', 'cli', 'var', 'geo', 'vendor', 'r', 'click_api',
    'well-known', 'sitemap.xml',
];

/**
 * The configured secret path, or '' when the panel is at /admin.php.
 */
function orbitraAdminPath(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $value = $pdo->query("SELECT value FROM settings WHERE key = 'admin_path' LIMIT 1")->fetchColumn();
    } catch (\Throwable $e) {
        // Databases from before this feature simply have no row.
        $value = '';
    }

    $cached = is_string($value) ? trim($value, "/ \t\n\r\0\x0B") : '';
    return $cached;
}

/**
 * Validate a candidate path.
 *
 * @return array{ok: bool, value: string, error: string}
 */
function orbitraValidateAdminPath(PDO $pdo, $raw): array
{
    $value = strtolower(trim((string) $raw, "/ \t\n\r\0\x0B"));

    // Empty means "back to the default /admin.php", which is always allowed.
    if ($value === '') {
        return ['ok' => true, 'value' => '', 'error' => ''];
    }

    if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $value)) {
        return [
            'ok' => false,
            'value' => '',
            'error' => 'admin_path_invalid',
        ];
    }

    if (in_array($value, ORBITRA_ADMIN_PATH_RESERVED, true)) {
        return ['ok' => false, 'value' => '', 'error' => 'admin_path_reserved'];
    }

    // A campaign alias and the admin path are both single path segments, so one
    // would shadow the other. Refuse rather than silently break a live campaign.
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM campaigns WHERE lower(alias) = ? LIMIT 1");
        $stmt->execute([$value]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'value' => '', 'error' => 'admin_path_alias_taken'];
        }
    } catch (\Throwable $e) {
        // If the check cannot run, allow the value — the panel routing wins anyway.
    }

    return ['ok' => true, 'value' => $value, 'error' => ''];
}

/**
 * Does this request path address the panel?
 *
 * "/my-panel", "/my-panel/" and "/My-Panel" all do; "/my-panel/anything" does
 * not, because the panel is a single segment and everything below it belongs to
 * campaign routing.
 */
function orbitraAdminPathMatches(string $adminPath, ?string $uriPath): bool
{
    if ($adminPath === '' || $uriPath === null) {
        return false;
    }

    return strtolower(trim($uriPath, '/')) === $adminPath;
}

/**
 * Serve the panel for a request that matched the secret path.
 * Returns false when the request is not for the panel.
 */
function orbitraTryServeAdminPath(PDO $pdo, ?string $uriPath): bool
{
    if (!orbitraAdminPathMatches(orbitraAdminPath($pdo), $uriPath)) {
        return false;
    }

    // Tells admin.php this request came through the secret path, so its own
    // guard lets it render instead of answering 404.
    if (!defined('ORBITRA_ADMIN_ROUTED')) {
        define('ORBITRA_ADMIN_ROUTED', true);
    }

    require __DIR__ . '/../admin.php';
    return true;
}
