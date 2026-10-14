<?php
/**
 * User-Agent parsing — one place for every click entry point and the panel.
 *
 * Two layers, on purpose:
 *
 *   orbitraUaOsFamily() / orbitraUaBrowserFamily()
 *       Cheap strpos checks run on EVERY click. They return the coarse values
 *       stored in clicks.os / clicks.browser, which stream filters, reports and
 *       Keitaro imports already key on (Windows/Android/iOS/macOS/Linux and
 *       Chrome/Safari/Firefox/Edge/Opera/Samsung Browser/Internet Explorer).
 *       Do not introduce new family names here without updating the filter UI.
 *
 *   orbitraParseUserAgent()
 *       The full breakdown for humans — OS and browser versions, device brand
 *       and model, in-app browser, bot name. It runs on READ (click details,
 *       click log), never on the click path, so it costs nothing per click and
 *       works for every click already in the database.
 *
 * Why every UA starts with "Mozilla/5.0": historical compatibility, it carries
 * no information. The browser is the product token near the end (Chrome/,
 * CriOS/, Firefox/, Edg/ ...). Chrome freezes the macOS part to 10_15_7 and the
 * minor version to .0.0.0 (UA reduction), so those are shown as sent.
 */

if (!function_exists('orbitraUaOsFamily')) {
    /** Coarse OS family stored in clicks.os. */
    function orbitraUaOsFamily(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        if (strpos($ua, 'windows') !== false) {
            return 'Windows';
        }
        if (strpos($ua, 'android') !== false) {
            return 'Android';
        }
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false
            || strpos($ua, 'ipod') !== false || preg_match('/\bios\b/', $ua)) {
            return 'iOS';
        }
        if (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) {
            return 'macOS';
        }
        if (strpos($ua, 'linux') !== false || strpos($ua, 'cros') !== false) {
            return 'Linux';
        }
        return 'Unknown';
    }
}

if (!function_exists('orbitraUaBrowserFamily')) {
    /**
     * Coarse browser family stored in clicks.browser.
     *
     * iOS browsers do not send "Chrome/" or "Firefox/": Chrome on iPhone is
     * CriOS/, Firefox is FxiOS/, Edge is EdgiOS/, Opera is OPiOS/ or OPT/. They
     * used to fall through to Safari because they also carry "Safari/".
     */
    function orbitraUaBrowserFamily(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        if (strpos($ua, 'edg/') !== false || strpos($ua, 'edga/') !== false || strpos($ua, 'edgios/') !== false) {
            return 'Edge';
        }
        if (strpos($ua, 'opr/') !== false || strpos($ua, 'opios/') !== false
            || strpos($ua, 'opt/') !== false || strpos($ua, 'opera') !== false) {
            return 'Opera';
        }
        if (strpos($ua, 'samsungbrowser') !== false) {
            return 'Samsung Browser';
        }
        if (strpos($ua, 'crios/') !== false) {
            return 'Chrome';
        }
        if (strpos($ua, 'fxios/') !== false) {
            return 'Firefox';
        }
        if (strpos($ua, 'chrome/') !== false) {
            return 'Chrome';
        }
        if (strpos($ua, 'firefox/') !== false) {
            return 'Firefox';
        }
        if (strpos($ua, 'safari/') !== false) {
            return 'Safari';
        }
        if (strpos($ua, 'trident/') !== false || strpos($ua, 'msie') !== false) {
            return 'Internet Explorer';
        }
        return 'Unknown';
    }
}

