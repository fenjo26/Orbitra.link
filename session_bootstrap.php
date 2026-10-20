<?php

function orbitraIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }
    if (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false) {
        return true;
    }
    if (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
        return true;
    }
    return false;
}

/**
 * How long a "remember me" session stays valid, in seconds (30 days).
 *
 * Without the checkbox the panel session is a browser-session cookie: close
 * the browser overnight and the morning visit lands on the login page no
 * matter what session_lifetime says. With it, the login handler re-issues the
 * cookie with this expiry and stamps the session, and both the idle check and
 * gc_maxlifetime below honor the stamp — GC must not sweep a remembered file
 * that has merely been idle longer than the regular lifetime.
 */
if (!defined('ORBITRA_REMEMBER_LIFETIME')) {
    define('ORBITRA_REMEMBER_LIFETIME', 2592000);
}

/**
 * How long an idle panel session stays valid, in seconds.
 *
 * The `session_lifetime` setting (seeded at 86400 by migration 8) used to be
 * dead weight: nothing read it, so PHP's own default applied and a panel left
 * alone for 24 minutes came back to a wall of 401s from every request the
 * still-loaded UI made. Resolution order is constant → environment → the
 * settings table, so a server can pin it without a database read.
 */
function orbitraSessionLifetime(): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $lifetime = 0;
    if (defined('ORBITRA_SESSION_LIFETIME')) {
        $lifetime = (int) constant('ORBITRA_SESSION_LIFETIME');
    }
    if ($lifetime <= 0) {
        $env = getenv('ORBITRA_SESSION_LIFETIME');
        if (is_string($env) && ctype_digit(trim($env))) {
            $lifetime = (int) trim($env);
        }
    }
    if ($lifetime <= 0) {
        try {
            // admin.php has already built $pdo; api.php bootstraps the session
            // before config.php, so it opens its own short-lived handle.
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!$pdo instanceof PDO) {
                $dbFile = __DIR__ . '/orbitra_db.sqlite';
                if (is_file($dbFile)) {
                    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 2,
                    ]);
                    $pdo->exec('PRAGMA busy_timeout = 2000;');
                }
            }
            if ($pdo instanceof PDO) {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'session_lifetime' LIMIT 1");
                $value = $stmt ? $stmt->fetchColumn() : false;
                if ($value !== false && ctype_digit(trim((string) $value))) {
                    $lifetime = (int) trim((string) $value);
                }
            }
        } catch (\Throwable $e) {
            // A locked or pre-migration database falls back to the default
            // below rather than logging anyone out.
            $lifetime = 0;
        }
    }

    if ($lifetime <= 0) {
        $lifetime = 86400;
    }
    // Five minutes to thirty days: a typo in the settings table must not make
    // the panel unusable or the session immortal.
    $lifetime = max(300, min($lifetime, 2592000));

    $cached = $lifetime;
    return $cached;
}

function orbitraBootstrapSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = orbitraIsHttps();

    $lifetime = orbitraSessionLifetime();
    // Remembered sessions may idle past the regular lifetime, so the janitor
    // has to outlive them too; the explicit idle check below stays the real
    // gate for every session, remembered or not.
    ini_set('session.gc_maxlifetime', (string) max($lifetime, ORBITRA_REMEMBER_LIFETIME));

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    session_name('ORBITRASESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $sessionDir = __DIR__ . '/var/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    $ownSessionDir = is_dir($sessionDir) && is_writable($sessionDir);
    if ($ownSessionDir) {
        session_save_path($sessionDir);
        // A custom save_path is invisible to the distribution's session-cleanup
        // cron, so nothing would ever sweep var/sessions. PHP's own probabilistic
        // GC does it instead — at gc_maxlifetime, which now means what the
        // setting says.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    session_start();

    // Idle expiry, enforced here rather than left to GC.
    //
    // Two reasons this cannot be delegated. When the tracker's own session
    // directory is not writable, PHP falls back to the shared one, which
    // Debian/Ubuntu sweep from cron using the php.ini value — ini_set() above
    // does not reach that cron. And PHP only rewrites a session file when the
    // data changed, so an active operator whose session data is stable looks
    // untouched to any mtime-based cleaner. Stamping the request time on every
    // hit fixes both: the file stays fresh while the panel is in use, and the
    // check below is what actually ends an idle session.
    $now = time();
    $lastSeen = (int) ($_SESSION['_orbitra_last_seen'] ?? 0);
    $idleLimit = !empty($_SESSION['_orbitra_remember'])
        ? max($lifetime, ORBITRA_REMEMBER_LIFETIME)
        : $lifetime;
    if ($lastSeen > 0 && ($now - $lastSeen) > $idleLimit) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_orbitra_last_seen'] = $now;
}

