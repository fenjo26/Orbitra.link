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

    private static $cacheV4 = null; // ['ranges' => [[start,end],...], 'loaded' => bool]
    private static $cacheV6 = null;

    public static function dir(): string
    {
        return __DIR__ . '/../var/ipranges';
    }

    public static function fileV4(): string
    {
        return self::dir() . '/ipv4_merged.txt';
    }

    public static function fileV6(): string
    {
        return self::dir() . '/ipv6_merged.txt';
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

    /**
     * Скачать оба списка (атомарно: во временный файл, потом rename).
     * @return array{ok:bool,ipv4:int,ipv6:int,errors:string[]}
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

        return $out;
    }

    /** Есть ли IP в каком-либо диапазоне из списков. */
    public static function match(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }
        if (strpos($ip, ':') === false) {
            return self::matchV4($ip);
        }
        return self::matchV6($ip);
    }

    // ————————————————————————————————————————————————————————————

    private static function matchV4(string $ip): bool
    {
        if (self::$cacheV4 === null) {
            self::$cacheV4 = self::loadV4();
        }
        if (!self::$cacheV4['loaded']) {
            return false;
        }
        $needle = ip2long($ip);
        if ($needle === false) {
            return false;
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
                return true;
            }
        }
        return false;
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
                $ranges[] = $cidr;
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

    private static function matchV6(string $ip): bool
    {
        if (self::$cacheV6 === null) {
            self::$cacheV6 = self::loadV6();
        }
        if (!self::$cacheV6['loaded']) {
            return false;
        }
        $needle = @inet_pton($ip);
        if ($needle === false) {
            return false;
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
                return true;
            }
        }
        return false;
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
            $ranges[] = [$start, $end];
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
