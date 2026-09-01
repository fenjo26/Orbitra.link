<?php
/**
 * PwaLanding — generator of static PWA landings (a Google Play style store page).
 *
 * A PWA landing is a plain `landings` row (type 'local') whose config_json
 * carries the `pwa` key. generate() renders that config into three static
 * files inside the landing dir:
 *
 *   index.html            — the store page itself; serve-time placeholders
 *                           ({subid}, {lp_url}) are replaced by the lander
 *                           route on every request, never baked in: the
 *                           service worker serves navigations network-first,
 *                           so a cached page can never pin a stale click.
 *   manifest.webmanifest  — name/icon/start_url/scope "./", display standalone.
 *   sw.js                 — scope defaults to /lander/<slug>/ (the script's own
 *                           directory), so it never touches the admin panel's
 *                           root sw.js or any other landing on the domain.
 *
 * Why the click flow redirects to /lander/<slug>/ instead of serving the page
 * inline at the domain root: Chrome only offers the install prompt when the
 * page is inside the manifest scope, and the scope is this landing's own
 * folder. The click row, cookies and landing_at are written before the 302.
 *
 * The funnel beacons (intent / install / open) POST nothing — they are
 * 1x1-pixel GETs to /pixel.gif?action=pwa&kind=...&subid=<click id>; the
 * click row is the dedup gate on the server side.
 */

class PwaLanding
{
    /** Keys the constructor is allowed to persist; everything else is dropped. */
    private static function configKeys(): array
    {
        return [
            'pwa', 'app_name', 'developer', 'category', 'lang', 'icon', 'icon_url',
            'screens', 'description', 'downloads', 'ads_label', 'button_text',
            'version', 'updated', 'tags', 'rating_counts', 'comments',
            'whats_new_enabled', 'whats_new_text', 'support_enabled',
            'support_email', 'support_address', 'verified_badge', 'theme_mode',
            'color_scheme', 'ios_flow', 'preloader', 'bottom_menu', 'show_header',
            'show_share', 'auto_redirect', 'decline_redirect', 'install_redirect',
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'pwa'               => true,
            'app_name'          => '',
            'developer'         => '',
            'category'          => 'Casino',
            'lang'              => 'en',
            'icon'              => '',
            'icon_url'          => '',
            'screens'           => [],
            'description'       => '',
            'downloads'         => '1M+',
            'ads_label'         => 'Contains ads',
            'button_text'       => 'Install',
            'version'           => '1.0.0',
            'updated'           => '',
            'tags'              => [],
            'rating_counts'     => [4, 3, 2, 1, 1],
            'comments'          => [],
            'whats_new_enabled' => false,
            'whats_new_text'    => '',
            'support_enabled'   => false,
            'support_email'     => '',
            'support_address'   => '',
            'verified_badge'    => true,
            'theme_mode'        => 'light',
            'color_scheme'      => 'green',
            'ios_flow'          => 'instruction',
            'preloader'         => true,
            'bottom_menu'       => false,
            'show_header'       => true,
            'show_share'        => false,
            'auto_redirect'     => 0,
            'decline_redirect'  => 0,
            'install_redirect'  => 0,
        ];
    }

    /** Decode a landings.config_json value into a normalized config array. */
    public static function configFromRow(array $landingRow): array
    {
        $decoded = json_decode((string) ($landingRow['config_json'] ?? ''), true);
        if (!is_array($decoded) || empty($decoded['pwa'])) {
            return [];
        }
        return self::normalizeConfig($decoded);
    }

    public static function isPwa(array $landingRow): bool
    {
        return self::configFromRow($landingRow) !== [];
    }

