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
    public const RENDERER_VERSION = 7;

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
            'app_action', 'app_screen_title', 'app_screen_text', 'app_screen_button', 'app_screen_image',
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
            'rating_counts'     => [4200, 480, 120, 30, 15],
            'comments'          => [],
            'whats_new_enabled' => false,
            'whats_new_text'    => '',
            'support_enabled'   => false,
            'support_email'     => '',
            'support_address'   => '',
            'verified_badge'    => true,
            'theme_mode'        => 'light',
            'color_scheme'      => 'green',
            'store_style'       => 'auto',
            'push_enabled'      => false,
            'ios_flow'          => 'default',
            'preloader'         => true,
            'bottom_menu'       => false,
            'show_header'       => true,
            'show_share'        => false,
            'auto_redirect'     => 0,
            'decline_redirect'  => 0,
            'install_redirect'  => 0,
            'app_action'        => 'store',
            'app_screen_title'  => '',
            'app_screen_text'   => '',
            'app_screen_button' => 'Play now',
            'app_screen_image'  => '',
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
        // straight redirect into the funnel, screen = a custom in-app screen
        // with a CTA into the funnel.
        if (!in_array($c['app_action'], ['store', 'offer', 'screen'], true)) {
            $c['app_action'] = 'store';
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
            'auto'    => (int) $c['auto_redirect'],
            'decline' => (int) $c['decline_redirect'],
            'install' => (int) $c['install_redirect'],
            'push'    => !empty($c['push_enabled']),
            'appAction' => $c['app_action'],
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
            . '<div class="sub rating-line">' . number_format($avg, 1, '.', '') . ' <span class="mini-stars">' . self::starsSvg($avg, 'var(--pwa-star)') . '</span> · ' . $totalFormatted . ' reviews · ' . self::esc($c['downloads']) . ' Downloads</div>'
            . '</div></div>'
            . '<button type="button" id="pwa-install-btn" class="install-btn install-trigger">' . self::esc($c['button_text']) . '</button>'
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
            . '<div class="ios-app-subtitle">' . self::esc($c['category']) . ' · ' . self::esc($c['developer']) . '</div>'
            . '<div class="ios-action-row">'
            . '<button type="button" id="pwa-install-btn-ios" class="ios-get-btn install-trigger">' . self::esc($t['get'] ?? 'GET') . '</button>'
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
            $iconInner = $iconSrc !== ''
                ? '<img src="' . self::esc($iconSrc) . '" alt="">'
                : '<span class="appscr-letter">' . self::esc(mb_substr($appName, 0, 1)) . '</span>';
            $hero = $c['app_screen_image'] !== ''
                ? '<div class="appscr-hero"><img src="' . self::esc($c['app_screen_image']) . '" alt="" onerror="this.parentNode.style.display=\'none\'"></div>'
                : '';
            $appScreen = '<div id="pwa-app-screen" hidden' . ($c['app_screen_image'] !== '' ? ' class="has-hero"' : '') . '>'
                . $hero
                . '<div class="appscr-icon">' . $iconInner . '</div>'
                . '<h2 class="appscr-title">' . self::esc($c['app_screen_title'] !== '' ? $c['app_screen_title'] : $appName) . '</h2>'
                . '<p class="appscr-text">' . self::esc($c['app_screen_text']) . '</p>'
                . '<button type="button" id="pwa-app-cta" class="install-btn">' . self::esc($c['app_screen_button']) . '</button>'
                . '</div>';
        }

        // The two serve-time placeholders stay literal in the source: the
        // lander route replaces them per request, so the SW's network-first
        // navigation rule always pairs a fresh page with a fresh click id.
        $js = <<<'JS'
(function () {
  var cfg = __CFG_JSON__;
  var subid = '__SUBID__';
  var lpUrl = '__LP_URL__';
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
  function afterPush() {
    // The visitor answered the prompt INSIDE the installed app — mark the
    // offer as made and hand control to the configured app action.
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
    beacon('prompt'); // the permission dialog is about to be shown
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { beacon('decline'); afterPush(); return; }
      navigator.serviceWorker.ready.then(function (reg) {
        reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToU8(VAPID) })
          .then(function (sub) {
            var s = sub.toJSON();
            fetch('/push_subscribe?lang=' + encodeURIComponent(navigator.language || ''), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ subid: subid, endpoint: s.endpoint, keys: s.keys, expirationTime: s.expirationTime || null })
            }).then(afterPush).catch(afterPush);
          }).catch(afterPush);
      }).catch(afterPush);
    });
  }
  function showPush() {
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
    if (cfg.appAction === 'offer') { redirect(); return; }
    if (cfg.appAction === 'screen') { showAppScreen(); return; }
  }
  var appCta = document.getElementById('pwa-app-cta');
  if (appCta) appCta.addEventListener('click', function () { beacon('intent'); redirect(); });
  var pushAllow = document.getElementById('pwa-push-allow');
  var pushLater = document.getElementById('pwa-push-later');
  if (pushAllow) pushAllow.addEventListener('click', enablePush);
  if (pushLater) pushLater.addEventListener('click', afterPush);

  function handleInstallClick() {
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
        $js = str_replace(
            ['__CFG_JSON__', '__SUBID__', '__LP_URL__', '__VAPID_PUBLIC__'],
            [json_encode($cfgForJs, JSON_UNESCAPED_UNICODE), '{subid}', '{lp_url}', '{vapid_public}'],
            $js
        );

        $css = <<<CSS
:root{--pwa-primary:$scheme;--pwa-bg:$bg;--pwa-surface:$surface;--pwa-text:$text;--pwa-muted:$muted;--pwa-border:$border;--pwa-star:#fbbc04}
*{box-sizing:border-box;margin:0;padding:0}
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
#pwa-app-screen{position:fixed;inset:0;background:var(--pwa-bg);z-index:40;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;padding:24px}
.appscr-icon{width:96px;height:96px;border-radius:22px;overflow:hidden;box-shadow:0 10px 24px rgba(0,0,0,.18)}
.appscr-icon img{width:100%;height:100%;object-fit:cover}
.appscr-letter{display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:40px;font-weight:700;color:#fff;background:var(--pwa-primary)}
.appscr-title{font-size:22px;font-weight:600;color:var(--pwa-text)}
.appscr-text{font-size:14px;color:var(--pwa-muted);max-width:320px;line-height:1.45}
#pwa-app-screen .install-btn{width:auto;padding:12px 44px}
.appscr-hero{position:absolute;top:0;left:0;right:0;height:46%;overflow:hidden}
.appscr-hero img{width:100%;height:100%;object-fit:cover}
#pwa-app-screen.has-hero{justify-content:flex-end;padding-bottom:40px}
#pwa-app-screen>*:not(.appscr-hero){position:relative;z-index:1}
.appscr-icon{width:96px;height:96px;border-radius:22px;overflow:hidden;box-shadow:0 10px 24px rgba(0,0,0,.18);border:2px solid var(--pwa-bg)}
CSS;

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
<style>' . $css . '</style>
</head>
<body>
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
</body>
</html>
';
    }
}
