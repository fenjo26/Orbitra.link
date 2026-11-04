<?php
/**
 * IpRanges — диапазоны датацентров/краулеров для клокера.
 *
 * Источник: github.com/lord-alfred/ipranges — публичные списки Google (Cloud и
 * GoogleBot), Bing, Amazon AWS, Microsoft, Oracle, DigitalOcean, GitHub,
 * Facebook, Twitter, Linode, Telegram, OpenAI, CloudFlare, Vultr, Apple Private
 * Relay, ProtonVPN и др., склеенные в один файл и обновляемые там ежедневно.
 * Берём готовые «all-in-one merged» списки — минимальное число CIDR, один файл
 * на семейство:
 *   https://raw.githubusercontent.com/lord-alfred/ipranges/main/all/ipv4_merged.txt
 *   https://raw.githubusercontent.com/lord-alfred/ipranges/main/all/ipv6_merged.txt
 *
 * Обновление: `php ipranges_cron.php` (раз в сутки, стоит в кроне инсталлятора)
 * или api.php?action=ipranges_update из панели. Клокер читает списки лениво:
 * пока файлов нет, слой iprange просто неактивен и ничего не ломает.
 *
 * Матчинг: файл парсится в отсортированные [start, end] и ищется бинарно —
 * ipv4 как int, ipv6 как 16 байт big-endian (strcmp на packed-строках даёт
 * корректный порядок). Парсинг кешируется на запрос.
 */

class IpRanges
{
    private const SRC_V4 = 'https://raw.githubusercontent.com/lord-alfred/ipranges/main/all/ipv4_merged.txt';
    private const SRC_V6 = 'https://raw.githubusercontent.com/lord-alfred/ipranges/main/all/ipv6_merged.txt';

    /**
     * Chrome Prefetch Proxy's own geofeed (RFC 8805): "prefix,cc,,,", one
     * line per egress. This is the ONLY correct source for the prefetch
     * exemption — Google's broader "user-triggered fetchers" feeds also cover
     * e.g. the Translate proxy, which would hand moderators a pass. The
     * prefetched request announces itself with Sec-Purpose: Prefetch;
     * anonymous-client-ip, and the geofeed row carries the visitor's real
     * country (the egress is geo-located), so geo targeting keeps working.
     */
    private const SRC_PREFETCH_GEOFEED = 'https://www.gstatic.com/chrome/prefetchproxy/prefetch_proxy_geofeed';

    /**
     * The engines' own machine-readable crawler IP lists — Google's
     * recommended method for verifying a claimed crawler at scale, no DNS on
     * the click path. Bing publishes the same JSON shape.
     */
    private const SRC_GOOGLE_CRAWLERS = 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json';
    private const SRC_BING_CRAWLERS = 'https://www.bing.com/toolbox/bingbot.json';

    private static $cacheV4 = null; // ['ranges' => [[start,end],...], 'loaded' => bool]
    private static $cacheV6 = null;
    /** @var array|null null = not loaded; [netBin, bits, cidr, cc] per row */
    private static $cachePrefetch = null;
    /** @var array|null null = not loaded; [netBin, bits, cidr] per row */
    private static $cacheGoogleBots = null;
    /** @var array|null null = not loaded; [netBin, bits, cidr] per row */
    private static $cacheBingBots = null;

    /** Test hook: override the storage directory (null = real var/ipranges). */
    private static $dirOverride = null;

    /**
     * Point IpRanges at another directory (tests write fixtures there) or
     * back to the real one (null). Resets every per-process cache.
     */
    public static function useDir(?string $dir): void
    {
        self::$dirOverride = $dir;
        self::$cacheV4 = null;
        self::$cacheV6 = null;
        self::$cachePrefetch = null;
        self::$cacheGoogleBots = null;
        self::$cacheBingBots = null;
    }

    public static function dir(): string
    {
        return self::$dirOverride ?? (__DIR__ . '/../var/ipranges');
    }

