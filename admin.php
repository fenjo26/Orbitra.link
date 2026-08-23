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

// The panel entry URL as the browser sees it: /admin.php, the secret admin
// path, or the domain root on dev routing. Everything the PWA needs to point
// back at the panel derives from this.
function orbitraPanelPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin.php', PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path === '/') {
        return '/';
    }
    return '/' . trim($path, '/');
}

// Запрет кэширования
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// PWA manifest. Served through the panel entry (not as a static file) so
// start_url always matches how this install is reached — /admin.php, the
// secret admin path or the domain root. A static file could hardcode only
// one of them, and /admin.php 404s behind a secret path.
if (isset($_GET['orbitra_manifest'])) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    $panelPath = orbitraPanelPath();
    echo json_encode([
        'name' => 'Orbitra',
        'short_name' => 'Orbitra',
        'description' => 'Orbitra tracking panel',
        'id' => $panelPath,
        'start_url' => $panelPath,
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#f4f5f7',
        'theme_color' => '#f05a3e',
        'icons' => [
            ['src' => '/frontend/dist/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/frontend/dist/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/frontend/dist/icons/maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/frontend/dist/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// admin.php - входная точка для React приложения (SPA)
$html = file_get_contents(__DIR__ . '/frontend/dist/index.html');
$html = str_replace('{{ csrf_token }}', $_SESSION['csrf_token'], $html);

// Content-hashed assets: the build emits assets/index-[hash].js|css and
// .vite/manifest.json maps the entry to those files. Reference the built
// files through the manifest rather than a hardcoded path — a rebuild
// changes the URL and, with the shell being no-store, a normal reload picks
// the new bundle up. (Replaces the ?v=filemtime cache-buster that the old
// stable filenames required.)
$manifestFile = __DIR__ . '/frontend/dist/.vite/manifest.json';
if (is_file($manifestFile)) {
    $manifest = json_decode((string) file_get_contents($manifestFile), true);
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    if (is_array($entry) && !empty($entry['file'])) {
        $assetBase = '/frontend/dist/';
        $jsTag = '<script type="module" crossorigin src="' . $assetBase . ltrim($entry['file'], '/') . '"></script>';
        $cssTags = '';
        foreach (($entry['css'] ?? []) as $cssFile) {
            $cssTags .= '<link rel="stylesheet" crossorigin href="' . $assetBase . ltrim((string) $cssFile, '/') . '">';
        }
        // The built index.html already carries the right tags after a full
        // build; rewriting from the manifest also heals a stale shell after
        // a partial deploy (assets refreshed, index.html not).
        $html = preg_replace('#<script type="module"[^>]*src="[^"]*assets/[^"]+\.js"[^>]*></script>#', $jsTag, $html);
        $html = preg_replace('#<link rel="stylesheet"[^>]*href="[^"]*assets/[^"]+\.css"[^>]*>#', $cssTags, $html);
    }
}

// PWA head extras: manifest link (via this entry — see above), install
// colour and the iOS home-screen icon.
$panelPath = orbitraPanelPath();
$pwaHead = ''
    . '<link rel="manifest" href="' . htmlspecialchars($panelPath . '?orbitra_manifest=1', ENT_QUOTES) . '">'
    . '<meta name="theme-color" content="#f05a3e">'
    . '<link rel="apple-touch-icon" href="/frontend/dist/icons/apple-touch-icon.png">';
$html = str_replace('</head>', $pwaHead . '</head>', $html);
echo $html;
