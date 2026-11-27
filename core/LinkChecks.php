<?php
// core/LinkChecks.php
//
// Link checks: on a schedule the tracker opens its own offers and domains the
// way a visitor would and records what came back — every hop of the redirect
// chain, the final page, the verdict. link_checks_cron.php is the only writer;
// the panel, the bot and the Telegram alerts read the same rows through the
// helpers here.
//
// Three rules shape the state machine:
// - A failure is confirmed before it is believed. The first failed check only
//   books a second look two minutes later; the second one fails the link and
//   raises the alert. One good check is enough to recover.
// - A link checked through a proxy that is down is not "broken". The failure
//   is the proxy's: the proxy gets one message of its own, the link is not
//   blamed and gets no alert of its own.
// - Every request goes through core/url_guard.php — public addresses only,
//   every hop vetted — so the check is never a door into the server's LAN.

/** Days the history of checks is kept. */
const ORBITRA_LINK_CHECKS_LOG_DAYS = 30;
/** Seconds between two probes of the same proxy. */
const ORBITRA_LINK_CHECKS_PROXY_PROBE_SECONDS = 600;
/** A first failure is confirmed after this many seconds, not after a whole interval. */
const ORBITRA_LINK_CHECKS_CONFIRM_DELAY = 120;
/** The scope (which offers and domains are checked) is re-read at most this often. */
const ORBITRA_LINK_CHECKS_SYNC_SECONDS = 300;
/** The sub id a check travels under, so partner statistics can tell it from real clicks. */
const ORBITRA_LINK_CHECKS_CLICK_ID = 'orbitra-check';
/** What the check calls itself when the admin has not chosen a User-Agent. */
const ORBITRA_LINK_CHECKS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
/** The probe returns the proxy's exit address and country. */
const ORBITRA_LINK_CHECKS_PROBE_URL = 'https://www.cloudflare.com/cdn-cgi/trace';

require_once __DIR__ . '/url_guard.php';
require_once __DIR__ . '/OfferUrl.php';
require_once __DIR__ . '/AutomationNotify.php';

