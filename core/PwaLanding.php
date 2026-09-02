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
    /**
     * Bumped whenever the rendered output changes meaningfully (layout, bug
     * fixes in the markup). Embedded as a meta marker into every generated
     * page; the lander route regenerates stale statics on the next view, so
     * renderer upgrades reach already-created PWA landings without a re-save.
     */
    public const RENDERER_VERSION = 12;

    /** Keys the constructor is allowed to persist; everything else is dropped. */
    private static function configKeys(): array
    {
        return [
            'pwa', 'app_name', 'developer', 'category', 'lang', 'icon', 'icon_url',
            'screens', 'description', 'downloads', 'ads_label', 'button_text',
            'version', 'updated', 'tags', 'rating_counts', 'comments',
            'whats_new_enabled', 'whats_new_text', 'support_enabled',
            'support_email', 'support_address', 'verified_badge', 'theme_mode',
            'color_scheme', 'store_style', 'ios_flow', 'preloader', 'bottom_menu', 'show_header',
            'show_share', 'auto_redirect', 'decline_redirect', 'install_redirect', 'push_enabled',
            'app_action', 'app_campaign_id', 'app_screen_type', 'app_screen_title', 'app_screen_text', 'app_screen_button',
            'app_screen_image', 'app_screen_custom_html', 'app_screen_custom_js',
            'custom_css', 'custom_head_code', 'custom_body_code', 'custom_js',
            'animation_glow', 'show_live_badge', 'sound_enabled', 'vibration_enabled',
            'action_target', 'action_campaign_id', 'action_url',
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'pwa'                    => true,
            'app_name'               => '',
            'developer'              => '',
            'category'               => 'Casino',
            'lang'                   => 'en',
            'icon'                   => '',
            'icon_url'               => '',
            'screens'                => [],
            'description'            => '',
            'downloads'              => '1M+',
            'ads_label'              => 'Contains ads',
            'button_text'            => 'Install',
            'version'                => '1.0.0',
            'updated'                => '',
            'tags'                   => [],
            'rating_counts'          => [4200, 480, 120, 30, 15],
            'comments'               => [],
            'whats_new_enabled'      => false,
            'whats_new_text'         => '',
            'support_enabled'        => false,
            'support_email'          => '',
            'support_address'        => '',
            'verified_badge'         => true,
            'theme_mode'             => 'light',
            'color_scheme'           => 'green',
            'store_style'            => 'auto',
            'push_enabled'           => false,
            'ios_flow'               => 'default',
            'preloader'              => true,
            'bottom_menu'            => false,
            'show_header'            => true,
            'show_share'             => false,
            'auto_redirect'          => 0,
            'decline_redirect'       => 0,
            'install_redirect'       => 0,
            'animation_glow'         => true,
            'show_live_badge'        => true,
            'sound_enabled'          => true,
            'vibration_enabled'      => true,
            'app_action'             => 'store',
            'app_screen_type'        => 'lobby',
            'app_screen_title'       => '',
            'app_screen_text'        => '',
            'app_screen_button'      => 'Play now',
            'app_screen_image'       => '',
            'app_screen_custom_html' => '',
            'app_screen_custom_js'   => '',
            'app_campaign_id'        => 0,
            'custom_css'             => '',
            'custom_head_code'       => '',
            'custom_body_code'       => '',
            'custom_js'              => '',
            'action_target'          => 'to_offer',
            'action_campaign_id'     => 0,
            'action_url'             => '',
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
        foreach (['preloader', 'bottom_menu', 'show_header', 'show_share', 'support_enabled', 'whats_new_enabled', 'verified_badge', 'push_enabled'] as $bKey) {
            $c[$bKey] = !empty($c[$bKey]);
        }
        foreach (['auto_redirect', 'decline_redirect', 'install_redirect'] as $tKey) {
            $c[$tKey] = max(0, min(180, (int) $c[$tKey]));
        }
        $c['icon_url'] = is_string($c['icon_url'] ?? null) ? trim($c['icon_url']) : '';
        if (!in_array($c['theme_mode'], ['light', 'dark'], true)) {
            $c['theme_mode'] = 'light';
        }
        if (!in_array($c['store_style'], ['auto', 'google_play', 'app_store'], true)) {
            $c['store_style'] = 'auto';
        }
        // What the INSTALLED app does when opened (the store page is only the
        // pre-install face): store = keep showing the listing, offer =
        // straight redirect into the funnel, campaign = redirect into a chosen
        // campaign (its streams/rotation distribute the traffic), screen = a
        // custom in-app screen with a CTA into the funnel.
        if (!in_array($c['app_action'], ['store', 'offer', 'screen', 'campaign'], true)) {
            $c['app_action'] = 'store';
        }
        $c['app_campaign_id'] = max(0, (int) ($c['app_campaign_id'] ?? 0));
        $c['app_screen_type'] = (string) ($c['app_screen_type'] ?? 'lobby');
        if (!in_array($c['app_screen_type'], ['lobby', 'slot', 'wheel', 'custom'], true)) {
            $c['app_screen_type'] = 'lobby';
        }
        $c['app_screen_custom_html'] = (string) ($c['app_screen_custom_html'] ?? '');
        $c['app_screen_custom_js']   = (string) ($c['app_screen_custom_js'] ?? '');
        $c['custom_css']             = (string) ($c['custom_css'] ?? '');
        $c['custom_head_code']       = (string) ($c['custom_head_code'] ?? '');
        $c['custom_body_code']       = (string) ($c['custom_body_code'] ?? '');
        $c['custom_js']              = (string) ($c['custom_js'] ?? '');
        $c['animation_glow']         = (bool) ($c['animation_glow'] ?? true);
        $c['show_live_badge']        = (bool) ($c['show_live_badge'] ?? true);
        $c['sound_enabled']          = (bool) ($c['sound_enabled'] ?? true);
        $c['vibration_enabled']      = (bool) ($c['vibration_enabled'] ?? true);

        $c['action_target'] = (string) ($c['action_target'] ?? 'to_offer');
        if (!in_array($c['action_target'], ['to_offer', 'to_campaign', 'to_url', 'not_found'], true)) {
            $c['action_target'] = 'to_offer';
        }
        $c['action_campaign_id'] = max(0, (int) ($c['action_campaign_id'] ?? 0));
        $c['action_url'] = trim((string) ($c['action_url'] ?? ''));

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
    public static function renderPreview(array $config, string $platform = 'auto', string $view = 'auto'): string
    {
        $c = self::normalizeConfig($config);
        if ($c === []) {
            throw new InvalidArgumentException('Invalid PWA config');
        }
        $html = self::renderIndex($c, 0);
        $html = str_replace('{subid}', '', $html);
        $html = str_replace('{lp_url}', '#', $html);
        $html = str_replace(['{clickid}', '{token}', '{offer}', '{sub_id}'], '#', $html);
        // No VAPID in preview: the subscribe screen stays hidden, the page is
        // about looks, not about collecting subscriptions from the operator.
        $html = str_replace('{vapid_public}', '', $html);
        if ($platform === 'ios') {
            // The flag must exist BEFORE the store-picking script in <head>
            // executes: appending it at the end left data-store on
            // google_play, so both preview toggles showed the same layout.
            // Injected this early it also drives the install-button behavior
            // (instruction overlay instead of the native prompt).
            $html = preg_replace(
                '/<head[^>]*>/i',
                "$0\n<script>window.__PWA_FORCE_IOS = true;</script>",
                $html,
                1
            );
        }
        if ($view === 'screen' || ($view === 'auto' && ($c['app_action'] ?? '') === 'screen')) {
            $html = preg_replace(
                '/<head[^>]*>/i',
                "$0\n<script>window.__PWA_FORCE_APP_SCREEN = true;</script>",
                $html,
                1
            );
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

    /**
     * Buckets follow the frontend/editor convention: index 0 = 5-star count,
     * index 4 = 1-star count. Weighing index $i with $i stars instead would
     * flip every listing's average to ~1.2 and make the histogram upside down.
     */
    private static function ratingAvg(array $counts): float
    {
        $sum = 0;
        $total = 0;
        for ($i = 0; $i < 5; $i++) {
            $n = (int) ($counts[$i] ?? 0);
            $sum += $n * (5 - $i);
            $total += $n;
        }
        return $total > 0 ? round($sum / $total, 1) : 0.0;
    }

    private static function ratingTotal(array $counts): int
    {
        return array_sum(array_map('intval', $counts));
    }

    private static function starsSvg(float $avg, string $color, int $size = 14): string
    {
        $full = (int) floor($avg);
        $half = ($avg - $full) >= 0.5 ? 1 : 0;
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $fill = $i <= $full ? 1 : ($i === $full + 1 && $half ? 0.5 : 0);
            $id = 'hs' . $i . '_' . mt_rand();
            $out .= '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"><defs><linearGradient id="' . $id . '">'
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
     * Minimal visitor-facing strings for the install instruction overlay & store chrome.
     */
    private static function langDict(): array
    {
        return [
            'en' => [
                0 => 'Install the app', 1 => 'Open this page in Safari', 2 => 'Tap the Share button', 3 => "Tap 'Add to Home Screen'", 4 => 'Launch the app from your home screen', 5 => 'Installing…', 6 => 'Close',
                'push_title' => 'Enable notifications', 'push_text' => 'Get bonuses and updates right on your phone', 'push_allow' => 'Allow', 'push_later' => 'Not now',
                'get' => 'GET', 'in_app_purchases' => 'In-App Purchases', 'ratings_reviews' => 'Ratings & Reviews', 'see_all' => 'See All',
                'whats_new' => "What's New", 'version_history' => 'Version History', 'version' => 'Version', 'preview' => 'Preview',
                'information' => 'Information', 'provider' => 'Provider', 'size' => 'Size', 'category' => 'Category',
                'compatibility' => 'Compatibility', 'compatibility_val' => 'Works on this iPhone', 'languages' => 'Languages', 'languages_val' => 'English and 6 more',
                'age_rating' => 'Age Rating', 'age_val' => '17+', 'chart_rank' => '#1 in', 'developer_response' => 'Developer Response',
                'ratings_count_label' => 'RATINGS', 'age_label' => 'AGE', 'chart_label' => 'CHART', 'dev_label' => 'DEVELOPER', 'lang_label' => 'LANGUAGE', 'size_label' => 'SIZE',
                'today' => 'Today', 'games' => 'Games', 'apps' => 'Apps', 'arcade' => 'Arcade', 'search' => 'Search', 'about_app' => 'About this app', 'reviews' => 'Reviews', 'support' => 'Support'
            ],
            'ru' => [
                0 => 'Установите приложение', 1 => 'Откройте страницу в Safari', 2 => 'Нажмите кнопку «Поделиться»', 3 => 'Выберите «На экран “Домой”»', 4 => 'Запустите приложение с домашнего экрана', 5 => 'Установка…', 6 => 'Закрыть',
                'push_title' => 'Включите уведомления', 'push_text' => 'Получайте бонусы и обновления прямо на телефон', 'push_allow' => 'Разрешить', 'push_later' => 'Не сейчас',
                'get' => 'ЗАГРУЗИТЬ', 'in_app_purchases' => 'Встроенные покупки', 'ratings_reviews' => 'Оценки и отзывы', 'see_all' => 'См. все',
                'whats_new' => 'Что нового', 'version_history' => 'История версий', 'version' => 'Версия', 'preview' => 'Предпросмотр',
                'information' => 'Информация', 'provider' => 'Продавец', 'size' => 'Размер', 'category' => 'Категория',
                'compatibility' => 'Совместимость', 'compatibility_val' => 'Работает на этом iPhone', 'languages' => 'Языки', 'languages_val' => 'Русский и еще 6',
                'age_rating' => 'Возраст', 'age_val' => '17+', 'chart_rank' => '#1 в', 'developer_response' => 'Ответ разработчика',
                'ratings_count_label' => 'ОЦЕНОК', 'age_label' => 'ВОЗРАСТ', 'chart_label' => 'ЧАРТ', 'dev_label' => 'РАЗРАБОТЧИК', 'lang_label' => 'ЯЗЫК', 'size_label' => 'РАЗМЕР',
                'today' => 'Сегодня', 'games' => 'Игры', 'apps' => 'Приложения', 'arcade' => 'Arcade', 'search' => 'Поиск', 'about_app' => 'О приложении', 'reviews' => 'Отзывы', 'support' => 'Поддержка'
            ],
            'uk' => [
                0 => 'Встановіть застосунок', 1 => 'Відкрийте сторінку в Safari', 2 => 'Натисніть кнопку «Поділитися»', 3 => 'Виберіть «На екран “Додому”»', 4 => 'Запустіть застосунок з головного екрана', 5 => 'Встановлення…', 6 => 'Закрити',
                'push_title' => 'Увімкніть повідомлення', 'push_text' => 'Отримуйте бонуси та новини прямо на телефон', 'push_allow' => 'Дозволити', 'push_later' => 'Не зараз',
                'get' => 'ОТРИМАТИ', 'in_app_purchases' => 'Вбудовані покупки', 'ratings_reviews' => 'Оцінки та відгуки', 'see_all' => 'Див. всі',
                'whats_new' => 'Що нового', 'version_history' => 'Історія версій', 'version' => 'Версія', 'preview' => 'Попередній перегляд',
                'information' => 'Інформація', 'provider' => 'Розробник', 'size' => 'Розмір', 'category' => 'Категорія',
                'compatibility' => 'Сумісність', 'compatibility_val' => 'Працює на цьому iPhone', 'languages' => 'Мови', 'languages_val' => 'Українська та ще 6',
                'age_rating' => 'Вік', 'age_val' => '17+', 'chart_rank' => '#1 в', 'developer_response' => 'Відповідь розробника',
                'ratings_count_label' => 'ОЦІНОК', 'age_label' => 'ВІК', 'chart_label' => 'ЧАРТ', 'dev_label' => 'РОЗРОБНИК', 'lang_label' => 'МОВА', 'size_label' => 'РОЗМІР',
                'today' => 'Сьогодні', 'games' => 'Ігри', 'apps' => 'Додатки', 'arcade' => 'Arcade', 'search' => 'Пошук', 'about_app' => 'Про застосунок', 'reviews' => 'Відгуки', 'support' => 'Підтримка'
            ],
            'es' => [
                0 => 'Instala la aplicación', 1 => 'Abre esta página en Safari', 2 => 'Toca el botón Compartir', 3 => 'Toca «Añadir a inicio»', 4 => 'Abre la aplicación desde tu pantalla de inicio', 5 => 'Instalando…', 6 => 'Cerrar',
                'push_title' => 'Activa las notificaciones', 'push_text' => 'Recibe bonos y novedades en tu teléfono', 'push_allow' => 'Permitir', 'push_later' => 'Ahora no',
                'get' => 'OBTENER', 'in_app_purchases' => 'Compras dentro de la app', 'ratings_reviews' => 'Valoraciones y reseñas', 'see_all' => 'Ver todo',
                'whats_new' => 'Novedades', 'version_history' => 'Historial de versiones', 'version' => 'Versión', 'preview' => 'Vista previa',
                'information' => 'Información', 'provider' => 'Proveedor', 'size' => 'Tamaño', 'category' => 'Categoría',
                'compatibility' => 'Compatibilidad', 'compatibility_val' => 'Funciona en este iPhone', 'languages' => 'Idiomas', 'languages_val' => 'Español y 6 más',
                'age_rating' => 'Edad', 'age_val' => '17+', 'chart_rank' => '#1 en', 'developer_response' => 'Respuesta del desarrollador',
                'ratings_count_label' => 'VALORACIONES', 'age_label' => 'EDAD', 'chart_label' => 'LISTAS', 'dev_label' => 'DESARROLLADOR', 'lang_label' => 'IDIOMA', 'size_label' => 'TAMAÑO',
                'today' => 'Hoy', 'games' => 'Juegos', 'apps' => 'Apps', 'arcade' => 'Arcade', 'search' => 'Buscar', 'about_app' => 'Información de la app', 'reviews' => 'Reseñas', 'support' => 'Soporte'
            ],
            'de' => [
                0 => 'App installieren', 1 => 'Öffne diese Seite in Safari', 2 => 'Tippe auf „Teilen“', 3 => 'Tippe auf „Zum Home-Bildschirm“', 4 => 'Öffne die App vom Home-Bildschirm', 5 => 'Wird installiert…', 6 => 'Schließen',
                'push_title' => 'Benachrichtigungen aktivieren', 'push_text' => 'Erhalte Boni und Updates direkt aufs Handy', 'push_allow' => 'Erlauben', 'push_later' => 'Später',
                'get' => 'LADEN', 'in_app_purchases' => 'In-App-Käufe', 'ratings_reviews' => 'Bewertungen & Rezensionen', 'see_all' => 'Alle anzeigen',
                'whats_new' => 'Neuheiten', 'version_history' => 'Versionsverlauf', 'version' => 'Version', 'preview' => 'Vorschau',
                'information' => 'Informationen', 'provider' => 'Entwickler', 'size' => 'Größe', 'category' => 'Kategorie',
                'compatibility' => 'Kompatibilität', 'compatibility_val' => 'Funktioniert auf diesem iPhone', 'languages' => 'Sprachen', 'languages_val' => 'Deutsch und 6 weitere',
                'age_rating' => 'Alter', 'age_val' => '17+', 'chart_rank' => '#1 in', 'developer_response' => 'Entwicklerantwort',
                'ratings_count_label' => 'BEWERTUNGEN', 'age_label' => 'ALTER', 'chart_label' => 'CHARTS', 'dev_label' => 'ENTWICKLER', 'lang_label' => 'SPRACHE', 'size_label' => 'GRÖSSE',
                'today' => 'Heute', 'games' => 'Spiele', 'apps' => 'Apps', 'arcade' => 'Arcade', 'search' => 'Suchen', 'about_app' => 'Über diese App', 'reviews' => 'Bewertungen', 'support' => 'Support'
            ],
            'fr' => [
                0 => 'Installer l’application', 1 => 'Ouvrez cette page dans Safari', 2 => 'Touchez le bouton Partager', 3 => 'Touchez « Sur l’écran d’accueil »', 4 => 'Lancez l’application depuis l’écran d’accueil', 5 => 'Installation…', 6 => 'Fermer',
                'push_title' => 'Activez les notifications', 'push_text' => 'Recevez bonus et actualités sur votre téléphone', 'push_allow' => 'Autoriser', 'push_later' => 'Plus tard',
                'get' => 'OBTENIR', 'in_app_purchases' => 'Achats intégrés', 'ratings_reviews' => 'Notes et avis', 'see_all' => 'Tout afficher',
                'whats_new' => 'Nouveautés', 'version_history' => 'Historique des versions', 'version' => 'Version', 'preview' => 'Aperçu',
                'information' => 'Informations', 'provider' => 'Fournisseur', 'size' => 'Taille', 'category' => 'Catégorie',
                'compatibility' => 'Compatibilité', 'compatibility_val' => 'Fonctionne sur cet iPhone', 'languages' => 'Langues', 'languages_val' => 'Français et 6 autres',
                'age_rating' => 'Âge', 'age_val' => '17+', 'chart_rank' => '#1 dans', 'developer_response' => 'Réponse du développeur',
                'ratings_count_label' => 'NOTES', 'age_label' => 'ÂGE', 'chart_label' => 'CLASSEMENT', 'dev_label' => 'DÉVELOPPEUR', 'lang_label' => 'LANGUE', 'size_label' => 'TAILLE',
                'today' => 'Aujourd’hui', 'games' => 'Jeux', 'apps' => 'Apps', 'arcade' => 'Arcade', 'search' => 'Rechercher', 'about_app' => 'À propos de cette appli', 'reviews' => 'Avis', 'support' => 'Assistance'
            ],
            'zh' => [
                0 => '安装应用', 1 => '在 Safari 中打开此页面', 2 => '点击“分享”按钮', 3 => '点击“添加到主屏幕”', 4 => '从主屏幕启动应用', 5 => '安装中…', 6 => '关闭',
                'push_title' => '开启通知', 'push_text' => '在手机上第一时间获取奖励和更新', 'push_allow' => '允许', 'push_later' => '暂不',
                'get' => '获取', 'in_app_purchases' => 'App 内购买项目', 'ratings_reviews' => '评分及评论', 'see_all' => '查看全部',
                'whats_new' => '新功能', 'version_history' => '版本历史记录', 'version' => '版本', 'preview' => '预览',
                'information' => '信息', 'provider' => '开发商', 'size' => '大小', 'category' => '类目',
                'compatibility' => '兼容性', 'compatibility_val' => '可在此 iPhone 上使用', 'languages' => '语言', 'languages_val' => '中文等 6 种',
                'age_rating' => '年龄分级', 'age_val' => '17+', 'chart_rank' => '#1 位于', 'developer_response' => '开发者回复',
                'ratings_count_label' => '份评分', 'age_label' => '年龄', 'chart_label' => '排行榜', 'dev_label' => '开发商', 'lang_label' => '语言', 'size_label' => '大小',
                'today' => 'Today', 'games' => '游戏', 'apps' => 'App', 'arcade' => 'Arcade', 'search' => '搜索', 'about_app' => '关于此 App', 'reviews' => '评论', 'support' => '技术支持'
            ],
        ];
    }

    private static function renderIndex(array $c, int $landingId): string
    {
        $scheme = self::colorSchemes()[$c['color_scheme']] ?? '#01875f';
        $dark = $c['theme_mode'] === 'dark';
        $bg = $dark ? '#000000' : '#ffffff';
        $surface = $dark ? '#1c1c1e' : '#f2f2f7';
        $text = $dark ? '#ffffff' : '#000000';
        $muted = $dark ? '#8e8e93' : '#8e8e93';
        $border = $dark ? '#38383a' : '#e5e5ea';
        $appName = $c['app_name'] !== '' ? $c['app_name'] : 'App';
        $storeStyle = $c['store_style'] ?? 'auto';

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
            'auto'       => (int) $c['auto_redirect'],
            'decline'    => (int) $c['decline_redirect'],
            'install'    => (int) $c['install_redirect'],
            'push'       => !empty($c['push_enabled']),
            'appAction'  => $c['app_action'],
            'sound'      => !empty($c['sound_enabled']),
            'vibration'  => !empty($c['vibration_enabled']),
        ];

        $iconSrc = self::iconSrc($c);
        $iconHtml = $iconSrc !== ''
            ? '<img class="app-icon" src="' . self::esc($iconSrc) . '" alt="" onerror="this.style.display=\'none\'">'
            : '<div class="app-icon app-icon-fallback">' . self::esc(mb_substr($appName, 0, 1)) . '</div>';

        $screensHtml = '';
        foreach ($c['screens'] as $shot) {
            $screensHtml .= '<img class="shot" loading="lazy" src="' . self::esc($shot) . '" alt="" onerror="this.style.display=\'none\'">';
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
            $n = (int) ($c['rating_counts'][5 - $i] ?? 0);
            $pct = (int) round($n / $maxCount * 100);
            $histogram .= '<div class="hrow"><span class="hnum">' . $i . '</span>'
                . '<span class="hbar"><span class="hfill" style="width:' . $pct . '%"></span></span></div>';
        }

        // Ratings count short format — top-chart apps show hundreds of
        // thousands of ratings, so totals render compactly (K/M).
        $totalFormatted = $total >= 1000000
            ? round($total / 1000000, 1) . 'M'
            : ($total >= 1000 ? round($total / 1000, 1) . 'K' : (string) $total);

        $glowClass = $c['animation_glow'] ? ' btn-glow-active' : '';
        $liveBadgeHtml = $c['show_live_badge'] ? '<span class="gp-live-counter">🟢 14.8K live</span>' : '';
        $liveBadgeIos = $c['show_live_badge'] ? '<span class="ios-live-counter">🟢 14.8K live</span>' : '';

        // ------------------------------------------------------------------
        // GOOGLE PLAY LAYOUT
        // ------------------------------------------------------------------
        $gpPage = '';
        if ($c['show_header']) {
            $gpPage .= '<header class="gp-head"><span class="gp-burger"></span><span class="gp-search">' . self::esc($appName) . '</span></header>';
        }
        $gpPage .= '<section class="hero gp-hero">'
            . '<div class="hero-row">'
            . $iconHtml
            . '<div class="hero-info">'
            . '<h1 class="app-name">' . self::esc($appName) . ($c['verified_badge'] ? ' <svg class="badge" width="14" height="14" viewBox="0 0 24 24"><path fill="#1a73e8" d="M12 1l2.4 2.5 3.4-.5 1 3.3 3 1.6-1.3 3.1 1.3 3.1-3 1.6-1 3.3-3.4-.5L12 23l-2.4-2.5-3.4.5-1-3.3-3-1.6 1.3-3.1L2.2 9.9l3-1.6 1-3.3 3.4.5z"/><path fill="#fff" d="M10.6 16.2L7 12.6l1.4-1.4 2.2 2.2 5-5 1.4 1.4z"/></svg>' : '')
            . '</h1>'
            . '<div class="dev">' . self::esc($c['developer']) . '</div>'
            . '<div class="sub">' . self::esc($c['ads_label']) . ' · ' . self::esc($c['category']) . '</div>'
            . '<div class="sub rating-line">' . number_format($avg, 1, '.', '') . ' <span class="mini-stars">' . self::starsSvg($avg, 'var(--pwa-star)') . '</span> · ' . $totalFormatted . ' reviews · ' . self::esc($c['downloads']) . ' Downloads' . $liveBadgeHtml . '</div>'
            . '</div></div>'
            . '<button type="button" id="pwa-install-btn" class="install-btn install-trigger' . $glowClass . '">' . self::esc($c['button_text']) . '</button>'
            . '<div id="pwa-installing" class="installing" hidden>' . self::esc($t[5]) . '</div>'
            . '</section>';

        if ($screensHtml !== '') {
            $gpPage .= '<section class="shots">' . $screensHtml . '</section>';
        }
        if ($c['whats_new_enabled'] && trim((string) $c['whats_new_text']) !== '') {
            $gpPage .= '<section class="block"><h2>' . self::esc($t['whats_new'] ?? 'What’s new') . '</h2><p class="desc">' . nl2br(self::esc($c['whats_new_text'])) . '</p></section>';
        }
        if ($description !== '') {
            $gpPage .= '<section class="block"><h2>' . self::esc($t['about_app'] ?? 'About this app') . '</h2><p class="desc">' . nl2br(self::esc($description)) . '</p>'
                . ($tagsHtml !== '' ? '<div class="tags">' . $tagsHtml . '</div>' : '')
                . '</section>';
        }
        if ($total > 0) {
            $gpPage .= '<section class="block"><h2>' . self::esc($t['ratings_reviews'] ?? 'Ratings and reviews') . '</h2><div class="ratings-row">'
                . '<div class="big-avg">' . number_format($avg, 1, '.', '') . '</div><div class="hist">' . $histogram . '</div>'
                . '</div></section>';
        }

        $gpReviewsHtml = '';
        foreach (array_slice($c['comments'], 0, 12) as $cm) {
            $gpReviewsHtml .= '<div class="review">'
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
        if ($gpReviewsHtml !== '') {
            $gpPage .= '<section class="block"><h2>' . self::esc($t['reviews'] ?? 'Reviews') . '</h2>' . $gpReviewsHtml . '</section>';
        }
        if ($c['support_enabled'] && ($c['support_email'] !== '' || $c['support_address'] !== '')) {
            $gpPage .= '<section class="block"><h2>' . self::esc($t['support'] ?? 'Support') . '</h2><p class="desc">'
                . ($c['support_email'] !== '' ? self::esc($c['support_email']) . '<br>' : '')
                . self::esc($c['support_address'])
                . '</p></section>';
        }
        if ($c['bottom_menu']) {
            $gpPage .= '<nav class="bottom-menu"><span>🎮</span><span>📱</span><span>🔍</span><span>📚</span></nav>';
        }

        // ------------------------------------------------------------------
        // APPLE APP STORE LAYOUT
        // ------------------------------------------------------------------
        $iosReviewsCardsHtml = '';
        foreach (array_slice($c['comments'], 0, 10) as $cm) {
            $iosReviewsCardsHtml .= '<div class="ios-rev-card">'
                . '<div class="ios-rev-header">'
                . '<span class="ios-rev-title">' . self::esc($cm['name'] !== '' ? $cm['name'] : 'User') . '</span>'
                . '<span class="ios-rev-date">' . self::esc($cm['date']) . '</span>'
                . '</div>'
                . '<div class="ios-rev-stars">' . self::starsSvg((float) $cm['stars'], '#fbbc04', 12) . '</div>'
                . '<div class="ios-rev-body">' . nl2br(self::esc($cm['text'])) . '</div>'
                . ($cm['reply'] !== ''
                    ? '<div class="ios-rev-reply"><div class="ios-reply-head">' . self::esc($t['developer_response'] ?? 'Developer Response') . '</div>' . nl2br(self::esc($cm['reply'])) . '</div>'
                    : '')
                . '</div>';
        }

        $iosPage = '<div class="ios-store-wrap">'
            . '<header class="ios-nav">'
            . '<span class="ios-nav-back"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg> ' . self::esc($t['apps'] ?? 'Apps') . '</span>'
            . '<span class="ios-nav-share"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></span>'
            . '</header>'
            . '<section class="ios-hero">'
            . $iconHtml
            . '<div class="ios-hero-info">'
            . '<h1 class="ios-app-title">' . self::esc($appName) . '</h1>'
            . '<div class="ios-app-subtitle">' . self::esc($c['category']) . ' · ' . self::esc($c['developer']) . ' ' . $liveBadgeIos . '</div>'
            . '<div class="ios-action-row">'
            . '<button type="button" id="pwa-install-btn-ios" class="ios-get-btn install-trigger' . $glowClass . '">' . self::esc($t['get'] ?? 'GET') . '</button>'
            . '<button type="button" class="ios-more-btn">···</button>'
            . '</div>'
            . '<div class="ios-in-app-text">' . self::esc($c['ads_label']) . '</div>'
            . '</div>'
            . '</section>'
            . '<section class="ios-metrics-ribbon">'
            . '<div class="ios-metric-col"><span class="ios-m-top">' . $totalFormatted . ' ' . self::esc($t['ratings_count_label'] ?? 'RATINGS') . '</span><span class="ios-m-main">' . number_format($avg, 1, '.', '') . '</span><span class="ios-m-sub mini-stars">' . self::starsSvg($avg, '#8e8e93', 10) . '</span></div>'
            . '<div class="ios-metric-col"><span class="ios-m-top">' . self::esc($t['age_label'] ?? 'AGE') . '</span><span class="ios-m-main">17+</span><span class="ios-m-sub">' . self::esc($t['age_rating'] ?? 'Years Old') . '</span></div>'
            . '<div class="ios-metric-col"><span class="ios-m-top">' . self::esc($t['chart_label'] ?? 'CHART') . '</span><span class="ios-m-main">#1</span><span class="ios-m-sub">' . self::esc(mb_strimwidth((string)$c['category'], 0, 10, '..')) . '</span></div>'
            . '<div class="ios-metric-col"><span class="ios-m-top">' . self::esc($t['dev_label'] ?? 'DEVELOPER') . '</span><span class="ios-m-main"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span><span class="ios-m-sub">' . self::esc(mb_strimwidth((string)$c['developer'], 0, 10, '..')) . '</span></div>'
            . '<div class="ios-metric-col"><span class="ios-m-top">' . self::esc($t['lang_label'] ?? 'LANGUAGE') . '</span><span class="ios-m-main">' . strtoupper(self::esc($lang)) . '</span><span class="ios-m-sub">+6 More</span></div>'
            . '<div class="ios-metric-col"><span class="ios-m-top">' . self::esc($t['size_label'] ?? 'SIZE') . '</span><span class="ios-m-main">48.2</span><span class="ios-m-sub">MB</span></div>'
            . '</section>';

        if ($c['whats_new_enabled'] && trim((string) $c['whats_new_text']) !== '') {
            $iosPage .= '<section class="ios-block">'
                . '<div class="ios-block-header"><h2>' . self::esc($t['whats_new'] ?? 'What’s New') . '</h2><span class="ios-link">' . self::esc($t['version_history'] ?? 'Version History') . '</span></div>'
                . '<div class="ios-ver-line">' . self::esc($t['version'] ?? 'Version') . ' ' . self::esc($c['version']) . ' · 2d ago</div>'
                . '<p class="desc">' . nl2br(self::esc($c['whats_new_text'])) . '</p>'
                . '</section>';
        }

        if ($screensHtml !== '') {
            $iosPage .= '<section class="ios-block"><div class="ios-block-header"><h2>' . self::esc($t['preview'] ?? 'Preview') . '</h2><span class="ios-device-tag">iPhone</span></div>'
                . '<div class="shots ios-shots">' . $screensHtml . '</div></section>';
        }

        if ($description !== '') {
            $iosPage .= '<section class="ios-block">'
                . '<p class="desc">' . nl2br(self::esc($description)) . '</p>'
                . ($tagsHtml !== '' ? '<div class="tags">' . $tagsHtml . '</div>' : '')
                . '</section>';
        }

        if ($total > 0 || $iosReviewsCardsHtml !== '') {
            $iosPage .= '<section class="ios-block">'
                . '<div class="ios-block-header"><h2>' . self::esc($t['ratings_reviews'] ?? 'Ratings & Reviews') . '</h2><span class="ios-link">' . self::esc($t['see_all'] ?? 'See All') . '</span></div>'
                . '<div class="ratings-row ios-ratings-summary">'
                . '<div class="big-avg">' . number_format($avg, 1, '.', '') . '<div class="ios-out-of">out of 5</div></div>'
                . '<div class="hist">' . $histogram . '<div class="ios-ratings-total">' . $totalFormatted . ' ' . self::esc($t['ratings_count_label'] ?? 'Ratings') . '</div></div>'
                . '</div>'
                . ($iosReviewsCardsHtml !== '' ? '<div class="ios-reviews-scroll">' . $iosReviewsCardsHtml . '</div>' : '')
                . '</section>';
        }

        $iosPage .= '<section class="ios-block ios-info-table">'
            . '<div class="ios-block-header"><h2>' . self::esc($t['information'] ?? 'Information') . '</h2></div>'
            . '<div class="ios-info-row"><span class="ios-info-label">' . self::esc($t['provider'] ?? 'Provider') . '</span><span class="ios-info-val">' . self::esc($c['developer'] !== '' ? $c['developer'] : 'Orbitra LLC') . '</span></div>'
            . '<div class="ios-info-row"><span class="ios-info-label">' . self::esc($t['size'] ?? 'Size') . '</span><span class="ios-info-val">48.2 MB</span></div>'
            . '<div class="ios-info-row"><span class="ios-info-label">' . self::esc($t['category'] ?? 'Category') . '</span><span class="ios-info-val">' . self::esc($c['category']) . '</span></div>'
            . '<div class="ios-info-row"><span class="ios-info-label">' . self::esc($t['compatibility'] ?? 'Compatibility') . '</span><span class="ios-info-val">' . self::esc($t['compatibility_val'] ?? 'Works on this iPhone') . '</span></div>'
            . '<div class="ios-info-row"><span class="ios-info-label">' . self::esc($t['languages'] ?? 'Languages') . '</span><span class="ios-info-val">' . self::esc($t['languages_val'] ?? 'English and 6 more') . '</span></div>'
            . '<div class="ios-info-row"><span class="ios-info-label">' . self::esc($t['age_rating'] ?? 'Age Rating') . '</span><span class="ios-info-val">' . self::esc($t['age_val'] ?? '17+') . '</span></div>'
            . '</section>';

        if ($c['bottom_menu']) {
            $iosPage .= '<nav class="ios-tab-bar">'
                . '<div class="ios-tab-item"><span class="ios-tab-icon">📰</span><span>' . self::esc($t['today'] ?? 'Today') . '</span></div>'
                . '<div class="ios-tab-item active"><span class="ios-tab-icon">🚀</span><span>' . self::esc($t['games'] ?? 'Games') . '</span></div>'
                . '<div class="ios-tab-item"><span class="ios-tab-icon">📱</span><span>' . self::esc($t['apps'] ?? 'Apps') . '</span></div>'
                . '<div class="ios-tab-item"><span class="ios-tab-icon">🕹️</span><span>' . self::esc($t['arcade'] ?? 'Arcade') . '</span></div>'
                . '<div class="ios-tab-item"><span class="ios-tab-icon">🔍</span><span>' . self::esc($t['search'] ?? 'Search') . '</span></div>'
                . '</nav>';
        }
        $iosPage .= '</div>';

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

        // Push subscription screen (NOTIFICATION_* funnel step): shown by the
        // page JS only when the serve-time {vapid_public} macro carries a key
        // — no keys configured, no screen. Markup/strings stay inert otherwise.
        $pushScreen = '';
        if ($c['push_enabled']) {
            $pushScreen = '<div id="pwa-push" class="ios-overlay" hidden><div class="ios-card">'
                . '<h3>' . self::esc($t['push_title']) . '</h3>'
                . '<p class="ios-push-text">' . self::esc($t['push_text']) . '</p>'
                . '<button type="button" id="pwa-push-allow" class="ios-push-allow">' . self::esc($t['push_allow']) . '</button>'
                . '<button type="button" id="pwa-push-later" class="ios-push-later">' . self::esc($t['push_later']) . '</button>'
                . '</div></div>';
        }

        // In-app screen for app_action=screen: what the INSTALLED app shows
        // instead of the store listing. The CTA leads into the funnel.
        $appScreen = '';
        if ($c['app_action'] === 'screen') {
            $appTitle = $c['app_screen_title'] !== '' ? $c['app_screen_title'] : $appName;
            $appText = $c['app_screen_text'] !== '' ? $c['app_screen_text'] : '';
            $appBtn = $c['app_screen_button'] !== '' ? $c['app_screen_button'] : 'Play now';

            $iconInner = $iconSrc !== ''
                ? '<img src="' . self::esc($iconSrc) . '" alt="">'
                : '<span class="appscr-avatar-txt">' . self::esc(mb_substr($appName, 0, 1)) . '</span>';

            if ($c['app_screen_type'] === 'custom') {
                $customHtml = $c['app_screen_custom_html'] !== ''
                    ? $c['app_screen_custom_html']
                    : '<div style="display:flex;min-height:100vh;align-items:center;justify-content:center;flex-direction:column;gap:16px;padding:24px;text-align:center;background:#0d1117;color:#fff;">'
                    . '<h2>' . self::esc($appTitle) . '</h2>'
                    . ($appText !== '' ? '<p style="color:rgba(255,255,255,0.7);max-width:400px;">' . nl2br(self::esc($appText)) . '</p>' : '')
                    . '<button type="button" class="appscr-cta-btn install-trigger" style="max-width:280px;">' . self::esc($appBtn) . '</button>'
                    . '</div>';

                $customScriptTag = $c['app_screen_custom_js'] !== ''
                    ? '<script>' . $c['app_screen_custom_js'] . '</script>'
                    : '';

                $appScreen = '<div id="pwa-app-screen" class="appscr-custom-mode" hidden>'
                    . '<div class="appscr-custom-container">' . $customHtml . '</div>'
                    . $customScriptTag
                    . '</div>';
            } elseif ($c['app_screen_type'] === 'slot') {
                $appScreen = '<div id="pwa-app-screen" class="appscr-game-mode appscr-slot-mode" hidden>'
                    . '<div class="appscr-shell slot-shell">'
                    . '<header class="appscr-header">'
                    . '<div class="appscr-user-badge">'
                    . '<div class="appscr-avatar">' . $iconInner . '</div>'
                    . '<div class="appscr-user-details">'
                    . '<div class="appscr-user-name">' . self::esc($appName) . '</div>'
                    . '<div class="appscr-user-sub">● VIP 777</div>'
                    . '</div>'
                    . '</div>'
                    . '<div class="appscr-header-right">'
                    . '<div class="appscr-balance-pill"><span class="appscr-coin-icon">🪙</span><span class="appscr-coin-val" id="pwa-slot-balance">5,000 COINS</span></div>'
                    . '</div>'
                    . '</header>'
                    . '<div class="pwa-slot-cabinet">'
                    . '<div class="pwa-jackpot-ribbon"><span class="jackpot-glow">⚡ MEGA JACKPOT ⚡</span><span class="jackpot-val" id="pwa-slot-jackpot">$250,000.00</span></div>'
                    . '<div class="pwa-slot-window">'
                    . '<div class="pwa-slot-payline"></div>'
                    . '<div class="pwa-slot-reels">'
                    . '<div class="pwa-reel" id="pwa-reel-0"><div class="pwa-reel-strip"><div class="pwa-sym">🍒</div><div class="pwa-sym">🔔</div><div class="pwa-sym">💎</div><div class="pwa-sym">7️⃣</div><div class="pwa-sym">👑</div><div class="pwa-sym">🍇</div><div class="pwa-sym">7️⃣</div><div class="pwa-sym">⭐</div></div></div>'
                    . '<div class="pwa-reel" id="pwa-reel-1"><div class="pwa-reel-strip"><div class="pwa-sym">🔔</div><div class="pwa-sym">7️⃣</div><div class="pwa-sym">🍒</div><div class="pwa-sym">👑</div><div class="pwa-sym">💎</div><div class="pwa-sym">7️⃣</div><div class="pwa-sym">⭐</div><div class="pwa-sym">🍇</div></div></div>'
                    . '<div class="pwa-reel" id="pwa-reel-2"><div class="pwa-reel-strip"><div class="pwa-sym">👑</div><div class="pwa-sym">💎</div><div class="pwa-sym">7️⃣</div><div class="pwa-sym">🍒</div><div class="pwa-sym">🔔</div><div class="pwa-sym">7️⃣</div><div class="pwa-sym">🍇</div><div class="pwa-sym">⭐</div></div></div>'
                    . '</div>'
                    . '</div>'
                    . '<div class="pwa-slot-controls">'
                    . '<div class="pwa-slot-status" id="pwa-slot-msg">TAP SPIN TO WIN THE JACKPOT!</div>'
                    . '<button type="button" id="pwa-slot-spin-btn" class="pwa-slot-spin-btn"><span class="spin-glow"></span><span class="spin-txt">🎰 SPIN NOW!</span></button>'
                    . '<div class="pwa-slot-spins-left">🎁 1 FREE SPIN AVAILABLE</div>'
                    . '</div>'
                    . '</div>'
                    . '<div id="pwa-slot-win-modal" class="pwa-modal-overlay" hidden>'
                    . '<div class="pwa-modal-card">'
                    . '<div class="pwa-modal-confetti">🎉</div>'
                    . '<div class="pwa-modal-badge">🏆 BIG WINNER!</div>'
                    . '<h2 class="pwa-modal-title">' . self::esc($appTitle !== '' ? $appTitle : 'JACKPOT WON: $1,500!') . '</h2>'
                    . '<p class="pwa-modal-text">' . self::esc($appText !== '' ? $appText : 'Congratulations! Your exclusive welcome bonus has been activated.') . '</p>'
                    . '<div class="pwa-modal-timer">⚡ Offer expires in: <span class="pwa-countdown">04:59</span></div>'
                    . '<button type="button" id="pwa-slot-claim" class="appscr-cta-btn install-trigger">'
                    . '<span class="appscr-cta-lbl">' . self::esc($appBtn !== '' ? $appBtn : 'CLAIM BONUS & PLAY') . '</span>'
                    . '<span class="appscr-cta-arrow">➔</span>'
                    . '</button>'
                    . '</div>'
                    . '</div>'
                    . '</div>'
                    . '</div>';
            } elseif ($c['app_screen_type'] === 'wheel') {
                $appScreen = '<div id="pwa-app-screen" class="appscr-game-mode appscr-wheel-mode" hidden>'
                    . '<div class="appscr-shell wheel-shell">'
                    . '<header class="appscr-header">'
                    . '<div class="appscr-user-badge">'
                    . '<div class="appscr-avatar">' . $iconInner . '</div>'
                    . '<div class="appscr-user-details">'
                    . '<div class="appscr-user-name">' . self::esc($appName) . '</div>'
                    . '<div class="appscr-user-sub">● VIP CLUB</div>'
                    . '</div>'
                    . '</div>'
                    . '<div class="appscr-header-right">'
                    . '<div class="appscr-balance-pill"><span class="appscr-coin-icon">💎</span><span class="appscr-coin-val">VIP BONUS</span></div>'
                    . '</div>'
                    . '</header>'
                    . '<div class="pwa-wheel-stage">'
                    . '<div class="pwa-wheel-headline">' . self::esc($appTitle !== '' ? $appTitle : 'LUCKY BONUS WHEEL') . '</div>'
                    . '<div class="pwa-wheel-subhead">' . self::esc($appText !== '' ? $appText : 'Spin the wheel to unlock your exclusive welcome bonus!') . '</div>'
                    . '<div class="pwa-wheel-container">'
                    . '<div class="pwa-wheel-pointer">▼</div>'
                    . '<svg id="pwa-wheel-disc" class="pwa-wheel-disc" viewBox="0 0 360 360">'
                    . '<g transform="translate(180,180)">'
                    . '<path d="M0,0 L0,-170 A170,170 0 0,1 120.2,-120.2 Z" fill="#e74c3c"/>'
                    . '<text transform="rotate(22.5) translate(0,-115) rotate(-22.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">$500</text>'
                    . '<path d="M0,0 L120.2,-120.2 A170,170 0 0,1 170,0 Z" fill="#f39c12"/>'
                    . '<text transform="rotate(67.5) translate(0,-115) rotate(-67.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">100 FS</text>'
                    . '<path d="M0,0 L170,0 A170,170 0 0,1 120.2,120.2 Z" fill="#8e44ad"/>'
                    . '<text transform="rotate(112.5) translate(0,-115) rotate(-112.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">200%</text>'
                    . '<path d="M0,0 L120.2,120.2 A170,170 0 0,1 0,170 Z" fill="#27ae60"/>'
                    . '<text transform="rotate(157.5) translate(0,-115) rotate(-157.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">50 FS</text>'
                    . '<path d="M0,0 L0,170 A170,170 0 0,1 -120.2,120.2 Z" fill="#e67e22"/>'
                    . '<text transform="rotate(202.5) translate(0,-115) rotate(-202.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">$100</text>'
                    . '<path d="M0,0 L-120.2,120.2 A170,170 0 0,1 -170,0 Z" fill="#2980b9"/>'
                    . '<text transform="rotate(247.5) translate(0,-115) rotate(-247.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">VIP</text>'
                    . '<path d="M0,0 L-170,0 A170,170 0 0,1 -120.2,-120.2 Z" fill="#16a085"/>'
                    . '<text transform="rotate(292.5) translate(0,-115) rotate(-292.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">250 FS</text>'
                    . '<path d="M0,0 L-120.2,-120.2 A170,170 0 0,1 0,-170 Z" fill="#d35400"/>'
                    . '<text transform="rotate(337.5) translate(0,-115) rotate(-337.5)" fill="#fff" font-size="13" font-weight="bold" text-anchor="middle">JACKPOT</text>'
                    . '<circle r="36" fill="#1e272e" stroke="#ffd700" stroke-width="4"/>'
                    . '</g>'
                    . '</svg>'
                    . '<button type="button" id="pwa-wheel-spin-btn" class="pwa-wheel-spin-btn">SPIN</button>'
                    . '</div>'
                    . '<div class="pwa-wheel-spins-hint">⚡ 1 FREE SPIN REMAINING</div>'
                    . '</div>'
                    . '<div id="pwa-wheel-win-modal" class="pwa-modal-overlay" hidden>'
                    . '<div class="pwa-modal-card">'
                    . '<div class="pwa-modal-confetti">🎁</div>'
                    . '<div class="pwa-modal-badge">🎉 WINNER!</div>'
                    . '<h2 class="pwa-modal-title">JACKPOT + 250 FREE SPINS!</h2>'
                    . '<p class="pwa-modal-text">Your prize has been reserved! Claim it now before it expires.</p>'
                    . '<div class="pwa-modal-timer">⚡ Reservation expires in: <span class="pwa-countdown">04:59</span></div>'
                    . '<button type="button" id="pwa-wheel-claim" class="appscr-cta-btn install-trigger">'
                    . '<span class="appscr-cta-lbl">' . self::esc($appBtn !== '' ? $appBtn : 'CLAIM BONUS & PLAY') . '</span>'
                    . '<span class="appscr-cta-arrow">➔</span>'
                    . '</button>'
                    . '</div>'
                    . '</div>'
                    . '</div>'
                    . '</div>';
            } else {
                // Native Lobby / Dashboard
                $catLower = strtolower($c['category']);
                if (strpos($catLower, 'sport') !== false || strpos($catLower, 'bet') !== false) {
                    $tilesHtml = '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">⚽</span><span class="appscr-tile-name">Live Match</span><span class="appscr-tile-badge">LIVE 78\'</span></div>'
                        . '<div class="appscr-tile active install-trigger"><span class="appscr-tile-icon">🔥</span><span class="appscr-tile-name">Top Express</span><span class="appscr-tile-badge gold">+35% BOOST</span></div>'
                        . '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">🎯</span><span class="appscr-tile-name">Quick Bet</span><span class="appscr-tile-badge">1-CLICK</span></div>';
                    $balancePill = '<span class="appscr-coin-icon">🏆</span><span class="appscr-coin-val">FREE BET: $50</span>';
                    $tab2Name = 'Matches';
                    $tab2Icon = '⚽';
                } elseif (strpos($catLower, 'dating') !== false || strpos($catLower, 'love') !== false) {
                    $tilesHtml = '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">💃</span><span class="appscr-tile-name">New Match</span><span class="appscr-tile-badge gold">HOT</span></div>'
                        . '<div class="appscr-tile active install-trigger"><span class="appscr-tile-icon">💬</span><span class="appscr-tile-name">Chat Now</span><span class="appscr-tile-badge">3 NEW</span></div>'
                        . '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">❤️</span><span class="appscr-tile-name">Nearby</span><span class="appscr-tile-badge">2 KM</span></div>';
                    $balancePill = '<span class="appscr-coin-icon">💖</span><span class="appscr-coin-val">99+ LIKES</span>';
                    $tab2Name = 'Likes';
                    $tab2Icon = '❤️';
                } elseif (strpos($catLower, 'shop') !== false || strpos($catLower, 'market') !== false || strpos($catLower, 'ecom') !== false) {
                    $tilesHtml = '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">🛍️</span><span class="appscr-tile-name">Flash Sale</span><span class="appscr-tile-badge gold">-70%</span></div>'
                        . '<div class="appscr-tile active install-trigger"><span class="appscr-tile-icon">🚚</span><span class="appscr-tile-name">Free Delivery</span><span class="appscr-tile-badge">24H</span></div>'
                        . '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">🛒</span><span class="appscr-tile-name">My Cart</span><span class="appscr-tile-badge">3 ITEMS</span></div>';
                    $balancePill = '<span class="appscr-coin-icon">🛒</span><span class="appscr-coin-val">3 ITEMS</span>';
                    $tab2Name = 'Cart';
                    $tab2Icon = '🛒';
                } elseif (strpos($catLower, 'fit') !== false) {
                    $tilesHtml = '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">🔥</span><span class="appscr-tile-name">HIIT Burn</span><span class="appscr-tile-badge">25 MIN</span></div>'
                        . '<div class="appscr-tile active install-trigger"><span class="appscr-tile-icon">💪</span><span class="appscr-tile-name">Daily Plan</span><span class="appscr-tile-badge gold">DAY 1</span></div>'
                        . '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">🥗</span><span class="appscr-tile-name">Diet Guide</span><span class="appscr-tile-badge">PRO</span></div>';
                    $balancePill = '<span class="appscr-coin-icon">🔥</span><span class="appscr-coin-val">DAY 1 ACTIVE</span>';
                    $tab2Name = 'Workouts';
                    $tab2Icon = '🏋️';
                } else {
                    $tilesHtml = '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">🎰</span><span class="appscr-tile-name">Mega 777</span><span class="appscr-tile-badge">JACKPOT</span></div>'
                        . '<div class="appscr-tile active install-trigger"><span class="appscr-tile-icon">🎁</span><span class="appscr-tile-name">Daily Wheel</span><span class="appscr-tile-badge gold">FREE SPIN</span></div>'
                        . '<div class="appscr-tile install-trigger"><span class="appscr-tile-icon">💎</span><span class="appscr-tile-name">VIP Royal</span><span class="appscr-tile-badge">HOT</span></div>';
                    $balancePill = '<span class="appscr-coin-icon">🪙</span><span class="appscr-coin-val">10,000 COINS</span>';
                    $tab2Name = 'Games';
                    $tab2Icon = '🎮';
                }

                $heroBg = $c['app_screen_image'] !== ''
                    ? '<div class="appscr-hero-wrap"><img class="appscr-hero-img" src="' . self::esc($c['app_screen_image']) . '" alt="" onerror="this.parentNode.style.display=\'none\'"><div class="appscr-hero-vignette"></div></div>'
                    : '<div class="appscr-hero-wrap appscr-hero-gradient"><div class="appscr-hero-vignette"></div></div>';

                $appScreen = '<div id="pwa-app-screen" hidden>'
                    . '<div class="appscr-bg-canvas">'
                    . $heroBg
                    . '<div class="appscr-shell">'
                    . '<header class="appscr-header">'
                    . '<div class="appscr-user-badge">'
                    . '<div class="appscr-avatar">' . $iconInner . '</div>'
                    . '<div class="appscr-user-details">'
                    . '<div class="appscr-user-name">' . self::esc($appName) . '</div>'
                    . '<div class="appscr-user-sub">● VIP CLUB</div>'
                    . '</div>'
                    . '</div>'
                    . '<div class="appscr-header-right">'
                    . '<div class="appscr-balance-pill">' . $balancePill . '</div>'
                    . '<div class="appscr-bell">🔔</div>'
                    . '</div>'
                    . '</header>'
                    . '<div class="appscr-ticker-wrap">'
                    . '<div class="appscr-ticker-content">🔥 <span>Alex M. won $4,200</span> • <span>Elena R. won 250 FS</span> • <span>David K. won $1,850</span> • <span>Sarah W. unlocked VIP</span></div>'
                    . '</div>'
                    . '<div class="appscr-body">'
                    . '<div class="appscr-main-card">'
                    . '<div class="appscr-card-badges">'
                    . '<span class="appscr-badge-live">● LIVE BONUS</span>'
                    . '<span class="appscr-badge-rtp">⚡ INSTANT ACCESS</span>'
                    . '</div>'
                    . '<h1 class="appscr-headline">' . self::esc($appTitle) . '</h1>'
                    . ($appText !== '' ? '<p class="appscr-subtext">' . nl2br(self::esc($appText)) . '</p>' : '')
                    . '<div class="appscr-cta-wrap">'
                    . '<button type="button" id="pwa-app-cta" class="appscr-cta-btn install-trigger">'
                    . '<span class="appscr-cta-glow"></span>'
                    . '<span class="appscr-cta-lbl">' . self::esc($appBtn) . '</span>'
                    . '<span class="appscr-cta-arrow">➔</span>'
                    . '</button>'
                    . '<div class="appscr-trust-row">'
                    . '<span>🔒 256-Bit SSL</span><span>•</span><span>⚡ Instant Payouts</span><span>•</span><span>🎯 18+</span>'
                    . '</div>'
                    . '</div>'
                    . '</div>'
                    . '<div class="appscr-lobby-section">'
                    . '<div class="appscr-section-head"><span>POPULAR TODAY</span><span class="appscr-see-all">ALL &gt;</span></div>'
                    . '<div class="appscr-tiles-row">' . $tilesHtml . '</div>'
                    . '</div>'
                    . '</div>'
                    . '<nav class="appscr-tabbar">'
                    . '<div class="appscr-tab active"><span class="appscr-tab-icon">🏠</span><span>Lobby</span></div>'
                    . '<div class="appscr-tab"><span class="appscr-tab-icon">' . $tab2Icon . '</span><span>' . $tab2Name . '</span></div>'
                    . '<div class="appscr-tab"><span class="appscr-tab-icon">🎁</span><span>Bonuses</span></div>'
                    . '<div class="appscr-tab"><span class="appscr-tab-icon">👤</span><span>Account</span></div>'
                    . '</nav>'
                    . '</div>'
                    . '</div>'
                    . '</div>';
            }
        }

        // The two serve-time placeholders stay literal in the source: the
        // lander route replaces them per request, so the SW's network-first
        // navigation rule always pairs a fresh page with a fresh click id.
        $js = <<<'JS'
(function () {
  var cfg = __CFG_JSON__;
  var subid = '__SUBID__';
  var lpUrl = '__LP_URL__';
  var campaignUrl = '__CAMPAIGN_URL__';
  var VAPID = '__VAPID_PUBLIC__';
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

  // Synthesized audio & haptics for casino/gaming atmosphere
  function playTick() {
    if (!cfg.sound) return;
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var osc = ctx.createOscillator();
      var g = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(600, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(150, ctx.currentTime + 0.04);
      g.gain.setValueAtTime(0.2, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.04);
      osc.connect(g);
      g.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.04);
    } catch (e) {}
  }

  function playWin() {
    if (!cfg.sound) return;
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      [523.25, 659.25, 783.99, 1046.50].forEach(function (freq, i) {
        var osc = ctx.createOscillator();
        var g = ctx.createGain();
        osc.type = 'triangle';
        osc.frequency.value = freq;
        g.gain.setValueAtTime(0.25, ctx.currentTime + i * 0.1);
        g.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + i * 0.1 + 0.35);
        osc.connect(g);
        g.connect(ctx.destination);
        osc.start(ctx.currentTime + i * 0.1);
        osc.stop(ctx.currentTime + i * 0.1 + 0.35);
      });
    } catch (e) {}
  }

  function vib(pat) {
    if (!cfg.vibration) return;
    if (navigator.vibrate) try { navigator.vibrate(pat); } catch (e) {}
  }

  function startTimer() {
    var els = document.querySelectorAll('.pwa-countdown');
    if (!els.length) return;
    var sec = 299;
    var t = setInterval(function () {
      sec--;
      if (sec < 0) { clearInterval(t); return; }
      var m = String(Math.floor(sec / 60)).padStart(2, '0');
      var s = String(sec % 60).padStart(2, '0');
      for (var i = 0; i < els.length; i++) els[i].textContent = m + ':' + s;
    }, 1000);
  }

  // Expose helpers globally so custom mini-games & tracking scripts can call them
  window.orbitraRedirect = redirect;
  window.orbitraBeacon = beacon;
  window.redirect = redirect;
  window.beacon = beacon;

  var preloader = document.getElementById('pwa-preloader');
  if (preloader) { setTimeout(function () { preloader.classList.add('done'); }, 600); }

  var deferred = null;
  function iosOverlay(show) {
    var el = document.getElementById('pwa-ios');
    if (el) el.hidden = !show;
  }
  var closeBtn = document.getElementById('pwa-ios-close');
  if (closeBtn) closeBtn.addEventListener('click', function () { iosOverlay(false); });

  // --- Push subscription (NOTIFICATION_* funnel step) ----------------------
  var pushDone = false;
  try { pushDone = !!localStorage.getItem('orbitra_push_done'); } catch (e) {}
  function pushAvailable() {
    return cfg.push && VAPID && 'PushManager' in window && 'Notification' in window && !pushDone;
  }
  var pushBusy = false;
  var pushSettled = false;
  function afterPush() {
    // The visitor answered the prompt INSIDE the installed app — mark the
    // offer as made and hand control to the configured app action. Guarded:
    // the fail-safe timer and the real completion callback both land here,
    // and running performAppAction() twice would fire two redirects.
    if (pushSettled) return;
    pushSettled = true;
    try { localStorage.setItem('orbitra_push_done', '1'); } catch (e) {}
    var el = document.getElementById('pwa-push');
    if (el) el.hidden = true;
    performAppAction();
  }
  function urlB64ToU8(b64) {
    var raw = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4));
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }
  function enablePush() {
    if (pushBusy) return;
    pushBusy = true;
    beacon('prompt'); // the permission dialog is about to be shown
    // Fail-safe: if the subscribe flow cannot complete (broken SW, blocked
    // endpoint) the visitor still flows to the offer instead of stalling.
    // It is armed only AFTER the permission answer: the native dialog has no
    // timeout of its own, and a visitor who reads it for eleven seconds would
    // otherwise have the app action fire out from under an open prompt.
    var failSafe = null;
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { beacon('decline'); afterPush(); return; }
      failSafe = setTimeout(afterPush, 10000);
      navigator.serviceWorker.ready.then(function (reg) {
        reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToU8(VAPID) })
          .then(function (sub) {
            var s = sub.toJSON();
            fetch('/push_subscribe?lang=' + encodeURIComponent(navigator.language || ''), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ subid: subid, endpoint: s.endpoint, keys: s.keys, expirationTime: s.expirationTime || null })
            }).then(function () { clearTimeout(failSafe); afterPush(); }).catch(function () { clearTimeout(failSafe); afterPush(); });
          }).catch(function () { clearTimeout(failSafe); afterPush(); });
      }).catch(function () { clearTimeout(failSafe); afterPush(); });
    }).catch(function () { clearTimeout(failSafe); afterPush(); });
  }
  function showPush() {
    // The notification offer belongs to the INSTALLED app, never to the store
    // listing — asking on the listing burns the one permission prompt the
    // browser grants before the visitor has installed anything. The caller
    // already checks isStandalone; this second gate is what a CSS or markup
    // regression has to get past to put the card back on the listing.
    if (!isStandalone) return;
    var el = document.getElementById('pwa-push');
    if (el) el.hidden = false;
  }
  function showAppScreen() {
    var el = document.getElementById('pwa-app-screen');
    if (el) el.hidden = false;
  }
  function performAppAction() {
    // What the INSTALLED app does on open: 'offer' = straight into the
    // funnel, 'screen' = custom in-app screen whose CTA leads there,
    // 'store' = keep showing the listing (useful while testing).
    if (cfg.appAction === 'campaign') { if (campaignUrl) window.location.href = campaignUrl; return; }
    if (cfg.appAction === 'offer') { redirect(); return; }
    if (cfg.appAction === 'screen') { showAppScreen(); return; }
  }
  var appCta = document.getElementById('pwa-app-cta');
  if (appCta) appCta.addEventListener('click', function () { beacon('intent'); redirect(); });
  var pushAllow = document.getElementById('pwa-push-allow');
  var pushLater = document.getElementById('pwa-push-later');
  if (pushAllow) pushAllow.addEventListener('click', enablePush);
  if (pushLater) pushLater.addEventListener('click', afterPush);

  // --- Slot machine interactive engine ---
  var slotSpinBtn = document.getElementById('pwa-slot-spin-btn');
  var slotWinModal = document.getElementById('pwa-slot-win-modal');
  var slotMsg = document.getElementById('pwa-slot-msg');
  var slotBalance = document.getElementById('pwa-slot-balance');
  var isSlotSpinning = false;
  if (slotSpinBtn) {
    slotSpinBtn.addEventListener('click', function () {
      if (isSlotSpinning) return;
      isSlotSpinning = true;
      beacon('intent');
      vib([40, 30, 40]);
      slotSpinBtn.disabled = true;
      if (slotMsg) slotMsg.textContent = 'SPINNING THE REELS...';
      var r0 = document.getElementById('pwa-reel-0');
      var r1 = document.getElementById('pwa-reel-1');
      var r2 = document.getElementById('pwa-reel-2');
      if (r0) r0.classList.add('spinning');
      if (r1) setTimeout(function(){ r1.classList.add('spinning'); }, 150);
      if (r2) setTimeout(function(){ r2.classList.add('spinning'); }, 300);

      var tickInterval = setInterval(playTick, 180);

      setTimeout(function () {
        if (r0) { r0.classList.remove('spinning'); r0.querySelector('.pwa-reel-strip').style.transform = 'translateY(-240px)'; vib(30); }
      }, 1400);
      setTimeout(function () {
        if (r1) { r1.classList.remove('spinning'); r1.querySelector('.pwa-reel-strip').style.transform = 'translateY(-240px)'; vib(30); }
      }, 1700);
      setTimeout(function () {
        clearInterval(tickInterval);
        if (r2) { r2.classList.remove('spinning'); r2.querySelector('.pwa-reel-strip').style.transform = 'translateY(-240px)'; }
        if (slotBalance) slotBalance.textContent = '25,000 COINS!';
        if (slotMsg) slotMsg.textContent = '🎉 JACKPOT WINNER!';
        playWin();
        vib([80, 50, 150, 50, 200]);
        setTimeout(function () {
          if (slotWinModal) slotWinModal.hidden = false;
          startTimer();
          isSlotSpinning = false;
        }, 500);
      }, 2000);
    });
  }

  // --- Lucky wheel interactive engine ---
  var wheelSpinBtn = document.getElementById('pwa-wheel-spin-btn');
  var wheelDisc = document.getElementById('pwa-wheel-disc');
  var wheelWinModal = document.getElementById('pwa-wheel-win-modal');
  var isWheelSpinning = false;
  if (wheelSpinBtn) {
    wheelSpinBtn.addEventListener('click', function () {
      if (isWheelSpinning) return;
      isWheelSpinning = true;
      beacon('intent');
      vib([50, 40, 50]);
      wheelSpinBtn.disabled = true;
      if (wheelDisc) {
        var targetDeg = 1800 + 337.5;
        wheelDisc.style.transition = 'transform 3.5s cubic-bezier(0.12, 0.95, 0.2, 1)';
        wheelDisc.style.transform = 'rotate(' + targetDeg + 'deg)';
      }
      var wTick = setInterval(playTick, 240);
      setTimeout(function () {
        clearInterval(wTick);
        playWin();
        vib([100, 60, 200]);
        if (wheelWinModal) wheelWinModal.hidden = false;
        startTimer();
        isWheelSpinning = false;
      }, 3700);
    });
  }

  function handleInstallClick() {
    beacon('intent');
    if (isStandalone || window.__PWA_FORCE_APP_SCREEN === true) { later(0, redirect); return; }
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
  }

  var triggers = document.querySelectorAll('.install-trigger');
  for (var i = 0; i < triggers.length; i++) {
    triggers[i].addEventListener('click', handleInstallClick);
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
    // The store page never asks for notifications — the installed app does
    // (see the standalone branch). Straight to the offer per config here.
    later(cfg.install, redirect);
  });

  if (window.__PWA_FORCE_APP_SCREEN === true) {
    showAppScreen();
  }

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
    // The installed app is the right place for the push offer — once per
    // browser. After the answer (or with push disabled) the configured app
    // action takes over.
    if (pushAvailable()) { showPush(); return; }
    performAppAction();
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
        $targetActionUrl = '{lp_url}';
        if (($c['action_target'] ?? '') === 'to_campaign' && (int) ($c['action_campaign_id'] ?? 0) > 0) {
            $targetActionUrl = '/?campaign_id=' . (int) $c['action_campaign_id'] . '&subid={subid}';
        } elseif (($c['action_target'] ?? '') === 'to_url' && trim((string) ($c['action_url'] ?? '')) !== '') {
            $targetActionUrl = trim((string) $c['action_url']);
        } elseif (($c['action_target'] ?? '') === 'not_found') {
            $targetActionUrl = '/404';
        }

        // app_action=campaign: opening the installed app goes straight into the
        // chosen campaign (its own rotation splits the traffic); empty until a
        // campaign is picked, in which case the open falls back to the listing.
        $campaignUrl = '';
        if (($c['app_action'] ?? '') === 'campaign' && (int) ($c['app_campaign_id'] ?? 0) > 0) {
            $campaignUrl = '/?campaign_id=' . (int) $c['app_campaign_id'] . '&subid={subid}';
        }

        $js = str_replace(
            ['__CFG_JSON__', '__SUBID__', '__LP_URL__', '__CAMPAIGN_URL__', '__VAPID_PUBLIC__'],
            [json_encode($cfgForJs, JSON_UNESCAPED_UNICODE), '{subid}', $targetActionUrl, $campaignUrl, '{vapid_public}'],
            $js
        );

        $css = <<<CSS