    public static function fileV4(): string
    {
        return self::dir() . '/ipv4_merged.txt';
    }

    public static function fileV6(): string
    {
        return self::dir() . '/ipv6_merged.txt';
    }

    /**
     * Geofeed прокси предзагрузки Chrome: строки "prefix,cc" (RFC 8805,
     * лишние колонки отброшены при обновлении).
     */
    public static function filePrefetchGeofeed(): string
    {
        return self::dir() . '/prefetch_geofeed.txt';
    }

    /** Список IP краулеров Google (common-crawlers.json → CIDR-строки). */
    public static function fileGoogleCrawlers(): string
    {
        return self::dir() . '/google_crawlers.txt';
    }

    /** Список IP бинга (bingbot.json → CIDR-строки). */
    public static function fileBingCrawlers(): string
    {
        return self::dir() . '/bing_crawlers.txt';
    }

    /** Скачаны ли списки (хотя бы один файл). */
    public static function available(): bool
    {
        return file_exists(self::fileV4()) || file_exists(self::fileV6());
    }

    /** Спискам меньше суток? false = пора обновить (или их ещё нет). */
    public static function isFresh(): bool
    {
        foreach ([self::fileV4(), self::fileV6()] as $f) {
            if (!file_exists($f) || time() - (int) filemtime($f) > 86400) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ленивое авто-обновление для существующих установок, где cron ещё не
     * прописан: первый клок-клик по устаревшим/отсутствующим спискам планирует
     * докачку В ПОСЛЕДНИЙ момент запроса — ответ посетителю уже отправлен,
     * задержки клику нет. Замок в var/locks защищает от параллельной стаи
     * скачиваний; в CLI метод нейтрален (кроны обновляются сами).
     */
    public static function ensureFreshBackground(): void
    {
        if (self::isFresh()) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            return;
        }
        $lock = __DIR__ . '/../var/locks/ipranges.lock';
        $lockDir = dirname($lock);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        if (file_exists($lock) && time() - (int) filemtime($lock) < 600) {
            return;
        }
        @touch($lock);
        register_shutdown_function(function () use ($lock) {
            try {
                self::update();
            } finally {
                @unlink($lock);
            }
        });
    }

    /** Число диапазонов в локальном файле (для статуса в панели). */
    public static function countV4(): int
    {
        return file_exists(self::fileV4()) ? max(0, substr_count((string) @file_get_contents(self::fileV4()), '/')) : 0;
    }

    public static function countV6(): int
    {
        return file_exists(self::fileV6()) ? max(0, substr_count((string) @file_get_contents(self::fileV6()), '/')) : 0;
    }

    /** Число префиксов в geofeed прокси предзагрузки (для статуса в панели). */
    public static function countPrefetch(): int
    {
        return is_readable(self::filePrefetchGeofeed())
            ? max(0, substr_count((string) @file_get_contents(self::filePrefetchGeofeed()), '/'))
            : 0;
    }

    /** Число префиксов Google-краулеров. */
    public static function countGoogleCrawlers(): int
    {
        return is_readable(self::fileGoogleCrawlers())
            ? max(0, substr_count((string) @file_get_contents(self::fileGoogleCrawlers()), '/'))
            : 0;
    }

    /** Число префиксов Bing-краулеров. */
    public static function countBingCrawlers(): int
    {
        return is_readable(self::fileBingCrawlers())
            ? max(0, substr_count((string) @file_get_contents(self::fileBingCrawlers()), '/'))
            : 0;
    }

    /**
     * Скачать оба списка (атомарно: во временный файл, потом rename).
     * @return array{ok:bool,ipv4:int,ipv6:int,prefetch:int,google_crawlers:int,bing_crawlers:int,errors:string[]}
     *         (+ "<feed>_errors" для каждого точечного фида, который не скачался)
     */
    public static function update(): array
    {
        $out = ['ok' => true, 'ipv4' => 0, 'ipv6' => 0, 'errors' => []];
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'ipv4' => 0, 'ipv6' => 0, 'errors' => ['mkdir failed: ' . $dir]];
        }

