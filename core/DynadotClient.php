<?php
/**
 * DynadotClient — DNS parking, domain import and purchasing through the
 * Dynadot API3 (https://api.dynadot.com/api3.json, key in the query string).
 *
 * Access: Dynadot → Tools → API → generate the API key; if the account has an
 * IP whitelist enabled there, the server's outgoing IP must be on it.
 *
 * Two properties of this API shape the whole client:
 *
 * 1. One call at a time. A regular account may run a single API request at a
 *    time (60/min), and parallel calls get the key banned for 10-15 minutes.
 *    Every request therefore goes through a per-key flock() plus a minimum
 *    spacing between calls — across PHP-FPM workers and cron, not just within
 *    one request.
 *
 * 2. set_dns2 REPLACES the whole DNS configuration of a domain unless
 *    add_dns_to_current_setting=1 is passed, and setting DNS on a domain that
 *    uses its own name servers switches it to Dynadot DNS. setARecord() is
 *    therefore conservative: it refuses domains on external name servers,
 *    appends when nothing conflicts, and rebuilds the zone only when every
 *    existing record is of a type it can carry over faithfully.
 */

class DynadotClient
{
    /** @var callable|null Test transport: function(string $url): array{body:string|false,err:string} */
    public static $http = null;

    /** @var callable|null Test hook replacing the inter-call pause: function(float $seconds): void */
    public static $sleep = null;

    /** Minimum gap between two calls with the same key (Regular level: 60/min). */
    public const MIN_INTERVAL = 1.1;

    /** Record types a zone rebuild can carry over without losing information. */
    private const REBUILD_SAFE_TYPES = ['a', 'aaaa', 'cname', 'txt', 'mx'];

    /**
     * @param array $cfg {api_key, sandbox}
     * @return array{ok:bool,data:array,error:string}
     */
    public static function request(array $cfg, string $command, array $params = [], string $format = 'json'): array
    {
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            return ['ok' => false, 'data' => [], 'error' => 'Dynadot is not connected'];
        }
        $base = !empty($cfg['sandbox']) ? 'https://api-sandbox.dynadot.com/api3' : 'https://api.dynadot.com/api3';
        $url = $base . ($format === 'xml' ? '.xml' : '.json') . '?'
            . http_build_query(array_merge(['key' => $apiKey, 'command' => $command], $params));

        $raw = self::serialized($apiKey, static function () use ($url): array {
            if (is_callable(self::$http)) {
                return call_user_func(self::$http, $url);
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string) curl_error($ch);
            if ($code === 429) {
                $err = 'Dynadot rate limit reached (HTTP 429) — try again in a minute';
                $body = false;
            }
            return ['body' => $body, 'err' => $err];
        });

        $body = $raw['body'] ?? false;
        if (!is_string($body) || trim($body) === '') {
            return ['ok' => false, 'data' => [], 'error' => 'Connection error to Dynadot: ' . (($raw['err'] ?? '') ?: 'empty response')];
        }