if (!function_exists('orbitraUaBotName')) {
    /** Name of the crawler/tool a UA identifies as, or '' for a browser. */
    function orbitraUaBotName(string $userAgent): string
    {
        if (trim($userAgent) === '') {
            return '';
        }
        // Known names first: the generic rule below would also catch most of
        // them, but these get their canonical spelling.
        static $known = [
            'Googlebot' => 'googlebot', 'AdsBot-Google' => 'adsbot-google',
            'Mediapartners-Google' => 'mediapartners-google', 'Google-InspectionTool' => 'google-inspectiontool',
            'GoogleOther' => 'googleother', 'Google Read Aloud' => 'google-read-aloud',
            'bingbot' => 'bingbot', 'BingPreview' => 'bingpreview', 'YandexBot' => 'yandexbot',
            'Baiduspider' => 'baiduspider', 'DuckDuckBot' => 'duckduckbot', 'Applebot' => 'applebot',
            'Yahoo Slurp' => 'slurp', 'AhrefsBot' => 'ahrefsbot', 'SemrushBot' => 'semrushbot',
            'MJ12bot' => 'mj12bot', 'DotBot' => 'dotbot', 'PetalBot' => 'petalbot', 'Bytespider' => 'bytespider',
            'GPTBot' => 'gptbot', 'OAI-SearchBot' => 'oai-searchbot', 'ChatGPT-User' => 'chatgpt-user',
            'ClaudeBot' => 'claudebot', 'Claude-User' => 'claude-user', 'PerplexityBot' => 'perplexitybot',
            'CCBot' => 'ccbot', 'Amazonbot' => 'amazonbot', 'Barkrowler' => 'barkrowler',
            'Facebook crawler' => 'facebookexternalhit', 'Meta crawler' => 'meta-externalagent',
            'Facebot' => 'facebot', 'Twitterbot' => 'twitterbot', 'TelegramBot' => 'telegrambot',
            'WhatsApp' => 'whatsapp/', 'LinkedInBot' => 'linkedinbot', 'Slackbot' => 'slackbot',
            'Discordbot' => 'discordbot', 'Skype preview' => 'skypeuripreview',
            'Lighthouse' => 'lighthouse', 'HeadlessChrome' => 'headlesschrome',
            'PhantomJS' => 'phantomjs', 'Selenium' => 'selenium', 'Puppeteer' => 'puppeteer',
            'curl' => 'curl/', 'Wget' => 'wget/', 'python-requests' => 'python-requests',
            'Python' => 'python-urllib', 'aiohttp' => 'aiohttp', 'Scrapy' => 'scrapy',
            'Go HTTP client' => 'go-http-client', 'okhttp' => 'okhttp', 'axios' => 'axios/',
            'node-fetch' => 'node-fetch', 'Java' => 'java/', 'Apache HttpClient' => 'apache-httpclient',
            'libwww-perl' => 'libwww-perl', 'zgrab' => 'zgrab',
        ];
        $ua = strtolower($userAgent);
        foreach ($known as $name => $needle) {
            if (strpos($ua, $needle) !== false) {
                return $name;
            }
        }
        // Generic: a product token ending in bot/crawler/spider ("FooBot/1.2",
        // "compatible; barcrawler;"). Phone brand Cubot is not a bot.
        if (preg_match('/([a-z0-9][a-z0-9_.\-]*(?:bot|crawler|spider|scraper))(?=[\/;\s)]|$)/i', $userAgent, $m)
            && strcasecmp($m[1], 'cubot') !== 0) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('orbitraUaDeviceBrand')) {
    /** Best-effort brand for an Android model token. */
    function orbitraUaDeviceBrand(string $model): string
    {
        static $rules = [
            '/^(sm-|gt-|sgh-|sch-|samsung|galaxy)/i' => 'Samsung',
            '/^pixel/i' => 'Google',
            '/^(redmi|poco|mi[ \d]|xiaomi|m\d{4}[a-z]|2\d{3}[a-z0-9]{3,}|22\d{6}[a-z]{1,3})/i' => 'Xiaomi',
            '/^(huawei|honor|[a-z]{3}-(?:l|al|tl|lx|nx)\d)/i' => 'Huawei',
            '/^(oneplus|[a-z]{2}\d{4}$)/i' => 'OnePlus',
            '/^rmx/i' => 'realme',
            '/^(cph|oppo)/i' => 'OPPO',
            '/^(vivo|v\d{4}[a-z]?$|i\d{4}$)/i' => 'vivo',
            '/^(moto|motorola|xt\d)/i' => 'Motorola',
            '/^(lm-|lg)/i' => 'LG',
            '/^nokia/i' => 'Nokia',
            '/^(tecno)/i' => 'TECNO',
            '/^infinix/i' => 'Infinix',
            '/^itel/i' => 'itel',
            '/^(sony|so-|xq-|g\d{4}$)/i' => 'Sony',
            '/^(asus|zenfone|rog)/i' => 'ASUS',
            '/^zte/i' => 'ZTE',
            '/^lenovo/i' => 'Lenovo',
            '/^nothing/i' => 'Nothing',
        ];
        foreach ($rules as $re => $brand) {
            if (preg_match($re, $model)) {
                return $brand;
            }
        }
        return '';
    }
}

if (!function_exists('orbitraParseUserAgent')) {
    /**
     * Full, human-facing breakdown of a User-Agent. Read-side only.
     *
     * @return array{os:string,os_name:string,os_version:string,browser:string,
     *   browser_name:string,browser_version:string,device_type:string,
     *   device_brand:string,device_model:string,in_app:string,bot:string}
     */
    function orbitraParseUserAgent(string $userAgent): array
    {
        $ua = trim($userAgent);
        $r = [
            'os' => orbitraUaOsFamily($ua),
            'os_name' => '',
            'os_version' => '',
            'browser' => orbitraUaBrowserFamily($ua),
            'browser_name' => '',
            'browser_version' => '',
            'device_type' => function_exists('orbitraDetectDeviceType') ? orbitraDetectDeviceType($ua) : '',
            'device_brand' => '',
            'device_model' => '',
            'in_app' => '',
            'bot' => orbitraUaBotName($ua),
        ];
        if ($ua === '') {
            return $r;
        }
        $dot = static fn(string $v): string => rtrim(str_replace('_', '.', $v), '.');

        // ---- OS + version --------------------------------------------------
        $r['os_name'] = $r['os'] === 'Unknown' ? '' : $r['os'];
        if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
            static $nt = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7', '6.0' => 'Vista', '5.2' => 'XP', '5.1' => 'XP'];
            $r['os_version'] = $nt[$m[1]] ?? $m[1];
        } elseif (preg_match('/HarmonyOS[ \/]?([\d.]*)/i', $ua, $m)) {
            $r['os_name'] = 'HarmonyOS';
            $r['os_version'] = $m[1];
        } elseif (preg_match('/Android[ \-\/]?([\d.]+)?/i', $ua, $m)) {
            $r['os_version'] = $m[1] ?? '';
        } elseif (preg_match('/(iPhone|iPad|iPod)/', $ua, $dev)) {
            if (preg_match('/(?:iPhone )?OS (\d+(?:_\d+)*)/', $ua, $m)) {
                $r['os_version'] = $dot($m[1]);
            }
            if ($dev[1] === 'iPad') {
                $r['os_name'] = 'iPadOS';
            }
        } elseif (preg_match('/CrOS \S+ ([\d.]+)/', $ua, $m)) {
            $r['os_name'] = 'ChromeOS';
            $r['os_version'] = $m[1];
        } elseif (preg_match('/Mac OS X (\d+(?:[_.]\d+)*)/', $ua, $m)) {
            $r['os_version'] = $dot($m[1]);
        } elseif (preg_match('/(Ubuntu|Fedora|Debian)/', $ua, $m)) {
            $r['os_name'] = 'Linux (' . $m[1] . ')';
        }

        // ---- Device brand + model -----------------------------------------
        if (preg_match('/\((iPhone|iPad|iPod)/', $ua, $m)) {
            $r['device_brand'] = 'Apple';
            $r['device_model'] = $m[1] === 'iPod' ? 'iPod touch' : $m[1];
        } elseif (preg_match('/\(Macintosh/', $ua)) {
            $r['device_brand'] = 'Apple';
            $r['device_model'] = 'Mac';
        } elseif (preg_match('/\(([^)]*Android[^)]*)\)/i', $ua, $m)) {
            // "Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv"
            $parts = array_map('trim', explode(';', $m[1]));
            $after = false;
            foreach ($parts as $p) {
                if (stripos($p, 'android') !== false) {
                    $after = true;
                    continue;
                }
                if (!$after || $p === '' || $p === 'wv' || $p === 'U'
                    || preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4})?$/i', $p)) {
                    continue;
                }
                $model = trim(preg_replace('/\s*Build\/.*$/i', '', $p));
                // Chrome's UA reduction sends the literal "K" instead of the model.
                if ($model !== '' && $model !== 'K') {
                    $r['device_model'] = $model;
                    $r['device_brand'] = orbitraUaDeviceBrand($model);
                }
                break;
            }
            if ($r['device_brand'] !== '' && stripos($r['device_model'], $r['device_brand']) === 0) {
                $r['device_model'] = trim(substr($r['device_model'], strlen($r['device_brand'])), " _-");
            }
        }

        // ---- In-app browser (Facebook / Instagram / TikTok ...) -----------
        static $inApp = [
            'Facebook' => '/FBAN\/|FBAV\/([\d.]+)|FB_IAB/',
            'Messenger' => '/FBAN\/Messenger|MessengerForiOS|Orca-Android/',
            'Instagram' => '/Instagram ([\d.]+)/',
            'TikTok' => '/(?:musical_ly|TikTok|BytedanceWebview|trill|aweme)[_\/ ]?([\d.]+)?/i',
            'Snapchat' => '/Snapchat\/?([\d.]+)?/',
            'Telegram' => '/Telegram(?:-Android)?\/?([\d.]+)?/',
            'WeChat' => '/MicroMessenger\/([\d.]+)/',
            'LINE' => '/\bLine\/([\d.]+)/',
            'Pinterest' => '/Pinterest(?:\/| for )?([\d.]+)?/',
            'X (Twitter)' => '/Twitter(?:Android)?(?:\/| for )?([\d.]+)?/',
        ];
        foreach (['Messenger', 'Facebook', 'Instagram', 'TikTok', 'Snapchat', 'Telegram', 'WeChat', 'LINE', 'Pinterest', 'X (Twitter)'] as $app) {
            if (preg_match($inApp[$app], $ua, $m)) {
                $r['in_app'] = $app;
                break;
            }
        }

        // ---- Browser + version --------------------------------------------
        static $browsers = [
            ['Edge', '/Edg(?:e|A|iOS)?\/([\d.]+)/'],
            ['Opera Mini', '/Opera Mini\/([\d.]+)/'],
            ['Opera', '/(?:OPR|OPiOS|OPT)\/([\d.]+)/'],
            ['Samsung Internet', '/SamsungBrowser\/([\d.]+)/'],
            ['Yandex Browser', '/YaBrowser\/([\d.]+)/'],
            ['UC Browser', '/UCBrowser\/([\d.]+)/'],
            ['Mi Browser', '/(?:MiuiBrowser|XiaoMi\/MiuiBrowser)\/([\d.]+)/'],
            ['Huawei Browser', '/HuaweiBrowser\/([\d.]+)/'],
            ['DuckDuckGo', '/(?:DuckDuckGo|Ddg)\/([\d.]+)/'],
            ['Vivaldi', '/Vivaldi\/([\d.]+)/'],
            ['Brave', '/Brave\/([\d.]+)/'],
            ['Chrome', '/CriOS\/([\d.]+)/'],
            ['Firefox', '/FxiOS\/([\d.]+)/'],
            ['Firefox', '/Firefox\/([\d.]+)/'],
            ['Chrome Headless', '/HeadlessChrome\/([\d.]+)/'],
            ['Chrome WebView', '/; wv\).*Chrome\/([\d.]+)/'],
            ['Chrome', '/Chrome\/([\d.]+)/'],
            ['Safari', '/Version\/([\d.]+).*Safari\//'],
            ['Internet Explorer', '/(?:MSIE |Trident\/.*rv:)([\d.]+)/'],
        ];
        foreach ($browsers as [$name, $re]) {
            if (preg_match($re, $ua, $m)) {
                $r['browser_name'] = $name;
                $r['browser_version'] = $m[1] ?? '';
                break;
            }
        }
        if ($r['browser_name'] === '') {
            if ($r['in_app'] !== '') {
                // iOS in-app views are WKWebView without a Version/ token.
                $r['browser_name'] = 'WebView';
            } elseif ($r['browser'] !== 'Unknown') {
                $r['browser_name'] = $r['browser'];
            }
        }
        // Platform suffix where the same name means a different engine/app.
        if ($r['os'] === 'iOS' && in_array($r['browser_name'], ['Chrome', 'Firefox', 'Edge', 'Opera'], true)) {
            $r['browser_name'] .= ' (iOS)';
        } elseif ($r['os'] === 'Android' && $r['browser_name'] === 'Chrome' && $r['device_type'] !== 'Desktop') {
            $r['browser_name'] = 'Chrome Mobile';
        }

        if ($r['bot'] !== '') {
            $r['device_type'] = 'Bot';
            // A tool with no browser token is its own client: "curl 8.5.0",
            // as Keitaro shows it, rather than "Unknown".
            if ($r['browser_name'] === '' || $r['browser'] === 'Unknown') {
                $r['browser_name'] = $r['bot'];
                if (preg_match('/' . preg_quote($r['bot'], '/') . '[\/ ]v?([\d][\d.]*)/i', $ua, $m)) {
                    $r['browser_version'] = rtrim($m[1], '.');
                }
            }
        }
        return $r;
    }
}