if (!function_exists('orbitraLinkChecksSettings')) {

    function orbitraLinkChecksNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** The feature's switch panel, with the defaults of a fresh install. */
    function orbitraLinkChecksSettings(PDO $pdo): array
    {
        $rows = [];
        try {
            $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN
                ('link_checks_enabled', 'link_checks_offers', 'link_checks_domains',
                 'link_checks_interval_min', 'link_checks_user_agent', 'link_checks_autopause')");
            foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
                $rows[(string) $key] = (string) $value;
            }
        } catch (\Throwable $e) {
            // Before migration 89 ran: the defaults below apply.
        }
        $interval = (int) ($rows['link_checks_interval_min'] ?? '60');
        return [
            'enabled' => ($rows['link_checks_enabled'] ?? '0') === '1',
            'offers' => ($rows['link_checks_offers'] ?? 'all') === 'all' ? 'all' : 'off',
            'domains' => ($rows['link_checks_domains'] ?? 'all') === 'all' ? 'all' : 'off',
            'interval_min' => $interval >= 5 ? min($interval, 10080) : 60,
            'user_agent' => trim((string) ($rows['link_checks_user_agent'] ?? '')),
            'autopause' => ($rows['link_checks_autopause'] ?? '0') === '1',
        ];
    }

    function orbitraLinkChecksSetSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')");
        $stmt->execute([$key, $value]);
    }

    /** Save the switch panel; unknown or malformed fields keep their current value. */
    function orbitraLinkChecksSetSettings(PDO $pdo, array $input): array
    {
        $current = orbitraLinkChecksSettings($pdo);
        // A User-Agent travels in a request header: a line break in it would
        // inject one, so anything but printable text is refused.
        $userAgent = trim((string) ($input['user_agent'] ?? $current['user_agent']));
        if ($userAgent !== '' && !preg_match('~^[\x20-\x7E]{1,512}$~', $userAgent)) {
            $userAgent = $current['user_agent'];
        }
        $interval = (int) ($input['interval_min'] ?? $current['interval_min']);
        if ($interval < 5) {
            $interval = $current['interval_min'];
        }
        orbitraLinkChecksSetSetting($pdo, 'link_checks_enabled', !empty($input['enabled']) ? '1' : '0');
        orbitraLinkChecksSetSetting($pdo, 'link_checks_offers', ($input['offers'] ?? $current['offers']) === 'all' ? 'all' : 'off');
        orbitraLinkChecksSetSetting($pdo, 'link_checks_domains', ($input['domains'] ?? $current['domains']) === 'all' ? 'all' : 'off');
        orbitraLinkChecksSetSetting($pdo, 'link_checks_interval_min', (string) min($interval, 10080));
        orbitraLinkChecksSetSetting($pdo, 'link_checks_user_agent', $userAgent);
        orbitraLinkChecksSetSetting($pdo, 'link_checks_autopause', !empty($input['autopause']) ? '1' : '0');
        // The scope may have changed: re-read it on the next tick, not in five minutes.
        orbitraLinkChecksSetSetting($pdo, 'link_checks_last_sync_at', '');
        return orbitraLinkChecksSettings($pdo);
    }

    /** A UTC timestamp of the settings table as seconds, or null when it is empty. */
    function orbitraLinkChecksSettingTs(PDO $pdo, string $key): ?int
    {
        try {
            $value = $pdo->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
            $value->execute([$key]);
            $raw = (string) $value->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        return $raw === '' ? null : (strtotime($raw . ' UTC') ?: null);
    }

    // --- Proxies ------------------------------------------------------------

    /**
     * Parse "scheme://[user:pass@]host[:port]" into the fields of a proxy row.
     * Returns [ok, fields, error code]; the codes are translated by
     * orbitraLinkChecksErrorMessage() for the API and by the panel itself.
     */
    function orbitraLinkChecksParseProxyUrl(string $raw): array
    {
        $raw = trim($raw);
        if ($raw !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) {
            $raw = 'http://' . $raw;
        }
        $parts = parse_url($raw);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $types = ['http' => 'http', 'https' => 'https', 'socks5' => 'socks5', 'socks5h' => 'socks5', 'socks4' => 'socks4'];
        if (!is_array($parts) || empty($parts['host']) || !isset($types[$scheme])) {
            return [false, null, 'proxy_format'];
        }
        $port = (int) ($parts['port'] ?? (in_array($types[$scheme], ['http', 'https'], true) ? 8080 : 1080));
        if ($port < 1 || $port > 65535) {
            return [false, null, 'proxy_port'];
        }
        return [true, [
            'type' => $types[$scheme],
            'host' => trim($parts['host'], '[]'),
            'port' => $port,
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ], ''];
    }

    /** The code of why a proxy host cannot be used, or '' when it can. */
    function orbitraLinkChecksProxyHostRefusal(string $host): string
    {
        if ($host === '' || !preg_match('~^[A-Za-z0-9.\-]+$~', $host)) {
            return 'proxy_host';
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        // gethostbyname() hands an IP literal back unchanged — that is not a failure.
        $ip = $literal ? $host : gethostbyname($host);
        if ((!$literal && $ip === $host) || !orbitraUrlGuardIsPublicIp($ip)) {
            // The proxy is the one host the server is sent to on purpose: it has
            // to be out there, not on the server's own LAN.
            return 'proxy_private';
        }
        return '';
    }

    /** A proxy error code in English, for clients that cannot translate it themselves. */
    function orbitraLinkChecksErrorMessage(string $code): string
    {
        $messages = [
            'proxy_format' => 'Use scheme://[user:pass@]host:port with http, https, socks4 or socks5',
            'proxy_port' => 'Port must be between 1 and 65535',
            'proxy_host' => 'Invalid proxy host',
            'proxy_private' => 'The proxy host must resolve to a public address',
            'proxy_type' => 'Use http, https, socks4 or socks5',
            'proxy_credentials' => 'Invalid proxy credentials',
            'proxy_missing' => 'Proxy not found',
            'invalid_fields' => 'Check the fields: interval from 5 minutes, one domain per line',
            'not_found' => 'Link not found',
        ];
        return $messages[$code] ?? $code;
    }

    /** curl options that send a request through a proxy row. */
    function orbitraLinkChecksProxyOptions(array $proxy): array
    {
        $type = CURLPROXY_HTTP;
        if ($proxy['type'] === 'socks5') {
            // The proxy resolves the target name, so geo-DNS answers as it would for a visitor.
            $type = CURLPROXY_SOCKS5_HOSTNAME;
        } elseif ($proxy['type'] === 'socks4') {
            $type = CURLPROXY_SOCKS4;
        } elseif ($proxy['type'] === 'https' && defined('CURLPROXY_HTTPS')) {
            $type = CURLPROXY_HTTPS;
        }
        $options = [
            CURLOPT_PROXY => $proxy['host'] . ':' . (int) $proxy['port'],
            CURLOPT_PROXYTYPE => $type,
        ];
        if ((string) ($proxy['username'] ?? '') !== '') {
            $options[CURLOPT_PROXYUSERPWD] = $proxy['username'] . ':' . (string) ($proxy['password'] ?? '');
        }
        return $options;
    }

    /**
     * Through a proxy the server never resolves the target, so there is no
     * address to vet or to pin: the URL's shape is checked and an IP-literal
     * target must still be public, nothing more is known here.
     */
    function orbitraLinkChecksProxyResolver(string $url): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($scheme === '' || empty($parts['host']) || ($scheme !== 'http' && $scheme !== 'https')) {
            return ['ok' => false, 'host' => $host, 'port' => 0, 'addresses' => [], 'reason' => 'Only http and https URLs are checked'];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && !orbitraUrlGuardIsPublicIp($host)) {
            return ['ok' => false, 'host' => $host, 'port' => 0, 'addresses' => [], 'reason' => 'Host is a private or reserved address'];
        }
        return ['ok' => true, 'host' => $host, 'port' => 0, 'addresses' => [], 'reason' => ''];
    }

    /** One probe of a proxy: its exit address and country, or why it failed. */
    function orbitraLinkChecksProbeProxy(array $proxy, ?callable $fetch = null): array
    {
        $options = orbitraLinkChecksProxyOptions($proxy) + [CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 12];
        $fetch = $fetch ?? 'orbitraUrlGuardFetch';
        $result = $fetch(ORBITRA_LINK_CHECKS_PROBE_URL, $options, 'orbitraLinkChecksProxyResolver');
        $ip = '';
        $country = '';
        if (($result['error'] ?? 'x') === '' && (int) ($result['status'] ?? 0) === 200) {
            foreach (preg_split('~\R~', (string) ($result['body'] ?? '')) ?: [] as $line) {
                if (str_starts_with($line, 'ip=')) {
                    $ip = trim(substr($line, 3));
                } elseif (str_starts_with($line, 'loc=')) {
                    $country = strtoupper(trim(substr($line, 4)));
                }
            }
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $error = (string) ($result['error'] ?? '');
            return ['ok' => false, 'exit_ip' => '', 'exit_country' => '',
                'error' => $error !== '' ? $error : 'HTTP ' . (int) ($result['status'] ?? 0)];
        }
        return ['ok' => true, 'exit_ip' => $ip, 'exit_country' => $country, 'error' => ''];
    }

    // --- What gets checked -----------------------------------------------------

    /** The offer link as a visitor would receive it, with the check's own sub id. */
    function orbitraLinkChecksOfferUrl(array $offer): string
    {
        $url = (string) ($offer['url'] ?? '');
        if ((string) ($offer['tg_channel_id'] ?? '') !== '' && trim($url) === '') {
            // A Telegram-channel offer: the link opens a bot, not a page.
            return '';
        }
        $url = orbitraAppendOfferParams($url, (string) ($offer['offer_params'] ?? ''));
        return trim(orbitraResolveOfferUrlMacros($url, ORBITRA_LINK_CHECKS_CLICK_ID, (int) $offer['id'], []));
    }

    function orbitraLinkChecksDomainUrl(array $domain): string
    {
        $secure = !empty($domain['https_only']) || in_array($domain['ssl_status'] ?? '', ['installed', 'cloudflare'], true);
        return ($secure ? 'https' : 'http') . '://' . strtolower(trim((string) $domain['name'])) . '/';
    }

    /**
     * Bring link_checks in line with the scope: add what is now in it, drop
     * what left it (archived, deleted, or switched off), and re-read a changed
     * name or URL. Per-link settings stay untouched.
     */
    function orbitraLinkChecksSync(PDO $pdo, array $settings): array
    {
        $wanted = [];
        if ($settings['offers'] === 'all') {
            $offers = $pdo->query("SELECT o.id, o.name, o.url, o.tg_channel_id, n.offer_params
                FROM offers o
                LEFT JOIN affiliate_networks n ON n.id = o.affiliate_network_id
                WHERE o.is_archived = 0 AND o.is_local = 0 AND o.url != ''");
            foreach ($offers->fetchAll(PDO::FETCH_ASSOC) as $offer) {
                $url = orbitraLinkChecksOfferUrl($offer);
                if (preg_match('~^https?://~i', $url)) {
                    $wanted['offer:' . (int) $offer['id']] = [(string) $offer['name'], $url];
                }
            }
        }
        if ($settings['domains'] === 'all') {
            foreach ($pdo->query('SELECT id, name, ssl_status, https_only FROM domains')->fetchAll(PDO::FETCH_ASSOC) as $domain) {
                $wanted['domain:' . (int) $domain['id']] = [(string) $domain['name'], orbitraLinkChecksDomainUrl($domain)];
            }
        }

        $existing = [];
        foreach ($pdo->query('SELECT id, object_type, object_id, label, url FROM link_checks')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['object_type'] . ':' . (int) $row['object_id']] = $row;
        }

        $added = 0;
        $removed = 0;
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT OR IGNORE INTO link_checks (object_type, object_id, label, url, next_check_at)
                VALUES (?, ?, ?, ?, ?)');
            $rename = $pdo->prepare('UPDATE link_checks SET label = ?, url = ? WHERE id = ?');
            // A new link says nothing about the old one: what was learned about
            // the destination is forgotten and the next check runs right away.
            $relearn = $pdo->prepare("UPDATE link_checks SET learned_domain = NULL, fail_streak = 0, status = 'pending',
                next_check_at = ? WHERE id = ?");
            foreach ($wanted as $key => [$label, $url]) {
                if (!isset($existing[$key])) {
                    // Staggered over ten minutes, so a fresh install does not open every link in one tick.
                    $insert->execute([substr($key, 0, strpos($key, ':')), (int) substr($key, strpos($key, ':') + 1),
                        $label, $url, gmdate('Y-m-d H:i:s', time() + random_int(0, 600))]);
                    $added++;
                } elseif ($existing[$key]['label'] !== $label || $existing[$key]['url'] !== $url) {
                    $rename->execute([$label, $url, (int) $existing[$key]['id']]);
                    if ($existing[$key]['url'] !== $url) {
                        $relearn->execute([gmdate('Y-m-d H:i:s', time() + random_int(0, 120)), (int) $existing[$key]['id']]);
                    }
                }
            }
            $dropLogs = $pdo->prepare('DELETE FROM link_check_logs WHERE check_id = ?');
            $drop = $pdo->prepare('DELETE FROM link_checks WHERE id = ?');
            foreach ($existing as $key => $row) {
                if (!isset($wanted[$key])) {
                    $dropLogs->execute([(int) $row['id']]);
                    $drop->execute([(int) $row['id']]);
                    $removed++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['added' => $added, 'removed' => $removed];
    }

    // --- One check -------------------------------------------------------------

    /** The settings a link runs with: its own where it has them, the defaults elsewhere. */
    function orbitraLinkChecksEffective(array $row, array $settings): array
    {
        $interval = (int) ($row['interval_min'] ?? 0);
        $interval = min($interval >= 5 ? $interval : $settings['interval_min'], 10080);
        $userAgent = trim((string) ($row['user_agent'] ?? ''));
        $mode = in_array($row['expected_mode'] ?? '', ['auto', 'list', 'off'], true) ? $row['expected_mode'] : 'auto';
        $domains = [];
        foreach (preg_split('~[\s,]+~', (string) ($row['expected_domains'] ?? '')) ?: [] as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }
        return [
            'interval_min' => $interval,
            'user_agent' => $userAgent !== '' ? $userAgent : ($settings['user_agent'] !== '' ? $settings['user_agent'] : ORBITRA_LINK_CHECKS_UA),
            'expected_mode' => $mode,
            'expected_domains' => $domains,
        ];
    }

    function orbitraLinkChecksDomainMatches(string $host, string $expected): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $expected = strtolower(rtrim($expected, '.'));
        return $expected !== '' && ($host === $expected || str_ends_with($host, '.' . $expected));
    }

    /**
     * A redirect the page performs itself, where a plain HTTP client sees a 200:
     * <meta http-equiv="refresh" content="0;url=…"> or a location assignment in
     * an inline script. Found by pattern, not by a browser — enough for the
     * tracker and cloaking pages that put the jump in the first screenful.
     */
    function orbitraLinkChecksPageRedirect(string $url, int $status, string $body, string $headers): string
    {
        if ($status < 200 || $status >= 300 || $body === '') {
            return '';
        }
        $head = substr($body, 0, 20000);
        if (preg_match('~<meta\b[^>]*\bcontent\s*=\s*["\']?\s*(\d+)\s*;\s*url\s*=\s*(["\']?)([^"\'\s>;]+)\2[^>]*>~i', $head, $m)
            && stripos($m[0], 'refresh') !== false) {
            return (int) $m[1] <= 5 ? html_entity_decode($m[3], ENT_QUOTES) : '';
        }
        if (preg_match_all('~(?:window\.|document\.|top\.|self\.)?location(?:\.href|\.pathname)?\s*=\s*["\'](https?://[^"\'\s]+)["\']~i', $head, $matches, PREG_OFFSET_CAPTURE)
            || preg_match_all('~location\.(?:replace|assign)\(\s*["\'](https?://[^"\'\s]+)["\']~i', $head, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$target, $offset]) {
                // A jump wired to an event ("onclick=\"location.href=…\"") is a
                // button, not a redirect: the tag it sits in names the event.
                $tagStart = (int) strrpos(substr($head, 0, (int) $offset), '<');
                $before = substr($head, $tagStart, (int) $offset - $tagStart);
                if (!preg_match('~\bon[a-z]+\s*=~i', $before)) {
                    return html_entity_decode($target, ENT_QUOTES);
                }
            }
        }
        return '';
    }

    /** Whether the final answer is an anti-bot page rather than the site. */
    function orbitraLinkChecksProtection(string $headers, string $body): bool
    {
        if (preg_match('~^cf-mitigated:\s*challenge~mi', $headers)) {
            return true;
        }
        return (bool) preg_match('~cf-chl|challenge-platform|_Incapsula_Resource|px-captcha|Just a moment\.\.\.|Checking your browser before accessing|Attention Required! \| Cloudflare~i', substr($body, 0, 60000));
    }

    /**
     * Turn one fetch into a verdict: the status the link gets, the machine code
     * of the reason and whose failure it is ('' when nothing failed).
     */
    function orbitraLinkChecksVerdict(array $fetch, array $effective, string $objectType, bool $proxied, int $durationMs, string $learnedDomain): array
    {
        if (($fetch['reason'] ?? '') !== '') {
            // A name nothing resolves is a dead link; only a link into a private
            // network is one the server refuses to follow.
            $unresolved = ($fetch['reason_code'] ?? '') === 'url_host_unresolved';
            return $unresolved
                ? ['status' => 'failed', 'error_code' => 'dns_error', 'blame' => 'target', 'learn' => null]
                : ['status' => 'failed', 'error_code' => 'url_blocked', 'blame' => '', 'learn' => null];
        }
        $error = strtolower((string) ($fetch['error'] ?? ''));
        if ($error !== '') {
            if ($proxied && strpos($error, 'redirect') === false) {
                // Nothing came back from the far end: with a proxy in the way that is the proxy's doing.
                return ['status' => 'failed', 'error_code' => 'proxy_error', 'blame' => 'proxy', 'learn' => null];
            }
            $code = 'connection_error';
            if (strpos($error, 'timed out') !== false) {
                $code = 'timeout';
            } elseif (strpos($error, 'could not resolve') !== false) {
                $code = 'dns_error';
            } elseif (strpos($error, 'refused') !== false) {
                $code = 'connection_refused';
            } elseif (strpos($error, 'ssl') !== false || strpos($error, 'certificate') !== false) {
                $code = 'ssl_error';
            } elseif (strpos($error, 'redirect loop') !== false) {
                $code = 'redirect_loop';
            } elseif (strpos($error, 'too many redirects') !== false) {
                $code = 'too_many_redirects';
            }
            return ['status' => 'failed', 'error_code' => $code, 'blame' => 'target', 'learn' => null];
        }

        $status = (int) ($fetch['status'] ?? 0);
        $body = (string) ($fetch['body'] ?? '');
        $headers = (string) ($fetch['headers'] ?? '');
        $warning = static function (string $code): array {
            return ['status' => 'warning', 'error_code' => $code, 'blame' => 'target', 'learn' => null];
        };

        // A domain is up when its web server answers at all: a 404 is a configured
        // answer, a dead host is not.
        if ($objectType === 'domain') {
            if ($status === 0) {
                return ['status' => 'failed', 'error_code' => 'connection_error', 'blame' => 'target', 'learn' => null];
            }
            if ($status >= 500) {
                return ['status' => 'failed', 'error_code' => 'http_' . $status, 'blame' => 'target', 'learn' => null];
            }
            return ['status' => 'ok', 'error_code' => '', 'blame' => '', 'learn' => null];
        }

        if ($status < 200 || $status >= 300) {
            if (orbitraLinkChecksProtection($headers, $body)) {
                // The redirects work, the page itself did not let the check in.
                return $warning('site_protection');
            }
            return ['status' => 'failed', 'error_code' => 'http_' . $status, 'blame' => 'target', 'learn' => null];
        }
        if (orbitraLinkChecksProtection($headers, $body)) {
            return $warning('site_protection');
        }

        $learn = null;
        if ($effective['expected_mode'] !== 'off') {
            $host = strtolower(rtrim((string) parse_url((string) ($fetch['url'] ?? ''), PHP_URL_HOST), '.'));
            if ($effective['expected_mode'] === 'auto') {
                if ($learnedDomain === '') {
                    // First good look at the destination: this is where the link is supposed to lead.
                    if ($host !== '') {
                        $learn = $host;
                    }
                } elseif (!orbitraLinkChecksDomainMatches($host, $learnedDomain)) {
                    return $warning('unexpected_final_url');
                }
            } elseif ($effective['expected_domains'] !== []) {
                $allowed = false;
                foreach ($effective['expected_domains'] as $expected) {
                    if (orbitraLinkChecksDomainMatches($host, $expected)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    return $warning('unexpected_final_url');
                }
            }
        }

        foreach ((array) ($fetch['hops'] ?? []) as $hop) {
            // Only an explicit http:// target is a downgrade: a relative or a
            // protocol-relative Location keeps the hop's own scheme.
            if (stripos((string) ($hop['location'] ?? ''), 'http://') === 0
                && stripos((string) $hop['url'], 'https://') === 0) {
                return $warning('https_to_http');
            }
        }
        if ($durationMs > 10000) {
            return $warning('slow_response');
        }
        return ['status' => 'ok', 'error_code' => '', 'blame' => '', 'learn' => $learn];
    }

    /**
     * Run one check without touching the database. $fetch is injectable for
     * tests and has the signature of orbitraUrlGuardFetch().
     */
    function orbitraLinkChecksRun(array $row, array $settings, ?array $proxy, ?callable $fetch = null): array
    {
        $effective = orbitraLinkChecksEffective($row, $settings);
        // A link pointed at a proxy that is gone or switched off is the proxy's failure, not the link's.
        $proxied = !empty($row['proxy_id']);
        $options = [
            CURLOPT_USERAGENT => $effective['user_agent'],
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
        ];
        $startedAt = microtime(true);
        if (!$proxied || $proxy === null) {
            $result = ['url' => (string) $row['url'], 'status' => 0, 'body' => '', 'error' => '', 'reason' => '', 'headers' => '', 'hops' => []];
            if ($proxied) {
                $result['error'] = 'proxy is unavailable';
            } elseif ($fetch !== null) {
                $result = $fetch((string) $row['url'], $options, 'orbitraUrlGuardResolve', 'orbitraLinkChecksPageRedirect');
            } else {
                $result = orbitraUrlGuardFetch((string) $row['url'], $options, 'orbitraUrlGuardResolve', 'orbitraLinkChecksPageRedirect');
            }
        } else {
            $options = orbitraLinkChecksProxyOptions($proxy) + $options;
            $result = $fetch !== null
                ? $fetch((string) $row['url'], $options, 'orbitraLinkChecksProxyResolver', 'orbitraLinkChecksPageRedirect')
                : orbitraUrlGuardFetch((string) $row['url'], $options, 'orbitraLinkChecksProxyResolver', 'orbitraLinkChecksPageRedirect');
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $verdict = orbitraLinkChecksVerdict($result, $effective, (string) $row['object_type'], $proxied, $durationMs, (string) ($row['learned_domain'] ?? ''));

        $hops = [];
        foreach (array_slice((array) ($result['hops'] ?? []), 0, 12) as $hop) {
            $hops[] = [
                'url' => substr((string) $hop['url'], 0, 2000),
                'status' => (int) $hop['status'],
                'ms' => (int) $hop['ms'],
                'location' => substr((string) ($hop['location'] ?? ''), 0, 2000),
                'error' => (string) ($hop['error'] ?? ''),
            ];
        }
        return [
            'verdict' => $verdict,
            'log' => [
                'duration_ms' => $durationMs,
                'status' => $verdict['status'],
                'error_code' => $verdict['error_code'],
                'http_code' => (int) ($result['status'] ?? 0),
                'final_url' => substr((string) ($result['url'] ?? ''), 0, 2000),
                'proxy_id' => $proxied ? (int) $row['proxy_id'] : null,
                'user_agent' => $effective['user_agent'],
                'hops' => json_encode($hops),
            ],
        ];
    }

    /**
     * The state machine. Returns the columns to store and the event to tell
     * the owner about (null when there is nothing to say).
     */
    function orbitraLinkChecksTransition(array $row, array $verdict, array $effective, string $now, int $timestamp): array
    {
        $previous = (string) ($row['status'] ?? 'pending');
        $alerted = (int) ($row['alerted'] ?? 0);
        $event = null;
        // ±10 % keeps a thousand links from knocking on the same door in the same second.
        $jitter = random_int(-(int) floor($effective['interval_min'] * 6), (int) floor($effective['interval_min'] * 6));
        $next = gmdate('Y-m-d H:i:s', $timestamp + $effective['interval_min'] * 60 + $jitter);
        $fields = ['error_code' => $verdict['error_code']];

        if ($verdict['status'] === 'ok') {
            $fields += ['status' => 'ok', 'fail_streak' => 0, 'next_check_at' => $next];
            if ($alerted && $previous !== 'ok') {
                $event = ['kind' => 'recovered', 'since' => (string) ($row['last_change_at'] ?? '')];
                $fields['alerted'] = 0;
            }
        } elseif ($verdict['blame'] === 'proxy') {
            // Shown as failed, never alerted per link, and looked at again soon.
            $fields += ['status' => 'failed', 'next_check_at' => gmdate('Y-m-d H:i:s', $timestamp + 300)];
        } elseif ($verdict['status'] === 'failed') {
            $streak = (int) ($row['fail_streak'] ?? 0) + 1;
            $fields['fail_streak'] = $streak;
            if ($streak <= 1) {
                $fields += ['status' => 'warning', 'next_check_at' => gmdate('Y-m-d H:i:s', $timestamp + ORBITRA_LINK_CHECKS_CONFIRM_DELAY)];
            } else {
                $fields += ['status' => 'failed', 'next_check_at' => $next];
                if (!$alerted) {
                    $event = ['kind' => 'failed'];
                    $fields['alerted'] = 1;
                }
            }
        } else {
            $fields += ['status' => 'warning', 'fail_streak' => 0, 'next_check_at' => $next];
            if (!$alerted && $previous !== 'warning') {
                $event = ['kind' => 'warning'];
                $fields['alerted'] = 1;
            }
        }
        if ($fields['status'] !== $previous) {
            $fields['last_change_at'] = $now;
        }
        return ['fields' => $fields, 'event' => $event];
    }

    /** Store a finished check: the link's new state and one history row. */
    function orbitraLinkChecksStore(PDO $pdo, array $row, array $run, string $now, int $timestamp, ?array $settings = null): ?array
    {
        $effective = orbitraLinkChecksEffective($row, $settings ?? orbitraLinkChecksSettings($pdo));
        $transition = orbitraLinkChecksTransition($row, $run['verdict'], $effective, $now, $timestamp);
        $fields = $transition['fields'] + [
            'http_code' => $run['log']['http_code'],
            'final_url' => $run['log']['final_url'],
            'duration_ms' => $run['log']['duration_ms'],
            'last_checked_at' => $now,
        ];
        if ($run['verdict']['learn'] !== null) {
            $fields['learned_domain'] = $run['verdict']['learn'];
        }
        $assignments = implode(', ', array_map(static fn (string $column) => "$column = ?", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE link_checks SET $assignments WHERE id = ?");
        $stmt->execute(array_merge(array_values($fields), [(int) $row['id']]));

        $log = $run['log'] + ['check_id' => (int) $row['id'], 'started_at' => $now];
        $columns = implode(', ', array_keys($log));
        $marks = implode(', ', array_fill(0, count($log), '?'));
        $pdo->prepare("INSERT INTO link_check_logs ($columns) VALUES ($marks)")->execute(array_values($log));

        $event = $transition['event'];
        if ($event !== null) {
            $event += ['row' => array_merge($row, $fields), 'verdict' => $run['verdict'], 'log' => $run['log'],
                'ended' => $timestamp, 'paused' => false];
        }
        return $event;
    }

    function orbitraLinkChecksChainText(array $log): string
    {
        $hosts = [];
        foreach (json_decode((string) ($log['hops'] ?? ''), true) ?: [] as $hop) {
            $host = strtolower((string) parse_url((string) ($hop['url'] ?? ''), PHP_URL_HOST));
            $host = $host !== '' ? $host : (string) ($hop['url'] ?? '');
            if ($host !== '' && ($hosts === [] || end($hosts) !== $host)) {
                $hosts[] = $host;
            }
        }
        $chain = implode(' → ', $hosts);
        $code = (int) ($log['http_code'] ?? 0);
        return $chain . ($code > 0 ? " ($code)" : '');
    }

    function orbitraLinkChecksDuration(int $seconds): string
    {
        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . ' min';
        }
        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . ' h ' . (int) floor(($seconds % 3600) / 60) . ' min';
        }
        return (int) floor($seconds / 86400) . ' d ' . (int) floor(($seconds % 86400) / 3600) . ' h';
    }

    // --- Reading, for the panel and the bot ------------------------------------

    function orbitraLinkChecksStatusCounts(): array
    {
        return ['total' => 0, 'ok' => 0, 'warning' => 0, 'failed' => 0, 'pending' => 0];
    }

    function orbitraLinkChecksSummary(PDO $pdo): array
    {
        $settings = orbitraLinkChecksSettings($pdo);
        $summary = [
            'enabled' => $settings['enabled'],
            'offers' => orbitraLinkChecksStatusCounts(),
            'domains' => orbitraLinkChecksStatusCounts(),
            'failed' => 0,
            'warning' => 0,
        ];
        try {
            $rows = $pdo->query("SELECT object_type, status, COUNT(*) AS n FROM link_checks
                WHERE is_enabled = 1 GROUP BY object_type, status")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $type = (string) $row['object_type'] . 's';
            $status = (string) $row['status'];
            if (!isset($summary[$type])) {
                continue;
            }
            $summary[$type]['total'] += (int) $row['n'];
            if (isset($summary[$type][$status])) {
                $summary[$type][$status] += (int) $row['n'];
            }
            if ($status === 'failed' || $status === 'warning') {
                $summary[$status] += (int) $row['n'];
            }
        }
        return $summary;
    }

    /** One link as the panel shows it: parsed lists, timestamps in seconds. */
    function orbitraLinkChecksHydrate(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['last_checked_at', 'next_check_at', 'last_change_at', 'proxy_last_checked_at'], true)) {
                $out[$key] = $value;
                $out[$key . '_ts'] = $value ? (strtotime((string) $value . ' UTC') ?: null) : null;
            } else {
                $out[$key] = $value;
            }
        }
        $out['id'] = (int) ($row['id'] ?? 0);
        $out['object_id'] = (int) ($row['object_id'] ?? 0);
        $out['is_enabled'] = (int) ($row['is_enabled'] ?? 0) === 1;
        $out['proxy_id'] = $row['proxy_id'] !== null ? (int) $row['proxy_id'] : null;
        $out['interval_min'] = $row['interval_min'] !== null ? (int) $row['interval_min'] : null;
        $out['learned_domain'] = (string) ($row['learned_domain'] ?? '');
        $out['expected_domains'] = array_values(array_filter(array_map('trim', preg_split('~[\s,]+~', (string) ($row['expected_domains'] ?? '')) ?: [])));
        return $out;
    }

    /**
     * The list of checks. Problems first. $filters: id, type, status ('' or a
     * status, 'problem' for failed and warning together), q, limit, offset.
     */
    function orbitraLinkChecksList(PDO $pdo, array $filters = []): array
    {
        $where = ['lc.is_enabled = 1'];
        $params = [];
        $type = (string) ($filters['type'] ?? '');
        $one = (int) ($filters['id'] ?? 0);
        if ($one > 0) {
            $where[] = 'lc.id = ?';
            $params[] = $one;
        }
        if (in_array($type, ['offer', 'domain'], true)) {
            $where[] = 'lc.object_type = ?';
            $params[] = $type;
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'problem') {
            $where[] = "lc.status IN ('failed', 'warning')";
        } elseif (in_array($status, ['ok', 'warning', 'failed', 'pending'], true)) {
            $where[] = 'lc.status = ?';
            $params[] = $status;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(lc.label LIKE ? ESCAPE \'\\\' OR lc.url LIKE ? ESCAPE \'\\\' OR lc.final_url LIKE ? ESCAPE \'\\\')';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like, $like);
        }
        $clause = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM link_checks lc WHERE $clause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $listStmt = $pdo->prepare("SELECT lc.*, cp.name AS proxy_name, cp.status AS proxy_status, cp.exit_country AS proxy_country,
                cp.last_checked_at AS proxy_last_checked_at
            FROM link_checks lc
            LEFT JOIN check_proxies cp ON cp.id = lc.proxy_id
            WHERE $clause
            ORDER BY CASE lc.status WHEN 'failed' THEN 0 WHEN 'warning' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END,
                COALESCE(lc.last_checked_at, lc.created_at) DESC, lc.id ASC
            LIMIT " . $limit . ' OFFSET ' . $offset);
        $listStmt->execute($params);
        $rows = array_map('orbitraLinkChecksHydrate', $listStmt->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => $total];
    }

    /** The history of one link, newest first, with every hop of each check. */
    function orbitraLinkChecksLog(PDO $pdo, int $checkId, int $limit = 30): array
    {
        $stmt = $pdo->prepare('SELECT l.*, cp.name AS proxy_name FROM link_check_logs l
            LEFT JOIN check_proxies cp ON cp.id = l.proxy_id
            WHERE l.check_id = ? ORDER BY l.id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute([$checkId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['started_at_ts'] = $row['started_at'] ? (strtotime((string) $row['started_at'] . ' UTC') ?: null) : null;
            $row['hops'] = json_decode((string) ($row['hops'] ?? ''), true) ?: [];
            $rows[] = $row;
        }
        return $rows;
    }

    /** Proxies as the panel shows them: the password never leaves the server. */
    function orbitraLinkChecksProxyRows(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT cp.*,
                (SELECT COUNT(*) FROM link_checks lc WHERE lc.proxy_id = cp.id AND lc.is_enabled = 1) AS links
            FROM check_proxies cp ORDER BY cp.is_active DESC, cp.name')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'host' => (string) $row['host'],
                'port' => (int) $row['port'],
                'username' => (string) ($row['username'] ?? ''),
                'has_password' => (string) ($row['password'] ?? '') !== '',
                'status' => (string) $row['status'],
                'exit_ip' => (string) ($row['exit_ip'] ?? ''),
                'exit_country' => (string) ($row['exit_country'] ?? ''),
                'last_error' => (string) ($row['last_error'] ?? ''),
                'last_checked_at' => $row['last_checked_at'],
                'last_checked_at_ts' => $row['last_checked_at'] ? (strtotime((string) $row['last_checked_at'] . ' UTC') ?: null) : null,
                'is_active' => (int) $row['is_active'] === 1,
                'links' => (int) $row['links'],
            ];
        }
        return $out;
    }

    /**
     * Create or edit a proxy. A new one is probed right away so the panel can
     * show whether it works; an edit of its address or credentials re-probes it.
     * Returns [row | null, error].
     */
    function orbitraLinkChecksSaveProxy(PDO $pdo, array $input, ?callable $fetch = null): array
    {
        $id = (int) ($input['id'] ?? 0);
        $existing = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM check_proxies WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$existing) {
                return [null, 'proxy_missing'];
            }
        }
        $fields = $existing ?: ['type' => '', 'host' => '', 'port' => 0, 'username' => '', 'password' => ''];
        $pasted = trim((string) ($input['url'] ?? ''));
        if ($pasted !== '') {
            [$ok, $parsed, $reason] = orbitraLinkChecksParseProxyUrl($pasted);
            if (!$ok) {
                return [null, $reason];
            }
            $fields = array_merge($fields, $parsed);
        }
        foreach (['type' => 'type', 'host' => 'host', 'username' => 'username', 'password' => 'password'] as $key => $column) {
            if (array_key_exists($key, $input)) {
                $fields[$column] = trim((string) $input[$key]);
            }
        }
        if (isset($input['port'])) {
            $fields['port'] = (int) $input['port'];
        }
        if (!in_array($fields['type'], ['http', 'https', 'socks4', 'socks5'], true)) {
            return [null, 'proxy_type'];
        }
        $refusal = orbitraLinkChecksProxyHostRefusal((string) $fields['host']);
        if ($refusal !== '') {
            return [null, $refusal];
        }
        if ($fields['port'] < 1 || $fields['port'] > 65535) {
            return [null, 'proxy_port'];
        }
        foreach (['username', 'password'] as $key) {
            if (preg_match('~[\r\n\x00]~', (string) $fields[$key])) {
                return [null, 'proxy_credentials'];
            }
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = $fields['host'] . ':' . $fields['port'];
        }
        $identityChanged = $existing === null
            || $existing['type'] !== $fields['type'] || $existing['host'] !== $fields['host']
            || (int) $existing['port'] !== (int) $fields['port'] || (string) $existing['username'] !== (string) $fields['username']
            || (string) $existing['password'] !== (string) $fields['password'];

        if ($existing) {
            // A proxy is switched off only when the form says so, never by being left out.
            $isActive = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : (int) $existing['is_active'];
            $stmt = $pdo->prepare("UPDATE check_proxies SET name = ?, type = ?, host = ?, port = ?, username = ?, password = ?,
                is_active = ?, status = ?, last_error = NULL WHERE id = ?");
            $stmt->execute([$name, $fields['type'], $fields['host'], (int) $fields['port'], $fields['username'], $fields['password'],
                $isActive, $identityChanged ? 'unknown' : (string) $existing['status'], $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO check_proxies (name, type, host, port, username, password, status, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 'unknown', 1)");
            $stmt->execute([$name, $fields['type'], $fields['host'], (int) $fields['port'], $fields['username'], $fields['password']]);
            $id = (int) $pdo->lastInsertId();
        }
        // A fresh or changed proxy is probed at once: the admin sees here and now whether it works.
        if ($identityChanged || $existing === null) {
            $probe = orbitraLinkChecksProbeProxy($fields, $fetch);
            $stmt = $pdo->prepare("UPDATE check_proxies SET status = ?, exit_ip = ?, exit_country = ?, last_error = ?,
                last_checked_at = ? WHERE id = ?");
            $stmt->execute([$probe['ok'] ? 'ok' : 'failed', $probe['exit_ip'], $probe['exit_country'],
                $probe['ok'] ? '' : $probe['error'], orbitraLinkChecksNow(), $id]);
        }
        foreach (orbitraLinkChecksProxyRows($pdo) as $row) {
            if ((int) $row['id'] === $id) {
                return [$row, ''];
            }
        }
        return [null, 'proxy_missing'];
    }

    /**
     * Per-link settings. Anything absent keeps its value; null clears it back
     * to the default. Returns false for a bad value, null for an unknown link.
     */
    function orbitraLinkChecksSaveLink(PDO $pdo, int $id, array $input): array|false|null
    {
        $stmt = $pdo->prepare('SELECT * FROM link_checks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $fields = [];
        if (array_key_exists('is_enabled', $input)) {
            $fields['is_enabled'] = !empty($input['is_enabled']) ? 1 : 0;
        }
        if (array_key_exists('interval_min', $input)) {
            $interval = $input['interval_min'] === null || $input['interval_min'] === '' ? null : (int) $input['interval_min'];
            $fields['interval_min'] = $interval !== null && $interval >= 5 ? min($interval, 10080) : null;
        }
        if (array_key_exists('proxy_id', $input)) {
            $proxyId = $input['proxy_id'] === null || $input['proxy_id'] === '' || (int) $input['proxy_id'] <= 0 ? null : (int) $input['proxy_id'];
            if ($proxyId !== null) {
                $check = $pdo->prepare('SELECT id FROM check_proxies WHERE id = ? AND is_active = 1');
                $check->execute([$proxyId]);
                if (!$check->fetchColumn()) {
                    return false;
                }
            }
            $fields['proxy_id'] = $proxyId;
        }
        if (array_key_exists('user_agent', $input)) {
            $userAgent = trim((string) $input['user_agent']);
            if ($userAgent !== '' && !preg_match('~^[\x20-\x7E]{1,512}$~', $userAgent)) {
                return false;
            }
            $fields['user_agent'] = $userAgent;
        }
        if (array_key_exists('expected_mode', $input)) {
            if (!in_array($input['expected_mode'], ['auto', 'list', 'off'], true)) {
                return false;
            }
            $fields['expected_mode'] = $input['expected_mode'];
        }
        if (array_key_exists('expected_domains', $input)) {
            $domains = [];
            foreach (preg_split('~[\s,]+~', (string) (is_array($input['expected_domains']) ? implode("\n", $input['expected_domains']) : $input['expected_domains'])) ?: [] as $domain) {
                $domain = strtolower(trim($domain));
                if ($domain === '') {
                    continue;
                }
                if (!preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$~', $domain)) {
                    return false;
                }
                $domains[] = $domain;
            }
            if (count($domains) > 20) {
                return false;
            }
            $fields['expected_domains'] = implode("\n", $domains);
        }
        if (array_key_exists('learned_domain', $input) && ($input['learned_domain'] === null || $input['learned_domain'] === '')) {
            // Forgetting the learned destination makes the next good check learn it again.
            $fields['learned_domain'] = null;
        }
        if ($fields !== []) {
            // A changed route is worth a fresh look right away.
            $fields += ['next_check_at' => gmdate('Y-m-d H:i:s', time() + random_int(0, 30))];
            $assignments = implode(', ', array_map(static fn (string $column) => "$column = ?", array_keys($fields)));
            $pdo->prepare("UPDATE link_checks SET $assignments WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
        }
        $fresh = $pdo->prepare('SELECT lc.*, cp.name AS proxy_name FROM link_checks lc
            LEFT JOIN check_proxies cp ON cp.id = lc.proxy_id WHERE lc.id = ?');
        $fresh->execute([$id]);
        $freshRow = $fresh->fetch(PDO::FETCH_ASSOC);
        return $freshRow ? orbitraLinkChecksHydrate($freshRow) : null;
    }

    // --- Telling the owner -------------------------------------------------------

    /** A reason code as a sentence: "HTTP 404" for any http_NNN, the code's own text otherwise. */
    function orbitraLinkChecksReason(string $lang, string $code, array $params = []): string
    {
        $key = 'r_' . $code;
        if (preg_match('~^http_(\d+)$~', $code, $m)) {
            $key = 'r_http';
            $params['code'] = $m[1];
        }
        $text = orbitraLinkChecksText($lang, $key, $params);
        return $text === $key ? $code : $text;
    }

    function orbitraLinkChecksKind(string $lang, string $objectType): string
    {
        return orbitraLinkChecksText($lang, $objectType === 'domain' ? 'kind_domain' : 'kind_offer');
    }

    function orbitraLinkChecksProblemText(string $lang, array $event): string
    {
        $row = $event['row'];
        $code = (string) $event['verdict']['error_code'];
        $params = [];
        if ($code === 'unexpected_final_url') {
            $host = strtolower(rtrim((string) parse_url((string) ($event['log']['final_url'] ?? ''), PHP_URL_HOST), '.'));
            $params = ['expected' => str_replace("\n", ', ', (string) (($row['expected_mode'] ?? 'auto') === 'list' ? $row['expected_domains'] : $row['learned_domain'])), 'got' => $host];
        } elseif ($code === 'slow_response') {
            $params = ['seconds' => round((int) $event['log']['duration_ms'] / 1000, 1)];
        }
        $lines = [orbitraLinkChecksText($lang, $event['verdict']['status'] === 'failed' ? 'failed' : 'warning',
            ['kind' => orbitraLinkChecksKind($lang, (string) $row['object_type']), 'name' => (string) $row['label']])];
        $lines[] = orbitraLinkChecksText($lang, 'reason', ['reason' => orbitraLinkChecksReason($lang, $code, $params)]);
        $chain = orbitraLinkChecksChainText($event['log']);
        if ($chain !== '' && $code !== 'proxy_error') {
            $lines[] = orbitraLinkChecksText($lang, 'chain', ['chain' => $chain]);
        }
        if (!empty($event['paused'])) {
            $lines[] = orbitraLinkChecksText($lang, 'paused');
        }
        // The link itself is left out on purpose: in a group chat its URL and tokens would be public.
        return implode("\n", $lines);
    }

    /**
     * One message per event — or one digest when many links change in the same
     * pass: a network drop fails everything at once, and that is one fact, not
     * fifty messages. Returns how many sends were attempted.
     */
    function orbitraLinkChecksNotifyLinks(PDO $pdo, array $events, ?callable $broadcast = null): int
    {
        if ($events === []) {
            return 0;
        }
        $send = static function (callable $build) use ($pdo, $broadcast): int {
            return $broadcast !== null ? (int) $broadcast($build)
                : orbitraAutomationBroadcast($pdo, $build, ['flag' => 'notify_link_checks']);
        };
        $isRecovery = static fn (array $event): bool => $event['kind'] === 'recovered';
        $problems = array_values(array_filter($events, static fn (array $e): bool => !$isRecovery($e)));
        $recoveries = array_values(array_filter($events, $isRecovery));

        $sent = 0;
        if (count($problems) > 5) {
            $sent += $send(static function (string $lang) use ($problems): string {
                $names = implode(', ', array_map(static fn (array $e): string => (string) $e['row']['label'], array_slice($problems, 0, 5)));
                if (count($problems) > 5) {
                    $names .= ', ' . orbitraLinkChecksText($lang, 'more', ['count' => count($problems) - 5]);
                }
                return orbitraLinkChecksText($lang, 'many_failed', ['count' => count($problems), 'names' => $names]);
            });
        } else {
            foreach ($problems as $event) {
                $sent += $send(static fn (string $lang): string => orbitraLinkChecksProblemText($lang, $event));
            }
        }
        if (count($recoveries) > 5) {
            $sent += $send(static fn (string $lang): string => orbitraLinkChecksText($lang, 'many_recovered', ['count' => count($recoveries)]));
        } else {
            foreach ($recoveries as $event) {
                $sent += $send(static function (string $lang) use ($event): string {
                    $since = strtotime((string) $event['since'] . ' UTC') ?: (int) $event['ended'];
                    return orbitraLinkChecksText($lang, 'recovered', [
                        'kind' => orbitraLinkChecksKind($lang, (string) $event['row']['object_type']),
                        'name' => (string) $event['row']['label'],
                        'duration' => orbitraLinkChecksDuration(max(0, (int) $event['ended'] - $since)),
                    ]);
                });
            }
        }
        return $sent;
    }

    /** The proxy gets one message of its own — not one per link that uses it. */
    function orbitraLinkChecksNotifyProxy(PDO $pdo, array $proxy, string $kind, int $links, ?callable $broadcast = null): int
    {
        $build = static function (string $lang) use ($proxy, $kind, $links): string {
            if ($kind === 'failed') {
                return orbitraLinkChecksText($lang, 'proxy_failed', ['name' => (string) $proxy['name'], 'count' => $links]);
            }
            return orbitraLinkChecksText($lang, 'proxy_recovered', ['name' => (string) $proxy['name'], 'ip' => (string) $proxy['exit_ip']]);
        };
        return $broadcast !== null ? (int) $broadcast($build)
            : orbitraAutomationBroadcast($pdo, $build, ['flag' => 'notify_link_checks']);
    }

    /**
     * One pass of the worker: re-read the scope, probe the proxies that are
     * due, check as many due links as the budget allows, then tell the owner.
     * The cron file is only a lock and a ping around this. $opts: fetch and
     * broadcast (injected by tests), budget in seconds, batch size.
     */
    function orbitraLinkChecksPass(PDO $pdo, array $opts = []): array
    {
        $fetch = $opts['fetch'] ?? null;
        $broadcast = $opts['broadcast'] ?? null;
        $budget = (float) ($opts['budget'] ?? 40);
        $batch = max(1, (int) ($opts['batch'] ?? 40));
        $startedAt = microtime(true);
        $settings = orbitraLinkChecksSettings($pdo);
        $stats = ['checked' => 0, 'problems' => 0, 'recovered' => 0, 'proxies_probed' => 0, 'added' => 0, 'removed' => 0];

        $lastSync = orbitraLinkChecksSettingTs($pdo, 'link_checks_last_sync_at');
        if ($lastSync === null || time() - $lastSync >= ORBITRA_LINK_CHECKS_SYNC_SECONDS) {
            $sync = orbitraLinkChecksSync($pdo, $settings);
            orbitraLinkChecksSetSetting($pdo, 'link_checks_last_sync_at', orbitraLinkChecksNow());
            $stats['added'] = (int) $sync['added'];
            $stats['removed'] = (int) $sync['removed'];
        }

        foreach ($pdo->query('SELECT * FROM check_proxies WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $proxy) {
            if (microtime(true) - $startedAt >= $budget) {
                break;
            }
            $lastProbe = $proxy['last_checked_at'] ? (strtotime($proxy['last_checked_at'] . ' UTC') ?: 0) : 0;
            if (time() - $lastProbe < ORBITRA_LINK_CHECKS_PROXY_PROBE_SECONDS) {
                continue;
            }
            $probe = orbitraLinkChecksProbeProxy($proxy, $fetch);
            $status = $probe['ok'] ? 'ok' : 'failed';
            $pdo->prepare('UPDATE check_proxies SET status = ?, exit_ip = ?, exit_country = ?, last_error = ?, last_checked_at = ? WHERE id = ?')
                ->execute([$status, $probe['exit_ip'], $probe['exit_country'], $probe['ok'] ? '' : $probe['error'], orbitraLinkChecksNow(), (int) $proxy['id']]);
            $stats['proxies_probed']++;
            // Only a change between two sure states is news; "unknown" is the state before the first probe.
            if (($proxy['status'] === 'ok') !== ($status === 'ok') && $proxy['status'] !== 'unknown') {
                $linksStmt = $pdo->prepare('SELECT COUNT(*) FROM link_checks WHERE proxy_id = ? AND is_enabled = 1');
                $linksStmt->execute([(int) $proxy['id']]);
                $proxy['exit_ip'] = $probe['exit_ip'];
                orbitraLinkChecksNotifyProxy($pdo, $proxy, $status === 'ok' ? 'recovered' : 'failed', (int) $linksStmt->fetchColumn(), $broadcast);
            }
        }

        $due = $pdo->prepare("SELECT lc.*, cp.type AS proxy_type, cp.host AS proxy_host, cp.port AS proxy_port,
                cp.username AS proxy_username, cp.password AS proxy_password
            FROM link_checks lc
            LEFT JOIN check_proxies cp ON cp.id = lc.proxy_id AND cp.is_active = 1
            WHERE lc.is_enabled = 1 AND lc.next_check_at <= ?
            ORDER BY lc.next_check_at ASC LIMIT " . $batch);
        $due->execute([orbitraLinkChecksNow()]);
        $lease = $pdo->prepare('UPDATE link_checks SET next_check_at = ? WHERE id = ?');
        $events = [];
        foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (microtime(true) - $startedAt >= $budget) {
                break;
            }
            // Lease: a worker that dies mid-check does not make the very next
            // tick pick the same link again. A stored result overwrites it.
            $lease->execute([gmdate('Y-m-d H:i:s', time() + 300), (int) $row['id']]);
            $proxy = null;
            if ($row['proxy_host'] !== null) {
                $proxy = ['type' => $row['proxy_type'], 'host' => $row['proxy_host'], 'port' => $row['proxy_port'],
                    'username' => $row['proxy_username'], 'password' => $row['proxy_password']];
            }
            try {
                $run = orbitraLinkChecksRun($row, $settings, $proxy, $fetch);
                $event = orbitraLinkChecksStore($pdo, $row, $run, orbitraLinkChecksNow(), time(), $settings);
            } catch (\Throwable $e) {
                continue;
            }
            $stats['checked']++;
            if ($event !== null) {
                $stats[$event['kind'] === 'recovered' ? 'recovered' : 'problems']++;
                $events[] = $event;
            }
        }

        if ($settings['autopause']) {
            // Only on the owner's say-so: a check that pauses an offer stops its traffic.
            $pause = $pdo->prepare("UPDATE offers SET state = 'paused' WHERE id = ? AND state = 'active'");
            foreach ($events as $index => $event) {
                if ($event['kind'] === 'failed' && $event['row']['object_type'] === 'offer') {
                    $pause->execute([(int) $event['row']['object_id']]);
                    $events[$index]['paused'] = $pause->rowCount() > 0;
                }
            }
        }
        orbitraLinkChecksNotifyLinks($pdo, $events, $broadcast);

        $lastPrune = orbitraLinkChecksSettingTs($pdo, 'link_checks_last_prune_at');
        if ($lastPrune === null || time() - $lastPrune >= 86400) {
            $pdo->prepare("DELETE FROM link_check_logs WHERE id IN
                (SELECT id FROM link_check_logs WHERE started_at < datetime('now', ?) LIMIT 2000)")
                ->execute(['-' . ORBITRA_LINK_CHECKS_LOG_DAYS . ' days']);
            orbitraLinkChecksSetSetting($pdo, 'link_checks_last_prune_at', orbitraLinkChecksNow());
        }
        return $stats;
    }

    /**
     * Book links for the worker's next pass — an admin action, since every
     * check of an offer link is a click in the partner network's statistics.
     * Capped per ten minutes on top of that: a held-down "Check now" must not
     * turn into a flood. Returns ['queued' => int, 'limited' => bool].
     */
    function orbitraLinkChecksQueueNow(PDO $pdo, array $ids, bool $all): array
    {
        $windowAt = orbitraLinkChecksSettingTs($pdo, 'link_checks_manual_window_at');
        $used = 0;
        try {
            $used = (int) $pdo->query("SELECT value FROM settings WHERE key = 'link_checks_manual_used'")->fetchColumn();
        } catch (\Throwable $e) {
        }
        if ($windowAt === null || time() - $windowAt >= 600) {
            $windowAt = time();
            $used = 0;
        }
        if ($used >= 100) {
            return ['queued' => 0, 'limited' => true];
        }
        if ($all) {
            $ids = $pdo->query('SELECT id FROM link_checks WHERE is_enabled = 1')->fetchAll(PDO::FETCH_COLUMN);
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_slice(array_values($clean), 0, 100 - $used);
        if ($clean === []) {
            return ['queued' => 0, 'limited' => false];
        }
        // Spread over half a minute; the worker takes them in its next pass anyway.
        $book = $pdo->prepare('UPDATE link_checks SET next_check_at = ? WHERE is_enabled = 1 AND id IN ('
            . implode(', ', array_fill(0, count($clean), '?')) . ')');
        $times = array_map(static fn (): string => gmdate('Y-m-d H:i:s', time() + random_int(0, 30)), $clean);
        $queued = 0;
        foreach (array_combine($clean, $times) as $id => $when) {
            $book->execute([$when, $id]);
            $queued += $book->rowCount();
        }
        orbitraLinkChecksSetSetting($pdo, 'link_checks_manual_window_at', gmdate('Y-m-d H:i:s', $windowAt));
        orbitraLinkChecksSetSetting($pdo, 'link_checks_manual_used', (string) ($used + $queued));
        return ['queued' => $queued, 'limited' => false];
    }

    /** Is the worker alive, what did its last pass do, and is anything overdue. */
    function orbitraLinkChecksWorkerInfo(PDO $pdo): array
    {
        $lastPing = orbitraLinkChecksSettingTs($pdo, 'link_checks_last_ping_at');
        $lastRun = null;
        $lastError = '';
        try {
            $rows = $pdo->query("SELECT key, value FROM settings WHERE key IN ('link_checks_last_run', 'link_checks_last_error')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $decoded = json_decode((string) ($rows['link_checks_last_run'] ?? ''), true);
            $lastRun = is_array($decoded) ? $decoded : null;
            $lastError = (string) ($rows['link_checks_last_error'] ?? '');
        } catch (\Throwable $e) {
        }
        $overdue = 0;
        try {
            $overdue = (int) $pdo->query("SELECT COUNT(*) FROM link_checks WHERE is_enabled = 1 AND next_check_at < datetime('now', '-10 minutes')")->fetchColumn();
        } catch (\Throwable $e) {
        }
        return [
            'alive' => $lastPing !== null && time() - $lastPing <= 300,
            'last_ping_ts' => $lastPing,
            'last_run' => $lastRun,
            'last_error' => $lastError,
            'overdue' => $overdue,
        ];
    }
}