        foreach ([[self::SRC_V4, self::fileV4(), 'ipv4'], [self::SRC_V6, self::fileV6(), 'ipv6']] as [$src, $dst, $key]) {
            $body = self::httpGet($src);
            if ($body === null) {
                $out['ok'] = false;
                $out['errors'][] = "download failed: {$src}";
                continue;
            }
            // Разумная отсечка: all-in-one ipv4 — тысячи строк, но не мегабайты.
            if (strlen($body) < 16 || substr_count($body, '/') < 1) {
                $out['ok'] = false;
                $out['errors'][] = "suspicious content from {$src}";
                continue;
            }
            $tmp = $dst . '.tmp';
            if (@file_put_contents($tmp, $body) === false) {
                $out['ok'] = false;
                $out['errors'][] = "write failed: {$tmp}";
                continue;
            }
            if (!@rename($tmp, $dst)) {
                $out['ok'] = false;
                $out['errors'][] = "rename failed: {$dst}";
                continue;
            }
            $out[$key] = substr_count(trim($body), "\n") + 1;
        }

        // Кеши прошлого запроса больше не соответствуют файлам.
        self::$cacheV4 = null;
        self::$cacheV6 = null;

        // Точечные фиды клоакера: geofeed прокси предзагрузки Chrome и списки
        // IP краулеров Google/Bing. Отказ любого из них НЕ проваливает
        // обновление (ok не трогаем): старый файл остаётся, при полном
        // отсутствии файла фича просто выключена — поведение как до неё.
        foreach ([
            ['prefetch', 'updatePrefetchGeofeed'],
            ['google_crawlers', 'updateGoogleCrawlers'],
            ['bing_crawlers', 'updateBingCrawlers'],
        ] as [$key, $method]) {
            $result = self::$method();
            $out[$key] = $result['count'];
            if ($result['count'] < 0) {
                $out[$key . '_errors'] = $result['errors'];
            }
        }
        self::$cachePrefetch = null;
        self::$cacheGoogleBots = null;
        self::$cacheBingBots = null;