    /** Whitelist + coerce: the generator must survive any hand-edited JSON. */
    public static function normalizeConfig($config): array
    {
        if (!is_array($config) || empty($config['pwa'])) {
            return [];
        }
        $c = array_intersect_key($config, array_flip(self::configKeys()));
        $d = self::defaultConfig();
        foreach ($d as $k => $v) {
            if (!array_key_exists($k, $c) || $c[$k] === null) {
                $c[$k] = $v;
            }
        }
        foreach (['screens', 'tags'] as $listKey) {
            $c[$listKey] = array_values(array_filter(array_map(
                static fn ($v) => is_scalar($v) ? (string) $v : '',
                is_array($c[$listKey]) ? $c[$listKey] : []
            )));
        }
        if (!is_array($c['rating_counts'])) {
            $c['rating_counts'] = $d['rating_counts'];
        }
        $c['rating_counts'] = array_pad(array_slice(array_map('intval', $c['rating_counts']), 0, 5), 5, 0);
        if (!is_array($c['comments'])) {
            $c['comments'] = [];
        }
        $clean = [];
        foreach ($c['comments'] as $cm) {
            if (!is_array($cm)) {
                continue;
            }
            $clean[] = [
                'name'   => (string) ($cm['name'] ?? ''),
                'text'   => (string) ($cm['text'] ?? ''),
                'stars'  => max(1, min(5, (int) ($cm['stars'] ?? 5))),
                'likes'  => max(0, (int) ($cm['likes'] ?? 0)),
                'date'   => (string) ($cm['date'] ?? ''),
                'reply'  => (string) ($cm['reply'] ?? ''),
            ];
        }
        $c['comments'] = $clean;
        foreach (['preloader', 'bottom_menu', 'show_header', 'show_share', 'support_enabled', 'whats_new_enabled', 'verified_badge'] as $bKey) {
            $c[$bKey] = !empty($c[$bKey]);
        }
        foreach (['auto_redirect', 'decline_redirect', 'install_redirect'] as $tKey) {
            $c[$tKey] = max(0, min(180, (int) $c[$tKey]));
        }
        $c['icon_url'] = is_string($c['icon_url'] ?? null) ? trim($c['icon_url']) : '';
        if (!in_array($c['theme_mode'], ['light', 'dark'], true)) {
            $c['theme_mode'] = 'light';
        }
        if (!in_array($c['ios_flow'], ['default', 'instruction'], true)) {
            $c['ios_flow'] = 'instruction';
        }
        $schemes = array_keys(self::colorSchemes());
        if (!in_array($c['color_scheme'], $schemes, true)) {
            $c['color_scheme'] = 'green';
        }
        return $c;
    }

    public static function colorSchemes(): array
    {
        return [
            'green'  => '#01875f',
            'blue'   => '#1a73e8',
            'purple' => '#7c4dff',
            'red'    => '#d93025',
            'orange' => '#e8710a',
            'pink'   => '#d01884',
        ];
    }

