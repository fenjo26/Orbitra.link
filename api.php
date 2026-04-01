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
require_once 'version.php';
require_once __DIR__ . '/core/backorder.php';
require_once __DIR__ . '/core/keitaro_import.php';

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
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

// Rate Limiting fallback implementation
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

// === AUTHENTICATION MIDDLEWARE & CSRF ===
$publicActions = ['login', 'check_setup', 'setup_first_user'];

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';

if (!in_array($action, $publicActions)) {
    // Ensure CSRF exists in session
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
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

// === NGINX AUTO-CONFIGURATION ===
/**
 * Check if a command exists on the system
 */
function command_exists($cmd) {
    $return = shell_exec("which $cmd 2>/dev/null");
    return !empty($return);
}

/**
 * Automatically update Nginx server_name directive with all domains from database
 * Called after domain add/delete operations
 */
function updateNginxConfig($pdo) {
    try {
        // Get all active domains from database
        $stmt = $pdo->query("SELECT name FROM domains WHERE name IS NOT NULL AND name != '' ORDER BY name");
        $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($domains)) {
            return ['status' => 'skip', 'message' => 'No domains in database'];
        }

        // Separate domains into SSL and non-SSL
        $sslDomains = [];
        $httpDomains = [];
        foreach ($domains as $domain) {
            $certPath = "/etc/letsencrypt/live/$domain/fullchain.pem";
            if (file_exists($certPath)) {
                $sslDomains[] = $domain;
            } else {
                $httpDomains[] = $domain;
            }
        }

        // Build Nginx configuration
        $config = "# Auto-generated by Orbitra - DO NOT EDIT MANUALLY\n\n";

        // HTTP server block (all domains)
        $allDomains = implode(' ', $domains);
        $config .= "server {\n";
        $config .= "    listen 80;\n";
        $config .= "    server_name $allDomains;\n";
        $config .= "    root /var/www/orbitra;\n";
        $config .= "    index index.php admin.php index.html;\n\n";

        // Access to React/Vite static files
        $config .= "    # Access to React/Vite static files\n";
        $config .= "    location /frontend/dist/ {\n";
        $config .= "        alias /var/www/orbitra/frontend/dist/;\n";
        $config .= "        try_files \$uri \$uri/ /frontend/dist/index.html;\n";
        $config .= "    }\n\n";

        // Router handling (API and clicks)
        $config .= "    # Router handling (API and clicks)\n";
        $config .= "    location / {\n";
        $config .= "        try_files \$uri \$uri/ /index.php?\$query_string;\n";
        $config .= "    }\n\n";

        // Allow large file uploads for Geo DB
        $config .= "    # Allow large file uploads for Geo DB\n";
        $config .= "    client_max_body_size 256m;\n\n";

        // PHP processing
        $config .= "    # PHP processing\n";
        $config .= "    location ~ \.php$ {\n";
        $config .= "        include snippets/fastcgi-php.conf;\n";
        $config .= "        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;\n";
        $config .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
        $config .= "        include fastcgi_params;\n";
        $config .= "    }\n\n";

        // Deny access to SQLite DB and configurations
        $config .= "    # Deny access to SQLite DB and configurations\n";
        $config .= "    location ~ \.sqlite$ {\n";
        $config .= "        deny all;\n";
        $config .= "    }\n";
        $config .= "    location ~ /\. {\n";
        $config .= "        deny all;\n";
        $config .= "    }\n";
        $config .= "}\n\n";

        // HTTPS server blocks (one per SSL domain)
        foreach ($sslDomains as $domain) {
            $config .= "server {\n";
            $config .= "    listen 443 ssl;\n";
            $config .= "    server_name $domain;\n\n";

            // SSL certificates
            $config .= "    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;\n";
            $config .= "    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;\n";
            $config .= "    include /etc/letsencrypt/options-ssl-nginx.conf;\n";
            $config .= "    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;\n\n";

            $config .= "    root /var/www/orbitra;\n";
            $config .= "    index index.php admin.php index.html;\n\n";

            // Access to React/Vite static files
            $config .= "    # Access to React/Vite static files\n";
            $config .= "    location /frontend/dist/ {\n";
            $config .= "        alias /var/www/orbitra/frontend/dist/;\n";
            $config .= "        try_files \$uri \$uri/ /frontend/dist/index.html;\n";
            $config .= "    }\n\n";

            // Router handling (API and clicks)
            $config .= "    # Router handling (API and clicks)\n";
            $config .= "    location / {\n";
            $config .= "        try_files \$uri \$uri/ /index.php?\$query_string;\n";
            $config .= "    }\n\n";

            // Allow large file uploads for Geo DB
            $config .= "    # Allow large file uploads for Geo DB\n";
            $config .= "    client_max_body_size 256m;\n\n";

            // PHP processing
            $config .= "    # PHP processing\n";
            $config .= "    location ~ \.php$ {\n";
            $config .= "        include snippets/fastcgi-php.conf;\n";
            $config .= "        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;\n";
            $config .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
            $config .= "        include fastcgi_params;\n";
            $config .= "    }\n\n";

            // Deny access to SQLite DB and configurations
            $config .= "    # Deny access to SQLite DB and configurations\n";
            $config .= "    location ~ \.sqlite$ {\n";
            $config .= "        deny all;\n";
            $config .= "    }\n";
            $config .= "    location ~ /\. {\n";
            $config .= "        deny all;\n";
            $config .= "    }\n";
            $config .= "}\n\n";
        }

        // Nginx config path
        $nginxConfig = '/etc/nginx/sites-available/orbitra';

        // Check if config file exists
        if (!file_exists($nginxConfig)) {
            return ['status' => 'error', 'message' => 'Nginx config not found at ' . $nginxConfig];
        }

        // Read existing config to compare
        $currentConfig = file_get_contents($nginxConfig);

        // Check if config changed
        if (trim($config) === trim($currentConfig)) {
            return ['status' => 'skip', 'message' => 'Config unchanged'];
        }

        // Write updated config to temp file first
        $tempConfig = $nginxConfig . '.tmp';
        $result = @file_put_contents($tempConfig, $config);

        // Fallback: if direct write fails, use sudo
        if ($result === false) {
            $tempConfig = '/tmp/orbitra_nginx_update.conf';
            file_put_contents($tempConfig, $config);
            @shell_exec("sudo cp $tempConfig $nginxConfig");
            @unlink($tempConfig);
            $tempConfig = $nginxConfig; // Config already copied
        }

        // Test nginx config
        $testResult = @shell_exec('sudo nginx -t 2>&1');
        if (strpos($testResult, 'successful') === false && strpos($testResult, 'test is successful') === false) {
            @unlink($tempConfig);
            return ['status' => 'error', 'message' => 'Nginx config test failed: ' . $testResult];
        }

        // Replace original config (if using temp file in same directory)
        if (file_exists($tempConfig) && $tempConfig !== $nginxConfig) {
            rename($tempConfig, $nginxConfig);
        }

        // Reload nginx via sudo
        $reloadResult = @shell_exec('sudo systemctl reload nginx 2>&1');
        $reloadSuccess = ($reloadResult === null || strpos($reloadResult, 'failed') === false);

        $sslCount = count($sslDomains);
        $httpCount = count($httpDomains);

        if ($reloadSuccess) {
            $message = "Nginx updated: $httpCount HTTP + $sslCount HTTPS domains";
            return ['status' => 'success', 'message' => $message];
        } else {
            return ['status' => 'pending', 'message' => 'Config updated, but nginx reload failed. Run: sudo systemctl reload nginx'];
        }
    } catch (\Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Install SSL certificate for a single domain using Certbot
 * Tries synchronous first (user just clicked save), falls back to background
 */
function installSslForDomain($domain) {
    // Check if certbot is available
    if (!command_exists('certbot')) {
        return false;
    }

    // Check if SSL already exists for this domain
    $certPath = "/etc/letsencrypt/live/$domain/cert.pem";
    if (file_exists($certPath)) {
        return false; // Already has SSL
    }

    // Try synchronous first (user just enabled HTTPS-only, they're waiting)
    $cmd = "sudo certbot --nginx -n -d $domain --agree-tos --register-unsafely-without-email 2>&1";
    $output = @shell_exec($cmd);

    // Check if successful
    if ($output && strpos($output, 'Successfully received certificate') !== false) {
        // Reload nginx to apply SSL config
        @shell_exec('sudo systemctl reload nginx 2>&1');
        return true;
    }

    // If failed or no output, run in background
    $cmd = "sudo certbot --nginx -n -d $domain --agree-tos --register-unsafely-without-email > /dev/null 2>&1 &";
    @shell_exec($cmd);

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
            list($whereCl, $paramsCl) = getDashboardFilters('cl.');
            // Add AND condition if WHERE already exists, else start with WHERE
            $joinCondition = !empty($whereCl) ? str_replace("WHERE ", "AND ", $whereCl) : "";

            $limitClause = isset($_GET['limit']) ? "LIMIT " . (int) $_GET['limit'] : "";
            $havingClause = isset($_GET['limit']) ? "HAVING clicks > 0" : "";

            $stmt = $pdo->prepare("
                SELECT c.*, 
                       cg.name as group_name,
                       ts.name as source_name,
                       COUNT(cl.id) as clicks, 
                       COUNT(DISTINCT cl.ip) as unique_clicks,
                       COALESCE(SUM(cl.is_conversion), 0) as conversions
                FROM campaigns c
                LEFT JOIN campaign_groups cg ON c.group_id = cg.id
                LEFT JOIN traffic_sources ts ON c.source_id = ts.id
                LEFT JOIN clicks cl ON c.id = cl.campaign_id $joinCondition
                WHERE c.is_archived = 0
                GROUP BY c.id
                $havingClause
                ORDER BY clicks DESC, c.created_at DESC
                $limitClause
            ");
            $stmt->execute($paramsCl);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
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

            echo json_encode(['status' => 'success', 'data' => $campaign]);
            break;

        case 'save_campaign':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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
                                    rotation_type=?, token=?, catch_404_stream_id=?
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
                                $id
                            ]);
                        } else {
                            // Don't wipe token if UI doesn't include it.
                            $stmt = $pdo->prepare("
                                UPDATE campaigns 
                                SET name=?, alias=?, domain_id=?, group_id=?, source_id=?, 
                                    cost_model=?, cost_value=?, uniqueness_method=?, uniqueness_hours=?, 
                                    rotation_type=?, catch_404_stream_id=?
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
                                $id
                            ]);
                        }
                    } else {
                        if ($token === null) {
                            $token = $generateCampaignToken();
                        }
                        $stmt = $pdo->prepare("
                            INSERT INTO campaigns 
                            (name, alias, domain_id, group_id, source_id, cost_model, cost_value, uniqueness_method, uniqueness_hours, rotation_type, token, catch_404_stream_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                            $catch404StreamId
                        ]);
                        $id = $pdo->lastInsertId();
                    }

                    // Backfill token for older campaigns where it may be NULL/empty.
                    $pdo->prepare("UPDATE campaigns SET token = ? WHERE id = ? AND (token IS NULL OR token = '')")
                        ->execute([$generateCampaignToken(), (int) $id]);

                    // For MVP: delete old streams and insert new ones
                    $pdo->prepare("DELETE FROM streams WHERE campaign_id = ?")->execute([$id]);

                    $stmtStream = $pdo->prepare("
                        INSERT INTO streams (campaign_id, offer_id, weight, is_active, type, position, filters_json, schema_type, action_payload, schema_custom_json)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                            $str['schema_type'] ?? 'redirect',
                            $str['action_payload'] ?? '',
                            json_encode($str['schema_custom'] ?? [])
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
            $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                                position, filters_json, schema_type, action_payload, schema_custom_json, name
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $newCampaignId, $stream['offer_id'], $stream['weight'],
                            $stream['is_active'], $stream['type'], $stream['position'],
                            $stream['filters_json'], $stream['schema_type'],
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

        case 'campaign_groups':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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

        case 'traffic_sources':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $name = $data['name'] ?? '';
                $template = $data['template'] ?? '';
                $postbackUrl = $data['postback_url'] ?? '';
                $postbackStatuses = $data['postback_statuses'] ?? 'lead,sale';
                $parametersJson = json_encode($data['parameters'] ?? []);
                $notes = $data['notes'] ?? '';
                $state = $data['state'] ?? 'active';

                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Name is required']);
                    break;
                }

                try {
                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE traffic_sources SET name=?, template=?, postback_url=?, postback_statuses=?, parameters_json=?, notes=?, state=? WHERE id=?");
                        $stmt->execute([$name, $template, $postbackUrl, $postbackStatuses, $parametersJson, $notes, $state, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO traffic_sources (name, template, postback_url, postback_statuses, parameters_json, notes, state) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $template, $postbackUrl, $postbackStatuses, $parametersJson, $notes, $state]);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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

        case 'traffic_source_templates':
            // Pre-defined templates for popular traffic sources
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
                    'name' => 'tiktok',
                    'display_name' => 'TikTok Ads',
                    'postback_url' => '',
                    'parameters' => [
                        ['alias' => 'campaign_id', 'param' => 'campaign_id', 'macro' => '__CAMPAIGN_ID__'],
                        ['alias' => 'adgroup_id', 'param' => 'adgroup_id', 'macro' => '__AID__'],
                        ['alias' => 'ad_id', 'param' => 'ad_id', 'macro' => '__CID__'],
                        ['alias' => 'creative', 'param' => 'creative', 'macro' => '__CREATIVE_ID__'],
                        ['alias' => 'pixel', 'param' => 'pixel', 'macro' => '__PIXEL__'],
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
                    'name' => 'custom',
                    'display_name' => 'Свой источник',
                    'postback_url' => '',
                    'parameters' => []
                ],
            ];
            echo json_encode(['status' => 'success', 'data' => $templates]);
            break;

        case 'landing_groups':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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

        case 'landings':
            list($whereCl, $paramsCl) = getDashboardFilters('cl.');
            $joinCondition = !empty($whereCl) ? str_replace("WHERE ", "AND ", $whereCl) : "";
            $limitClause = isset($_GET['limit']) ? "LIMIT " . (int) $_GET['limit'] : "";
            $orderBy = isset($_GET['limit']) ? "ORDER BY clicks DESC, l.id DESC" : "ORDER BY l.id DESC";
            $havingClause = isset($_GET['limit']) ? "HAVING clicks > 0" : "";

            // Expanded to include metrics similarly to offers/campaigns
            $stmt = $pdo->prepare("
                SELECT l.id, l.name, l.type, l.url, l.state, lg.name as group_name,
                       COUNT(cl.id) as clicks, 
                       COUNT(DISTINCT cl.ip) as unique_clicks,
                       COALESCE(SUM(cl.is_conversion), 0) as conversions
                FROM landings l
                LEFT JOIN landing_groups lg ON l.group_id = lg.id
                LEFT JOIN clicks cl ON (l.id = cl.landing_id OR (cl.landing_id IS (NULL) AND cl.id = 'NO_DIRECT_LINK_YET')) $joinCondition
                WHERE l.is_archived = 0
                GROUP BY l.id
                $havingClause
                $orderBy
                $limitClause
            ");
            $stmt->execute($paramsCl);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        // Simple landings list for dropdowns (no heavy joins with clicks table)
        case 'landings_simple':
            $stmt = $pdo->query("
                SELECT l.id, l.name, l.state, l.type
                FROM landings l
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
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (!empty($data['name'])) {
                    $id = $data['id'] ?? null;
                    $groupId = !empty($data['group_id']) ? $data['group_id'] : null;
                    $type = $data['type'] ?? 'local';
                    $url = $data['url'] ?? null;
                    $actionPayload = $data['action_payload'] ?? null;
                    $state = $data['state'] ?? 'active';

                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE landings SET name=?, group_id=?, type=?, url=?, action_payload=?, state=? WHERE id=?");
                        $stmt->execute([$data['name'], $groupId, $type, $url, $actionPayload, $state, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO landings (name, group_id, type, url, action_payload, state) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$data['name'], $groupId, $type, $url, $actionPayload, $state]);
                        $id = $pdo->lastInsertId();
                    }
                    echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing name']);
                }
            }
            break;

        case 'delete_landing':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $id = $_POST['id'] ?? null;
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing Landing ID']);
                    break;
                }
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['status' => 'error', 'message' => 'File upload error']);
                    break;
                }

                // Security: Check file size (< 50MB)
                if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
                    echo json_encode(['status' => 'error', 'message' => 'File too large (max 50MB)']);
                    break;
                }

                $zipFile = $_FILES['file']['tmp_name'];

                // Security: Check MIME type
                $allowedMimes = ['application/zip', 'application/x-zip-compressed'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $zipFile);
                // finfo_close() is deprecated since PHP 8.5 - resources are auto-freed

                if (!in_array($mimeType, $allowedMimes)) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only ZIP allowed.']);
                    break;
                }

                $uploadDir = __DIR__ . '/landings/' . $id . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    // Security: Verify contents before extraction
                    $safeToExtract = true;
                    $errorMsg = '';
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        // Deny PHP files
                        if (preg_match('/\.(php|phtml|php5|php7)$/i', $filename)) {
                            $safeToExtract = false;
                            $errorMsg = 'PHP files not allowed';
                            break;
                        }
                        // Deny path traversal inside zip
                        if (strpos($filename, '..') !== false || strpos($filename, '/') === 0) {
                            $safeToExtract = false;
                            $errorMsg = 'Invalid filename in archive';
                            break;
                        }
                    }

                    if ($safeToExtract) {
                        $zip->extractTo($uploadDir);
                        echo json_encode(['status' => 'success', 'message' => 'Files extracted successfully']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => $errorMsg]);
                    }
                    $zip->close();
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to open ZIP file']);
                }
            }
            break;

        case 'landing_files':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $dir = __DIR__ . '/landings/' . $id;
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
                // Security: Normalize path and prevent traversal
                $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
                $path = preg_replace('/\.\.+/', '', $path);

                $fullPath = realpath(__DIR__ . '/landings/' . $id . '/' . ltrim($path, '/'));
                $allowedPath = realpath(__DIR__ . '/landings/' . $id . '/');

                if ($fullPath === false || strpos($fullPath, $allowedPath) !== 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                    break;
                }

                $file = $fullPath;
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

        case 'save_landing_file':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $id = $data['id'] ?? null;
                $path = $data['path'] ?? null;
                $content = $data['content'] ?? '';

                if ($id && $path) {
                    // Security: Normalize path and prevent traversal
                    $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
                    $path = preg_replace('/\.\.+/', '', $path);

                    $fullPath = realpath(__DIR__ . '/landings/' . $id . '/' . ltrim($path, '/'));
                    $allowedPath = realpath(__DIR__ . '/landings/' . $id . '/');

                    if ($fullPath === false || strpos($fullPath, $allowedPath) !== 0) {
                        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                        break;
                    }

                    $file = $fullPath;
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
            $offerClickRevenueExpression = "0";
            if ($conversionsValueColumn !== null) {
                $offerClickRevenueExpression = "COALESCE((SELECT SUM($conversionsValueColumn) FROM conversions cv WHERE cv.click_id = cl.id), 0)";
            }

            // Полный список офферов со статистикой
            $stmt = $pdo->prepare("
                SELECT id, name, group_id, affiliate_network_id, url, redirect_type, 
                       is_local, geo, payout_type, payout_value, payout_auto, 
                       allow_rebills, capping_limit, capping_timezone, alt_offer_id, 
                       notes, state, created_at, group_name, affiliate_network_name,
                       COUNT(click_id) as clicks, 
                       COUNT(DISTINCT click_ip) as unique_clicks,
                       COALESCE(SUM(is_conversion), 0) as conversions,
                       SUM(click_revenue) as revenue
                FROM (
                    SELECT o.id, o.name, o.group_id, o.affiliate_network_id, o.url, o.redirect_type, 
                           o.is_local, o.geo, o.payout_type, o.payout_value, o.payout_auto, 
                           o.allow_rebills, o.capping_limit, o.capping_timezone, o.alt_offer_id, 
                           o.notes, o.state, o.created_at,
                           og.name as group_name,
                           an.name as affiliate_network_name,
                           cl.id as click_id,
                           cl.ip as click_ip,
                           cl.is_conversion as is_conversion,
                           $offerClickRevenueExpression as click_revenue
                    FROM offers o
                    LEFT JOIN offer_groups og ON o.group_id = og.id
                    LEFT JOIN affiliate_networks an ON o.affiliate_network_id = an.id
                    LEFT JOIN clicks cl ON o.id = cl.offer_id $joinCondition
                    WHERE o.is_archived = 0
                )
                GROUP BY id
                $havingClause
                $orderBy
                $limitClause
            ");
            $stmt->execute($paramsCl);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            break;

        case 'all_offers':
            $stmt = $pdo->query("SELECT id, name, url, state FROM offers WHERE state = 'active' ORDER BY name ASC");
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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
                    'display_name' => 'Универсальная',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'leadbit',
                    'display_name' => 'Leadbit',
                    'offer_params_template' => '&sub1={subid}&sub2={ip}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'm4leads',
                    'display_name' => 'M4Leads',
                    'offer_params_template' => '&s={subid}',
                    'postback_url_template' => ''
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
                    'name' => 'melbetaffiliate',
                    'display_name' => 'MelbetAffiliate',
                    'offer_params_template' => '&subid={subid}',
                    'postback_url_template' => ''
                ],
                [
                    'name' => 'custom',
                    'display_name' => 'Своя сеть',
                    'offer_params_template' => '',
                    'postback_url_template' => ''
                ],
            ];
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
            // Fix broken Nginx configuration from Certbot
            $configPath = '/etc/nginx/sites-available/orbitra';
            $fixed = false;

            // Check if config is broken (missing listen 80 or has return 404)
            $configContent = @file_get_contents($configPath);
            if ($configContent) {
                $needsFix = false;

                // Check for "return 404" (Certbot's broken config)
                if (strpos($configContent, 'return 404') !== false) {
                    $needsFix = true;
                }

                // Check for missing "listen 80" in first server block
                $lines = explode("\n", $configContent);
                $inFirstServer = false;
                $foundListen80 = false;
                foreach ($lines as $line) {
                    if (preg_match('/^\s*server\s*\{/', $line)) {
                        if (!$inFirstServer) {
                            $inFirstServer = true;
                        }
                    } elseif ($inFirstServer && preg_match('/^\s*\}/', $line)) {
                        break; // End of first server block
                    } elseif ($inFirstServer && preg_match('/listen\s+80/', $line)) {
                        $foundListen80 = true;
                        break;
                    }
                }

                if (!$foundListen80) {
                    $needsFix = true;
                }

                // Fix if needed
                if ($needsFix) {
                    $newConfig = "# Auto-generated by Orbitra - DO NOT EDIT MANUALLY\n\n";
                    $newConfig .= "server {\n";
                    $newConfig .= "    listen 80 default_server;\n";
                    $newConfig .= "    server_name _;\n";
                    $newConfig .= "    root /var/www/orbitra;\n";
                    $newConfig .= "    index index.php admin.php index.html;\n\n";

                    $newConfig .= "    # Access to React/Vite static files\n";
                    $newConfig .= "    location /frontend/dist/ {\n";
                    $newConfig .= "        alias /var/www/orbitra/frontend/dist/;\n";
                    $newConfig .= "        try_files \$uri \$uri/ /frontend/dist/index.html;\n";
                    $newConfig .= "    }\n\n";

                    $newConfig .= "    # Router handling (API and clicks)\n";
                    $newConfig .= "    location / {\n";
                    $newConfig .= "        try_files \$uri \$uri/ /index.php?\$query_string;\n";
                    $newConfig .= "    }\n\n";

                    $newConfig .= "    # Allow large file uploads for Geo DB\n";
                    $newConfig .= "    client_max_body_size 256m;\n\n";

                    $newConfig .= "    # PHP processing\n";
                    $newConfig .= "    location ~ \.php$ {\n";
                    $newConfig .= "        include snippets/fastcgi-php.conf;\n";
                    $newConfig .= "        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;\n";
                    $newConfig .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
                    $newConfig .= "        include fastcgi_params;\n";
                    $newConfig .= "    }\n\n";

                    $newConfig .= "    # Deny access to SQLite DB and configurations\n";
                    $newConfig .= "    location ~ \.sqlite$ {\n";
                    $newConfig .= "        deny all;\n";
                    $newConfig .= "    }\n";
                    $newConfig .= "    location ~ /\. {\n";
                    $newConfig .= "        deny all;\n";
                    $newConfig .= "    }\n";
                    $newConfig .= "}\n";

                    // Write to temp file first, then copy via sudo
                    $tempConfig = '/tmp/orbitra_nginx_fix.conf';
                    file_put_contents($tempConfig, $newConfig);
                    @shell_exec("sudo cp $tempConfig $configPath");
                    @unlink($tempConfig);

                    // Reload nginx
                    @shell_exec('sudo systemctl reload nginx 2>&1');

                    $fixed = true;
                }
            }

            if ($fixed) {
                echo json_encode(['status' => 'success', 'message' => 'Nginx config fixed']);
            } else {
                echo json_encode(['status' => 'skip', 'message' => 'Config looks OK']);
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
            $crontabPath = $shellExecAllowed ? trim((string) @shell_exec('command -v crontab 2>/dev/null')) : '';
            $crontabAvailable = $crontabPath !== '';
            $userCrontabInstalled = 0;
            if ($crontabAvailable) {
                $existing = (string) @shell_exec('crontab -l 2>/dev/null');
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

            $crontabPath = trim((string) @shell_exec('command -v crontab 2>/dev/null'));
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

            $phpPath = trim((string) @shell_exec('command -v php 2>/dev/null'));
            if ($phpPath === '') {
                $phpPath = 'php';
            }

            $line = "*/3 * * * * $phpPath " . escapeshellarg($scriptPath) . " >> " . escapeshellarg($logPath) . " 2>&1";
            $block = "# ORBITRA_BACKORDER_BEGIN\n" . $line . "\n# ORBITRA_BACKORDER_END\n";

            $existing = (string) @shell_exec('crontab -l 2>/dev/null');
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
            $out = (string) @shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
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

            $crontabPath = trim((string) @shell_exec('command -v crontab 2>/dev/null'));
            if ($crontabPath === '') {
                echo json_encode(['status' => 'error', 'message' => 'crontab command not found']);
                break;
            }

            $existing = (string) @shell_exec('crontab -l 2>/dev/null');
            $new = preg_replace("/\\n?# ORBITRA_BACKORDER_BEGIN[\\s\\S]*?# ORBITRA_BACKORDER_END\\n?/m", "\n", $existing);
            $new = trim((string) $new) . "\n";

            $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_crontab_');
            if (!is_string($tmp) || $tmp === '') {
                echo json_encode(['status' => 'error', 'message' => 'Failed to create temp file']);
                break;
            }
            @file_put_contents($tmp, $new);
            $out = (string) @shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
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

            $phpPath = trim((string) @shell_exec('command -v php 2>/dev/null'));
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

            $data = json_decode(file_get_contents('php://input'), true);
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

            $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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

            $data = json_decode(file_get_contents('php://input'), true);
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

            $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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

                        // If HTTPS-only was just enabled, queue SSL installation
                        $sslQueued = false;
                        if ($httpsOnly) {
                            // Check if SSL already exists
                            $certPath = "/etc/letsencrypt/live/$name/cert.pem";
                            if (!file_exists($certPath)) {
                                // Update ssl_status to pending
                                $pdo->prepare("UPDATE domains SET ssl_status = 'pending', ssl_error = NULL WHERE id = ?")->execute([$id]);
                                $sslQueued = true;
                            } else {
                                // SSL already exists, mark as installed
                                $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")->execute([$id]);
                            }

                            // Start background SSL installer if needed
                            if ($sslQueued) {
                                $cliPath = __DIR__ . '/cli/ssl_installer.php';
                                if (file_exists($cliPath)) {
                                    shell_exec("php $cliPath > /dev/null 2>&1 &");
                                }
                            }
                        } else {
                            // HTTPS-only disabled, reset SSL status
                            $pdo->prepare("UPDATE domains SET ssl_status = 'none', ssl_error = NULL WHERE id = ?")->execute([$id]);
                        }

                        // Update Nginx configuration
                        $nginxResult = updateNginxConfig($pdo);

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

                            // Determine initial SSL status
                            $sslStatus = $httpsOnly ? 'pending' : 'none';

                            try {
                                $stmt = $pdo->prepare("INSERT INTO domains (name, index_campaign_id, catch_404, group_id, is_noindex, https_only, ssl_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$domainName, $indexCampId, $catch404, $groupId, $isNoindex, $httpsOnly, $sslStatus]);
                                $newId = $pdo->lastInsertId();
                                $results[] = ['id' => $newId, 'name' => $domainName];

                                if ($httpsOnly) {
                                    $sslPending = true;
                                }

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

                        // Start background SSL installer if any domains need HTTPS
                        if ($sslPending) {
                            $cliPath = __DIR__ . '/cli/ssl_installer.php';
                            if (file_exists($cliPath)) {
                                shell_exec("php $cliPath > /dev/null 2>&1 &");
                            }
                        }

                        // Update Nginx configuration
                        $nginxResult = updateNginxConfig($pdo);

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
                $data = json_decode(file_get_contents('php://input'), true);
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

        case 'check_ssl_status':
            // Check SSL installation status for all HTTPS-only domains
            try {
                $stmt = $pdo->query("SELECT id, name, https_only, ssl_status, ssl_error FROM domains ORDER BY id DESC");
                $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Filter to only include HTTPS-only domains with status
                $httpsDomains = array_filter($domains, function($d) {
                    return $d['https_only'] == 1;
                });

                echo json_encode([
                    'status' => 'success',
                    'data' => array_values($httpsDomains)
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
                $campaignId = $data['campaign_id'] ?? null;
                $ip = $data['ip'] ?? '127.0.0.1';
                $userAgent = $data['user_agent'] ?? 'Mozilla/5.0';
                $country = $data['country'] ?? 'US';
                $deviceType = $data['device_type'] ?? 'desktop';
                $acceptLanguageRaw = trim((string) ($data['accept_language'] ?? ($data['language'] ?? 'en')));
                $languageCodes = extractBrowserLanguageCodes($acceptLanguageRaw);
                $language = $languageCodes[0] ?? 'unknown';

                $trace = [];
                $trace[] = "Start simulation for Campaign ID: $campaignId";
                $trace[] = "Context -> IP: $ip, UA: $userAgent, Country: $country, Device: $deviceType, Primary Language: $language";
                $trace[] = "Accept-Language raw: " . ($acceptLanguageRaw !== '' ? $acceptLanguageRaw : '-');
                $trace[] = "Parsed browser languages: " . (!empty($languageCodes) ? implode(', ', $languageCodes) : 'none');

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
                    function streamMatchesFiltersSim($stream, $ip, $country, $deviceType, $languageCodes, &$trace)
                    {
                        if (empty($stream['filters_json']))
                            return true;
                        $filters = json_decode($stream['filters_json'], true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($filters) || empty($filters))
                            return true;

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
                            else
                                $matched = true;

                            if ($mode === 'include' && !$matched) {
                                $trace[] = "  [Filter Failed] Stream '{$stream['name']}' requires {$f['name']} IN " . implode(',', $payload);
                                return false;
                            }
                            if ($mode === 'exclude' && $matched) {
                                $trace[] = "  [Filter Failed] Stream '{$stream['name']}' excludes {$f['name']} IN " . implode(',', $payload);
                                return false;
                            }
                        }
                        return true;
                    }
                }

                $selectedStream = null;
                $trace[] = "Evaluating Intercepting streams...";
                foreach ($allStreams as $stream) {
                    if (($stream['type'] ?? 'regular') === 'intercepting') {
                        if (streamMatchesFiltersSim($stream, $ip, $country, $deviceType, $languageCodes, $trace)) {
                            $selectedStream = $stream;
                            $trace[] = "=> MATCHED Intercepting Stream: " . $stream['name'];
                            break;
                        }
                    }
                }

                if (!$selectedStream) {
                    $trace[] = "Evaluating Regular streams...";
                    $regular = array_filter($allStreams, fn($s) => ($s['type'] ?? 'regular') === 'regular' && streamMatchesFiltersSim($s, $ip, $country, $deviceType, $languageCodes, $trace));

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
            $campaign_id = (int) ($_GET['campaign_id'] ?? 0);
            $date_from = $_GET['date_from'] ?? null;
            $date_to = $_GET['date_to'] ?? null;
            $group_by = $_GET['group_by'] ?? 'country';

            // Validate `group_by` to prevent SQL injection
            $allowed_dimensions = [
                'country' => 'clicks.country',
                'device_type' => 'clicks.device_type',
                'language' => 'clicks.language',
                'stream_id' => 'clicks.stream_id',
                'source_id' => 'clicks.source_id',
            ];
            for ($i = 1; $i <= 5; $i++) {
                $allowed_dimensions["sub_id_$i"] = "json_extract(clicks.parameters_json, '$.sub_id_$i')";
            }

            if (!array_key_exists($group_by, $allowed_dimensions)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid group_by parameter']);
                break;
            }

            $dim_sql = $allowed_dimensions[$group_by];
            $conds = ["clicks.campaign_id = ?"];
            $params = [$campaign_id];

            if ($date_from) {
                $conds[] = "date(clicks.created_at) >= date(?)";
                $params[] = $date_from;
            }
            if ($date_to) {
                $conds[] = "date(clicks.created_at) <= date(?)";
                $params[] = $date_to;
            }

            $where = implode(' AND ', $conds);
            $conversionsValueColumn = getConversionsValueColumn($pdo);
            $campaignRevenueExpression = "0";
            if ($conversionsValueColumn !== null) {
                $campaignRevenueExpression = "COALESCE((SELECT SUM($conversionsValueColumn) FROM conversions WHERE click_id = clicks.id), 0)";
            }
            $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);
            $campaignRealRevenueExpression = "0";
            if ($revenueRecordsValueColumn !== null) {
                $campaignRealRevenueExpression = "COALESCE((SELECT SUM($revenueRecordsValueColumn) FROM revenue_records WHERE click_id = clicks.id), 0)";
            }

            $sql = "
                SELECT 
                    dimension_name,
                    COUNT(click_id) as clicks,
                    COUNT(DISTINCT click_ip) as unique_clicks,
                    SUM(is_conversion) as conversions,
                    SUM(click_revenue) as revenue,
                    SUM(click_real_revenue) as real_revenue
                FROM (
                    SELECT COALESCE($dim_sql, 'Unknown') as dimension_name,
                           clicks.id as click_id,
                           clicks.ip as click_ip,
                           clicks.is_conversion,
                           $campaignRevenueExpression as click_revenue,
                           $campaignRealRevenueExpression as click_real_revenue
                    FROM clicks
                    WHERE $where
                )
                GROUP BY dimension_name
                ORDER BY clicks DESC
                LIMIT 500
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Cost and Profit (simplified, assuming 0 cost as per earlier logic)
            foreach ($rows as &$r) {
                $r['cost'] = 0.00;
                $r['profit'] = (float) $r['revenue'] - $r['cost'];
                $r['real_profit'] = (float) $r['real_revenue'] - $r['cost'];
                $r['cr'] = $r['clicks'] > 0 ? round(($r['conversions'] / $r['clicks']) * 100, 2) : 0;
                $r['epc'] = $r['clicks'] > 0 ? round(((float) $r['revenue'] / $r['clicks']), 4) : 0;
                $r['real_epc'] = $r['clicks'] > 0 ? round(((float) $r['real_revenue'] / $r['clicks']), 4) : 0;
                $r['real_roi'] = $r['cost'] > 0 ? round(((float) $r['real_profit'] / $r['cost']) * 100, 2) : ($r['real_profit'] > 0 ? 100 : 0);

                // Fetch Stream/Source names instead of IDs if grouped by them
                if ($group_by === 'stream_id' && is_numeric($r['dimension_name'])) {
                    $st_q = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
                    $st_q->execute([$r['dimension_name']]);
                    if ($st_name = $st_q->fetchColumn()) {
                        $r['dimension_name'] = $st_name;
                    }
                } else if ($group_by === 'source_id' && is_numeric($r['dimension_name'])) {
                    $st_q = $pdo->prepare("SELECT name FROM traffic_sources WHERE id = ?");
                    $st_q->execute([$r['dimension_name']]);
                    if ($st_name = $st_q->fetchColumn()) {
                        $r['dimension_name'] = $st_name;
                    }
                }
            }

            echo json_encode(['status' => 'success', 'data' => $rows]);
            break;

        case 'global_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('postback_key', 'currency', 'maxmind_license_key', 'maxmind_account_id', 'ip2location_token')");
                $data = [];
                while ($row = $stmt->fetch()) {
                    $data[$row['key']] = $row['value'];
                }
                echo json_encode(['status' => 'success', 'data' => $data]);
            } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $settings = $input['settings'] ?? [];
                if (!empty($settings)) {
                    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
                    foreach (['postback_key', 'currency', 'maxmind_license_key', 'maxmind_account_id', 'ip2location_token'] as $key) {
                        if (isset($settings[$key])) {
                            $stmt->execute([$key, $settings[$key]]);
                        }
                    }
                }
                echo json_encode(['status' => 'success']);
            }
            break;



        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);

                try {
                    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");

                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            $value = json_encode($value);
                        }
                        $stmt->execute([$key, $value]);
                    }

                    echo json_encode(['status' => 'success']);
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
                    $cpuCores = (int) shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: 1;
                } elseif (PHP_OS_FAMILY === 'Windows') {
                    $cpuCores = (int) shell_exec('echo %NUMBER_OF_PROCESSORS%') ?: 1;
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
                ];

                // SQLite info
                $sqliteVersion = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                $sqliteJournalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
                $sqliteSynchronous = $pdo->query('PRAGMA synchronous')->fetchColumn();

                // Geo databases status
                $geoDbs = [];
                $sypexFile = __DIR__ . '/var/geoip/SxGeoCity/SxGeoCity.dat';
                $maxmindFile = __DIR__ . '/geo/GeoLite2-City.mmdb';
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

            // URL to check for latest version (change to your server or GitHub raw file)
            // Example for GitHub: 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/version.json'
            $versionCheckUrl = 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/version.json';

            $latestVersion = $currentVersion; // Default: no update
            $releaseNotes = '';
            $downloadUrl = '';
            $releasedAt = null;

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
                    }
                }
            }

            // Compare versions
            $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

            $updateInfo = [
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'update_available' => $updateAvailable,
                'release_notes' => $releaseNotes,
                'download_url' => $downloadUrl,
                'released_at' => $releasedAt
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

                // Perform a git pull if inside a git repository
                $isGit = is_dir(__DIR__ . '/.git');
                if ($isGit) {
                    $repoDir = __DIR__;
                    $git = 'git -C ' . escapeshellarg($repoDir);

                    // Security: Ensure we are on a safe branch
                    $allowedBranches = ['main', 'master'];
                    $currentBranch = trim(exec($git . ' rev-parse --abbrev-ref HEAD 2>&1'));
                    if (!in_array($currentBranch, $allowedBranches)) {
                        echo json_encode(['status' => 'error', 'message' => 'Unsafe branch: ' . htmlspecialchars($currentBranch)]);
                        break;
                    }

                    // Stash local changes if any, then pull
                    $statusLines = [];
                    $statusReturn = 0;
                    exec($git . ' status --porcelain 2>&1', $statusLines, $statusReturn);
                    $hasLocalChanges = ($statusReturn === 0 && !empty($statusLines));

                    if ($hasLocalChanges) {
                        exec($git . ' stash push -u -m "orbitra-auto-update" 2>&1', $stashOutput, $stashReturn);
                    }

                    $output = [];
                    $returnCode = 0;
                    exec($git . ' pull --ff-only origin ' . escapeshellarg($currentBranch) . ' 2>&1', $output, $returnCode);

                    // If server has no SSH keys, pulling from git@github.com may fail; retry with HTTPS without changing origin.
                    if ($returnCode !== 0) {
                        $joined = strtolower(implode("\n", $output));
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
                                $returnCode = $retryCode;
                            } else {
                                $output[] = '[Hint] Configure origin as https://github.com/<user>/<repo>.git for web-based updates.';
                            }
                        }
                    }

                    // Restore stashed changes after pull
                    if ($hasLocalChanges && $returnCode === 0) {
                        exec($git . ' stash pop 2>&1', $popOutput, $popReturn);
                        if ($popReturn === 0) {
                            $output = array_merge($output, ['[Stash restored]']);
                        } else {
                            $output = array_merge($output, ['[Stash restore failed]'], $popOutput ?? []);
                        }
                    }

                    if ($returnCode === 0) {
                        echo json_encode(['status' => 'success', 'message' => 'Обновлено успешно. Вывод: ' . implode(" ", $output)]);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            $geoDir = __DIR__ . '/var/geoip/SxGeoCity';
            $datFile = $geoDir . '/SxGeoCity.dat';

            $dbs = [];
            // Sypex Geo City Lite
            $sypex = [
                'id' => 'sypex_city_lite',
                'name' => 'Sypex Geo City Lite',
                'type' => 'Country-Region-City',
                'status' => file_exists($datFile) ? 'OK' : 'Нет базы',
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
                'status' => ($ip2locDb && file_exists($ip2locDb)) ? 'OK' : 'Нет базы',
                'updated_at' => ($ip2locDb && file_exists($ip2locDb)) ? date('Y-m-d H:i:s', filemtime($ip2locDb)) : null,
                'size' => ($ip2locDb && file_exists($ip2locDb)) ? filesize($ip2locDb) : 0
            ];
            $dbs[] = $ip2loc;

            // MaxMind GeoLite2-City
            $maxMindDb = __DIR__ . '/geo/GeoLite2-City.mmdb';
            $maxMind = [
                'id' => 'maxmind_city',
                'name' => 'MaxMind GeoLite2-City (Requires License Key)',
                'type' => 'Country-City',
                'status' => file_exists($maxMindDb) ? 'OK' : 'Нет базы',
                'updated_at' => file_exists($maxMindDb) ? date('Y-m-d H:i:s', filemtime($maxMindDb)) : null,
                'size' => file_exists($maxMindDb) ? filesize($maxMindDb) : 0
            ];
            $dbs[] = $maxMind;

            echo json_encode(['status' => 'success', 'data' => $dbs]);
            break;

        case 'geo_db_upload':
            // Manual upload of geo database file
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $dbId = $_POST['db_id'] ?? 'sypex_city_lite';

                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['status' => 'error', 'message' => 'Ошибка загрузки файла']);
                    break;
                }

                $file = $_FILES['file'];
                $fileName = strtolower($file['name']);
                $fileTmp = $file['tmp_name'];
                $fileSize = $file['size'];

                // Validate file
                $allowedExts = ['dat', 'zip', 'bin'];
                $ext = pathinfo($fileName, PATHINFO_EXTENSION);

                if (!in_array($ext, $allowedExts)) {
                    echo json_encode(['status' => 'error', 'message' => 'Разрешены только файлы .dat, .bin и .zip']);
                    break;
                }

                if ($fileSize > 512 * 1024 * 1024) { // 512MB max
                    echo json_encode(['status' => 'error', 'message' => 'Файл слишком большой (макс. 512MB)']);
                    break;
                }

                try {
                    $geoDir = __DIR__ . '/var/geoip/SxGeoCity';
                    if (!is_dir($geoDir)) {
                        mkdir($geoDir, 0777, true);
                    }

                    if ($ext === 'zip') {
                        // Extract ZIP
                        $zip = new ZipArchive;
                        if ($zip->open($fileTmp) === TRUE) {
                            $zipDir = sys_get_temp_dir() . '/upload_' . uniqid();
                            mkdir($zipDir);
                            $zip->extractTo($zipDir);
                            $zip->close();

                            $found = false;
                            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zipDir));
                            foreach ($iter as $file) {
                                if ($file->isFile()) {
                                    $fExt = strtolower($file->getExtension());
                                    if ($fExt === 'dat') {
                                        copy($file->getPathname(), $geoDir . '/SxGeoCity.dat');
                                        logSystem($pdo, 'INFO', 'Sypex Geo DB uploaded via ZIP');
                                        $found = true;
                                    } else if ($fExt === 'bin') {
                                        if (!is_dir(__DIR__ . '/geo'))
                                            mkdir(__DIR__ . '/geo', 0755, true);
                                        copy($file->getPathname(), __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN');
                                        logSystem($pdo, 'INFO', 'IP2Location DB uploaded via ZIP');
                                        $found = true;
                                    } else if ($fExt === 'mmdb') {
                                        if (!is_dir(__DIR__ . '/geo'))
                                            mkdir(__DIR__ . '/geo', 0755, true);
                                        copy($file->getPathname(), __DIR__ . '/geo/GeoLite2-City.mmdb');
                                        logSystem($pdo, 'INFO', 'MaxMind DB uploaded via ZIP');
                                        $found = true;
                                    }
                                }
                            }

                            array_map('unlink', glob("$zipDir/*.*"));
                            @rmdir($zipDir);

                            if ($found) {
                                echo json_encode(['status' => 'success', 'message' => 'Архив распакован, базы обновлены']);
                            } else {
                                echo json_encode(['status' => 'error', 'message' => 'В архиве не найдено подходящих файлов (.dat, .bin, .mmdb)']);
                            }
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Не удалось открыть ZIP архив']);
                        }
                    } else if ($ext === 'bin') {
                        if (!is_dir(__DIR__ . '/geo'))
                            mkdir(__DIR__ . '/geo', 0755, true);
                        if (move_uploaded_file($fileTmp, __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN')) {
                            logSystem($pdo, 'INFO', 'IP2Location DB uploaded directly');
                            echo json_encode(['status' => 'success', 'message' => 'Файл IP2Location загружен успешно']);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить файл .bin']);
                        }
                    } else {
                        // Direct .dat file
                        $destPath = $geoDir . '/SxGeoCity.dat';
                        if (move_uploaded_file($fileTmp, $destPath)) {
                            logSystem($pdo, 'INFO', 'Sypex Geo DB uploaded directly');
                            echo json_encode(['status' => 'success', 'message' => 'Файл загружен успешно']);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить файл']);
                        }
                    }
                } catch (\Exception $e) {
                    logSystem($pdo, 'ERROR', 'Geo DB Upload Error: ' . $e->getMessage());
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            break;

        case 'geo_db_update':
            // Security: limit execution time for downloads
            // set_time_limit(300); // Disabled for PHP-FPM compatibility

            $input = json_decode(file_get_contents('php://input'), true);
            $dbId = $_POST['id'] ?? $input['id'] ?? null;

            if (!in_array($dbId, ['sypex_city_lite', 'maxmind_city', 'ip2location_lite_db11'])) {
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

            if ($dbId === 'ip2location_lite_db11') {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'ip2location_token'");
                $token = $stmt->fetchColumn();
                if (!$token) {
                    echo json_encode(['status' => 'error', 'message' => 'Не указан IP2Location Token. Укажите его в настройках "Гео-базы".']);
                    break;
                }

                $tmpArchive = sys_get_temp_dir() . '/ip2location.zip';
                $variant = 'DB11LITEBINIPV6';
                $url = "https://www.ip2location.com/download?token={$token}&file={$variant}";
                $ch = curl_init($url);
                $fp = @fopen($tmpArchive, 'wb');
                if ($fp === false) {
                    echo json_encode(['status' => 'error', 'message' => 'Не удалось создать временный файл для загрузки. Проверьте права на запись в ' . sys_get_temp_dir()]);
                    break;
                }
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                fclose($fp);

                if ($httpCode !== 200 || !file_exists($tmpArchive) || filesize($tmpArchive) <= 1024) {
                    echo json_encode(['status' => 'error', 'message' => "Не удалось скачать {$variant}. Проверьте токен и квоту IP2Location."]);
                    break;
                }

                // Extract .bin
                $destPath = __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN';
                if (!is_dir(__DIR__ . '/geo')) {
                    mkdir(__DIR__ . '/geo', 0755, true);
                }

                try {
                    $zip = new ZipArchive;
                    if ($zip->open($tmpArchive) === TRUE) {
                        // Extract to temp directory to avoid memory issues
                        $extractDir = sys_get_temp_dir() . '/ip2loc_extract_' . time();
                        mkdir($extractDir, 0755, true);
                        $zip->extractTo($extractDir);
                        $zip->close();

                        // Find and move the BIN file
                        $extracted = false;
                        foreach (glob($extractDir . '/*.BIN') as $file) {
                            if (filesize($file) > 10 * 1024 * 1024) {
                                rename($file, $destPath);
                                $extracted = true;
                                break;
                            }
                        }
                        // Cleanup temp directory
                        system('rm -rf ' . escapeshellarg($extractDir));

                        if ($extracted) {
                            $binSize = filesize($destPath) ?: 0;
                            // DB11 IPv4+IPv6 should be 30-50 MB, check for too small files
                            if ($binSize < 10 * 1024 * 1024) {
                                @unlink($destPath);
                                echo json_encode(['status' => 'error', 'message' => "Скачан неполный IP2Location BIN ({$binSize} bytes). Ожидается DB11 IPv4+IPv6 (id=20)."]);
                                @unlink($tmpArchive);
                                break;
                            }
                            logSystem($pdo, 'INFO', 'IP2Location Geo DB Updated successfully', ['variant' => $variant]);
                            echo json_encode(['status' => 'success', 'message' => "База IP2Location успешно обновлена ({$variant})"]);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Failed to find .BIN in downloaded archive']);
                        }
                    } else {
                        // The file might be a PDF or HTML error page if the download limits were reached.
                        echo json_encode(['status' => 'error', 'message' => 'Failed to open downloaded IP2Location ZIP archive. Token might be invalid or limit reached.']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Extraction failed: ' . $e->getMessage()]);
                }
                @unlink($tmpArchive);
                break;
            }

            if ($dbId === 'maxmind_city') {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'maxmind_license_key'");
                $license_key = $stmt->fetchColumn();

                $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'maxmind_account_id'");
                $account_id = $stmt->fetchColumn();

                if (!$license_key || !$account_id) {
                    echo json_encode(['status' => 'error', 'message' => 'Не указаны MaxMind Account ID и/или License Key. Укажите их в настройках "Гео-базы".']);
                    break;
                }

                // New MaxMind download URL format (2024+)
                $url = "https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz";
                $tmpArchive = sys_get_temp_dir() . '/geolite2-city.tar.gz';

                // Download with Basic Authentication
                $ch = curl_init($url);
                $fp = fopen($tmpArchive, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_USERPWD, $account_id . ':' . $license_key);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                // curl_close() deprecated in PHP 8.5 - resources are auto-freed
                fclose($fp);

                if ($httpCode !== 200) {
                    echo json_encode(['status' => 'error', 'message' => "Failed to download database. HTTP Code: $httpCode. Check your Account ID and License Key."]);
                    break;
                }

                // Extract .mmdb
                $dbFileName = 'GeoLite2-City.mmdb';
                $destPath = __DIR__ . '/geo/' . $dbFileName;
                if (!is_dir(__DIR__ . '/geo')) {
                    mkdir(__DIR__ . '/geo', 0755, true);
                }

                try {
                    $ref = new \ReflectionClass('\PharData');
                    $p = $ref->newInstance($tmpArchive);

                    $extracted = false;
                    foreach (new RecursiveIteratorIterator($p) as $file) {
                        if (str_ends_with($file->getFilename(), '.mmdb')) {
                            copy($file->getPathname(), $destPath);
                            $extracted = true;
                            break;
                        }
                    }
                    if ($extracted) {
                        logSystem($pdo, 'INFO', 'MaxMind Geo DB Updated successfully');
                        echo json_encode(['status' => 'success', 'message' => 'База MaxMind успешно обновлена']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to find .mmdb in downloaded archive']);
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
                        system('rm -rf ' . escapeshellarg($tempDir));

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
                $data = json_decode(file_get_contents('php://input'), true);
                $id = !empty($data['id']) ? (int) $data['id'] : null;
                $username = $data['username'] ?? '';
                $password = $data['password'] ?? '';
                $email = $data['email'] ?? '';
                $role = $data['role'] ?? 'user';
                $language = $data['language'] ?? 'ru';
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
                $userId = $data['user_id'] ?? null;
                $keyName = $data['key_name'] ?? 'API Key';

                if (!$userId) {
                    echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
                    break;
                }

                // Generate random API key
                $apiKey = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("INSERT INTO user_api_keys (user_id, key_name, api_key, permissions) VALUES (?, ?, ?, 'read')");
                $stmt->execute([$userId, $keyName, $apiKey]);

                echo json_encode(['status' => 'success', 'data' => ['api_key' => $apiKey, 'id' => $pdo->lastInsertId()]]);
            }
            break;

        case 'delete_api_key':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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

                $data = json_decode(file_get_contents('php://input'), true);
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
                            'language' => $user['language'] ?? 'ru',
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
                $data = json_decode(file_get_contents('php://input'), true);

                // Check if users already exist
                $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                if ($stmt->fetchColumn() > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Пользователи уже существуют']);
                    break;
                }

                $username = trim($data['username'] ?? '');
                $password = $data['password'] ?? '';
                $timezone = $data['timezone'] ?? 'Europe/Kyiv';
                $language = $data['language'] ?? 'ru';

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
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, is_active, timezone, language) VALUES ('admin', ?, 'admin@localhost', 'admin', 1, 'Europe/Moscow', 'ru')");
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (isset($data['action']) && $data['action'] === 'delete') {
                    $pdo->prepare("DELETE FROM bot_ips WHERE id = ?")->execute([$data['id']]);
                    echo json_encode(['status' => 'success']);
                } elseif (isset($data['action']) && $data['action'] === 'clear_all') {
                    $pdo->exec("DELETE FROM bot_ips");
                    echo json_encode(['status' => 'success']);
                } else {
                    $ips = explode("\n", $data['ips'] ?? '');
                    $stmt = $pdo->prepare("INSERT OR IGNORE INTO bot_ips (ip_or_cidr) VALUES (?)");
                    $added = 0;
                    foreach ($ips as $ip) {
                        $ip = trim($ip);
                        if ($ip) {
                            $stmt->execute([$ip]);
                            if ($stmt->rowCount() > 0)
                                $added++;
                        }
                    }
                    echo json_encode(['status' => 'success', 'added' => $added]);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM bot_ips ORDER BY id DESC LIMIT 1000"); // Limit for performance if huge
                $total = $pdo->query("SELECT COUNT(*) as c FROM bot_ips")->fetch()['c'];
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(), 'total' => $total]);
            }
            break;

        case 'bot_signatures':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                if (isset($data['action']) && $data['action'] === 'delete') {
                    $pdo->prepare("DELETE FROM bot_signatures WHERE id = ?")->execute([$data['id']]);
                    echo json_encode(['status' => 'success']);
                } elseif (isset($data['action']) && $data['action'] === 'clear_all') {
                    $pdo->exec("DELETE FROM bot_signatures");
                    echo json_encode(['status' => 'success']);
                } else {
                    $sigs = explode("\n", $data['signatures'] ?? '');
                    $stmt = $pdo->prepare("INSERT OR IGNORE INTO bot_signatures (signature) VALUES (?)");
                    $added = 0;
                    foreach ($sigs as $sig) {
                        $sig = trim($sig);
                        if ($sig) {
                            $stmt->execute([$sig]);
                            if ($stmt->rowCount() > 0)
                                $added++;
                        }
                    }
                    echo json_encode(['status' => 'success', 'added' => $added]);
                }
            } else {
                $stmt = $pdo->query("SELECT * FROM bot_signatures ORDER BY id DESC LIMIT 1000");
                $total = $pdo->query("SELECT COUNT(*) as c FROM bot_signatures")->fetch()['c'];
                echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(), 'total' => $total]);
            }
            break;

        case 'profile_settings':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $userId = $data['user_id'] ?? 1; // Defaulting to 1 for MVP single-user setup
                $lang = $data['language'] ?? 'ru';
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
            $data = json_decode(file_get_contents('php://input'), true);
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

            // Calculate derived metrics and format data
            $tableData = [];
            $chartLabels = [];
            $chartDatasets = [];

            // Initialize dataset arrays for each metric
            $metricData = [];
            foreach ($selectedMetrics as $m) {
                $metricData[$m] = [];
            }

            foreach ($rows as $row) {
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

        case 'run_migration':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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

                $lang = $chat['language'] ?? 'ru';
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
                $data = json_decode(file_get_contents('php://input'), true);
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

                if ($id) {
                    $stmt = $pdo->prepare("UPDATE campaign_pixels SET type=?, pixel_id=?, token=?, events=?, is_active=? WHERE id=? AND campaign_id=?");
                    $stmt->execute([$type, $pixel_id, $token, $events, $is_active, $id, $campaign_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO campaign_pixels (campaign_id, type, pixel_id, token, events, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$campaign_id, $type, $pixel_id, $token, $events, $is_active]);
                    $id = $pdo->lastInsertId();
                }

                echo json_encode(['status' => 'success', 'data' => ['id' => $id]]);
            }
            break;

        case 'delete_campaign_pixel':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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
                $data = json_decode(file_get_contents('php://input'), true);
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

                switch ($action) {
                    case 'aggregator_connections':
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $data = json_decode(file_get_contents('php://input'), true);

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
                            $data = json_decode(file_get_contents('php://input'), true);
                            $credentials = $data['credentials'] ?? [];
                            $engine = $data['engine'] ?? 'generic';

                            switch ($engine) {
                                case 'referon':
                                    $result = ReferOnEngine::testConnection($credentials);
                                    break;
                                case 'affilka':
                                    $result = AffilkaEngine::testConnection($credentials);
                                    break;
                                default:
                                    $result = GenericApiEngine::testConnection($credentials);
                            }
                            echo json_encode(['status' => 'success', 'data' => $result]);
                        }
                        break;

                    case 'aggregator_sync':
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $data = json_decode(file_get_contents('php://input'), true);
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
                                switch ($conn['engine'] ?? 'generic') {
                                    case 'referon':
                                        $records = ReferOnEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        break;
                                    case 'affilka':
                                        $records = AffilkaEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                                        break;
                                    default:
                                        $records = GenericApiEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
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