        return $out;
    }

    /**
     * Скачать geofeed прокси предзагрузки (RFC 8805) и записать его как
     * "prefix,cc" строки, отбросив комментарии и пустые колонки.
     *
     * @return array{count:int,errors:string[]}
     */
    public static function updatePrefetchGeofeed(): array
    {
        $body = self::httpGet(self::SRC_PREFETCH_GEOFEED);
        if ($body === null) {
            return ['count' => -1, 'errors' => ['download failed: ' . self::SRC_PREFETCH_GEOFEED]];
        }
        $rows = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $cols = array_map('trim', explode(',', $line));
            if (count($cols) < 2 || strpos($cols[0], '/') === false || $cols[1] === '') {
                continue;
            }
            if (@inet_pton((string) preg_replace('/\/.*$/', '', $cols[0])) === false) {
                continue; // мусорная строка, не префикс
            }
            $rows[] = $cols[0] . ',' . strtoupper(substr($cols[1], 0, 2));
        }
        $rows = array_values(array_unique($rows));
        if (empty($rows)) {
            return ['count' => -1, 'errors' => ['no usable prefixes in geofeed']];
        }
        return self::writeFeedFile(self::filePrefetchGeofeed(), implode("\n", $rows) . "\n", count($rows));
    }

    /**
     * Скачать список IP Google-краулеров (common-crawlers.json) в CIDR-строки.
     *
     * @return array{count:int,errors:string[]}
     */
    public static function updateGoogleCrawlers(): array
    {
        return self::updateJsonPrefixFeed(self::SRC_GOOGLE_CRAWLERS, self::fileGoogleCrawlers());
    }

    /**
     * Скачать список IP бинга (bingbot.json) в CIDR-строки.
     *
     * @return array{count:int,errors:string[]}
     */
    public static function updateBingCrawlers(): array
    {
        return self::updateJsonPrefixFeed(self::SRC_BING_CRAWLERS, self::fileBingCrawlers());
    }

    /**
     * Общий загрузчик JSON-фидов вида {"prefixes":[{"ipv4Prefix"|…}]}.
     *
     * @return array{count:int,errors:string[]}
     */
    private static function updateJsonPrefixFeed(string $src, string $dst): array
    {
        $body = self::httpGet($src);
        if ($body === null) {
            return ['count' => -1, 'errors' => ["download failed: {$src}"]];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['prefixes']) || !is_array($decoded['prefixes'])) {
            return ['count' => -1, 'errors' => ["bad JSON from {$src}"]];
        }
        $cidrs = [];
        foreach ($decoded['prefixes'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $cidr = trim((string) ($entry['ipv4Prefix'] ?? $entry['ipv6Prefix'] ?? ''));
            if ($cidr !== '' && strpos($cidr, '/') !== false) {
                $cidrs[$cidr] = true;
            }
        }
        if (empty($cidrs)) {
            return ['count' => -1, 'errors' => ["no prefixes in {$src}"]];
        }
        return self::writeFeedFile($dst, implode("\n", array_keys($cidrs)) . "\n", count($cidrs));
    }

    /**
     * Атомарная запись файла фида (tmp + rename).
     *
     * @return array{count:int,errors:string[]}
     */
    private static function writeFeedFile(string $dst, string $content, int $count): array
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['count' => -1, 'errors' => ['mkdir failed: ' . $dir]];
        }
        $tmp = $dst . '.tmp';
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $dst)) {
            return ['count' => -1, 'errors' => ['write failed: ' . $dst]];
        }
        return ['count' => $count, 'errors' => []];
    }

    /**
     * Есть ли IP в каком-либо диапазоне из списков.
     *
     * @return string|null matched CIDR (the list's own line, e.g. '52.0.0.0/8')
     *                     or null when the address is outside every range.
     *                     Truthy/falsy like the former bool for `if (match())`.
     */
    public static function match(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        if (strpos($ip, ':') === false) {
            return self::matchV4($ip);
        }
        return self::matchV6($ip);
    }

    /**
     * Страна посещения для IP из geofeed прокси предзагрузки Chrome, или null.
     * Список маленький (полторы тысячи префиксов против десятков тысяч в
     * merged), поэтому линейный проход по packed-префиксам. Отсутствующий
     * файл = пустой список = никогда не совпадает: фича может только «не
     * сработать», но не сломать клик.
     *
     * @return string|null двухбуквенный код страны ('IN', 'DE', …)
     */
    public static function prefetchProxyCountry(string $ip): ?string
    {
        $ipBin = @inet_pton(trim($ip));
        if ($ipBin === false) {
            return null;
        }
        foreach (self::prefetchPrefixes() as [$netBin, $bits, , $cc]) {
            if (strlen($netBin) !== strlen($ipBin)) {
                continue; // v4 против v6 — не совпадают по определению
            }
            $wholeBytes = intdiv($bits, 8);
            if ($wholeBytes > 0 && strncmp($ipBin, $netBin, $wholeBytes) !== 0) {
                continue;
            }
            $remainder = $bits % 8;
            $matched = $remainder === 0;
            if (!$matched) {
                $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
                $matched = ($ipBin[$wholeBytes] & $mask) === ($netBin[$wholeBytes] & $mask);
            }
            if ($matched) {
                return $cc;
            }
        }
        return null;
    }

    /**
     * Есть ли IP в опубликованном пространстве Google-краулеров.
     *
     * @return string|null matched CIDR или null
     */
    public static function matchGoogleCrawler(string $ip): ?string
    {
        return self::matchSimpleFeed($ip, self::googleCrawlerPrefixes());
    }

    /** Есть ли IP в опубликованном пространстве Bing-краулеров. */
    public static function matchBingCrawler(string $ip): ?string
    {
        return self::matchSimpleFeed($ip, self::bingCrawlerPrefixes());
    }

    /**
     * Линейный матчинг IP по списку [netBin, bits, cidr] без страны.
     *
     * @param array[] $prefixes
     */
    private static function matchSimpleFeed(string $ip, array $prefixes): ?string
    {
        $ipBin = @inet_pton(trim($ip));
        if ($ipBin === false) {
            return null;
        }
        foreach ($prefixes as [$netBin, $bits, $cidr]) {
            if (strlen($netBin) !== strlen($ipBin)) {
                continue;
            }
            $wholeBytes = intdiv($bits, 8);
            if ($wholeBytes > 0 && strncmp($ipBin, $netBin, $wholeBytes) !== 0) {
                continue;
            }
            $remainder = $bits % 8;
            $matched = $remainder === 0;
            if (!$matched) {
                $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
                $matched = ($ipBin[$wholeBytes] & $mask) === ($netBin[$wholeBytes] & $mask);
            }
            if ($matched) {
                return $cidr;
            }
        }
        return null;
    }

    /**
     * Geofeed как [[netBin, bits, cidr, cc], ...], один раз за процесс.
     *
     * @return array[]
     */
    private static function prefetchPrefixes(): array
    {
        if (self::$cachePrefetch !== null) {
            return self::$cachePrefetch;
        }
        self::$cachePrefetch = [];
        $file = self::filePrefetchGeofeed();
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                // "prefix,cc" — вторая колонка обязательна
                [$prefix, $cc] = array_pad(explode(',', $line, 2), 2, '');
                $cc = strtoupper(trim($cc));
                $parsed = self::parsePackedPrefix($prefix);
                if ($parsed === null || $cc === '' || strlen($cc) !== 2) {
                    continue;
                }
                self::$cachePrefetch[] = [$parsed[0], $parsed[1], $prefix, $cc];
            }
        }
        return self::$cachePrefetch;
    }

    /**
     * Google-краулеры как [[netBin, bits, cidr], ...], один раз за процесс.
     *
     * @return array[]
     */
    private static function googleCrawlerPrefixes(): array
    {
        if (self::$cacheGoogleBots !== null) {
            return self::$cacheGoogleBots;
        }
        self::$cacheGoogleBots = self::loadSimpleFeed(self::fileGoogleCrawlers());
        return self::$cacheGoogleBots;
    }

    /**
     * Bing-краулеры как [[netBin, bits, cidr], ...], один раз за процесс.
     *
     * @return array[]
     */
    private static function bingCrawlerPrefixes(): array
    {
        if (self::$cacheBingBots !== null) {
            return self::$cacheBingBots;
        }
        self::$cacheBingBots = self::loadSimpleFeed(self::fileBingCrawlers());
        return self::$cacheBingBots;
    }

    /**
     * CIDR-файл → [[netBin, bits, cidr], ...]; мусорные строки пропускаются.
     *
     * @return array[]
     */
    private static function loadSimpleFeed(string $file): array
    {
        $prefixes = [];
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $parsed = self::parsePackedPrefix($line);
                if ($parsed !== null) {
                    $prefixes[] = [$parsed[0], $parsed[1], $line];
                }
            }
        }
        return $prefixes;
    }

    /**
     * "net/bits" → [netBin, bits] или null (кривой префикс).
     *
     * @return array{0:string,1:int}|null
     */
    private static function parsePackedPrefix(string $cidr): ?array
    {
        if (strpos($cidr, '/') === false) {
            return null;
        }
        [$network, $bitsRaw] = explode('/', $cidr, 2);
        if (!preg_match('/^\d+$/', trim($bitsRaw))) {
            return null;
        }
        $netBin = @inet_pton(trim($network));
        if ($netBin === false) {
            return null;
        }
        $bits = (int) trim($bitsRaw);
        if ($bits < 0 || $bits > strlen($netBin) * 8) {
            return null;
        }
        return [$netBin, $bits];
    }

    // ————————————————————————————————————————————————————————————

    /** @return string|null matched CIDR or null */
    private static function matchV4(string $ip): ?string
    {
        if (self::$cacheV4 === null) {
            self::$cacheV4 = self::loadV4();
        }
        if (!self::$cacheV4['loaded']) {
            return null;
        }
        $needle = ip2long($ip);
        if ($needle === false) {
            return null;
        }
        $ranges = self::$cacheV4['ranges'];
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($ranges[$mid][1] < $needle) {
                $lo = $mid + 1;
            } elseif ($ranges[$mid][0] > $needle) {
                $hi = $mid - 1;
            } else {
                return $ranges[$mid][2];
            }
        }
        return null;
    }

    private static function loadV4(): array
    {
        $file = self::fileV4();
        if (!file_exists($file) || !is_readable($file)) {
            return ['loaded' => false, 'ranges' => []];
        }
        $ranges = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $cidr = self::parseCidrV4($line);
            if ($cidr) {
                // [start, end, source line] — the line is the evidence the
                // click log shows for iprange_datacenter matches.
                $ranges[] = [$cidr[0], $cidr[1], $line];
            }
        }
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        return ['loaded' => !empty($ranges), 'ranges' => $ranges];
    }

    private static function parseCidrV4(string $cidr): ?array
    {
        $pos = strpos($cidr, '/');
        $ip = $pos === false ? $cidr : substr($cidr, 0, $pos);
        $prefix = $pos === false ? 32 : (int) substr($cidr, $pos + 1);
        $start = ip2long($ip);
        if ($start === false || $prefix < 0 || $prefix > 32) {
            return null;
        }
        $end = $start | ((1 << (32 - $prefix)) - 1);
        return [$start, $end];
    }

    /** @return string|null matched CIDR or null */
    private static function matchV6(string $ip): ?string
    {
        if (self::$cacheV6 === null) {
            self::$cacheV6 = self::loadV6();
        }
        if (!self::$cacheV6['loaded']) {
            return null;
        }
        $needle = @inet_pton($ip);
        if ($needle === false) {
            return null;
        }
        $ranges = self::$cacheV6['ranges'];
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($ranges[$mid][1], $needle) < 0) {
                $lo = $mid + 1;
            } elseif (strcmp($ranges[$mid][0], $needle) > 0) {
                $hi = $mid - 1;
            } else {
                return $ranges[$mid][2];
            }
        }
        return null;
    }

    private static function loadV6(): array
    {
        $file = self::fileV6();
        if (!file_exists($file) || !is_readable($file)) {
            return ['loaded' => false, 'ranges' => []];
        }
        $ranges = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '/');
            $ip = $pos === false ? $line : substr($line, 0, $pos);
            $prefix = $pos === false ? 128 : (int) substr($line, $pos + 1);
            $start = @inet_pton($ip);
            if ($start === false || $prefix < 0 || $prefix > 128) {
                continue;
            }
            // Маска по байтам: старшие prefix бит остаются, младшие зануляются.
            $mask = str_repeat("\xff", intdiv($prefix, 8));
            if ($prefix % 8) {
                $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
            }
            $mask = str_pad($mask, 16, "\0");
            $start = $start & $mask;
            // Конец диапазона: старт | инверсия маски.
            $end = $start | (~$mask & str_repeat("\xff", 16));
            $ranges[] = [$start, $end, $line];
        }
        usort($ranges, fn($a, $b) => strcmp($a[0], $b[0]));
        return ['loaded' => !empty($ranges), 'ranges' => $ranges];
    }

    /** @return string|null */
    private static function httpGet(string $url, int $timeout = 30)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            // curl_close() is a no-op since PHP 8 and deprecated since 8.5.
            if ($body === false || $code < 200 || $code >= 300) {
                return null;
            }
            return (string) $body;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout]]);
        $result = @file_get_contents($url, false, $ctx);
        return is_string($result) ? $result : null;
    }
}
