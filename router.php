<?php
require_once 'config.php';

$host = $_SERVER['HTTP_HOST'];
// Remove port if exists (e.g. localhost:8080)
$host = preg_replace('/:\d+$/', '', $host);

// Route based on Domain
$stmt = $pdo->prepare("SELECT * FROM domains WHERE name = ? LIMIT 1");
$stmt->execute([$host]);
$domain = $stmt->fetch();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Fast-fail common browser static asset requests that shouldn't trigger tracking
if (preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|woff|woff2|ttf|svg|map)$/i', $uri)) {
    $file = __DIR__ . $uri;
    if (!file_exists($file)) {
        http_response_code(404);
        exit;
    }
}

if ($domain) {
    if ($uri === '/') {
        if ($domain['index_campaign_id']) {
            $_GET['campaign_id'] = $domain['index_campaign_id'];
            include 'index.php';
            exit;
        }
    }
    else {
        // Fallback for subpaths on this domain. Route aliased campaign or 404
        if (preg_match('#^/([^/]+)$#', $uri, $matches) && $uri !== '/admin' && $uri !== '/api.php') {
            $alias = $matches[1];
            // Set for index.php consumption
            $_GET['campaign'] = $alias;
            // Also pass catch_404 info in case alias fails
            if ($domain['catch_404'] && $domain['index_campaign_id']) {
                $_GET['fallback_campaign_id'] = $domain['index_campaign_id'];
            }
            include 'index.php';
            exit;
        }

        // Catch 404 for deeper paths (if the requested path is not a file)
        if ($domain['catch_404'] && $domain['index_campaign_id'] && !file_exists(__DIR__ . $uri) && $uri !== '/admin' && $uri !== '/api.php') {
            $_GET['campaign_id'] = $domain['index_campaign_id'];
            include 'index.php';
            exit;
        }
    }
}

// Serve admin panel for root
if ($uri === '/' || $uri === '') {
    include 'admin.php';
    exit;
}

// Standard Routing
if (preg_match('#^/r/.*#', $uri)) {
    include 'index.php';
    exit;
}
elseif (preg_match('#^/' . preg_quote($postback_key) . '/postback(\?.*)?$#', $_SERVER["REQUEST_URI"])) {
    include 'postback.php';
    exit;
}
elseif ($uri === '/api.php') {
    include 'api.php';
    exit;
}
elseif ($uri === '/click_api/v3' || $uri === '/click_api/v3/') {
    require_once __DIR__ . '/core/click_api.php';
    orbitraClickApiV3($pdo);
    exit;
}
// Support for root aliases even when domain is not parked (e.g., localhost testing)
elseif (preg_match('#^/([^/]+)$#', $uri, $matches) && $uri !== '/admin' && $uri !== '/router.php') {
    $alias = $matches[1];

    // Check if it's a valid campaign alias before consuming it
    $stmtAlias = $pdo->prepare("SELECT id FROM campaigns WHERE alias = ? LIMIT 1");
    $stmtAlias->execute([$alias]);
    if ($stmtAlias->fetch()) {
        $_GET['campaign'] = $alias;
        include 'index.php';
        exit;
    }
}
elseif ($uri === '/api.php') {
    include 'api.php';
    exit;
}
elseif (preg_match('#^/frontend/dist/(assets/.+)$#', $uri)) {
    // Serve Vite build assets
    $file = __DIR__ . $uri;
    if (file_exists($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $contentTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        $contentType = $contentTypes[$ext] ?? 'application/octet-stream';
        header("Content-Type: $contentType");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        readfile($file);
        exit;
    }
}
elseif (preg_match('#^/frontend/dist/vite\.svg$#', $uri)) {
    $file = __DIR__ . '/frontend/dist/vite.svg';
    if (file_exists($file)) {
        header("Content-Type: image/svg+xml");
        readfile($file);
        exit;
    }
}
elseif (file_exists(__DIR__ . $uri) && $uri !== '/' && $uri !== '/admin.php' && $uri !== '/router.php') {
    return false; // serve the requested resource as-is.
}
else {
    include 'admin.php'; // Default to admin panel if no resource is found or asked for index
}