    /**
     * Render config → static files in the landing dir. Only writes the three
     * owned files; uploaded images referenced by the config live beside them.
     *
     * @return array list of written file names
     * @throws RuntimeException when the landing dir cannot be resolved/created
     */
    public static function generate(PDO $pdo, int $landingId): array
    {
        require_once __DIR__ . '/landing_path.php';
        $stmt = $pdo->prepare("SELECT id, config_json FROM landings WHERE id = ? LIMIT 1");
        $stmt->execute([$landingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Landing #$landingId not found");
        }
        $config = self::configFromRow($row);
        if ($config === []) {
            throw new RuntimeException("Landing #$landingId has no PWA config");
        }

        $dir = orbitraLandingDir($pdo, $landingId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException("Cannot create landing dir: $dir");
        }

        // Screens that actually exist on disk — a media-library URL (leading
        // '/') lives outside this dir and is kept as-is; a config referencing
        // a deleted local file must not ship broken strips.
        $config['screens'] = array_values(array_filter(
            $config['screens'],
            static function ($f) use ($dir) {
                if ($f === '') {
                    return false;
                }
                return $f[0] === '/' || is_file($dir . '/' . $f);
            }
        ));
        // Icon: media-library URL wins; legacy relative filename must exist.
        $config['icon'] = ($config['icon'] !== '' && is_file($dir . '/' . $config['icon']))
            ? $config['icon']
            : '';
        if ($config['icon_url'] !== '' && $config['icon_url'][0] !== '/') {
            $config['icon_url'] = '';
        }

        $files = [
            'index.html'           => self::renderIndex($config, $landingId),
            'manifest.webmanifest' => self::renderManifest($config),
            'sw.js'                => self::renderSw($landingId),
        ];
        foreach ($files as $name => $content) {
            if (@file_put_contents($dir . '/' . $name, $content) === false) {
                throw new RuntimeException("Cannot write $name into $dir");
            }
        }
        return array_keys($files);
    }

    /**
     * Render a config into standalone preview HTML for the constructor's live
     * pane. The exact production renderer runs — preview must never drift
     * from what generate() ships — with two neutralizations: the click macros
     * become inert ({subid} → '' keeps beacons silent, {lp_url} → '#' keeps
     * the redirect timers harmless inside the admin iframe) and, for the iOS
     * view, the UA-gated instruction overlay is forced via a flag the
     * generated script understands.
     *
     * Unlike generate() this performs NO disk checks: icon/screens entries are
     * rendered as configured so the operator sees the draft, not the filter.
     */
    public static function renderPreview(array $config, string $platform = 'auto'): string
    {
        $c = self::normalizeConfig($config);
        if ($c === []) {
            throw new InvalidArgumentException('Invalid PWA config');
        }
        $html = self::renderIndex($c, 0);
        $html = str_replace('{subid}', '', $html);
        $html = str_replace('{lp_url}', '#', $html);
        if ($platform === 'ios') {
            $html .= "\n<script>window.__PWA_FORCE_IOS = true;</script>\n";
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private static function esc(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function ratingAvg(array $counts): float
    {
        $sum = 0;
        $total = 0;
        for ($i = 1; $i <= 5; $i++) {
            $n = (int) ($counts[$i - 1] ?? 0);
            $sum += $n * $i;
            $total += $n;
        }
        return $total > 0 ? round($sum / $total, 1) : 0.0;
    }

    private static function ratingTotal(array $counts): int
    {
        return array_sum(array_map('intval', $counts));
    }

    private static function starsSvg(float $avg, string $color): string
    {
        $full = (int) floor($avg);
        $half = ($avg - $full) >= 0.5 ? 1 : 0;
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $fill = $i <= $full ? 1 : ($i === $full + 1 && $half ? 0.5 : 0);
            $id = 'hs' . $i . '_' . mt_rand();
            $out .= '<svg width="14" height="14" viewBox="0 0 24 24"><defs><linearGradient id="' . $id . '">'
                . '<stop offset="' . ($fill * 100) . '%" stop-color="' . $color . '"/>'
                . '<stop offset="' . ($fill * 100) . '%" stop-color="rgba(128,128,128,.35)"/>'
                . '</linearGradient></defs>'
                . '<path fill="url(#' . $id . ')" d="M12 17.3l6.2 3.7-1.7-7 5.5-4.7-7.2-.6L12 2 9.2 8.7 2 9.3l5.5 4.7-1.7 7z"/></svg>';
        }
        return $out;
    }

    private static function iconSrc(array $c): string
    {
        return $c['icon_url'] !== '' ? $c['icon_url'] : $c['icon'];
    }

    private static function iconMime(string $src): string
    {
        return str_ends_with(strtolower($src), '.webp') ? 'image/webp' : 'image/png';
    }

    private static function renderManifest(array $c): string
    {
        $scheme = self::colorSchemes()[$c['color_scheme']] ?? '#01875f';
        $bg = $c['theme_mode'] === 'dark' ? '#0f1114' : '#ffffff';
        $icons = [];
        $iconSrc = self::iconSrc($c);
        if ($iconSrc !== '') {
            $icons[] = [
                'src'     => $iconSrc,
                'sizes'   => '512x512',
                'type'    => self::iconMime($iconSrc),
                'purpose' => 'any',
            ];
        }
        $manifest = [
            'name'             => $c['app_name'] !== '' ? $c['app_name'] : 'App',
            'short_name'       => mb_substr($c['app_name'] !== '' ? $c['app_name'] : 'App', 0, 12),
            'start_url'        => './',
            'scope'            => './',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => $bg,
            'theme_color'      => $scheme,
            'icons'            => $icons,
        ];
        return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    private static function renderSw(int $landingId): string
    {
        $cache = 'orbitra-pwa-' . (int) $landingId . '-v' . time();
        $sw = <<<'SW'
// Generated by Orbitra. Scope = this landing's own directory (the script's
// folder), so it never controls the admin panel or a sibling landing.
var CACHE = '__CACHE__';
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
    e.waitUntil(clients.claim());
});
self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== location.origin) return;
    var accepts = req.headers.get('accept') || '';
    if (req.mode === 'navigate' || accepts.indexOf('text/html') !== -1) {
        // Navigations always go to the network: the served HTML carries the
        // fresh click macros ({subid}/{lp_url}). Offline falls back to cache.
        e.respondWith(fetch(req).catch(function () {
            return caches.match(req).then(function (hit) { return hit || caches.match('./'); });
        }));
        return;
    }
    e.respondWith(caches.open(CACHE).then(function (cache) {
        return cache.match(req).then(function (hit) {
            if (hit) return hit;
            return fetch(req).then(function (res) {
                if (res && res.ok && url.pathname.indexOf(self.registration.scope) === 0) {
                    cache.put(req, res.clone());
                }
                return res;
            });
        });
    }));
});
SW;
        return str_replace('__CACHE__', $cache, $sw);
    }

    /**
     * Minimal visitor-facing strings for the install instruction overlay.
     * One language per PWA (config.lang), en fallback — matches the phase-1
     * decision not to ship per-locale content sets yet.
     */
    private static function langDict(): array
    {
        return [
            'en' => ['Install the app', 'Open this page in Safari', 'Tap the Share button', "Tap 'Add to Home Screen'", 'Launch the app from your home screen', 'Installing…', 'Close'],
            'ru' => ['Установите приложение', 'Откройте страницу в Safari', 'Нажмите кнопку «Поделиться»', 'Выберите «На экран “Домой”»', 'Запустите приложение с домашнего экрана', 'Установка…', 'Закрыть'],
            'uk' => ['Встановіть застосунок', 'Відкрийте сторінку в Safari', 'Натисніть кнопку «Поділитися»', 'Виберіть «На екран “Додому”»', 'Запустіть застосунок з головного екрана', 'Встановлення…', 'Закрити'],
            'es' => ['Instala la aplicación', 'Abre esta página en Safari', 'Toca el botón Compartir', 'Toca «Añadir a inicio»', 'Abre la aplicación desde tu pantalla de inicio', 'Instalando…', 'Cerrar'],
            'de' => ['App installieren', 'Öffne diese Seite in Safari', 'Tippe auf „Teilen“', 'Tippe auf „Zum Home-Bildschirm“', 'Öffne die App vom Home-Bildschirm', 'Wird installiert…', 'Schließen'],
            'fr' => ['Installer l’application', 'Ouvrez cette page dans Safari', 'Touchez le bouton Partager', 'Touchez « Sur l’écran d’accueil »', 'Lancez l’application depuis l’écran d’accueil', 'Installation…', 'Fermer'],
            'zh' => ['安装应用', '在 Safari 中打开此页面', '点击“分享”按钮', '点击“添加到主屏幕”', '从主屏幕启动应用', '安装中…', '关闭'],
        ];
    }

    private static function renderIndex(array $c, int $landingId): string
    {
        $scheme = self::colorSchemes()[$c['color_scheme']] ?? '#01875f';
        $dark = $c['theme_mode'] === 'dark';
        $bg = $dark ? '#0f1114' : '#ffffff';
        $surface = $dark ? '#191c20' : '#f5f5f5';
        $text = $dark ? '#e8eaed' : '#202124';
        $muted = $dark ? '#9aa0a6' : '#5f6368';
        $border = $dark ? '#2b2f33' : '#e0e0e0';
        $appName = $c['app_name'] !== '' ? $c['app_name'] : 'App';
        // Description macros resolve at generation time: the name/developer are
        // static page content here, unlike the click macros below.
        $description = str_replace(
            ['{value}', '{value1}', '{value2}'],
            [$appName, $c['developer'], $c['developer']],
            (string) $c['description']
        );
        $avg = self::ratingAvg($c['rating_counts']);
        $total = self::ratingTotal($c['rating_counts']);
        $dict = self::langDict();
        $lang = isset($dict[$c['lang']]) ? $c['lang'] : 'en';
        $t = $dict[$lang];

        $cfgForJs = [
            'auto'    => (int) $c['auto_redirect'],
            'decline' => (int) $c['decline_redirect'],
            'install' => (int) $c['install_redirect'],
        ];

        $iconSrc = self::iconSrc($c);
        $iconHtml = $iconSrc !== ''
            ? '<img class="app-icon" src="' . self::esc($iconSrc) . '" alt="">'
            : '<div class="app-icon app-icon-fallback">' . self::esc(mb_substr($appName, 0, 1)) . '</div>';

        $screensHtml = '';
        foreach ($c['screens'] as $shot) {
            $screensHtml .= '<img class="shot" loading="lazy" src="' . self::esc($shot) . '" alt="">';
        }

        $tagsHtml = '';
        foreach ($c['tags'] as $tag) {
            if ($tag === '') {
                continue;
            }
            $tagsHtml .= '<span class="tag">' . self::esc($tag) . '</span>';
        }

        $histogram = '';
        $maxCount = max(1, max($c['rating_counts']));
        for ($i = 5; $i >= 1; $i--) {
            $n = (int) ($c['rating_counts'][$i - 1] ?? 0);
            $pct = (int) round($n / $maxCount * 100);
            $histogram .= '<div class="hrow"><span class="hnum">' . $i . '</span>'
                . '<span class="hbar"><span class="hfill" style="width:' . $pct . '%"></span></span></div>';
        }

        $reviewsHtml = '';
        foreach (array_slice($c['comments'], 0, 12) as $cm) {
            $reviewsHtml .= '<div class="review">'
                . '<div class="review-head">'
                . '<span class="avatar">' . self::esc(mb_substr($cm['name'] !== '' ? $cm['name'] : 'A', 0, 1)) . '</span>'
                . '<span class="review-name">' . self::esc($cm['name']) . '</span>'
                . '<span class="review-meta">' . self::esc($cm['date']) . '</span>'
                . '</div>'
                . '<div class="review-stars">' . self::starsSvg((float) $cm['stars'], 'var(--pwa-star)') . '<span class="review-likes">♡ ' . (int) $cm['likes'] . '</span></div>'
                . '<div class="review-text">' . nl2br(self::esc($cm['text'])) . '</div>'
                . ($cm['reply'] !== ''
                    ? '<div class="review-reply"><div class="reply-author">' . self::esc($c['developer']) . '</div>' . nl2br(self::esc($cm['reply'])) . '</div>'
                    : '')
                . '</div>';
        }

        $page = '';
        if ($c['show_header']) {
            $page .= '<header class="gp-head"><span class="gp-burger"></span><span class="gp-search">' . self::esc($appName) . '</span></header>';
        }
        $page .= '<section class="hero">'
            . '<div class="hero-row">'
            . $iconHtml
            . '<div class="hero-info">'
            . '<h1 class="app-name">' . self::esc($appName) . ($c['verified_badge'] ? ' <svg class="badge" width="14" height="14" viewBox="0 0 24 24"><path fill="#1a73e8" d="M12 1l2.4 2.5 3.4-.5 1 3.3 3 1.6-1.3 3.1 1.3 3.1-3 1.6-1 3.3-3.4-.5L12 23l-2.4-2.5-3.4.5-1-3.3-3-1.6 1.3-3.1L2.2 9.9l3-1.6 1-3.3 3.4.5z"/><path fill="#fff" d="M10.6 16.2L7 12.6l1.4-1.4 2.2 2.2 5-5 1.4 1.4z"/></svg>' : '')
            . '</h1>'
            . '<div class="dev">' . self::esc($c['developer']) . '</div>'
            . '<div class="sub">' . self::esc($c['ads_label']) . ' · ' . self::esc($c['category']) . '</div>'
            . '<div class="sub rating-line">' . number_format($avg, 1, '.', '') . ' <span class="mini-stars">' . self::starsSvg($avg, 'var(--pwa-star)') . '</span> · ' . $total . ' reviews · ' . self::esc($c['downloads']) . ' Downloads</div>'
            . '</div></div>'
            . '<button type="button" id="pwa-install-btn" class="install-btn">' . self::esc($c['button_text']) . '</button>'
            . '<div id="pwa-installing" class="installing" hidden>' . self::esc($t[5]) . '</div>'
            . '</section>';

        if ($screensHtml !== '') {
            $page .= '<section class="shots">' . $screensHtml . '</section>';
        }
        if ($c['whats_new_enabled'] && trim((string) $c['whats_new_text']) !== '') {
            $page .= '<section class="block"><h2>What’s new</h2><p class="desc">' . nl2br(self::esc($c['whats_new_text'])) . '</p></section>';
        }
        if ($description !== '') {
            $page .= '<section class="block"><h2>About this app</h2><p class="desc">' . nl2br(self::esc($description)) . '</p>'
                . ($tagsHtml !== '' ? '<div class="tags">' . $tagsHtml . '</div>' : '')
                . '</section>';
        }
        if ($total > 0) {
            $page .= '<section class="block"><h2>Ratings and reviews</h2><div class="ratings-row">'
                . '<div class="big-avg">' . number_format($avg, 1, '.', '') . '</div><div class="hist">' . $histogram . '</div>'
                . '</div></section>';
        }
        if ($reviewsHtml !== '') {
            $page .= '<section class="block"><h2>Reviews</h2>' . $reviewsHtml . '</section>';
        }
        if ($c['support_enabled'] && ($c['support_email'] !== '' || $c['support_address'] !== '')) {
            $page .= '<section class="block"><h2>Support</h2><p class="desc">'
                . ($c['support_email'] !== '' ? self::esc($c['support_email']) . '<br>' : '')
                . self::esc($c['support_address'])
                . '</p></section>';
        }
        if ($c['bottom_menu']) {
            $page .= '<nav class="bottom-menu"><span>🎮</span><span>📱</span><span>🔍</span><span>📚</span></nav>';
        }

        $preloader = $c['preloader']
            ? '<div id="pwa-preloader" class="preloader"><span class="spin"></span></div>'
            : '';
        $share = $c['show_share']
            ? '<div class="share-row"><span class="share-btn">👍</span><span class="share-btn">🔗</span><span class="share-btn">✉️</span></div>'
            : '';

        $iosOverlay = '';
        if ($c['ios_flow'] === 'instruction') {
            $iosOverlay = '<div id="pwa-ios" class="ios-overlay" hidden><div class="ios-card">'
                . '<h3>' . self::esc($t[0]) . '</h3><ol>'
                . '<li>' . self::esc($t[1]) . '</li><li>' . self::esc($t[2]) . '</li>'
                . '<li>' . self::esc($t[3]) . '</li><li>' . self::esc($t[4]) . '</li></ol>'
                . '<button type="button" id="pwa-ios-close">' . self::esc($t[6]) . '</button>'
                . '</div></div>';
        }

        // The two serve-time placeholders stay literal in the source: the
        // lander route replaces them per request, so the SW's network-first
        // navigation rule always pairs a fresh page with a fresh click id.
        $js = <<<'JS'
(function () {
  var cfg = __CFG_JSON__;
  var subid = '__SUBID__';
  var lpUrl = '__LP_URL__';
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  // __PWA_FORCE_IOS is set only by the constructor's iOS preview.
  var isIOS = window.__PWA_FORCE_IOS === true
    || /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var installingEl = document.getElementById('pwa-installing');

  function beacon(kind) {
    if (!subid) return;
    var img = new Image();
    img.src = '/pixel.gif?action=pwa&kind=' + kind + '&subid=' + encodeURIComponent(subid) + '&r=' + Date.now();
  }
  function redirect() { if (lpUrl) window.location.href = lpUrl; }
  function later(sec, fn) { if (sec > 0) setTimeout(fn, sec * 1000); else fn(); }

  var preloader = document.getElementById('pwa-preloader');
  if (preloader) { setTimeout(function () { preloader.classList.add('done'); }, 600); }

  var deferred = null;
  function iosOverlay(show) {
    var el = document.getElementById('pwa-ios');
    if (el) el.hidden = !show;
  }
  var closeBtn = document.getElementById('pwa-ios-close');
  if (closeBtn) closeBtn.addEventListener('click', function () { iosOverlay(false); });

  var btn = document.getElementById('pwa-install-btn');
  if (btn) {
    btn.addEventListener('click', function () {
      beacon('intent');
      if (isStandalone) { later(0, redirect); return; }
      if (isIOS) { iosOverlay(true); return; }
      if (deferred) {
        if (installingEl) installingEl.hidden = false;
        deferred.prompt();
        deferred.userChoice.then(function (choice) {
          deferred = null;
          if (choice && choice.outcome === 'accepted') {
            // appinstalled fires separately and drives the redirect.
          } else {
            if (installingEl) installingEl.hidden = true;
            if (cfg.decline > 0) later(cfg.decline, redirect);
          }
        });
      } else {
        iosOverlay(true);
      }
    });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (installingEl) installingEl.hidden = true;
  });
  window.addEventListener('appinstalled', function () {
    beacon('install');
    iosOverlay(false);
    if (installingEl) installingEl.hidden = false;
    later(cfg.install, redirect);
  });

  if (isStandalone) {
    beacon('open');
    if (isIOS) {
      // No beforeinstallprompt on iOS: the first standalone launch IS the
      // install confirmation. The server NULL-guard dedups anyway.
      var key = 'orbitra_pwa_installed';
      try {
        if (!localStorage.getItem(key)) { beacon('install'); localStorage.setItem(key, '1'); }
      } catch (e) { beacon('install'); }
    }
  } else if (cfg.auto > 0) {
    setTimeout(redirect, cfg.auto * 1000);
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      // Sandbox previews (constructor iframe without allow-same-origin)
      // throw on the property ACCESS itself — .catch() would not help.
      try { navigator.serviceWorker.register('sw.js').catch(function () {}); } catch (e) {}
    });
  }
})();
JS;
        $js = str_replace(
            ['__CFG_JSON__', '__SUBID__', '__LP_URL__'],
            [json_encode($cfgForJs, JSON_UNESCAPED_UNICODE), '{subid}', '{lp_url}'],
            $js
        );

        $css = <<<CSS
