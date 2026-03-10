<?php
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
