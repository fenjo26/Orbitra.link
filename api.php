<?php
/** @noinspection PhpComplexFunctionInspection */
/** @noinspection PhpTooManyParametersInspection */
require_once __DIR__ . '/session_bootstrap.php';
orbitraBootstrapSession();

// Keep errors in logs, but do not leak them into API JSON responses.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Логирование ошибок в файл
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/var/logs/php_errors.log');

// Создаём директорию для логов если нет
if (!is_dir(__DIR__ . '/var/logs')) {
    mkdir(__DIR__ . '/var/logs', 0777, true);
}

// api.php - JSON API для React Dashboard
require_once 'config.php';
require_once __DIR__ . '/core/ReportMetrics.php';
require_once 'version.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/core/shell.php';
require_once __DIR__ . '/core/git_update.php';
require_once __DIR__ . '/core/backorder.php';
require_once __DIR__ . '/core/keitaro_import.php';
require_once __DIR__ . '/core/CloakDetector.php';
require_once __DIR__ . '/core/geo_databases.php';

// CORS Headers
$allowedOrigins = ['https://tracker.yourdomain.com', 'http://127.0.0.1:8000', 'http://localhost:8080', 'http://localhost:5173', 'http://localhost']; // Add real domains here
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // Fallback for tools like curl if needed, but safer to restrict
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, X-Api-Key');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

// Rate Limiting fallback implementation
/**
 * Raw request body, overridable for in-process calls.
 *
 * mcp.php dispatches an MCP tool by populating $_GET/$_SERVER and including this
 * file, which means there is no real php://input to read — that stream can only
 * be consumed from an actual HTTP request. Handlers therefore go through this
 * helper instead of reading the stream directly.
 */
function orbitraRequestBody()
{
    if (isset($GLOBALS['ORBITRA_INTERNAL_REQUEST_BODY'])) {
        return (string) $GLOBALS['ORBITRA_INTERNAL_REQUEST_BODY'];
    }
    // Must stay a literal stream read: this is the one place that may not go
    // through the helper, or the fallback calls itself forever.
    return (string) file_get_contents('php://input');
}

// === Cloudflare integration helpers ===

/** Connection config from settings; server_ip falls back to the web-facing address. */
function orbitraCloudflareConfig(PDO $pdo): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $out = ['token' => '', 'proxied' => true, 'ssl_mode' => 'flexible', 'server_ip' => ''];
    try {
        $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('cf_api_token','cf_proxied','cf_ssl_mode','cf_server_ip')");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            switch ($row['key']) {
                case 'cf_api_token': $out['token'] = (string) $row['value']; break;
                case 'cf_proxied': $out['proxied'] = ((string) $row['value']) !== '0'; break;
                case 'cf_ssl_mode': $out['ssl_mode'] = (string) $row['value']; break;
                case 'cf_server_ip': $out['server_ip'] = (string) $row['value']; break;
            }
        }
    } catch (\Throwable $e) {
        // Defaults above degrade into "not connected".
    }
    if ($out['server_ip'] === '') {
        $out['server_ip'] = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    }
    $cfg = $out;
    return $cfg;
}

/**
 * Point a domain's A record at the tracker through Cloudflare. On success (and
 * when the proxy is on) the domain's SSL is served by the CF edge, so its
 * ssl_status becomes 'cloudflare' and certbot leaves it alone.
 * @return array{ok:bool,message:string}
 */
function orbitraCloudflareSyncDomain(PDO $pdo, array $domain, ?array $cfg = null): array
{
    require_once __DIR__ . '/core/CloudflareApi.php';
    $cfg = $cfg ?? orbitraCloudflareConfig($pdo);
    if ($cfg['token'] === '') {
        return ['ok' => false, 'message' => 'Cloudflare is not connected'];
    }
    if ($cfg['server_ip'] === '') {
        return ['ok' => false, 'message' => 'Server IP is unknown — set it in the Cloudflare integration'];
    }

    $zone = CloudflareApi::findZoneForHost($cfg['token'], (string) $domain['name']);
    if (!$zone) {
        return ['ok' => false, 'message' => 'Zone not found in Cloudflare account'];
    }

    $dns = CloudflareApi::upsertDnsRecord($cfg['token'], $zone, (string) $domain['name'], $cfg['server_ip'], (bool) $cfg['proxied']);
    if (!$dns['ok']) {
        return $dns;
    }

    // SSL mode of the zone: best-effort — a refused setting must not undo the DNS work.
    CloudflareApi::setSslMode($cfg['token'], (string) $zone['id'], (string) $cfg['ssl_mode']);

    if (!empty($cfg['proxied'])) {
        // SSL now comes from the CF edge — take the domain out of the certbot queue.
        try {
            $pdo->prepare("UPDATE domains SET ssl_status = 'cloudflare', ssl_error = NULL WHERE id = ?")->execute([(int) $domain['id']]);
        } catch (\Throwable $e) {
            // The record itself is in place; the status is cosmetic.
        }
    }

    return $dns;
}

/**
 * Runs `composer install` for the tracker and reports what happened.
 *
 * ip2location/ip2location-php and ip2location/ip2proxy-php both declare
 * "ext-bcmath" as a hard requirement. On a server where that extension was never
 * installed, Composer rejects the whole lock file — "Your lock file does not
 * contain a compatible set of packages" — and the update fails at the dependency
 * step even though the source pulled cleanly. The web server cannot apt-install
 * the extension itself, so retry while ignoring that one platform requirement:
 * every other package (geoip2 among them) then installs and the panel keeps
 * working, with a message telling the admin the one command that fixes it fully.
 *
 * @param string $repoDir  Tracker root (the directory holding composer.phar).
 * @param array  $output   Receives the Composer output lines.
 * @return array{ok:bool,degraded:bool,hint:string}
 */
function orbitraComposerInstall(string $repoDir, array &$output): array
{
    $base = 'cd ' . escapeshellarg($repoDir)
        . ' && php ' . escapeshellarg($repoDir . '/composer.phar')
        . ' install --no-dev --prefer-dist --no-interaction --optimize-autoloader';

    $code = 0;
    $lines = [];
    exec($base . ' 2>&1', $lines, $code);
    $output = array_merge($output, $lines);
    if ($code === 0) {
        return ['ok' => true, 'degraded' => false, 'hint' => ''];
    }

    $missingBcmath = !extension_loaded('bcmath')
        || stripos(implode(' ', $lines), 'ext-bcmath') !== false;
    if (!$missingBcmath) {
        return ['ok' => false, 'degraded' => false, 'hint' => ''];
    }

    $retryLines = [];
    $retryCode = 0;
    exec($base . ' --ignore-platform-req=ext-bcmath 2>&1', $retryLines, $retryCode);
    $output = array_merge($output, ['[Retrying without the bcmath platform check]'], $retryLines);

    $hint = 'На сервере не установлено расширение PHP bcmath — оно нужно readers '
        . 'IP2Location/IP2Proxy для IPv6. Установите его командой: '
        . 'sudo apt-get install -y php-bcmath && sudo systemctl restart php$(php -r "echo PHP_MAJOR_VERSION.\'.\'.PHP_MINOR_VERSION;")-fpm';

    return ['ok' => $retryCode === 0, 'degraded' => $retryCode === 0, 'hint' => $hint];
}

function checkRateLimit($key, $maxRequests = 5, $window = 300)
{
    // Попробовать Redis, если расширение установлено
    if (extension_loaded('redis') && class_exists('Redis')) {
        try {
            $redis = new Redis();
            if (@$redis->connect('127.0.0.1', 6379)) {
                $current = $redis->get("ratelimit:$key") ?: 0;
                if ($current >= $maxRequests)
                    return false;
                $redis->incr("ratelimit:$key");
                $redis->expire("ratelimit:$key", $window);
                return true;
            }
        } catch (\Exception $e) {
        }
    }

    // SQLite Fallback для rate limiting
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (key VARCHAR(255) PRIMARY KEY, count INTEGER, expires_at DATETIME)");
        $pdo->exec("DELETE FROM rate_limits WHERE expires_at < datetime('now')");

        $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['count'] >= $maxRequests)
                return false;
            $pdo->prepare("UPDATE rate_limits SET count = count + 1 WHERE key = ?")->execute([$key]);
        } else {
            $pdo->prepare("INSERT INTO rate_limits (key, count, expires_at) VALUES (?, 1, datetime('now', '+$window seconds'))")->execute([$key]);
        }
        return true;
    } catch (\Exception $e) {
    }
    return true; // Graceful degrade если БД недоступна
}

// === API KEY AUTHENTICATION (for MCP / headless clients) ===
// Allows programmatic clients (e.g. the Orbitra MCP server) to authenticate with a
// personal API key instead of a browser session + CSRF token. The key is looked up in
// `user_api_keys`; when found we populate the session context in-memory for this request
// only, so all downstream handlers keep working unchanged.
//   Header options:  Authorization: Bearer <api_key>   OR   X-Api-Key: <api_key>
//   Permissions:     'read'  -> GET (read-only) actions only
//                    'write' | 'full' -> read + write (POST) actions
$apiKeyProvided = '';
$hdrAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($hdrAuth === '' && function_exists('apache_request_headers')) {
    $reqHeaders = apache_request_headers();
    $hdrAuth = $reqHeaders['Authorization'] ?? ($reqHeaders['authorization'] ?? '');
}
if ($hdrAuth !== '' && preg_match('/Bearer\s+(\S+)/i', $hdrAuth, $mAuth)) {
    $apiKeyProvided = trim($mAuth[1]);
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $apiKeyProvided = trim($_SERVER['HTTP_X_API_KEY']);
}

$apiKeyAuth = null; // ['id','user_id','permissions','role'] when authenticated via API key
if ($apiKeyProvided !== '' && !isset($_SESSION['user_id'])) {
    try {
        $stmtKey = $pdo->prepare(
            "SELECT k.id, k.user_id, k.permissions, u.role
             FROM user_api_keys k JOIN users u ON u.id = k.user_id
             WHERE k.api_key = ? LIMIT 1"
        );
        $stmtKey->execute([$apiKeyProvided]);
        $keyRow = $stmtKey->fetch(PDO::FETCH_ASSOC);
        if ($keyRow) {
            $apiKeyAuth = $keyRow;
            // Populate request-scoped auth context (not persisted — API clients are stateless).
            $_SESSION['user_id'] = (int) $keyRow['user_id'];
            $_SESSION['role'] = $keyRow['role'] ?? 'user';
            $_SESSION['auth_via'] = 'api_key';
            try {
                $pdo->prepare("UPDATE user_api_keys SET last_used = datetime('now') WHERE id = ?")
                    ->execute([$keyRow['id']]);
            } catch (\Exception $e) {
            }
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
            exit;
        }
    } catch (\Exception $e) {
    }
}
// =================================

// === ADMIN IP ACCESS LIST ===
// The same list admin.php enforces at the panel entry. An empty list is open;
// a populated list restricts every authenticated surface (session and API key)
// to the listed addresses. First-time setup (no users yet) is exempt, so an
// operator cannot lock themselves out before creating the first account.
require_once __DIR__ . '/core/ip_access.php';
$orbitraSetupInProgress = false;
try {
    $orbitraSetupInProgress = ((int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()) === 0;
} catch (\Throwable $e) {
    // A DB without the users table is even more "not set up" — allow through.
    $orbitraSetupInProgress = true;
}
if (!$orbitraSetupInProgress && !orbitraAdminIpAllowed($pdo)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied from this IP address']);
    exit;
}

// === AUTHENTICATION MIDDLEWARE & CSRF ===
$publicActions = ['login', 'check_setup', 'setup_first_user'];

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';

if (!in_array($action, $publicActions)) {
    // Ensure CSRF exists in session
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Any method that can change data needs the same guard, not POST alone —
    // otherwise an endpoint that also accepts DELETE would be reachable without
    // a CSRF token and, for API keys, without the write-permission check.
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        // API-key clients are exempt from CSRF (they don't use cookies); enforce
        // permission scope instead: read-only keys cannot perform write actions.
        if ($apiKeyAuth !== null) {
            $keyPerm = strtolower((string) ($apiKeyAuth['permissions'] ?? 'read'));
            if ($keyPerm !== 'write' && $keyPerm !== 'full') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'API key is read-only (write permission required)']);
                exit;
            }
        } elseif (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch']);
            exit;
        }
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}
// =================================

// Fetch default timezone from users
$userTimezone = 'Europe/Moscow'; // fallback
try {
    $stmtUser = $pdo->query("SELECT timezone FROM users WHERE id = 1 LIMIT 1");
    if ($stmtUser) {
        $tz = $stmtUser->fetchColumn();
        if ($tz) {
            $userTimezone = $tz;
        }
    }
} catch (\Exception $e) {
}

date_default_timezone_set($userTimezone);

// A report can ask for another timezone (the date-range picker sends one); every
// query below shifts dates by $dbTzOffset, so this is what makes that selection
// change the numbers instead of only the label.
if (!empty($_GET['timezone']) && in_array((string) $_GET['timezone'], DateTimeZone::listIdentifiers(), true)) {
    $userTimezone = (string) $_GET['timezone'];
    date_default_timezone_set($userTimezone);
}

// Calculate SQLite offset string for the current timezone
$dz = new DateTimeZone($userTimezone);
$dt = new DateTime('now', $dz);
$offsetOffset = $dz->getOffset($dt);
$dbTzOffset = sprintf('%+03d:%02d', intval($offsetOffset / 3600), abs($offsetOffset % 3600) / 60);

// Logging Helpers
function logSystem($pdo, $level, $message, $context = null)
{
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)");
        $stmt->execute([$level, $message, is_string($context) ? $context : json_encode($context)]);
    } catch (\Exception $e) {
    }
}

function logAudit($pdo, $action, $resource, $resource_id = null, $context = null, $status_code = 200)
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (action, resource, resource_id, context, ip, user_agent, status_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$action, $resource, $resource_id, is_string($context) ? $context : json_encode($context), $ip, $user_agent, $status_code]);
    } catch (\Exception $e) {
        logSystem($pdo, 'ERROR', "Audit Log Error", $e->getMessage());
    }
}

/**
 * Resolve a path inside one landing's directory, or null if it escapes.
 *
 * The landing file endpoints used to build this path inline, and two details made
 * that unsafe: the id was interpolated as a string, so `id=..` pointed the "allowed"
 * root at the application directory and let any signed-in user read config.php and
 * api.php; and the containment test was a bare prefix comparison, which also treats
 * /landings/12 as living inside /landings/1.
 *
 * @param bool $mustExist false when creating, where only the parent can be resolved
 * @return string|null absolute path, guaranteed to sit inside landings/<id>/
 */
function orbitraLandingFilePath($id, $relPath, bool $mustExist = true): ?string
{
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    // A landing's directory may now live under its slug rather than its id, so
    // resolve through orbitraLandingDir() which reads the slug from the DB. The
    // visitor never controls the slug here — the id is cast to int and the slug
    // is looked up server-side — so this cannot be aimed at an arbitrary path.
    global $pdo;
    if ($pdo instanceof PDO) {
        $resolved = orbitraLandingDir($pdo, $id);
    } else {
        $resolved = __DIR__ . '/landings/' . $id;
    }
    $root = realpath($resolved);
    if ($root === false) {
        return null;
    }

    // Normalise the relative path ourselves rather than leaning on realpath():
    // when creating a file the directories above it may not exist yet, and
    // realpath() cannot resolve a path that is not there.
    $segments = [];
    foreach (explode('/', str_replace('\\', '/', (string) $relPath)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null; // no climbing out, at any depth
        }
        $segments[] = $segment;
    }
    if (!$segments) {
        return null;
    }
    $target = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);

    $real = realpath($target);
    if ($real === false) {
        // Does not exist yet. With no ".." left in the path it cannot point outside
        // $root, so it is safe to hand back for creation.
        return $mustExist ? null : $target;
    }
    // It exists: re-check the resolved path, which also catches a symlink inside
    // the landing that points somewhere else entirely.
    return ($real === $root || strpos($real, $root . DIRECTORY_SEPARATOR) === 0) ? $real : null;
}

/** Extensions the landing editor may write. Never PHP: that is code execution. */
function orbitraLandingEditableExtensions(): array
{
    return ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md', 'xml', 'svg',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'bmp',
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'ogg', 'wav'];
}

/**
 * Shared CRUD for the two bot blacklists (bot_ips, bot_signatures).
 *
 * The panel and this endpoint used to disagree on all three operations: the UI
 * posted {items:[...]} while the handler read a newline-joined {ips:"..."} string,
 * and it sent DELETE where the handler only looked at POST — so adding, removing
 * and clearing all silently did nothing while still reporting success. Both call
 * shapes are accepted now so an older built frontend keeps working after an
 * update that only replaces the PHP files.
 */
/**
 * Template packs shipped as data files (data/keitaro_*.json).
 *
 * These are generated from the Keitaro exports rather than typed by hand: a macro
 * or postback URL invented from memory looks right in the dropdown and silently
 * tracks nothing. Regenerate the files instead of editing entries here.
 */
function orbitraLoadTemplatePack(string $file): array
{
    static $cache = [];
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $path = __DIR__ . '/data/' . $file;
    $rows = [];
    if (is_file($path) && is_readable($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $rows = $decoded;
        }
    }
    $cache[$file] = $rows;
    return $rows;
}

/**
 * Append a pack to the built-in list, skipping anything already shipped inline
 * (by name or display name) and keeping the "Custom ..." entry last.
 */
function orbitraMergeTemplates(array $builtin, array $pack): array
{
    $seen = [];
    foreach ($builtin as $tpl) {
        if (!empty($tpl['name'])) {
            $seen['n:' . mb_strtolower((string) $tpl['name'])] = true;
        }
        if (!empty($tpl['display_name'])) {
            $seen['d:' . mb_strtolower((string) $tpl['display_name'])] = true;
        }
    }
    foreach ($pack as $tpl) {
        if (!is_array($tpl) || empty($tpl['name'])) {
            continue;
        }
        $nKey = 'n:' . mb_strtolower((string) $tpl['name']);
        $dKey = 'd:' . mb_strtolower((string) ($tpl['display_name'] ?? ''));
        if (isset($seen[$nKey]) || (!empty($tpl['display_name']) && isset($seen[$dKey]))) {
            continue;
        }
        $seen[$nKey] = true;
        $seen[$dKey] = true;
        $builtin[] = $tpl;
    }
    // "Custom source" / "Custom network" stay at the bottom of the dropdown.
    $custom = [];
    $rest = [];
    foreach ($builtin as $tpl) {
        if (in_array((string) ($tpl['name'] ?? ''), ['custom', 'custom_network'], true)) {
            $custom[] = $tpl;
        } else {
            $rest[] = $tpl;
        }
    }
    return array_merge($rest, $custom);
}

function orbitraBotListEndpoint($pdo, $table, $column, $payloadKey)
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // Blacklists routinely run to tens of thousands of entries — public
        // datacenter ranges alone are that big — so the list is searched and
        // paged in SQL. Returning a flat first-1000 made everything past that
        // invisible and impossible to delete from the panel.
        $search = trim((string) ($_GET['search'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 200);
        $limit = max(1, min($limit, 1000));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $where = '';
        $args = [];
        if ($search !== '') {
            // Escape the LIKE wildcards so searching for "1.2.3.%" is literal.
            $where = " WHERE {$column} LIKE ? ESCAPE '\\'";
            $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }

        // "value" is a stable alias over the differently named columns
        // (ip_or_cidr / signature) that the panel renders.
        $stmt = $pdo->prepare(
            "SELECT *, {$column} AS value FROM {$table}{$where} ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($args, [$limit, $offset]));
        $rows = $stmt->fetchAll();

        $total = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
        if ($search === '') {
            $filtered = $total;
        } else {
            $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$table}{$where}");
            $countStmt->execute($args);
            $filtered = (int) $countStmt->fetch()['c'];
        }

        echo json_encode([
            'status' => 'success',
            'data' => $rows,
            'total' => $total,
            'filtered' => $filtered,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        return;
    }

    $data = json_decode(orbitraRequestBody(), true);
    if (!is_array($data)) {
        $data = [];
    }
    $op = $data['action'] ?? null;

    if ($op === 'clear_all' || !empty($data['clear_all'])) {
        $removed = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
        $pdo->exec("DELETE FROM {$table}");
        echo json_encode(['status' => 'success', 'removed' => $removed]);
        return;
    }

    if ($op === 'delete' || isset($data['id'])) {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing id']);
            return;
        }
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Entry not found']);
            return;
        }
        echo json_encode(['status' => 'success', 'removed' => 1]);
        return;
    }

    // Add. Accept either an array of entries or one newline-separated string.
    @set_time_limit(300);
    $raw = $data['items'] ?? $data[$payloadKey] ?? [];
    if (is_string($raw)) {
        $raw = preg_split('/\r\n|\r|\n/', $raw);
    }
    if (!is_array($raw)) {
        $raw = [];
    }

    $entries = [];
    foreach ($raw as $entry) {
        if (!is_scalar($entry)) {
            continue;
        }
        $entry = trim((string) $entry);
        if ($entry === '') {
            continue;
        }
        $entries[] = $entry;
    }

    $totalBefore = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
    
    // Perform insertions inside a single transaction with chunking for instant execution (100k+ rows)
    $pdo->beginTransaction();
    try {
        $chunks = array_chunk($entries, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?)'));
            $st = $pdo->prepare("INSERT OR IGNORE INTO {$table} ({$column}) VALUES {$placeholders}");
            $st->execute($chunk);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save bot entries: ' . $e->getMessage()]);
        return;
    }

    $totalAfter = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
    $added = max(0, $totalAfter - $totalBefore);
    $skipped = max(0, count($entries) - $added);

    echo json_encode([
        'status' => 'success',
        'added' => $added,
        'count' => $added,
        'skipped' => $skipped,
    ]);
}

// === NGINX AUTO-CONFIGURATION ===
/**
 * Check if a command exists on the system
 */
function command_exists($cmd) {
    // Goes through the shell helper: on a host where shell_exec has been removed
    // this used to raise "Call to undefined function orbitraShell()", and since it
    // is the first check most of these features make, that Error surfaced as a
    // bare 500 instead of "this server cannot run external commands".
    return orbitraCommandExists((string) $cmd);
}

/**
 * Rewrite /etc/nginx/sites-available/orbitra from the domains in the database.
 * Called after every domain add / edit / delete and after an SSL certificate lands.
 *
 * The generated config always keeps a catch-all server block, so the panel stays
 * reachable at the bare server IP however many domains are parked — and stays
 * reachable after the domain you always used gets deleted.
 */
function updateNginxConfig($pdo) {
    require_once __DIR__ . '/core/nginx_config.php';
    require_once __DIR__ . '/core/ssl_manager.php';

    // Every domain add, edit and delete passes through here, which makes it the
    // one place guaranteed to run when this tracker starts managing domains —
    // and therefore where the certificate worker gets scheduled. Idempotent and
    // silent when the environment does not allow touching the crontab.
    orbitraEnsureSslCron();

    return orbitraSyncNginx($pdo);
}

/**
 * Install SSL certificate for a single domain using Certbot
 * Tries synchronous first (user just clicked save), falls back to background
 */
function installSslForDomain($domain) {
    require_once __DIR__ . '/core/nginx_config.php';
    require_once __DIR__ . '/core/ssl_manager.php';

    // Saving a domain is the moment we know this tracker manages domains, so it
    // is the moment to make sure the retry worker is scheduled. Idempotent, and
    // silent when the environment does not allow it.
    orbitraEnsureSslCron();

    // Check if certbot is available
    if (!command_exists('certbot')) {
        return false;
    }

    // Check if SSL already exists for this domain
    $certPath = "/etc/letsencrypt/live/$domain/cert.pem";
    if (file_exists($certPath)) {
        return false; // Already has SSL
    }

    $cmd = orbitraCertbotCertonlyCommand($domain);

    // Try synchronous first (user just enabled HTTPS-only, they're waiting)
    $output = orbitraShell($cmd . ' 2>&1');

    if (orbitraCertbotSucceeded($output, $domain)) {
        // The certificate exists now, so regenerating the config adds the HTTPS
        // server block for it. Certbot no longer does this for us — on purpose.
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            updateNginxConfig($pdo);
        } else {
            orbitraShell('sudo systemctl reload nginx 2>&1');
        }
        return true;
    }

    // If failed or no output, retry in the background via the SSL installer,
    // which also records the error so the Domains page can show it.
    orbitraShell($cmd . ' > /dev/null 2>&1 &');

    return true;
}

/**
 * Queue SSL installation for domains with https_only enabled
 */
function queueSslInstallation($pdo, $domainId = null) {
    // Get domains that need SSL
    $sql = "SELECT name FROM domains WHERE https_only = 1 AND name IS NOT NULL AND name != ''";
    if ($domainId) {
        $sql .= " AND id = $domainId";
    }
    $stmt = $pdo->query($sql);
    $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $queued = 0;
    foreach ($domains as $domain) {
        if (installSslForDomain($domain)) {
            $queued++;
        }
    }

    return $queued;
}

function getDashboardFilters($prefix = '')
{
    global $dbTzOffset;

    $campaign_id = !empty($_GET['campaign_id']) ? (int) $_GET['campaign_id'] : null;
    $date_range = $_GET['date_range'] ?? 'all';
    $custom_from = !empty($_GET['custom_from']) ? $_GET['custom_from'] : null;
    $custom_to = !empty($_GET['custom_to']) ? $_GET['custom_to'] : null;

    $conditions = [];
    $params = [];

    if ($campaign_id) {
        $conditions[] = "{$prefix}campaign_id = ?";
        $params[] = $campaign_id;
    }

    $dateColumn = "{$prefix}created_at";

    switch ($date_range) {
        case 'today':
            $conditions[] = "date($dateColumn, '$dbTzOffset') = date('now', '$dbTzOffset')";
            break;
        case 'yesterday':
            $conditions[] = "date($dateColumn, '$dbTzOffset') = date('now', '-1 day', '$dbTzOffset')";
            break;
        case 'this_week':
            $conditions[] = "date($dateColumn, '$dbTzOffset') >= date('now', 'weekday 1', '-7 days', '$dbTzOffset')";
            break;
        case 'last_7_days':
            $conditions[] = "date($dateColumn, '$dbTzOffset') >= date('now', '-7 days', '$dbTzOffset')";
            break;
        case 'this_month':
            $conditions[] = "date($dateColumn, '$dbTzOffset') >= date('now', 'start of month', '$dbTzOffset')";
            break;
        case 'last_30_days':
            $conditions[] = "date($dateColumn, '$dbTzOffset') >= date('now', '-30 days', '$dbTzOffset')";
            break;
        case 'custom':
            if ($custom_from) {
                $conditions[] = "date($dateColumn, '$dbTzOffset') >= date(?)";
                $params[] = $custom_from;
            }
            if ($custom_to) {
                $conditions[] = "date($dateColumn, '$dbTzOffset') <= date(?)";
                $params[] = $custom_to;
            }
            break;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    return [$whereClause, $params];
}

/**
 * Check URL availability and return status
 * @param string $url The URL to check
 * @return array ['status' => string, 'message' => string]
 */
function checkUrlAvailability($url)
{
    // Ensure URL has a scheme before validation
    if (!empty($url) && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'error', 'message' => 'Invalid URL'];
    }

    // Parse URL and ensure it has a scheme (double-check)
    $parsed = parse_url($url);
    if (empty($parsed['scheme'])) {
        $url = 'https://' . $url;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Orbitra/1.0; +https://orbitra.io)',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($error) {
        if (strpos($error, 'timed out') !== false || strpos($error, 'timeout') !== false) {
            return ['status' => 'timeout', 'message' => 'Timeout'];
        }
        return ['status' => 'error', 'message' => $error];
    }

    if ($httpCode >= 200 && $httpCode < 400) {
        return ['status' => (string) $httpCode, 'message' => 'OK'];
    }

    return ['status' => (string) $httpCode, 'message' => getStatusMessage($httpCode)];
}

/**
 * Get human-readable status message for HTTP code
 */
function getStatusMessage($code)
{
    $messages = [
        200 => 'OK',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];
    return $messages[$code] ?? "HTTP $code";
}

function getTableColumns($pdo, $tableName)
{
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $cache[$tableName] = [];
    try {
        $stmt = $pdo->query("PRAGMA table_info($tableName)");
        if ($stmt) {
            $cache[$tableName] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
    } catch (\Exception $e) {
        $cache[$tableName] = [];
    }

    return $cache[$tableName];
}

function getRevenueRecordsValueColumn($pdo)
{
    static $column = false;
    if ($column !== false) {
        return $column;
    }

    $column = null;
    $columns = getTableColumns($pdo, 'revenue_records');
    if (in_array('amount', $columns, true)) {
        $column = 'amount';
    } elseif (in_array('revenue', $columns, true)) {
        // Backward compatibility for older schemas.
        $column = 'revenue';
    }

    return $column;
}

function getConversionsValueColumn($pdo)
{
    static $column = false;
    if ($column !== false) {
        return $column;
    }

    $column = null;
    $columns = getTableColumns($pdo, 'conversions');
    if (in_array('payout', $columns, true)) {
        $column = 'payout';
    } elseif (in_array('revenue', $columns, true)) {
        // Legacy schemas.
        $column = 'revenue';
    } elseif (in_array('amount', $columns, true)) {
        $column = 'amount';
    }

    return $column;
}

function normalizeBrowserLanguageCode($value)
{
    if (!is_string($value)) {
        return '';
    }

    $value = strtolower(trim($value));
    if ($value === '' || $value === '*') {
        return '';
    }

    $value = explode(';', $value)[0];
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $primary = preg_split('/[-_]/', $value)[0] ?? '';
    $primary = preg_replace('/[^a-z]/', '', $primary);
    if ($primary === '') {
        return '';
    }

    return $primary;
}

function extractBrowserLanguageCodes($headerValue)
{
    if (!is_string($headerValue)) {
        return [];
    }

    $result = [];
    foreach (explode(',', $headerValue) as $rawPart) {
        $normalized = normalizeBrowserLanguageCode($rawPart);
        if ($normalized === '') {
            continue;
        }
        if (!in_array($normalized, $result, true)) {
            $result[] = $normalized;
        }
    }

    return $result;
}

try {
    switch ($action) {
        case 'metrics':
            // 7 Stat Cards
            $metrics = [];

            list($whereCl, $paramsCl) = getDashboardFilters();
            $clicksStmt = $pdo->prepare("SELECT COUNT(id) as total_clicks, COUNT(DISTINCT ip) as unique_clicks FROM clicks $whereCl");
            $clicksStmt->execute($paramsCl);
            $clickData = $clicksStmt->fetch();
            $metrics['clicks'] = (int) $clickData['total_clicks'];
            $metrics['unique_clicks'] = (int) $clickData['unique_clicks'];
            list($whereClicksPrefixed, $paramsClicksPrefixed) = getDashboardFilters('clicks.');

            list($whereCv, $paramsCv) = getDashboardFilters();
            // Handle conversions specific join if campaign_id is provided, but conversions has click_id.
            // Wait, conversions doesn't have campaign_id natively. We just join clicks!
            $joinConv = "";
            if (!empty($_GET['campaign_id'])) {
                $joinConv = "LEFT JOIN clicks cl ON conversions.click_id = cl.id ";
                list($whereCv, $paramsCv) = getDashboardFilters('cl.');
            } else {
                list($whereCv, $paramsCv) = getDashboardFilters('');
            }

            $conversionsValueColumn = getConversionsValueColumn($pdo);
            $conversionRevenueSumExpression = $conversionsValueColumn !== null
                ? "COALESCE(SUM(conversions.$conversionsValueColumn), 0)"
                : "0";
            $convStmt = $pdo->prepare("SELECT COUNT(conversions.id) as total_conversions, $conversionRevenueSumExpression as total_revenue FROM conversions $joinConv $whereCv");
            $convStmt->execute($paramsCv);
            $convData = $convStmt->fetch();
            $metrics['conversions'] = (int) $convData['total_conversions'];
            $metrics['revenue'] = (float) $convData['total_revenue'];

            // Расход (в этой модели у нас нет cost, для красоты ставим 0 или моковые данные)
            $metrics['cost'] = 0.00;

            // Прибыль
            $metrics['profit'] = $metrics['revenue'] - $metrics['cost'];

            // ROI = (Profit / Cost) * 100
            $metrics['roi'] = $metrics['cost'] > 0 ? round(($metrics['profit'] / $metrics['cost']) * 100, 2) : ($metrics['profit'] > 0 ? 100 : 0);

            // Real Revenue
            $metrics['real_revenue'] = 0.0;
            $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);
            if ($revenueRecordsValueColumn !== null) {
                $rrStmt = $pdo->prepare("SELECT COALESCE(SUM(rr.$revenueRecordsValueColumn), 0) as real_rev FROM revenue_records rr JOIN clicks ON rr.click_id = clicks.id $whereClicksPrefixed");
                $rrStmt->execute($paramsClicksPrefixed);
                $metrics['real_revenue'] = (float) $rrStmt->fetch()['real_rev'];
            }
            $real_profit = $metrics['real_revenue'] - $metrics['cost'];
            $metrics['real_roi'] = $metrics['cost'] > 0 ? round(($real_profit / $metrics['cost']) * 100, 2) : ($real_profit > 0 ? 100 : 0);

            // CTR Placeholder
            $metrics['ctr'] = 100; // Simplified, typically needs impressions

            echo json_encode(['status' => 'success', 'data' => $metrics, 'server_time' => date('H:i:s')]);
            break;

        case 'chart':
            list($whereCl, $paramsCl) = getDashboardFilters('');

            // Determine if the range is a single day (from GET params)
            $isSingleDay = false;
            if (isset($_GET['date_range'])) {
                if ($_GET['date_range'] === 'today' || $_GET['date_range'] === 'yesterday') {
                    $isSingleDay = true;
                } else if ($_GET['date_range'] === 'custom' && !empty($_GET['custom_from']) && !empty($_GET['custom_to'])) {
                    if ($_GET['custom_from'] === $_GET['custom_to']) {
                        $isSingleDay = true;
                    }
                }
            }

            // SQLite datetime formatting string
            $timeFormat = $isSingleDay ? "'%Y-%m-%d %H:00:00'" : "'%Y-%m-%d'";

            $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);
            $realRevenueExpression = "0";
            if ($revenueRecordsValueColumn !== null) {
                $realRevenueExpression = "COALESCE((SELECT SUM($revenueRecordsValueColumn) FROM revenue_records rr WHERE rr.click_id = clicks.id), 0)";
            }
            $conversionsValueColumn = getConversionsValueColumn($pdo);
            $conversionRevenueExpression = "0";
            if ($conversionsValueColumn !== null) {
                $conversionRevenueExpression = "COALESCE((SELECT SUM($conversionsValueColumn) FROM conversions WHERE conversions.click_id = clicks.id), 0)";
            }

            $chartQuery = "
                SELECT period, 
                       COUNT(id) as clicks, 
                       COUNT(DISTINCT ip) as unique_clicks,
                       SUM(is_conversion) as conversions,
                       SUM(revenue) as revenue,
                       SUM(real_revenue) as real_revenue
                FROM (
                    SELECT strftime($timeFormat, clicks.created_at, '$dbTzOffset') as period, 
                           clicks.id,
                           clicks.ip,
                           clicks.is_conversion,
                           $conversionRevenueExpression as revenue,
                           $realRevenueExpression as real_revenue
                    FROM clicks 
                    $whereCl
                )
                GROUP BY period
                ORDER BY period ASC 
                LIMIT 100
            ";

            $chartStmt = $pdo->prepare($chartQuery);
            $chartStmt->execute($paramsCl);
            $chartData = $chartStmt->fetchAll();

            $labels = [];
            $clicks = [];
            $unique_clicks = [];
            $conversions = [];
            $revenue = [];
            $cost = [];
            $profit = [];
            $roi = [];
            $real_revenue = [];
            $real_roi = [];
            $ctr = [];

            // If it's a single day, pre-fill all 24 hours with zeros to ensure the chart always shows 0:00 to 23:00
            if ($isSingleDay) {
                // Determine the base date string (e.g., '2023-10-27') from either the first result or today
                $baseDate = date('Y-m-d');
                if (isset($_GET['date_range']) && $_GET['date_range'] === 'yesterday') {
                    $baseDate = date('Y-m-d', strtotime('-1 day'));
                } else if (isset($_GET['date_range']) && $_GET['date_range'] === 'custom' && !empty($_GET['custom_from'])) {
                    $baseDate = $_GET['custom_from'];
                }

                $hourlyData = [];
                // Always show the full day timeline (00:00..23:00) on X axis.
                // Future hours will stay zero until events appear.
                $maxHour = 23;

                for ($i = 0; $i <= $maxHour; $i++) {
                    $hourStr = str_pad($i, 2, '0', STR_PAD_LEFT);
                    $key = "$baseDate $hourStr:00:00";
                    $hourlyData[$key] = [
                        'clicks' => 0,
                        'unique_clicks' => 0,
                        'conversions' => 0,
                        'revenue' => 0.0,
                        'cost' => 0.0,
                        'profit' => 0.0,
                        'roi' => 0.0,
                        'real_revenue' => 0.0,
                        'real_roi' => 0.0,
                        'ctr' => 100
                    ];
                }

                foreach ($chartData as $row) {
                    $period = $row['period'] ?? ''; // format is 'YYYY-MM-DD HH:00:00'
                    if ($period !== '' && isset($hourlyData[$period])) {
                        $hourlyData[$period]['clicks'] = (int) $row['clicks'];
                        $hourlyData[$period]['unique_clicks'] = (int) $row['unique_clicks'];
                        $hourlyData[$period]['conversions'] = (int) $row['conversions'];
                        $hourlyData[$period]['revenue'] = (float) $row['revenue'];
                        $hourlyData[$period]['cost'] = 0.0; // Mocked cost if DB has no cost column yet
                        $hourlyData[$period]['profit'] = $hourlyData[$period]['revenue'] - $hourlyData[$period]['cost'];
                        $hourlyData[$period]['roi'] = $hourlyData[$period]['cost'] > 0 ? round(($hourlyData[$period]['profit'] / $hourlyData[$period]['cost']) * 100, 2) : ($hourlyData[$period]['profit'] > 0 ? 100 : 0);

                        $hourlyData[$period]['real_revenue'] = (float) $row['real_revenue'];
                        $real_profit = $hourlyData[$period]['real_revenue'] - $hourlyData[$period]['cost'];
                        $hourlyData[$period]['real_roi'] = $hourlyData[$period]['cost'] > 0 ? round(($real_profit / $hourlyData[$period]['cost']) * 100, 2) : ($real_profit > 0 ? 100 : 0);
                        $hourlyData[$period]['ctr'] = 100; // Simplified
                    }
                }

                foreach ($hourlyData as $period => $data) {
                    $hourOnly = date('H:00', strtotime($period));
                    $labels[] = $hourOnly;
                    $clicks[] = $data['clicks'];
                    $unique_clicks[] = $data['unique_clicks'];
                    $conversions[] = $data['conversions'];
                    $revenue[] = $data['revenue'];
                    $cost[] = $data['cost'];
                    $profit[] = $data['profit'];
                    $roi[] = $data['roi'];
                    $real_revenue[] = $data['real_revenue'];
                    $real_roi[] = $data['real_roi'];
                    $ctr[] = $data['ctr'];
                }
            } else {
                // Standard multi-day grouping with dynamic formatting based on date_range
                $dateRange = $_GET['date_range'] ?? 'this_month';

                $daysMap = [
                    'Mon' => 'Пн',
                    'Tue' => 'Вт',
                    'Wed' => 'Ср',
                    'Thu' => 'Чт',
                    'Fri' => 'Пт',
                    'Sat' => 'Сб',
                    'Sun' => 'Вс'
                ];
                $monthsMap = [
                    '01' => 'Янв',
                    '02' => 'Фев',
                    '03' => 'Мар',
                    '04' => 'Апр',
                    '05' => 'Май',
                    '06' => 'Июн',
                    '07' => 'Июл',
                    '08' => 'Авг',
                    '09' => 'Сен',
                    '10' => 'Окт',
                    '11' => 'Ноя',
                    '12' => 'Дек'
                ];

                // Determine start and end dates to zero-fill the gaps
                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d');
                $step = '+1 day';
                $formatKey = 'Y-m-d';
                $isYear = false;

                if ($dateRange === 'this_week') {
                    $startDate = date('Y-m-d', strtotime('monday this week'));
                    $endDate = date('Y-m-d', strtotime('sunday this week'));
                } else if ($dateRange === 'last_7_days') {
                    $startDate = date('Y-m-d', strtotime('-6 days'));
                    $endDate = date('Y-m-d');
                } else if ($dateRange === 'last_30_days') {
                    $startDate = date('Y-m-d', strtotime('-29 days'));
                    $endDate = date('Y-m-d');
                } else if ($dateRange === 'this_month') {
                    $startDate = date('Y-m-01');
                    $endDate = date('Y-m-t');
                } else if ($dateRange === 'last_month') {
                    $startDate = date('Y-m-01', strtotime('first day of last month'));
                    $endDate = date('Y-m-t', strtotime('last day of last month'));
                } else if ($dateRange === 'this_year') {
                    $startDate = date('Y-01-01');
                    $endDate = date('Y-12-31');
                    $step = '+1 month';
                    $formatKey = 'Y-m';
                    $isYear = true;
                } else if ($dateRange === 'custom') {
                    $startDate = $_GET['custom_from'] ?? date('Y-m-d');
                    $endDate = $_GET['custom_to'] ?? date('Y-m-d');
                }

                // Zero-fill the array
                $dailyData = [];
                $currentDateStr = $startDate;

                // Safety limiter for custom ranges to prevent infinite loops
                $maxIterations = 366;
                $i = 0;

                while (strtotime($currentDateStr) <= strtotime($endDate) && $i < $maxIterations) {
                    $key = $isYear ? date('Y-m', strtotime($currentDateStr)) : $currentDateStr;
                    $dailyData[$key] = [
                        'clicks' => 0,
                        'unique_clicks' => 0,
                        'conversions' => 0,
                        'revenue' => 0.0,
                        'cost' => 0.0,
                        'profit' => 0.0,
                        'roi' => 0.0,
                        'real_revenue' => 0.0,
                        'real_roi' => 0.0,
                        'ctr' => 100,
                        'raw_date' => $currentDateStr
                    ];
                    $currentDateStr = date($isYear ? 'Y-m-d' : 'Y-m-d', strtotime($currentDateStr . " $step"));
                    $i++;
                }

                // Populate with DB data
                foreach ($chartData as $row) {
                    $rawDate = $row['period']; // YYYY-MM-DD
                    $key = $isYear ? date('Y-m', strtotime($rawDate)) : $rawDate;

                    if (isset($dailyData[$key])) {
                        $dailyData[$key]['clicks'] = (int) $row['clicks'];
                        $dailyData[$key]['unique_clicks'] = (int) $row['unique_clicks'];
                        $dailyData[$key]['conversions'] = (int) $row['conversions'];
                        $dailyData[$key]['revenue'] = (float) $row['revenue'];
                        $dailyData[$key]['cost'] = 0.0;
                        $dailyData[$key]['profit'] = $dailyData[$key]['revenue'] - $dailyData[$key]['cost'];
                        $dailyData[$key]['roi'] = $dailyData[$key]['cost'] > 0 ? round(($dailyData[$key]['profit'] / $dailyData[$key]['cost']) * 100, 2) : ($dailyData[$key]['profit'] > 0 ? 100 : 0);

                        $dailyData[$key]['real_revenue'] = (float) $row['real_revenue'];
                        $real_profit = $dailyData[$key]['real_revenue'] - $dailyData[$key]['cost'];
                        $dailyData[$key]['real_roi'] = $dailyData[$key]['cost'] > 0 ? round(($real_profit / $dailyData[$key]['cost']) * 100, 2) : ($real_profit > 0 ? 100 : 0);
                        $dailyData[$key]['ctr'] = 100; // Simplified
                    }
                }

                // Format the output
                foreach ($dailyData as $key => $data) {
                    $rawDate = $data['raw_date'];
                    $formattedLabel = $rawDate;

                    if ($dateRange === 'this_week') {
                        $dayEng = date('D', strtotime($rawDate));
                        $formattedLabel = $daysMap[$dayEng] ?? $dayEng;
                    } else if ($dateRange === 'this_month' || $dateRange === 'last_month') {
                        $formattedLabel = date('d', strtotime($rawDate));
                    } else if ($dateRange === 'last_7_days' || $dateRange === 'last_30_days') {
                        $formattedLabel = date('d.m', strtotime($rawDate));
                    } else if ($dateRange === 'this_year') {
                        $monthNum = date('m', strtotime($rawDate));
                        $formattedLabel = $monthsMap[$monthNum] ?? $monthNum;
                    } else {
                        $formattedLabel = date('d.m', strtotime($rawDate));
                    }

                    $labels[] = $formattedLabel;
                    $clicks[] = $data['clicks'];
                    $unique_clicks[] = $data['unique_clicks'];
                    $conversions[] = $data['conversions'];
                    $revenue[] = $data['revenue'];
                    $cost[] = $data['cost'];
                    $profit[] = $data['profit'];
                    $roi[] = $data['roi'];
                    $real_revenue[] = $data['real_revenue'];
                    $real_roi[] = $data['real_roi'];
                    $ctr[] = $data['ctr'];
                }
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'labels' => $labels,
                    'datasets' => [
                        ['label' => 'clicks', 'data' => $clicks],
                        ['label' => 'unique_clicks', 'data' => $unique_clicks],
                        ['label' => 'conversions', 'data' => $conversions],
                        ['label' => 'cost', 'data' => $cost],
                        ['label' => 'revenue', 'data' => $revenue],
                        ['label' => 'profit', 'data' => $profit],
                        ['label' => 'roi', 'data' => $roi],
                        ['label' => 'real_revenue', 'data' => $real_revenue],
                        ['label' => 'real_roi', 'data' => $real_roi],
                        ['label' => 'ctr', 'data' => $ctr],
                    ]
                ]
            ]);
            break;

        case 'campaigns':
            $date_from = $_GET['date_from'] ?? null;
            $date_to = $_GET['date_to'] ?? null;
            $group_id = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int) $_GET['group_id'] : null;

            // Clicks are filtered inside the JOIN so campaigns without traffic still
            // appear in the list. Dates are compared in the report timezone, the same
            // way getDashboardFilters() does it.
            $paramsCl = [];
            $joinConds = [];
            if ($date_from) {
                $joinConds[] = "date(cl.created_at, '$dbTzOffset') >= date(?)";
                $paramsCl[] = $date_from;
            }
            if ($date_to) {
                $joinConds[] = "date(cl.created_at, '$dbTzOffset') <= date(?)";
                $paramsCl[] = $date_to;
            }

            if (empty($date_from) && empty($date_to)) {
                // No explicit range: fall back to the dashboard's own filter set.
                list($whereCl, $dashboardParams) = getDashboardFilters('cl.');
                $joinCondition = !empty($whereCl) ? str_replace('WHERE ', 'AND ', $whereCl) : '';
                $paramsCl = $dashboardParams;
            } else {
                $joinCondition = $joinConds ? 'AND ' . implode(' AND ', $joinConds) : '';
            }

            // Cast to int and inlined rather than bound: the branch above owns the
            // parameter list, and a stray placeholder here used to make the whole
            // endpoint fail with "number of bound variables does not match".
            $groupWhere = ($group_id !== null && $group_id > 0) ? " AND c.group_id = $group_id" : '';

            $convAggSql = orbitraConversionAggregateSql(getConversionsValueColumn($pdo));
            $revRecordsCol = getRevenueRecordsValueColumn($pdo);
            $realJoin = $revRecordsCol !== null
                ? 'LEFT JOIN ' . orbitraRevenueRecordsAggregateSql($revRecordsCol) . ' rr ON rr.click_id = cl.id'
                : '';
            $realRevSelect = $revRecordsCol !== null ? 'COALESCE(SUM(rr.real_rev), 0)' : '0';

            $limitClause = isset($_GET['limit']) ? "LIMIT " . (int) $_GET['limit'] : "";
            $havingClause = isset($_GET['limit']) ? "HAVING clicks > 0" : "";

            $stmt = $pdo->prepare("
                SELECT c.*, 
                       cg.name as group_name,
                       ts.name as source_name,
                       COUNT(cl.id) as clicks,
                       SUM(cl.uniq_campaign) as unique_clicks,
                       SUM(cl.uniq_stream) as unique_clicks_stream,
                       SUM(cl.uniq_global) as unique_clicks_global,
                       SUM(cl.uniq_global) as visitors,
                       SUM(cl.is_bot) as bots,
                       SUM(cl.is_proxy) as proxies,
                       SUM(CASE WHEN cl.referer IS NULL OR cl.referer = '' THEN 1 ELSE 0 END) as empty_referrers,
                       AVG(CASE WHEN cl.landing_at IS NOT NULL AND cl.offer_at IS NOT NULL
                           THEN CAST(strftime('%s', cl.offer_at) - strftime('%s', cl.landing_at) AS REAL) END) as avg_lp_seconds,
                       SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 THEN 1 ELSE 0 END) as prelander_clicks,
                       SUM(CASE WHEN cl.offer_id IS NOT NULL AND cl.offer_id > 0 THEN 1 ELSE 0 END) as offer_clicks,
                       SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 AND cl.offer_id IS NOT NULL AND cl.offer_id > 0 THEN 1 ELSE 0 END) as lp_clicks,
                       COALESCE(SUM(cv.cnt_any), 0) as conversions,
                       COALESCE(SUM(cv.cnt_sale), 0) as purchases,
                       COALESCE(SUM(cv.cnt_hold), 0) as holds,
                       COALESCE(SUM(cv.cnt_rejected), 0) as rejected,
                       COALESCE(SUM(cv.cnt_trash), 0) as trash,
                       COALESCE(SUM(cv.cnt_registration), 0) as registrations,
                       COALESCE(SUM(cv.cnt_deposit), 0) as deposits,
                       COALESCE(SUM(cl.cost), 0) as cost,
                       COALESCE(SUM(cv.rev_all), 0) as revenue,
                       COALESCE(SUM(cv.rev_sale), 0) as revenue_confirmed,
                       COALESCE(SUM(cv.rev_hold), 0) as revenue_hold,
                       COALESCE(SUM(cv.rev_rejected), 0) as revenue_rejected,
                       COALESCE(SUM(cv.rev_trash), 0) as revenue_trash,
                       COALESCE(SUM(cv.rev_registration), 0) as revenue_registration,
                       COALESCE(SUM(cv.rev_deposit), 0) as revenue_deposit,
                       $realRevSelect as real_revenue
                FROM campaigns c
                LEFT JOIN campaign_groups cg ON c.group_id = cg.id
                LEFT JOIN traffic_sources ts ON c.source_id = ts.id
                LEFT JOIN clicks cl ON c.id = cl.campaign_id $joinCondition
                LEFT JOIN $convAggSql cv ON cv.click_id = cl.id
                $realJoin
                WHERE c.is_archived = 0 $groupWhere
                GROUP BY c.id
                $havingClause
                ORDER BY clicks DESC, c.created_at DESC
                $limitClause
            ");
            $stmt->execute($paramsCl);

            $formattedCampaigns = [];
            foreach ($stmt->fetchAll() as $r) {
                $formattedCampaigns[] = array_merge($r, orbitraComputeDerivedMetrics($r));
            }

            echo json_encode(['status' => 'success', 'data' => $formattedCampaigns]);
            break;

        // Optimized campaigns list without heavy clicks JOIN (for dropdowns/quick loading)
        case 'campaigns_simple':
            $stmt = $pdo->query("
                SELECT c.id, c.name, c.alias, c.state, c.type,
                       cg.name as group_name,
                       ts.name as source_name,
                       d.name as domain_name
                FROM campaigns c
                LEFT JOIN campaign_groups cg ON c.group_id = cg.id
                LEFT JOIN traffic_sources ts ON c.source_id = ts.id
                LEFT JOIN domains d ON c.domain_id = d.id
                WHERE c.is_archived = 0
                ORDER BY c.created_at DESC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        // Optimized offers list without heavy clicks JOIN (for dropdowns/quick loading)
        case 'offers_simple':
            $stmt = $pdo->query("
                SELECT o.id, o.name, o.url, o.state, o.payout_type, o.payout_value,
                       o.geo, an.name as network_name
                FROM offers o
                LEFT JOIN affiliate_networks an ON o.affiliate_network_id = an.id
                WHERE o.is_archived = 0
                ORDER BY o.name ASC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        case 'get_campaign':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();

            if (!$campaign) {
                echo json_encode(['status' => 'error', 'message' => 'Not found']);
                break;
            }

            $stmtStr = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? ORDER BY position ASC, id ASC");
            $stmtStr->execute([$id]);
            $campaign['streams'] = $stmtStr->fetchAll();

            $stmtPb = $pdo->prepare("SELECT * FROM campaign_postbacks WHERE campaign_id = ?");
            $stmtPb->execute([$id]);
            $campaign['postbacks'] = $stmtPb->fetchAll();

            // URL parameters are edited as a plain map in the editor; decode the
            // stored blob so the "Параметры" tab survives a save/reopen cycle.
            $campaign['parameters'] = [];
            if (!empty($campaign['parameters_json'])) {
                $decoded = json_decode($campaign['parameters_json'], true);
                if (is_array($decoded)) {
                    $campaign['parameters'] = $decoded;
                }
            }
            unset($campaign['parameters_json']);

            echo json_encode(['status' => 'success', 'data' => $campaign]);
            break;

        case 'campaign_cost_match':
            // Cost-sync diagnostics for one campaign: does its recent traffic
            // carry the ad-network IDs cost import matches on? This is the
            // "why don't my costs attach" answer, computed instead of guessed.
            $cmId = (int) ($_GET['campaign_id'] ?? 0);
            if ($cmId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign_id']);
                break;
            }
            try {
                $stmtCm = $pdo->prepare("
                    SELECT
                        COUNT(*) AS clicks7d,
                        COALESCE(SUM(CASE WHEN json_extract(parameters_json, '\$.ad_id') IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_ad_id,
                        COALESCE(SUM(CASE WHEN json_extract(parameters_json, '\$.adset_id') IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_adset_id,
                        COALESCE(SUM(CASE WHEN json_extract(parameters_json, '\$.campaign_id') IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_campaign_id
                    FROM clicks
                    WHERE campaign_id = ? AND created_at >= datetime('now', '-7 days')
                ");
                $stmtCm->execute([$cmId]);
                $rowCm = $stmtCm->fetch(PDO::FETCH_ASSOC) ?: [];
                $stmtSrc = $pdo->prepare("
                    SELECT ts.name, ts.parameters_json
                    FROM campaigns c LEFT JOIN traffic_sources ts ON ts.id = c.source_id
                    WHERE c.id = ? LIMIT 1
                ");
                $stmtSrc->execute([$cmId]);
                $srcCm = $stmtSrc->fetch(PDO::FETCH_ASSOC) ?: null;
                echo json_encode(['status' => 'success', 'data' => [
                    'clicks7d' => (int) ($rowCm['clicks7d'] ?? 0),
                    'with_ad_id' => (int) ($rowCm['with_ad_id'] ?? 0),
                    'with_adset_id' => (int) ($rowCm['with_adset_id'] ?? 0),
                    'with_campaign_id' => (int) ($rowCm['with_campaign_id'] ?? 0),
                    'source_name' => $srcCm['name'] ?? null,
                ]]);
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'save_campaign':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $alias = $data['alias'] ?? '';
                $domainId = !empty($data['domain_id']) ? (int) $data['domain_id'] : null;
                $groupId = !empty($data['group_id']) ? (int) $data['group_id'] : null;
                $sourceId = !empty($data['source_id']) ? (int) $data['source_id'] : null;
                $costModel = $data['cost_model'] ?? 'CPC';
                $costValue = !empty($data['cost_value']) ? (float) $data['cost_value'] : 0.00;
                $uniquenessMethod = $data['uniqueness_method'] ?? 'IP';
                $uniquenessHours = !empty($data['uniqueness_hours']) ? (int) $data['uniqueness_hours'] : 24;
                $rotationType = isset($data['rotation_type']) ? trim((string) $data['rotation_type']) : '';
                if ($rotationType !== 'weight' && $rotationType !== 'position') {
                    // Keep default consistent across DB/UI/router.
                    $rotationType = 'position';
                }
                // Click API token (Keitaro-compatible). Important: if the client doesn't send it,
                // we must NOT overwrite an existing token with NULL/empty.
                $tokenProvided = is_array($data) && array_key_exists('token', $data);
                $token = null;
                if ($tokenProvided) {
                    $token = trim((string) ($data['token'] ?? ''));
                    if ($token === '') {
                        $token = null;
                    }
                }
                $catch404StreamId = !empty($data['catch_404_stream_id']) ? (int) $data['catch_404_stream_id'] : null;
                $challengeType = isset($data['challenge_type']) ? trim((string) $data['challenge_type']) : 'none';
                $challengeCustomCode = isset($data['challenge_custom_code']) ? (string) $data['challenge_custom_code'] : null;

                $streams = $data['streams'] ?? [];
                $postbacks = $data['postbacks'] ?? [];

                if (!$name || !$alias) {
                    echo json_encode(['status' => 'error', 'message' => 'Name and Alias are required']);
                    break;
                }

                try {
                    $pdo->beginTransaction();

                    // Generate token for Click API if missing (Keitaro-style: 32 chars).
                    $generateCampaignToken = function () use ($pdo): string {
                        $stmtTokExists = $pdo->prepare("SELECT id FROM campaigns WHERE token = ? LIMIT 1");
                        for ($i = 0; $i < 30; $i++) {
                            $cand = bin2hex(random_bytes(16));
                            $stmtTokExists->execute([$cand]);
                            if (!$stmtTokExists->fetchColumn()) {
                                return $cand;
                            }
                        }
                        return bin2hex(random_bytes(16));
                    };

                    if ($id) {
                        if ($tokenProvided) {
                            $stmt = $pdo->prepare("
                                UPDATE campaigns 
                                SET name=?, alias=?, domain_id=?, group_id=?, source_id=?, 
                                    cost_model=?, cost_value=?, uniqueness_method=?, uniqueness_hours=?, 
                                    rotation_type=?, token=?, catch_404_stream_id=?,
                                    challenge_type=?, challenge_custom_code=?
                                WHERE id=?
                            ");
                            $stmt->execute([
                                $name,
                                $alias,
                                $domainId,
                                $groupId,
                                $sourceId,
                                $costModel,
                                $costValue,
                                $uniquenessMethod,
                                $uniquenessHours,
                                $rotationType,
                                $token,
                                $catch404StreamId,
                                $challengeType,
                                $challengeCustomCode,
                                $id
                            ]);
                        } else {
                            // Don't wipe token if UI doesn't include it.
                            $stmt = $pdo->prepare("
                                UPDATE campaigns 
                                SET name=?, alias=?, domain_id=?, group_id=?, source_id=?, 
                                    cost_model=?, cost_value=?, uniqueness_method=?, uniqueness_hours=?, 
                                    rotation_type=?, catch_404_stream_id=?,
                                    challenge_type=?, challenge_custom_code=?
                                WHERE id=?
                            ");
                            $stmt->execute([
                                $name,
                                $alias,
                                $domainId,
                                $groupId,
                                $sourceId,
                                $costModel,
                                $costValue,
                                $uniquenessMethod,
                                $uniquenessHours,
                                $rotationType,
                                $catch404StreamId,
                                $challengeType,
                                $challengeCustomCode,
                                $id
                            ]);
                        }
                    } else {
                        if ($token === null) {
                            $token = $generateCampaignToken();
                        }
                        $stmt = $pdo->prepare("
                            INSERT INTO campaigns 
                            (name, alias, domain_id, group_id, source_id, cost_model, cost_value, uniqueness_method, uniqueness_hours, rotation_type, token, catch_404_stream_id, challenge_type, challenge_custom_code)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $name,
                            $alias,
                            $domainId,
                            $groupId,
                            $sourceId,
                            $costModel,
                            $costValue,
                            $uniquenessMethod,
                            $uniquenessHours,
                            $rotationType,
                            $token,
                            $catch404StreamId,
                            $challengeType,
                            $challengeCustomCode
                        ]);
                        $id = $pdo->lastInsertId();
                    }

                    // Backfill token for older campaigns where it may be NULL/empty.
                    $pdo->prepare("UPDATE campaigns SET token = ? WHERE id = ? AND (token IS NULL OR token = '')")
                        ->execute([$generateCampaignToken(), (int) $id]);

                    // Persist the URL-parameter map only when the client sent one,
                    // so older API clients can't wipe it with an absent field.
                    if (is_array($data['parameters'] ?? null)) {
                        $pdo->prepare("UPDATE campaigns SET parameters_json = ? WHERE id = ?")
                            ->execute([json_encode($data['parameters'], JSON_UNESCAPED_UNICODE), (int) $id]);
                    }

                    // For MVP: delete old streams and insert new ones. The name
                    // column was missing from this INSERT, so every save silently
                    // wiped the stream names the editor had just collected.
                    $pdo->prepare("DELETE FROM streams WHERE campaign_id = ?")->execute([$id]);

                    $stmtStream = $pdo->prepare("
                        INSERT INTO streams (campaign_id, offer_id, weight, is_active, type, position, filters_json, filters_logic, schema_type, action_payload, schema_custom_json, offer_selection, name)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($streams as $str) {
                        // Convert offer_id = 0 to NULL to avoid FOREIGN KEY constraint error
                        $offerId = !empty($str['offer_id']) ? (int) $str['offer_id'] : null;

                        $stmtStream->execute([
                            $id,
                            $offerId,
                            $str['weight'] ?? 100,
                            $str['is_active'] ?? 1,
                            $str['type'] ?? 'regular',
                            $str['position'] ?? 0,
                            json_encode($str['filters'] ?? []),
                            (($str['filters_logic'] ?? 'and') === 'or') ? 'or' : 'and',
                            $str['schema_type'] ?? 'redirect',
                            $str['action_payload'] ?? '',
                            json_encode($str['schema_custom'] ?? []),
                            in_array($str['offer_selection'] ?? 'before', ['before', 'after'], true)
                                ? $str['offer_selection']
                                : 'before',
                            $str['name'] ?? null,
                        ]);
                    }

                    // Delete and update postbacks
                    $pdo->prepare("DELETE FROM campaign_postbacks WHERE campaign_id = ?")->execute([$id]);
                    $stmtPb = $pdo->prepare("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses) VALUES (?, ?, ?, ?)");
                    foreach ($postbacks as $pb) {
                        if (!empty($pb['url'])) {
                            $stmtPb->execute([
                                $id,
                                $pb['url'],
                                $pb['method'] ?? 'GET',
                                $pb['statuses'] ?? 'lead,sale,rejected'
                            ]);
                        }
                    }

                    $pdo->commit();
                    $stmtTokOut = $pdo->prepare("SELECT token FROM campaigns WHERE id = ? LIMIT 1");
                    $stmtTokOut->execute([(int) $id]);
                    $tokenOut = $stmtTokOut->fetchColumn();
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id, 'token' => $tokenOut, 'rotation_type' => $rotationType]]);
                } catch (\Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'regenerate_campaign_token':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $campaignId = (int) ($data['campaign_id'] ?? ($data['id'] ?? 0));
            if ($campaignId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign_id']);
                break;
            }
            try {
                $stmtFind = $pdo->prepare("SELECT id FROM campaigns WHERE id = ? LIMIT 1");
                $stmtFind->execute([$campaignId]);
                if (!$stmtFind->fetchColumn()) {
                    echo json_encode(['status' => 'error', 'message' => 'Campaign not found']);
                    break;
                }

                $stmtTokExists = $pdo->prepare("SELECT id FROM campaigns WHERE token = ? LIMIT 1");
                $newToken = null;
                for ($i = 0; $i < 30; $i++) {
                    $cand = bin2hex(random_bytes(16));
                    $stmtTokExists->execute([$cand]);
                    if (!$stmtTokExists->fetchColumn()) {
                        $newToken = $cand;
                        break;
                    }
                }
                if (!$newToken) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to generate unique token']);
                    break;
                }
                $pdo->prepare("UPDATE campaigns SET token = ? WHERE id = ?")->execute([$newToken, $campaignId]);
                echo json_encode(['status' => 'success', 'data' => ['campaign_id' => $campaignId, 'token' => $newToken]]);
            } catch (Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'delete_campaign':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                if (!empty($data['id'])) {
                    $pdo->prepare("UPDATE campaigns SET is_archived = 1, archived_at = datetime('now') WHERE id = ?")->execute([$data['id']]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                }
            }
            break;

        case 'bulk_delete_campaigns':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'ids must be an array']);
                break;
            }
            $ids = array_values(array_unique(array_filter(array_map(function ($v) {
                return (int) $v;
            }, $ids), function ($v) {
                return $v > 0;
            })));
            if (empty($ids)) {
                echo json_encode(['status' => 'success', 'data' => ['updated' => 0]]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE campaigns SET is_archived = 1, archived_at = datetime('now') WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $updated = $stmt->rowCount();
                $pdo->commit();
                logAudit($pdo, 'DELETE', 'Campaigns (bulk)', null, ['ids' => $ids, 'updated' => $updated]);
                echo json_encode(['status' => 'success', 'data' => ['updated' => $updated]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'copy_campaign':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;

                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'ID не передан']);
                    break;
                }

                try {
                    $pdo->beginTransaction();

                    // Get original campaign
                    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
                    $stmt->execute([$id]);
                    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$campaign) {
                        echo json_encode(['status' => 'error', 'message' => 'Кампания не найдена']);
                        break;
                    }

                    // Find next copy number
                    $baseName = preg_replace('/^Copy #\d+ /', '', $campaign['name']);
                    $stmt = $pdo->prepare("SELECT name FROM campaigns WHERE name LIKE ?");
                    $stmt->execute(["Copy %"]);
                    $existingCopies = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $copyNum = 1;
                    while (in_array("Copy #$copyNum $baseName", $existingCopies)) {
                        $copyNum++;
                    }
                    $newName = "Copy #$copyNum $baseName";

                    // Generate random alias like when creating new campaign (8 chars: a-z0-9, like Keitaro)
                    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                    $newAlias = '';
                    for ($i = 0; $i < 8; $i++) {
                        $newAlias .= $chars[random_int(0, strlen($chars) - 1)];
                    }

                    // Check for uniqueness and regenerate if needed (max 30 attempts)
                    $aliasAttempts = 0;
                    while ($aliasAttempts < 30) {
                        $stmt = $pdo->prepare("SELECT id FROM campaigns WHERE alias = ?");
                        $stmt->execute([$newAlias]);
                        if (!$stmt->fetch()) {
                            break; // Alias is unique
                        }
                        // Regenerate
                        $newAlias = '';
                        for ($i = 0; $i < 8; $i++) {
                            $newAlias .= $chars[random_int(0, strlen($chars) - 1)];
                        }
                        $aliasAttempts++;
                    }

                    if ($aliasAttempts >= 30) {
                        throw new Exception('Не удалось сгенерировать уникальный alias');
                    }

                    // Generate new token
                    $newToken = bin2hex(random_bytes(16));

                    // Insert new campaign
                    $stmt = $pdo->prepare("
                        INSERT INTO campaigns (
                            name, alias, domain_id, group_id, source_id,
                            cost_model, cost_value, uniqueness_method, uniqueness_hours,
                            rotation_type, token, catch_404_stream_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newName, $newAlias, $campaign['domain_id'], $campaign['group_id'],
                        $campaign['source_id'], $campaign['cost_model'], $campaign['cost_value'],
                        $campaign['uniqueness_method'], $campaign['uniqueness_hours'],
                        $campaign['rotation_type'], $newToken, $campaign['catch_404_stream_id']
                    ]);
                    $newCampaignId = $pdo->lastInsertId();

                    // Copy streams
                    $stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ?");
                    $stmt->execute([$id]);
                    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($streams as $stream) {
                        $stmt = $pdo->prepare("
                            INSERT INTO streams (
                                campaign_id, offer_id, weight, is_active, type,
                                position, filters_json, filters_logic, schema_type, action_payload, schema_custom_json, name
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $newCampaignId, $stream['offer_id'], $stream['weight'],
                            $stream['is_active'], $stream['type'], $stream['position'],
                            $stream['filters_json'],
                            (($stream['filters_logic'] ?? 'and') === 'or') ? 'or' : 'and',
                            $stream['schema_type'],
                            $stream['action_payload'], $stream['schema_custom_json'],
                            $stream['name'] ?? ''
                        ]);
                    }

                    // Copy postbacks
                    $stmt = $pdo->prepare("SELECT * FROM campaign_postbacks WHERE campaign_id = ?");
                    $stmt->execute([$id]);
                    $postbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($postbacks as $postback) {
                        $stmt = $pdo->prepare("
                            INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $newCampaignId, $postback['url'], $postback['method'], $postback['statuses']
                        ]);
                    }

                    $pdo->commit();

                    logAudit($pdo, 'COPY', 'Campaign', $id, "Created copy: $newName (ID: $newCampaignId)");

                    echo json_encode([
                        'status' => 'success',
                        'id' => $newCampaignId,
                        'name' => $newName,
                        'alias' => $newAlias
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        // 'groups' is an alias kept for older clients that called it instead of
        // the namespaced action (Campaigns.jsx used ?action=groups for years).
        case 'groups':
        case 'campaign_groups':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                if (!empty($data['name'])) {
                    $stmt = $pdo->prepare("INSERT INTO campaign_groups (name) VALUES (?)");
                    $stmt->execute([$data['name']]);
                    echo json_encode(['status' => 'success', 'data' => ['id' => $pdo->lastInsertId()]]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing name']);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM campaign_groups ORDER BY name ASC");
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            }
            break;

        case 'delete_campaign_group':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    // Reset group_id for campaigns in this group
                    $pdo->prepare("UPDATE campaigns SET group_id = NULL WHERE group_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM campaign_groups WHERE id = ?")->execute([$id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                }
            }
            break;

        case 'traffic_sources':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $template = $data['template'] ?? '';
                $postbackUrl = $data['postback_url'] ?? '';
                $postbackStatuses = $data['postback_statuses'] ?? 'lead,sale';
                $parametersJson = json_encode($data['parameters'] ?? []);
                $notes = $data['notes'] ?? '';
                $state = $data['state'] ?? 'active';
                $url = $data['url'] ?? null;

                // Ensure URL has a scheme for validation and checking
                if ($url && !preg_match('~^https?://~i', $url)) {
                    $url = 'https://' . $url;
                }

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                    break;
                }

                try {
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE traffic_sources SET name=?, template=?, postback_url=?, postback_statuses=?, parameters_json=?, notes=?, state=?, url=? WHERE id=?");
                        $stmt->execute([$name, $template, $postbackUrl, $postbackStatuses, $parametersJson, $notes, $state, $url, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO traffic_sources (name, template, postback_url, postback_statuses, parameters_json, notes, state, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $template, $postbackUrl, $postbackStatuses, $parametersJson, $notes, $state, $url]);
                        $id = $pdo->lastInsertId();
                    }
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            } else {
                // Get traffic sources with stats
                list($whereCl, $paramsCl) = getDashboardFilters('cl.');
                $joinCondition = !empty($whereCl) ? str_replace("WHERE ", "AND ", $whereCl) : "";
                $limitClause = isset($_GET['limit']) ? "LIMIT " . (int) $_GET['limit'] : "";
                $havingClause = isset($_GET['limit']) ? "HAVING clicks > 0" : "";

                $stmt = $pdo->prepare("
                    SELECT ts.*, 
                           COUNT(DISTINCT c.id) as campaigns_count,
                           COUNT(cl.id) as clicks,
                           COALESCE(SUM(cl.is_conversion), 0) as conversions
                    FROM traffic_sources ts
                    LEFT JOIN campaigns c ON ts.id = c.source_id
                    LEFT JOIN clicks cl ON c.id = cl.campaign_id $joinCondition
                    WHERE ts.is_archived = 0
                    GROUP BY ts.id
                    $havingClause
                    ORDER BY clicks DESC, ts.name ASC
                    $limitClause
                ");
                $stmt->execute($paramsCl);
                $sources = $stmt->fetchAll();
                // Decode parameters_json for each
                foreach ($sources as &$s) {
                    $s['parameters'] = !empty($s['parameters_json']) ? json_decode($s['parameters_json'], true) : [];
                }
                echo json_encode(['status' => 'success', 'data' => $sources]);
            }
            break;

        case 'get_traffic_source':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM traffic_sources WHERE id = ?");
            $stmt->execute([$id]);
            $source = $stmt->fetch();
            if (!$source) {
                echo json_encode(['status' => 'error', 'message' => 'Traffic source not found']);
                break;
            }
            $source['parameters'] = !empty($source['parameters_json']) ? json_decode($source['parameters_json'], true) : [];
            echo json_encode(['status' => 'success', 'data' => $source]);
            break;

        case 'delete_traffic_source':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                    break;
                }
                try {
                    $pdo->beginTransaction();
                    // Reset source_id in campaigns
                    $stmtCamp = $pdo->prepare("UPDATE campaigns SET source_id = NULL WHERE source_id = ?");
                    $stmtCamp->execute([$id]);
                    $campaignsUpdated = $stmtCamp->rowCount();

                    $stmt = $pdo->prepare("UPDATE traffic_sources SET is_archived = 1, archived_at = datetime('now') WHERE id = ?");
                    $stmt->execute([$id]);
                    $updated = $stmt->rowCount();

                    $pdo->commit();
                    logAudit($pdo, 'DELETE', 'Traffic Source', $id, ['updated' => $updated, 'campaigns_unlinked' => $campaignsUpdated]);
                    echo json_encode(['status' => 'success', 'data' => ['updated' => $updated]]);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'bulk_delete_traffic_sources':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'ids must be an array']);
                break;
            }
            $ids = array_values(array_unique(array_filter(array_map(function ($v) {
                return (int) $v;
            }, $ids), function ($v) {
                return $v > 0;
            })));
            if (empty($ids)) {
                echo json_encode(['status' => 'success', 'data' => ['updated' => 0]]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // Reset source_id in campaigns
                $stmtCamp = $pdo->prepare("UPDATE campaigns SET source_id = NULL WHERE source_id IN ($placeholders)");
                $stmtCamp->execute($ids);
                $campaignsUpdated = $stmtCamp->rowCount();

                $stmt = $pdo->prepare("UPDATE traffic_sources SET is_archived = 1, archived_at = datetime('now') WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $updated = $stmt->rowCount();

                $pdo->commit();
                logAudit($pdo, 'DELETE', 'Traffic Sources (bulk)', null, ['ids' => $ids, 'updated' => $updated, 'campaigns_unlinked' => $campaignsUpdated]);
                echo json_encode(['status' => 'success', 'data' => ['updated' => $updated, 'campaigns_unlinked' => $campaignsUpdated]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'check_source_url':
            // Check a single source URL
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT id, url FROM traffic_sources WHERE id = ? AND is_archived = 0");
            $stmt->execute([$id]);
            $source = $stmt->fetch();
            if (!$source || empty($source['url'])) {
                echo json_encode(['status' => 'error', 'message' => 'Source not found or no URL set']);
                break;
            }

            $url = $source['url'];
            $result = checkUrlAvailability($url);
            $updateStmt = $pdo->prepare("UPDATE traffic_sources SET http_status = ?, last_checked = datetime('now'), status_message = ? WHERE id = ?");
            $updateStmt->execute([$result['status'], $result['message'], $id]);
            echo json_encode(['status' => 'success', 'data' => $result]);
            break;

        case 'check_all_source_urls':
            // Check all source URLs
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $stmt = $pdo->query("SELECT id, url FROM traffic_sources WHERE url IS NOT NULL AND url != '' AND is_archived = 0");
            $sources = $stmt->fetchAll();
            $results = ['checked' => 0, 'updated' => 0, 'details' => []];
            $updateStmt = $pdo->prepare("UPDATE traffic_sources SET http_status = ?, last_checked = datetime('now'), status_message = ? WHERE id = ?");

            foreach ($sources as $source) {
                $result = checkUrlAvailability($source['url']);
                $updateStmt->execute([$result['status'], $result['message'], $source['id']]);
                $results['checked']++;
                $results['updated']++;
                $results['details'][] = [
                    'id' => $source['id'],
                    'url' => $source['url'],
                    'status' => $result['status']
                ];
            }
            echo json_encode(['status' => 'success', 'data' => $results]);
            break;

        case 'bulk_import_sources':
            // Import multiple sources from a list
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $lines = $data['lines'] ?? [];

            if (!is_array($lines)) {
                echo json_encode(['status' => 'error', 'message' => 'lines must be an array']);
                break;
            }

            $results = ['imported' => 0, 'errors' => [], 'duplicates' => 0];
            $insertStmt = $pdo->prepare("INSERT INTO traffic_sources (name, url, state) VALUES (?, ?, 'active')");
            $checkStmt = $pdo->prepare("SELECT id FROM traffic_sources WHERE name = ? AND is_archived = 0");

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // Parse line: "url" or "name|url"
                if (strpos($line, '|') !== false) {
                    list($name, $url) = array_map('trim', explode('|', $line, 2));
                } else {
                    $url = trim($line);
                    // Extract hostname as name
                    $name = parse_url($url, PHP_URL_HOST) ?: $url;
                }

                if (empty($name)) {
                    $results['errors'][] = ['line' => $line, 'error' => 'Empty name'];
                    continue;
                }

                // Check for duplicate
                $checkStmt->execute([$name]);
                if ($checkStmt->fetch()) {
                    $results['duplicates']++;
                    $results['errors'][] = ['line' => $line, 'error' => 'Duplicate name'];
                    continue;
                }

                try {
                    $insertStmt->execute([$name, $url ?: null]);
                    $results['imported']++;
                } catch (\Exception $e) {
                    $results['errors'][] = ['line' => $line, 'error' => $e->getMessage()];
                }
            }
            echo json_encode(['status' => 'success', 'data' => $results]);
            break;

        case 'traffic_source_templates':
            // Pre-defined templates for popular traffic sources from Keitaro
            $templates = [
                [
                    'name' => 'facebook',
                    'display_name' => 'Facebook Ads',
                    'postback_url' => '',
                    'parameters' => [
                        ['alias' => 'ad_id', 'param' => 'ad_id', 'macro' => '{{ad.id}}'],
                        ['alias' => 'adset_id', 'param' => 'adset_id', 'macro' => '{{adset.id}}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{{campaign.id}}'],
                        ['alias' => 'ad_name', 'param' => 'ad_name', 'macro' => '{{ad.name}}'],
                        ['alias' => 'adset_name', 'param' => 'adset_name', 'macro' => '{{adset.name}}'],
                        ['alias' => 'campaign_name', 'param' => 'campaign_name', 'macro' => '{{campaign.name}}'],
                        ['alias' => 'site', 'param' => 'site', 'macro' => '{{site.name}}'],
                    ]
                ],
                [
                    'name' => 'google_ads',
                    'display_name' => 'Google Ads',
                    'postback_url' => '',
                    'parameters' => [
                        ['alias' => 'keyword', 'param' => 'keyword', 'macro' => '{keyword}'],
                        ['alias' => 'matchtype', 'param' => 'matchtype', 'macro' => '{matchtype}'],
                        ['alias' => 'creative', 'param' => 'creative', 'macro' => '{creative}'],
                        ['alias' => 'campaign', 'param' => 'campaign', 'macro' => '{campaignid}'],
                        ['alias' => 'adgroup', 'param' => 'adgroup', 'macro' => '{adgroupid}'],
                        ['alias' => 'device', 'param' => 'device', 'macro' => '{device}'],
                        ['alias' => 'loc_physical', 'param' => 'loc_physical', 'macro' => '{loc_physical_ms}'],
                    ]
                ],
                [
                    'name' => 'tiktok',
                    'display_name' => 'TikTok Ads',
                    'postback_url' => '',
                    'parameters' => [
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '__CAMPAIGN_ID__'],
                        ['alias' => 'adgroup_id', 'param' => 'adgroup_id', 'macro' => '__AID__'],
                        ['alias' => 'ad_id', 'param' => 'ad_id', 'macro' => '__CID__'],
                        ['alias' => 'creative', 'param' => 'creative', 'macro' => '__CREATIVE_ID__'],
                        ['alias' => 'pixel', 'param' => 'pixel', 'macro' => '__PIXEL__'],
                        ['alias' => 'ttclid', 'param' => 'ttclid', 'macro' => '__CLICKID__'],
                    ]
                ],
                [
                    'name' => 'taboola',
                    'display_name' => 'Taboola',
                    'postback_url' => 'https://trc.taboola.com/actions-handler/postback?ci={external_id}&v={payout}&tx={clickid}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{click_id}'],
                        ['alias' => 'site', 'param' => 'site', 'macro' => '{site}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{campaign_id}'],
                        ['alias' => 'campaign_name', 'param' => 'campaign_name', 'macro' => '{campaign_name}'],
                        ['alias' => 'cpc', 'param' => 'cpc', 'macro' => '{cpc}'],
                        ['alias' => 'content', 'param' => 'content', 'macro' => '{title}'],
                    ]
                ],
                [
                    'name' => 'outbrain',
                    'display_name' => 'Outbrain',
                    'postback_url' => 'https://tr.outbrain.com/pixel?apid={external_id}&tx={clickid}&cv={payout}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{clickId}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{campaignId}'],
                        ['alias' => 'campaign_name', 'param' => 'campaign_name', 'macro' => '{campaignName}'],
                        ['alias' => 'cpc', 'param' => 'cpc', 'macro' => '{cpc}'],
                    ]
                ],
                [
                    'name' => 'mgid',
                    'display_name' => 'MGID',
                    'postback_url' => 'https://a.mgid.com/postback?ci={external_id}&v={payout}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{clickId}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{campaignId}'],
                        ['alias' => 'cpc', 'param' => 'cpc', 'macro' => '{cpc}'],
                        ['alias' => 'widget', 'param' => 'widget', 'macro' => '{widgetId}'],
                    ]
                ],
                [
                    'name' => 'exoclick',
                    'display_name' => 'ExoClick',
                    'postback_url' => 'https://main.exoclick.com/tag?type=postback&cmp={campaign_id}&id={external_id}&yoid={your_id}&val={payout}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{var1}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{var2}'],
                        ['alias' => 'ad_id', 'param' => 'ad_id', 'macro' => '{var3}'],
                        ['alias' => 'site', 'param' => 'site', 'macro' => '{var4}'],
                        ['alias' => 'cpc', 'param' => 'cpc', 'macro' => '{var5}'],
                    ]
                ],
                [
                    'name' => 'propellerads',
                    'display_name' => 'PropellerAds',
                    'postback_url' => 'https://postback.propellerads.com/?clickid={external_id}&sum={payout}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{subid}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{campaign_id}'],
                        ['alias' => 'zone', 'param' => 'zone', 'macro' => '{zoneid}'],
                        ['alias' => 'cpc', 'param' => 'cpc', 'macro' => '{cpc}'],
                    ]
                ],
                [
                    'name' => 'yandex_direct',
                    'display_name' => 'Яндекс.Директ',
                    'postback_url' => '',
                    'parameters' => [
                        ['alias' => 'phrase', 'param' => 'phrase', 'macro' => '{phrase_id}'],
                        ['alias' => 'campaign', 'param' => 'campaign', 'macro' => '{campaign_id}'],
                        ['alias' => 'ad', 'param' => 'ad', 'macro' => '{ad_id}'],
                        ['alias' => 'keyword', 'param' => 'keyword', 'macro' => '{keyword}'],
                        ['alias' => 'position', 'param' => 'position', 'macro' => '{position_type}'],
                        ['alias' => 'device', 'param' => 'device', 'macro' => '{device_type}'],
                    ]
                ],
                [
                    'name' => 'zeropark',
                    'display_name' => 'Zeropark',
                    'postback_url' => 'https://postback.zeropark.com/2eb72633-c33f-4f9d-9e73-d29b40604b48?clickid={external_id}&sum={payout}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{cid}'],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '{campaign_id}'],
                        ['alias' => 'keyword', 'param' => 'keyword', 'macro' => '{keyword}'],
                        ['alias' => 'target', 'param' => 'target', 'macro' => '{target}'],
                    ]
                ],
                [
                    'name' => 'hasoffers',
                    'display_name' => 'HasOffers',
                    'postback_url' => 'http://domain.go2cloud.org/aff_lsr?offer_id={offer_id}&transaction_id={external_id}',
                    'parameters' => [
                        ['alias' => 'external_id', 'param' => 'external_id', 'macro' => '{transaction_id}'],
                        ['alias' => 'offer_id', 'param' => 'offer_id', 'macro' => '{offer_id}'],
                        ['alias' => 'affiliate_id', 'param' => 'affiliate_id', 'macro' => '{affiliate_id}'],
                    ]
                ],
                [
                    'name' => 'email',
                    'display_name' => 'Email',
                    'postback_url' => '',
                    'parameters' => [
                        ['alias' => 'subscriber_id', 'param' => 'subscriber_id', 'macro' => ''],
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => ''],
                        ['alias' => 'list_id', 'param' => 'list_id', 'macro' => ''],
                        ['alias' => 'broadcast_id', 'param' => 'broadcast_id', 'macro' => ''],
                        ['alias' => 'esp', 'param' => 'esp', 'macro' => ''],
                    ]
                ],
                [
                    'name' => 'custom',
                    'display_name' => 'Custom source',
                    'postback_url' => '',
                    'parameters' => []
                ],
            ];
            $templates = orbitraMergeTemplates($templates, orbitraLoadTemplatePack('keitaro_traffic_sources.json'));
            echo json_encode(['status' => 'success', 'data' => $templates]);
            break;

        case 'landing_groups':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                if (!empty($data['name'])) {
                    $stmt = $pdo->prepare("INSERT INTO landing_groups (name) VALUES (?)");
                    $stmt->execute([$data['name']]);
                    echo json_encode(['status' => 'success', 'data' => ['id' => $pdo->lastInsertId()]]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing name']);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM landing_groups ORDER BY name ASC");
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            }
            break;

        case 'delete_landing_group':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    // Reset group_id for landings in this group
                    $pdo->prepare("UPDATE landings SET group_id = NULL WHERE group_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM landing_groups WHERE id = ?")->execute([$id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                }
            }
            break;

        case 'landings':
            list($whereCl, $paramsCl) = getDashboardFilters('cl.');
            $joinCondition = !empty($whereCl) ? str_replace("WHERE ", "AND ", $whereCl) : "";
            $limitClause = isset($_GET['limit']) ? "LIMIT " . (int) $_GET['limit'] : "";
            $orderBy = isset($_GET['limit']) ? "ORDER BY clicks DESC, l.id DESC" : "ORDER BY l.id DESC";
            $havingClause = isset($_GET['limit']) ? "HAVING clicks > 0" : "";

            // Expanded to include metrics similarly to offers/campaigns (including LP->Offer clicks and LP CTR)
            $stmt = $pdo->prepare("
                SELECT l.id, l.name, l.type, l.url, l.state, lg.name as group_name,
                       COUNT(cl.id) as clicks,
                       COUNT(DISTINCT cl.ip) as unique_clicks,
                       COALESCE(SUM(CASE WHEN cl.offer_id IS NOT NULL AND cl.offer_id > 0 THEN 1 ELSE 0 END), 0) as lp_clicks,
                       COALESCE(SUM(cl.is_conversion), 0) as conversions,
                       MAX(cl.created_at) as last_event
                FROM landings l
                LEFT JOIN landing_groups lg ON l.group_id = l.group_id
                LEFT JOIN clicks cl ON (l.id = cl.landing_id OR (cl.landing_id IS (NULL) AND cl.id = 'NO_DIRECT_LINK_YET')) $joinCondition
                WHERE l.is_archived = 0
                GROUP BY l.id
                $havingClause
                $orderBy
                $limitClause
            ");
            $stmt->execute($paramsCl);
            $landingsData = $stmt->fetchAll();
            foreach ($landingsData as &$lRow) {
                $c = (int) ($lRow['clicks'] ?? 0);
                $lpc = (int) ($lRow['lp_clicks'] ?? 0);
                $conv = (int) ($lRow['conversions'] ?? 0);
                $lRow['lp_clicks'] = $lpc;
                $lRow['lp_ctr'] = $c > 0 ? round(($lpc / $c) * 100, 2) : 0.0;
                // Same denominator convention as campaign CR: conversions per click.
                $lRow['cr'] = $c > 0 ? round(($conv / $c) * 100, 2) : 0.0;
            }
            unset($lRow);
            echo json_encode(['status' => 'success', 'data' => $landingsData]);
            break;

        // Simple landings list for dropdowns (no heavy joins with clicks table)
        case 'landings_simple':
            $stmt = $pdo->query("
                SELECT l.id, l.name, l.state, l.type, l.group_id, lg.name AS group_name
                FROM landings l
                LEFT JOIN landing_groups lg ON lg.id = l.group_id
                WHERE l.is_archived = 0
                ORDER BY l.name ASC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        case 'get_landing':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM landings WHERE id = ?");
                $stmt->execute([$id]);
                $landing = $stmt->fetch();
                if ($landing) {
                    echo json_encode(['status' => 'success', 'data' => $landing]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Landing not found']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
            }
            break;

        case 'save_landing':
            // Everything below runs inside a try: a Throwable escaping this
            // handler becomes a bare 500, and the panel can only report that as
            // "network error" — which hides the actual cause from the operator.
            // A JSON error carries the reason to the form instead.
            try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }

                $data = json_decode(orbitraRequestBody(), true);
                if (!is_array($data)) {
                    $data = [];
                }
                if (!empty($data['name'])) {
                    $id = $data['id'] ?? null;
                    $groupId = !empty($data['group_id']) ? $data['group_id'] : null;
                    $type = $data['type'] ?? 'local';
                    // url is NOT NULL in the schema, but only redirect/preload
                    // actually use it — local/action landings have no URL. Coerce
                    // an absent/null url to '' so any caller (MCP, the old quick
                    // form, the new modal) saves cleanly instead of tripping the
                    // NOT NULL constraint.
                    $url = $data['url'] ?? '';
                    if (!is_string($url)) {
                        $url = '';
                    }
                    $actionPayload = $data['action_payload'] ?? null;
                    $state = $data['state'] ?? 'active';

                    // Only the five known actions are stored; anything else would
                    // land in the tracker's runtime switch as an unknown value.
                    $allowedActions = ['not_found', 'show_text', 'show_html', 'do_nothing', 'to_campaign'];
                    $actionType = (string) ($data['action_type'] ?? '');
                    if (!in_array($actionType, $allowedActions, true)) {
                        $actionType = '';
                    }
                    if ($type === 'action' && $actionType === '') {
                        // Landings saved before action types existed rendered their
                        // payload as HTML; keep that meaning rather than inventing one.
                        $actionType = ($actionPayload === null || $actionPayload === '') ? 'do_nothing' : 'show_html';
                    }

                    // Redirect method only matters for a redirect landing; a stray
                    // value on another type is dropped so it cannot reach the runtime.
                    $redirectType = (string) ($data['redirect_type'] ?? 'redirect');
                    if (!in_array($redirectType, ['redirect', 'js', 'meta_refresh'], true)) {
                        $redirectType = 'redirect';
                    }
                    if ($type !== 'redirect') {
                        $redirectType = 'redirect';
                    }

                    // Slug: the folder a local landing's files live under. Validate
                    // before writing — a duplicate or malformed slug would either
                    // merge two landings' files or break path resolution.
                    $slugRaw = trim((string) ($data['slug'] ?? ''));
                    $slugWasTyped = $slugRaw !== '';
                    if ($slugRaw === '' && $type === 'local' && !$id) {
                        // No slug given on create: derive one from the name so the
                        // folder is human-readable rather than landings/<id>/.
                        $slugRaw = orbitraSlugify($data['name']);
                    }
                    $slugCheck = orbitraValidateLandingSlug($pdo, $slugRaw, $id ?: null);
                    if (!$slugCheck['ok'] && !$slugWasTyped && $slugRaw !== '') {
                        // The operator did not choose this slug, we derived it from
                        // the name — so a collision with an existing landing or a
                        // reserved word is ours to resolve, not theirs to fix.
                        // Try 'name-2', 'name-3', … and if none is free fall back to
                        // an empty slug, which resolves to landings/<id>/.
                        $base = rtrim(substr($slugRaw, 0, 60), '-_');
                        for ($n = 2; $n <= 50; $n++) {
                            $candidate = orbitraValidateLandingSlug($pdo, $base . '-' . $n, $id ?: null);
                            if ($candidate['ok']) {
                                $slugCheck = $candidate;
                                break;
                            }
                        }
                        if (!$slugCheck['ok']) {
                            $slugCheck = ['ok' => true, 'value' => '', 'error' => ''];
                        }
                    }
                    if (!$slugCheck['ok']) {
                        echo json_encode(['status' => 'error', 'message' => $slugCheck['error']]);
                        break;
                    }
                    $slug = $slugCheck['value'];

                    if ($id) {
                        // Renaming a slug moves the landing's folder, so the old path
                        // stops answering and the new one starts. Only do this when the
                        // slug actually changed and the landing has files on disk.
                        try {
                            $oldRow = $pdo->prepare("SELECT slug FROM landings WHERE id = ? LIMIT 1");
                            $oldRow->execute([$id]);
                            $oldSlug = (string) $oldRow->fetchColumn();
                        } catch (\Throwable $e) {
                            $oldSlug = '';
                        }
                        $oldDir = orbitraLandingDir($pdo, $id);
                        $stmt = $pdo->prepare("UPDATE landings SET name=?, group_id=?, type=?, url=?, action_payload=?, action_type=?, state=?, slug=?, redirect_type=? WHERE id=?");
                        $stmt->execute([$data['name'], $groupId, $type, $url, $actionPayload, $actionType, $state, $slug, $redirectType, $id]);

                        // Recompute the target dir AFTER the update so orbitraLandingDir
                        // reflects the new slug, then move the folder if both exist.
                        if (($slug !== '' && $slug !== $oldSlug) || ($slug === '' && $oldSlug !== '')) {
                            $newDir = orbitraLandingDir($pdo, $id);
                            if (is_dir($oldDir) && realpath($oldDir) !== realpath($newDir) && !is_dir($newDir)) {
                                @rename($oldDir, $newDir);
                            }
                        }
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO landings (name, group_id, type, url, action_payload, action_type, state, slug, redirect_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$data['name'], $groupId, $type, $url, $actionPayload, $actionType, $state, $slug, $redirectType]);
                        $id = $pdo->lastInsertId();
                    }
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing name']);
                }
            } catch (\Throwable $e) {
                error_log('save_landing failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Landing save failed: ' . $e->getMessage(),
                ]);
            }
            break;

        case 'delete_landing':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                    break;
                }
                try {
                    $stmt = $pdo->prepare("UPDATE landings SET is_archived = 1, archived_at = datetime('now') WHERE id = ?");
                    $stmt->execute([$id]);
                    $updated = $stmt->rowCount();
                    logAudit($pdo, 'DELETE', 'Landing', $id, ['updated' => $updated]);
                    echo json_encode(['status' => 'success', 'data' => ['updated' => $updated]]);
                } catch (Throwable $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'bulk_delete_landings':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'ids must be an array']);
                break;
            }
            $ids = array_values(array_unique(array_filter(array_map(function ($v) {
                return (int) $v;
            }, $ids), function ($v) {
                return $v > 0;
            })));
            if (empty($ids)) {
                echo json_encode(['status' => 'success', 'data' => ['updated' => 0]]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE landings SET is_archived = 1, archived_at = datetime('now') WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $updated = $stmt->rowCount();
                $pdo->commit();
                logAudit($pdo, 'DELETE', 'Landings (bulk)', null, ['ids' => $ids, 'updated' => $updated]);
                echo json_encode(['status' => 'success', 'data' => ['updated' => $updated]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'upload_landing':
            // Same reasoning as save_landing: a Throwable escaping here is a 500,
            // and the panel can only render that as "ZIP upload error" with no
            // cause attached. Every failure below names itself instead.
            try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $id = $_POST['id'] ?? null;
                if (!$id) {
                    // An empty $_POST on a POST request usually means the body was
                    // larger than post_max_size — PHP discards it wholesale, so the
                    // id we sent alongside the file disappears with it.
                    $postMax = (string) ini_get('post_max_size');
                    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
                    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'upload_exceeds_post_max',
                            'detail' => ['size_mb' => round($contentLength / 1048576, 1), 'limit' => $postMax],
                        ]);
                        break;
                    }
                    echo json_encode(['status' => 'error', 'message' => 'Missing Landing ID']);
                    break;
                }
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    // The upload error code says far more than "File upload error":
                    // a size limit, a missing tmp dir and an aborted transfer are
                    // three different problems with three different fixes.
                    $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
                    $uploadErrCode = [
                        UPLOAD_ERR_INI_SIZE => 'upload_err_ini_size',
                        UPLOAD_ERR_FORM_SIZE => 'upload_err_form_size',
                        UPLOAD_ERR_PARTIAL => 'upload_err_partial',
                        UPLOAD_ERR_NO_FILE => 'upload_err_no_file',
                        UPLOAD_ERR_NO_TMP_DIR => 'upload_err_no_tmp_dir',
                        UPLOAD_ERR_CANT_WRITE => 'upload_err_cant_write',
                        UPLOAD_ERR_EXTENSION => 'upload_err_extension',
                    ][$uploadErr] ?? 'upload_err_unknown';
                    echo json_encode([
                        'status' => 'error',
                        'message' => $uploadErrCode,
                        'detail' => ['limit' => ini_get('upload_max_filesize'), 'code' => $uploadErr],
                    ]);
                    break;
                }

                // Security: Check file size (< 50MB)
                if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
                    echo json_encode(['status' => 'error', 'message' => 'File too large (max 50MB)']);
                    break;
                }

                $zipFile = $_FILES['file']['tmp_name'];

                // Security: Check MIME type. fileinfo and zip are both optional PHP
                // extensions; without them the calls below fatal, so say which one
                // is missing rather than dying as a 500.
                if (!function_exists('finfo_open')) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'missing_ext_fileinfo',
                    ]);
                    break;
                }
                if (!class_exists('ZipArchive')) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'missing_ext_zip',
                    ]);
                    break;
                }
                $allowedMimes = ['application/zip', 'application/x-zip-compressed'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $zipFile);
                // finfo_close() is deprecated since PHP 8.5 - resources are auto-freed

                if (!in_array($mimeType, $allowedMimes)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'not_a_zip',
                        'detail' => ['mime' => $mimeType],
                    ]);
                    break;
                }

                $uploadDir = orbitraLandingDir($pdo, $id) . '/';
                if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    // Reported, not ignored: the old code let mkdir fail silently and
                    // then answered "success" for an extraction that never happened,
                    // leaving a landing that serves nothing.
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'landing_dir_not_created',
                        'detail' => ['path' => $uploadDir, 'root' => __DIR__],
                    ]);
                    break;
                }
                if (!is_writable($uploadDir)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'landing_dir_not_writable',
                        'detail' => ['path' => $uploadDir, 'root' => __DIR__],
                    ]);
                    break;
                }

                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    // Security: Verify contents before extraction
                    $safeToExtract = true;
                    $errorMsg = '';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        // PHP is refused unless an admin has deliberately allowed it;
                        // when allowed the archive is still scanned after extraction.
                        if (preg_match('/\.(php|phtml|php5|php7|phar)$/i', $filename)) {
                            require_once __DIR__ . '/core/PhpLanding.php';
                            if (!PhpLanding::enabled($pdo)) {
                                $safeToExtract = false;
                                $errorMsg = 'This archive contains PHP files. Turn on "Allow PHP landings" '
                                    . 'in Settings -> General if you trust this landing\'s code.';
                                break;
                            }
                        }
                        // Deny path traversal inside zip
                        if (strpos($filename, '..') !== false || strpos($filename, '/') === 0) {
                            $safeToExtract = false;
                            $errorMsg = 'Invalid filename in archive';
                            break;
                        }
                    }

                    if ($safeToExtract) {
                        // extractTo returns false on a write failure. The old code
                        // discarded that and reported success regardless, so a
                        // permissions problem looked like a working upload until
                        // the landing turned out to be empty.
                        if (!$zip->extractTo($uploadDir)) {
                            // A ZIP can be readable and still be unextractable: the
                            // "maximum compression" preset in 7-Zip and WinRAR writes
                            // LZMA, BZip2 or PPMd entries, and libzip is normally
                            // built with Store and Deflate only. The archive opens,
                            // the file list reads fine, and extraction just fails —
                            // so check the methods before blaming permissions.
                            $badMethods = [];
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $stat = $zip->statIndex($i);
                                $method = $stat['comp_method'] ?? 8;
                                if (!in_array((int) $method, [0, 8], true)) {
                                    $badMethods[(int) $method] = true;
                                }
                            }
                            $zip->close();
                            if ($badMethods) {
                                $methodNames = [
                                    9 => 'Deflate64', 12 => 'BZip2', 14 => 'LZMA',
                                    93 => 'Zstandard', 95 => 'XZ', 98 => 'PPMd',
                                ];
                                $named = [];
                                foreach (array_keys($badMethods) as $m) {
                                    $named[] = $methodNames[$m] ?? ('метод ' . $m);
                                }
                                echo json_encode([
                                    'status' => 'error',
                                    'message' => 'zip_unsupported_compression',
                                    'detail' => ['methods' => $named],
                                ]);
                                break;
                            }
                            echo json_encode([
                                'status' => 'error',
                                'message' => 'zip_extract_failed',
                                'detail' => ['path' => $uploadDir],
                            ]);
                            break;
                        }

                        // Single-nested-folder archives ("zip -r folder/") land one
                        // level down and would never serve; lift them to the root
                        // before anything inspects the layout.
                        orbitraFlattenSingleNestedDir($uploadDir);

                        // Names alone cannot tell whether the PHP inside is acceptable,
                        // so the check happens on the extracted source — and a failing
                        // archive is removed rather than left half-installed.
                        require_once __DIR__ . '/core/PhpLanding.php';
                        $phpProblems = PhpLanding::scanDirectory($uploadDir);
                        if ($phpProblems) {
                            $lines = [];
                            foreach ($phpProblems as $file => $names) {
                                $lines[] = $file . ': ' . implode(', ', $names);
                            }
                            $it = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::CHILD_FIRST
                            );
                            foreach ($it as $entry) {
                                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
                            }
                            echo json_encode([
                                'status' => 'error',
                                'message' => 'This landing uses calls that are not allowed in a PHP landing — '
                                    . implode(' | ', $lines)
                            ]);
                            $zip->close();
                            break;
                        }

                        echo json_encode(['status' => 'success', 'message' => 'Files extracted successfully']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => $errorMsg]);
                    }
                    $zip->close();
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'zip_open_failed']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
            }
            } catch (\Throwable $e) {
                error_log('upload_landing failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                echo json_encode([
                    'status' => 'error',
                    'message' => 'upload_failed',
                    'detail' => ['error' => $e->getMessage()],
                ]);
            }
            break;

        // === Local offers: the same archive stack landings have ===
        // A local offer's files live in offers/<id>/ and are served by index.php
        // at the moment the tracker would redirect to the offer.
        case 'upload_offer':
            try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $postMax = (string) ini_get('post_max_size');
                    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
                    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'upload_exceeds_post_max',
                            'detail' => ['size_mb' => round($contentLength / 1048576, 1), 'limit' => $postMax],
                        ]);
                        break;
                    }
                    echo json_encode(['status' => 'error', 'message' => 'Missing Offer ID']);
                    break;
                }

                // The offer must exist and actually be local — otherwise the
                // archive would sit in a directory nothing ever serves.
                $stmtOwn = $pdo->prepare("SELECT id, is_local FROM offers WHERE id = ? LIMIT 1");
                $stmtOwn->execute([$id]);
                $offerRow = $stmtOwn->fetch(PDO::FETCH_ASSOC);
                if (!$offerRow) {
                    echo json_encode(['status' => 'error', 'message' => 'Offer not found']);
                    break;
                }
                if (!(int) ($offerRow['is_local'] ?? 0)) {
                    echo json_encode(['status' => 'error', 'message' => 'offer_not_local', 'detail' => ['hint' => 'Switch the offer type to Local first']]);
                    break;
                }

                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
                    $uploadErrCode = [
                        UPLOAD_ERR_INI_SIZE => 'upload_err_ini_size',
                        UPLOAD_ERR_FORM_SIZE => 'upload_err_form_size',
                        UPLOAD_ERR_PARTIAL => 'upload_err_partial',
                        UPLOAD_ERR_NO_FILE => 'upload_err_no_file',
                        UPLOAD_ERR_NO_TMP_DIR => 'upload_err_no_tmp_dir',
                        UPLOAD_ERR_CANT_WRITE => 'upload_err_cant_write',
                        UPLOAD_ERR_EXTENSION => 'upload_err_extension',
                    ][$uploadErr] ?? 'upload_err_unknown';
                    echo json_encode([
                        'status' => 'error',
                        'message' => $uploadErrCode,
                        'detail' => ['limit' => ini_get('upload_max_filesize'), 'code' => $uploadErr],
                    ]);
                    break;
                }
                if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
                    echo json_encode(['status' => 'error', 'message' => 'File too large (max 50MB)']);
                    break;
                }

                $zipFile = $_FILES['file']['tmp_name'];
                if (!function_exists('finfo_open')) {
                    echo json_encode(['status' => 'error', 'message' => 'missing_ext_fileinfo']);
                    break;
                }
                if (!class_exists('ZipArchive')) {
                    echo json_encode(['status' => 'error', 'message' => 'missing_ext_zip']);
                    break;
                }
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $zipFile);
                if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed'], true)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'not_a_zip',
                        'detail' => ['mime' => $mimeType],
                    ]);
                    break;
                }

                $uploadDir = __DIR__ . '/offers/' . $id;
                if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'offer_dir_not_created',
                        'detail' => ['path' => $uploadDir, 'root' => __DIR__],
                    ]);
                    break;
                }
                if (!is_writable($uploadDir)) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'offer_dir_not_writable',
                        'detail' => ['path' => $uploadDir, 'root' => __DIR__],
                    ]);
                    break;
                }

                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    $safeToExtract = true;
                    $errorMsg = '';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        if (preg_match('/\.(php|phtml|php5|php7|phar)$/i', $filename)) {
                            require_once __DIR__ . '/core/PhpLanding.php';
                            if (!PhpLanding::enabled($pdo)) {
                                $safeToExtract = false;
                                $errorMsg = 'This archive contains PHP files. Turn on "Allow PHP landings" '
                                    . 'in Settings -> General if you trust this offer\'s code.';
                                break;
                            }
                        }
                        if (strpos($filename, '..') !== false || strpos($filename, '/') === 0) {
                            $safeToExtract = false;
                            $errorMsg = 'Invalid filename in archive';
                            break;
                        }
                    }

                    if ($safeToExtract) {
                        if (!$zip->extractTo($uploadDir)) {
                            $badMethods = [];
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $stat = $zip->statIndex($i);
                                $method = $stat['comp_method'] ?? 8;
                                if (!in_array((int) $method, [0, 8], true)) {
                                    $badMethods[(int) $method] = true;
                                }
                            }
                            $zip->close();
                            if ($badMethods) {
                                $methodNames = [9 => 'Deflate64', 12 => 'BZip2', 14 => 'LZMA', 93 => 'Zstandard', 95 => 'XZ', 98 => 'PPMd'];
                                $named = [];
                                foreach (array_keys($badMethods) as $m) {
                                    $named[] = $methodNames[$m] ?? ('метод ' . $m);
                                }
                                echo json_encode([
                                    'status' => 'error',
                                    'message' => 'zip_unsupported_compression',
                                    'detail' => ['methods' => $named],
                                ]);
                                break;
                            }
                            echo json_encode([
                                'status' => 'error',
                                'message' => 'zip_extract_failed',
                                'detail' => ['path' => $uploadDir],
                            ]);
                            break;
                        }

                        // Same nested-folder flattening as landings — offer archives
                        // come from the same zip tools.
                        orbitraFlattenSingleNestedDir($uploadDir);

                        require_once __DIR__ . '/core/PhpLanding.php';
                        $phpProblems = PhpLanding::scanDirectory($uploadDir);
                        if ($phpProblems) {
                            $lines = [];
                            foreach ($phpProblems as $file => $names) {
                                $lines[] = $file . ': ' . implode(', ', $names);
                            }
                            $it = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::CHILD_FIRST
                            );
                            foreach ($it as $entry) {
                                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
                            }
                            echo json_encode([
                                'status' => 'error',
                                'message' => 'This offer uses calls that are not allowed in a PHP offer — '
                                    . implode(' | ', $lines)
                            ]);
                            $zip->close();
                            break;
                        }

                        echo json_encode(['status' => 'success', 'message' => 'Files extracted successfully']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => $errorMsg]);
                    }
                    $zip->close();
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'zip_open_failed']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
            }
            } catch (\Throwable $e) {
                error_log('upload_offer failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                echo json_encode([
                    'status' => 'error',
                    'message' => 'upload_failed',
                    'detail' => ['error' => $e->getMessage()],
                ]);
            }
            break;

        case 'offer_files':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id > 0) {
                $dir = __DIR__ . '/offers/' . $id;
                if (!is_dir($dir)) {
                    echo json_encode(['status' => 'success', 'data' => []]);
                    break;
                }
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                $files = [];
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relativePath = str_replace($dir . '/', '', $file->getPathname());
                        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                        if (in_array($ext, ['html', 'php', 'css', 'js', 'json', 'txt', 'md'])) {
                            $files[] = $relativePath;
                        }
                    }
                }
                echo json_encode(['status' => 'success', 'data' => $files]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
            }
            break;

        // Delete one file inside a local offer's folder. Containment via realpath
        // against offers/<id>/ — the same guarantee landing file ops give.
        case 'offer_file_op':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = (int) ($data['id'] ?? 0);
                $path = (string) ($data['path'] ?? '');
                $op = (string) ($data['op'] ?? 'delete');
                if ($id <= 0 || $path === '' || $op !== 'delete') {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID, path or unsupported op']);
                    break;
                }
                $root = realpath(__DIR__ . '/offers/' . $id);
                if ($root === false) {
                    echo json_encode(['status' => 'error', 'message' => 'Offer folder not found']);
                    break;
                }
                $file = realpath($root . '/' . ltrim($path, '/'));
                if ($file === false || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                    break;
                }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, ['html', 'css', 'js', 'json', 'txt', 'md', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'pdf'], true)) {
                    echo json_encode(['status' => 'error', 'message' => 'File type not allowed']);
                    break;
                }
                if (@unlink($file)) {
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
            }
            break;

        case 'landing_files':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $dir = orbitraLandingDir($pdo, $id);
                if (!is_dir($dir)) {
                    echo json_encode(['status' => 'success', 'data' => []]);
                    break;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                $files = [];
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relativePath = str_replace($dir . '/', '', $file->getPathname());
                        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
                        if (in_array($ext, ['html', 'php', 'css', 'js', 'json', 'txt', 'md'])) {
                            $files[] = $relativePath;
                        }
                    }
                }
                echo json_encode(['status' => 'success', 'data' => $files]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
            }
            break;

        case 'get_landing_file':
            $id = $_GET['id'] ?? null;
            $path = $_GET['path'] ?? null;
            if ($id && $path) {
                $file = orbitraLandingFilePath($id, $path);
                if ($file === null) {
                    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                    break;
                }

                if (file_exists($file) && is_file($file)) {
                    $content = file_get_contents($file);
                    echo json_encode(['status' => 'success', 'data' => $content]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'File not found']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID or path']);
            }
            break;

        // Create, upload, rename, move and delete inside a landing's folder.
        // Every one of them resolves through orbitraLandingFilePath(), so a crafted
        // path cannot reach outside landings/<id>/, and every write is checked
        // against the extension whitelist — a .php here would be code execution
        // in the web root.
        case 'landing_file_op':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            if (!is_array($data)) {
                $data = [];
            }
            $op = (string) ($data['op'] ?? '');
            $landingId = (int) ($data['id'] ?? 0);
            if ($landingId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Missing landing id']);
                break;
            }
            $landingRoot = orbitraLandingDir($pdo, $landingId);
            if (!is_dir($landingRoot)) {
                @mkdir($landingRoot, 0775, true);
            }

            $checkWritable = function ($path) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                return in_array($ext, orbitraLandingEditableExtensions(), true) ? '' : $ext;
            };

            if ($op === 'create') {
                $target = orbitraLandingFilePath($landingId, $data['path'] ?? '', false);
                if ($target === null) {
                    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                    break;
                }
                if (($bad = $checkWritable($target)) !== '') {
                    echo json_encode(['status' => 'error', 'message' => 'This file type is not allowed: .' . $bad]);
                    break;
                }
                if (file_exists($target)) {
                    echo json_encode(['status' => 'error', 'message' => 'A file with that name already exists']);
                    break;
                }
                @mkdir(dirname($target), 0775, true);
                file_put_contents($target, (string) ($data['content'] ?? ''));
                echo json_encode(['status' => 'success']);
                break;
            }

            if ($op === 'delete') {
                $target = orbitraLandingFilePath($landingId, $data['path'] ?? '');
                if ($target === null || !is_file($target)) {
                    echo json_encode(['status' => 'error', 'message' => 'File not found']);
                    break;
                }
                echo json_encode(@unlink($target)
                    ? ['status' => 'success']
                    : ['status' => 'error', 'message' => 'Could not delete the file (check permissions)']);
                break;
            }

            if ($op === 'rename' || $op === 'move') {
                $from = orbitraLandingFilePath($landingId, $data['path'] ?? '');
                $to = orbitraLandingFilePath($landingId, $data['to'] ?? '', false);
                if ($from === null || !is_file($from)) {
                    echo json_encode(['status' => 'error', 'message' => 'File not found']);
                    break;
                }
                if ($to === null) {
                    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                    break;
                }
                if (($bad = $checkWritable($to)) !== '') {
                    echo json_encode(['status' => 'error', 'message' => 'This file type is not allowed: .' . $bad]);
                    break;
                }
                if (file_exists($to)) {
                    echo json_encode(['status' => 'error', 'message' => 'A file with that name already exists']);
                    break;
                }
                @mkdir(dirname($to), 0775, true);
                echo json_encode(@rename($from, $to)
                    ? ['status' => 'success']
                    : ['status' => 'error', 'message' => 'Could not move the file (check permissions)']);
                break;
            }

            echo json_encode(['status' => 'error', 'message' => 'Unknown operation: ' . $op]);
            break;

        case 'upload_landing_file':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }
            $landingId = (int) ($_POST['id'] ?? 0);
            $destDir = trim((string) ($_POST['dir'] ?? ''), '/');
            if ($landingId <= 0 || !isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'Missing landing id or file']);
                break;
            }
            if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
                echo json_encode(['status' => 'error', 'message' => 'File too large (max 50MB)']);
                break;
            }
            // The uploaded name is attacker-controlled: keep only its basename so
            // "../../index.php" cannot become a path.
            $safeName = basename(str_replace('\\', '/', (string) $_FILES['file']['name']));
            $relative = ($destDir !== '' ? $destDir . '/' : '') . $safeName;
            $target = orbitraLandingFilePath($landingId, $relative, false);
            if ($target === null) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                break;
            }
            $uploadExt = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            if (!in_array($uploadExt, orbitraLandingEditableExtensions(), true)) {
                echo json_encode(['status' => 'error', 'message' => 'This file type is not allowed: .' . $uploadExt]);
                break;
            }
            @mkdir(dirname($target), 0775, true);
            echo json_encode(move_uploaded_file($_FILES['file']['tmp_name'], $target)
                ? ['status' => 'success', 'data' => ['path' => $relative]]
                : ['status' => 'error', 'message' => 'Could not store the file (check permissions)']);
            break;

        case 'save_landing_file':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                $path = $data['path'] ?? null;
                $content = $data['content'] ?? '';

                if ($id && $path) {
                    $file = orbitraLandingFilePath($id, $path);
                    if ($file === null) {
                        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                        break;
                    }
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (!in_array($ext, orbitraLandingEditableExtensions(), true)) {
                        echo json_encode(['status' => 'error', 'message' => 'This file type cannot be edited: .' . $ext]);
                        break;
                    }

                    if (file_exists($file) && is_file($file)) {
                        file_put_contents($file, $content);
                        echo json_encode(['status' => 'success']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'File not found']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID or path']);
                }
            }
            break;

        case 'offers':
            list($whereCl, $paramsCl) = getDashboardFilters('cl.');
            $joinCondition = !empty($whereCl) ? str_replace("WHERE ", "AND ", $whereCl) : "";
            $limitClause = isset($_GET['limit']) ? "LIMIT " . (int) $_GET['limit'] : "";
            $orderBy = isset($_GET['limit']) ? "ORDER BY clicks DESC, created_at DESC" : "ORDER BY created_at DESC";
            $havingClause = isset($_GET['limit']) ? "HAVING clicks > 0" : "";
            $conversionsValueColumn = getConversionsValueColumn($pdo);

            // Полный список офферов со статистикой. Status counters and money
            // come from the same per-click conversion aggregate the report
            // engine uses, and the ratios are derived by the same
            // orbitraComputeDerivedMetrics() — so the offers table can never
            // drift from the verified 64-metric math. The SQL lives in
            // core/ReportMetrics.php so tests run the exact production query.
            $stmt = $pdo->prepare(orbitraOffersWithStatsSql($joinCondition, $conversionsValueColumn)
                . " $havingClause $orderBy $limitClause");
            $stmt->execute($paramsCl);
            $offersData = $stmt->fetchAll();
            foreach ($offersData as &$oRow) {
                $m = orbitraComputeDerivedMetrics($oRow);
                $oRow['cr'] = $m['cr'];
                $oRow['epc_confirmed'] = $m['epc_confirmed'];
                $oRow['cpc'] = $m['cpc'];
                $oRow['profit_confirmed'] = $m['profit_confirmed'];
                $oRow['roi_confirmed'] = $m['roi_confirmed'];
            }
            unset($oRow);
            echo json_encode(['status' => 'success', 'data' => $offersData]);
            break;

        case 'all_offers':
            // The campaign stream picker filters and displays by group, network
            // and GEO, so the dropdown payload carries the names it needs.
            $stmt = $pdo->query("
                SELECT o.id, o.name, o.url, o.state, o.is_local, o.redirect_type,
                       o.group_id, og.name AS group_name,
                       o.affiliate_network_id, an.name AS affiliate_network_name,
                       o.geo, o.payout_type, o.payout_value
                FROM offers o
                LEFT JOIN offer_groups og ON og.id = o.group_id
                LEFT JOIN affiliate_networks an ON an.id = o.affiliate_network_id
                WHERE o.state = 'active'
                ORDER BY o.name ASC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        case 'get_offer':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM offers WHERE id = ?");
            $stmt->execute([$id]);
            $offer = $stmt->fetch();
            if (!$offer) {
                echo json_encode(['status' => 'error', 'message' => 'Offer not found']);
                break;
            }
            // Parse values_json
            $offer['values'] = !empty($offer['values_json']) ? json_decode($offer['values_json'], true) : [];
            echo json_encode(['status' => 'success', 'data' => $offer]);
            break;

        case 'save_offer':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $groupId = !empty($data['group_id']) ? (int) $data['group_id'] : null;
                $affiliateNetworkId = !empty($data['affiliate_network_id']) ? (int) $data['affiliate_network_id'] : null;
                $url = $data['url'] ?? '';
                $redirectType = $data['redirect_type'] ?? 'redirect';
                $isLocal = !empty($data['is_local']) ? 1 : 0;
                $geo = $data['geo'] ?? '';
                $payoutType = $data['payout_type'] ?? 'cpa';
                $payoutValue = !empty($data['payout_value']) ? (float) $data['payout_value'] : 0.00;
                $payoutAuto = !empty($data['payout_auto']) ? 1 : 0;
                $allowRebills = !empty($data['allow_rebills']) ? 1 : 0;
                $cappingLimit = !empty($data['capping_limit']) ? (int) $data['capping_limit'] : 0;
                $cappingTimezone = $data['capping_timezone'] ?? 'UTC';
                $altOfferId = !empty($data['alt_offer_id']) ? (int) $data['alt_offer_id'] : null;
                $notes = $data['notes'] ?? '';
                $valuesJson = json_encode($data['values'] ?? []);
                $state = $data['state'] ?? 'active';

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                    break;
                }

                try {
                    if ($id) {
                        $stmt = $pdo->prepare("
                            UPDATE offers 
                            SET name=?, group_id=?, affiliate_network_id=?, url=?, redirect_type=?, 
                                is_local=?, geo=?, payout_type=?, payout_value=?, payout_auto=?, 
                                allow_rebills=?, capping_limit=?, capping_timezone=?, alt_offer_id=?, 
                                notes=?, values_json=?, state=?
                            WHERE id=?
                        ");
                        $stmt->execute([
                            $name,
                            $groupId,
                            $affiliateNetworkId,
                            $url,
                            $redirectType,
                            $isLocal,
                            $geo,
                            $payoutType,
                            $payoutValue,
                            $payoutAuto,
                            $allowRebills,
                            $cappingLimit,
                            $cappingTimezone,
                            $altOfferId,
                            $notes,
                            $valuesJson,
                            $state,
                            $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO offers 
                            (name, group_id, affiliate_network_id, url, redirect_type, is_local, geo, 
                             payout_type, payout_value, payout_auto, allow_rebills, capping_limit, 
                             capping_timezone, alt_offer_id, notes, values_json, state)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $name,
                            $groupId,
                            $affiliateNetworkId,
                            $url,
                            $redirectType,
                            $isLocal,
                            $geo,
                            $payoutType,
                            $payoutValue,
                            $payoutAuto,
                            $allowRebills,
                            $cappingLimit,
                            $cappingTimezone,
                            $altOfferId,
                            $notes,
                            $valuesJson,
                            $state
                        ]);
                        $id = $pdo->lastInsertId();
                    }
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'delete_offer':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    try {
                        $pdo->prepare("UPDATE offers SET is_archived = 1, archived_at = datetime('now') WHERE id = ?")->execute([$id]);
                        echo json_encode(['status' => 'success']);
                    } catch (\Exception $e) {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                }
            }
            break;

        case 'bulk_delete_offers':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'ids must be an array']);
                break;
            }
            $ids = array_values(array_unique(array_filter(array_map(function ($v) {
                return (int) $v;
            }, $ids), function ($v) {
                return $v > 0;
            })));
            if (empty($ids)) {
                echo json_encode(['status' => 'success', 'data' => ['updated' => 0]]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE offers SET is_archived = 1, archived_at = datetime('now') WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $updated = $stmt->rowCount();
                $pdo->commit();
                logAudit($pdo, 'DELETE', 'Offers (bulk)', null, ['ids' => $ids, 'updated' => $updated]);
                echo json_encode(['status' => 'success', 'data' => ['updated' => $updated]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'copy_offer':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;

                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'ID не передан']);
                    break;
                }

                try {
                    $pdo->beginTransaction();

                    // Get original offer
                    $stmt = $pdo->prepare("SELECT * FROM offers WHERE id = ?");
                    $stmt->execute([$id]);
                    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$offer) {
                        echo json_encode(['status' => 'error', 'message' => 'Оффер не найден']);
                        break;
                    }

                    // Find next copy number
                    $baseName = preg_replace('/^Copy #\d+ /', '', $offer['name']);
                    $stmt = $pdo->prepare("SELECT name FROM offers WHERE name LIKE ?");
                    $stmt->execute(["Copy %"]);
                    $existingCopies = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $copyNum = 1;
                    while (in_array("Copy #$copyNum $baseName", $existingCopies)) {
                        $copyNum++;
                    }
                    $newName = "Copy #$copyNum $baseName";

                    // Insert new offer
                    $stmt = $pdo->prepare("
                        INSERT INTO offers (
                            name, group_id, affiliate_network_id, url, redirect_type,
                            is_local, geo, payout_type, payout_value, payout_auto,
                            allow_rebills, capping_limit, capping_timezone, alt_offer_id,
                            notes, values_json, state
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $newName, $offer['group_id'], $offer['affiliate_network_id'],
                        $offer['url'], $offer['redirect_type'], $offer['is_local'],
                        $offer['geo'], $offer['payout_type'], $offer['payout_value'],
                        $offer['payout_auto'], $offer['allow_rebills'], $offer['capping_limit'],
                        $offer['capping_timezone'], $offer['alt_offer_id'], $offer['notes'],
                        $offer['values_json'], $offer['state']
                    ]);
                    $newOfferId = $pdo->lastInsertId();

                    $pdo->commit();

                    logAudit($pdo, 'COPY', 'Offer', $id, "Created copy: $newName (ID: $newOfferId)");

                    echo json_encode([
                        'status' => 'success',
                        'id' => $newOfferId,
                        'name' => $newName
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'offer_groups':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                if (!empty($data['name'])) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO offer_groups (name) VALUES (?)");
                        $stmt->execute([$data['name']]);
                        echo json_encode(['status' => 'success', 'data' => ['id' => $pdo->lastInsertId()]]);
                    } catch (\Exception $e) {
                        echo json_encode(['status' => 'error', 'message' => 'Группа с таким названием уже существует']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing name']);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM offer_groups ORDER BY name ASC");
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            }
            break;

        case 'delete_offer_group':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    // Reset group_id for offers in this group
                    $pdo->prepare("UPDATE offers SET group_id = NULL WHERE group_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM offer_groups WHERE id = ?")->execute([$id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                }
            }
            break;

        case 'affiliate_networks':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $template = $data['template'] ?? '';
                $offerParams = $data['offer_params'] ?? '';
                $postbackUrl = $data['postback_url'] ?? '';
                $notes = $data['notes'] ?? '';
                $state = $data['state'] ?? 'active';

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                    break;
                }

                try {
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE affiliate_networks SET name=?, template=?, offer_params=?, postback_url=?, notes=?, state=? WHERE id=?");
                        $stmt->execute([$name, $template, $offerParams, $postbackUrl, $notes, $state, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO affiliate_networks (name, template, offer_params, postback_url, notes, state) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $template, $offerParams, $postbackUrl, $notes, $state]);
                        $id = $pdo->lastInsertId();
                    }
                    logAudit($pdo, isset($data['id']) ? 'UPDATE' : 'CREATE', 'Affiliate Network', $id, "Name: $name");
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            } else {
                $stmt = $pdo->query("
                    SELECT an.*, 
                           COUNT(DISTINCT o.id) as offers_count
                    FROM affiliate_networks an
                    LEFT JOIN offers o ON an.id = o.affiliate_network_id AND o.is_archived = 0
                    WHERE an.is_archived = 0
                    GROUP BY an.id
                    ORDER BY an.name ASC
                ");
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            }
            break;

        case 'get_affiliate_network':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM affiliate_networks WHERE id = ?");
            $stmt->execute([$id]);
            $network = $stmt->fetch();
            if ($network) {
                echo json_encode(['status' => 'success', 'data' => $network]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Network not found']);
            }
            break;

        case 'delete_affiliate_network':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    try {
                        $pdo->beginTransaction();
                        $stmtOffers = $pdo->prepare("UPDATE offers SET affiliate_network_id = NULL WHERE affiliate_network_id = ?");
                        $stmtOffers->execute([$id]);
                        $offersDetached = $stmtOffers->rowCount();

                        $stmt = $pdo->prepare("UPDATE affiliate_networks SET is_archived = 1, archived_at = datetime('now') WHERE id = ?");
                        $stmt->execute([$id]);
                        $updated = $stmt->rowCount();

                        $pdo->commit();
                        logAudit($pdo, 'DELETE', 'Affiliate Network', $id, ['updated' => $updated, 'offers_detached' => $offersDetached]);
                        echo json_encode(['status' => 'success', 'data' => ['updated' => $updated]]);
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                }
            }
            break;

        case 'bulk_delete_affiliate_networks':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'ids must be an array']);
                break;
            }
            $ids = array_values(array_unique(array_filter(array_map(function ($v) {
                return (int) $v;
            }, $ids), function ($v) {
                return $v > 0;
            })));
            if (empty($ids)) {
                echo json_encode(['status' => 'success', 'data' => ['updated' => 0]]);
                break;
            }
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                // Detach offers from these networks
                $stmtOffers = $pdo->prepare("UPDATE offers SET affiliate_network_id = NULL WHERE affiliate_network_id IN ($placeholders)");
                $stmtOffers->execute($ids);
                $offersUpdated = $stmtOffers->rowCount();

                $stmt = $pdo->prepare("UPDATE affiliate_networks SET is_archived = 1, archived_at = datetime('now') WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $updated = $stmt->rowCount();

                $pdo->commit();
                logAudit($pdo, 'DELETE', 'Affiliate Networks (bulk)', null, ['ids' => $ids, 'updated' => $updated, 'offers_detached' => $offersUpdated]);
                echo json_encode(['status' => 'success', 'data' => ['updated' => $updated, 'offers_detached' => $offersUpdated]]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'affiliate_network_templates':
            // Pre-defined templates for popular affiliate networks
            $templates = [
                [
                    'name' => 'generic',
                    'display_name' => 'Generic',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                // --- Platform-level templates: work with ANY network running on these platforms ---
                [
                    'name' => 'everflow',
                    'display_name' => 'Everflow (platform)',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'cake',
                    'display_name' => 'CAKE (platform)',
                    'offer_params_template' => '&s1={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'hitpath',
                    'display_name' => 'HitPath (platform)',
                    'offer_params_template' => '&c1={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'affise',
                    'display_name' => 'Affise (platform)',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'tune',
                    'display_name' => 'TUNE / HasOffers (platform)',
                    'offer_params_template' => '&aff_sub={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'onewin',
                    'display_name' => '1win.run',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={amount}&status=[REPLACE]&currency=[ISO]&from=1win.run',
                    'notes_template' => 'tpl.net_notes_onewin'
                ],
                [
                    'name' => 'twentytwobet',
                    'display_name' => '22BET.com',
                    'offer_params_template' => '&sub_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub_id}&status={status}&lead_status=reg&sale_status=ftd,bl&from=22BET.com'
                ],
                [
                    'name' => 'fourrabet',
                    'display_name' => '4rabetpartner.com',
                    'offer_params_template' => '&sub_id2={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub_id2}&payout={revenue}&currency={currency}&status={goal}&tid={id}&from=4rabetpartner.com'
                ],
                [
                    'name' => 'advertise',
                    'display_name' => 'Advertise.net',
                    'offer_params_template' => '&tid={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={tid}&payout={amount}&status={status}&lead_status=processing&sale_status=approved&rejected_status=rejected&offer_id={offer_id}&offer_name={offer_name}&source_name={source_name}&order_sum={amount}&currency={currency}&from=advertise.net'
                ],
                [
                    'name' => 'alfaleads',
                    'display_name' => 'Alfaleads.net',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?sub_id={sub1}&status=[REPLACE]&payout={sum}&from=alfaleads.net'
                ],
                [
                    'name' => 'appsflyer',
                    'display_name' => 'AppsFlyer.com',
                    'offer_params_template' => '&af_sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={Sub Param 1}&payout={Event Revenue USD}&tid={AppsFlyer ID}&currency=usd&status=sale&from=AppsFlyer.com'
                ],
                [
                    'name' => 'boomerang',
                    'display_name' => 'Boomerang-partners.com',
                    'offer_params_template' => '&visit_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=${visit_id}&status=REPLACE&from=boomerang-partners.com'
                ],
                [
                    'name' => 'biamo',
                    'display_name' => 'Biamopartners.com',
                    'offer_params_template' => '&subacc4={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={subacc4}&payout={revenue}&status={status}&lead_status=hold&sale_status=confirmed&tid={trans_id}&currency=usd&from=biamopartners.com'
                ],
                [
                    'name' => 'cataffs',
                    'display_name' => 'Cataffs.team',
                    'offer_params_template' => '&ClickID={subid}&WebID={campaign_id}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=${ClickID}&status=REPLACE&payout=XX&currency=usd&from=Cataffs.team'
                ],
                [
                    'name' => 'cpabro',
                    'display_name' => 'Cpabro.vip',
                    'offer_params_template' => '&track_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={track_id}&tid={action}&payout={income}&status={action}_{status}&currency={currency}&lead_status=reg_confirmed&sale_status=first_dep_confirmed&ignore_status=dep_confirmed&from=cpabro.vip'
                ],
                [
                    'name' => 'enot',
                    'display_name' => 'Enot.partners',
                    'offer_params_template' => '&aff_click_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={aff_click_id}&payout={payout}&currency=usd&status=REPLACE&from=enot.partners'
                ],
                [
                    'name' => 'gamblingpro',
                    'display_name' => 'Gambling.pro',
                    'offer_params_template' => '&pid={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?payout={income}&status={action}&subid={pid}&currency=usd&lead_status=reg&sale_status=first_dep&from=gambling.pro'
                ],
                [
                    'name' => 'ggbetaff',
                    'display_name' => 'GGBetAff',
                    'offer_params_template' => '&click_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=##CLICK_ID##&tid=##POSTBACK_ID##&status=REPLACE&payout=##CPA_AMOUNT##,##RS_AMOUNT##&currency=##CURRENCY##&from=GGBetAff',
                    'notes_template' => 'tpl.net_notes_ggbetaff'
                ],
                [
                    'name' => 'hellpartners',
                    'display_name' => 'Hellpartners.com',
                    'offer_params_template' => '&subid={subid}&dynamic={sub_id_1}&dynamic1={sub_id_2}&cpapayout={CHANGE_FOR_SUMM}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=[subid]&status=REPLACE&payout=[cpapayout]&currency=eur&tid=[tid]&from=Hellpartners.com',
                    'notes_template' => 'tpl.net_notes_hellpartners'
                ],
                [
                    'name' => 'jimpartners',
                    'display_name' => 'Jimpartners.com',
                    'offer_params_template' => '&clickid={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={clickid}&status=REPLACE&payout={paysum}&currency=USD&from=jimpartners.com'
                ],
                [
                    'name' => 'leadbit',
                    'display_name' => 'Leadbit.com',
                    'offer_params_template' => '&sub1={subid}&sub2={ip}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={cost}&status={status}&currency=usd&tid={id}&offer_landing={landing}&flow={flow}&lead_status=new&sale_status=confirm&rejected_status=reject,decline,invalid,trash&from=leadbit.com'
                ],
                [
                    'name' => 'm1shop',
                    'display_name' => 'M1-shop.ru',
                    'offer_params_template' => '&s={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?sub_id={s}&sub_id_1={w}&sub_id_2={t}&status={status}&lead_status=new&sale_status=approved&rejected_status=rejected,declined&payout={web_total}&currency=rub&from=M1-shop.ru'
                ],
                [
                    'name' => 'm4leads',
                    'display_name' => 'M4leads.com',
                    'offer_params_template' => '&sub_id1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=[sub_id1]&tid=[order_id]&payout=[price]&status=[status]&from=m4leads.com'
                ],
                [
                    'name' => 'mbpartners',
                    'display_name' => 'MB.partners',
                    'offer_params_template' => '&cid={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={cid}&status=REPLACE&payout={payout}&currency=usd&from=MB.partners',
                    'notes_template' => 'tpl.net_notes_mbpartners'
                ],
                [
                    'name' => 'melbetaffiliate',
                    'display_name' => 'Melbetaffiliates.com',
                    'offer_params_template' => '&click_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={click_id}&status=REPLACE&payout={amount}&currency=usd&from=Melbetaffiliates.com'
                ],
                [
                    'name' => 'mostbetcpa',
                    'display_name' => 'Mostbet.partners(cpa)',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={profit}&status={status}&lead_status=registration,active&sale_status=fd_approved,fdp&rejected_status=fd_rejected&sub_id_15={fdp_usd}&sub_id_16={id}&sub_id_17={landing}&sub_id_18={dep_sum_usd}&from=mostbet.partners(cpa)'
                ],
                [
                    'name' => 'mostbetrs',
                    'display_name' => 'Mostbet.partners(revshare)',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={rs}&status=REPLACE&currency=usd&sub_id_15={id}&sub_id_16={periodFrom}&sub_id_17={periodTill}&sub_id_18={dep_count}&sub_id_19={dep_sum}&sub_id_20={bid_win}&sub_id_21={bid_loss}&from=mostbet.partners(revshare)'
                ],
                [
                    'name' => 'nutramedia',
                    'display_name' => 'Nutra.Media',
                    'offer_params_template' => '&data1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={data1}&payout={profit}&status={status}&lead_status=waiting&sale_status=approved&rejected_status=declined,trash&currency=rub&from=Nutra.Media'
                ],
                [
                    'name' => 'cparip',
                    'display_name' => 'Partners.cpa.rip',
                    'offer_params_template' => '&aff_click_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={aff_click_id}&payout={payout}&status={goal_alias}&currency={offer_currency}&deposit_status=fdp,first_dep&rejected_status=rejected,trash&from=Partners.cpa.rip',
                    'notes_template' => 'tpl.net_notes_cparip'
                ],
                [
                    'name' => 'pinuppartners',
                    'display_name' => 'Pin-up.partners',
                    'offer_params_template' => '&subId1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={subId1}&payout={payment}&currency={currency}&status=[REPLACE]&from=pin-up.partners'
                ],
                [
                    'name' => 'profitov',
                    'display_name' => 'Profitov.Partners',
                    'offer_params_template' => '&sub4={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub4}&status=REPLACE&tid={transactionid}_{goal}&payout={sum}&currency={currency}&from=Profitov.Partners'
                ],
                [
                    'name' => 'q3network',
                    'display_name' => 'Q3.network',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={sum}&status=[REPLACE]&currency=[REPLACE]&from=q3.network'
                ],
                [
                    'name' => 'riddick',
                    'display_name' => 'Riddick.guru',
                    'offer_params_template' => '&subids.1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?sub_id={subids.1}&status={status}&payout={revenue}&lead_status=in_progress&sale_status=confirmed&rejected_status=cancelled,invalid&currency={currency}&from=Riddick.guru'
                ],
                [
                    'name' => 'royalpartners',
                    'display_name' => 'Royal.partners',
                    'offer_params_template' => '&ctag={subid}&btag={campaign_id}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=${ctag}&status=REPLACE&payout=${amount}&currency=${currency}&tid=${deposit_id}&player_id=${player_id}&from=Royal.partners',
                    'notes_template' => 'tpl.net_notes_royalpartners'
                ],
                [
                    'name' => 'vulkanbet',
                    'display_name' => 'Vulkan.bet',
                    'offer_params_template' => '&CLICK_ID={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=##CLICK_ID##&tid=##TRANS_ID##&payout=##CPA_AMOUNT##&currency=eur&status=replace&sub_id_1=##IP_REG##&sub_id_2=##POSTBACK_ID##&from=Vulkan.bet'
                ],
                [
                    'name' => 'vulkanpartner',
                    'display_name' => 'Vulkanpartner.com',
                    'offer_params_template' => '&param1={subid}&utm_source={source}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={param1}&tid={conversion_id}&payout={commission_total}&status={conversion_status}&currency=usd&offer_id={offer_id}&offer_title={offer_title}&from=vulkanpartner.com'
                ],
                [
                    'name' => 'welcomepartners',
                    'display_name' => 'WelcomePartners',
                    'offer_params_template' => '&click_id={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=##CLICK_ID##&tid=##POSTBACK_ID##&status=reg,dep,lead,approve,reject,rebill&lead_status=reg,dep,lead&sale_status=approve,rebill&rejected_status=reject&payout=##CPA_AMOUNT##,##RS_AMOUNT##&currency=##CURRENCY##&from=WelcomePartners',
                    'notes_template' => 'tpl.net_notes_welcomepartners'
                ],
                [
                    'name' => 'xpartners',
                    'display_name' => 'X-partners.com',
                    'offer_params_template' => '&sub1={subid}',
                    'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&status={status}&payout={sum}&currency={currency}&lead_status=1%2C2&rejected_status=3&from=x-partners.com'
                ],
                [
                    'name' => 'drcash',
                    'display_name' => 'Dr.Cash',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'adcombo',
                    'display_name' => 'AdCombo',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'partners1xbet',
                    'display_name' => 'Partners1xBet',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'traffic_light',
                    'display_name' => 'Traffic Light',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'lemonad',
                    'display_name' => 'LemonAD',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'custom',
                    'display_name' => 'Custom network',
                    'offer_params_template' => '',
                    'postback_url_template' => ''
                ],
            ];
            $templates = orbitraMergeTemplates($templates, orbitraLoadTemplatePack('keitaro_affiliate_networks.json'));
            echo json_encode(['status' => 'success', 'data' => $templates]);
            break;

        case 'logs':
            $type = $_GET['type'] ?? 'traffic';

            // Strictly limit dashboard requests to 20 for performance 
            if (isset($_GET['dashboard']) && $_GET['dashboard'] === 'true') {
                $limit = 20;
            } else {
                $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
            }
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

            if ($type === 'traffic') {
                $stmt = $pdo->prepare("
                    SELECT
                        cl.id,
                        cl.id as click_id,
                        datetime(cl.created_at, '$dbTzOffset') as created_at,
                        c.name as campaign_name,
                        cl.ip,
                        COALESCE(NULLIF(cl.country_code, ''), cl.country) as country_code,
                        cl.region,
                        cl.city,
                        cl.timezone as geo_timezone,
                        cl.language,
                        cl.accept_language_raw,
                        cl.device_type,
                        cl.user_agent,
                        o.url as redirect_url,
                        '' as subid
                    FROM clicks cl
                    LEFT JOIN campaigns c ON cl.campaign_id = c.id
                    LEFT JOIN offers o ON cl.offer_id = o.id
                    ORDER BY cl.created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            } elseif ($type === 'postbacks') {
                $stmt = $pdo->prepare("
                    SELECT
                        conv.id,
                        conv.click_id,
                        conv.status,
                        conv.original_status,
                        conv.payout,
                        conv.currency,
                        datetime(conv.created_at, '$dbTzOffset') as created_at,
                        cl.campaign_id,
                        c.name as campaign_name
                    FROM conversions conv
                    LEFT JOIN clicks cl ON conv.click_id = cl.id
                    LEFT JOIN campaigns c ON cl.campaign_id = c.id
                    ORDER BY conv.created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            } elseif ($type === 'system') {
                $stmt = $pdo->prepare("SELECT *, datetime(created_at, '$dbTzOffset') as created_at FROM system_logs ORDER BY created_at DESC LIMIT ? OFFSET ?");
                $stmt->execute([$limit, $offset]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            } elseif ($type === 'audit') {
                $stmt = $pdo->prepare("SELECT *, datetime(created_at, '$dbTzOffset') as created_at FROM audit_logs ORDER BY created_at DESC LIMIT ? OFFSET ?");
                $stmt->execute([$limit, $offset]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            } elseif ($type === 's2s') {
                $stmt = $pdo->prepare("SELECT *, datetime(created_at, '$dbTzOffset') as created_at FROM s2s_postbacks_log ORDER BY created_at DESC LIMIT ? OFFSET ?");
                $stmt->execute([$limit, $offset]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Неизвестный тип логов']);
            }
            break;

        case 'click_details':
            $clickId = $_GET['id'] ?? null;
            if (!$clickId) {
                echo json_encode(['status' => 'error', 'message' => 'Не указан ID клика']);
                break;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    cl.*,
                    c.name as campaign_name,
                    c.alias as campaign_alias,
                    o.name as offer_name,
                    l.name as landing_name,
                    s.name as source_name,
                    st.type as stream_type,
                    an.name as affiliate_network_name
                FROM clicks cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                LEFT JOIN offers o ON cl.offer_id = o.id
                LEFT JOIN landings l ON cl.landing_id = l.id
                LEFT JOIN traffic_sources s ON cl.source_id = s.id
                LEFT JOIN streams st ON cl.stream_id = st.id
                LEFT JOIN affiliate_networks an ON o.affiliate_network_id = an.id
                WHERE cl.id = ?
                LIMIT 1
            ");
            $stmt->execute([$clickId]);
            $clickInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($clickInfo) {
                if ($clickInfo['parameters_json']) {
                    $clickInfo['parameters'] = json_decode($clickInfo['parameters_json'], true);
                } else {
                    $clickInfo['parameters'] = [];
                }
                echo json_encode(['status' => 'success', 'data' => $clickInfo]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Клик не найден']);
            }
            break;

        case 'fix_nginx':
            // Historically this hand-rolled its own nginx config: it hardcoded the
            // php8.3-fpm socket, copied via sudo with no `nginx -t`, and knew nothing
            // about HTTPS or the admin catch-all. That duplicated core/nginx_config.php
            // and re-introduced exactly the bugs the shared generator fixed. It now
            // delegates to that generator (the same path regenerate_nginx uses), so a
            // "fix my nginx" call produces a config that is actually tested and reloads
            // safely. The frontend never called this action directly, but it stays
            // reachable for anything that still posts action=fix_nginx.
            try {
                $result = updateNginxConfig($pdo);
                if ($result['status'] === 'success') {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Nginx configuration regenerated successfully',
                        'result' => $result
                    ]);
                } else if ($result['status'] === 'skip') {
                    echo json_encode([
                        'status' => 'skip',
                        'message' => 'No domains in database',
                        'result' => $result
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => $result['message'] ?? 'Failed to regenerate nginx configuration',
                        'result' => $result
                    ]);
                }
            } catch (\Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'regenerate_nginx':
            // Regenerate full Nginx configuration with HTTP and HTTPS blocks
            // This restores proper config after fix_nginx.sh removed HTTPS blocks
            try {
                $result = updateNginxConfig($pdo);

                if ($result['status'] === 'success') {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Nginx configuration regenerated successfully',
                        'result' => $result
                    ]);
                } else if ($result['status'] === 'skip') {
                    echo json_encode([
                        'status' => 'skip',
                        'message' => 'No domains in database',
                        'result' => $result
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to regenerate config',
                        'result' => $result
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            break;

        case 'domains':
            // Try multiple methods to get server IP
            $serverIp = '127.0.0.1'; // Default fallback

            // Method 1: $_SERVER['SERVER_ADDR'] (web request)
            if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '') {
                $serverIp = $_SERVER['SERVER_ADDR'];
            }
            // Method 2: Resolve hostname from HTTP_HOST
            elseif (isset($_SERVER['HTTP_HOST'])) {
                $hostname = explode(':', $_SERVER['HTTP_HOST'])[0];
                $hostIp = @gethostbyname($hostname);
                if ($hostIp !== $hostname) {
                    $serverIp = $hostIp;
                }
            }
            // Method 3: Use external service as last resort (cached for 1 hour)
            else {
                $cacheFile = __DIR__ . '/var/server_ip_cache.txt';
                if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
                    $serverIp = trim(file_get_contents($cacheFile));
                } else {
                    // Try to get public IP from external service
                    $publicIp = @file_get_contents('http://169.254.169.254/latest/meta-data/public-ipv4'); // AWS
                    if (!$publicIp) {
                        $publicIp = @file_get_contents('http://checkip.amazonaws.com');
                    }
                    if ($publicIp && filter_var($publicIp, FILTER_VALIDATE_IP)) {
                        $serverIp = trim($publicIp);
                        @file_put_contents($cacheFile, $serverIp);
                    }
                }
            }

            $stmt = $pdo->query("
                SELECT d.*, c.name as index_campaign_name
                FROM domains d
                LEFT JOIN campaigns c ON d.index_campaign_id = c.id
                ORDER BY d.created_at DESC
            ");
            $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // DNS Cache TTL: 30 minutes (1800 seconds) - increased for better performance
            $dnsCacheTtl = 1800;
            $currentTime = time();
            $needsUpdate = [];
            $forceRefresh = isset($_GET['force_refresh']) && $_GET['force_refresh'] === '1';

            // Limit DNS lookups per request for performance (check 20 domains without cache at a time)
            $maxDnsLookups = 20;
            $dnsLookupsCount = 0;

            // Compute dynamic DNS status with caching
            // ONLY refresh if force_refresh=1 or cache is completely missing
            foreach ($domains as &$domain) {
                $domainId = (int)$domain['id'];
                $hasCachedStatus = !empty($domain['dns_status']);
                $cacheAge = 0;

                if (!empty($domain['dns_checked_at'])) {
                    $cachedTime = strtotime($domain['dns_checked_at']);
                    if ($cachedTime) {
                        $cacheAge = $currentTime - $cachedTime;
                    }
                }

                // Use cached status if available (even if old) - for fast page load
                // Only do DNS lookup if: force_refresh=1 OR no cached status at all
                if ($hasCachedStatus && !$forceRefresh) {
                    // Use cached status regardless of age for instant page load
                    $domain['status'] = $domain['dns_status'];
                    $domain['cache_age'] = $cacheAge;
                } elseif (!$hasCachedStatus || $forceRefresh) {
                    // Only do DNS lookup for domains without status OR when explicitly requested
                    // Limit DNS lookups per request to prevent slow page loads with many domains

                    // Skip DNS lookup if we've reached the limit and not forcing refresh
                    // This ensures ALL domains eventually get checked, just not all at once
                    if (!$hasCachedStatus && !$forceRefresh && $dnsLookupsCount >= $maxDnsLookups) {
                        $domain['status'] = 'checking';
                    } else {
                        // Perform DNS lookup
                        $domainIp = @gethostbyname($domain['name']);

                        // Debug logging
                        error_log("DNS Check: {$domain['name']} -> {$domainIp} (server: {$serverIp})");

                        // More robust IP matching - trim whitespace and handle both IPv4 and IPv6
                        $domainIp = trim($domainIp);
                        $serverIp = trim($serverIp);

                        if ($domainIp === $serverIp) {
                            $domain['status'] = 'active';
                            error_log("DNS Match: {$domain['name']} is ACTIVE");
                        } elseif ($domainIp === '127.0.0.1' || $serverIp === '127.0.0.1') {
                            // Localhost environment - consider as active
                            $domain['status'] = 'active';
                            error_log("DNS Localhost: {$domain['name']} marked ACTIVE (localhost)");
                        } elseif ($domainIp === $domain['name']) {
                            // DNS lookup failed - domain doesn't resolve
                            $domain['status'] = 'pending';
                            error_log("DNS Failed: {$domain['name']} does not resolve");
                        } else {
                            // Domain resolves but to different IP
                            $domain['status'] = 'pending';
                            error_log("DNS Mismatch: {$domain['name']} resolves to {$domainIp}, expected {$serverIp}");
                        }

                        // Mark for database update
                        $needsUpdate[] = [
                            'id' => $domainId,
                            'status' => $domain['status'],
                            'ip' => $domainIp
                        ];

                        // Increment DNS lookup counter only for non-cached domains
                        if (!$hasCachedStatus) {
                            $dnsLookupsCount++;
                        }
                    }
                } else {
                    // Has cached status - use it
                    $domain['status'] = $domain['dns_status'];
                    $domain['cache_age'] = $cacheAge;
                }
            }

            // Batch update DNS cache in database (only if we did lookups)
            if (!empty($needsUpdate)) {
                $updateStmt = $pdo->prepare("UPDATE domains SET dns_status = ?, dns_ip = ?, dns_checked_at = CURRENT_TIMESTAMP WHERE id = ?");
                foreach ($needsUpdate as $update) {
                    $updateStmt->execute([$update['status'], $update['ip'], $update['id']]);
                }
            }

            echo json_encode(['status' => 'success', 'data' => $domains, 'server_ip' => $serverIp]);
            break;

        // Check DNS status for a single domain (non-blocking)
        case 'check_domain_dns':
            $domainId = $_GET['id'] ?? null;
            if (!$domainId) {
                echo json_encode(['status' => 'error', 'message' => 'Missing domain ID']);
                break;
            }

            $stmt = $pdo->prepare("SELECT id, name FROM domains WHERE id = ?");
            $stmt->execute([$domainId]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$domain) {
                echo json_encode(['status' => 'error', 'message' => 'Domain not found']);
                break;
            }

            // Try multiple methods to get server IP
            $serverIp = '127.0.0.1'; // Default fallback

            // Method 1: $_SERVER['SERVER_ADDR'] (web request)
            if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '') {
                $serverIp = $_SERVER['SERVER_ADDR'];
            }
            // Method 2: Resolve hostname from HTTP_HOST
            elseif (isset($_SERVER['HTTP_HOST'])) {
                $hostname = explode(':', $_SERVER['HTTP_HOST'])[0];
                $hostIp = @gethostbyname($hostname);
                if ($hostIp !== $hostname) {
                    $serverIp = $hostIp;
                }
            }
            // Method 3: Use external service as last resort (cached for 1 hour)
            else {
                $cacheFile = __DIR__ . '/var/server_ip_cache.txt';
                if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
                    $serverIp = trim(file_get_contents($cacheFile));
                } else {
                    // Try to get public IP from external service
                    $publicIp = @file_get_contents('http://169.254.169.254/latest/meta-data/public-ipv4'); // AWS
                    if (!$publicIp) {
                        $publicIp = @file_get_contents('http://checkip.amazonaws.com');
                    }
                    if ($publicIp && filter_var($publicIp, FILTER_VALIDATE_IP)) {
                        $serverIp = trim($publicIp);
                        @file_put_contents($cacheFile, $serverIp);
                    }
                }
            }

            // Do DNS lookup
            $domainIp = @gethostbyname($domain['name']);
            $status = 'pending';
            if ($domainIp === $serverIp || $domainIp === '127.0.0.1' || $serverIp === '127.0.0.1') {
                $status = 'active';
            }

            // Update cache
            $updateStmt = $pdo->prepare("UPDATE domains SET dns_status = ?, dns_ip = ?, dns_checked_at = CURRENT_TIMESTAMP WHERE id = ?");
            $updateStmt->execute([$status, $domainIp, $domainId]);

            echo json_encode(['status' => 'success', 'data' => [
                'id' => $domain['id'],
                'name' => $domain['name'],
                'dns_status' => $status,
                'dns_ip' => $domainIp
            ]]);
            break;

        // Force DNS check for ALL domains (no limits)
        case 'force_check_all_dns':
            // Try multiple methods to get server IP
            $serverIp = '127.0.0.1'; // Default fallback

            // Method 1: $_SERVER['SERVER_ADDR'] (web request)
            if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '') {
                $serverIp = $_SERVER['SERVER_ADDR'];
            }
            // Method 2: Resolve hostname from HTTP_HOST
            elseif (isset($_SERVER['HTTP_HOST'])) {
                $hostname = explode(':', $_SERVER['HTTP_HOST'])[0];
                $hostIp = @gethostbyname($hostname);
                if ($hostIp !== $hostname) {
                    $serverIp = $hostIp;
                }
            }
            // Method 3: Use external service as last resort (cached for 1 hour)
            else {
                $cacheFile = __DIR__ . '/var/server_ip_cache.txt';
                if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
                    $serverIp = trim(file_get_contents($cacheFile));
                } else {
                    // Try to get public IP from external service
                    $publicIp = @file_get_contents('http://169.254.169.254/latest/meta-data/public-ipv4'); // AWS
                    if (!$publicIp) {
                        $publicIp = @file_get_contents('http://checkip.amazonaws.com');
                    }
                    if ($publicIp && filter_var($publicIp, FILTER_VALIDATE_IP)) {
                        $serverIp = trim($publicIp);
                        @file_put_contents($cacheFile, $serverIp);
                    }
                }
            }

            // Get all domains
            $stmt = $pdo->query("SELECT id, name, dns_status FROM domains ORDER BY id ASC");
            $allDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            $updateStmt = $pdo->prepare("UPDATE domains SET dns_status = ?, dns_ip = ?, dns_checked_at = CURRENT_TIMESTAMP WHERE id = ?");

            foreach ($allDomains as $domain) {
                // Do DNS lookup for EACH domain (no limits)
                $domainIp = @gethostbyname($domain['name']);
                $domainIp = trim($domainIp);
                $serverIp = trim($serverIp);

                // Determine status
                if ($domainIp === $serverIp) {
                    $status = 'active';
                } elseif ($domainIp === '127.0.0.1' || $serverIp === '127.0.0.1') {
                    $status = 'active';
                } elseif ($domainIp === $domain['name']) {
                    $status = 'pending';
                } else {
                    $status = 'pending';
                }

                // Update database
                $updateStmt->execute([$status, $domainIp, $domain['id']]);

                $results[] = [
                    'id' => $domain['id'],
                    'name' => $domain['name'],
                    'dns_status' => $status,
                    'dns_ip' => $domainIp
                ];
            }

            echo json_encode(['status' => 'success', 'data' => $results, 'server_ip' => $serverIp]);
            break;

        // === Backorder / Domain Availability Tracker ===
        case 'backorder_domains':
            $stmt = $pdo->query("
                SELECT
                    id,
                    name,
                    COALESCE(NULLIF(status, ''), 'unknown') as status,
                    notes,
                    ahrefs_dr,
                    ahrefs_ur,
                    ahrefs_ref_domains,
                    created_at,
                    last_checked_at,
                    last_http_code,
                    last_error,
                    last_rdap_url,
                    last_result_json
                FROM backorder_domains
                ORDER BY
                    CASE COALESCE(NULLIF(status, ''), 'unknown')
                        WHEN 'available' THEN 0
                        WHEN 'unknown' THEN 1
                        WHEN 'rate_limited' THEN 2
                        WHEN 'error' THEN 3
                        WHEN 'unsupported' THEN 4
                        WHEN 'registered' THEN 9
                        ELSE 5
                    END,
                    COALESCE(last_checked_at, created_at) ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        case 'backorder_cron_info':
            // Small helper endpoint for UI: show cron command, status, and a few diagnostics.
            // Note: cron scheduling still happens at OS level; UI only helps users configure it.
            $scriptPath = realpath(__DIR__ . '/backorder_cron.php');
            if (!is_string($scriptPath) || $scriptPath === '') {
                $scriptPath = __DIR__ . '/backorder_cron.php';
            }

            // Best-effort: detect the PHP process user (useful for /etc/cron.d user field).
            $phpUser = 'www-data';
            if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
                $pw = @posix_getpwuid(@posix_geteuid());
                if (is_array($pw) && !empty($pw['name']) && is_string($pw['name'])) {
                    $phpUser = $pw['name'];
                }
            }

            $logDir = __DIR__ . '/var/log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logPath = $logDir . '/backorder_cron.log';

            $cronFile = '/etc/cron.d/orbitra-backorder';
            $cronDirWritable = is_dir('/etc/cron.d') && is_writable('/etc/cron.d');
            $cronFileExists = is_file($cronFile);

            // Detect whether we can manage user crontab from PHP (no root required, but shell_exec must be allowed).
            $disableFunctions = (string) ini_get('disable_functions');
            $shellExecAllowed = function_exists('shell_exec') && (stripos($disableFunctions, 'shell_exec') === false);
            $crontabPath = $shellExecAllowed ? trim((string) orbitraShell('command -v crontab 2>/dev/null')) : '';
            $crontabAvailable = $crontabPath !== '';
            $userCrontabInstalled = 0;
            if ($crontabAvailable) {
                $existing = (string) orbitraShell('crontab -l 2>/dev/null');
                if ($existing !== '' && strpos($existing, 'ORBITRA_BACKORDER_BEGIN') !== false) {
                    $userCrontabInstalled = 1;
                }
            }

            $keys = [
                'backorder_cron_enabled',
                'backorder_cron_last_ping_at',
                'backorder_cron_last_checked_at',
                'backorder_cron_last_domain',
                'backorder_cron_last_status',
                'backorder_cron_last_http_code',
                'backorder_cron_last_error',
            ];
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
            $stmt->execute($keys);
            $s = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $s[(string) $row['key']] = (string) $row['value'];
            }

            $enabled = $s['backorder_cron_enabled'] ?? '1';

            // Re-check interval for "due" domains (used by cron + UI auto-run). Stored as seconds.
            $checkIntervalSec = 900;
            try {
                $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_sec'")->fetchColumn();
                if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
                    $checkIntervalSec = max(15, (int) $v);
                }
            } catch (Throwable $e) {
                // ignore
            }

            $total = (int) ($pdo->query("SELECT COUNT(*) FROM backorder_domains")->fetchColumn() ?: 0);
            $neverChecked = (int) ($pdo->query("SELECT COUNT(*) FROM backorder_domains WHERE last_checked_at IS NULL")->fetchColumn() ?: 0);

            $bootstrapFile = __DIR__ . '/var/cache/rdap_dns_bootstrap.json';
            $bootstrapMtime = is_file($bootstrapFile) ? (filemtime($bootstrapFile) ?: 0) : 0;
            $bootstrapMtimeStr = $bootstrapMtime > 0 ? date('Y-m-d H:i:s', $bootstrapMtime) : null;
            $bootstrapAgeSeconds = $bootstrapMtime > 0 ? max(0, time() - $bootstrapMtime) : null;

            $cronEvery3min = "*/3 * * * * php " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logPath) . " 2>&1";
            $cronEvery1min = "* * * * * php " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logPath) . " 2>&1";

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'enabled' => $enabled,
                    'php_user' => $phpUser,
                    'check_interval_sec' => $checkIntervalSec,
                    'script_path' => $scriptPath,
                    'log_path' => $logPath,
                    'cron_file' => $cronFile,
                    'cron_dir_writable' => $cronDirWritable ? 1 : 0,
                    'cron_file_exists' => $cronFileExists ? 1 : 0,
                    'shell_exec_allowed' => $shellExecAllowed ? 1 : 0,
                    'crontab_path' => $crontabPath ?: null,
                    'user_crontab_installed' => $userCrontabInstalled,
                    'cron_examples' => [
                        ['id' => 'every_3_min', 'label' => '*/3 * * * *', 'value' => $cronEvery3min],
                        ['id' => 'every_1_min', 'label' => '* * * * *', 'value' => $cronEvery1min],
                    ],
                    'last_ping_at' => $s['backorder_cron_last_ping_at'] ?? null,
                    'last_checked_at' => $s['backorder_cron_last_checked_at'] ?? null,
                    'last_domain' => $s['backorder_cron_last_domain'] ?? null,
                    'last_status' => $s['backorder_cron_last_status'] ?? null,
                    'last_http_code' => $s['backorder_cron_last_http_code'] ?? null,
                    'last_error' => $s['backorder_cron_last_error'] ?? null,
                    'domains' => [
                        'total' => $total,
                        'never_checked' => $neverChecked,
                    ],
                    'rdap_bootstrap' => [
                        'cache_file' => $bootstrapFile,
                        'mtime' => $bootstrapMtimeStr,
                        'age_seconds' => $bootstrapAgeSeconds,
                        'ttl_seconds' => 604800,
                    ],
                ]
            ]);
            break;

        case 'backorder_install_user_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            $disableFunctions = (string) ini_get('disable_functions');
            if (!function_exists('shell_exec') || (stripos($disableFunctions, 'shell_exec') !== false)) {
                echo json_encode(['status' => 'error', 'message' => 'shell_exec is disabled on this server']);
                break;
            }

            $crontabPath = trim((string) orbitraShell('command -v crontab 2>/dev/null'));
            if ($crontabPath === '') {
                echo json_encode(['status' => 'error', 'message' => 'crontab command not found']);
                break;
            }

            $scriptPath = realpath(__DIR__ . '/backorder_cron.php');
            if (!is_string($scriptPath) || $scriptPath === '') {
                $scriptPath = __DIR__ . '/backorder_cron.php';
            }
            $logDir = __DIR__ . '/var/log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logPath = $logDir . '/backorder_cron.log';

            $phpPath = trim((string) orbitraShell('command -v php 2>/dev/null'));
            if ($phpPath === '') {
                $phpPath = 'php';
            }

            $line = "*/3 * * * * $phpPath " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logPath) . " 2>&1";
            $block = "# ORBITRA_BACKORDER_BEGIN\n" . $line . "\n# ORBITRA_BACKORDER_END\n";

            $existing = (string) orbitraShell('crontab -l 2>/dev/null');
            // Remove existing block if present.
            $new = preg_replace("/\\n?# ORBITRA_BACKORDER_BEGIN[\\s\\S]*?# ORBITRA_BACKORDER_END\\n?/m", "\n", $existing);
            $new = trim((string) $new);
            if ($new !== '') {
                $new .= "\n\n";
            }
            $new .= $block;

            $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_crontab_');
            if (!is_string($tmp) || $tmp === '') {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create temp file']);
                break;
            }
            @file_put_contents($tmp, $new);
            $out = (string) orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
            @unlink($tmp);

            // If error, crontab usually prints it.
            if (stripos($out, 'error') !== false) {
                echo json_encode(['status' => 'error', 'message' => trim($out) ?: 'crontab failed']);
                break;
            }

            echo json_encode(['status' => 'success', 'data' => ['line' => $line]]);
            break;

        case 'backorder_remove_user_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            $disableFunctions = (string) ini_get('disable_functions');
            if (!function_exists('shell_exec') || (stripos($disableFunctions, 'shell_exec') !== false)) {
                echo json_encode(['status' => 'error', 'message' => 'shell_exec is disabled on this server']);
                break;
            }

            $crontabPath = trim((string) orbitraShell('command -v crontab 2>/dev/null'));
            if ($crontabPath === '') {
                echo json_encode(['status' => 'error', 'message' => 'crontab command not found']);
                break;
            }

            $existing = (string) orbitraShell('crontab -l 2>/dev/null');
            $new = preg_replace("/\\n?# ORBITRA_BACKORDER_BEGIN[\\s\\S]*?# ORBITRA_BACKORDER_END\\n?/m", "\n", $existing);
            $new = trim((string) $new) . "\n";

            $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_crontab_');
            if (!is_string($tmp) || $tmp === '') {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create temp file']);
                break;
            }
            @file_put_contents($tmp, $new);
            $out = (string) orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
            @unlink($tmp);

            if (stripos($out, 'error') !== false) {
                echo json_encode(['status' => 'error', 'message' => trim($out) ?: 'crontab failed']);
                break;
            }

            echo json_encode(['status' => 'success', 'data' => ['deleted' => 1]]);
            break;

        case 'postback_queue_info':
            // Health/state of the outbound postback delivery worker, for the
            // Automation settings panel. Read-only, admin session already enforced above.
            $pqSettings = [];
            try {
                $rows = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'postback_queue_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
                if (is_array($rows)) {
                    $pqSettings = $rows;
                }
            } catch (\Throwable $e) {
                // Ignore: settings may be empty before the first run.
            }

            $pqCounts = ['pending' => 0, 'in_flight' => 0, 'delivered' => 0, 'failed' => 0];
            try {
                $cntStmt = $pdo->query("SELECT status, COUNT(*) AS c FROM s2s_postbacks_log GROUP BY status");
                foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pqCounts[(string) ($r['status'] ?? 'delivered')] = (int) $r['c'];
                }
            } catch (\Throwable $e) {
                // Column may not exist yet on a partially migrated DB; leave zeros.
            }

            $pqScript = realpath(__DIR__ . '/postback_queue_cron.php');
            if (!is_string($pqScript) || $pqScript === '') {
                $pqScript = __DIR__ . '/postback_queue_cron.php';
            }

            $pqShellOk = function_exists('shell_exec') && stripos((string) ini_get('disable_functions'), 'shell_exec') === false;
            $pqCrontabInstalled = false;
            if ($pqShellOk) {
                $pqCrontab = (string) orbitraShell('crontab -l 2>/dev/null');
                $pqCrontabInstalled = strpos($pqCrontab, 'ORBITRA_POSTBACK_QUEUE_BEGIN') !== false;
            }

            // "Healthy" = the worker pinged within the last 5 minutes. It runs every
            // minute, so a longer gap means cron is not firing.
            $pqLastPing = $pqSettings['postback_queue_last_ping_at'] ?? null;
            $pqPingAge = null;
            if ($pqLastPing) {
                // The worker writes this with date() (server-local), so parse it the same way.
                $pqPingAge = max(0, time() - (int) strtotime((string) $pqLastPing));
            }

            echo json_encode(['status' => 'success', 'data' => [
                'enabled'                => ($pqSettings['postback_queue_enabled'] ?? '1') !== '0',
                'user_crontab_installed' => $pqCrontabInstalled,
                'shell_exec_allowed'     => $pqShellOk,
                'script_path'            => $pqScript,
                'last_ping_at'           => $pqLastPing,
                'last_ping_age_seconds'  => $pqPingAge,
                'healthy'                => $pqPingAge !== null && $pqPingAge < 300,
                'last_error'             => $pqSettings['postback_queue_last_error'] ?? null,
                'last_run'               => [
                    'processed' => (int) ($pqSettings['postback_queue_last_run_processed'] ?? 0),
                    'delivered' => (int) ($pqSettings['postback_queue_last_run_delivered'] ?? 0),
                    'requeued'  => (int) ($pqSettings['postback_queue_last_run_requeued'] ?? 0),
                    'failed'    => (int) ($pqSettings['postback_queue_last_run_failed'] ?? 0),
                ],
                'counts'                 => $pqCounts,
                'cron_line'              => '* * * * * php ' . $pqScript . ' >> ' . __DIR__ . '/var/log/postback_queue.log 2>&1',
            ]]);
            break;

        case 'postback_queue_install_user_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            $disableFunctions = (string) ini_get('disable_functions');
            if (!function_exists('shell_exec') || (stripos($disableFunctions, 'shell_exec') !== false)) {
                echo json_encode(['status' => 'error', 'message' => 'shell_exec is disabled on this server']);
                break;
            }

            $crontabPath = trim((string) orbitraShell('command -v crontab 2>/dev/null'));
            if ($crontabPath === '') {
                echo json_encode(['status' => 'error', 'message' => 'crontab command not found']);
                break;
            }

            $scriptPath = realpath(__DIR__ . '/postback_queue_cron.php');
            if (!is_string($scriptPath) || $scriptPath === '') {
                $scriptPath = __DIR__ . '/postback_queue_cron.php';
            }
            $logDir = __DIR__ . '/var/log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logPath = $logDir . '/postback_queue.log';

            $phpPath = trim((string) orbitraShell('command -v php 2>/dev/null'));
            if ($phpPath === '') {
                $phpPath = 'php';
            }

            // Every minute: the backoff schedule is in seconds, so frequent runs keep
            // delivery latency low without busy-waiting (due-query filters by next_retry_at).
            $line = "* * * * * $phpPath " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logPath) . " 2>&1";
            $block = "# ORBITRA_POSTBACK_QUEUE_BEGIN\n" . $line . "\n# ORBITRA_POSTBACK_QUEUE_END\n";

            $existing = (string) orbitraShell('crontab -l 2>/dev/null');
            $new = preg_replace("/\\n?# ORBITRA_POSTBACK_QUEUE_BEGIN[\\s\\S]*?# ORBITRA_POSTBACK_QUEUE_END\\n?/m", "\n", $existing);
            $new = trim((string) $new);
            if ($new !== '') {
                $new .= "\n\n";
            }
            $new .= $block;

            $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_crontab_');
            if (!is_string($tmp) || $tmp === '') {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create temp file']);
                break;
            }
            @file_put_contents($tmp, $new);
            $out = (string) orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
            @unlink($tmp);

            if (stripos($out, 'error') !== false) {
                echo json_encode(['status' => 'error', 'message' => trim($out) ?: 'crontab failed']);
                break;
            }

            // Flip the worker on so the just-installed cron actually delivers.
            $stmt = $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('postback_queue_enabled', '1', datetime('now')) ON CONFLICT(key) DO UPDATE SET value = '1', updated_at = datetime('now')");
            $stmt->execute();

            echo json_encode(['status' => 'success', 'data' => ['line' => $line]]);
            break;

        case 'postback_queue_remove_user_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            $disableFunctions = (string) ini_get('disable_functions');
            if (!function_exists('shell_exec') || (stripos($disableFunctions, 'shell_exec') !== false)) {
                echo json_encode(['status' => 'error', 'message' => 'shell_exec is disabled on this server']);
                break;
            }

            $crontabPath = trim((string) orbitraShell('command -v crontab 2>/dev/null'));
            if ($crontabPath === '') {
                echo json_encode(['status' => 'error', 'message' => 'crontab command not found']);
                break;
            }

            $existing = (string) orbitraShell('crontab -l 2>/dev/null');
            $new = preg_replace("/\\n?# ORBITRA_POSTBACK_QUEUE_BEGIN[\\s\\S]*?# ORBITRA_POSTBACK_QUEUE_END\\n?/m", "\n", $existing);
            $new = trim((string) $new) . "\n";

            $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_crontab_');
            if (!is_string($tmp) || $tmp === '') {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create temp file']);
                break;
            }
            @file_put_contents($tmp, $new);
            $out = (string) orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
            @unlink($tmp);

            if (stripos($out, 'error') !== false) {
                echo json_encode(['status' => 'error', 'message' => trim($out) ?: 'crontab failed']);
                break;
            }

            echo json_encode(['status' => 'success', 'data' => ['deleted' => 1]]);
            break;

        case 'backorder_install_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            $cronDir = '/etc/cron.d';
            $cronFile = $cronDir . '/orbitra-backorder';
            if (!is_dir($cronDir) || !is_writable($cronDir)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No permission to write ' . $cronFile . '. Run the install command on the server as root.',
                ]);
                break;
            }

            $scriptPath = realpath(__DIR__ . '/backorder_cron.php');
            if (!is_string($scriptPath) || $scriptPath === '') {
                $scriptPath = __DIR__ . '/backorder_cron.php';
            }

            $logDir = __DIR__ . '/var/log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logPath = $logDir . '/backorder_cron.log';

            $phpPath = trim((string) orbitraShell('command -v php 2>/dev/null'));
            if ($phpPath === '') {
                $phpPath = '/usr/bin/php';
            }

            // cron.d format requires an explicit user field.
            $runUser = 'www-data';
            if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
                $pw = @posix_getpwuid(@posix_geteuid());
                if (is_array($pw) && !empty($pw['name']) && is_string($pw['name'])) {
                    $runUser = $pw['name'];
                }
            }
            $line = "*/3 * * * * $runUser $phpPath " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logPath) . " 2>&1";
            $content = "# Orbitra backorder checks (installed via UI)\n" . $line . "\n";

            $ok = @file_put_contents($cronFile, $content);
            if ($ok === false) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to write cron file: ' . $cronFile]);
                break;
            }
            @chmod($cronFile, 0644);

            echo json_encode(['status' => 'success', 'data' => ['cron_file' => $cronFile, 'line' => $line]]);
            break;

        case 'backorder_remove_cron':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }
            $cronFile = '/etc/cron.d/orbitra-backorder';
            if (!is_file($cronFile)) {
                echo json_encode(['status' => 'success', 'data' => ['deleted' => 0]]);
                break;
            }
            if (!is_writable($cronFile)) {
                echo json_encode(['status' => 'error', 'message' => 'No permission to delete ' . $cronFile]);
                break;
            }
            @unlink($cronFile);
            echo json_encode(['status' => 'success', 'data' => ['deleted' => 1]]);
            break;

        case 'backorder_import':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }

            $data = json_decode(orbitraRequestBody(), true);
            $text = (string) ($data['domains_text'] ?? '');
            $lines = preg_split("/\\r\\n|\\n|\\r/", $text);

            $inserted = 0;
            $ignored = 0;
            $invalid = 0;

            $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO backorder_domains (name, status) VALUES (?, 'unknown')");

            foreach ($lines as $line) {
                $norm = orbitraBackorderNormalizeDomain((string) $line);
                if ($norm === '') {
                    continue;
                }
                if (!orbitraBackorderIsValidDomain($norm)) {
                    $invalid++;
                    continue;
                }

                $stmtIns->execute([$norm]);
                if ($stmtIns->rowCount() > 0) {
                    $inserted++;
                } else {
                    $ignored++;
                }
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'inserted' => $inserted,
                    'duplicates_ignored' => $ignored,
                    'invalid' => $invalid
                ]
            ]);
            break;

        case 'backorder_update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }

            $data = json_decode(orbitraRequestBody(), true);
            $id = !empty($data['id']) ? (int) $data['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ID not provided']);
                break;
            }

            $notes = isset($data['notes']) ? (string) $data['notes'] : null;
            $dr = isset($data['ahrefs_dr']) && $data['ahrefs_dr'] !== '' ? (float) $data['ahrefs_dr'] : null;
            $ur = isset($data['ahrefs_ur']) && $data['ahrefs_ur'] !== '' ? (float) $data['ahrefs_ur'] : null;
            $refDomains = isset($data['ahrefs_ref_domains']) && $data['ahrefs_ref_domains'] !== '' ? (int) $data['ahrefs_ref_domains'] : null;

            $stmt = $pdo->prepare("
                UPDATE backorder_domains
                SET notes = ?, ahrefs_dr = ?, ahrefs_ur = ?, ahrefs_ref_domains = ?
                WHERE id = ?
            ");
            $stmt->execute([$notes, $dr, $ur, $refDomains, $id]);
            echo json_encode(['status' => 'success']);
            break;

        case 'backorder_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $id = !empty($data['id']) ? (int) $data['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ID not provided']);
                break;
            }
            $pdo->prepare("DELETE FROM backorder_domains WHERE id=?")->execute([$id]);
            echo json_encode(['status' => 'success']);
            break;

        case 'backorder_delete_selected':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            $data = json_decode(orbitraRequestBody(), true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['status' => 'error', 'message' => 'No IDs provided']);
                break;
            }

            $cleanIds = [];
            foreach ($ids as $v) {
                $iv = (int) $v;
                if ($iv > 0) $cleanIds[] = $iv;
            }
            $cleanIds = array_values(array_unique($cleanIds));
            if (empty($cleanIds)) {
                echo json_encode(['status' => 'error', 'message' => 'No valid IDs provided']);
                break;
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM backorder_domains WHERE id IN ($placeholders)");
            $stmt->execute($cleanIds);

            echo json_encode(['status' => 'success', 'data' => ['deleted' => $stmt->rowCount()]]);
            break;

        case 'backorder_check_now':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }

            $data = json_decode(orbitraRequestBody(), true);
            $id = !empty($data['id']) ? (int) $data['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ID not provided']);
                break;
            }

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    COALESCE(NULLIF(status, ''), 'unknown') AS status,
                    last_http_code,
                    last_error,
                    last_rdap_url,
                    last_result_json
                FROM backorder_domains
                WHERE id=? LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); // Free SQLite read lock
            
            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Domain not found']);
                break;
            }

            $domainName = (string) $row['name'];
            $check = orbitraBackorderCheck($domainName);

            $prevStatus = (string) ($row['status'] ?? 'unknown');
            $transient = orbitraBackorderIsTransientCheckResult($check);

            $statusToStore = (string) ($check['status'] ?? 'unknown');
            $httpToStore = $check['http_code'] ?? 0;
            $errorToStore = $check['error'] ?? null;
            $rdapToStore = $check['rdap_url'] ?? null;
            $jsonToStore = $check['result_json'] ?? null;

            if ($transient) {
                // Do not poison a known status with temporary rate limits / WAF blocks.
                if (in_array($prevStatus, ['registered', 'available'], true)) {
                    $statusToStore = $prevStatus;
                    $httpToStore = $row['last_http_code'] ?? 0;
                    $errorToStore = $row['last_error'] ?? null;
                    $rdapToStore = $row['last_rdap_url'] ?? null;
                    $jsonToStore = $row['last_result_json'] ?? null;
                } else {
                    // If we previously had only an error/rate_limited, degrade to "unknown".
                    if (in_array($prevStatus, ['error', 'rate_limited'], true)) {
                        $statusToStore = 'unknown';
                    } else {
                        $statusToStore = $prevStatus ?: 'unknown';
                    }
                }
            }

            $pdo->prepare("
                UPDATE backorder_domains
                SET
                    status = ?,
                    last_checked_at = CURRENT_TIMESTAMP,
                    last_http_code = ?,
                    last_error = ?,
                    last_rdap_url = ?,
                    last_result_json = ?
                WHERE id = ?
            ")->execute([
                $statusToStore,
                $httpToStore,
                $errorToStore,
                $rdapToStore,
                $jsonToStore,
                $id
            ]);

            $check['stored_status'] = $statusToStore;
            $check['transient'] = $transient;
            echo json_encode(['status' => 'success', 'data' => $check]);
            break;

        case 'backorder_check_batch':
            // Batch checker for UI: checks up to N "due" domains per request.
            // Due = never checked OR last_checked_at older than a configured interval,
            // OR (for manual "one pass") last_checked_at older than run_started_at.
            // This provides a "no-cron" workflow while keeping each request bounded.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }

            $data = json_decode(orbitraRequestBody(), true);
            $limit = isset($data['limit']) ? (int) $data['limit'] : 3;
            if ($limit <= 0) $limit = 3;
            if ($limit > 10) $limit = 10;

            $runStartedAt = isset($data['run_started_at']) ? (int) $data['run_started_at'] : 0;

            // Re-check intervals (seconds). Stored in settings; no schema changes needed.
            // Goal: do not burn external limits (especially .gr web UI) on already-registered domains.
            $checkIntervalSec = 900; // unknown/dns_available/unsupported (default)
            $checkIntervalRegisteredSec = 86400; // registered: check rarely
            $checkIntervalRateLimitedSec = 3600; // back off on rate limits
            $checkIntervalErrorSec = 1800; // transient errors: retry later
            try {
                $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_sec'")->fetchColumn();
                if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
                    $checkIntervalSec = max(15, (int) $v);
                }
            } catch (Throwable $e) {
                // ignore
            }

            try {
                $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_registered_sec'")->fetchColumn();
                if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
                    $checkIntervalRegisteredSec = max(60, (int) $v);
                }
            } catch (Throwable $e) {
                // ignore
            }
            try {
                $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_rate_limited_sec'")->fetchColumn();
                if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
                    $checkIntervalRateLimitedSec = max(60, (int) $v);
                }
            } catch (Throwable $e) {
                // ignore
            }
            try {
                $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_error_sec'")->fetchColumn();
                if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
                    $checkIntervalErrorSec = max(60, (int) $v);
                }
            } catch (Throwable $e) {
                // ignore
            }

            $nowEpoch = time();
            $cutoffDefaultEpoch = $nowEpoch - $checkIntervalSec;
            $cutoffRegisteredEpoch = $nowEpoch - $checkIntervalRegisteredSec;
            $cutoffRateLimitedEpoch = $nowEpoch - $checkIntervalRateLimitedSec;
            $cutoffErrorEpoch = $nowEpoch - $checkIntervalErrorSec;

            $paramsDue = [
                ':cutoff_default' => $cutoffDefaultEpoch,
                ':cutoff_registered' => $cutoffRegisteredEpoch,
                ':cutoff_rate_limited' => $cutoffRateLimitedEpoch,
                ':cutoff_error' => $cutoffErrorEpoch,
            ];
            $runConstraintSql = '';
            if ($runStartedAt > 0) {
                // Prevent re-checking the same domain more than once in a single UI run.
                $runConstraintSql = "CAST(strftime('%s', last_checked_at) AS INTEGER) < :run_started_at AND";
                $paramsDue[':run_started_at'] = $runStartedAt;
            }

            $dueWhereSql = "
                WHERE COALESCE(NULLIF(status, ''), 'unknown') != 'available'
                  AND (
                      last_checked_at IS NULL
                      OR (
                          $runConstraintSql
                          (
                              (COALESCE(NULLIF(status, ''), 'unknown') = 'registered' AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_registered)
                              OR (COALESCE(NULLIF(status, ''), 'unknown') = 'rate_limited' AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_rate_limited)
                              OR (COALESCE(NULLIF(status, ''), 'unknown') = 'error' AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_error)
                              OR (COALESCE(NULLIF(status, ''), 'unknown') NOT IN ('registered','rate_limited','error') AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_default)
                          )
                      )
                  )
            ";

            $lockDir = __DIR__ . '/var/locks';
            if (!is_dir($lockDir)) {
                @mkdir($lockDir, 0777, true);
            }
            $lockFile = $lockDir . '/backorder_batch.lock';
            $fp = @fopen($lockFile, 'c+');
            if ($fp && !flock($fp, LOCK_EX | LOCK_NB)) {
                echo json_encode(['status' => 'error', 'message' => 'Busy']);
                break;
            }

            $checked = 0;
            $results = [];
            $startedAt = microtime(true);
            $timeBudgetSeconds = 25.0;

            try {
                $stmtDue = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM backorder_domains
                    $dueWhereSql
                ");
                $stmtDue->execute($paramsDue);
                $dueTotal = (int) ($stmtDue->fetchColumn() ?: 0);

                while ($checked < $limit && (microtime(true) - $startedAt) < $timeBudgetSeconds) {
                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            name,
                            COALESCE(NULLIF(status, ''), 'unknown') AS status,
                            last_http_code,
                            last_error,
                            last_rdap_url,
                            last_result_json
                        FROM backorder_domains
                        $dueWhereSql
                        ORDER BY
                            CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END,
                            COALESCE(last_checked_at, created_at) ASC
                        LIMIT 1
                    ");
                    $stmt->execute($paramsDue);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor(); // Free SQLite read lock so UPDATE can write
                    
                    if (!$row) {
                        break;
                    }

                    $id = (int) $row['id'];
                    $name = (string) $row['name'];

                    $check = orbitraBackorderCheck($name);

                    $prevStatus = (string) ($row['status'] ?? 'unknown');
                    $transient = orbitraBackorderIsTransientCheckResult($check);

                    $statusToStore = (string) ($check['status'] ?? 'unknown');
                    $httpToStore = $check['http_code'] ?? 0;
                    $errorToStore = $check['error'] ?? null;
                    $rdapToStore = $check['rdap_url'] ?? null;
                    $jsonToStore = $check['result_json'] ?? null;

                    if ($transient) {
                        if (in_array($prevStatus, ['registered', 'available'], true)) {
                            $statusToStore = $prevStatus;
                            $httpToStore = $row['last_http_code'] ?? 0;
                            $errorToStore = $row['last_error'] ?? null;
                            $rdapToStore = $row['last_rdap_url'] ?? null;
                            $jsonToStore = $row['last_result_json'] ?? null;
                        } else {
                            if (in_array($prevStatus, ['error', 'rate_limited'], true)) {
                                $statusToStore = 'unknown';
                            } else {
                                $statusToStore = $prevStatus ?: 'unknown';
                            }
                        }
                    }

                    $pdo->prepare("
                        UPDATE backorder_domains
                        SET
                            status = ?,
                            last_checked_at = CURRENT_TIMESTAMP,
                            last_http_code = ?,
                            last_error = ?,
                            last_rdap_url = ?,
                            last_result_json = ?
                        WHERE id = ?
                    ")->execute([
                        $statusToStore,
                        $httpToStore,
                        $errorToStore,
                        $rdapToStore,
                        $jsonToStore,
                        $id
                    ]);

                    $results[] = [
                        'id' => $id,
                        'name' => $name,
                        'status' => $statusToStore,
                        'http_code' => $httpToStore,
                        'error' => $errorToStore,
                        'rdap_url' => $rdapToStore,
                    ];
                    $checked++;
                }

                $neverChecked = (int) ($pdo->query("SELECT COUNT(*) FROM backorder_domains WHERE last_checked_at IS NULL")->fetchColumn() ?: 0);
                $total = (int) ($pdo->query("SELECT COUNT(*) FROM backorder_domains")->fetchColumn() ?: 0);

                $stmtDue2 = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM backorder_domains
                    $dueWhereSql
                ");
                $stmtDue2->execute($paramsDue);
                $dueRemaining = (int) ($stmtDue2->fetchColumn() ?: 0);

                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'checked' => $checked,
                        'limit' => $limit,
                        'results' => $results,
                        'run_started_at' => $runStartedAt > 0 ? $runStartedAt : null,
                        'cutoff_epoch' => $cutoffDefaultEpoch,
                        'check_intervals' => [
                            'default' => $checkIntervalSec,
                            'registered' => $checkIntervalRegisteredSec,
                            'rate_limited' => $checkIntervalRateLimitedSec,
                            'error' => $checkIntervalErrorSec,
                        ],
                        'domains' => [
                            'total' => $total,
                            'never_checked' => $neverChecked,
                            'due_total' => $dueTotal,
                            'due_remaining' => $dueRemaining,
                        ],
                        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ]
                ]);
            } catch (Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            } finally {
                if (isset($fp) && is_resource($fp)) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            }
            break;
        // === End Backorder ===

        case 'save_domain':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $indexCampId = !empty($data['index_campaign_id']) ? (int) $data['index_campaign_id'] : null;
                $catch404 = !empty($data['catch_404']) ? 1 : 0;
                $groupId = !empty($data['group_id']) ? (int) $data['group_id'] : null;
                $isNoindex = !empty($data['is_noindex']) ? 1 : 0;
                $httpsOnly = !empty($data['https_only']) ? 1 : 0;

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Имя домена обязательно']);
                    break;
                }

                try {
                    // EDIT MODE: Update existing domain
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE domains SET name=?, index_campaign_id=?, catch_404=?, group_id=?, is_noindex=?, https_only=? WHERE id=?");
                        $stmt->execute([$name, $indexCampId, $catch404, $groupId, $isNoindex, $httpsOnly, $id]);
                        logAudit($pdo, 'UPDATE', 'Domain', $id, "Name: $name");

                        // Every parked domain wants a certificate, whether or not
                        // http:// is redirected to https://. Turning HTTPS-only off
                        // used to reset the status to 'none', which took the domain
                        // out of the queue and left it on the self-signed catch-all.
                        $certPath = "/etc/letsencrypt/live/$name/cert.pem";
                        $sslQueued = false;
                        if (file_exists($certPath)) {
                            $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")->execute([$id]);
                        } else {
                            $pdo->prepare("UPDATE domains SET ssl_status = 'pending', ssl_error = NULL, ssl_attempts = 0, ssl_last_attempt = NULL WHERE id = ?")->execute([$id]);
                            $sslQueued = true;
                        }

                        // Nginx first: the ACME challenge is served from the config
                        // this writes, so issuing a certificate before it exists fails.
                        $nginxResult = updateNginxConfig($pdo);

                        if (!empty($sslQueued)) {
                            $cliPath = __DIR__ . '/cli/ssl_installer.php';
                            if (file_exists($cliPath)) {
                                orbitraShell("php " . escapeshellarg($cliPath) . " > /dev/null 2>&1 &");
                            }
                        }

                        $response = ['status' => 'success', 'nginx' => $nginxResult];
                        if ($sslQueued) {
                            $response['ssl'] = 'SSL сертификат устанавливается в фоновом режиме (1-2 минуты)';
                        }
                        echo json_encode($response);
                    } else {
                        // CREATE MODE: Support bulk domain addition (comma-separated)
                        $names = array_map('trim', explode(',', $name));
                        $names = array_filter($names); // Remove empty strings
                        $names = array_unique($names); // Remove duplicates

                        if (empty($names)) {
                            echo json_encode(['status' => 'error', 'message' => 'Имя домена обязательно']);
                            break;
                        }

                        $results = [];
                        $sslPending = false;
                        $errors = [];

                        foreach ($names as $domainName) {
                            // Validate domain name (basic check)
                            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $domainName)) {
                                $errors[] = "Неверный формат домена: $domainName";
                                continue;
                            }

                            // Parking the domain is the request for a certificate,
                            // as it is in Keitaro — not the HTTPS-only toggle, which
                            // only decides whether http:// redirects to https://.
                            // A domain added without it used to sit at 'none' and
                            // never get a certificate at all.
                            $sslStatus = 'pending';

                            try {
                                $stmt = $pdo->prepare("INSERT INTO domains (name, index_campaign_id, catch_404, group_id, is_noindex, https_only, ssl_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$domainName, $indexCampId, $catch404, $groupId, $isNoindex, $httpsOnly, $sslStatus]);
                                $newId = $pdo->lastInsertId();
                                $results[] = ['id' => $newId, 'name' => $domainName];

                                // Cloudflare: when the integration is connected and the
                                // domain's zone is in the account, the A record is written
                                // right here — and a proxied domain takes its SSL from the
                                // CF edge, leaving the certbot queue (ssl_status=cloudflare).
                                $cfCfg = orbitraCloudflareConfig($pdo);
                                if ($cfCfg['token'] !== '') {
                                    $cfSync = orbitraCloudflareSyncDomain($pdo, ['id' => $newId, 'name' => $domainName], $cfCfg);
                                    $results[count($results) - 1]['cloudflare'] = $cfSync['ok']
                                        ? $cfSync['message']
                                        : null; // zone not in the account is not an error
                                }

                                $sslPending = true;

                                logAudit($pdo, 'CREATE', 'Domain', $newId, "Name: $domainName");
                            } catch (\Exception $e) {
                                // Check for duplicate
                                if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                                    $errors[] = "Домен уже существует: $domainName";
                                } else {
                                    $errors[] = "Ошибка добавления $domainName: " . $e->getMessage();
                                }
                            }
                        }

                        // Nginx first: the ACME challenge is served from the config
                        // this writes, so issuing a certificate before it exists fails.
                        $nginxResult = updateNginxConfig($pdo);

                        // Start background SSL installer if any domains need HTTPS
                        if ($sslPending) {
                            $cliPath = __DIR__ . '/cli/ssl_installer.php';
                            if (file_exists($cliPath)) {
                                orbitraShell("php " . escapeshellarg($cliPath) . " > /dev/null 2>&1 &");
                            }
                        }

                        $response = [
                            'status' => 'success',
                            'domains' => $results,
                            'nginx' => $nginxResult
                        ];

                        if ($sslPending) {
                            $response['ssl'] = 'SSL сертификаты устанавливаются в фоновом режиме (1-2 минуты)';
                        }

                        if (!empty($errors)) {
                            $response['warnings'] = $errors;
                        }

                        echo json_encode($response);
                    }
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()]);
                }
            }
            break;

        case 'delete_domain':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                if ($id) {
                    $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$id]);
                    logAudit($pdo, 'DELETE', 'Domain', $id);

                    // Auto-update Nginx configuration
                    $nginxResult = updateNginxConfig($pdo);

                    echo json_encode(['status' => 'success', 'nginx' => $nginxResult]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'ID не передан']);
                }
            }
            break;

        case 'ssl_environment':
            // Whether this server can issue certificates at all. Read-only, and
            // admin-only: it reports what is installed and writable, which is not
            // something to hand to anyone who can reach the panel URL.
            if (($_SESSION['role'] ?? '') !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }
            try {
                require_once __DIR__ . '/core/ssl_manager.php';
                echo json_encode(['status' => 'success', 'data' => orbitraSslEnvironment()]);
            } catch (\Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'run_ssl_worker':
            // Issue certificates now, in this request, and report what happened.
            //
            // The queue is normally worked by cron and by a background process
            // spawned on save — both of which need shell_exec, and both of which
            // are silently unavailable on plenty of hosts. Without this there was
            // no way to start issuance, or to find out why it was not starting,
            // without SSH access to the server.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }
            if (($_SESSION['role'] ?? '') !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }
            try {
                require_once __DIR__ . '/core/ssl_manager.php';

                // Certbot is the one dependency worth naming outright: without it
                // every domain sits at "pending" forever and the reason is invisible.
                // One source of truth for "can this server do it", shared with
                // the banner on the Domains page.
                $env = orbitraSslEnvironment();
                $notes = [];
                foreach ($env['problems'] as $problem) {
                    $notes[] = $problem;
                }

                $result = orbitraProcessSslQueue($pdo);
                $result['cron_scheduled'] = orbitraEnsureSslCron();
                $result['environment'] = $env;
                $result['server_ip'] = orbitraServerIp();
                if ($notes) {
                    $result['notes'] = $notes;
                }

                echo json_encode(['status' => 'success', 'data' => $result]);
            } catch (\Throwable $e) {
                error_log('run_ssl_worker failed: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // === Cloudflare integration ===
        // One account (API token in settings), Keitaro-style: domains parked in
        // the tracker get their A record managed in Cloudflare automatically, and
        // proxied domains get SSL at the CF edge instead of waiting for certbot.

        case 'cloudflare_status':
            try {
                $cfgCf = orbitraCloudflareConfig($pdo);
                echo json_encode(['status' => 'success', 'data' => [
                    'connected' => $cfgCf['token'] !== '',
                    'proxied' => $cfgCf['proxied'],
                    'ssl_mode' => $cfgCf['ssl_mode'],
                    'server_ip' => $cfgCf['server_ip'],
                    'managed_domains' => (int) $pdo->query("SELECT COUNT(*) FROM domains WHERE ssl_status = 'cloudflare'")->fetchColumn(),
                ]]);
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'cloudflare_save':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $dataCf = json_decode(orbitraRequestBody(), true);
                try {
                    $token = trim((string) ($dataCf['api_token'] ?? ''));
                    $proxied = !empty($dataCf['proxied']);
                    $sslMode = in_array(($dataCf['ssl_mode'] ?? ''), ['flexible', 'full', 'strict'], true) ? $dataCf['ssl_mode'] : 'flexible';
                    $serverIp = trim((string) ($dataCf['server_ip'] ?? ''));

                    // An empty token field means "keep the stored one" — the form
                    // never echoes the secret back, same as the FB cost connections.
                    if ($token === '') {
                        $stmtOld = $pdo->query("SELECT value FROM settings WHERE key = 'cf_api_token' LIMIT 1");
                        $token = (string) $stmtOld->fetchColumn();
                    }

                    if ($token !== '') {
                        require_once __DIR__ . '/core/CloudflareApi.php';
                        $verify = CloudflareApi::verifyToken($token);
                        if (!$verify['ok']) {
                            echo json_encode(['status' => 'error', 'message' => 'cloudflare_token_invalid', 'detail' => ['error' => $verify['message']]]);
                            break;
                        }
                    }

                    foreach ([
                        ['cf_api_token', $token],
                        ['cf_proxied', $proxied ? '1' : '0'],
                        ['cf_ssl_mode', $sslMode],
                        ['cf_server_ip', $serverIp],
                    ] as [$keyCf, $valueCf]) {
                        $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$keyCf, $valueCf]);
                    }

                    echo json_encode(['status' => 'success', 'data' => ['connected' => $token !== '']]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'cloudflare_test':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $cfgCf = orbitraCloudflareConfig($pdo);
                    if ($cfgCf['token'] === '') {
                        echo json_encode(['status' => 'error', 'message' => 'cloudflare_not_connected']);
                        break;
                    }
                    require_once __DIR__ . '/core/CloudflareApi.php';
                    $zones = CloudflareApi::listZones($cfgCf['token']);
                    if (!$zones['ok']) {
                        echo json_encode(['status' => 'error', 'message' => $zones['message']]);
                        break;
                    }
                    echo json_encode(['status' => 'success', 'data' => ['zones' => $zones['count']]]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'cloudflare_sync_domain':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $dataCf = json_decode(orbitraRequestBody(), true);
                $idCf = (int) ($dataCf['id'] ?? 0);
                try {
                    $stmtCf = $pdo->prepare("SELECT id, name FROM domains WHERE id = ? LIMIT 1");
                    $stmtCf->execute([$idCf]);
                    $domainCf = $stmtCf->fetch(PDO::FETCH_ASSOC);
                    if (!$domainCf) {
                        echo json_encode(['status' => 'error', 'message' => 'Domain not found']);
                        break;
                    }
                    $resultCf = orbitraCloudflareSyncDomain($pdo, $domainCf);
                    echo json_encode(['status' => $resultCf['ok'] ? 'success' : 'error', 'message' => $resultCf['message']]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        // Re-point every domain whose zone lives in the connected Cloudflare
        // account at the current server IP — the "moved the tracker to a new
        // server" button Keitaro's integration is known for.
        case 'cloudflare_sync_all':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $cfgCf = orbitraCloudflareConfig($pdo);
                    if ($cfgCf['token'] === '') {
                        echo json_encode(['status' => 'error', 'message' => 'cloudflare_not_connected']);
                        break;
                    }
                    $synced = [];
                    $failed = [];
                    foreach ($pdo->query("SELECT id, name FROM domains WHERE is_archived = 0") as $domainCf) {
                        $resultCf = orbitraCloudflareSyncDomain($pdo, $domainCf, $cfgCf);
                        if ($resultCf['ok']) {
                            $synced[] = $domainCf['name'];
                        } elseif (strpos($resultCf['message'], 'Zone not found') === false) {
                            // A domain outside the CF account is not an error.
                            $failed[] = $domainCf['name'] . ': ' . $resultCf['message'];
                        }
                    }
                    echo json_encode(['status' => 'success', 'data' => ['synced' => $synced, 'failed' => $failed]]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'ipranges_update':
            // Cloaking feed: refresh the datacenter/crawler IP lists
            // (lord-alfred/ipranges) on demand — the daily cron does the same.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    require_once __DIR__ . '/core/IpRanges.php';
                    $resultIr = IpRanges::update();
                    echo json_encode([
                        'status' => $resultIr['ok'] ? 'success' : 'error',
                        'message' => $resultIr['ok'] ? '' : implode('; ', $resultIr['errors']),
                        'data' => [
                            'ipv4' => $resultIr['ipv4'],
                            'ipv6' => $resultIr['ipv6'],
                            'updated_at' => time(),
                        ],
                    ]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            } else {
                require_once __DIR__ . '/core/IpRanges.php';
                // A status probe from the panel schedules the same lazy background
                // refresh the cloak detector uses — visiting the settings page is
                // enough to heal an install whose cron is not registered.
                IpRanges::ensureFreshBackground();
                $v4 = IpRanges::available() && file_exists(IpRanges::fileV4()) ? (int) @filemtime(IpRanges::fileV4()) : null;
                $v6 = IpRanges::available() && file_exists(IpRanges::fileV6()) ? (int) @filemtime(IpRanges::fileV6()) : null;
                echo json_encode(['status' => 'success', 'data' => [
                    'available' => IpRanges::available(),
                    'fresh' => IpRanges::isFresh(),
                    'v4_mtime' => $v4,
                    'v6_mtime' => $v6,
                    'v4_ranges' => IpRanges::countV4(),
                    'v6_ranges' => IpRanges::countV6(),
                ]]);
            }
            break;

        case 'check_ssl_status':
            // Check SSL installation status for all HTTPS-only domains.
            //
            // The stored status is a record of what happened, not of what is true
            // now: it said "installed" as soon as a certificate appeared on disk,
            // while nginx only serves that certificate if the config was
            // regenerated afterwards — the HTTPS server block for a domain is
            // written only when its fullchain.pem already exists. A domain could
            // therefore show a tick in the panel while the browser was still being
            // handed the catch-all's self-signed certificate. Every answer here is
            // now reconciled against the filesystem and the live config, and a
            // certificate that exists but is not wired up triggers one rebuild.
            try {
                $stmt = $pdo->query("SELECT id, name, https_only, ssl_status, ssl_error FROM domains ORDER BY id DESC");
                $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Filter to only include HTTPS-only domains with status
                $httpsDomains = array_filter($domains, function($d) {
                    return $d['https_only'] == 1;
                });

                $liveConfig = (string) @file_get_contents('/etc/nginx/sites-available/orbitra');
                $needsSync = false;
                $reconciled = [];

                foreach ($httpsDomains as $d) {
                    $name = (string) $d['name'];
                    $certFile = '/etc/letsencrypt/live/' . $name . '/fullchain.pem';
                    $hasCert = $name !== '' && file_exists($certFile);
                    // The block is identified by the certificate path it points at,
                    // which appears only inside that domain's own 443 server block.
                    $isWired = $hasCert && strpos($liveConfig, $certFile) !== false;

                    if ($hasCert && $d['ssl_status'] !== 'installed') {
                        $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")
                            ->execute([$d['id']]);
                        $d['ssl_status'] = 'installed';
                    } elseif (!$hasCert && $d['ssl_status'] === 'installed') {
                        // The certificate was removed or never really landed.
                        $pdo->prepare("UPDATE domains SET ssl_status = 'pending' WHERE id = ?")
                            ->execute([$d['id']]);
                        $d['ssl_status'] = 'pending';
                    }

                    if ($hasCert && !$isWired) {
                        $needsSync = true;
                    }

                    $d['cert_present'] = $hasCert;
                    $d['https_active'] = $isWired;
                    $reconciled[] = $d;
                }

                // A certificate nobody wired into the config is the one failure the
                // panel used to hide. Rebuilding is idempotent — orbitraSyncNginx()
                // compares with what is on disk and skips when they match.
                if ($needsSync) {
                    $syncResult = updateNginxConfig($pdo);
                    if (is_array($syncResult) && ($syncResult['status'] ?? '') === 'success') {
                        $liveConfig = (string) @file_get_contents('/etc/nginx/sites-available/orbitra');
                        foreach ($reconciled as &$d) {
                            $d['https_active'] = !empty($d['cert_present'])
                                && strpos($liveConfig, '/etc/letsencrypt/live/' . $d['name'] . '/fullchain.pem') !== false;
                        }
                        unset($d);
                    }
                }

                echo json_encode([
                    'status' => 'success',
                    'data' => $reconciled
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            break;

        case 'campaign_logs':
            $campaignId = $_GET['campaign_id'] ?? null;
            if (!$campaignId) {
                echo json_encode(['status' => 'error', 'message' => 'Missing campaign_id']);
                break;
            }

            $limit = (int) ($_GET['limit'] ?? 100);
            $stmt = $pdo->prepare("
                SELECT 
                    cl.id,
                    datetime(cl.created_at, '$dbTzOffset') as created_at,
                    cl.ip,
                    cl.user_agent,
                    COALESCE(NULLIF(cl.country_code, ''), cl.country) as country_code,
                    cl.region,
                    cl.city,
                    cl.timezone as geo_timezone,
                    cl.language,
                    cl.accept_language_raw,
                    cl.device_type,
                    cl.os,
                    cl.browser,
                    cl.is_conversion,
                    cl.revenue,
                    c.name as campaign_name,
                    o.name as offer_name,
                    s.name as stream_name
                FROM clicks cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                LEFT JOIN offers o ON cl.offer_id = o.id
                LEFT JOIN streams s ON cl.stream_id = s.id
                WHERE cl.campaign_id = ?
                ORDER BY cl.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$campaignId, $limit]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format logs with ClickContext-style text
            foreach ($logs as &$log) {
                $logText = "[ClickContext]\n";
                $logText .= "IP: {$log['ip']}\n";
                $logText .= "UserAgent: {$log['user_agent']}\n";
                $logText .= "Country: {$log['country_code']}\n";
                $logText .= "Region: " . ($log['region'] ?: '-') . "\n";
                $logText .= "City: " . ($log['city'] ?: '-') . "\n";
                $logText .= "Timezone: " . ($log['geo_timezone'] ?: '-') . "\n";
                $logText .= "Language: " . ($log['language'] ?: '-') . "\n";
                $logText .= "Accept-Language: " . ($log['accept_language_raw'] ?: '-') . "\n";
                $logText .= "Device: {$log['device_type']}\n";
                $logText .= "OS: {$log['os']}\n";
                $logText .= "Browser: {$log['browser']}\n";
                $logText .= "Campaign: {$log['campaign_name']}\n";
                $logText .= "Stream: {$log['stream_name']}\n";
                $logText .= "Offer: {$log['offer_name']}\n";
                $logText .= "Conversion: " . ($log['is_conversion'] ? 'Yes' : 'No') . "\n";
                $logText .= "Revenue: {$log['revenue']}\n";
                $log['log_text'] = $logText;
            }

            echo json_encode(['status' => 'success', 'data' => $logs]);
            break;

        case 'clear_stats':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $campaignId = $data['campaign_id'] ?? null;
                if ($campaignId) {
                    $pdo->prepare("DELETE FROM clicks WHERE campaign_id = ?")->execute([$campaignId]);
                    $pdo->prepare("DELETE FROM conversions WHERE campaign_id = ?")->execute([$campaignId]);
                    logAudit($pdo, 'CLEAR_STATS', 'Campaign', $campaignId);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing campaign ID']);
                }
            }
            break;

        case 'clear_campaign_stats':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $campaignId = $data['campaign_id'] ?? null;
                if ($campaignId) {
                    $pdo->prepare("DELETE FROM clicks WHERE campaign_id = ?")->execute([$campaignId]);
                    $pdo->prepare("DELETE FROM conversions WHERE campaign_id = ?")->execute([$campaignId]);
                    logAudit($pdo, 'CLEAR_STATS', 'Campaign', $campaignId);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing campaign ID']);
                }
            }
            break;

        case 'update_costs':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $campaignId = $data['campaign_id'] ?? null;
                $totalCost = (float) ($data['cost'] ?? 0);
                $startDate = $data['start_date'] ?? null;
                $endDate = $data['end_date'] ?? null;
                $uniqueOnly = !empty($data['unique_only']);

                if ($campaignId && $totalCost > 0 && $startDate && $endDate) {
                    $sql = "SELECT id FROM clicks WHERE campaign_id = ? AND created_at >= ? AND created_at <= ?";
                    $params = [$campaignId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];
                    if ($uniqueOnly) {
                        $sql = "SELECT MIN(id) as id FROM clicks WHERE campaign_id = ? AND created_at >= ? AND created_at <= ? GROUP BY ip";
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $clicks = $stmt->fetchAll();

                    if (count($clicks) > 0) {
                        $cpc = $totalCost / count($clicks);
                        $clickIds = array_column($clicks, 'id');

                        $updateStmt = $pdo->prepare("UPDATE clicks SET cost = ? WHERE id = ?");
                        $pdo->beginTransaction();
                        foreach ($clickIds as $cid) {
                            $updateStmt->execute([$cpc, $cid]);
                        }
                        $pdo->commit();
                        echo json_encode(['status' => 'success', 'updated_clicks' => count($clicks)]);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'No clicks found in this period']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
                }
            }
            break;

        case 'simulate_traffic':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $campaignId = $data['campaign_id'] ?? null;
                $ip = $data['ip'] ?? '127.0.0.1';
                $userAgent = $data['user_agent'] ?? 'Mozilla/5.0';
                $country = $data['country'] ?? 'US';
                $deviceType = $data['device_type'] ?? 'desktop';
                $acceptLanguageRaw = trim((string) ($data['accept_language'] ?? ($data['language'] ?? 'en')));
                $asn = trim((string) ($data['asn'] ?? ''));
                $isp = trim((string) ($data['isp'] ?? ''));
                $proxyRecord = orbitraLookupIp2Proxy((string) $ip, __DIR__);
                $isProxy = (int) ($proxyRecord['isProxy'] ?? 0);
                $proxyType = trim((string) ($proxyRecord['proxyType'] ?? ''));
                $proxyThreat = trim((string) ($proxyRecord['threat'] ?? ''));
                $proxyProvider = trim((string) ($proxyRecord['provider'] ?? ''));
                $proxyFraudScore = is_numeric($proxyRecord['fraudScore'] ?? null)
                    ? (int) $proxyRecord['fraudScore']
                    : null;

                if ($asn === '' || $isp === '') {
                    $asnRecord = orbitraLookupIp2LocationAsn((string) $ip, __DIR__);
                    if ($asn === '') {
                        $asnValue = trim((string) ($asnRecord['asn'] ?? $proxyRecord['asn'] ?? ''));
                        if ($asnValue !== '' && $asnValue !== '-') {
                            $asn = stripos($asnValue, 'AS') === 0 ? $asnValue : 'AS' . $asnValue;
                        }
                    }
                    if ($isp === '') {
                        $ispValue = trim((string) ($asnRecord['as'] ?? $proxyRecord['isp'] ?? $proxyRecord['as'] ?? ''));
                        if ($ispValue !== '-') {
                            $isp = $ispValue;
                        }
                    }
                }
                $jsExecuted = !array_key_exists('js_executed', $data)
                    || filter_var($data['js_executed'], FILTER_VALIDATE_BOOL);
                $webdriver = array_key_exists('webdriver', $data)
                    && filter_var($data['webdriver'], FILTER_VALIDATE_BOOL);
                $languageCodes = extractBrowserLanguageCodes($acceptLanguageRaw);
                $language = $languageCodes[0] ?? 'unknown';

                $trace = [];
                $trace[] = "Start simulation for Campaign ID: $campaignId";
                $trace[] = "Context -> IP: $ip, UA: $userAgent, Country: $country, Device: $deviceType, Primary Language: $language";
                $trace[] = "Accept-Language raw: " . ($acceptLanguageRaw !== '' ? $acceptLanguageRaw : '-');
                $trace[] = "Parsed browser languages: " . (!empty($languageCodes) ? implode(', ', $languageCodes) : 'none');
                $trace[] = "Network -> ASN: " . ($asn !== '' ? $asn : '-') . ", ISP: " . ($isp !== '' ? $isp : '-');
                $trace[] = "IP2Proxy -> is_proxy={$isProxy}, type=" . ($proxyType !== '' ? $proxyType : '-')
                    . ", provider=" . ($proxyProvider !== '' ? $proxyProvider : '-')
                    . ", threat=" . ($proxyThreat !== '' ? $proxyThreat : '-')
                    . ", fraud_score=" . ($proxyFraudScore !== null ? $proxyFraudScore : '-');

                if (!$campaignId) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing campaign ID']);
                    break;
                }

                $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
                $stmt->execute([$campaignId]);
                $campaign = $stmt->fetch();
                if (!$campaign) {
                    echo json_encode(['status' => 'error', 'message' => 'Campaign not found']);
                    break;
                }
                $trace[] = "Campaign found: " . $campaign['name'];

                $stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC, id ASC");
                $stmt->execute([$campaignId]);
                $allStreams = $stmt->fetchAll();
                $trace[] = "Loaded " . count($allStreams) . " active streams";

                if (!function_exists('streamMatchesFiltersSim')) {
                    function streamMatchesFiltersSim($stream, $ip, $country, $deviceType, $languageCodes, $botContext, &$trace)
                    {
                        if (empty($stream['filters_json']))
                            return true;
                        $filters = json_decode($stream['filters_json'], true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($filters) || empty($filters))
                            return true;

                        $logic = orbitraStreamFilterLogic($stream);
                        $votes = [];

                        foreach ($filters as $f) {
                            $mode = $f['mode'] ?? 'include';
                            $payload = $f['payload'] ?? [];
                            if (empty($payload))
                                continue;

                            $matched = false;
                            if ($f['name'] === 'Country')
                                $matched = in_array($country, $payload);
                            else if ($f['name'] === 'Device')
                                $matched = in_array($deviceType, $payload);
                            else if ($f['name'] === 'Language') {
                                $normalizedPayload = [];
                                foreach ($payload as $item) {
                                    $candidate = normalizeBrowserLanguageCode((string) $item);
                                    if ($candidate !== '') {
                                        $normalizedPayload[] = $candidate;
                                    }
                                }
                                $matched = !empty(array_intersect($normalizedPayload, $languageCodes));
                            }
                            else if ($f['name'] === 'Bot') {
                                $botVerdict = CloakDetector::detectBotFilter($botContext);
                                $matched = (bool) ($botVerdict['is_suspicious'] ?? false);
                                $trace[] = "  [Bot Filter] suspicious=" . ($matched ? 'yes' : 'no')
                                    . ", reasons=[" . implode(', ', $botVerdict['reasons'] ?? []) . "]";
                            }
                            else
                                $matched = true;

                            $vote = ($mode === 'include') ? $matched : !$matched;
                            $votes[] = $vote;
                            if (!$vote) {
                                $trace[] = "  [Filter " . ($logic === 'or' ? 'Not Satisfied' : 'Failed') . "] Stream '" . $stream['name'] . "' "
                                    . ($mode === 'include' ? 'requires' : 'excludes') . " {$f['name']} IN " . implode(',', $payload)
                                    . " (logic: " . strtoupper($logic) . ")";
                            }
                        }
                        $result = orbitraCombineFilterVotes($votes, $logic);
                        if ($logic === 'or' && in_array(true, $votes, true)) {
                            $trace[] = "  [Filter Satisfied] Stream '{$stream['name']}' matched at least one filter (logic: OR)";
                        }
                        return $result;
                    }
                }

                $selectedStream = null;
                $botFilterContext = [
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'asn' => $asn,
                    'isp' => $isp,
                    'is_proxy' => $isProxy,
                    'proxy_type' => $proxyType,
                    'proxy_threat' => $proxyThreat,
                    'proxy_provider' => $proxyProvider,
                    'proxy_fraud_score' => $proxyFraudScore,
                    'accept_language' => $acceptLanguageRaw,
                    'pdo' => $pdo,
                ];
                $trace[] = "Evaluating Intercepting streams...";
                foreach ($allStreams as $stream) {
                    if (($stream['type'] ?? 'regular') === 'intercepting') {
                        if (streamMatchesFiltersSim($stream, $ip, $country, $deviceType, $languageCodes, $botFilterContext, $trace)) {
                            $selectedStream = $stream;
                            $trace[] = "=> MATCHED Intercepting Stream: " . $stream['name'];
                            break;
                        }
                    }
                }

                if (!$selectedStream) {
                    $trace[] = "Evaluating Regular streams...";
                    $regular = array_filter($allStreams, fn($s) => ($s['type'] ?? 'regular') === 'regular' && streamMatchesFiltersSim($s, $ip, $country, $deviceType, $languageCodes, $botFilterContext, $trace));

                    if (!empty($regular)) {
                        $trace[] = "Found " . count($regular) . " eligible regular streams";
                        if (($campaign['rotation_type'] ?? 'position') === 'position') {
                            $selectedStream = reset($regular);
                            $trace[] = "=> Selected by position: " . $selectedStream['name'];
                        } else {
                            // Keep it consistent with index.php/click.php selection logic.
                            $totalW = 0;
                            foreach ($regular as $it) {
                                $w = (int) ($it['weight'] ?? 0);
                                if ($w < 0) $w = 0;
                                $totalW += $w;
                            }
                            $trace[] = "=> Weight rotation, total weight: $totalW";
                            if ($totalW > 0) {
                                $rand = mt_rand(1, $totalW);
                                $curW = 0;
                                foreach ($regular as $it) {
                                    $curW += max(0, (int) ($it['weight'] ?? 0));
                                    if ($rand <= $curW) {
                                        $selectedStream = $it;
                                        break;
                                    }
                                }
                                if (!$selectedStream) {
                                    $selectedStream = reset($regular);
                                }
                                $trace[] = "=> Selected by weight (rand=$rand): " . ($selectedStream['name'] ?? '');
                            } else {
                                $selectedStream = reset($regular);
                                $trace[] = "=> Weights are 0, picking first: " . $selectedStream['name'];
                            }
                        }
                    }
                }

                if (!$selectedStream) {
                    $trace[] = "Evaluating Fallback streams...";
                    foreach ($allStreams as $stream) {
                        if (($stream['type'] ?? '') === 'fallback') {
                            $selectedStream = $stream;
                            $trace[] = "=> MATCHED Fallback Stream: " . $stream['name'];
                            break;
                        }
                    }
                }

                if ($selectedStream) {
                    $trace[] = "--- RESULT ---";
                    if (($selectedStream['schema_type'] ?? 'redirect') === 'action') {
                        $trace[] = "Action: " . ($selectedStream['action_payload'] ?? 'do_nothing');
                    } else if (($selectedStream['schema_type'] ?? 'redirect') === 'landing_offer') {
                        $trace[] = "Handling Landing + Offer split test (simulating choice)...";
                        $customSchema = json_decode($selectedStream['schema_custom_json'] ?? '{}', true);
                        $landingsCount = count($customSchema['landings'] ?? []);
                        $offersCount = count($customSchema['offers'] ?? []);
                        $trace[] = "Has $landingsCount landings and $offersCount offers mapped.";
                    } else if (($selectedStream['schema_type'] ?? 'redirect') === 'cloak') {
                        $customSchema = json_decode($selectedStream['schema_custom_json'] ?? '{}', true);
                        if (!is_array($customSchema)) {
                            $customSchema = [];
                        }
                        $cloakConfig = [
                            'detect_datacenter' => $customSchema['detect_datacenter'] ?? true,
                            'detect_vpn' => $customSchema['detect_vpn'] ?? true,
                            'detect_bots' => $customSchema['detect_bots'] ?? true,
                            'detect_ua' => $customSchema['detect_ua'] ?? true,
                            'sensitivity' => $customSchema['sensitivity'] ?? 'medium',
                        ];
                        $verdict = CloakDetector::detect([
                            'ip' => $ip,
                            'user_agent' => $userAgent,
                            'asn' => $asn,
                            'isp' => $isp,
                            'is_proxy' => $isProxy,
                            'proxy_type' => $proxyType,
                            'proxy_threat' => $proxyThreat,
                            'proxy_provider' => $proxyProvider,
                            'proxy_fraud_score' => $proxyFraudScore,
                            'accept_language' => $acceptLanguageRaw,
                            'pdo' => $pdo,
                        ], $cloakConfig);

                        $reasons = !empty($verdict['reasons'])
                            ? implode(', ', $verdict['reasons'])
                            : 'none';
                        $trace[] = "Cloak passive detector -> sensitivity={$cloakConfig['sensitivity']}, reasons=[$reasons]";

                        $jsChallengeEnabled = filter_var(
                            $customSchema['js_challenge'] ?? false,
                            FILTER_VALIDATE_BOOL
                        );
                        if (!empty($verdict['is_suspicious'])) {
                            $trace[] = "Cloak result: SAFE page (passive detector).";
                        } elseif ($jsChallengeEnabled && (!$jsExecuted || $webdriver)) {
                            $why = !$jsExecuted ? 'JavaScript did not execute' : 'navigator.webdriver=true';
                            $trace[] = "Cloak result: SAFE page (JS challenge: $why).";
                        } else {
                            if ($jsChallengeEnabled) {
                                $trace[] = "JS challenge -> executed=yes, webdriver=no.";
                            }
                            $landingsCount = count($customSchema['landings'] ?? []);
                            $offersCount = count($customSchema['offers'] ?? []);
                            $trace[] = "Cloak result: MONEY page ($landingsCount landings, $offersCount offers mapped).";
                        }
                    } else {
                        $trace[] = "Redirect to single Offer ID: " . ($selectedStream['offer_id'] ?? 0);
                    }
                } else {
                    $trace[] = "--- RESULT: NO STREAM MATCHED (404 / 500) ---";
                }

                echo json_encode(['status' => 'success', 'trace' => $trace]);
            }
            break;

        // === CONVERSIONS API ===
        case 'conversions':
            $page = (int) ($_GET['page'] ?? 1);
            $perPage = (int) ($_GET['per_page'] ?? 50);
            $offset = ($page - 1) * $perPage;

            $where = "1=1";
            $params = [];

            // Filters
            if (!empty($_GET['status'])) {
                $where .= " AND cv.status = ?";
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['campaign_id'])) {
                $where .= " AND cv.campaign_id = ?";
                $params[] = (int) $_GET['campaign_id'];
            }
            if (!empty($_GET['offer_id'])) {
                $where .= " AND cv.offer_id = ?";
                $params[] = (int) $_GET['offer_id'];
            }
            if (!empty($_GET['date_from'])) {
                $where .= " AND cv.created_at >= ?";
                $params[] = $_GET['date_from'] . ' 00:00:00';
            }
            if (!empty($_GET['date_to'])) {
                $where .= " AND cv.created_at <= ?";
                $params[] = $_GET['date_to'] . ' 23:59:59';
            }
            if (!empty($_GET['search'])) {
                $where .= " AND (cv.click_id LIKE ? OR cv.tid LIKE ? OR cv.ip LIKE ?)";
                $search = '%' . $_GET['search'] . '%';
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            // Count total
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM conversions cv WHERE $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();

            // Get data
            $sql = "
                SELECT cv.*, 
                       c.name as campaign_name,
                       o.name as offer_name
                FROM conversions cv
                LEFT JOIN campaigns c ON cv.campaign_id = c.id
                LEFT JOIN offers o ON cv.offer_id = o.id
                WHERE $where
                ORDER BY cv.created_at DESC
                LIMIT $perPage OFFSET $offset
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $conversions = $stmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data' => $conversions,
                'pagination' => [
                    'total' => (int) $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => ceil($total / $perPage)
                ]
            ]);
            break;

        case 'conversion_statuses':
            $stmt = $pdo->query("SELECT DISTINCT status FROM conversions ORDER BY status");
            $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['status' => 'success', 'data' => $statuses]);
            break;

        // === POSTBACK LOGS API ===
        case 'postback_logs':
            $page = (int) ($_GET['page'] ?? 1);
            $perPage = (int) ($_GET['per_page'] ?? 50);
            $offset = ($page - 1) * $perPage;

            $where = "1=1";
            $params = [];

            if (!empty($_GET['is_success'])) {
                $where .= " AND pl.is_success = ?";
                $params[] = (int) $_GET['is_success'];
            }
            if (!empty($_GET['date_from'])) {
                $where .= " AND pl.created_at >= ?";
                $params[] = $_GET['date_from'] . ' 00:00:00';
            }
            if (!empty($_GET['date_to'])) {
                $where .= " AND pl.created_at <= ?";
                $params[] = $_GET['date_to'] . ' 23:59:59';
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM postback_logs pl WHERE $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();

            $sql = "
                SELECT pl.* 
                FROM postback_logs pl
                WHERE $where
                ORDER BY pl.created_at DESC
                LIMIT $perPage OFFSET $offset
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data' => $logs,
                'pagination' => [
                    'total' => (int) $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => ceil($total / $perPage)
                ]
            ]);
            break;

        // === SETTINGS API ===
        case 'settings':
            $stmt = $pdo->query("SELECT * FROM settings");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['key']] = $row['value'];
            }
            // Parse aliases
            if (!empty($settings['postback_aliases'])) {
                $settings['postback_aliases'] = json_decode($settings['postback_aliases'], true);
            }
            echo json_encode(['status' => 'success', 'data' => $settings]);
            break;

        case 'campaign_report':
            // Layered reporting: up to 3 group-by dimensions ("Country → Campaign
            // → adset_id"), Keitaro-style. campaign_id = 0 means "all campaigns".
            $campaign_id = (int) ($_GET['campaign_id'] ?? 0);
            $date_from = $_GET['date_from'] ?? null;
            $date_to = $_GET['date_to'] ?? null;
            $group_by_raw = (string) ($_GET['group_by'] ?? 'country');

            $allowed_dimensions = [
                'country'        => 'clicks.country',
                'region'         => 'clicks.region',
                'city'           => 'clicks.city',
                'device_type'    => 'clicks.device_type',
                'os'             => 'clicks.os',
                'browser'        => 'clicks.browser',
                'language'       => 'clicks.language',
                'stream_id'      => 'clicks.stream_id',
                'source_id'      => 'clicks.source_id',
                'offer_id'       => 'clicks.offer_id',
                'landing_id'     => 'clicks.landing_id',
                'campaign_id'    => 'clicks.campaign_id',
                'day'            => "date(clicks.created_at, '$dbTzOffset')",
                'hour'           => "strftime('%Y-%m-%d %H:00', clicks.created_at, '$dbTzOffset')",
                'ad_id'          => "json_extract(clicks.parameters_json, '\$.ad_id')",
                'adset_id'       => "json_extract(clicks.parameters_json, '\$.adset_id')",
                'ad_campaign_id' => "json_extract(clicks.parameters_json, '\$.campaign_id')",
                'keyword'        => "json_extract(clicks.parameters_json, '\$.keyword')",
                'creative_id'    => "json_extract(clicks.parameters_json, '\$.creative')",
                'external_id'    => "json_extract(clicks.parameters_json, '\$.external_id')",
            ];
            for ($i = 1; $i <= 30; $i++) {
                $allowed_dimensions["sub_id_$i"] = "json_extract(clicks.parameters_json, '\$.sub_id_$i')";
            }

            $layers = array_values(array_slice(array_filter(array_map('trim', explode(',', $group_by_raw)), fn($d) => $d !== ''), 0, 5));
            if (empty($layers)) {
                $layers = ['country'];
            }
            foreach ($layers as $layer) {
                if (!array_key_exists($layer, $allowed_dimensions)) {
                    if (str_starts_with($layer, 'param_') || str_starts_with($layer, 'custom_')) {
                        $paramName = preg_replace('/^(param_|custom_)/', '', $layer);
                        if (preg_match('/^[a-zA-Z0-9_\-]+$/', $paramName)) {
                            $allowed_dimensions[$layer] = "json_extract(clicks.parameters_json, '\$." . addslashes($paramName) . "')";
                            continue;
                        }
                    }
                    echo json_encode(['status' => 'error', 'message' => 'Invalid group_by parameter: ' . htmlspecialchars($layer)]);
                    break 2;
                }
            }

            // Dimension aliases for both query levels: the inner select computes
            // COALESCE(expr) once, the outer one selects and groups by the alias.
            // (This block was dropped in a merge — implode(null) 500'd every report.)
            $dimInner = [];
            $dimOuter = [];
            $dimGroupBy = [];
            foreach ($layers as $i => $layer) {
                $dimInner[] = "COALESCE({$allowed_dimensions[$layer]}, 'Unknown') as dim_" . ($i + 1);
                $dimOuter[] = 'dim_' . ($i + 1);
                $dimGroupBy[] = 'dim_' . ($i + 1);
            }

            $conds = [];
            $params = [];
            if ($campaign_id > 0) {
                $conds[] = 'clicks.campaign_id = ?';
                $params[] = $campaign_id;
            }
            if ($date_from) {
                $conds[] = "date(clicks.created_at, '$dbTzOffset') >= date(?)";
                $params[] = $date_from;
            }
            if ($date_to) {
                $conds[] = "date(clicks.created_at, '$dbTzOffset') <= date(?)";
                $params[] = $date_to;
            }

            // Optional filters (JSON array of {field, op, value})
            if (!empty($_GET['filters'])) {
                $filtersRaw = is_string($_GET['filters']) ? json_decode($_GET['filters'], true) : $_GET['filters'];
                if (is_array($filtersRaw)) {
                    foreach ($filtersRaw as $flt) {
                        $field = $flt['field'] ?? '';
                        $op = $flt['op'] ?? 'eq';
                        $val = $flt['value'] ?? '';
                        if ($field === '' || $val === '') continue;

                        $sqlExpr = null;
                        if (isset($allowed_dimensions[$field])) {
                            $sqlExpr = $allowed_dimensions[$field];
                        } else if (preg_match('/^[a-zA-Z0-9_\-]+$/', $field)) {
                            $sqlExpr = "json_extract(clicks.parameters_json, '\$." . addslashes($field) . "')";
                        }

                        if ($sqlExpr) {
                            if ($op === 'eq') {
                                $conds[] = "$sqlExpr = ?";
                                $params[] = $val;
                            } else if ($op === 'neq') {
                                $conds[] = "$sqlExpr != ?";
                                $params[] = $val;
                            } else if ($op === 'contains') {
                                $conds[] = "$sqlExpr LIKE ?";
                                $params[] = "%$val%";
                            } else if ($op === 'not_contains') {
                                $conds[] = "$sqlExpr NOT LIKE ?";
                                $params[] = "%$val%";
                            }
                        }
                    }
                }
            }

            $where = $conds ? implode(' AND ', $conds) : '1=1';

            // One pass over conversions / revenue_records, joined on click id — the
            // previous version ran ten correlated subqueries per click row.
            $convAggSql = orbitraConversionAggregateSql(getConversionsValueColumn($pdo));
            $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);
            $realJoin = $revenueRecordsValueColumn !== null
                ? 'LEFT JOIN ' . orbitraRevenueRecordsAggregateSql($revenueRecordsValueColumn) . ' rr ON rr.click_id = clicks.id'
                : '';
            $realRevSelect = $revenueRecordsValueColumn !== null ? 'COALESCE(rr.real_rev, 0)' : '0';

            $sql = "
                SELECT
                    " . implode(', ', $dimOuter) . ",
                    COUNT(click_id) as clicks,
                    SUM(uniq_campaign) as unique_clicks,
                    SUM(uniq_stream) as unique_clicks_stream,
                    SUM(uniq_global) as unique_clicks_global,
                    SUM(uniq_global) as visitors,
                    SUM(is_bot) as bots,
                    SUM(is_proxy) as proxies,
                    SUM(CASE WHEN referer IS NULL OR referer = '' THEN 1 ELSE 0 END) as empty_referrers,
                    AVG(CASE WHEN landing_at IS NOT NULL AND offer_at IS NOT NULL
                        THEN CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS REAL) END) as avg_lp_seconds,
                    SUM(CASE WHEN landing_id IS NOT NULL AND landing_id > 0 THEN 1 ELSE 0 END) as prelander_clicks,
                    SUM(CASE WHEN offer_id IS NOT NULL AND offer_id > 0 THEN 1 ELSE 0 END) as offer_clicks,
                    SUM(CASE WHEN landing_id IS NOT NULL AND landing_id > 0 AND offer_id IS NOT NULL AND offer_id > 0 THEN 1 ELSE 0 END) as lp_clicks,
                    COALESCE(SUM(cnt_any), 0) as conversions,
                    COALESCE(SUM(cnt_sale), 0) as purchases,
                    COALESCE(SUM(cnt_hold), 0) as holds,
                    COALESCE(SUM(cnt_rejected), 0) as rejected,
                    COALESCE(SUM(cnt_trash), 0) as trash,
                    COALESCE(SUM(cnt_registration), 0) as registrations,
                    COALESCE(SUM(cnt_deposit), 0) as deposits,
                    COALESCE(SUM(click_cost), 0) as cost,
                    COALESCE(SUM(click_revenue), 0) as revenue,
                    COALESCE(SUM(click_sale_revenue), 0) as revenue_confirmed,
                    COALESCE(SUM(click_hold_revenue), 0) as revenue_hold,
                    COALESCE(SUM(click_rej_revenue), 0) as revenue_rejected,
                    COALESCE(SUM(click_trash_revenue), 0) as revenue_trash,
                    COALESCE(SUM(click_reg_revenue), 0) as revenue_registration,
                    COALESCE(SUM(click_dep_revenue), 0) as revenue_deposit,
                    COALESCE(SUM(click_real_revenue), 0) as real_revenue
                FROM (
                    SELECT clicks.id as click_id,
                           clicks.ip as click_ip,
                           clicks.referer,
                           clicks.is_bot,
                           clicks.is_proxy,
                           clicks.uniq_campaign,
                           clicks.uniq_stream,
                           clicks.uniq_global,
                           clicks.landing_at,
                           clicks.offer_at,
                           clicks.landing_id,
                           clicks.offer_id,
                           clicks.cost as click_cost,
                           COALESCE(cv.cnt_any, 0) as cnt_any,
                           COALESCE(cv.rev_all, 0) as click_revenue,
                           COALESCE(cv.rev_sale, 0) as click_sale_revenue,
                           COALESCE(cv.rev_hold, 0) as click_hold_revenue,
                           COALESCE(cv.rev_rejected, 0) as click_rej_revenue,
                           COALESCE(cv.rev_trash, 0) as click_trash_revenue,
                           $realRevSelect as click_real_revenue,
                           COALESCE(cv.cnt_sale, 0) as cnt_sale,
                           COALESCE(cv.cnt_hold, 0) as cnt_hold,
                           COALESCE(cv.cnt_rejected, 0) as cnt_rejected,
                           COALESCE(cv.cnt_trash, 0) as cnt_trash,
                           COALESCE(cv.cnt_registration, 0) as cnt_registration,
                           COALESCE(cv.cnt_deposit, 0) as cnt_deposit,
                           COALESCE(cv.rev_registration, 0) as click_reg_revenue,
                           COALESCE(cv.rev_deposit, 0) as click_dep_revenue,
                           " . implode(', ', $dimInner) . "
                    FROM clicks
                    LEFT JOIN $convAggSql cv ON cv.click_id = clicks.id
                    $realJoin
                    WHERE $where
                )
                GROUP BY " . implode(', ', $dimGroupBy) . "
                ORDER BY clicks DESC
                LIMIT 2000
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Resolve numeric ids of the layer dimensions into names, in batch —
            // one query per entity type instead of one per row.
            $nameMaps = [];
            $idLayers = ['stream_id' => 'streams', 'source_id' => 'traffic_sources', 'offer_id' => 'offers', 'landing_id' => 'landings', 'campaign_id' => 'campaigns'];
            foreach ($layers as $i => $layer) {
                if (!isset($idLayers[$layer])) {
                    continue;
                }
                $ids = [];
                foreach ($rows as $r) {
                    $v = (string) ($r['dim_' . ($i + 1)] ?? '');
                    if ($v !== '' && $v !== 'Unknown' && ctype_digit($v)) {
                        $ids[$v] = true;
                    }
                }
                if (!$ids) {
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT id, name FROM {$idLayers[$layer]} WHERE id IN ($placeholders)");
                $st->execute(array_keys($ids));
                $nameMaps[$layer] = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            }

            $out = [];
            foreach ($rows as $r) {
                $dims = [];
                foreach ($layers as $i => $layer) {
                    $v = (string) ($r['dim_' . ($i + 1)] ?? 'Unknown');
                    if (isset($nameMaps[$layer][$v])) {
                        $v = (string) $nameMaps[$layer][$v];
                    }
                    $dims[] = $v;
                }
                $out[] = array_merge(['dims' => $dims], orbitraComputeDerivedMetrics($r));
            }

            echo json_encode(['status' => 'success', 'data' => ['layers' => $layers, 'rows' => $out]]);
            break;

        case 'global_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('postback_key', 'currency', 'maxmind_license_key', 'maxmind_account_id', 'ip2location_token', 'allow_php_landings', 'php_landing_timeout', 'admin_path', 'stats_enabled', 'stats_retention_days', 'archive_retention_days', 'admin_ip_access', 'ignore_prefetch')");
                $data = [];
                while ($row = $stmt->fetch()) {
                    $data[$row['key']] = $row['value'];
                }
                if (!isset($data['admin_path'])) {
                    $data['admin_path'] = '';
                }
                // The retention/IP fields used to be discarded here, so the UI showed
                // its own hardcoded defaults regardless of what was in the DB. Backfill
                // the canonical config defaults so the form reflects reality.
                if (!isset($data['stats_enabled'])) {
                    $data['stats_enabled'] = '1';
                }
                if (!isset($data['stats_retention_days'])) {
                    $data['stats_retention_days'] = '256';
                }
                if (!isset($data['archive_retention_days'])) {
                    $data['archive_retention_days'] = '30';
                }
                if (!isset($data['admin_ip_access'])) {
                    $data['admin_ip_access'] = '';
                }
                if (!isset($data['ignore_prefetch'])) {
                    $data['ignore_prefetch'] = '1';
                }
                echo json_encode(['status' => 'success', 'data' => $data]);
            } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = json_decode(orbitraRequestBody(), true);
                $settings = $input['settings'] ?? [];
                $extra = [];
                if (!empty($settings)) {
                    // Validate the admin path before writing anything: a bad value
                    // moves the panel somewhere unreachable, and the user is holding
                    // the only door handle.
                    if (array_key_exists('admin_path', $settings) && ($_SESSION['role'] ?? '') !== 'admin') {
                        unset($settings['admin_path']);
                    }
                    if (array_key_exists('admin_path', $settings)) {
                        require_once __DIR__ . '/core/admin_path.php';
                        $check = orbitraValidateAdminPath($pdo, $settings['admin_path']);
                        if (!$check['ok']) {
                            echo json_encode(['status' => 'error', 'message' => $check['error']]);
                            break;
                        }
                        $settings['admin_path'] = $check['value'];
                        $extra['admin_path'] = $check['value'];
                        $extra['admin_url'] = $check['value'] === '' ? '/admin.php' : '/' . $check['value'];
                    }

                    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
                    foreach (['postback_key', 'currency', 'maxmind_license_key', 'maxmind_account_id', 'ip2location_token',
                              'allow_php_landings', 'php_landing_timeout', 'admin_path',
                              'stats_enabled', 'stats_retention_days', 'archive_retention_days',
                              'admin_ip_access', 'ignore_prefetch'] as $key) {
                        if (!isset($settings[$key])) {
                            continue;
                        }
                        $value = $settings[$key];
                        // Turning PHP landings on is an admin decision, not something
                        // any signed-in user gets to flip.
                        if ($key === 'allow_php_landings') {
                            if (($_SESSION['role'] ?? '') !== 'admin') {
                                continue;
                            }
                            $value = $value === '1' || $value === 1 || $value === true ? '1' : '0';
                        }
                        if ($key === 'php_landing_timeout') {
                            // Clamped to 1..9 like Keitaro's; anything absent, zero
                            // or unparseable falls back to the 3-second default
                            // rather than to "no limit".
                            $seconds = (int) $value;
                            $value = (string) max(1, min($seconds > 0 ? $seconds : 3, 9));
                        }
                        // The access list is a security control: only an admin may
                        // change it, and it must parse before we persist it — a typo
                        // in the only allowed address would otherwise lock out the
                        // panel (or, worse, widen it by being discarded).
                        if ($key === 'admin_ip_access') {
                            if (($_SESSION['role'] ?? '') !== 'admin') {
                                continue;
                            }
                            $cleaned = is_string($value) ? trim($value) : '';
                            if ($cleaned !== '' && $cleaned !== '0') {
                                require_once __DIR__ . '/core/ip_access.php';
                                $parsed = orbitraParseIpAccess($cleaned);
                                if (!empty($parsed['errors'])) {
                                    echo json_encode(['status' => 'error', 'message' => 'admin_ip_access: invalid entries (' . implode(', ', $parsed['errors']) . ')']);
                                    break 2;
                                }
                            }
                            $value = $cleaned;
                        }
                        // Booleans normalised to '0'/'1'.
                        if ($key === 'stats_enabled' || $key === 'ignore_prefetch') {
                            $value = ($value === '1' || $value === 1 || $value === true) ? '1' : '0';
                        }
                        // Retention windows: positive integers, clamped to a sane
                        // range (1 day..10 years). Empty/garbage falls back to the
                        // config default rather than "keep forever" or "delete now".
                        if ($key === 'stats_retention_days' || $key === 'archive_retention_days') {
                            $days = (int) $value;
                            $value = (string) max(1, min($days > 0 ? $days : ($key === 'stats_retention_days' ? 256 : 30), 3650));
                        }
                        $stmt->execute([$key, $value]);
                    }
                }
                echo json_encode(array_merge(['status' => 'success'], $extra));
            }
            break;



        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);

                try {
                    // The admin path decides where the panel is reachable, so a bad
                    // value locks the user out of their own tracker. Validate before
                    // writing anything, and report the URL the panel moves to.
                    $adminPathNotice = null;
                    if (array_key_exists('admin_path', $data)) {
                        require_once __DIR__ . '/core/admin_path.php';
                        $check = orbitraValidateAdminPath($pdo, $data['admin_path']);
                        if (!$check['ok']) {
                            echo json_encode(['status' => 'error', 'message' => $check['error']]);
                            break;
                        }
                        $data['admin_path'] = $check['value'];
                        $adminPathNotice = $check['value'];
                    }

                    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");

                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            $value = json_encode($value);
                        }
                        $stmt->execute([$key, $value]);
                    }

                    $result = ['status' => 'success'];
                    if ($adminPathNotice !== null) {
                        $result['admin_path'] = $adminPathNotice;
                        $result['admin_url'] = $adminPathNotice === ''
                            ? '/admin.php'
                            : '/' . $adminPathNotice;
                    }
                    echo json_encode($result);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'postback_url':
            // Return the postback URL for this tracker
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'");
            $key = $stmt->fetchColumn() ?: 'fd12e72';

            $postbackUrl = "$protocol://$host/$key/postback";

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'postback_url' => $postbackUrl,
                    'postback_key' => $key,
                    'example' => "$postbackUrl?subid=CLICKID&status=lead&payout=10"
                ]
            ]);
            break;

        case 'system_status':
            try {
                // Database size
                $dbFile = __DIR__ . '/orbitra_db.sqlite';
                $dbSize = file_exists($dbFile) ? filesize($dbFile) : 0;

                // Total Clicks
                $clicksCount = $pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();
                // Total Conversions
                $convCount = $pdo->query("SELECT COUNT(*) FROM conversions")->fetchColumn();

                // Disk Space
                $diskFree = disk_free_space(__DIR__);
                $diskTotal = disk_total_space(__DIR__);
                $diskUsedPercent = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0;

                // System load (unix)
                $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

                // CPU cores
                $cpuCores = 1;
                if (is_readable('/proc/cpuinfo')) {
                    $cpuinfo = file_get_contents('/proc/cpuinfo');
                    preg_match_all('/^processor/m', $cpuinfo, $matches);
                    $cpuCores = count($matches[0]) ?: 1;
                } elseif (PHP_OS_FAMILY === 'Darwin') {
                    $cpuCores = (int) orbitraShell('sysctl -n hw.ncpu 2>/dev/null') ?: 1;
                } elseif (PHP_OS_FAMILY === 'Windows') {
                    $cpuCores = (int) orbitraShell('echo %NUMBER_OF_PROCESSORS%') ?: 1;
                }

                // System memory (Linux)
                $totalMem = 0;
                $freeMem = 0;
                $usedMemPercent = 0;
                if (is_readable('/proc/meminfo')) {
                    $meminfo = file_get_contents('/proc/meminfo');
                    preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch);
                    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch);
                    preg_match('/MemFree:\s+(\d+)/', $meminfo, $freeMatch);
                    $totalMem = isset($totalMatch[1]) ? (int) $totalMatch[1] * 1024 : 0;
                    $availableMem = isset($availMatch[1]) ? (int) $availMatch[1] * 1024 : (isset($freeMatch[1]) ? (int) $freeMatch[1] * 1024 : 0);
                    $freeMem = $availableMem;
                    $usedMemPercent = $totalMem > 0 ? round((($totalMem - $freeMem) / $totalMem) * 100, 1) : 0;
                }

                // PHP Memory
                $memoryUsage = memory_get_usage(true);
                $memoryPeak = memory_get_peak_usage(true);
                $memoryLimit = ini_get('memory_limit');
                $memoryLimitBytes = 0;
                if (preg_match('/^(\d+)(.)$/', $memoryLimit, $matches)) {
                    $value = (int) $matches[1];
                    $unit = strtoupper($matches[2]);
                    $memoryLimitBytes = match ($unit) {
                        'G' => $value * 1024 * 1024 * 1024,
                        'M' => $value * 1024 * 1024,
                        'K' => $value * 1024,
                        default => $value
                    };
                }

                // PHP Info
                $phpVersion = PHP_VERSION;
                $phpExtensions = [
                    'pdo' => extension_loaded('pdo'),
                    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                    'pdo_mysql' => extension_loaded('pdo_mysql'),
                    'curl' => extension_loaded('curl'),
                    'mbstring' => extension_loaded('mbstring'),
                    'json' => extension_loaded('json'),
                    'zip' => extension_loaded('zip'),
                    // Optional: improves landing-slug transliteration for
                    // alphabets the built-in fallback table does not cover.
                    'intl' => extension_loaded('intl'),
                ];

                // SQLite info
                $sqliteVersion = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                $sqliteJournalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
                $sqliteSynchronous = $pdo->query('PRAGMA synchronous')->fetchColumn();

                // Geo databases status
                $geoDbs = [];
                $sypexFile = __DIR__ . '/var/geoip/SxGeoCity/SxGeoCity.dat';
                $maxmindFile = __DIR__ . '/geo/GeoLite2-City.mmdb';
                $maxmindAsnFile = __DIR__ . '/geo/GeoLite2-ASN.mmdb';
                $ip2locCandidates = [
                    __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN',
                    __DIR__ . '/geo/IP2LOCATION-LITE.BIN',
                ];
                $ip2locFile = null;
                foreach ($ip2locCandidates as $candidate) {
                    if (file_exists($candidate)) {
                        $ip2locFile = $candidate;
                        break;
                    }
                }

                $geoDbs[] = [
                    'name' => 'Sypex Geo City',
                    'status' => file_exists($sypexFile) ? 'ok' : 'missing',
                    'size' => file_exists($sypexFile) ? filesize($sypexFile) : 0,
                    'updated' => file_exists($sypexFile) ? date('Y-m-d H:i', filemtime($sypexFile)) : null
                ];
                $geoDbs[] = [
                    'name' => 'MaxMind GeoLite2',
                    'status' => file_exists($maxmindFile) ? 'ok' : 'missing',
                    'size' => file_exists($maxmindFile) ? filesize($maxmindFile) : 0,
                    'updated' => file_exists($maxmindFile) ? date('Y-m-d H:i', filemtime($maxmindFile)) : null
                ];
                $geoDbs[] = [
                    'name' => 'MaxMind GeoLite2-ASN',
                    'status' => file_exists($maxmindAsnFile) ? 'ok' : 'missing',
                    'size' => file_exists($maxmindAsnFile) ? filesize($maxmindAsnFile) : 0,
                    'updated' => file_exists($maxmindAsnFile) ? date('Y-m-d H:i', filemtime($maxmindAsnFile)) : null
                ];
                $geoDbs[] = [
                    'name' => 'IP2Location LITE (DB11)',
                    'status' => ($ip2locFile && file_exists($ip2locFile)) ? 'ok' : 'missing',
                    'size' => ($ip2locFile && file_exists($ip2locFile)) ? filesize($ip2locFile) : 0,
                    'updated' => ($ip2locFile && file_exists($ip2locFile)) ? date('Y-m-d H:i', filemtime($ip2locFile)) : null
                ];

                // Server software detection
                $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
                $webServer = 'Unknown';
                $webServerVersion = '';
                if (stripos($serverSoftware, 'nginx') !== false) {
                    $webServer = 'nginx';
                    if (preg_match('/nginx\/([\d.]+)/i', $serverSoftware, $m)) {
                        $webServerVersion = $m[1];
                    }
                } elseif (stripos($serverSoftware, 'apache') !== false) {
                    $webServer = 'Apache';
                    if (preg_match('/Apache\/([\d.]+)/i', $serverSoftware, $m)) {
                        $webServerVersion = $m[1];
                    }
                } elseif (stripos($serverSoftware, 'litespeed') !== false) {
                    $webServer = 'LiteSpeed';
                }

                // PHP SAPI
                $phpSapi = PHP_SAPI;

                // Calculate recommendations
                $warnings = [];
                $recommendations = [];

                // Disk space warning
                if ($diskUsedPercent > 90) {
                    $warnings[] = ['level' => 'critical', 'message' => 'Критически мало места на диске! Освободите место.'];
                } elseif ($diskUsedPercent > 80) {
                    $warnings[] = ['level' => 'warning', 'message' => 'Мало места на диске. Рекомендуется очистить старые логи.'];
                }

                // CPU load warning
                $loadPerCore = $cpuCores > 0 ? $load[0] / $cpuCores : $load[0];
                if ($loadPerCore > 2) {
                    $warnings[] = ['level' => 'critical', 'message' => 'Высокая нагрузка на CPU. Рассмотрите апгрейд сервера.'];
                } elseif ($loadPerCore > 1) {
                    $warnings[] = ['level' => 'warning', 'message' => 'Повышенная нагрузка на CPU.'];
                }

                // RAM warning
                if ($usedMemPercent > 90) {
                    $warnings[] = ['level' => 'critical', 'message' => 'Критически мало оперативной памяти!'];
                } elseif ($usedMemPercent > 80) {
                    $warnings[] = ['level' => 'warning', 'message' => 'Мало свободной оперативной памяти.'];
                }

                // Database size recommendation
                if ($dbSize > 500 * 1024 * 1024) { // > 500MB
                    $recommendations[] = ['level' => 'info', 'message' => 'База данных превышает 500MB. Рассмотрите переход на MySQL для лучшей производительности.'];
                } elseif ($dbSize > 200 * 1024 * 1024) { // > 200MB
                    $recommendations[] = ['level' => 'info', 'message' => 'База данных растёт. При достижении 500MB рекомендуется перейти на MySQL.'];
                }

                // Geo DB recommendation
                $hasGeoDb = false;
                foreach ($geoDbs as $geo) {
                    if ($geo['status'] === 'ok') {
                        $hasGeoDb = true;
                        break;
                    }
                }
                if (!$hasGeoDb) {
                    $warnings[] = ['level' => 'warning', 'message' => 'noGeoDb'];
                }

                // Estimate capacity
                $capacityScore = 100;
                if ($diskUsedPercent > 80)
                    $capacityScore -= 20;
                if ($usedMemPercent > 80)
                    $capacityScore -= 20;
                if ($loadPerCore > 1)
                    $capacityScore -= 15;
                if ($dbSize > 200 * 1024 * 1024)
                    $capacityScore -= 10;

                $capacityScore = max(0, $capacityScore);

                // Components status
                $components = [
                    [
                        'name' => 'PHP Runtime',
                        'version' => $phpVersion,
                        'memory_bytes' => $memoryUsage,
                        'memory_limit_bytes' => $memoryLimitBytes,
                        'sapi' => $phpSapi,
                        'status' => 'running'
                    ],
                    [
                        'name' => 'Web Server',
                        'type' => $webServer,
                        'version' => $webServerVersion,
                        'full_info' => $serverSoftware,
                        'status' => 'running'
                    ],
                    [
                        'name' => 'SQLite Database',
                        'version' => $sqliteVersion,
                        'journal_mode' => $sqliteJournalMode,
                        'synchronous' => $sqliteSynchronous,
                        'size_bytes' => $dbSize,
                        'status' => file_exists($dbFile) ? 'running' : 'error'
                    ]
                ];

                // Add geo DB as components
                foreach ($geoDbs as $geo) {
                    $components[] = [
                        'name' => $geo['name'],
                        'size_bytes' => $geo['size'],
                        'updated' => $geo['updated'],
                        'status' => $geo['status'] === 'ok' ? 'running' : 'missing'
                    ];
                }

                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'version' => (defined('ORBITRA_VERSION') ? ORBITRA_VERSION : '0.9.2.9') . '-Orbitra',
                        'clicks' => (int) $clicksCount,
                        'conversions' => (int) $convCount,
                        'db_size_bytes' => $dbSize,
                        // Disk
                        'disk_free_bytes' => $diskFree,
                        'disk_total_bytes' => $diskTotal,
                        'disk_used_percent' => $diskUsedPercent,
                        // CPU
                        'cpu_cores' => $cpuCores,
                        'cpu_load' => round($load[0], 2),
                        'cpu_load_5' => round($load[1], 2),
                        'cpu_load_15' => round($load[2], 2),
                        'cpu_load_per_core' => round($loadPerCore, 2),
                        // Memory
                        'system_total_memory' => $totalMem,
                        'system_free_memory' => $freeMem,
                        'system_memory_used_percent' => $usedMemPercent,
                        'php_memory_bytes' => $memoryUsage,
                        'php_memory_peak_bytes' => $memoryPeak,
                        'php_memory_limit' => $memoryLimit,
                        'php_memory_limit_bytes' => $memoryLimitBytes,
                        // PHP
                        'php_version' => $phpVersion,
                        'php_sapi' => $phpSapi,
                        'php_extensions' => $phpExtensions,
                        // Server
                        'server_software' => $serverSoftware,
                        'web_server' => $webServer,
                        'web_server_version' => $webServerVersion,
                        // Geo
                        'geo_dbs' => $geoDbs,
                        // Capacity
                        'capacity_score' => $capacityScore,
                        'warnings' => $warnings,
                        'recommendations' => $recommendations,
                        // Components
                        'components' => $components
                    ]
                ]);
            } catch (\Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // === UPDATE SYSTEM API ===
        case 'check_update':
            $currentVersion = defined('ORBITRA_VERSION') ? ORBITRA_VERSION : '0.9.2.9';

            // Bootstrap dependencies for the 0.9.7.3 -> 0.9.7.4 transition.
            // The update request itself is executed by the old api.php already in
            // memory, so that old handler can pull the new source but cannot run the
            // Composer step that only exists in the new source. UpdatePage always
            // performs this check again after a successful pull; use that first new
            // request to finish the locked dependency install. Later releases use
            // run_update's normal post-pull Composer step and skip this branch.
            $dependencyBootstrap = null;
            if (
                version_compare($currentVersion, '0.9.7.4', '>=')
                && isset($_SESSION['role'])
                && $_SESSION['role'] === 'admin'
                && !class_exists('\\IP2Proxy\\Database')
                && is_file(__DIR__ . '/composer.phar')
                && is_file(__DIR__ . '/composer.lock')
            ) {
                $disabled = array_filter(preg_split('/[\s,]+/', (string) ini_get('disable_functions')));
                if (function_exists('exec') && !in_array('exec', $disabled, true)) {
                    $bootstrapOutput = [];
                    $bootstrapResult = orbitraComposerInstall(__DIR__, $bootstrapOutput);
                    $dependencyBootstrap = [
                        'attempted' => true,
                        'success' => $bootstrapResult['ok'],
                    ];
                    if (!$bootstrapResult['ok']) {
                        $dependencyBootstrap['message'] = 'Не удалось установить Composer-зависимости: '
                            . implode(' ', $bootstrapOutput);
                    } elseif ($bootstrapResult['degraded']) {
                        $dependencyBootstrap['message'] = $bootstrapResult['hint'];
                    }
                } else {
                    $dependencyBootstrap = [
                        'attempted' => false,
                        'success' => false,
                        'message' => 'Для установки новых Composer-зависимостей требуется exec() или ручной запуск composer install.',
                    ];
                }
            }

            // URL to check for latest version (change to your server or GitHub raw file)
            // Example for GitHub: 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/version.json'
            $versionCheckUrl = 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/version.json';

            $latestVersion = $currentVersion; // Default: no update
            $releaseNotes = '';
            $downloadUrl = '';
            $releasedAt = null;
            // A failed GitHub fetch must not masquerade as "you are up to date":
            // raw.githubusercontent is unreachable from a fair share of hosting
            // networks, and silently answering latest=current sent users away
            // from updates that existed. The panel shows the check_failed hint
            // and points at the manual git pull instead.
            $checkFailedReason = null;

            // Try to fetch latest version from remote server
            if (function_exists('curl_init')) {
                $ch = curl_init($versionCheckUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'Orbitra-Tracker/' . $currentVersion
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed

                if ($httpCode === 200 && $response) {
                    $data = json_decode($response, true);
                    if ($data && isset($data['version'])) {
                        $latestVersion = $data['version'];
                        $releaseNotes = $data['release_notes'] ?? '';
                        $downloadUrl = $data['download_url'] ?? '';
                        $releasedAt = $data['released_at'] ?? null;
                    } else {
                        $checkFailedReason = 'Unreadable version.json (HTTP ' . $httpCode . ')';
                    }
                } else {
                    $checkFailedReason = 'GitHub unreachable (HTTP ' . $httpCode . ', curl error: ' . curl_error($ch) . ')';
                }
            } else {
                $checkFailedReason = 'curl is not available on this server';
            }

            // Compare versions
            $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

            $updateInfo = [
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'update_available' => $updateAvailable,
                'release_notes' => $releaseNotes,
                'download_url' => $downloadUrl,
                'released_at' => $releasedAt,
                'dependency_bootstrap' => $dependencyBootstrap,
                // null when the fetch worked; otherwise the panel explains that
                // "no update" here means "could not check", not "up to date".
                'check_failed' => $checkFailedReason,
            ];

            echo json_encode(['status' => 'success', 'data' => $updateInfo]);
            break;

        case 'run_update':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Security: Only admins can trigger git pull
                if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                    break;
                }

                // Auto-update shells out to git and Composer, which needs exec().
                // Many shared hosts list these in disable_functions, in which case
                // the bare exec() below fatals with "Call to undefined function"
                // and nginx answers 500 with no JSON. Detect it up front so the
                // panel gets an actionable message instead of a network error.
                $disabled = array_filter(preg_split('/[\s,]+/', (string) ini_get('disable_functions')));
                $canExec = function_exists('exec')
                    && !in_array('exec', $disabled, true);
                if (!$canExec) {
                    $manualCommand = 'sudo apt-get install -y php-bcmath'
                        . ' && cd ' . escapeshellarg(__DIR__)
                        . ' && git pull --ff-only origin main'
                        . ' && php composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader';
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Автоматическое обновление недоступно: на сервере отключена функция exec() '
                            . '(смотрите disable_functions в php.ini). Обновитесь вручную через SSH '
                            . 'от системного пользователя, которому принадлежит каталог Orbitra: '
                            . $manualCommand
                    ]);
                    break;
                }

                // Perform a git pull if inside a git repository
                $isGit = is_dir(__DIR__ . '/.git');
                if ($isGit) {
                    $repoDir = __DIR__;
                    $git = 'git -C ' . escapeshellarg($repoDir);

                    // "dubious ownership": git refuses to touch a repository owned by
                    // another user. It happens the moment someone pulls over SSH as
                    // root, because this runs as the web user. Catch it first — every
                    // git call below would otherwise fail, and the branch check would
                    // report the error text as the branch name.
                    $ownerProbe = [];
                    $ownerCode = 0;
                    exec($git . ' rev-parse --is-inside-work-tree 2>&1', $ownerProbe, $ownerCode);
                    if (stripos(implode("\n", $ownerProbe), 'dubious ownership') !== false) {
                        $webUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data')
                            : 'www-data';
                        $owner = @fileowner($repoDir) !== false && function_exists('posix_getpwuid')
                            ? (posix_getpwuid(@fileowner($repoDir))['name'] ?? 'root')
                            : 'root';
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Git отказывается работать с репозиторием: каталог принадлежит пользователю "'
                                . $owner . '", а обновление выполняется от "' . $webUser . '" (dubious ownership). '
                                . 'Так бывает, если хоть раз потянуть обновление по SSH от root. '
                                . 'Выполните на сервере: chown -R ' . $webUser . ':' . $webUser . ' ' . $repoDir
                                . ' — после этого кнопка обновления заработает. '
                                . 'Либо разово обновитесь вручную: sudo -u ' . $webUser . ' git -C ' . $repoDir . ' pull --ff-only origin main'
                        ]);
                        break;
                    }

                    // The installer used to `chmod +x cli/*.php`, and git tracks the
                    // executable bit — so those files looked locally modified and
                    // every pull touching them aborted with "your local changes
                    // would be overwritten". The chmod is gone, but installs that
                    // already have it need git told to stop caring about the mode,
                    // or they can never update again.
                    exec($git . ' config core.fileMode false 2>&1');

                    // Security: Ensure we are on a safe branch
                    $allowedBranches = ['main', 'master'];
                    $currentBranch = trim(exec($git . ' rev-parse --abbrev-ref HEAD 2>&1'));
                    if (!in_array($currentBranch, $allowedBranches)) {
                        echo json_encode(['status' => 'error', 'message' => 'Unsafe branch: ' . htmlspecialchars($currentBranch)]);
                        break;
                    }

                    // A failed merge or stash restore poisons every later pull. Repair
                    // that state before trying to stash again; `git stash` itself
                    // refuses to run while the index contains unmerged entries.
                    $preflightOutput = [];
                    if (orbitraGitHasUnmergedFiles($repoDir)) {
                        $preflightOutput[] = '[Unmerged files found before update — repairing the git worktree]';
                        if (!orbitraGitRepairConflictState($repoDir, $preflightOutput)) {
                            echo json_encode([
                                'status' => 'error',
                                'message' => 'Не удалось автоматически очистить незавершённый git-конфликт. '
                                    . 'Выполните на сервере: git -C ' . escapeshellarg($repoDir) . ' reset --hard HEAD',
                                'output' => implode("\n", $preflightOutput),
                            ]);
                            break;
                        }
                    }

                    // Stash local changes if any, then pull
                    $statusLines = [];
                    $statusReturn = 0;
                    exec($git . ' status --porcelain 2>&1', $statusLines, $statusReturn);
                    $hasLocalChanges = ($statusReturn === 0 && !empty($statusLines));

                    $stashed = false;
                    if ($hasLocalChanges) {
                        $stashOutput = [];
                        $stashReturn = 0;
                        exec($git . ' stash push -u -m "orbitra-auto-update" 2>&1', $stashOutput, $stashReturn);
                        $stashed = ($stashReturn === 0);
                    }

                    $pullOutput = [];
                    $returnCode = 0;
                    exec($git . ' pull --ff-only origin ' . escapeshellarg($currentBranch) . ' 2>&1', $pullOutput, $returnCode);
                    $output = array_merge($preflightOutput, $pullOutput);

                    // If server has no SSH keys, pulling from git@github.com may fail; retry with HTTPS without changing origin.
                    if ($returnCode !== 0) {
                        $joined = strtolower(implode("\n", $pullOutput));
                        if (strpos($joined, 'permission denied (publickey)') !== false || strpos($joined, 'could not read from remote repository') !== false) {
                            $originUrl = trim(exec($git . ' remote get-url origin 2>&1'));
                            $httpsUrl = '';
                            if (preg_match('#^git@github\\.com:([^/]+)/(.+?)(?:\\.git)?$#', $originUrl, $m)) {
                                $httpsUrl = 'https://github.com/' . $m[1] . '/' . $m[2] . '.git';
                            } elseif (preg_match('#^ssh://git@github\\.com/([^/]+)/(.+?)(?:\\.git)?$#', $originUrl, $m)) {
                                $httpsUrl = 'https://github.com/' . $m[1] . '/' . $m[2] . '.git';
                            }

                            if ($httpsUrl !== '') {
                                $output[] = '[Retrying via HTTPS]';
                                $retryOut = [];
                                $retryCode = 0;
                                exec($git . ' pull --ff-only ' . escapeshellarg($httpsUrl) . ' ' . escapeshellarg($currentBranch) . ' 2>&1', $retryOut, $retryCode);
                                $output = array_merge($output, $retryOut);
                                $pullOutput = $retryOut;
                                $returnCode = $retryCode;
                            } else {
                                $output[] = '[Hint] Configure origin as https://github.com/<user>/<repo>.git for web-based updates.';
                            }
                        }
                    }

                    // Fallback: if local framework edits blocked the pull, discard
                    // the tracked working-tree changes and retry. User data is safe:
                    // databases, uploaded landings, GeoIP data, caches and logs are ignored.
                    if ($returnCode !== 0) {
                        if (orbitraGitOutputShowsConflict($pullOutput)) {
                            $output[] = '[Conflicts/local changes block update — repairing the git worktree]';
                            if (!orbitraGitRepairConflictState($repoDir, $output)) {
                                $returnCode = 1;
                            } else {
                                $retryLocal = [];
                                $retryLocalCode = 0;
                                exec($git . ' pull --ff-only origin ' . escapeshellarg($currentBranch) . ' 2>&1', $retryLocal, $retryLocalCode);
                                $output = array_merge($output, $retryLocal);
                                $pullOutput = $retryLocal;
                                $returnCode = $retryLocalCode;
                            }
                        }
                    }

                    // A root-owned working tree stops the pull at the first file it
                    // has to replace. It is the same class of problem as "dubious
                    // ownership" but reported differently, so it gets the same
                    // treatment: name the cause and hand over the command that
                    // fixes it, rather than passing git's wording to the operator.
                    // The usual source is a root-run install: npm builds
                    // frontend/dist as root, and unlinking a file there needs write
                    // permission on the directory, not on the file.
                    if ($returnCode !== 0) {
                        $joinedPerm = strtolower(implode("\n", $output));
                        if (
                            strpos($joinedPerm, 'permission denied') !== false ||
                            strpos($joinedPerm, 'unable to unlink') !== false ||
                            strpos($joinedPerm, 'unable to create file') !== false
                        ) {
                            $webUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data')
                                : 'www-data';
                            // We are bailing out for good, so put back anything we
                            // stashed before the pull — leaving it hidden in a stash
                            // the operator never asked for is worse than a failed pop.
                            if ($stashed) {
                                $popPerm = [];
                                $popPermCode = 0;
                                exec($git . ' stash pop 2>&1', $popPerm, $popPermCode);
                                $output = array_merge($output, $popPermCode === 0 ? ['[Stash restored]'] : ['[Stash restore failed]']);
                            }
                            echo json_encode([
                                'status' => 'error',
                                'message' => 'Обновление не может заменить файлы: часть каталога принадлежит другому пользователю, '
                                    . 'а git выполняется от "' . $webUser . '". Чаще всего так выходит после установки от root — '
                                    . 'сборка фронтенда создаёт frontend/dist от root, и заменить файл в этом каталоге веб-сервер уже не может. '
                                    . 'Выполните на сервере: chown -R ' . $webUser . ':' . $webUser . ' ' . $repoDir
                                    . ' — после этого кнопка обновления заработает.',
                                'output' => implode("\n", $output),
                            ]);
                            break;
                        }
                    }

                    // Restore stashed changes after a successful pull (only if we
                    // actually stashed — avoids popping an unrelated old stash).
                    if ($stashed && $returnCode === 0) {
                        $popOutput = [];
                        $popReturn = 0;
                        exec($git . ' stash pop 2>&1', $popOutput, $popReturn);
                        if ($popReturn === 0) {
                            $output = array_merge($output, ['[Stash restored]']);
                        } else {
                            $output = array_merge($output, ['[Stash restore conflicted — local changes remain saved in git stash]'], $popOutput ?? []);
                            // `stash pop` can apply half the patch and leave an
                            // unmerged index. Keep the stash, but remove that partial
                            // application so the next admin update is not blocked.
                            if (!orbitraGitRepairConflictState($repoDir, $output)) {
                                $returnCode = 1;
                                $output[] = '[Could not clean the failed stash restore]';
                            }
                        }
                    }

                    // Composer packages are ignored by git. A source update that
                    // adds a reader (for example IP2Proxy) must therefore install
                    // the locked dependencies on existing VPS installations too.
                    $composerNotice = '';
                    if ($returnCode === 0 && file_exists($repoDir . '/composer.phar') && file_exists($repoDir . '/composer.lock')) {
                        $composerOutput = [];
                        $composerResult = orbitraComposerInstall($repoDir, $composerOutput);
                        $output[] = '[Composer dependencies]';
                        $output = array_merge($output, $composerOutput);
                        if (!$composerResult['ok']) {
                            $returnCode = 1;
                            $output[] = '[Composer install failed — source was updated, but dependencies need attention]';
                        } elseif ($composerResult['degraded']) {
                            $composerNotice = ' ВНИМАНИЕ: ' . $composerResult['hint'];
                        }
                    }

                    if ($returnCode === 0) {
                        echo json_encode(['status' => 'success', 'message' => 'Обновлено успешно.' . $composerNotice . ' Вывод: ' . implode(" ", $output)]);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Ошибка git pull: ' . implode(" ", $output)]);
                    }
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Автоматическое обновление доступно только для git-установок. Скачайте новую версию вручную.'
                    ]);
                }
            }
            break;

        case 'test_postback':
            // Test postback endpoint
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $subid = $data['subid'] ?? null;
                $status = $data['status'] ?? 'lead';
                $payout = $data['payout'] ?? 0;

                if (!$subid) {
                    echo json_encode(['status' => 'error', 'message' => 'subid is required']);
                    break;
                }

                // Check if click exists
                $stmt = $pdo->prepare("SELECT * FROM clicks WHERE id = ?");
                $stmt->execute([$subid]);
                $click = $stmt->fetch();

                if (!$click) {
                    echo json_encode(['status' => 'error', 'message' => "Click with subid=$subid not found"]);
                    break;
                }

                // Insert conversion
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO conversions (click_id, status, payout, currency, campaign_id, offer_id, ip, created_at)
                        VALUES (?, ?, ?, 'USD', ?, ?, ?, datetime('now'))
                    ");
                    $stmt->execute([$subid, $status, $payout, $click['campaign_id'], $click['offer_id'], $click['ip']]);

                    // Update click
                    $pdo->prepare("UPDATE clicks SET is_conversion = 1, revenue = revenue + ? WHERE id = ?")->execute([$payout, $subid]);

                    echo json_encode(['status' => 'success', 'message' => 'Test conversion recorded']);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;



        // === GEO DATABASES API ===
        case 'geo_dbs':
            // Earlier builds could put a valid IP2Proxy PX file in the DB11
            // slot. Move it to the correct slot before reporting statuses.
            orbitraGeoMigrateMisplacedProxy(__DIR__);

            $geoDir = __DIR__ . '/var/geoip/SxGeoCity';
            $datFile = $geoDir . '/SxGeoCity.dat';

            $dbs = [];
            // Sypex Geo City Lite
            $sypex = [
                'id' => 'sypex_city_lite',
                'name' => 'Sypex Geo City Lite',
                'type' => 'Country-Region-City',
                'status' => orbitraGeoFileStatus($datFile, 'sypex_city', 'SxGeoCity.dat'),
                'updated_at' => file_exists($datFile) ? date('Y-m-d H:i:s', filemtime($datFile)) : null,
                'size' => file_exists($datFile) ? filesize($datFile) : 0
            ];
            $dbs[] = $sypex;

            // IP2Location LITE BIN (DB11)
            $ip2locCandidates = [
                __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN',
                __DIR__ . '/geo/IP2LOCATION-LITE.BIN'
            ];
            $ip2locDb = null;
            foreach ($ip2locCandidates as $candidate) {
                if (file_exists($candidate)) {
                    $ip2locDb = $candidate;
                    break;
                }
            }
            $ip2loc = [
                'id' => 'ip2location_lite_db11',
                'name' => 'IP2Location LITE (DB11)',
                'type' => 'Country-Region-City-Latitude-Longitude-ZIPCode-TimeZone (IPv4+IPv6)',
                'status' => $ip2locDb
                    ? orbitraGeoFileStatus($ip2locDb, 'ip2location_geo', basename($ip2locDb))
                    : 'missing',
                'updated_at' => ($ip2locDb && file_exists($ip2locDb)) ? date('Y-m-d H:i:s', filemtime($ip2locDb)) : null,
                'size' => ($ip2locDb && file_exists($ip2locDb)) ? filesize($ip2locDb) : 0
            ];
            $dbs[] = $ip2loc;

            $ip2locationAsnDb = __DIR__ . '/geo/IP2LOCATION-LITE-ASN.BIN';
            $dbs[] = [
                'id' => 'ip2location_lite_asn',
                'name' => 'IP2Location ASN LITE',
                'type' => 'ASN-Network Organization (IPv4+IPv6)',
                'status' => orbitraGeoFileStatus($ip2locationAsnDb, 'ip2location_asn', 'IP2LOCATION-LITE-ASN.BIN'),
                'updated_at' => file_exists($ip2locationAsnDb) ? date('Y-m-d H:i:s', filemtime($ip2locationAsnDb)) : null,
                'size' => file_exists($ip2locationAsnDb) ? filesize($ip2locationAsnDb) : 0
            ];

            $ip2proxyDb = __DIR__ . '/geo/IP2PROXY-LITE-PX12.BIN';
            $dbs[] = [
                'id' => 'ip2proxy_lite_px12',
                'name' => 'IP2Proxy LITE (PX12)',
                'type' => 'Proxy-VPN-TOR-Datacenter-Residential-Threat-FraudScore (IPv4+IPv6)',
                'status' => orbitraGeoFileStatus($ip2proxyDb, 'ip2proxy', 'IP2PROXY-LITE-PX12.BIN'),
                'updated_at' => file_exists($ip2proxyDb) ? date('Y-m-d H:i:s', filemtime($ip2proxyDb)) : null,
                'size' => file_exists($ip2proxyDb) ? filesize($ip2proxyDb) : 0
            ];

            // MaxMind GeoLite2-City
            $maxMindDb = __DIR__ . '/geo/GeoLite2-City.mmdb';
            $maxMind = [
                'id' => 'maxmind_city',
                'name' => 'MaxMind GeoLite2-City (Requires License Key)',
                'type' => 'Country-City',
                'status' => orbitraGeoFileStatus($maxMindDb, 'maxmind_city', 'GeoLite2-City.mmdb'),
                'updated_at' => file_exists($maxMindDb) ? date('Y-m-d H:i:s', filemtime($maxMindDb)) : null,
                'size' => file_exists($maxMindDb) ? filesize($maxMindDb) : 0
            ];
            $dbs[] = $maxMind;

            // Uses the same free MaxMind Account ID + License Key as City.
            // Cloak and ISP filters need this file to resolve ASN/organisation.
            $maxMindAsnDb = __DIR__ . '/geo/GeoLite2-ASN.mmdb';
            $dbs[] = [
                'id' => 'maxmind_asn',
                'name' => 'MaxMind GeoLite2-ASN (Free, Requires License Key)',
                'type' => 'ASN-ISP',
                'status' => orbitraGeoFileStatus($maxMindAsnDb, 'maxmind_asn', 'GeoLite2-ASN.mmdb'),
                'updated_at' => file_exists($maxMindAsnDb) ? date('Y-m-d H:i:s', filemtime($maxMindAsnDb)) : null,
                'size' => file_exists($maxMindAsnDb) ? filesize($maxMindAsnDb) : 0
            ];

            echo json_encode(['status' => 'success', 'data' => $dbs]);
            break;

        case 'geo_db_upload':
            // One upload control accepts all supported provider files, but every
            // format is identified and stored in its own slot.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['status' => 'error', 'message' => 'Ошибка загрузки файла']);
                    break;
                }

                $file = $_FILES['file'];
                $fileName = (string) $file['name'];
                $fileTmp = $file['tmp_name'];
                $fileSize = $file['size'];

                $allowedExts = ['dat', 'zip', 'bin', 'mmdb'];
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExts, true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Разрешены только файлы .dat, .bin, .mmdb и .zip']);
                    break;
                }

                if ($fileSize > 512 * 1024 * 1024) { // 512MB max
                    echo json_encode(['status' => 'error', 'message' => 'Файл слишком большой (макс. 512MB)']);
                    break;
                }

                try {
                    if ($ext === 'zip') {
                        $zip = new ZipArchive;
                        if ($zip->open($fileTmp) !== true) {
                            throw new RuntimeException('Не удалось открыть ZIP архив');
                        }

                        $installed = [];
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $entry = $zip->statIndex($i);
                            $entryName = (string) ($entry['name'] ?? '');
                            $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                            if (!in_array($entryExt, ['dat', 'bin', 'mmdb'], true)) {
                                continue;
                            }
                            if ((int) ($entry['size'] ?? 0) > 3 * 1024 * 1024 * 1024) {
                                throw new RuntimeException('Файл внутри архива слишком большой.');
                            }

                            $input = $zip->getStream($entryName);
                            if ($input === false) {
                                throw new RuntimeException('Не удалось прочитать ' . basename($entryName) . ' из архива.');
                            }
                            $tempPath = tempnam(sys_get_temp_dir(), 'orbitra-geo-upload-');
                            $output = $tempPath !== false ? fopen($tempPath, 'wb') : false;
                            if ($output === false) {
                                fclose($input);
                                throw new RuntimeException('Не удалось создать временный файл базы.');
                            }
                            stream_copy_to_stream($input, $output);
                            fclose($input);
                            fclose($output);

                            try {
                                $result = orbitraGeoInstallFile($tempPath, basename($entryName), __DIR__, true);
                                $installed[] = $result['label'];
                                logSystem($pdo, 'INFO', 'Geo DB uploaded via ZIP', [
                                    'kind' => $result['kind'],
                                    'name' => basename($entryName),
                                ]);
                            } finally {
                                if (is_file($tempPath)) {
                                    @unlink($tempPath);
                                }
                            }
                        }
                        $zip->close();

                        if (empty($installed)) {
                            throw new RuntimeException('В архиве не найдено поддерживаемых файлов (.dat, .bin, .mmdb)');
                        }
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Установлено: ' . implode(', ', array_unique($installed)),
                        ]);
                    } else {
                        $result = orbitraGeoInstallFile($fileTmp, $fileName, __DIR__);
                        logSystem($pdo, 'INFO', 'Geo DB uploaded directly', [
                            'kind' => $result['kind'],
                            'name' => basename($fileName),
                        ]);
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Установлено: ' . $result['label'],
                        ]);
                    }
                } catch (Throwable $e) {
                    logSystem($pdo, 'ERROR', 'Geo DB Upload Error: ' . $e->getMessage());
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'geo_db_update':
            // Security: limit execution time for downloads
            // set_time_limit(300); // Disabled for PHP-FPM compatibility

            $input = json_decode(orbitraRequestBody(), true);
            $dbId = $_POST['id'] ?? $input['id'] ?? null;

            if (!in_array($dbId, [
                'sypex_city_lite',
                'maxmind_city',
                'maxmind_asn',
                'ip2location_lite_db11',
                'ip2location_lite_asn',
                'ip2proxy_lite_px12',
            ], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Неизвестная база данных: ' . htmlspecialchars($dbId)]);
                break;
            }

            // Helper function to download file using cURL
            $downloadFile = function ($url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'Orbitra/1.0'
                ]);
                $data = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed

                if ($error || $httpCode !== 200) {
                    return null;
                }
                return $data;
            };

            $ip2Packages = [
                'ip2location_lite_db11' => [
                    'variant' => 'DB11LITEBINIPV6',
                    'kind' => 'ip2location_geo',
                ],
                'ip2location_lite_asn' => [
                    'variant' => 'DBASNLITEBINIPV6',
                    'kind' => 'ip2location_asn',
                ],
                'ip2proxy_lite_px12' => [
                    'variant' => 'PX12LITEBIN',
                    'kind' => 'ip2proxy',
                ],
            ];

            if (isset($ip2Packages[$dbId])) {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'ip2location_token'");
                $token = $stmt->fetchColumn();
                if (!$token) {
                    echo json_encode(['status' => 'error', 'message' => 'Не указан IP2Location Token. Укажите его в настройках "Гео-базы".']);
                    break;
                }

                $package = $ip2Packages[$dbId];
                $variant = $package['variant'];
                $tmpArchive = sys_get_temp_dir() . '/orbitra-ip2-' . bin2hex(random_bytes(6)) . '.zip';
                $url = 'https://www.ip2location.com/download?' . http_build_query([
                    'token' => $token,
                    'file' => $variant,
                ]);
                $ch = curl_init($url);
                $fp = @fopen($tmpArchive, 'wb');
                if ($fp === false) {
                    echo json_encode(['status' => 'error', 'message' => 'Не удалось создать временный файл для загрузки. Проверьте права на запись в ' . sys_get_temp_dir()]);
                    break;
                }
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 600);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Orbitra/1.0');
                $downloadOk = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                fclose($fp);

                if ($downloadOk === false || $httpCode !== 200 || !file_exists($tmpArchive) || filesize($tmpArchive) <= 1024) {
                    @unlink($tmpArchive);
                    $details = $curlError !== '' ? ' cURL: ' . $curlError : '';
                    echo json_encode(['status' => 'error', 'message' => "Не удалось скачать {$variant}. Проверьте токен и квоту IP2Location.{$details}"]);
                    break;
                }

                try {
                    $zip = new ZipArchive;
                    if ($zip->open($tmpArchive) !== true) {
                        throw new RuntimeException('Архив IP2Location не является корректным ZIP. Возможно, исчерпана квота скачиваний.');
                    }

                    $installed = null;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entryName = (string) $zip->getNameIndex($i);
                        if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'bin') {
                            continue;
                        }
                        $input = $zip->getStream($entryName);
                        $tempPath = tempnam(sys_get_temp_dir(), 'orbitra-ip2-bin-');
                        $output = $tempPath !== false ? fopen($tempPath, 'wb') : false;
                        if ($input === false || $output === false) {
                            if (is_resource($input)) {
                                fclose($input);
                            }
                            throw new RuntimeException('Не удалось распаковать BIN из архива.');
                        }
                        stream_copy_to_stream($input, $output);
                        fclose($input);
                        fclose($output);

                        try {
                            $classification = orbitraGeoClassifyFile($tempPath, basename($entryName));
                            if ($classification['kind'] !== $package['kind']) {
                                throw new RuntimeException('Полученный BIN имеет неожиданный тип: ' . $classification['kind']);
                            }
                            $candidate = orbitraGeoInstallFile($tempPath, basename($entryName), __DIR__, true);
                            $installed = $candidate;
                        } finally {
                            if (is_file($tempPath)) {
                                @unlink($tempPath);
                            }
                        }
                        break;
                    }
                    $zip->close();

                    if ($installed === null) {
                        throw new RuntimeException('В скачанном архиве не найден BIN.');
                    }

                    logSystem($pdo, 'INFO', 'IP2 database updated successfully', [
                        'variant' => $variant,
                        'kind' => $installed['kind'],
                    ]);
                    echo json_encode(['status' => 'success', 'message' => "База {$installed['label']} успешно обновлена ({$variant})"]);
                } catch (Throwable $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Ошибка установки: ' . $e->getMessage()]);
                }
                @unlink($tmpArchive);
                break;
            }

            if ($dbId === 'maxmind_city' || $dbId === 'maxmind_asn') {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'maxmind_license_key'");
                $license_key = $stmt->fetchColumn();

                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'maxmind_account_id'");
                $account_id = $stmt->fetchColumn();

                if (!$license_key || !$account_id) {
                    echo json_encode(['status' => 'error', 'message' => 'Не указаны MaxMind Account ID и/или License Key. Укажите их в настройках "Гео-базы".']);
                    break;
                }

                $editionId = $dbId === 'maxmind_asn' ? 'GeoLite2-ASN' : 'GeoLite2-City';
                // MaxMind redirects this permalink to a short-lived Cloudflare R2
                // URL. CURLOPT_FOLLOWLOCATION is required for downloads since 2024.
                $url = "https://download.maxmind.com/geoip/databases/{$editionId}/download?suffix=tar.gz";
                $tmpArchive = sys_get_temp_dir() . '/orbitra-' . strtolower($editionId) . '-' . bin2hex(random_bytes(6)) . '.tar.gz';

                // Download with Basic Authentication
                $ch = curl_init($url);
                $fp = @fopen($tmpArchive, 'wb');
                if ($fp === false) {
                    echo json_encode(['status' => 'error', 'message' => 'Не удалось создать временный файл для загрузки MaxMind.']);
                    break;
                }
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_USERPWD, $account_id . ':' . $license_key);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Orbitra/1.0');
                $downloadOk = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                fclose($fp);

                if ($downloadOk === false || $httpCode !== 200 || !file_exists($tmpArchive) || filesize($tmpArchive) <= 1024) {
                    @unlink($tmpArchive);
                    $details = $curlError !== '' ? " cURL: {$curlError}" : '';
                    echo json_encode(['status' => 'error', 'message' => "Failed to download {$editionId}. HTTP Code: {$httpCode}. Check your Account ID, License Key and outbound HTTPS access.{$details}"]);
                    break;
                }

                // Extract .mmdb
                $dbFileName = $editionId . '.mmdb';
                $destPath = __DIR__ . '/geo/' . $dbFileName;
                if (!is_dir(__DIR__ . '/geo')) {
                    mkdir(__DIR__ . '/geo', 0755, true);
                }

                try {
                    $ref = new \ReflectionClass('\PharData');
                    $p = $ref->newInstance($tmpArchive);

                    $extracted = false;
                    foreach (new RecursiveIteratorIterator($p) as $file) {
                        if ($file->getFilename() === $dbFileName) {
                            $tmpDestPath = $destPath . '.tmp-' . bin2hex(random_bytes(4));
                            if (copy($file->getPathname(), $tmpDestPath) && filesize($tmpDestPath) > 1024) {
                                chmod($tmpDestPath, 0644);
                                $extracted = rename($tmpDestPath, $destPath);
                            }
                            if (file_exists($tmpDestPath)) {
                                @unlink($tmpDestPath);
                            }
                            break;
                        }
                    }
                    if ($extracted) {
                        logSystem($pdo, 'INFO', "MaxMind {$editionId} DB updated successfully");
                        echo json_encode(['status' => 'success', 'message' => "База MaxMind {$editionId} успешно обновлена"]);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => "Failed to find {$dbFileName} in downloaded archive"]);
                    }
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Extraction failed: ' . $e->getMessage()]);
                }
                @unlink($tmpArchive);
                break;
            }

            if ($dbId === 'sypex_city_lite') {
                try {
                    $geoDir = __DIR__ . '/var/geoip/SxGeoCity';
                    if (!is_dir($geoDir)) {
                        mkdir($geoDir, 0777, true);
                    }
                    if (!is_dir(__DIR__ . '/geo')) {
                        mkdir(__DIR__ . '/geo', 0777, true);
                    }

                    // 1. Download Database ZIP
                    $zipFile = $geoDir . '/SxGeoCity_utf8.zip';
                    $zipData = $downloadFile('https://sypexgeo.net/files/SxGeoCity_utf8.zip');
                    if (!$zipData) {
                        throw new \Exception("Не удалось скачать архив базы от Sypex. Проверьте подключение к интернету.");
                    }
                    file_put_contents($zipFile, $zipData);

                    // 2. Unzip Database and extract SxGeo.php if missing
                    $zip = new ZipArchive;
                    if ($zip->open($zipFile) === TRUE) {

                        // Распаковываем во временную папку для поиска .dat файла
                        $tempDir = sys_get_temp_dir() . '/sypex_extract_' . time();
                        mkdir($tempDir, 0755, true);
                        $zip->extractTo($tempDir);
                        $zip->close();
                        @unlink($zipFile);

                        // Рекурсивно ищем SxGeoCity.dat
                        $found = false;
                        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));
                        foreach ($iter as $file) {
                            if ($file->isFile() && $file->getFilename() === 'SxGeoCity.dat') {
                                copy($file->getPathname(), $geoDir . '/SxGeoCity.dat');
                                $found = true;
                                break;
                            }
                        }

                        // Извлечение SxGeo.php если нужно (ищем в том же архиве)
                        $parserPath = __DIR__ . '/core/SxGeo.php';
                        if (!file_exists($parserPath)) {
                            foreach ($iter as $file) {
                                if ($file->isFile() && $file->getFilename() === 'SxGeo.php') {
                                    if (!is_dir(__DIR__ . '/core'))
                                        mkdir(__DIR__ . '/core', 0755, true);
                                    copy($file->getPathname(), $parserPath);
                                    break;
                                }
                            }
                        }

                        // Очистка временной папки
                        orbitraRemoveDirectory($tempDir);

                        logSystem($pdo, 'INFO', 'Sypex Geo DB Updated successfully');
                        echo json_encode(['status' => 'success', 'message' => 'База Sypex успешно обновлена']);
                    } else {
                        throw new \Exception("Не удалось открыть скачанный архив.");
                    }
                } catch (\Exception $e) {
                    error_log("Sypex Geo Update Error: " . $e->getMessage());
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
                break;
            }

            // Fallback (should be unreachable given validation)
            echo json_encode(['status' => 'error', 'message' => 'Возникла неизвестная ошибка при обновлении: ' . $dbId]);
            break;

        // === USERS API ===
        case 'logout':
            session_destroy();
            echo json_encode(['status' => 'success']);
            break;

        case 'users':
            $stmt = $pdo->query("
                SELECT id, username, email, role, language, permissions_json, is_active, last_login, created_at 
                FROM users 
                ORDER BY created_at DESC
            ");
            $users = $stmt->fetchAll();
            foreach ($users as &$u) {
                $u['permissions'] = !empty($u['permissions_json']) ? json_decode($u['permissions_json'], true) : [];
                unset($u['permissions_json']);
            }
            echo json_encode(['status' => 'success', 'data' => $users]);
            break;

        case 'get_user':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT id, username, email, role, permissions_json, is_active, last_login, created_at FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'User not found']);
                break;
            }
            $user['permissions'] = !empty($user['permissions_json']) ? json_decode($user['permissions_json'], true) : [];
            unset($user['permissions_json']);

            // Get API keys
            $stmtKeys = $pdo->prepare("SELECT id, key_name, api_key, permissions, last_used, created_at FROM user_api_keys WHERE user_id = ?");
            $stmtKeys->execute([$id]);
            $user['api_keys'] = $stmtKeys->fetchAll();

            echo json_encode(['status' => 'success', 'data' => $user]);
            break;

        case 'save_user':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $username = $data['username'] ?? '';
                $password = $data['password'] ?? '';
                $email = $data['email'] ?? '';
                $role = $data['role'] ?? 'user';
                $language = $data['language'] ?? 'en';
                $permissions = $data['permissions'] ?? [];
                $isActive = !empty($data['is_active']) ? 1 : 1;

                if (!$username) {
                    echo json_encode(['status' => 'error', 'message' => 'Username is required']);
                    break;
                }

                // If saving permissions, check that target user is not admin
                if ($id && !empty($permissions)) {
                    $stmtCheck = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $stmtCheck->execute([$id]);
                    $targetUser = $stmtCheck->fetch();
                    if ($targetUser && $targetUser['role'] === 'admin') {
                        // Admins cannot have their permissions edited by other admins
                        $permissions = []; // Ignore permissions for admin users
                    }
                }

                try {
                    if ($id) {
                        // Update existing user
                        if ($password) {
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, email=?, role=?, language=?, permissions_json=?, is_active=? WHERE id=?");
                            $stmt->execute([$username, $hashedPassword, $email, $role, $language, json_encode($permissions), $isActive, $id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=?, language=?, permissions_json=?, is_active=? WHERE id=?");
                            $stmt->execute([$username, $email, $role, $language, json_encode($permissions), $isActive, $id]);
                        }
                    } else {
                        // Create new user
                        if (!$password) {
                            echo json_encode(['status' => 'error', 'message' => 'Password is required for new user']);
                            break;
                        }
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, language, permissions_json, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $hashedPassword, $email, $role, $language, json_encode($permissions), $isActive]);
                        $id = $pdo->lastInsertId();
                    }
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                        echo json_encode(['status' => 'error', 'message' => 'Пользователь с таким логином уже существует']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                }
            }
            break;

        case 'delete_user':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                    break;
                }
                // Prevent deleting the first user (owner)
                if ((int) $id === 1) {
                    echo json_encode(['status' => 'error', 'message' => 'Невозможно удалить основного пользователя']);
                    break;
                }
                // Prevent deleting the last admin
                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $user = $stmt->fetch();
                if ($user && $user['role'] === 'admin') {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                    $adminCount = $stmt->fetchColumn();
                    if ($adminCount <= 1) {
                        echo json_encode(['status' => 'error', 'message' => 'Невозможно удалить последнего администратора']);
                        break;
                    }
                }
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                echo json_encode(['status' => 'success']);
            }
            break;

        case 'generate_api_key':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $userId = $data['user_id'] ?? null;
                $keyName = $data['key_name'] ?? 'API Key';
                // Permission scope: 'read' (default, GET only) or 'write' (read + management).
                $keyPermission = strtolower(trim((string) ($data['permissions'] ?? 'read')));
                if (!in_array($keyPermission, ['read', 'write'], true)) {
                    $keyPermission = 'read';
                }

                if (!$userId) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
                    break;
                }

                // Generate random API key
                $apiKey = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("INSERT INTO user_api_keys (user_id, key_name, api_key, permissions) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $keyName, $apiKey, $keyPermission]);

                echo json_encode(['status' => 'success', 'data' => ['api_key' => $apiKey, 'id' => $pdo->lastInsertId(), 'permissions' => $keyPermission]]);
            }
            break;

        case 'delete_api_key':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                    break;
                }
                $pdo->prepare("DELETE FROM user_api_keys WHERE id = ?")->execute([$id]);
                echo json_encode(['status' => 'success']);
            }
            break;

        case 'login':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                if (!checkRateLimit("login:$ip", 5, 300)) {
                    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Please try again later.']);
                    break;
                }

                $data = json_decode(orbitraRequestBody(), true);
                $username = $data['username'] ?? '';
                $password = $data['password'] ?? '';

                if (!$username || !$password) {
                    echo json_encode(['status' => 'error', 'message' => 'Username and password required']);
                    break;
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Start session & store user data
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    // Ensure CSRF token exists
                    if (!isset($_SESSION['csrf_token'])) {
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }

                    // Update last login
                    $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([$user['id']]);

                    echo json_encode([
                        'status' => 'success',
                        'data' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'language' => $user['language'] ?? 'en',
                            'timezone' => $user['timezone'] ?? 'Europe/Kyiv',
                            'permissions' => !empty($user['permissions_json']) ? json_decode($user['permissions_json'], true) : [],
                            'csrf_token' => $_SESSION['csrf_token']
                        ]
                    ]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Неверный логин или пароль']);
                }
            }
            break;

        case 'check_setup':
            // Check if setup is needed (no users exist)
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $userCount = $stmt->fetchColumn();
            echo json_encode([
                'status' => 'success',
                'needs_setup' => $userCount == 0,
                'user_count' => (int) $userCount
            ]);
            break;

        case 'setup_first_user':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);

                // Check if users already exist
                $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                if ($stmt->fetchColumn() > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Пользователи уже существуют']);
                    break;
                }

                $username = trim($data['username'] ?? '');
                $password = $data['password'] ?? '';
                $timezone = $data['timezone'] ?? 'Europe/Kyiv';
                $language = $data['language'] ?? 'en';

                // Validation
                if (strlen($username) < 3) {
                    echo json_encode(['status' => 'error', 'message' => 'Логин должен быть минимум 3 символа']);
                    break;
                }
                if (strlen($password) < 6) {
                    echo json_encode(['status' => 'error', 'message' => 'Пароль должен быть минимум 6 символов']);
                    break;
                }

                // Validate timezone
                try {
                    new DateTimeZone($timezone);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => "Неверный часовой пояс: $timezone"]);
                    break;
                }

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, is_active, timezone, language, permissions_json) VALUES (?, ?, 'admin', 1, ?, ?, ?)");
                $stmt->execute([
                    $username,
                    $hashedPassword,
                    $timezone,
                    $language,
                    json_encode(['can_delete_offers' => true, 'can_delete_campaigns' => true, 'can_manage_users' => true])
                ]);

                echo json_encode(['status' => 'success', 'message' => 'Пользователь создан']);
            }
            break;

        case 'init_admin':
            // Legacy endpoint - redirect to check_setup logic
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            if ($stmt->fetchColumn() == 0) {
                $hashedPassword = password_hash('admin', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, is_active, timezone, language) VALUES ('admin', ?, 'admin@localhost', 'admin', 1, 'Europe/Moscow', 'en')");
                $stmt->execute([$hashedPassword]);
                echo json_encode(['status' => 'success', 'message' => 'Admin user created']);
            } else {
                echo json_encode(['status' => 'success', 'message' => 'Users already exist']);
            }
            break;

        // === GEO PROFILES API ===
        case 'geo_profiles':
            $stmt = $pdo->query("SELECT * FROM geo_profiles ORDER BY name ASC");
            $profiles = $stmt->fetchAll();
            foreach ($profiles as &$p) {
                $p['countries'] = !empty($p['countries']) ? json_decode($p['countries'], true) : [];
            }
            echo json_encode(['status' => 'success', 'data' => $profiles]);
            break;

        case 'get_geo_profile':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM geo_profiles WHERE id = ?");
            $stmt->execute([$id]);
            $profile = $stmt->fetch();
            if (!$profile) {
                echo json_encode(['status' => 'error', 'message' => 'Profile not found']);
                break;
            }
            $profile['countries'] = !empty($profile['countries']) ? json_decode($profile['countries'], true) : [];
            echo json_encode(['status' => 'success', 'data' => $profile]);
            break;

        case 'save_geo_profile':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $countries = $data['countries'] ?? [];

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                    break;
                }

                try {
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE geo_profiles SET name=?, countries=? WHERE id=?");
                        $stmt->execute([$name, json_encode($countries), $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO geo_profiles (name, countries) VALUES (?, ?)");
                        $stmt->execute([$name, json_encode($countries)]);
                        $id = $pdo->lastInsertId();
                    }
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'delete_geo_profile':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
                    break;
                }
                $pdo->prepare("DELETE FROM geo_profiles WHERE id = ?")->execute([$id]);
                echo json_encode(['status' => 'success']);
            }
            break;

        case 'init_geo_templates':
            // Insert default geo profile templates if not exist
            $templates = [
                ['USA and Canada', ['US', 'CA']],
                ['West Europe', ['GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY']],
                ['Europe', ['AL', 'GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY', 'BY', 'BA', 'BG', 'HR', 'CZ', 'EE', 'HU', 'LV', 'LT', 'MK', 'MD', 'ME', 'PL', 'RO', 'RS', 'SK', 'SI']],
                ['exUSSR', ['AM', 'AZ', 'BY', 'EE', 'GE', 'KZ', 'KG', 'LV', 'LT', 'MD', 'RU', 'TJ', 'TM', 'UA', 'UZ']],
                ['English-Speaking', ['US', 'GB', 'CA', 'AU', 'NZ', 'IE', 'ZA', 'SG', 'JM', 'TT', 'GY', 'BB']],
                ['German-Speaking', ['AT', 'CH', 'LI', 'LU', 'DE']],
                ['French-Speaking', ['FR', 'MC', 'LU', 'CD', 'MG', 'CI', 'CM', 'BF', 'NE', 'SN', 'ML', 'BE']],
                ['Portuguese-Speaking', ['AO', 'BR', 'PT', 'CV', 'GW', 'MZ', 'ST', 'GQ', 'MU']],
                ['Spanish-Speaking', ['CO', 'ES', 'AR', 'MX', 'VE', 'PE', 'CL', 'EC', 'GT', 'CU', 'DO', 'HN', 'BO', 'SV', 'NI', 'PY', 'CR', 'UY', 'PA', 'GQ']],
                ['Italian-Speaking', ['IT', 'CH', 'SM', 'VA', 'MT', 'HR', 'SI']],
                ['North America', ['AI', 'AG', 'AW', 'BS', 'BB', 'BZ', 'BM', 'VI', 'CA', 'KY', 'CR', 'CU', 'DO', 'SV', 'GL', 'GD', 'GP', 'GT', 'HT', 'HN', 'JM', 'MQ', 'MX', 'MS', 'NL', 'NI', 'PA', 'PR', 'KN', 'LC', 'PM', 'VC', 'TT', 'TC', 'US']],
                ['USA, Canada and Europe', array_merge(['US', 'CA'], ['AL', 'GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY', 'BY', 'BA', 'BG', 'HR', 'CZ', 'EE', 'HU', 'LV', 'LT', 'MK', 'MD', 'ME', 'PL', 'RO', 'RS', 'SK', 'SI'])],
                ['English-Speaking and West Europe', array_merge(['US', 'GB', 'CA', 'AU', 'NZ', 'IE', 'ZA', 'SG', 'JM', 'TT', 'GY', 'BB'], ['DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY'])],
                ['English-Speaking and Europe', array_merge(['US', 'GB', 'CA', 'AU', 'NZ', 'IE', 'ZA', 'SG', 'JM', 'TT', 'GY', 'BB'], ['AL', 'GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY', 'BY', 'BA', 'BG', 'HR', 'CZ', 'EE', 'HU', 'LV', 'LT', 'MK', 'MD', 'ME', 'PL', 'RO', 'RS', 'SK', 'SI'])],
            ];

            $stmt = $pdo->prepare("INSERT OR IGNORE INTO geo_profiles (name, countries, is_template) VALUES (?, ?, 1)");
            $count = 0;
            foreach ($templates as $t) {
                $stmt->execute([$t[0], json_encode($t[1])]);
                $count++;
            }
            echo json_encode(['status' => 'success', 'message' => "Initialized $count geo profile templates"]);
            break;

        case 'countries_list':
            // Return list of all countries with codes
            $countries = [
                ['code' => 'AF', 'name' => 'Афганистан'],
                ['code' => 'AL', 'name' => 'Албания'],
                ['code' => 'DZ', 'name' => 'Алжир'],
                ['code' => 'AD', 'name' => 'Андорра'],
                ['code' => 'AO', 'name' => 'Ангола'],
                ['code' => 'AG', 'name' => 'Антигуа и Барбуда'],
                ['code' => 'AR', 'name' => 'Аргентина'],
                ['code' => 'AM', 'name' => 'Армения'],
                ['code' => 'AU', 'name' => 'Австралия'],
                ['code' => 'AT', 'name' => 'Австрия'],
                ['code' => 'AZ', 'name' => 'Азербайджан'],
                ['code' => 'BS', 'name' => 'Багамские острова'],
                ['code' => 'BH', 'name' => 'Бахрейн'],
                ['code' => 'BD', 'name' => 'Бангладеш'],
                ['code' => 'BB', 'name' => 'Барбадос'],
                ['code' => 'BY', 'name' => 'Беларусь'],
                ['code' => 'BE', 'name' => 'Бельгия'],
                ['code' => 'BZ', 'name' => 'Белиз'],
                ['code' => 'BJ', 'name' => 'Бенин'],
                ['code' => 'BT', 'name' => 'Бутан'],
                ['code' => 'BO', 'name' => 'Боливия'],
                ['code' => 'BA', 'name' => 'Босния и Герцеговина'],
                ['code' => 'BW', 'name' => 'Ботсвана'],
                ['code' => 'BR', 'name' => 'Бразилия'],
                ['code' => 'BN', 'name' => 'Бруней'],
                ['code' => 'BG', 'name' => 'Болгария'],
                ['code' => 'BF', 'name' => 'Буркина-Фасо'],
                ['code' => 'BI', 'name' => 'Бурунди'],
                ['code' => 'KH', 'name' => 'Камбоджа'],
                ['code' => 'CM', 'name' => 'Камерун'],
                ['code' => 'CA', 'name' => 'Канада'],
                ['code' => 'CV', 'name' => 'Кабо-Верде'],
                ['code' => 'CF', 'name' => 'Центральноафриканская Республика'],
                ['code' => 'TD', 'name' => 'Чад'],
                ['code' => 'CL', 'name' => 'Чили'],
                ['code' => 'CN', 'name' => 'Китай'],
                ['code' => 'CO', 'name' => 'Колумбия'],
                ['code' => 'KM', 'name' => 'Коморы'],
                ['code' => 'CG', 'name' => 'Конго'],
                ['code' => 'CD', 'name' => 'Демократическая Республика Конго'],
                ['code' => 'CR', 'name' => 'Коста-Рика'],
                ['code' => 'CI', 'name' => 'Кот-д\'Ивуар'],
                ['code' => 'HR', 'name' => 'Хорватия'],
                ['code' => 'CU', 'name' => 'Куба'],
                ['code' => 'CY', 'name' => 'Кипр'],
                ['code' => 'CZ', 'name' => 'Чехия'],
                ['code' => 'DK', 'name' => 'Дания'],
                ['code' => 'DJ', 'name' => 'Джибути'],
                ['code' => 'DM', 'name' => 'Доминика'],
                ['code' => 'DO', 'name' => 'Доминиканская Республика'],
                ['code' => 'EC', 'name' => 'Эквадор'],
                ['code' => 'EG', 'name' => 'Египет'],
                ['code' => 'SV', 'name' => 'Сальвадор'],
                ['code' => 'GQ', 'name' => 'Экваториальная Гвинея'],
                ['code' => 'ER', 'name' => 'Эритрея'],
                ['code' => 'EE', 'name' => 'Эстония'],
                ['code' => 'ET', 'name' => 'Эфиопия'],
                ['code' => 'FJ', 'name' => 'Фиджи'],
                ['code' => 'FI', 'name' => 'Финляндия'],
                ['code' => 'FR', 'name' => 'Франция'],
                ['code' => 'GA', 'name' => 'Габон'],
                ['code' => 'GM', 'name' => 'Гамбия'],
                ['code' => 'GE', 'name' => 'Грузия'],
                ['code' => 'DE', 'name' => 'Германия'],
                ['code' => 'GH', 'name' => 'Гана'],
                ['code' => 'GR', 'name' => 'Греция'],
                ['code' => 'GD', 'name' => 'Гренада'],
                ['code' => 'GT', 'name' => 'Гватемала'],
                ['code' => 'GN', 'name' => 'Гвинея'],
                ['code' => 'GW', 'name' => 'Гвинея-Бисау'],
                ['code' => 'GY', 'name' => 'Гайана'],
                ['code' => 'HT', 'name' => 'Гаити'],
                ['code' => 'HN', 'name' => 'Гондурас'],
                ['code' => 'HU', 'name' => 'Венгрия'],
                ['code' => 'IS', 'name' => 'Исландия'],
                ['code' => 'IN', 'name' => 'Индия'],
                ['code' => 'ID', 'name' => 'Индонезия'],
                ['code' => 'IR', 'name' => 'Иран'],
                ['code' => 'IQ', 'name' => 'Ирак'],
                ['code' => 'IE', 'name' => 'Ирландия'],
                ['code' => 'IL', 'name' => 'Израиль'],
                ['code' => 'IT', 'name' => 'Италия'],
                ['code' => 'JM', 'name' => 'Ямайка'],
                ['code' => 'JP', 'name' => 'Япония'],
                ['code' => 'JO', 'name' => 'Иордания'],
                ['code' => 'KZ', 'name' => 'Казахстан'],
                ['code' => 'KE', 'name' => 'Кения'],
                ['code' => 'KI', 'name' => 'Кирибати'],
                ['code' => 'KP', 'name' => 'Северная Корея'],
                ['code' => 'KR', 'name' => 'Южная Корея'],
                ['code' => 'KW', 'name' => 'Кувейт'],
                ['code' => 'KG', 'name' => 'Киргизия'],
                ['code' => 'LA', 'name' => 'Лаос'],
                ['code' => 'LV', 'name' => 'Латвия'],
                ['code' => 'LB', 'name' => 'Ливан'],
                ['code' => 'LS', 'name' => 'Лесото'],
                ['code' => 'LR', 'name' => 'Либерия'],
                ['code' => 'LY', 'name' => 'Ливия'],
                ['code' => 'LI', 'name' => 'Лихтенштейн'],
                ['code' => 'LT', 'name' => 'Литва'],
                ['code' => 'LU', 'name' => 'Люксембург'],
                ['code' => 'MK', 'name' => 'Македония'],
                ['code' => 'MG', 'name' => 'Мадагаскар'],
                ['code' => 'MW', 'name' => 'Малави'],
                ['code' => 'MY', 'name' => 'Малайзия'],
                ['code' => 'MV', 'name' => 'Мальдивы'],
                ['code' => 'ML', 'name' => 'Мали'],
                ['code' => 'MT', 'name' => 'Мальта'],
                ['code' => 'MH', 'name' => 'Маршалловы острова'],
                ['code' => 'MR', 'name' => 'Мавритания'],
                ['code' => 'MU', 'name' => 'Маврикий'],
                ['code' => 'MX', 'name' => 'Мексика'],
                ['code' => 'FM', 'name' => 'Микронезия'],
                ['code' => 'MD', 'name' => 'Молдова'],
                ['code' => 'MC', 'name' => 'Монако'],
                ['code' => 'MN', 'name' => 'Монголия'],
                ['code' => 'ME', 'name' => 'Черногория'],
                ['code' => 'MA', 'name' => 'Марокко'],
                ['code' => 'MZ', 'name' => 'Мозамбик'],
                ['code' => 'MM', 'name' => 'Мьянма'],
                ['code' => 'NA', 'name' => 'Намибия'],
                ['code' => 'NR', 'name' => 'Науру'],
                ['code' => 'NP', 'name' => 'Непал'],
                ['code' => 'NL', 'name' => 'Нидерланды'],
                ['code' => 'NZ', 'name' => 'Новая Зеландия'],
                ['code' => 'NI', 'name' => 'Никарагуа'],
                ['code' => 'NE', 'name' => 'Нигер'],
                ['code' => 'NG', 'name' => 'Нигерия'],
                ['code' => 'NO', 'name' => 'Норвегия'],
                ['code' => 'OM', 'name' => 'Оман'],
                ['code' => 'PK', 'name' => 'Пакистан'],
                ['code' => 'PW', 'name' => 'Палау'],
                ['code' => 'PA', 'name' => 'Панама'],
                ['code' => 'PG', 'name' => 'Папуа-Новая Гвинея'],
                ['code' => 'PY', 'name' => 'Парагвай'],
                ['code' => 'PE', 'name' => 'Перу'],
                ['code' => 'PH', 'name' => 'Филиппины'],
                ['code' => 'PL', 'name' => 'Польша'],
                ['code' => 'PT', 'name' => 'Португалия'],
                ['code' => 'QA', 'name' => 'Катар'],
                ['code' => 'RO', 'name' => 'Румыния'],
                ['code' => 'RU', 'name' => 'Россия'],
                ['code' => 'RW', 'name' => 'Руанда'],
                ['code' => 'KN', 'name' => 'Сент-Китс и Невис'],
                ['code' => 'LC', 'name' => 'Сент-Люсия'],
                ['code' => 'VC', 'name' => 'Сент-Винсент и Гренадины'],
                ['code' => 'WS', 'name' => 'Самоа'],
                ['code' => 'SM', 'name' => 'Сан-Марино'],
                ['code' => 'ST', 'name' => 'Сан-Томе и Принсипи'],
                ['code' => 'SA', 'name' => 'Саудовская Аравия'],
                ['code' => 'SN', 'name' => 'Сенегал'],
                ['code' => 'RS', 'name' => 'Сербия'],
                ['code' => 'SC', 'name' => 'Сейшелы'],
                ['code' => 'SL', 'name' => 'Сьерра-Леоне'],
                ['code' => 'SG', 'name' => 'Сингапур'],
                ['code' => 'SK', 'name' => 'Словакия'],
                ['code' => 'SI', 'name' => 'Словения'],
                ['code' => 'SB', 'name' => 'Соломоновы острова'],
                ['code' => 'SO', 'name' => 'Сомали'],
                ['code' => 'ZA', 'name' => 'ЮАР'],
                ['code' => 'SS', 'name' => 'Южный Судан'],
                ['code' => 'ES', 'name' => 'Испания'],
                ['code' => 'LK', 'name' => 'Шри-Ланка'],
                ['code' => 'SD', 'name' => 'Судан'],
                ['code' => 'SR', 'name' => 'Суринам'],
                ['code' => 'SZ', 'name' => 'Эсватини'],
                ['code' => 'SE', 'name' => 'Швеция'],
                ['code' => 'CH', 'name' => 'Швейцария'],
                ['code' => 'SY', 'name' => 'Сирия'],
                ['code' => 'TW', 'name' => 'Тайвань'],
                ['code' => 'TJ', 'name' => 'Таджикистан'],
                ['code' => 'TZ', 'name' => 'Танзания'],
                ['code' => 'TH', 'name' => 'Таиланд'],
                ['code' => 'TL', 'name' => 'Восточный Тимор'],
                ['code' => 'TG', 'name' => 'Того'],
                ['code' => 'TO', 'name' => 'Тонга'],
                ['code' => 'TT', 'name' => 'Тринидад и Тобаго'],
                ['code' => 'TN', 'name' => 'Тунис'],
                ['code' => 'TR', 'name' => 'Турция'],
                ['code' => 'TM', 'name' => 'Туркменистан'],
                ['code' => 'TV', 'name' => 'Тувалу'],
                ['code' => 'UG', 'name' => 'Уганда'],
                ['code' => 'UA', 'name' => 'Украина'],
                ['code' => 'AE', 'name' => 'ОАЭ'],
                ['code' => 'GB', 'name' => 'Великобритания'],
                ['code' => 'US', 'name' => 'США'],
                ['code' => 'UY', 'name' => 'Уругвай'],
                ['code' => 'UZ', 'name' => 'Узбекистан'],
                ['code' => 'VU', 'name' => 'Вануату'],
                ['code' => 'VA', 'name' => 'Ватикан'],
                ['code' => 'VE', 'name' => 'Венесуэла'],
                ['code' => 'VN', 'name' => 'Вьетнам'],
                ['code' => 'YE', 'name' => 'Йемен'],
                ['code' => 'ZM', 'name' => 'Замбия'],
                ['code' => 'ZW', 'name' => 'Зимбабве'],
            ];
            echo json_encode(['status' => 'success', 'data' => $countries]);
            break;

        case 'conversion_types':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                if (isset($data['action']) && $data['action'] === 'delete') {
                    $pdo->prepare("DELETE FROM conversion_types WHERE id = ?")->execute([$data['id']]);
                    echo json_encode(['status' => 'success']);
                } else {
                    $id = $data['id'] ?? null;
                    $name = $data['name'] ?? '';
                    $status_values = $data['status_values'] ?? '';
                    $next_statuses = $data['next_statuses'] ?? '';
                    $record_con = isset($data['record_conversion']) ? (int) $data['record_conversion'] : 1;
                    $record_rev = isset($data['record_revenue']) ? (int) $data['record_revenue'] : 1;
                    $send_pb = isset($data['send_postback']) ? (int) $data['send_postback'] : 1;
                    $affect_cap = isset($data['affect_cap']) ? (int) $data['affect_cap'] : 1;

                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE conversion_types SET name=?, status_values=?, next_statuses=?, record_conversion=?, record_revenue=?, send_postback=?, affect_cap=? WHERE id=?");
                        $stmt->execute([$name, $status_values, $next_statuses, $record_con, $record_rev, $send_pb, $affect_cap, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO conversion_types (name, status_values, next_statuses, record_conversion, record_revenue, send_postback, affect_cap) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $status_values, $next_statuses, $record_con, $record_rev, $send_pb, $affect_cap]);
                    }
                    echo json_encode(['status' => 'success']);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM conversion_types ORDER BY id ASC");
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            }
            break;

        case 'custom_metrics':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                if (isset($data['action']) && $data['action'] === 'delete') {
                    $pdo->prepare("DELETE FROM custom_metrics WHERE id = ?")->execute([$data['id']]);
                    echo json_encode(['status' => 'success']);
                } else {
                    $id = $data['id'] ?? null;
                    $name = $data['name'] ?? '';
                    $formula = $data['formula'] ?? '';
                    $format = $data['format'] ?? 'number';
                    $decimals = isset($data['decimals']) ? (int) $data['decimals'] : 2;

                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE custom_metrics SET name=?, formula=?, format=?, decimals=? WHERE id=?");
                        $stmt->execute([$name, $formula, $format, $decimals, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO custom_metrics (name, formula, format, decimals) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$name, $formula, $format, $decimals]);
                    }
                    echo json_encode(['status' => 'success']);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM custom_metrics ORDER BY id ASC");
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            }
            break;

        case 'bot_ips':
            orbitraBotListEndpoint($pdo, 'bot_ips', 'ip_or_cidr', 'ips');
            break;

        case 'bot_signatures':
            orbitraBotListEndpoint($pdo, 'bot_signatures', 'signature', 'signatures');
            break;

        case 'profile_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $userId = $data['user_id'] ?? 1; // Defaulting to 1 for MVP single-user setup
                $lang = $data['language'] ?? 'en';
                $tz = $data['timezone'] ?? 'Europe/Moscow';
                $firstDay = $data['first_day_of_week'] ?? 1;

                // Validate timezone
                try {
                    new DateTimeZone($tz);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => "Неверный часовой пояс: $tz"]);
                    break;
                }

                $pdo->prepare("UPDATE users SET language=?, timezone=?, first_day_of_week=? WHERE id=?")->execute([$lang, $tz, $firstDay, $userId]);

                if (!empty($data['new_password'])) {
                    $pwd = password_hash($data['new_password'], PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$pwd, $userId]);
                }
                echo json_encode(['status' => 'success']);
            } else {
                $userId = $_GET['user_id'] ?? 1;
                $stmt = $pdo->prepare("SELECT id, username, email, language, timezone, first_day_of_week FROM users WHERE id=?");
                $stmt->execute([$userId]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetch()]);
            }
            break;

        case 'archive_items':
            $items = [
                'campaigns' => $pdo->query("SELECT id, name, created_at, archived_at FROM campaigns WHERE is_archived = 1")->fetchAll(),
                'offers' => $pdo->query("SELECT id, name, created_at, archived_at FROM offers WHERE is_archived = 1")->fetchAll(),
                'landings' => $pdo->query("SELECT id, name, created_at, archived_at FROM landings WHERE is_archived = 1")->fetchAll(),
                'traffic_sources' => $pdo->query("SELECT id, name, created_at, archived_at FROM traffic_sources WHERE is_archived = 1")->fetchAll(),
                'affiliate_networks' => $pdo->query("SELECT id, name, created_at, archived_at FROM affiliate_networks WHERE is_archived = 1")->fetchAll(),
            ];
            echo json_encode(['status' => 'success', 'data' => $items]);
            break;

        case 'archive_restore':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $type = $data['type'] ?? '';
                $id = $data['id'] ?? null;
                $allowed = ['campaigns', 'offers', 'landings', 'traffic_sources', 'affiliate_networks'];

                if (in_array($type, $allowed) && $id) {
                    $pdo->prepare("UPDATE $type SET is_archived = 0, archived_at = NULL WHERE id = ?")->execute([$id]);
                    logAudit($pdo, 'RESTORE', $type, $id);
                    echo json_encode(['status' => 'success']);
                } else if ($type && $data['action'] === 'restore_all' && in_array($type, $allowed)) {
                    $pdo->exec("UPDATE $type SET is_archived = 0, archived_at = NULL WHERE is_archived = 1");
                    logAudit($pdo, 'RESTORE_ALL', $type);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
                }
            }
            break;

        case 'archive_purge':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $type = $data['type'] ?? '';
                $id = $data['id'] ?? null;
                $action = $data['action'] ?? '';
                $allowed = ['campaigns', 'offers', 'landings', 'traffic_sources', 'affiliate_networks'];

                if ($action === 'purge_all') {
                    foreach ($allowed as $tbl) {
                        $pdo->exec("DELETE FROM $tbl WHERE is_archived = 1");
                    }
                    logAudit($pdo, 'PURGE_ALL', 'Archive');
                    echo json_encode(['status' => 'success']);
                } else if ($action === 'purge_section' && in_array($type, $allowed)) {
                    $pdo->exec("DELETE FROM $type WHERE is_archived = 1");
                    logAudit($pdo, 'PURGE_SECTION', $type);
                    echo json_encode(['status' => 'success']);
                } else if (in_array($type, $allowed) && $id) {
                    $pdo->prepare("DELETE FROM $type WHERE id = ? AND is_archived = 1")->execute([$id]);
                    logAudit($pdo, 'PURGE_ITEM', $type, $id);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
                }
            }
            break;

        case 'import_conversions':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $csv = $data['csv_data'] ?? '';

                if (empty(trim($csv))) {
                    echo json_encode(['status' => 'error', 'message' => 'Пустые данные']);
                    break;
                }

                $stmt = $pdo->query("SELECT name, status_values, record_conversion, record_revenue FROM conversion_types");
                $ct = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $db_types = [];
                $convStatuses = ['sale', 'deposit', 'lead'];
                $revStatuses = ['sale', 'deposit', 'lead', 'registration'];

                foreach ($ct as $row) {
                    $db_types[$row['name']] = array_map('trim', explode(',', $row['status_values']));
                    if ($row['record_conversion'])
                        $convStatuses[] = $row['name'];
                    if ($row['record_revenue'])
                        $revStatuses[] = $row['name'];
                }

                $known_types = ['lead', 'sale', 'rejected', 'registration', 'deposit', 'trash'];
                $all_known = array_merge($known_types, array_keys($db_types));

                $lines = explode("\n", str_replace("\r", "", trim($csv)));
                $successCount = 0;
                $errors = [];

                $clickStmt = $pdo->prepare("SELECT id FROM clicks WHERE id = ?");
                $findTidNullStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL");

                $insertStmt = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, original_status, payout, currency) VALUES (?, ?, ?, ?, ?, ?)");
                $updateTidStmt = $pdo->prepare("UPDATE conversions SET status = ?, original_status = ?, payout = ?, currency = ? WHERE click_id = ? AND tid = ?");
                $updateNoTidStmt = $pdo->prepare("UPDATE conversions SET status = ?, original_status = ?, payout = ?, currency = ? WHERE id = ?");

                $inConv = "'" . implode("','", array_map('addslashes', $convStatuses)) . "'";
                $inRev = "'" . implode("','", array_map('addslashes', $revStatuses)) . "'";
                $totalStatsStmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN status IN ($inConv) THEN 1 ELSE 0 END) as is_conv,
                        SUM(CASE WHEN status IN ($inRev) AND payout > 0 THEN payout ELSE 0 END) as total_rev
                    FROM conversions WHERE click_id = ?
                ");
                $clicksUpdateStmt = $pdo->prepare("UPDATE clicks SET is_conversion = ?, revenue = ? WHERE id = ?");

                $pdo->beginTransaction();

                foreach ($lines as $index => $line) {
                    $line = trim($line);
                    if (empty($line))
                        continue;

                    $parts = array_map('trim', str_getcsv($line));
                    if (count($parts) < 2) {
                        $errors[] = "Строка " . ($index + 1) . ": Неверный формат";
                        continue;
                    }

                    $subid = $parts[0];
                    $payout = (float) $parts[1];
                    $tid = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
                    $status = $parts[3] ?? 'lead';

                    $clickStmt->execute([$subid]);
                    if (!$clickStmt->fetch()) {
                        $errors[] = "Строка " . ($index + 1) . ": subid не найден ($subid)";
                        continue;
                    }

                    // Map status
                    $internalStatus = in_array($status, $all_known) ? $status : 'custom';
                    foreach ($db_types as $typeName => $values) {
                        if (in_array($status, $values)) {
                            $internalStatus = $typeName;
                            break;
                        }
                    }

                    if ($internalStatus === 'custom' && !in_array($status, $all_known)) {
                        $errors[] = "Строка " . ($index + 1) . ": Неизвестный статус ($status)";
                        continue;
                    }

                    if ($tid) {
                        // Check if exists
                        $checkTid = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid = ?");
                        $checkTid->execute([$subid, $tid]);
                        if ($checkTid->fetch()) {
                            $updateTidStmt->execute([$internalStatus, $status, $payout, 'USD', $subid, $tid]);
                        } else {
                            $insertStmt->execute([$subid, $tid, $internalStatus, $status, $payout, 'USD']);
                        }
                    } else {
                        $findTidNullStmt->execute([$subid]);
                        $existing = $findTidNullStmt->fetch();
                        if ($existing) {
                            $updateNoTidStmt->execute([$internalStatus, $status, $payout, 'USD', $existing['id']]);
                        } else {
                            $insertStmt->execute([$subid, null, $internalStatus, $status, $payout, 'USD']);
                        }
                    }

                    // Recalculate click stats
                    $totalStatsStmt->execute([$subid]);
                    $stats = $totalStatsStmt->fetch();
                    $isConv = ($stats['is_conv'] > 0) ? 1 : 0;
                    $totalRev = $stats['total_rev'] ?: 0.00;
                    $clicksUpdateStmt->execute([$isConv, $totalRev, $subid]);

                    $successCount++;
                }

                $pdo->commit();
                logAudit($pdo, 'IMPORT', 'Conversions', null, ['imported' => $successCount]);

                echo json_encode([
                    'status' => 'success',
                    'message' => "Изменено $successCount конверсий.",
                    'errors' => $errors
                ]);
            }
            break;

        // === MIGRATIONS API ===
        case 'migrations':
            $availableMigrations = [
                1 => ['version' => 1, 'description_key' => 'v1', 'sql' => "SELECT 1;"],
                2 => ['version' => 2, 'description_key' => 'v2', 'sql' => "SELECT 1;"],
                3 => ['version' => 3, 'description_key' => 'v3', 'sql' => "SELECT 1;"],
                4 => ['version' => 4, 'description_key' => 'v4', 'sql' => "SELECT 1;"],
                5 => ['version' => 5, 'description_key' => 'v5', 'sql' => "INSERT OR IGNORE INTO settings (key, value) VALUES ('archive_retention_days', '60');"],
                6 => ['version' => 6, 'description_key' => 'v6', 'sql' => "CREATE TABLE IF NOT EXISTS user_preferences (user_id INTEGER PRIMARY KEY, theme TEXT DEFAULT 'light');"],
                7 => ['version' => 7, 'description_key' => 'v7', 'sql' => "CREATE INDEX IF NOT EXISTS idx_clicks_date ON clicks(created_at);"],
                8 => ['version' => 8, 'description_key' => 'v8', 'sql' => "INSERT OR IGNORE INTO settings (key, value) VALUES ('session_lifetime', '86400');"]
            ];

            // Get executed migrations
            $stmt = $pdo->query("SELECT version, status, executed_at FROM schema_migrations");
            $executed = [];
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $executed[$row['version']] = $row;
                }
            }

            $result = [];
            foreach ($availableMigrations as $v => $m) {
                if (isset($executed[$v])) {
                    $m['status'] = $executed[$v]['status'];
                    $m['executed_at'] = $executed[$v]['executed_at'];
                } else {
                    $m['status'] = 'pending';
                    $m['executed_at'] = null;
                }
                $result[] = $m;
            }

            // Descending order so newer migrations are on top (like Keitaro)
            usort($result, function ($a, $b) {
                return $b['version'] <=> $a['version'];
            });

            echo json_encode(['status' => 'success', 'data' => $result]);
            break;

        case 'keitaro_import_sql':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            if (empty($_FILES) || !isset($_FILES['sql_file'])) {
                echo json_encode(['status' => 'error', 'message' => 'No file uploaded (sql_file)']);
                break;
            }
            $f = $_FILES['sql_file'];
            if (!is_array($f) || !isset($f['tmp_name'])) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid upload']);
                break;
            }
            if (!empty($f['error'])) {
                echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . (int) $f['error']]);
                break;
            }
            $tmp = (string) $f['tmp_name'];
            if ($tmp === '' || !is_file($tmp)) {
                echo json_encode(['status' => 'error', 'message' => 'Upload temp file not found']);
                break;
            }

            $dryRun = isset($_POST['dry_run']) && (string) $_POST['dry_run'] === '1';
            $importDomains = !isset($_POST['import_domains']) || (string) $_POST['import_domains'] === '1';
            $importOffers = !isset($_POST['import_offers']) || (string) $_POST['import_offers'] === '1';
            $importCompanies = !isset($_POST['import_companies']) || (string) $_POST['import_companies'] === '1';
            $importTrafficSources = isset($_POST['import_traffic_sources']) && (string) $_POST['import_traffic_sources'] === '1';
            $importLandings = isset($_POST['import_landings']) && (string) $_POST['import_landings'] === '1';
            $importCampaigns = isset($_POST['import_campaigns']) && (string) $_POST['import_campaigns'] === '1';
            $importStreams = isset($_POST['import_streams']) && (string) $_POST['import_streams'] === '1';
            $importCampaignPostbacks = isset($_POST['import_campaign_postbacks']) && (string) $_POST['import_campaign_postbacks'] === '1';
            $preserveCampaignIds = isset($_POST['preserve_campaign_ids']) && (string) $_POST['preserve_campaign_ids'] === '1';

            try {
                $res = orbitraKeitaroImportSqlDump($pdo, $tmp, [
                    'dry_run' => $dryRun,
                    'import_domains' => $importDomains,
                    'import_offers' => $importOffers,
                    'import_companies' => $importCompanies,
                    'import_traffic_sources' => $importTrafficSources,
                    'import_landings' => $importLandings,
                    'import_campaigns' => $importCampaigns,
                    'import_streams' => $importStreams,
                    'import_campaign_postbacks' => $importCampaignPostbacks,
                    'preserve_campaign_ids' => $preserveCampaignIds,
                ]);
                echo json_encode(['status' => 'success', 'data' => $res]);
            } catch (Throwable $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'purge_metadata':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
                break;
            }
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                break;
            }

            // Safety guard: require explicit confirmation phrase from UI/user.
            $data = json_decode(orbitraRequestBody(), true);
            if (!is_array($data)) {
                $data = [];
            }
            $confirm = strtoupper(trim((string) ($data['confirm'] ?? '')));
            if ($confirm !== 'DELETE') {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Confirmation required. Send {"confirm":"DELETE"}'
                ]);
                break;
            }

            // Default: purge tracker "metadata" (configuration), not statistics.
            // This intentionally keeps: users, settings, clicks, conversions, logs.
            $purge = $data['purge'] ?? [];
            if (!is_array($purge) || empty($purge)) {
                $purge = [
                    'companies' => 1,
                    'offers' => 1,
                    'domains' => 1,
                    'campaigns' => 1,
                    'streams' => 1,
                    'campaign_postbacks' => 1,
                    'campaign_pixels' => 1,
                    'traffic_sources' => 1,
                    'landings' => 1,
                    'groups' => 1,
                ];
            }

            $tableExists = function (PDO $pdo, string $table): bool {
                $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
                $stmt->execute([$table]);
                return (bool) $stmt->fetchColumn();
            };

            $deleteAll = function (PDO $pdo, string $table) use ($tableExists): array {
                if (!$tableExists($pdo, $table)) {
                    return ['table' => $table, 'deleted' => 0, 'skipped' => 1, 'reason' => 'missing'];
                }
                $countStmt = $pdo->query("SELECT COUNT(*) FROM \"$table\"");
                $count = (int) ($countStmt ? $countStmt->fetchColumn() : 0);
                $pdo->exec("DELETE FROM \"$table\"");
                return ['table' => $table, 'deleted' => $count, 'skipped' => 0];
            };

            // Compute deletion plan (order matters if foreign_keys are off).
            $plan = [];
            if (!empty($purge['campaign_postbacks'])) $plan[] = 'campaign_postbacks';
            if (!empty($purge['campaign_pixels'])) $plan[] = 'campaign_pixels';
            if (!empty($purge['streams'])) $plan[] = 'streams';
            if (!empty($purge['campaigns'])) $plan[] = 'campaigns';
            if (!empty($purge['domains'])) $plan[] = 'domains';
            if (!empty($purge['offers'])) $plan[] = 'offers';
            if (!empty($purge['landings'])) $plan[] = 'landings';
            if (!empty($purge['traffic_sources'])) $plan[] = 'traffic_sources';
            if (!empty($purge['groups'])) {
                $plan[] = 'campaign_groups';
                $plan[] = 'offer_groups';
                $plan[] = 'landing_groups';
            }
            if (!empty($purge['companies'])) $plan[] = 'affiliate_networks';

            // Ensure uniqueness and keep order
            $seen = [];
            $tables = [];
            foreach ($plan as $t) {
                if (!isset($seen[$t])) {
                    $seen[$t] = true;
                    $tables[] = $t;
                }
            }

            try {
                $pdo->beginTransaction();
                // Best effort: enforce FK behavior in this connection.
                $pdo->exec("PRAGMA foreign_keys = ON");

                $results = [];
                foreach ($tables as $t) {
                    $results[] = $deleteAll($pdo, $t);
                }

                $pdo->commit();
                logAudit($pdo, 'DELETE', 'Purge metadata', null, ['tables' => $results]);

                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'purged' => $results,
                    ]
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        // === TRENDS API ===
        case 'trends':
            $groupBy = $_GET['group_by'] ?? 'day';
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            $metricsParam = $_GET['metrics'] ?? 'clicks,conversions,revenue';
            $filtersParam = $_GET['filters'] ?? '[]';

            $selectedMetrics = explode(',', $metricsParam);
            $filters = json_decode($filtersParam, true) ?? [];

            // Build WHERE clause from filters
            $whereClauses = ["cl.created_at >= ? AND cl.created_at <= ?"];
            $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

            foreach ($filters as $f) {
                $field = $f['field'] ?? '';
                $operator = $f['operator'] ?? 'contains';
                $value = $f['value'] ?? '';

                if (!$field || !$value)
                    continue;

                switch ($operator) {
                    case 'contains':
                        $whereClauses[] = "cl.$field LIKE ?";
                        $params[] = "%$value%";
                        break;
                    case 'not_contains':
                        $whereClauses[] = "cl.$field NOT LIKE ?";
                        $params[] = "%$value%";
                        break;
                    case 'equals':
                        $whereClauses[] = "cl.$field = ?";
                        $params[] = $value;
                        break;
                    case 'not_equals':
                        $whereClauses[] = "cl.$field != ?";
                        $params[] = $value;
                        break;
                    case 'starts_with':
                        $whereClauses[] = "cl.$field LIKE ?";
                        $params[] = "$value%";
                        break;
                    case 'ends_with':
                        $whereClauses[] = "cl.$field LIKE ?";
                        $params[] = "%$value";
                        break;
                }
            }

            $whereSQL = implode(' AND ', $whereClauses);

            // Determine grouping format
            $dateFormat = match ($groupBy) {
                'month' => '%Y-%m',
                'day_of_week' => '%w',
                'hour' => '%Y-%m-%d %H:00',
                default => '%Y-%m-%d'
            };

            // Day of week names for display
            $dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
            $conversionsValueColumn = getConversionsValueColumn($pdo);
            $trendRevenueExpression = "0";
            if ($conversionsValueColumn !== null) {
                $trendRevenueExpression = "(SELECT SUM($conversionsValueColumn) FROM conversions WHERE click_id = cl.id)";
            }
            $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);
            $trendRealRevenueExpression = "0";
            if ($revenueRecordsValueColumn !== null) {
                $trendRealRevenueExpression = "(SELECT SUM($revenueRecordsValueColumn) FROM revenue_records WHERE click_id = cl.id)";
            }

            // Get aggregated data
            $sql = "
                SELECT 
                    period,
                    COUNT(click_id) as clicks,
                    COUNT(DISTINCT click_ip) as unique_clicks,
                    SUM(is_conversion) as conversions,
                    COALESCE(SUM(click_revenue), 0) as revenue,
                    COALESCE(SUM(click_real_revenue), 0) as real_revenue,
                    COALESCE(SUM(cost), 0) as cost
                FROM (
                    SELECT strftime('$dateFormat', cl.created_at) as period,
                           cl.id as click_id,
                           cl.ip as click_ip,
                           cl.is_conversion,
                           cl.cost,
                           $trendRevenueExpression as click_revenue,
                           $trendRealRevenueExpression as click_real_revenue
                    FROM clicks cl
                    WHERE $whereSQL
                )
                GROUP BY period
                ORDER BY period ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Zero-fill the time axis for day/hour granularity so the chart
            // spans the full selected range instead of collapsing to only the
            // periods that had traffic (which makes a single data point look
            // "stuck" at the X-axis origin). Mirrors the dashboard chart logic.
            // month and day_of_week are unaffected — bucketing is not linear.
            $zeroFilled = [];
            if (in_array($groupBy, ['day', 'hour'], true)) {
                $filled = []; // period-key => raw row
                if ($groupBy === 'hour') {
                    // Single-day hourly: 00:00 .. 23:00 for the dateFrom day.
                    $baseDate = substr($dateFrom, 0, 10);
                    for ($h = 0; $h <= 23; $h++) {
                        $key = $baseDate . ' ' . str_pad($h, 2, '0', STR_PAD_LEFT) . ':00:00';
                        $filled[$key] = ['period' => $key, 'clicks' => 0, 'unique_clicks' => 0,
                            'conversions' => 0, 'revenue' => 0, 'real_revenue' => 0, 'cost' => 0];
                    }
                    foreach ($rows as $row) {
                        if (isset($filled[$row['period']])) { $filled[$row['period']] = $row; }
                    }
                } else {
                    // Multi-day: walk dateFrom..dateTo, one bucket per day.
                    $cursor = substr($dateFrom, 0, 10);
                    $endDay = substr($dateTo, 0, 10);
                    $guard = 0;
                    while (strcmp($cursor, $endDay) <= 0 && $guard < 800) {
                        $filled[$cursor] = ['period' => $cursor, 'clicks' => 0, 'unique_clicks' => 0,
                            'conversions' => 0, 'revenue' => 0, 'real_revenue' => 0, 'cost' => 0];
                        $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
                        $guard++;
                    }
                    foreach ($rows as $row) {
                        if (isset($filled[$row['period']])) { $filled[$row['period']] = $row; }
                    }
                }
                ksort($filled);
                $zeroFilled = array_values($filled);
            }
            $effectiveRows = !empty($zeroFilled) ? $zeroFilled : $rows;

            // Calculate derived metrics and format data
            $tableData = [];
            $chartLabels = [];
            $chartDatasets = [];

            // Initialize dataset arrays for each metric
            $metricData = [];
            foreach ($selectedMetrics as $m) {
                $metricData[$m] = [];
            }

            foreach ($effectiveRows as $row) {
                $period = $row['period'];

                // Format period label
                if ($groupBy === 'day_of_week') {
                    $period = $dayNames[(int) $period] ?? $period;
                }

                // Calculate derived metrics
                $clicks = (int) $row['clicks'];
                $conversions = (int) $row['conversions'];
                $revenue = (float) $row['revenue'];
                $realRevenue = (float) $row['real_revenue'];
                $cost = (float) $row['cost'];

                $profit = $revenue - $cost;
                $realProfit = $realRevenue - $cost;
                $ctr = 100; // Simplified
                $cr = $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0;
                $realRoi = $cost > 0 ? round(($realProfit / $cost) * 100, 2) : ($realProfit > 0 ? 100 : 0);

                // Derived row data
                $derivedRow = [
                    'period' => $period,
                    'clicks' => $clicks,
                    'unique_clicks' => (int) $row['unique_clicks'],
                    'conversions' => $conversions,
                    'revenue' => round($revenue, 2),
                    'real_revenue' => round($realRevenue, 2),
                    'cost' => round($cost, 2),
                    'profit' => round($profit, 2),
                    'real_profit' => round($realProfit, 2),
                    'ctr' => $ctr,
                    'cr' => $cr,
                    'real_roi' => $realRoi
                ];
                $rowData = $derivedRow;

                $tableData[] = $rowData;
                $chartLabels[] = $period;

                foreach ($selectedMetrics as $m) {
                    $metricData[$m][] = $rowData[$m] ?? 0;
                }
            }

            // Build chart datasets
            $metricColors = [
                'clicks' => '#3B82F6',
                'unique_clicks' => '#10B981',
                'conversions' => '#F59E0B',
                'revenue' => '#8B5CF6',
                'real_revenue' => '#4338CA',
                'cost' => '#EF4444',
                'profit' => '#06B6D4',
                'real_roi' => '#6366F1',
                'ctr' => '#EC4899',
                'cr' => '#84CC16'
            ];

            $metricLabels = [
                'clicks' => 'Клики',
                'unique_clicks' => 'Уник. клики',
                'conversions' => 'Конверсии',
                'revenue' => 'Доход',
                'real_revenue' => 'Real Rev',
                'cost' => 'Расход',
                'profit' => 'Прибыль',
                'real_roi' => 'Real ROI',
                'ctr' => 'CTR',
                'cr' => 'CR'
            ];

            foreach ($selectedMetrics as $m) {
                $chartDatasets[] = [
                    'metric' => $m,
                    'label' => $metricLabels[$m] ?? $m,
                    'data' => $metricData[$m],
                    'borderColor' => $metricColors[$m] ?? '#666666',
                    'backgroundColor' => ($metricColors[$m] ?? '#666666') . '20',
                    'fill' => true,
                    'tension' => 0.4
                ];
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'chart' => [
                        'labels' => $chartLabels,
                        'datasets' => $chartDatasets
                    ],
                    'table' => $tableData
                ]
            ]);
            break;

        // === COHORT ANALYSIS API ===
        // Campaign-launch cohorts: campaigns grouped by creation month/quarter,
        // metrics tracked across each cohort's lifetime periods (M0..Mn).
        // GET, read-only — covered by the auth gates (lines 111-217), works
        // with read-scoped API keys. Mirrors the trends/campaign_report shape.
        case 'cohort':
            $granularity = $_GET['granularity'] ?? 'month';
            if (!in_array($granularity, ['month', 'quarter'], true)) {
                $granularity = 'month';
            }
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months'));
            $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
            $groupId  = $_GET['group_id'] ?? '';
            $maxPeriods = (int)($_GET['max_periods'] ?? 12);
            if ($maxPeriods < 1)  { $maxPeriods = 12; }
            if ($maxPeriods > 36) { $maxPeriods = 36; }
            $filters = json_decode($_GET['filters'] ?? '[]', true) ?? [];

            // Whitelisted filter fields (campaign_report pattern, injection-safe).
            // Unlike trends (which interpolates $field raw), this maps user keys
            // to explicit qualified columns.
            $allowedFilterFields = [
                'country_code' => 'cl.country_code',
                'country'      => 'cl.country',
                'device_type'  => 'cl.device_type',
                'language'     => 'cl.language',
                'browser'      => 'cl.browser',
                'os'           => 'cl.os',
                'campaign_id'  => 'cl.campaign_id',
                'offer_id'     => 'cl.offer_id',
                'source_id'    => 'cl.source_id',
                'stream_id'    => 'cl.stream_id',
                'ip'           => 'cl.ip',
            ];
            $allowedOperators = ['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'ends_with'];

            // Period expressions. cohort_label is the row key (human label);
            // cohort_period / click_period are absolute integer period numbers
            // whose difference is the lifetime period_index (0 = launch period).
            // $dbTzOffset applied as the 3rd strftime arg (chart pattern, not
            // the trends pattern which drops it).
            if ($granularity === 'quarter') {
                $cohortLabelExpr  = "strftime('%Y', c.created_at, '$dbTzOffset') || '-Q' || ((CAST(strftime('%m', c.created_at, '$dbTzOffset') AS INTEGER) - 1) / 3 + 1)";
                $cohortPeriodExpr = "(CAST(strftime('%Y', c.created_at, '$dbTzOffset') AS INTEGER) * 4 + (CAST(strftime('%m', c.created_at, '$dbTzOffset') AS INTEGER) - 1) / 3 + 1)";
                $clickPeriodExpr  = "(CAST(strftime('%Y', cl.created_at, '$dbTzOffset') AS INTEGER) * 4 + (CAST(strftime('%m', cl.created_at, '$dbTzOffset') AS INTEGER) - 1) / 3 + 1)";
            } else {
                $cohortLabelExpr  = "strftime('%Y-%m', c.created_at, '$dbTzOffset')";
                $cohortPeriodExpr = "(CAST(strftime('%Y', c.created_at, '$dbTzOffset') AS INTEGER) * 12 + CAST(strftime('%m', c.created_at, '$dbTzOffset') AS INTEGER))";
                $clickPeriodExpr  = "(CAST(strftime('%Y', cl.created_at, '$dbTzOffset') AS INTEGER) * 12 + CAST(strftime('%m', cl.created_at, '$dbTzOffset') AS INTEGER))";
            }

            // Money columns via schema-introspecting helpers (api.php:714-752).
            // Never hardcode 'amount'/'payout' — the column name varies by schema.
            $conversionsValueColumn = getConversionsValueColumn($pdo);
            $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);

            // Period expressions per granularity. The event-period expression
            // takes a date column alias as input so it can be reused for clicks,
            // conversions and revenue_records — each attributed to its OWN event
            // time, not lumped into the click's period (the prior implementation
            // summed all per-click revenue into the click period, hiding exactly
            // the delayed-revenue effect cohort analysis exists to surface).
            $labelExpr    = fn($col) => $granularity === 'quarter'
                ? "strftime('%Y', $col, '$dbTzOffset') || '-Q' || ((CAST(strftime('%m', $col, '$dbTzOffset') AS INTEGER) - 1) / 3 + 1)"
                : "strftime('%Y-%m', $col, '$dbTzOffset')";
            $periodExpr   = fn($col) => $granularity === 'quarter'
                ? "(CAST(strftime('%Y', $col, '$dbTzOffset') AS INTEGER) * 4 + (CAST(strftime('%m', $col, '$dbTzOffset') AS INTEGER) - 1) / 3 + 1)"
                : "(CAST(strftime('%Y', $col, '$dbTzOffset') AS INTEGER) * 12 + CAST(strftime('%m', $col, '$dbTzOffset') AS INTEGER))";
            $cohortLabel  = $labelExpr('c.created_at');
            $cohortPeriod = $periodExpr('c.created_at');

            // WHERE: click date range + optional group_id + whitelisted filters.
            // Applied to the clicks source; conversion/revenue sources inherit
            // the same range by joining clicks filtered identically.
            $clickWhere = ["cl.created_at >= ?", "cl.created_at <= ?"];
            $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
            $groupClause = '';
            if ($groupId !== '') {
                $groupClause = " AND c.group_id = " . (int)$groupId;
            }
            foreach ($filters as $f) {
                $field    = $f['field'] ?? '';
                $operator = $f['operator'] ?? 'contains';
                $value    = $f['value'] ?? '';
                if (!$field || $value === '' || $value === null) { continue; }
                if (!isset($allowedFilterFields[$field])) { continue; }
                if (!in_array($operator, $allowedOperators, true)) { continue; }
                $col = $allowedFilterFields[$field];
                switch ($operator) {
                    case 'contains':     $clickWhere[] = "$col LIKE ?";    $params[] = "%$value%"; break;
                    case 'not_contains': $clickWhere[] = "$col NOT LIKE ?"; $params[] = "%$value%"; break;
                    case 'equals':       $clickWhere[] = "$col = ?";        $params[] = $value;     break;
                    case 'not_equals':   $clickWhere[] = "$col != ?";       $params[] = $value;     break;
                    case 'starts_with':  $clickWhere[] = "$col LIKE ?";     $params[] = "$value%";  break;
                    case 'ends_with':    $clickWhere[] = "$col LIKE ?";     $params[] = "%$value";  break;
                }
            }
            $clickWhereSQL = implode(' AND ', $clickWhere);

            // --- Source 1: clicks (volume + cost), bucketed by CLICK period ---
            $clickPeriodExpr = $periodExpr('cl.created_at');
            $clickSql = "SELECT $cohortLabel AS cohort_label, $cohortPeriod AS cohort_period,
                            $clickPeriodExpr AS event_period,
                            COUNT(cl.id) AS clicks,
                            COUNT(DISTINCT cl.ip) AS unique_clicks,
                            COALESCE(SUM(cl.cost), 0) AS cost,
                            COUNT(DISTINCT cl.campaign_id) AS campaigns_active
                         FROM clicks cl JOIN campaigns c ON cl.campaign_id = c.id
                         WHERE $clickWhereSQL$groupClause
                         GROUP BY cohort_label, cohort_period, event_period";
            $clickStmt = $pdo->prepare($clickSql);
            $clickStmt->execute($params);
            $clickRows = $clickStmt->fetchAll(PDO::FETCH_ASSOC);

            // --- Source 2: conversions (count + revenue), bucketed by CONVERSION period ---
            // joins the filtered clicks set so cohort filters still apply, but
            // attributes revenue to the period the conversion actually occurred.
            $convRows = [];
            if ($conversionsValueColumn !== null) {
                $convPeriodExpr = $periodExpr('cv.created_at');
                // Re-resolve the filtered clicks via a subquery on click_id, so
                // a conversion outside the click date range still lands in its
                // own period as long as its click is in the cohort.
                $convSql = "SELECT $cohortLabel AS cohort_label, $cohortPeriod AS cohort_period,
                                $convPeriodExpr AS event_period,
                                COUNT(*) AS conversions,
                                COUNT(DISTINCT cv.click_id) AS converting_clicks,
                                COALESCE(SUM(cv.$conversionsValueColumn), 0) AS revenue,
                                COUNT(DISTINCT cl.campaign_id) AS campaigns_active
                             FROM conversions cv
                             JOIN clicks cl ON cv.click_id = cl.id
                             JOIN campaigns c ON cl.campaign_id = c.id
                             WHERE cl.id IN (SELECT cl2.id FROM clicks cl2 JOIN campaigns c2 ON cl2.campaign_id = c2.id WHERE $clickWhereSQL$groupClause)
                             GROUP BY cohort_label, cohort_period, event_period";
                $convStmt = $pdo->prepare($convSql);
                $convStmt->execute($params);
                $convRows = $convStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // --- Source 3: revenue_records (real revenue), bucketed by EVENT date ---
            $realRows = [];
            if ($revenueRecordsValueColumn !== null) {
                // event_date is a DATE; fall back to created_at when null.
                $rrDateCol = "COALESCE(NULLIF(rr.event_date, ''), DATE(rr.created_at))";
                // Build the period expression inline — $periodExpr is a closure that
                // expects a bare column name and can't take a SQL expression, so the
                // real-revenue period is constructed directly from the same template.
                if ($granularity === 'quarter') {
                    $realPeriodExpr = "(CAST(strftime('%Y', $rrDateCol, '$dbTzOffset') AS INTEGER) * 4 + (CAST(strftime('%m', $rrDateCol, '$dbTzOffset') AS INTEGER) - 1) / 3 + 1)";
                } else {
                    $realPeriodExpr = "(CAST(strftime('%Y', $rrDateCol, '$dbTzOffset') AS INTEGER) * 12 + CAST(strftime('%m', $rrDateCol, '$dbTzOffset') AS INTEGER))";
                }
                $realSql = "SELECT $cohortLabel AS cohort_label, $cohortPeriod AS cohort_period,
                                $realPeriodExpr AS event_period,
                                COALESCE(SUM(rr.$revenueRecordsValueColumn), 0) AS real_revenue
                             FROM revenue_records rr
                             JOIN clicks cl ON rr.click_id = cl.id
                             JOIN campaigns c ON cl.campaign_id = c.id
                             WHERE cl.id IN (SELECT cl2.id FROM clicks cl2 JOIN campaigns c2 ON cl2.campaign_id = c2.id WHERE $clickWhereSQL$groupClause)
                             GROUP BY cohort_label, cohort_period, event_period";
                $realStmt = $pdo->prepare($realSql);
                $realStmt->execute($params);
                $realRows = $realStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // --- Merge sources by (cohort_label, period_index) ---
            // period_index = event_period - cohort_period, gated to [0, maxPeriods].
            // Each source contributes its own metrics; missing sources leave zeros.
            $cells = []; // [label => [period => [metrics]]]
            $mergeRows = function (array $sourceRows, callable $valueMap) use (&$cells, $maxPeriods) {
                foreach ($sourceRows as $r) {
                    $label = $r['cohort_label'];
                    $cp = (int)$r['cohort_period'];
                    $ep = isset($r['event_period']) ? (int)$r['event_period'] : null;
                    if ($ep === null || $ep === 0) { continue; } // event_period null = undatable revenue
                    $idx = $ep - $cp;
                    if ($idx < 0 || $idx > $maxPeriods) { continue; }
                    if (!isset($cells[$label])) { $cells[$label] = []; }
                    if (!isset($cells[$label][$idx])) {
                        $cells[$label][$idx] = [
                            'clicks' => 0, 'unique_clicks' => 0, 'conversions' => 0,
                            'converting_clicks' => 0,
                            'revenue' => 0.0, 'real_revenue' => 0.0, 'cost' => 0.0,
                            'campaigns_active' => 0,
                        ];
                    }
                    foreach ($valueMap($r) as $k => $v) {
                        $cells[$label][$idx][$k] += $v;
                    }
                }
            };
            $mergeRows($clickRows, fn($r) => [
                'clicks' => (int)$r['clicks'],
                'unique_clicks' => (int)$r['unique_clicks'],
                'cost' => (float)$r['cost'],
                'campaigns_active' => (int)$r['campaigns_active'],
            ]);
            $mergeRows($convRows, fn($r) => [
                'conversions' => (int)$r['conversions'],
                'converting_clicks' => (int)$r['converting_clicks'],
                'revenue' => (float)$r['revenue'],
                'campaigns_active' => (int)$r['campaigns_active'],
            ]);
            $mergeRows($realRows, fn($r) => [
                'real_revenue' => (float)$r['real_revenue'],
            ]);

            // Flatten + derive metrics.
            $rows = [];
            foreach ($cells as $label => $periods) {
                ksort($periods);
                foreach ($periods as $idx => $m) {
                    $clicks           = $m['clicks'];
                    $conversions      = $m['conversions'];
                    $convertingClicks = $m['converting_clicks'];
                    $revenue          = $m['revenue'];
                    $realRevenue      = $m['real_revenue'];
                    $cost             = $m['cost'];
                    $profit           = $revenue - $cost;
                    $realProfit       = $realRevenue - $cost;
                    $rows[] = [
                        'cohort_label'  => $label,
                        'period_index'  => $idx,
                        'clicks'        => $clicks,
                        'unique_clicks' => $m['unique_clicks'],
                        'conversions'   => $conversions,
                        'revenue'       => round($revenue, 2),
                        'real_revenue'  => round($realRevenue, 2),
                        'cost'          => round($cost, 2),
                        'profit'        => round($profit, 2),
                        'real_profit'   => round($realProfit, 2),
                        // CR = share of clicks that converted at least once, so it
                        // stays within [0, 100] even when a click has several
                        // conversions (the CPA-tracker convention).
                        'cr'      => $clicks > 0 ? round(($convertingClicks / $clicks) * 100, 2) : 0,
                        'roi'     => $cost > 0 ? round(($profit / $cost) * 100, 2) : ($profit > 0 ? 100 : 0),
                        'real_roi'=> $cost > 0 ? round(($realProfit / $cost) * 100, 2) : ($realProfit > 0 ? 100 : 0),
                        'campaigns_active' => $m['campaigns_active'],
                    ];
                }
            }
            usort($rows, fn($a, $b) => strcmp($a['cohort_label'], $b['cohort_label']) ?: $a['period_index'] <=> $b['period_index']);

            // Cohort denominator: campaigns launched per cohort (independent of
            // the click date range, so a cohort with traffic only later still
            // shows its full launch count).
            $launchedParams = [];
            $launchedSql = "SELECT $cohortLabelExpr AS cohort_label, COUNT(*) AS launched
                            FROM campaigns c
                            WHERE c.created_at IS NOT NULL";
            if ($groupId !== '') {
                $launchedSql .= " AND c.group_id = ?";
                $launchedParams[] = (int)$groupId;
            }
            $launchedSql .= " GROUP BY cohort_label";
            $launchedStmt = $pdo->prepare($launchedSql);
            $launchedStmt->execute($launchedParams);
            $launchedMap = [];
            foreach ($launchedStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $launchedMap[$lr['cohort_label']] = (int)$lr['launched'];
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'granularity' => $granularity,
                    'max_period'  => $maxPeriods,
                    'rows'        => $rows,
                    'launched'    => $launchedMap,
                ]
            ]);
            break;

        case 'run_migration':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $version = (int) ($data['version'] ?? 0);

                $availableMigrations = [
                    1 => "SELECT 1;",
                    2 => "SELECT 1;",
                    3 => "SELECT 1;",
                    4 => "SELECT 1;",
                    5 => "INSERT OR IGNORE INTO settings (key, value) VALUES ('archive_retention_days', '60');",
                    6 => "CREATE TABLE IF NOT EXISTS user_preferences (user_id INTEGER PRIMARY KEY, theme TEXT DEFAULT 'light');",
                    7 => "CREATE INDEX IF NOT EXISTS idx_clicks_date ON clicks(created_at);",
                    8 => "INSERT OR IGNORE INTO settings (key, value) VALUES ('session_lifetime', '86400');"
                ];

                if (!isset($availableMigrations[$version])) {
                    echo json_encode(['status' => 'error', 'message' => 'Неизвестная версия миграции']);
                    break;
                }

                $sql = $availableMigrations[$version];

                try {
                    $pdo->exec($sql);

                    // Mark as executed
                    $stmt = $pdo->prepare("INSERT INTO schema_migrations (version, description, status, executed_at) VALUES (?, ?, 'completed', datetime('now')) ON CONFLICT(version) DO UPDATE SET status = 'completed', executed_at = datetime('now')");
                    $stmt->execute([$version, "Migration $version"]);

                    echo json_encode(['status' => 'success', 'message' => "Миграция $version выполнена успешно"]);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Ошибка выполнения миграции: ' . $e->getMessage()]);
                }
            }
            break;

        // === TELEGRAM BOT API ===
        case 'telegram_settings':
            $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('telegram_bot_token', 'telegram_webhook_set', 'telegram_notify_conversions', 'telegram_daily_time')");
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['key']] = $row['value'];
            }
            // Get connected chats
            $chatsStmt = $pdo->query("SELECT chat_id, username, first_name, language, notify_conversions, notify_daily, created_at FROM telegram_bot_chats WHERE is_active = 1");
            $chats = $chatsStmt ? $chatsStmt->fetchAll() : [];

            // Mask token for display
            $token = $settings['telegram_bot_token'] ?? '';
            $maskedToken = $token ? substr($token, 0, 10) . '...' . substr($token, -4) : '';

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'token_set' => !empty($token),
                    'masked_token' => $maskedToken,
                    'webhook_set' => ($settings['telegram_webhook_set'] ?? '0') === '1',
                    'notify_conversions' => ($settings['telegram_notify_conversions'] ?? '1') === '1',
                    'daily_time' => $settings['telegram_daily_time'] ?? '21:00',
                    'chats' => $chats
                ]
            ]);
            break;

        case 'save_telegram_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $action = $data['action'] ?? 'save';

                if ($action === 'disconnect') {
                    // Remove webhook and clear token
                    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
                    $oldToken = $stmt ? $stmt->fetchColumn() : '';
                    if ($oldToken) {
                        // Remove webhook
                        $ch = curl_init("https://api.telegram.org/bot{$oldToken}/deleteWebhook");
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        curl_exec($ch);
                        // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                    }
                    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute(['telegram_bot_token', '']);
                    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute(['telegram_webhook_set', '0']);
                    logAudit($pdo, 'UPDATE', 'Telegram Bot', null, ['action' => 'disconnect']);
                    echo json_encode(['status' => 'success', 'message' => 'Bot disconnected']);
                    break;
                }

                $token = trim($data['token'] ?? '');
                $notifyConversions = $data['notify_conversions'] ?? true;
                $dailyTime = $data['daily_time'] ?? '21:00';

                if ($token) {
                    // Verify token by calling getMe
                    $ch = curl_init("https://api.telegram.org/bot{$token}/getMe");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $response = curl_exec($ch);
                    // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                    $result = json_decode($response, true);

                    if (!$result || !($result['ok'] ?? false)) {
                        echo json_encode(['status' => 'error', 'message' => 'Invalid bot token. Check the token and try again.']);
                        break;
                    }

                    $botUsername = $result['result']['username'] ?? '';

                    // Save token
                    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute(['telegram_bot_token', $token]);

                    // Set webhook
                    $webhookUrl = rtrim($data['webhook_url'] ?? (rtrim(
                        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                        '/'
                    ) . '/telegram_bot.php'), '/');

                    $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['url' => $webhookUrl]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $webhookResult = json_decode(curl_exec($ch), true);
                    // curl_close() deprecated in PHP 8.5 - resources are auto-freed

                    $webhookOk = $webhookResult['ok'] ?? false;
                    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute(['telegram_webhook_set', $webhookOk ? '1' : '0']);
                }

                // Save other settings
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute(['telegram_notify_conversions', $notifyConversions ? '1' : '0']);
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute(['telegram_daily_time', $dailyTime]);

                logAudit($pdo, 'UPDATE', 'Telegram Bot', null, ['action' => 'save', 'bot' => $botUsername ?? '']);

                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'bot_username' => $botUsername ?? '',
                        'webhook_set' => $webhookOk ?? false
                    ]
                ]);
            }
            break;

        case 'telegram_test':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
                $token = $stmt ? $stmt->fetchColumn() : '';

                if (!$token) {
                    echo json_encode(['status' => 'error', 'message' => 'Bot token not configured']);
                    break;
                }

                // Get first active chat
                $chatStmt = $pdo->query("SELECT chat_id, language FROM telegram_bot_chats WHERE is_active = 1 LIMIT 1");
                $chat = $chatStmt ? $chatStmt->fetch() : null;

                if (!$chat) {
                    echo json_encode(['status' => 'error', 'message' => 'No chats connected. Send /start to the bot first.']);
                    break;
                }

                $lang = $chat['language'] ?? 'en';
                $testMsg = $lang === 'ru'
                    ? "✅ *Тестовое сообщение*\n\nOrbitra бот работает корректно!"
                    : "✅ *Test Message*\n\nOrbitra bot is working correctly!";

                $url = "https://api.telegram.org/bot{$token}/sendMessage";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'chat_id' => $chat['chat_id'],
                    'text' => $testMsg,
                    'parse_mode' => 'Markdown'
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $result = json_decode(curl_exec($ch), true);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed

                if ($result && ($result['ok'] ?? false)) {
                    echo json_encode(['status' => 'success', 'message' => 'Test message sent']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to send: ' . ($result['description'] ?? 'Unknown error')]);
                }
            }
            break;

        // ==================== Campaign Pixels ====================
        case 'campaign_pixels':
            $campaign_id = $_GET['campaign_id'] ?? null;
            if (!$campaign_id) {
                echo json_encode(['status' => 'error', 'message' => 'Campaign ID required']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM campaign_pixels WHERE campaign_id = ? ORDER BY type");
            $stmt->execute([$campaign_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        case 'save_campaign_pixel':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $campaign_id = $data['campaign_id'] ?? null;
                $type = $data['type'] ?? '';
                $pixel_id = $data['pixel_id'] ?? '';

                if (!$campaign_id || !$type || !$pixel_id) {
                    echo json_encode(['status' => 'error', 'message' => 'Campaign ID, type, and pixel ID are required']);
                    break;
                }

                $id = $data['id'] ?? null;
                $token = $data['token'] ?? '';
                $events = $data['events'] ?? 'PageView,Lead';
                $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;

                // Server-side half (Facebook Conversions API). mapping_json holds
                // {tracker status => Meta event}; an empty event means "never send
                // this status", which is how rejected/trash are suppressed.
                $mapping_json = null;
                if (isset($data['mapping_json'])) {
                    $mapping_json = is_array($data['mapping_json'])
                        ? json_encode($data['mapping_json'], JSON_UNESCAPED_UNICODE)
                        : (string) $data['mapping_json'];
                } elseif (isset($data['mapping']) && is_array($data['mapping'])) {
                    $mapping_json = json_encode($data['mapping'], JSON_UNESCAPED_UNICODE);
                }
                $test_event_code = trim((string) ($data['test_event_code'] ?? ''));
                $proxy_url = trim((string) ($data['proxy_url'] ?? ''));
                $api_version = trim((string) ($data['api_version'] ?? ''));

                if ($id) {
                    $stmt = $pdo->prepare("UPDATE campaign_pixels SET type=?, pixel_id=?, token=?, events=?, is_active=?, mapping_json=?, test_event_code=?, proxy_url=?, api_version=? WHERE id=? AND campaign_id=?");
                    $stmt->execute([$type, $pixel_id, $token, $events, $is_active, $mapping_json, $test_event_code, $proxy_url, $api_version, $id, $campaign_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO campaign_pixels (campaign_id, type, pixel_id, token, events, is_active, mapping_json, test_event_code, proxy_url, api_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$campaign_id, $type, $pixel_id, $token, $events, $is_active, $mapping_json, $test_event_code, $proxy_url, $api_version]);
                    $id = $pdo->lastInsertId();
                }

                echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
            }
            break;

        // Every Facebook pixel across all campaigns, for the Integrations page.
        // The token itself is never returned — only whether one is set, which is
        // what decides between "server-side on" and "browser pixel only".
        case 'facebook_capi_list':
            require_once __DIR__ . '/core/FacebookConversions.php';
            $stmt = $pdo->query("
                SELECT cp.id, cp.campaign_id, cp.pixel_id, cp.events, cp.is_active,
                       cp.mapping_json, cp.test_event_code, cp.proxy_url, cp.api_version,
                       c.name AS campaign_name
                FROM campaign_pixels cp
                LEFT JOIN campaigns c ON cp.campaign_id = c.id
                WHERE cp.type = 'facebook'
                ORDER BY cp.id DESC
            ");
            $pixels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tokenStmt = $pdo->prepare("SELECT token FROM campaign_pixels WHERE id = ?");
            foreach ($pixels as &$px) {
                $tokenStmt->execute([$px['id']]);
                $px['has_token'] = trim((string) $tokenStmt->fetchColumn()) !== '';

                // A one-line "lead→Lead, sale→Purchase" for the list row, so the
                // operator can see what a campaign actually sends without opening it.
                $mapping = json_decode((string) ($px['mapping_json'] ?? ''), true);
                if (is_array($mapping) && $mapping) {
                    $parts = [];
                    foreach ($mapping as $status => $event) {
                        if (trim((string) $event) !== '') {
                            $parts[] = $status . '→' . $event;
                        }
                    }
                    $px['mapping_summary'] = $parts ? implode(', ', $parts) : null;
                } else {
                    $px['mapping_summary'] = null;
                }
            }
            unset($px);

            echo json_encode(['status' => 'success', 'data' => $pixels]);
            break;

        // Default status→event map + the Meta events offered in the mapping UI.
        case 'facebook_capi_meta':
            require_once __DIR__ . '/core/FacebookConversions.php';
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'default_mapping'  => FacebookConversions::defaultMapping(),
                    'available_events' => FacebookConversions::availableEvents(),
                ],
            ]);
            break;

        // Send one event straight to Meta so an operator can confirm the pixel ID and
        // token before waiting on real traffic. Bypasses the queue deliberately: the
        // person pressing "test" wants the answer now, not on the next cron tick.
        case 'facebook_capi_test':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['status' => 'error', 'message' => 'POST required']);
                break;
            }

            require_once __DIR__ . '/core/FacebookConversions.php';
            $data = json_decode(orbitraRequestBody(), true);

            $pixel = null;
            if (!empty($data['id'])) {
                $stmt = $pdo->prepare("SELECT * FROM campaign_pixels WHERE id = ? LIMIT 1");
                $stmt->execute([$data['id']]);
                $pixel = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            // Unsaved form: accept the credentials inline so "test" works before save.
            if (!$pixel) {
                $pixel = [
                    'pixel_id'        => $data['pixel_id'] ?? '',
                    'token'           => $data['token'] ?? '',
                    'mapping_json'    => $data['mapping_json'] ?? null,
                    'test_event_code' => $data['test_event_code'] ?? '',
                    'proxy_url'       => $data['proxy_url'] ?? '',
                    'api_version'     => $data['api_version'] ?? '',
                ];
            }

            if (empty($pixel['pixel_id']) || empty($pixel['token'])) {
                echo json_encode(['status' => 'error', 'message' => 'Pixel ID and Conversions API token are required.']);
                break;
            }

            // A real recent click makes the test representative — it carries the same
            // fbclid/IP/user-agent a live event would. Falls back to a synthetic one.
            $clickRow = null;
            if (!empty($data['campaign_id'])) {
                $stmt = $pdo->prepare("
                    SELECT id, ip, user_agent, referer, country_code, region, city, zipcode,
                           parameters_json, created_at
                    FROM clicks
                    WHERE campaign_id = ? AND json_extract(parameters_json, '$.fbclid') IS NOT NULL
                    ORDER BY created_at DESC LIMIT 1
                ");
                $stmt->execute([$data['campaign_id']]);
                $clickRow = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$clickRow) {
                $clickRow = [
                    'ip'              => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? 'Orbitra/CAPI-test',
                    'referer'         => '',
                    'country_code'    => '',
                    'region'          => '',
                    'city'            => '',
                    'zipcode'         => '',
                    'parameters_json' => '{}',
                    'created_at'      => date('Y-m-d H:i:s'),
                ];
            }

            $clickParamsForTest = json_decode($clickRow['parameters_json'] ?? '{}', true);
            if (!is_array($clickParamsForTest)) {
                $clickParamsForTest = [];
            }

            $payload = FacebookConversions::buildPayload($pixel, $clickRow, [
                'event_name'   => $data['event_name'] ?? 'Lead',
                'event_time'   => time(),
                'event_id'     => 'orbitra_test_' . bin2hex(random_bytes(6)),
                'payout'       => (float) ($data['payout'] ?? 1),
                'currency'     => $data['currency'] ?? 'USD',
                'click_params' => $clickParamsForTest,
                'extra'        => [],
            ]);

            $result = FacebookConversions::send($pixel, $payload);
            echo json_encode([
                'status'  => $result['success'] ? 'success' : 'error',
                'message' => $result['message'],
                'data'    => [
                    'used_real_click' => !empty($clickRow['id']),
                    'payload'         => $payload,
                    'response'        => $result['response'],
                ],
            ]);
            break;

        case 'delete_campaign_pixel':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    $stmt = $pdo->prepare("DELETE FROM campaign_pixels WHERE id = ?");
                    $stmt->execute([$id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'ID required']);
                }
            }
            break;

        // ==================== App Configs ====================
        case 'app_configs':
            $stmt = $pdo->query("
                SELECT ac.*, c.name as campaign_name
                FROM app_configs ac
                LEFT JOIN campaigns c ON ac.campaign_id = c.id
                ORDER BY ac.created_at DESC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        case 'save_app_config':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $name = $data['name'] ?? '';
                $config_json = $data['config_json'] ?? '{}';

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                    break;
                }

                // Validate JSON
                $decoded = json_decode($config_json);
                if ($decoded === null && $config_json !== 'null') {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
                    break;
                }

                $id = $data['id'] ?? null;
                $campaign_id = $data['campaign_id'] ?: null;
                $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;

                if ($id) {
                    $stmt = $pdo->prepare("UPDATE app_configs SET name=?, campaign_id=?, config_json=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                    $stmt->execute([$name, $campaign_id, $config_json, $is_active, $id]);
                } else {
                    $config_key = substr(md5(uniqid(mt_rand(), true)), 0, 12);
                    $stmt = $pdo->prepare("INSERT INTO app_configs (name, campaign_id, config_key, config_json, is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $campaign_id, $config_key, $config_json, $is_active]);
                    $id = $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("SELECT * FROM app_configs WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['status' => 'success', 'data' => $stmt->fetch()]);
            }
            break;

        case 'delete_app_config':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(orbitraRequestBody(), true);
                $id = $data['id'] ?? null;
                if ($id) {
                    $stmt = $pdo->prepare("DELETE FROM app_configs WHERE id = ?");
                    $stmt->execute([$id]);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'ID required']);
                }
            }
            break;

        // Public endpoint — no auth needed
        case 'app_config':
            header('Access-Control-Allow-Origin: *');
            $key = $_GET['key'] ?? '';
            if (!$key) {
                echo json_encode(['error' => 'Key required']);
                break;
            }
            $stmt = $pdo->prepare("SELECT config_json, is_active FROM app_configs WHERE config_key = ?");
            $stmt->execute([$key]);
            $config = $stmt->fetch();
            if (!$config) {
                http_response_code(404);
                echo json_encode(['error' => 'Config not found']);
            } elseif (!$config['is_active']) {
                echo json_encode(['active' => false]);
            } else {
                echo $config['config_json'];
            }
            break;

        // Public endpoint — geo detection for integration scripts
        case 'detect_geo':
            header('Access-Control-Allow-Origin: *');
            header('Content-Type: application/json');

            // Reuse GeoIP logic from index.php
            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                require_once __DIR__ . '/vendor/autoload.php';
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            // Check forwarded headers
            $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
            foreach ($ipKeys as $key) {
                if (!empty($_SERVER[$key])) {
                    foreach (explode(',', $_SERVER[$key]) as $candidateIp) {
                        $candidateIp = trim($candidateIp);
                        if (filter_var($candidateIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                            $ip = $candidateIp;
                            break 2;
                        }
                    }
                }
            }

            $country = 'UNKNOWN';
            if (!in_array($ip, ['127.0.0.1', '::1'])) {
                // 1. MaxMind
                $maxMindDb = __DIR__ . '/geo/GeoLite2-City.mmdb';
                if (file_exists($maxMindDb) && class_exists('\GeoIp2\Database\Reader')) {
                    try {
                        $reader = new \GeoIp2\Database\Reader($maxMindDb);
                        $record = $reader->city($ip);
                        $country = $record->country->isoCode ?: 'UNKNOWN';
                    } catch (\Exception $e) {
                    }
                }

                // 2. IP2Location (DB11)
                if ($country === 'UNKNOWN') {
                    $ip2locCandidates = [
                        __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN',
                        __DIR__ . '/geo/IP2LOCATION-LITE.BIN'
                    ];
                    $ip2locDb = null;
                    foreach ($ip2locCandidates as $candidate) {
                        if (file_exists($candidate)) {
                            $ip2locDb = $candidate;
                            break;
                        }
                    }

                    if ($ip2locDb && class_exists('\IP2Location\Database')) {
                        try {
                            $db = new \IP2Location\Database($ip2locDb, \IP2Location\Database::FILE_IO);
                            $records = $db->lookup($ip, \IP2Location\Database::ALL);
                            if ($records && is_array($records) && !empty($records['countryCode']) && $records['countryCode'] !== '-') {
                                $country = $records['countryCode'];
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }

                // 3. SxGeo
                if ($country === 'UNKNOWN') {
                    $sxGeoDat = __DIR__ . '/var/geoip/SxGeoCity/SxGeoCity.dat';
                    $sxGeoParser = __DIR__ . '/core/SxGeo.php';
                    if (file_exists($sxGeoDat) && file_exists($sxGeoParser)) {
                        require_once $sxGeoParser;
                        try {
                            $sxGeoClass = '\SxGeo';
                            if (class_exists($sxGeoClass)) {
                                $sxGeo = new $sxGeoClass($sxGeoDat);
                                $cc = $sxGeo->getCountry($ip);
                                if ($cc)
                                    $country = $cc;
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }

                // 4. Fallback: external API
                if ($country === 'UNKNOWN') {
                    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    $response = curl_exec($ch);
                    // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                    if ($response) {
                        $data = json_decode($response, true);
                        if (!empty($data['countryCode']))
                            $country = $data['countryCode'];
                    }
                }
            }

            echo json_encode(['country' => $country, 'ip' => $ip]);
            break;


        default:
            // === REVENUE AGGREGATOR API ===
            if (strpos($action, 'aggregator_') === 0) {
                require_once __DIR__ . '/aggregator_engines/GenericApiEngine.php';
                require_once __DIR__ . '/aggregator_engines/ReferOnEngine.php';
                require_once __DIR__ . '/aggregator_engines/AffilkaEngine.php';
                require_once __DIR__ . '/aggregator_engines/FacebookAdsEngine.php';
                require_once __DIR__ . '/aggregator_engines/GoogleAdsEngine.php';
                require_once __DIR__ . '/aggregator_engines/TikTokAdsEngine.php';

                switch ($action) {
                    case 'aggregator_connections':
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $data = json_decode(orbitraRequestBody(), true);

                            if (isset($data['action']) && $data['action'] === 'delete') {
                                $pdo->prepare("DELETE FROM aggregator_connections WHERE id = ?")->execute([$data['id']]);
                                echo json_encode(['status' => 'success']);
                                break;
                            }

                            $id = $data['id'] ?? null;
                            $name = $data['name'] ?? '';
                            $engine = $data['engine'] ?? 'generic';
                            $affiliateNetworkId = $data['affiliate_network_id'] ?? null;
                            $authType = $data['auth_type'] ?? 'api_key';
                            $credentialsJson = isset($data['credentials']) ? json_encode($data['credentials']) : '{}';
                            $baseUrl = $data['base_url'] ?? '';
                            $dealType = $data['deal_type'] ?? 'cpa';
                            $baseline = $data['baseline'] ?? 0;
                            $clickIdParam = $data['click_id_param'] ?? 'sub_id';
                            $fieldMappingJson = isset($data['field_mapping']) ? json_encode($data['field_mapping']) : null;
                            $syncInterval = $data['sync_interval_hours'] ?? 2;
                            $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;

                            if ($id) {
                                $stmt = $pdo->prepare("UPDATE aggregator_connections SET name=?, engine=?, affiliate_network_id=?, auth_type=?, credentials_json=?, base_url=?, deal_type=?, baseline=?, click_id_param=?, field_mapping_json=?, sync_interval_hours=?, is_active=? WHERE id=?");
                                $stmt->execute([$name, $engine, $affiliateNetworkId, $authType, $credentialsJson, $baseUrl, $dealType, $baseline, $clickIdParam, $fieldMappingJson, $syncInterval, $isActive, $id]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO aggregator_connections (name, engine, affiliate_network_id, auth_type, credentials_json, base_url, deal_type, baseline, click_id_param, field_mapping_json, sync_interval_hours, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                                $stmt->execute([$name, $engine, $affiliateNetworkId, $authType, $credentialsJson, $baseUrl, $dealType, $baseline, $clickIdParam, $fieldMappingJson, $syncInterval, $isActive]);
                                $id = $pdo->lastInsertId();
                            }

                            echo json_encode(['status' => 'success', 'id' => $id]);
                        } else {
                            $stmt = $pdo->query("
                                SELECT ac.*, an.name as network_name
                                FROM aggregator_connections ac
                                LEFT JOIN affiliate_networks an ON ac.affiliate_network_id = an.id
                                ORDER BY ac.id DESC
                            ");
                            $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            // Не возвращаем credentials в list
                            foreach ($connections as &$c) {
                                $c['has_credentials'] = !empty($c['credentials_json']) && $c['credentials_json'] !== '{}';
                                unset($c['credentials_json']);
                            }
                            echo json_encode(['status' => 'success', 'data' => $connections]);
                        }
                        break;

                    case 'aggregator_connection_detail':
                        $id = $_GET['id'] ?? null;
                        if (!$id) {
                            echo json_encode(['status' => 'error', 'message' => 'ID required']);
                            break;
                        }
                        $stmt = $pdo->prepare("SELECT * FROM aggregator_connections WHERE id = ?");
                        $stmt->execute([$id]);
                        $conn = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($conn) {
                            $conn['credentials'] = json_decode($conn['credentials_json'] ?? '{}', true);
                            $conn['field_mapping'] = json_decode($conn['field_mapping_json'] ?? '{}', true);
                            unset($conn['credentials_json'], $conn['field_mapping_json']);
                        }
                        echo json_encode(['status' => 'success', 'data' => $conn]);
                        break;

                    case 'aggregator_test_connection':
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $data = json_decode(orbitraRequestBody(), true);
                            $credentials = $data['credentials'] ?? [];
                            $engine = $data['engine'] ?? 'generic';

                            switch ($engine) {
                                case 'referon':
                                    $result = ReferOnEngine::testConnection($credentials);
                                    break;
                                case 'affilka':
                                    $result = AffilkaEngine::testConnection($credentials);
                                    break;
                                case 'facebook':
                                    $result = FacebookAdsEngine::testConnection($credentials);
                                    break;
                                case 'google_ads':
                                    $result = GoogleAdsEngine::testConnection($credentials);
                                    break;
                                case 'tiktok':
                                    $result = TikTokAdsEngine::testConnection($credentials);
                                    break;
                                default:
                                    $result = GenericApiEngine::testConnection($credentials);
                            }
                            echo json_encode(['status' => 'success', 'data' => $result]);
                        }
                        break;

                    case 'aggregator_sync':
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $data = json_decode(orbitraRequestBody(), true);
                            $connectionId = $data['connection_id'] ?? null;
                            $dateFrom = $data['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
                            $dateTo = $data['date_to'] ?? date('Y-m-d');

                            if (!$connectionId) {
                                echo json_encode(['status' => 'error', 'message' => 'connection_id required']);
                                break;
                            }

                            $stmt = $pdo->prepare("SELECT * FROM aggregator_connections WHERE id = ?");
                            $stmt->execute([$connectionId]);
                            $conn = $stmt->fetch(PDO::FETCH_ASSOC);

                            if (!$conn) {
                                echo json_encode(['status' => 'error', 'message' => 'Connection not found']);
                                break;
                            }

                            $startTime = microtime(true);
                            $credentials = json_decode($conn['credentials_json'] ?? '{}', true);
                            $fieldMapping = json_decode($conn['field_mapping_json'] ?? '{}', true);

                            try {
                                // Dispatch to correct engine
                                $isCostEngine = false;
                                switch ($conn['engine'] ?? 'generic') {
                                    case 'referon':
                                        $records = ReferOnEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        break;
                                    case 'affilka':
                                        $records = AffilkaEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        break;
                                    case 'facebook':
                                        $records = FacebookAdsEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        $isCostEngine = true;
                                        break;
                                    case 'google_ads':
                                        $records = GoogleAdsEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        $isCostEngine = true;
                                        break;
                                    case 'tiktok':
                                        $records = TikTokAdsEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        $isCostEngine = true;
                                        break;
                                    default:
                                        $records = GenericApiEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                }

                                // Cost engines write spend to cost_records and distribute it across clicks.
                                // Same helper the scheduled sync uses, so both paths upsert
                                // identically and re-syncing today picks up new spend.
                                if ($isCostEngine) {
                                    require_once __DIR__ . '/core/CostImporter.php';

                                    $pdo->beginTransaction();
                                    $costStats = CostImporter::import($pdo, (int) $connectionId, $records, is_array($fieldMapping) ? $fieldMapping : []);
                                    $pdo->commit();

                                    $fetched  = $costStats['fetched'];
                                    $matched  = $costStats['matched'];
                                    $newCount = $costStats['new'];

                                    $durationMs = round((microtime(true) - $startTime) * 1000);
                                    $pdo->prepare("UPDATE aggregator_connections SET last_sync_at = datetime('now'), last_sync_status = 'success', last_sync_error = NULL WHERE id = ?")->execute([$connectionId]);
                                    $pdo->prepare("INSERT INTO aggregator_sync_logs (connection_id, status, records_fetched, records_matched, records_new, duration_ms, date_from, date_to) VALUES (?,?,?,?,?,?,?,?)")
                                        ->execute([$connectionId, 'success', $fetched, $matched, $newCount, $durationMs, $dateFrom, $dateTo]);
                                    echo json_encode([
                                        'status' => 'success',
                                        'data' => [
                                            'fetched'     => $fetched,
                                            'matched'     => $matched,
                                            'new'         => $newCount,
                                            'updated'     => $costStats['updated'],
                                            'unmatched'   => $costStats['unmatched'],
                                            'duration_ms' => $durationMs,
                                        ],
                                    ]);
                                    break;
                                }

                                $insertStmt = $pdo->prepare("INSERT INTO revenue_records (connection_id, external_id, click_id, player_id, event_type, amount, currency, country, brand, sub_id, event_date, raw_json, is_matched) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                                $clickCheckStmt = $pdo->prepare("SELECT id FROM clicks WHERE id = ?");
                                $updateRevenueStmt = $pdo->prepare("UPDATE clicks SET revenue = revenue + ? WHERE id = ?");

                                $pdo->beginTransaction();
                                $fetched = count($records);
                                $matched = 0;
                                $newCount = 0;

                                foreach ($records as $rec) {
                                    $externalId = $rec['external_id'] ?? null;
                                    $clickId = $rec['click_id'] ?? null;

                                    // Проверяем дубликат
                                    if ($externalId) {
                                        $dupCheck = $pdo->prepare("SELECT id FROM revenue_records WHERE connection_id = ? AND external_id = ?");
                                        $dupCheck->execute([$connectionId, $externalId]);
                                        if ($dupCheck->fetch())
                                            continue;
                                    }

                                    // Проверяем matching с clicks
                                    $isMatched = 0;
                                    if ($clickId) {
                                        $clickCheckStmt->execute([$clickId]);
                                        if ($clickCheckStmt->fetch()) {
                                            $isMatched = 1;
                                            $matched++;

                                            // Update clicks.revenue with real amount
                                            $amount = (float) ($rec['amount'] ?? 0);
                                            if ($amount > 0) {
                                                $updateRevenueStmt->execute([$amount, $clickId]);
                                            }
                                        }
                                    }

                                    $insertStmt->execute([
                                        $connectionId,
                                        $externalId,
                                        $clickId,
                                        $rec['player_id'] ?? null,
                                        $rec['event_type'] ?? 'ftd',
                                        (float) ($rec['amount'] ?? 0),
                                        $rec['currency'] ?? 'USD',
                                        $rec['country'] ?? null,
                                        $rec['brand'] ?? null,
                                        $rec['sub_id'] ?? null,
                                        $rec['event_date'] ?? date('Y-m-d'),
                                        $rec['raw_json'] ?? null,
                                        $isMatched
                                    ]);
                                    $newCount++;
                                }

                                $pdo->commit();
                                $durationMs = round((microtime(true) - $startTime) * 1000);

                                // Update connection status
                                $pdo->prepare("UPDATE aggregator_connections SET last_sync_at = datetime('now'), last_sync_status = 'success', last_sync_error = NULL WHERE id = ?")->execute([$connectionId]);

                                // Save sync log
                                $pdo->prepare("INSERT INTO aggregator_sync_logs (connection_id, status, records_fetched, records_matched, records_new, duration_ms, date_from, date_to) VALUES (?,?,?,?,?,?,?,?)")
                                    ->execute([$connectionId, 'success', $fetched, $matched, $newCount, $durationMs, $dateFrom, $dateTo]);

                                echo json_encode([
                                    'status' => 'success',
                                    'data' => [
                                        'fetched' => $fetched,
                                        'matched' => $matched,
                                        'new' => $newCount,
                                        'duration_ms' => $durationMs
                                    ]
                                ]);
                            } catch (\Exception $e) {
                                if ($pdo->inTransaction())
                                    $pdo->rollBack();
                                $durationMs = round((microtime(true) - $startTime) * 1000);

                                $pdo->prepare("UPDATE aggregator_connections SET last_sync_at = datetime('now'), last_sync_status = 'error', last_sync_error = ? WHERE id = ?")->execute([$e->getMessage(), $connectionId]);
                                $pdo->prepare("INSERT INTO aggregator_sync_logs (connection_id, status, error_message, duration_ms, date_from, date_to) VALUES (?,?,?,?,?,?)")
                                    ->execute([$connectionId, 'error', $e->getMessage(), $durationMs, $dateFrom, $dateTo]);

                                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                            }
                        }
                        break;

                    case 'aggregator_revenue':
                        $connectionId = $_GET['connection_id'] ?? null;
                        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
                        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
                        $page = max(1, (int) ($_GET['page'] ?? 1));
                        $limit = min(100, max(10, (int) ($_GET['limit'] ?? 50)));
                        $offset = ($page - 1) * $limit;

                        $where = "WHERE rr.event_date >= ? AND rr.event_date <= ?";
                        $params = [$dateFrom, $dateTo];

                        if ($connectionId) {
                            $where .= " AND rr.connection_id = ?";
                            $params[] = $connectionId;
                        }

                        // Get totals
                        $totalStmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(amount) as total_amount, SUM(CASE WHEN is_matched = 1 THEN 1 ELSE 0 END) as matched_count FROM revenue_records rr $where");
                        $totalStmt->execute($params);
                        $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);

                        // Get records
                        $stmt = $pdo->prepare("
                            SELECT rr.*, ac.name as connection_name
                            FROM revenue_records rr
                            LEFT JOIN aggregator_connections ac ON rr.connection_id = ac.id
                            $where
                            ORDER BY rr.event_date DESC, rr.id DESC
                            LIMIT $limit OFFSET $offset
                        ");
                        $stmt->execute($params);

                        echo json_encode([
                            'status' => 'success',
                            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                            'totals' => $totals,
                            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $totals['total']]
                        ]);
                        break;

                    case 'aggregator_revenue_export':
                        $connectionId = $_GET['connection_id'] ?? null;
                        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
                        $dateTo = $_GET['date_to'] ?? date('Y-m-d');

                        $where = "WHERE rr.event_date >= ? AND rr.event_date <= ?";
                        $params = [$dateFrom, $dateTo];

                        if ($connectionId) {
                            $where .= " AND rr.connection_id = ?";
                            $params[] = $connectionId;
                        }

                        $stmt = $pdo->prepare("
                            SELECT rr.id, ac.name as connection, rr.event_date, rr.external_id, rr.click_id, 
                                   rr.player_id, rr.event_type, rr.amount, rr.currency, rr.country, 
                                   rr.brand, rr.sub_id, rr.is_matched, rr.created_at
                            FROM revenue_records rr
                            LEFT JOIN aggregator_connections ac ON rr.connection_id = ac.id
                            $where
                            ORDER BY rr.event_date DESC, rr.id DESC
                        ");
                        $stmt->execute($params);
                        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        header('Content-Type: text/csv; charset=utf-8');
                        header('Content-Disposition: attachment; filename=aggregator_revenue_' . $dateFrom . '_' . $dateTo . '.csv');
                        $output = fopen('php://output', 'w');

                        // UTF-8 BOM
                        fwrite($output, "\xEF\xBB\xBF");

                        fputcsv($output, ['ID', 'Connection', 'Event Date', 'External ID', 'Click ID', 'Player ID', 'Event Type', 'Amount', 'Currency', 'Country', 'Brand', 'Sub ID', 'Is Matched', 'Imported At']);
                        foreach ($records as $r) {
                            fputcsv($output, [
                                $r['id'],
                                $r['connection'],
                                $r['event_date'],
                                $r['external_id'],
                                $r['click_id'],
                                $r['player_id'],
                                $r['event_type'],
                                $r['amount'],
                                $r['currency'],
                                $r['country'],
                                $r['brand'],
                                $r['sub_id'],
                                ($r['is_matched'] ? 'Yes' : 'No'),
                                $r['created_at']
                            ]);
                        }
                        fclose($output);
                        exit;

                    case 'aggregator_sync_logs':
                        $connectionId = $_GET['connection_id'] ?? null;
                        $where = "";
                        $params = [];
                        if ($connectionId) {
                            $where = "WHERE sl.connection_id = ?";
                            $params[] = $connectionId;
                        }
                        $stmt = $pdo->prepare("
                            SELECT sl.*, ac.name as connection_name
                            FROM aggregator_sync_logs sl
                            LEFT JOIN aggregator_connections ac ON sl.connection_id = ac.id
                            $where
                            ORDER BY sl.created_at DESC
                            LIMIT 100
                        ");
                        $stmt->execute($params);
                        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                        break;

                    case 'aggregator_engine_fields':
                        $engine = $_GET['engine'] ?? 'generic';
                        switch ($engine) {
                            case 'referon':
                                $fields = ReferOnEngine::getRequiredFields();
                                break;
                            case 'affilka':
                                $fields = AffilkaEngine::getRequiredFields();
                                break;
                            case 'facebook':
                                $fields = FacebookAdsEngine::getRequiredFields();
                                break;
                            case 'google_ads':
                                $fields = GoogleAdsEngine::getRequiredFields();
                                break;
                            case 'tiktok':
                                $fields = TikTokAdsEngine::getRequiredFields();
                                break;
                            default:
                                $fields = GenericApiEngine::getRequiredFields();
                        }
                        echo json_encode(['status' => 'success', 'data' => $fields]);
                        break;

                    default:
                        echo json_encode(['status' => 'error', 'message' => 'Unknown aggregator action']);
                }
                break;
            }
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