        if ($format === 'xml') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            if ($xml === false) {
                return ['ok' => false, 'data' => [], 'error' => 'Unreadable XML response from Dynadot'];
            }
            $data = [$xml->getName() => json_decode(json_encode($xml), true) ?: []];
        } else {
            $data = json_decode($body, true);
            if (!is_array($data)) {
                return ['ok' => false, 'data' => [], 'error' => 'Unreadable response from Dynadot'];
            }
        }

        $error = self::extractError($data);
        if ($error !== null) {
            return ['ok' => false, 'data' => $data, 'error' => $error];
        }
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    /**
     * Runs $call holding the per-key lock and keeping MIN_INTERVAL since the
     * previous call with this key finished (the lock file stores that time).
     */
    private static function serialized(string $apiKey, callable $call): array
    {
        $path = rtrim(sys_get_temp_dir(), '/') . '/orbitra_dynadot_' . substr(hash('sha256', $apiKey), 0, 16) . '.lock';
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return $call(); // no lock available: still better than not calling
        }
        try {
            @flock($fp, LOCK_EX);
            $last = (float) trim((string) stream_get_contents($fp));
            $wait = self::MIN_INTERVAL - (microtime(true) - $last);
            if ($last > 0 && $wait > 0) {
                if (is_callable(self::$sleep)) {
                    call_user_func(self::$sleep, $wait);
                } else {
                    usleep((int) ceil($wait * 1000000));
                }
            }
            $result = $call();
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, sprintf('%.6F', microtime(true)));
            fflush($fp);
            return $result;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * API3 wraps every answer in "<Command>Response" (XML: plus a
     * "<Command>Header"); failures carry Status=error / ResponseCode=-1 and an
     * Error text. Returns the error text, or null on success.
     */
    private static function extractError(array $data): ?string
    {
        $nodes = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && preg_match('/Response$/i', (string) $key)) {
                $nodes[] = $value;
                foreach ($value as $k2 => $v2) {
                    if (is_array($v2) && preg_match('/Header$/i', (string) $k2)) {
                        $nodes[] = $v2;
                    }
                }
            }
        }
        if (empty($nodes)) {
            $nodes[] = $data;
        }
        foreach ($nodes as $node) {
            $status = strtolower((string) self::scalar($node['Status'] ?? ''));
            $code = (string) self::scalar($node['ResponseCode'] ?? ($node['SuccessCode'] ?? ''));
            $error = trim((string) self::scalar($node['Error'] ?? ''));
            if ($status === 'error' || $code === '-1' || ($error !== '' && $status !== 'success')) {
                return $error !== '' ? $error : 'Dynadot API error';
            }
        }
        return null;
    }

    private static function scalar($value)
    {
        return is_array($value) ? (empty($value) ? '' : (string) reset($value)) : $value;
    }

    /** All values stored under $key (case-insensitive) anywhere in $data. */
    private static function findAll($data, string $key): array
    {
        $out = [];
        if (!is_array($data)) {
            return $out;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && strcasecmp($k, $key) === 0) {
                $out[] = $v;
            }
            if (is_array($v)) {
                $out = array_merge($out, self::findAll($v, $key));
            }
        }
        return $out;
    }

    /** One child is an object, several are a list — normalise to a list. */
    private static function asList($value): array
    {
        if (!is_array($value) || empty($value)) {
            return [];
        }
        return array_keys($value) === range(0, count($value) - 1) ? $value : [$value];
    }

    // ─── Account ────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,balance:string,currency:?string,available:?string,error:string}
     */
    /** "€4.73" / "$1,234.50" / "0.00 USD" / "7.25" → [amount, currency|null], or null. */
    private static function parseMoney(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $currency = null;
        foreach (['€' => 'EUR', '$' => 'USD', '£' => 'GBP', '¥' => 'CNY'] as $sym => $code) {
            if (strpos($raw, $sym) !== false) {
                $currency = $code;
                $raw = str_replace($sym, '', $raw);
                break;
            }
        }
        if (preg_match('/\b([A-Z]{3})\b/', strtoupper($raw), $m)) {
            $currency = $currency ?? $m[1];
            $raw = trim(preg_replace('/\b[A-Za-z]{3}\b/', '', $raw));
        }
        $num = str_replace([',', ' '], '', trim($raw));
        if ($num === '' || !is_numeric($num)) {
            return null;
        }
        return [$num, $currency];
    }

    public static function getBalance(array $cfg): array
    {
        $resp = self::request($cfg, 'account_info');
        if (!$resp['ok']) {
            return ['ok' => false, 'balance' => '', 'currency' => null, 'available' => null, 'error' => $resp['error']];
        }
        $currency = null;
        $amount = null;
        foreach (array_merge(self::findAll($resp['data'], 'BalanceList'), self::findAll($resp['data'], 'balance_list')) as $list) {
            $items = [];
            foreach (self::asList($list) as $entry) {
                // XML-shaped JSON nests one more level: BalanceList → Balance → [...]
                if (is_array($entry) && isset($entry['Balance'])) {
                    $items = array_merge($items, self::asList($entry['Balance']));
                } else {
                    $items[] = $entry;
                }
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $c = strtoupper(trim((string) self::scalar($item['Currency'] ?? ($item['currency'] ?? ''))));
                $a = trim((string) self::scalar($item['Amount'] ?? ($item['amount'] ?? '')));
                if ($a === '' || !is_numeric(str_replace(',', '', $a))) {
                    continue;
                }
                if ($currency === null || $c === 'USD') {
                    $currency = $c !== '' ? $c : null;
                    $amount = $a;
                }
            }
        }
        if ($amount === null) {
            // The flat field carries the account currency as a symbol or code
            // ("€0.00", "$12.50", "0.00 USD"). A zero balance comes back with an
            // empty BalanceList, so this is the only place it shows up — the
            // old numeric-only check skipped it and the panel kept a dash.
            foreach (['AccountBalance', 'account_balance', 'Balance'] as $key) {
                foreach (self::findAll($resp['data'], $key) as $value) {
                    $money = self::parseMoney((string) self::scalar($value));
                    if ($money !== null) {
                        [$amount, $c] = $money;
                        if ($currency === null && $c !== null) {
                            $currency = $c;
                        }
                        break 2;
                    }
                }
            }
        }
        $balance = $amount !== null ? trim(($currency ?? '') . ' ' . $amount) : '';
        return ['ok' => true, 'balance' => $balance, 'currency' => $currency, 'available' => $amount, 'error' => ''];
    }

    /** @return array{ok:bool,message:string,balance:string} */
    public static function verifyConnection(array $cfg): array
    {
        $b = self::getBalance($cfg);
        return ['ok' => $b['ok'], 'message' => $b['ok'] ? 'OK' : $b['error'], 'balance' => $b['balance']];
    }

    // ─── Domains ────────────────────────────────────────────────────────

    /**
     * Every domain of the account (lowercase, sorted). list_domain pages are
     * requested until one adds nothing new, so an API build that ignores the
     * paging parameters still terminates after the first repeat.
     * @return array{ok:bool,domains:string[],error:string}
     */
    public static function listDomains(array $cfg): array
    {
        $names = [];
        $perPage = 100;
        for ($page = 1; $page <= 100; $page++) {
            $resp = self::request($cfg, 'list_domain', ['count_per_page' => $perPage, 'page_index' => $page, 'sort' => 'NameAsc']);
            if (!$resp['ok']) {
                if ($page === 1) {
                    return ['ok' => false, 'domains' => [], 'error' => $resp['error']];
                }
                break;
            }
            $before = count($names);
            $found = 0;
            self::collectDomainNames($resp['data'], $names, $found);
            if (count($names) === $before || $found < $perPage) {
                break;
            }
        }
        $list = array_keys($names);
        sort($list);
        return ['ok' => true, 'domains' => $list, 'error' => ''];
    }

    /** Domain objects are the arrays carrying a dotted "Name" plus registration data. */
    private static function collectDomainNames($data, array &$names, int &$found): void
    {
        if (!is_array($data)) {
            return;
        }
        $name = $data['Name'] ?? ($data['name'] ?? ($data['domain_name'] ?? null));
        if (is_string($name) && strpos($name, '.') !== false
            && (isset($data['Expiration']) || isset($data['NameServerSettings']) || isset($data['Registration'])
                || isset($data['expiration']) || isset($data['expiration_date']))) {
            $found++;
            $names[strtolower(trim($name))] = true;
            return;
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                self::collectDomainNames($v, $names, $found);
            }
        }
    }

    /**
     * Availability plus a one-year price when Dynadot quotes one.
     * @return array{domain:string,available:bool,is_premium:bool,price:?string,currency:?string,error:string}
     */
    public static function checkDomain(array $cfg, string $domain, bool $withPricing = true): array
    {
        $domain = strtolower(trim($domain));
        $params = ['domain0' => $domain];
        if ($withPricing) {
            $params['show_price'] = '1';
            $params['currency'] = 'USD';
        }
        $resp = self::request($cfg, 'search', $params);
        $out = ['domain' => $domain, 'available' => false, 'is_premium' => false, 'price' => null, 'currency' => null, 'error' => ''];
        if (!$resp['ok']) {
            $out['error'] = $resp['error'];
            return $out;
        }
        $row = null;
        foreach (self::findAll($resp['data'], 'SearchResults') as $results) {
            foreach (self::asList($results) as $r) {
                if (is_array($r) && isset($r['SearchResult'])) {
                    $r = self::asList($r['SearchResult'])[0] ?? [];
                }
                if (is_array($r) && strcasecmp((string) self::scalar($r['DomainName'] ?? ''), $domain) === 0) {
                    $row = $r;
                    break 2;
                }
            }
        }
        if ($row === null) {
            $out['error'] = 'No search result for ' . $domain;
            return $out;
        }
        $available = strtolower(trim((string) self::scalar($row['Available'] ?? '')));
        $out['available'] = in_array($available, ['yes', 'true', '1'], true);
        $priceText = (string) self::scalar($row['Price'] ?? '');
        $out['is_premium'] = stripos($priceText, 'premium') !== false
            || strtolower((string) self::scalar($row['IsPremium'] ?? ($row['Premium'] ?? ''))) === 'yes';
        if ($out['available'] && $priceText !== '') {
            // "Registration Price: 10.99 in USD and Renewal price: ..." — the first
            // amount is the registration one; a bare number is taken as USD.
            if (preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*(?:in\s*)?([A-Z]{3})/', $priceText, $m)) {
                $out['price'] = str_replace(',', '.', $m[1]);
                $out['currency'] = $m[2];
            } elseif (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*$/', $priceText, $m)) {
                $out['price'] = $m[1];
                $out['currency'] = 'USD';
            }
        }
        return $out;
    }

    /** @return array{ok:bool,message:string} */
    public static function registerDomain(array $cfg, string $domain, int $years = 1, bool $premium = false): array
    {
        $params = ['domain' => strtolower(trim($domain)), 'duration' => max(1, min(10, $years)), 'currency' => 'USD'];
        if ($premium) {
            $params['premium'] = '1';
        }
        $resp = self::request($cfg, 'register', $params);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => $resp['error']];
        }
        return ['ok' => true, 'message' => 'Registered ' . $params['domain'] . ' for ' . $params['duration'] . ' year(s)'];
    }

    // ─── DNS ────────────────────────────────────────────────────────────

    /**
     * Who serves the domain's DNS: 'dynadot_dns' (Dynadot DNS zone),
     * 'fresh' (parking/forwarding/nothing — no zone worth preserving) or
     * 'external' (its own name servers, e.g. Cloudflare).
     * @return array{ok:bool,mode:string,type:string,error:string}
     */
    public static function nameServerMode(array $cfg, string $domain): array
    {
        $resp = self::request($cfg, 'domain_info', ['domain' => strtolower(trim($domain))]);
        if (!$resp['ok']) {
            return ['ok' => false, 'mode' => '', 'type' => '', 'error' => $resp['error']];
        }
        $type = '';
        foreach (self::findAll($resp['data'], 'NameServerSettings') as $ns) {
            if (is_array($ns) && isset($ns['Type'])) {
                $type = trim((string) self::scalar($ns['Type']));
                break;
            }
        }
        return ['ok' => true, 'mode' => self::classifyNameServerType($type), 'type' => $type, 'error' => ''];
    }

    public static function classifyNameServerType(string $type): string
    {
        $t = strtolower(trim($type));
        if ($t === '' || preg_match('/park|forward|stealth|none|default/', $t)) {
            return 'fresh';
        }
        if (strpos($t, 'dynadot') !== false && strpos($t, 'dns') !== false) {
            return 'dynadot_dns';
        }
        return 'external';
    }

    /**
     * Current Dynadot DNS records. Uses the XML flavour: its get_dns layout
     * (GetDnsContent/NameServerSettings/MainDomains|SubDomains) is the one
     * working rebuild scripts rely on.
     * @return array{ok:bool,type:string,ttl:?string,main:array,sub:array,error:string}
     */
    public static function getDns(array $cfg, string $domain): array
    {
        $resp = self::request($cfg, 'get_dns', ['domain' => strtolower(trim($domain))], 'xml');
        $out = ['ok' => false, 'type' => '', 'ttl' => null, 'main' => [], 'sub' => [], 'error' => ''];
        if (!$resp['ok']) {
            $out['error'] = $resp['error'];
            return $out;
        }
        $settings = self::findAll($resp['data'], 'NameServerSettings')[0] ?? null;
        if (!is_array($settings)) {
            $out['error'] = 'Unrecognised get_dns response';
            return $out;
        }
        $out['type'] = trim((string) self::scalar($settings['Type'] ?? ''));
        $ttl = trim((string) self::scalar($settings['TTL'] ?? ''));
        $out['ttl'] = ctype_digit($ttl) ? $ttl : null;
        foreach (self::asList($settings['MainDomains']['MainDomainRecord'] ?? ($settings['MainDomains'] ?? [])) as $r) {
            if (!is_array($r) || !isset($r['RecordType'])) {
                continue;
            }
            $out['main'][] = [
                'type' => strtolower(trim((string) self::scalar($r['RecordType']))),
                'value' => trim((string) self::scalar($r['Value'] ?? '')),
                'value2' => trim((string) self::scalar($r['Value2'] ?? '')),
            ];
        }
        foreach (self::asList($settings['SubDomains']['SubDomainRecord'] ?? ($settings['SubDomains'] ?? [])) as $r) {
            if (!is_array($r) || !isset($r['RecordType'])) {
                continue;
            }
            $out['sub'][] = [
                'host' => strtolower(trim((string) self::scalar($r['Subhost'] ?? ''))),
                'type' => strtolower(trim((string) self::scalar($r['RecordType']))),
                'value' => trim((string) self::scalar($r['Value'] ?? '')),
                'value2' => trim((string) self::scalar($r['Value2'] ?? '')),
            ];
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * Point $sub (relative to the registered $domain; '' = the root, which also
     * parks www) at $ip without destroying anything else in the zone.
     * @return array{ok:bool,message:string}
     */
    public static function setARecord(array $cfg, string $domain, string $sub, string $ip): array
    {
        $domain = strtolower(trim($domain));
        $sub = strtolower(trim($sub, ". \t"));
        if ($domain === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'message' => 'Invalid domain or server IP'];
        }
        $targets = $sub === '' ? ['@', 'www'] : [$sub];
        $label = implode(', ', array_map(static fn ($h) => $h === '@' ? $domain : "$h.$domain", $targets));

        $ns = self::nameServerMode($cfg, $domain);
        if (!$ns['ok']) {
            return ['ok' => false, 'message' => $ns['error']];
        }
        if ($ns['mode'] === 'external') {
            // set_dns2 would silently switch the domain to Dynadot DNS and
            // take it away from the name servers it is actually using.
            return ['ok' => false, 'message' => "$domain uses its own name servers ({$ns['type']}) — its DNS is not managed at Dynadot, so point the A record where that zone lives"];
        }

        if ($ns['mode'] === 'fresh') {
            $params = ['domain' => $domain];
            $si = 0;
            foreach ($targets as $t) {
                if ($t === '@') {
                    $params['main_record_type0'] = 'a';
                    $params['main_record0'] = $ip;
                } else {
                    $params["subdomain{$si}"] = $t;
                    $params["sub_record_type{$si}"] = 'a';
                    $params["sub_record{$si}"] = $ip;
                    $si++;
                }
            }
            $resp = self::request($cfg, 'set_dns2', $params);
            return ['ok' => $resp['ok'], 'message' => $resp['ok'] ? "A {$label} → {$ip}" : $resp['error']];
        }

        // Dynadot DNS: read the zone first.
        $zone = self::getDns($cfg, $domain);
        if (!$zone['ok']) {
            return ['ok' => false, 'message' => $zone['error']];
        }
        $recordsFor = static function (string $host) use ($zone): array {
            return $host === '@' ? $zone['main'] : array_values(array_filter($zone['sub'], static fn ($r) => $r['host'] === $host));
        };
        $missing = [];
        $conflict = false;
        foreach ($targets as $t) {
            $hostRecords = array_values(array_filter($recordsFor($t), static fn ($r) => in_array($r['type'], ['a', 'cname'], true)));
            if (empty($hostRecords)) {
                $missing[] = $t;
                continue;
            }
            foreach ($hostRecords as $r) {
                if ($r['type'] !== 'a' || $r['value'] !== $ip) {
                    $conflict = true;
                }
            }
        }
        if (!$conflict && empty($missing)) {
            return ['ok' => true, 'message' => "A {$label} already → {$ip}"];
        }

        if (!$conflict) {
            // Nothing to replace: append, leaving every existing record untouched.
            $params = ['domain' => $domain, 'add_dns_to_current_setting' => '1'];
            $si = 0;
            foreach ($missing as $t) {
                if ($t === '@') {
                    $params['main_record_type0'] = 'a';
                    $params['main_record0'] = $ip;
                } else {
                    $params["subdomain{$si}"] = $t;
                    $params["sub_record_type{$si}"] = 'a';
                    $params["sub_record{$si}"] = $ip;
                    $si++;
                }
            }
            $resp = self::request($cfg, 'set_dns2', $params);
            return ['ok' => $resp['ok'], 'message' => $resp['ok'] ? "A {$label} → {$ip}" : $resp['error']];
        }

        // A target host points elsewhere: the zone has to be rewritten. Only do
        // it when every record survives the round trip unchanged.
        $unsafe = [];
        foreach (array_merge($zone['main'], $zone['sub']) as $r) {
            if (!in_array($r['type'], self::REBUILD_SAFE_TYPES, true)) {
                $unsafe[$r['type']] = true;
            }
        }
        if (!empty($unsafe)) {
            return ['ok' => false, 'message' => "{$label} already points elsewhere, and the zone has records Orbitra will not rewrite ("
                . implode(', ', array_keys($unsafe)) . ") — change the A record in Dynadot"];
        }

        $main = [];
        foreach ($zone['main'] as $r) {
            if (in_array('@', $targets, true) && in_array($r['type'], ['a', 'cname'], true)) {
                continue;
            }
            $main[] = $r;
        }
        if (in_array('@', $targets, true)) {
            $main[] = ['type' => 'a', 'value' => $ip, 'value2' => ''];
        }
        $subs = [];
        foreach ($zone['sub'] as $r) {
            if (in_array($r['host'], $targets, true) && in_array($r['type'], ['a', 'cname'], true)) {
                continue;
            }
            $subs[] = $r;
        }
        foreach ($targets as $t) {
            if ($t !== '@') {
                $subs[] = ['host' => $t, 'type' => 'a', 'value' => $ip, 'value2' => ''];
            }
        }
        if (count($main) > 20 || count($subs) > 100) {
            return ['ok' => false, 'message' => 'The zone has more records than one Dynadot update can carry — change the A record in Dynadot'];
        }

        $params = ['domain' => $domain];
        if ($zone['ttl'] !== null) {
            $params['ttl'] = $zone['ttl'];
        }
        foreach ($main as $i => $r) {
            $params["main_record_type{$i}"] = $r['type'];
            $params["main_record{$i}"] = $r['value'];
            if ($r['type'] === 'mx') {
                $params["main_recordx{$i}"] = $r['value2'] !== '' ? $r['value2'] : '10';
            }
        }
        foreach ($subs as $i => $r) {
            $params["subdomain{$i}"] = $r['host'];
            $params["sub_record_type{$i}"] = $r['type'];
            $params["sub_record{$i}"] = $r['value'];
            if ($r['type'] === 'mx') {
                $params["sub_recordx{$i}"] = $r['value2'] !== '' ? $r['value2'] : '10';
            }
        }
        $resp = self::request($cfg, 'set_dns2', $params);
        return ['ok' => $resp['ok'], 'message' => $resp['ok'] ? "A {$label} → {$ip} (zone rewritten, other records kept)" : $resp['error']];
    }
}