:root{--pwa-primary:$scheme;--pwa-bg:$bg;--pwa-surface:$surface;--pwa-text:$text;--pwa-muted:$muted;--pwa-border:$border;--pwa-star:#fbbc04}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Roboto,-apple-system,'Segoe UI',Arial,sans-serif;background:var(--pwa-bg);color:var(--pwa-text);padding-bottom:64px}
.preloader{position:fixed;inset:0;background:var(--pwa-bg);display:flex;align-items:center;justify-content:center;z-index:50;transition:opacity .35s;opacity:1}
.preloader.done{opacity:0;pointer-events:none}
.spin{width:34px;height:34px;border-radius:50%;border:3px solid var(--pwa-border);border-top-color:var(--pwa-primary);animation:pspin .8s linear infinite}
@keyframes pspin{to{transform:rotate(360deg)}}
.gp-head{display:flex;align-items:center;gap:12px;padding:12px 14px;position:sticky;top:0;background:var(--pwa-bg);z-index:10}
.gp-burger{width:18px;height:2px;background:var(--pwa-text);box-shadow:0 6px 0 var(--pwa-text),0 -6px 0 var(--pwa-text);border-radius:2px}
.gp-search{flex:1;background:var(--pwa-surface);border-radius:22px;padding:9px 16px;font-size:14px;color:var(--pwa-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hero{padding:14px 16px 4px}
.hero-row{display:flex;gap:14px}
.app-icon{width:84px;height:84px;border-radius:18px;object-fit:cover;flex:none;background:var(--pwa-surface)}
.app-icon-fallback{display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:700;color:#fff;background:var(--pwa-primary)}
.hero-info{min-width:0}
.app-name{font-size:21px;line-height:1.2;font-weight:500;word-break:break-word}
.badge{vertical-align:middle;margin-left:2px}
.dev{color:var(--pwa-primary);font-size:13px;margin-top:3px}
.sub{color:var(--pwa-muted);font-size:12px;margin-top:3px}
.rating-line{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.mini-stars{display:inline-flex;gap:1px}
.install-btn{margin:16px 0 6px;background:var(--pwa-primary);color:#fff;border:none;border-radius:22px;padding:11px 38px;font-size:15px;font-weight:500;width:100%}
.installing{color:var(--pwa-primary);font-size:13px;text-align:center;padding-bottom:8px}
.shots{display:flex;gap:10px;overflow-x:auto;padding:12px 16px;scrollbar-width:none}
.shots::-webkit-scrollbar{display:none}
.shots .shot{height:230px;border-radius:10px;flex:none;max-width:82vw;object-fit:cover}
.block{padding:18px 16px 4px}
.block h2{font-size:16px;font-weight:500;margin-bottom:10px}
.desc{font-size:14px;line-height:1.5;color:var(--pwa-text);white-space:normal}
.tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.tag{border:1px solid var(--pwa-border);border-radius:8px;padding:6px 12px;font-size:12px;color:var(--pwa-muted)}
.ratings-row{display:flex;gap:18px;align-items:center}
.big-avg{font-size:44px;font-weight:400}
.hist{flex:1}
.hrow{display:flex;align-items:center;gap:8px;margin:2px 0}
.hnum{font-size:12px;color:var(--pwa-muted);width:8px}
.hbar{flex:1;height:6px;background:var(--pwa-surface);border-radius:3px;overflow:hidden}
.hfill{display:block;height:100%;background:var(--pwa-primary)}
.review{padding:12px 0;border-bottom:1px solid var(--pwa-border)}
.review:last-child{border-bottom:none}
.review-head{display:flex;align-items:center;gap:10px}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--pwa-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:500;flex:none}
.review-name{font-size:14px;font-weight:500;flex:1}
.review-meta{font-size:12px;color:var(--pwa-muted)}
.review-stars{display:flex;align-items:center;gap:8px;margin:6px 0 4px}
.review-likes{font-size:12px;color:var(--pwa-muted)}
.review-text{font-size:14px;line-height:1.45}
.review-reply{margin-top:10px;background:var(--pwa-surface);border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.4}
.reply-author{font-weight:500;margin-bottom:4px}
.ios-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center;z-index:60}
.ios-card{background:var(--pwa-bg);color:var(--pwa-text);border-radius:18px 18px 0 0;padding:22px 20px;max-width:420px;width:100%}
.ios-card h3{font-size:17px;margin-bottom:12px}
.ios-card ol{padding-left:20px;font-size:14px;line-height:1.7}
.ios-card button{margin-top:14px;background:var(--pwa-surface);border:none;border-radius:20px;padding:10px 26px;font-size:14px;color:var(--pwa-text);width:100%}
.bottom-menu{position:fixed;left:0;right:0;bottom:0;background:var(--pwa-bg);border-top:1px solid var(--pwa-border);display:flex;justify-content:space-around;padding:12px 0;font-size:20px;z-index:20}
.share-row{display:flex;gap:14px;padding:6px 16px 12px}
.share-btn{width:42px;height:42px;border-radius:50%;background:var(--pwa-surface);display:flex;align-items:center;justify-content:center;font-size:18px}
CSS;

        return '<!DOCTYPE html>
<html lang="' . self::esc($lang) . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . self::esc($appName) . '</title>
<meta name="theme-color" content="' . self::esc($scheme) . '">
<link rel="manifest" href="manifest.webmanifest">
' . ($iconSrc !== '' ? '<link rel="apple-touch-icon" href="' . self::esc($iconSrc) . '">' : '') . '
<style>' . $css . '</style>
</head>
<body>
' . $preloader . '
' . $page . '
' . $share . '
' . $iosOverlay . '
<script>
' . $js . '
</script>
</body>
</html>
';
    }
}
