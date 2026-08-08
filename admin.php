<?php
// The IP access list runs before anything else: an unlisted client must never
// learn the panel exists, so it does not see the login form, the secret-path
// 404, or even the fact that this URL serves an admin surface. An empty list
// (the default) leaves the panel open to everyone.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/ip_access.php';
if (!orbitraAdminIpAllowed($pdo)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1></body></html>";
    exit;
}

// When a secret admin path is configured, the panel is only served through it.
// A direct hit on /admin.php then answers 404 — otherwise the secret would be
// pointless, since the default URL would still work.
if (!defined('ORBITRA_ADMIN_ROUTED')) {
    require_once __DIR__ . '/core/admin_path.php';

    if (orbitraAdminPath($pdo) !== '') {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>";
        exit;
    }
}

require_once __DIR__ . '/session_bootstrap.php';
orbitraBootstrapSession();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Запрет кэширования
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// admin.php - входная точка для React приложения (SPA)
$html = file_get_contents(__DIR__ . '/frontend/dist/index.html');
$html = str_replace('{{ csrf_token }}', $_SESSION['csrf_token'], $html);

// Cache busting for stable asset names (vite config uses non-hashed filenames).
// This avoids situations where server code is updated but the browser keeps an old /assets/index.js.
$assetJs = __DIR__ . '/frontend/dist/assets/index.js';
$assetCss = __DIR__ . '/frontend/dist/assets/index.css';
$v = 0;
if (is_file($assetJs)) {
    $v = (int) (filemtime($assetJs) ?: 0);
} elseif (is_file($assetCss)) {
    $v = (int) (filemtime($assetCss) ?: 0);
} else {
    $v = (int) time();
}
$html = str_replace('/frontend/dist/assets/index.js', '/frontend/dist/assets/index.js?v=' . $v, $html);
$html = str_replace('/frontend/dist/assets/index.css', '/frontend/dist/assets/index.css?v=' . $v, $html);
echo $html;