:root{--pwa-primary:$scheme;--pwa-bg:$bg;--pwa-surface:$surface;--pwa-text:$text;--pwa-muted:$muted;--pwa-border:$border;--pwa-star:#fbbc04}
*{box-sizing:border-box;margin:0;padding:0}
/* The overlays below (.ios-overlay, .pwa-modal-overlay) set display:flex,
   and an AUTHOR display rule beats the UA sheet's [hidden]{display:none}
   whatever the specificity. Without this line the install hint, the push
   prompt and the win modals render on the store page from the first paint
   and never go away when the JS sets el.hidden = true. !important so no
   later rule (or a preset's custom CSS) can win it back. */
[hidden]{display:none!important}
body{font-family:Roboto,-apple-system,'Segoe UI',Arial,sans-serif;background:var(--pwa-bg);color:var(--pwa-text);padding-bottom:64px;-webkit-font-smoothing:antialiased}

/* Store style switching */
html[data-store="app_store"] body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","SF Pro Text","Helvetica Neue",sans-serif}
html[data-store="app_store"] .store-gp{display:none!important}
html[data-store="app_store"] .store-ios{display:block!important}
html:not([data-store="app_store"]) .store-gp{display:block!important}
html:not([data-store="app_store"]) .store-ios{display:none!important}

.preloader{position:fixed;inset:0;background:var(--pwa-bg);display:flex;align-items:center;justify-content:center;z-index:50;transition:opacity .35s;opacity:1}
.preloader.done{opacity:0;pointer-events:none}
.spin{width:34px;height:34px;border-radius:50%;border:3px solid var(--pwa-border);border-top-color:var(--pwa-primary);animation:pspin .8s linear infinite}
@keyframes pspin{to{transform:rotate(360deg)}}

/* Google Play Styles */
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
.install-btn{margin:16px 0 6px;background:var(--pwa-primary);color:#fff;border:none;border-radius:22px;padding:11px 38px;font-size:15px;font-weight:500;width:100%;cursor:pointer}
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
.bottom-menu{position:fixed;left:0;right:0;bottom:0;background:var(--pwa-bg);border-top:1px solid var(--pwa-border);display:flex;justify-content:space-around;padding:12px 0;font-size:20px;z-index:20}
.share-row{display:flex;gap:14px;padding:6px 16px 12px}
.share-btn{width:42px;height:42px;border-radius:50%;background:var(--pwa-surface);display:flex;align-items:center;justify-content:center;font-size:18px}

/* Apple App Store Styles */
.ios-store-wrap{padding-top:4px}
.ios-nav{display:flex;align-items:center;justify-content:space-between;padding:10px 16px 6px;position:sticky;top:0;background:var(--pwa-bg);z-index:10}
.ios-nav-back{color:var(--pwa-primary);font-size:16px;display:flex;align-items:center;gap:4px;font-weight:400;cursor:pointer}
.ios-nav-share{color:var(--pwa-primary);font-size:16px;cursor:pointer}
.ios-hero{display:flex;gap:16px;padding:12px 18px 16px;align-items:flex-start}
.ios-hero .app-icon{width:112px;height:112px;border-radius:24px;border:0.5px solid var(--pwa-border);box-shadow:0 6px 16px rgba(0,0,0,0.08)}
.ios-hero-info{flex:1;min-width:0}
.ios-app-title{font-size:21px;font-weight:700;letter-spacing:-0.4px;line-height:1.2;color:var(--pwa-text)}
.ios-app-subtitle{font-size:13px;color:var(--pwa-muted);margin-top:3px;font-weight:400}
.ios-action-row{display:flex;align-items:center;gap:10px;margin-top:12px}
.ios-get-btn{background:var(--pwa-primary);color:#fff;border:none;border-radius:18px;padding:6px 20px;font-size:14px;font-weight:700;letter-spacing:0.3px;cursor:pointer}
.ios-more-btn{width:30px;height:30px;border-radius:50%;background:var(--pwa-surface);border:none;color:var(--pwa-primary);font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.ios-in-app-text{font-size:9px;color:var(--pwa-muted);margin-top:4px}
.ios-metrics-ribbon{display:flex;overflow-x:auto;padding:14px 16px;border-top:0.5px solid var(--pwa-border);border-bottom:0.5px solid var(--pwa-border);margin:4px 0 16px;scrollbar-width:none}
.ios-metrics-ribbon::-webkit-scrollbar{display:none}
.ios-metric-col{flex:1;min-width:76px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;position:relative;padding:0 8px}
.ios-metric-col:not(:last-child)::after{content:'';position:absolute;right:0;top:15%;height:70%;width:0.5px;background:var(--pwa-border)}
.ios-m-top{font-size:10px;font-weight:600;color:var(--pwa-muted);letter-spacing:0.2px}
.ios-m-main{font-size:19px;font-weight:700;color:var(--pwa-text);margin:2px 0 1px;display:flex;align-items:center;justify-content:center;height:24px}
.ios-m-sub{font-size:11px;color:var(--pwa-muted)}
.ios-block{padding:14px 18px;border-bottom:0.5px solid var(--pwa-border)}
.ios-block-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.ios-block-header h2{font-size:19px;font-weight:700;color:var(--pwa-text)}
.ios-link{color:var(--pwa-primary);font-size:14px;font-weight:400;cursor:pointer}
.ios-device-tag{font-size:12px;color:var(--pwa-muted);font-weight:500}
.ios-ver-line{font-size:13px;color:var(--pwa-muted);margin-bottom:8px}
.ios-shots .shot{height:320px;border-radius:18px;border:0.5px solid var(--pwa-border);box-shadow:0 4px 16px rgba(0,0,0,0.06)}
.ios-ratings-summary .big-avg{font-size:52px;font-weight:700;letter-spacing:-1px}
.ios-out-of{font-size:13px;color:var(--pwa-muted);font-weight:500;margin-top:-6px}
.ios-ratings-total{font-size:12px;color:var(--pwa-muted);text-align:right;margin-top:4px}
.ios-reviews-scroll{display:flex;gap:12px;overflow-x:auto;margin-top:16px;padding-bottom:6px;scrollbar-width:none}
.ios-reviews-scroll::-webkit-scrollbar{display:none}
.ios-rev-card{width:290px;flex:none;background:var(--pwa-surface);border-radius:14px;padding:14px 16px}
.ios-rev-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.ios-rev-title{font-size:13px;font-weight:600;color:var(--pwa-text)}
.ios-rev-date{font-size:11px;color:var(--pwa-muted)}
.ios-rev-stars{display:flex;gap:1px;margin-bottom:6px}
.ios-rev-body{font-size:13px;line-height:1.4;color:var(--pwa-text)}
.ios-rev-reply{margin-top:10px;background:rgba(0,0,0,0.04);border-radius:10px;padding:8px 10px;font-size:12px}
.ios-reply-head{font-weight:600;margin-bottom:2px}
.ios-info-table{padding-bottom:6px}
.ios-info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;font-size:13px;border-bottom:0.5px solid var(--pwa-border)}
.ios-info-row:last-child{border-bottom:none}
.ios-info-label{color:var(--pwa-muted)}
.ios-info-val{color:var(--pwa-text);font-weight:500;text-align:right;max-width:60%}
.ios-tab-bar{position:fixed;left:0;right:0;bottom:0;background:var(--pwa-bg);border-top:0.5px solid var(--pwa-border);display:flex;justify-content:space-around;padding:8px 0 20px;z-index:20}
.ios-tab-item{display:flex;flex-direction:column;align-items:center;gap:3px;font-size:10px;color:var(--pwa-muted);font-weight:500}
.ios-tab-item.active{color:var(--pwa-primary)}
.ios-tab-icon{font-size:18px}

/* Instruction Overlay */
.ios-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center;z-index:60}
.ios-card{background:var(--pwa-bg);color:var(--pwa-text);border-radius:18px 18px 0 0;padding:22px 20px;max-width:420px;width:100%}
.ios-card h3{font-size:17px;margin-bottom:12px}
.ios-card ol{padding-left:20px;font-size:14px;line-height:1.7}
.ios-card button{margin-top:14px;background:var(--pwa-surface);border:none;border-radius:20px;padding:10px 26px;font-size:14px;color:var(--pwa-text);width:100%;cursor:pointer}
.ios-push-text{font-size:14px;line-height:1.45;color:var(--pwa-muted);margin-bottom:4px}
.ios-push-allow{background:var(--pwa-primary)!important;color:#fff!important}
.ios-push-later{background:transparent!important;color:var(--pwa-muted)!important;margin-top:8px!important}
/* Native In-App Screen Styles (app_action=screen) */
#pwa-app-screen{position:fixed;inset:0;background:#070a10;z-index:40;overflow-y:auto;overflow-x:hidden;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
.appscr-bg-canvas{min-height:100%;position:relative;display:flex;flex-direction:column;background:radial-gradient(circle at 50% 20%, rgba(26,115,232,0.15), transparent 70%), #070a10}
.appscr-hero-wrap{position:absolute;top:0;left:0;right:0;height:48%;max-height:360px;overflow:hidden;pointer-events:none;z-index:0}
.appscr-hero-img{width:100%;height:100%;object-fit:cover;object-position:top center}
.appscr-hero-gradient{background:linear-gradient(180deg, var(--pwa-primary) 0%, rgba(7,10,16,0.9) 100%)}
.appscr-hero-vignette{position:absolute;inset:0;background:linear-gradient(to bottom, rgba(7,10,16,0.1) 0%, rgba(7,10,16,0.7) 65%, #070a10 100%)}
.appscr-shell{position:relative;z-index:1;flex:1;display:flex;flex-direction:column;padding:12px 16px 72px;max-width:440px;margin:0 auto;width:100%;box-sizing:border-box}
.appscr-header{display:flex;align-items:center;justify-content:space-between;padding:4px 0 10px;gap:8px}
.appscr-user-badge{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.08);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);padding:4px 10px 4px 4px;border-radius:24px;border:1px solid rgba(255,255,255,0.14)}
.appscr-avatar{width:32px;height:32px;border-radius:50%;overflow:hidden;background:var(--pwa-primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;border:1.5px solid rgba(255,255,255,0.3)}
.appscr-avatar img{width:100%;height:100%;object-fit:cover}
.appscr-avatar-txt{display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:16px;font-weight:700}
.appscr-user-name{font-size:12px;font-weight:700;line-height:1.2;color:#fff;max-width:105px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.appscr-user-sub{font-size:9px;color:#00e676;font-weight:700;letter-spacing:0.4px}
.appscr-header-right{display:flex;align-items:center;gap:6px}
.appscr-balance-pill{display:flex;align-items:center;gap:4px;background:rgba(245,176,65,0.18);border:1px solid rgba(245,176,65,0.45);border-radius:18px;padding:5px 9px;font-size:10px;font-weight:800;color:#ffd700;backdrop-filter:blur(12px)}
.appscr-bell{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:13px}
.appscr-body{flex:1;display:flex;flex-direction:column;justify-content:flex-end;padding-top:70px}
.appscr-main-card{background:rgba(18,24,38,0.8);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px);border:1px solid rgba(255,255,255,0.14);border-radius:20px;padding:18px 16px 16px;box-shadow:0 16px 40px rgba(0,0,0,0.55);text-align:center}
.appscr-card-badges{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:10px;flex-wrap:wrap}
.appscr-badge-live{background:#e74c3c;color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:8px;letter-spacing:0.4px;animation:pwaPulse 1.8s infinite}
.appscr-badge-rtp{background:rgba(255,255,255,0.12);color:#ffd700;font-size:9px;font-weight:700;padding:2px 7px;border-radius:8px}
.appscr-headline{font-size:19px;font-weight:800;line-height:1.25;color:#ffffff;text-shadow:0 2px 12px rgba(0,0,0,0.7);margin-bottom:6px}
.appscr-subtext{font-size:12px;line-height:1.45;color:rgba(255,255,255,0.8);margin-bottom:14px}
.appscr-cta-wrap{margin-top:8px}
.appscr-cta-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:14px 20px;font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;color:#fff;background:linear-gradient(135deg, var(--pwa-primary) 0%, color-mix(in srgb, var(--pwa-primary) 70%, #fff) 100%);border:none;border-radius:26px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,0.45), 0 0 20px color-mix(in srgb, var(--pwa-primary) 40%, transparent);position:relative;overflow:hidden;transition:transform 0.15s ease}
.appscr-cta-btn:active{transform:scale(0.98)}
.appscr-cta-arrow{font-size:16px;transition:transform 0.2s}
.appscr-trust-row{display:flex;align-items:center;justify-content:center;gap:6px;font-size:9px;color:rgba(255,255,255,0.55);margin-top:8px;font-weight:500}
.appscr-lobby-section{margin-top:14px}
.appscr-section-head{display:flex;align-items:center;justify-content:space-between;font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);letter-spacing:0.5px;margin-bottom:8px;padding:0 2px}
.appscr-see-all{color:var(--pwa-primary);cursor:pointer}
.appscr-tiles-row{display:grid;grid-template-columns:repeat(3, 1fr);gap:6px}
.appscr-tile{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:10px 4px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:3px;cursor:pointer;transition:all 0.15s}
.appscr-tile:active{transform:scale(0.95)}
.appscr-tile.active{background:rgba(255,255,255,0.12);border-color:var(--pwa-primary);box-shadow:0 4px 14px rgba(0,0,0,0.3)}
.appscr-tile-icon{font-size:20px;margin-bottom:1px}
.appscr-tile-name{font-size:10px;font-weight:700;color:#fff;line-height:1.2}
.appscr-tile-badge{font-size:7.5px;font-weight:800;padding:1px 4px;border-radius:5px;background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.8)}
.appscr-tile-badge.gold{background:rgba(245,176,65,0.25);color:#ffd700}
.appscr-tabbar{position:fixed;left:0;right:0;bottom:0;background:rgba(8,12,18,0.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-top:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-around;padding:6px 0 14px;z-index:50}
.appscr-tab{display:flex;flex-direction:column;align-items:center;gap:2px;font-size:9px;color:rgba(255,255,255,0.45);font-weight:600;cursor:pointer}
.appscr-tab.active{color:var(--pwa-primary)}
.appscr-tab-icon{font-size:16px}
@keyframes pwaPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.8;transform:scale(1.05)}}

/* Custom App Screen Mode */
.appscr-custom-mode{min-height:100vh;background:#0d1117;color:#fff;overflow-y:auto}
.appscr-custom-container{width:100%;min-height:100vh}

/* Slot Machine Mode */
.appscr-slot-mode{background:radial-gradient(circle at 50% 30%, #1a103c 0%, #080612 100%)}
.slot-shell{display:flex;flex-direction:column;align-items:center;min-height:100vh;justify-content:space-between;padding-bottom:30px}
.pwa-slot-cabinet{width:100%;max-width:380px;background:linear-gradient(180deg, #241442 0%, #120924 100%);border:2px solid #ffd700;border-radius:24px;padding:16px;box-shadow:0 0 35px rgba(255,215,0,0.25), 0 20px 50px rgba(0,0,0,0.8);margin:auto 0}
.pwa-jackpot-ribbon{display:flex;flex-direction:column;align-items:center;gap:2px;background:linear-gradient(90deg, #d35400, #f39c12, #d35400);padding:6px 12px;border-radius:12px;margin-bottom:14px;box-shadow:0 4px 15px rgba(243,156,18,0.4)}
.jackpot-glow{font-size:10px;font-weight:900;letter-spacing:1px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,0.6)}
.jackpot-val{font-size:18px;font-weight:900;color:#fff;font-family:monospace;letter-spacing:0.5px}
.pwa-slot-window{background:#000;border:3px solid #3d2375;border-radius:16px;padding:8px;position:relative;overflow:hidden;box-shadow:inset 0 0 20px rgba(0,0,0,0.9)}
.pwa-slot-payline{position:absolute;left:0;right:0;top:50%;height:3px;background:linear-gradient(90deg, transparent, #ff007f, #ffd700, #ff007f, transparent);transform:translateY(-50%);z-index:10;pointer-events:none;box-shadow:0 0 10px #ff007f}
.pwa-slot-reels{display:grid;grid-template-columns:repeat(3, 1fr);gap:8px;height:120px;overflow:hidden}
.pwa-reel{background:#180d30;border-radius:10px;overflow:hidden;position:relative;display:flex;justify-content:center}
.pwa-reel-strip{display:flex;flex-direction:column;transition:transform 0.4s cubic-bezier(0.1, 0.9, 0.2, 1);will-change:transform}
.pwa-sym{height:80px;display:flex;align-items:center;justify-content:center;font-size:42px;user-select:none}
.pwa-reel.spinning .pwa-reel-strip{animation:reelRoll 0.18s linear infinite;filter:blur(2px)}
@keyframes reelRoll{0%{transform:translateY(0)}100%{transform:translateY(-240px)}}
.pwa-slot-controls{margin-top:16px;display:flex;flex-direction:column;align-items:center;gap:10px}
.pwa-slot-status{font-size:11px;font-weight:800;color:#ffd700;letter-spacing:0.5px;min-height:16px;text-align:center}
.pwa-slot-spin-btn{width:100%;padding:16px 24px;border:none;border-radius:30px;background:linear-gradient(135deg, #ff007f 0%, #ff7700 100%);color:#fff;font-size:18px;font-weight:900;letter-spacing:1px;cursor:pointer;box-shadow:0 8px 25px rgba(255,0,127,0.5), inset 0 2px 0 rgba(255,255,255,0.4);position:relative;overflow:hidden;transition:transform 0.1s}
.pwa-slot-spin-btn:active{transform:scale(0.97)}
.pwa-slot-spins-left{font-size:10px;color:rgba(255,255,255,0.6);font-weight:700}

/* Wheel Mode */
.appscr-wheel-mode{background:radial-gradient(circle at 50% 30%, #152238 0%, #070c14 100%)}
.wheel-shell{display:flex;flex-direction:column;align-items:center;min-height:100vh;justify-content:space-between;padding-bottom:30px}
.pwa-wheel-stage{display:flex;flex-direction:column;align-items:center;margin:auto 0;width:100%}
.pwa-wheel-headline{font-size:22px;font-weight:900;color:#ffd700;letter-spacing:0.5px;text-shadow:0 2px 10px rgba(255,215,0,0.4);text-align:center}
.pwa-wheel-subhead{font-size:12px;color:rgba(255,255,255,0.7);margin-top:4px;margin-bottom:20px;text-align:center;max-width:320px}
.pwa-wheel-container{position:relative;width:290px;height:290px;display:flex;align-items:center;justify-content:center}
.pwa-wheel-pointer{position:absolute;top:-10px;left:50%;transform:translateX(-50%);font-size:26px;color:#ffd700;z-index:20;filter:drop-shadow(0 4px 8px rgba(0,0,0,0.8))}
.pwa-wheel-disc{width:100%;height:100%;filter:drop-shadow(0 10px 30px rgba(0,0,0,0.7));will-change:transform}
.pwa-wheel-spin-btn{position:absolute;width:68px;height:68px;border-radius:50%;background:radial-gradient(circle, #ffd700 0%, #e67e22 100%);color:#1e272e;font-weight:900;font-size:14px;border:3px solid #fff;cursor:pointer;box-shadow:0 4px 15px rgba(0,0,0,0.5);z-index:15;display:flex;align-items:center;justify-content:center}
.pwa-wheel-spins-hint{font-size:11px;color:#ffd700;font-weight:800;margin-top:20px;letter-spacing:0.5px}

/* Win Modals */
.pwa-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:center;z-index:100;padding:20px}
.pwa-modal-card{background:linear-gradient(180deg, #1f2a44 0%, #0d1424 100%);border:2px solid #ffd700;border-radius:24px;padding:28px 20px 22px;text-align:center;max-width:360px;width:100%;box-shadow:0 0 50px rgba(255,215,0,0.35);animation:pwaPop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)}
.pwa-modal-confetti{font-size:48px;margin-bottom:6px;animation:pwaPulse 1.2s infinite}
.pwa-modal-timer{display:inline-flex;align-items:center;gap:6px;background:rgba(231,76,60,0.18);border:1px solid rgba(231,76,60,0.45);color:#ff6b6b;font-size:11px;font-weight:800;padding:5px 12px;border-radius:14px;margin-bottom:16px;letter-spacing:0.5px}
.pwa-countdown{font-family:monospace;font-size:12px;color:#fff}
.appscr-ticker-wrap{background:rgba(0,0,0,0.45);border-bottom:1px solid rgba(255,255,255,0.08);padding:6px 0;overflow:hidden;white-space:nowrap;font-size:10.5px;color:#ffd700;font-weight:600}
.appscr-ticker-content{display:inline-block;padding-left:100%;animation:tickerScroll 24s linear infinite}
.appscr-ticker-content span{color:rgba(255,255,255,0.85);margin:0 8px}
.gp-live-counter, .ios-live-counter{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#00e676;font-weight:700;margin-left:6px;animation:pwaPulse 2s infinite}
.btn-glow-active{position:relative;overflow:hidden;box-shadow:0 0 22px color-mix(in srgb, var(--pwa-primary) 50%, transparent)!important}
.btn-glow-active::after{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:linear-gradient(60deg, transparent, rgba(255,255,255,0.3), transparent);transform:rotate(25deg);animation:shimmerGlow 3s infinite}
@keyframes shimmerGlow{0%{transform:translateX(-100%) rotate(25deg)}100%{transform:translateX(100%) rotate(25deg)}}
@keyframes tickerScroll{0%{transform:translateX(0)}100%{transform:translateX(-100%)}}
@keyframes pwaPop{0%{transform:scale(0.8);opacity:0}100%{transform:scale(1);opacity:1}}
CSS;

        $customCssBlock = $c['custom_css'] !== '' ? "\n/* Custom Styles */\n" . $c['custom_css'] . "\n" : '';
        $customHead = $c['custom_head_code'] !== '' ? "\n" . $c['custom_head_code'] . "\n" : '';
        $customBody = $c['custom_body_code'] !== '' ? "\n" . $c['custom_body_code'] . "\n" : '';
        $customJsBlock = $c['custom_js'] !== '' ? "\n<script>\n" . $c['custom_js'] . "\n</script>\n" : '';

        return '<!DOCTYPE html>
<html lang="' . self::esc($lang) . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="orbitra-renderer" content="' . self::RENDERER_VERSION . '">
<title>' . self::esc($appName) . '</title>
<meta name="theme-color" content="' . self::esc($scheme) . '">
<link rel="manifest" href="manifest.webmanifest">
' . ($iconSrc !== '' ? '<link rel="apple-touch-icon" href="' . self::esc($iconSrc) . '">' : '') . '
<script>
(function(){
  var isIOS = window.__PWA_FORCE_IOS === true
    || /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  var storeStyle = ' . json_encode($storeStyle) . ';
  var effective = (storeStyle === "app_store" || (storeStyle === "auto" && isIOS)) ? "app_store" : "google_play";
  document.documentElement.setAttribute("data-store", effective);
})();
</script>
<style>' . $css . $customCssBlock . '</style>
' . $customHead . '
</head>
<body>
' . $customBody . '
' . $preloader . '
<div class="store-layout store-gp">' . $gpPage . '</div>
<div class="store-layout store-ios">' . $iosPage . '</div>
' . $share . '
' . $iosOverlay . '
' . $pushScreen . '
' . $appScreen . '
<script>
' . $js . '
</script>
' . $customJsBlock . '
</body>
</html>
';
    }
}
