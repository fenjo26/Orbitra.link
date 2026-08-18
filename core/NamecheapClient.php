<?php
/**
 * NamecheapClient — управление DNS и покупка доменов через Namecheap XML API.
 *
 * Зачем: домены, зарегистрированные в Namecheap, паркуются в трекер без захода
 * в панель регистратора — A-записи на IP сервера прописываются сами, покупка
 * нового домена доступна прямо из раздела Domains (как интеграция Namecheap в
 * Keitaro).
 *
 * Доступ: Profile → Tools → Business & Dev Tools → Namecheap API Access →
 * Manage → Toggle API Access + Whitelisted IPs (исходящий IP сервера).
 *
 * API: https://api.namecheap.com/xml.response (XML, GET-параметры:
 * ApiUser/ApiKey/UserName/ClientIP/Command). Sandbox — api.sandbox.namecheap.com.
 *
 * Исходящий IP сервера Namecheap требует в whitelist. Реальный адрес виден в
 * тексте ошибки неавторизованного вызова — detectIpFromErrors() достаёт его
 * оттуда, чтобы панель показала пользователю точный IP для копирования.
 */

class NamecheapClient
{
    /** @var callable|null|null — подменяется в тестах: function(string $url): array{body:string|false,err:string} */
    public static $http = null;

    /** Список распространённых составных TLD — чтобы promo.my-site.co.uk не разбился на sld=co. */
    private const MULTI_TLDS = [
        'co.uk', 'org.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'net.nz',
        'com.br', 'com.mx', 'co.za', 'co.in', 'net.in', 'com.tr', 'com.ua', 'com.cn',
        'com.tw', 'co.jp', 'com.sg', 'com.hk', 'com.ar', 'com.co', 'com.pe',
    ];

    /**
     * @param array $cfg {api_key, username, client_ip, sandbox}
     * @return array{ok:bool,data:array,errors:string,ip_hint:string}
     */
    public static function request(array $cfg, string $command, array $params = []): array
    {
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        $username = trim((string) ($cfg['username'] ?? ''));
        if ($apiKey === '' || $username === '') {
            return ['ok' => false, 'data' => [], 'errors' => 'Namecheap is not connected', 'ip_hint' => ''];
        }

        $endpoint = !empty($cfg['sandbox'])
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';
        $query = array_merge([
            'ApiUser'  => $username,
            'ApiKey'   => $apiKey,
            'UserName' => $username,
            'ClientIP' => (string) ($cfg['client_ip'] ?? ''),
            'Command'  => $command,
        ], $params);
        $url = $endpoint . '?' . http_build_query($query);

        if (is_callable(self::$http)) {
            /** @var array{body:string|false,err:string} $raw */
            $raw = call_user_func(self::$http, $url);
            $xml = $raw['body'];
            $err = (string) $raw['err'];
        } else {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $xml = curl_exec($ch);
            $err = (string) curl_error($ch);
        }

        if (!is_string($xml) || $xml === '') {
            return ['ok' => false, 'data' => [], 'errors' => 'Connection error to Namecheap: ' . ($err ?: 'empty response'), 'ip_hint' => ''];
        }

        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        if ($parsed === false) {
            return ['ok' => false, 'data' => [], 'errors' => 'Unreadable XML response from Namecheap', 'ip_hint' => ''];
        }

        $status = (string) $parsed['Status'];
        $data = json_decode(json_encode($parsed), true) ?: [];

        if ($status === 'ERROR') {
            $parts = [];
            foreach ((array) ($data['Errors']['Error'] ?? []) as $e) {
                // simplexml→json у элемента с текстом отдаёт только текст
                // (атрибуты теряются) — Number в сообщение не попадает.
                $parts[] = is_array($e) ? (string) ($e[0] ?? implode(' ', $e)) : (string) $e;
            }
            $errors = trim(implode('; ', array_filter($parts, static fn ($p) => trim($p) !== ''))) ?: 'Namecheap API error';
            return ['ok' => false, 'data' => $data, 'errors' => $errors, 'ip_hint' => self::detectIpFromErrors($data['Errors'] ?? [])];
        }

        return ['ok' => true, 'data' => $data, 'errors' => '', 'ip_hint' => ''];
    }

    /** Whitelist-ошибки Namecheap содержат сам IP — показываем его в панели для копирования. */
    public static function detectIpFromErrors($errors): string
    {
        $text = '';
        if (is_array($errors)) {
            $text = json_encode($errors) ?: '';
        } elseif (is_string($errors)) {
            $text = $errors;
        }
        if ($text !== '' && preg_match('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', $text, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Баланс аккаунта: namecheap.users.getBalances (официальная форма,
     * множественное число; старый единственный getBalance Namecheap больше
     * не документирует — но ответ старой формы тоже разбираем).
     * @return array{ok:bool,currency:string,available:?string,account:?string,errors:string,ip_hint:string}
     */
    public static function getBalances(array $cfg): array
    {
        $resp = self::request($cfg, 'namecheap.users.getBalances');
        if (!$resp['ok']) {
            return ['ok' => false, 'currency' => '', 'available' => null, 'account' => null, 'errors' => $resp['errors'], 'ip_hint' => $resp['ip_hint']];
        }
        $cr = $resp['data']['CommandResponse'] ?? [];
        $attr = $cr['UserGetBalancesResult']['@attributes']
            ?? $cr['GetBalancesResult']['@attributes']
            ?? $cr['UserGetBalanceResult']['@attributes']
            ?? [];
        return [
            'ok' => true,
            'currency' => (string) ($attr['Currency'] ?? 'USD'),
            'available' => isset($attr['AvailableBalance']) ? (string) $attr['AvailableBalance'] : (isset($attr['Balance']) ? (string) $attr['Balance'] : null),
            'account' => isset($attr['AccountBalance']) ? (string) $attr['AccountBalance'] : null,
            'errors' => '',
            'ip_hint' => '',
        ];
    }

    /** Лёгкая проверка доступа: namecheap.users.getBalances. @return array{ok:bool,message:string,balance:?string,ip_hint:string} */
    public static function verifyConnection(array $cfg): array
    {
        $resp = self::getBalances($cfg);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => $resp['errors'], 'balance' => null, 'ip_hint' => $resp['ip_hint']];
        }
        $balance = $resp['available'] !== null ? $resp['currency'] . ' ' . $resp['available'] : null;
        return ['ok' => true, 'message' => 'Connected', 'balance' => $balance, 'ip_hint' => ''];
    }

    /** Все домены аккаунта (до 100 на страницу — хватает любому трекеру). @return string[] */
    public static function listDomains(array $cfg): array
    {
        $resp = self::request($cfg, 'namecheap.domains.getList', ['PageSize' => 100]);
        if (!$resp['ok']) {
            return [];
        }
        $domains = $resp['data']['CommandResponse']['DomainGetListResult']['Domain'] ?? [];
        if (isset($domains['@attributes'])) {
            $domains = [$domains];
        }
        $out = [];
        foreach ((array) $domains as $d) {
            $name = (string) ($d['@attributes']['Name'] ?? '');
            if ($name !== '') {
                $out[] = strtolower($name);
            }
        }
        return $out;
    }

    /**
     * Зарегистрированный домен аккаунта, которому принадлежит $host:
     * sub.promo.example.co.uk → example.co.uk. Список аккаунта — источник
     * истины, угадывать составные TLD не нужно. Зона может сама быть поддоменом
     * другой зоны аккаунта (shop.example.com и example.com) — выигрывает самая
     * длинная, она и есть DNS-зона.
     * @return string|null
     */
    public static function findRegisteredDomain(array $cfg, string $host): ?string
    {
        $host = strtolower(trim($host));
        $best = null;
        foreach (self::listDomains($cfg) as $registered) {
            if ($host === $registered || str_ends_with($host, '.' . $registered)) {
                if ($best === null || strlen($registered) > strlen($best)) {
                    $best = $registered;
                }
            }
        }
        return $best;
    }

    /** Address Book — профили для регистрации новых доменов. @return array<int,array{id:string,name:string,is_default:bool}> */
    public static function listAddresses(array $cfg): array
    {
        $resp = self::request($cfg, 'namecheap.users.address.getList');
        if (!$resp['ok']) {
            return [];
        }
        $list = $resp['data']['CommandResponse']['AddressGetListResult']['List'] ?? [];
        if (isset($list['@attributes'])) {
            $list = [$list];
        }
        $out = [];
        foreach ((array) $list as $item) {
            $attr = $item['@attributes'] ?? $item;
            if (!empty($attr['AddressId'])) {
                $out[] = [
                    'id' => (string) $attr['AddressId'],
                    'name' => (string) ($attr['AddressName'] ?? ('Address ' . $attr['AddressId'])),
                    'is_default' => (($attr['IsDefault'] ?? '') === 'true'),
                ];
            }
        }
        return $out;
    }

    /** Проверка доступности. @return array{domain:string,available:bool,is_premium:bool,price:?string} */
    public static function checkDomain(array $cfg, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $resp = self::request($cfg, 'namecheap.domains.check', ['DomainList' => $domain]);
        $result = [];
        if ($resp['ok']) {
            $raw = $resp['data']['CommandResponse']['DomainCheckResult'] ?? [];
            $result = isset($raw['@attributes']) ? $raw['@attributes'] : (is_array($raw) ? $raw : []);
        }
        return [
            'domain' => (string) ($result['Domain'] ?? $domain),
            'available' => (($result['Available'] ?? 'false') === 'true'),
            'is_premium' => (($result['IsPremiumName'] ?? 'false') === 'true'),
            'price' => isset($result['PremiumRegistrationPrice']) ? (string) $result['PremiumRegistrationPrice'] : null,
        ];
    }

    /**
     * Регистрация (покупка) домена. Всем четырём контактным ролям назначается
     * один адрес из Address Book — иначе Namecheap требует полный набор полей
     * адреса в запросе.
     * @return array{ok:bool,message:string,ip_hint:string}
     */
    public static function registerDomain(array $cfg, string $domain, int $years = 1, ?string $addressId = null): array
    {
        $params = [
            'DomainName' => strtolower(trim($domain)),
            'Years' => (string) max(1, min(10, $years)),
            'AddFreeWhoisguard' => 'NO',
            'WGEnabled' => 'NO',
        ];
        if ($addressId !== null && $addressId !== '') {
            $params['RegistrantAddressId'] = $addressId;
            $params['TechAddressId'] = $addressId;
            $params['AdminAddressId'] = $addressId;
            $params['AuxBillingAddressId'] = $addressId;
        }
        $resp = self::request($cfg, 'namecheap.domains.create', $params);
        return ['ok' => $resp['ok'], 'message' => $resp['errors'] ?: 'Registered', 'ip_hint' => $resp['ip_hint']];
    }

    /**
     * Прописывает A-запись $host (относительно зоны $sld.$tld) на $ip, сохраняя
     * остальные записи зоны: setHosts перезаписывает зону целиком, поэтому
     * сначала читаем текущие записи (getHosts) и отправляем их обратно вместе
     * с нашей. Хост '@' паркует и 'www'.
     * @return array{ok:bool,message:string,ip_hint:string}
     */
    public static function setHostRecords(array $cfg, string $sld, string $tld, string $host, string $ip): array
    {
        $sld = strtolower(trim($sld));
        $tld = strtolower(trim($tld));
        $host = strtolower(trim($host));
        if ($sld === '' || $tld === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'message' => 'Invalid domain or server IP', 'ip_hint' => ''];
        }

        $records = [];
        $getResp = self::request($cfg, 'namecheap.domains.dns.getHosts', ['SLD' => $sld, 'TLD' => $tld]);
        if ($getResp['ok']) {
            $rawHosts = $getResp['data']['CommandResponse']['DomainDNSGetHostsResult']['host'] ?? [];
            if (isset($rawHosts['@attributes'])) {
                $rawHosts = [$rawHosts];
            }
            foreach ((array) $rawHosts as $h) {
                $attr = $h['@attributes'] ?? $h;
                $records[] = [
                    'HostName' => (string) ($attr['Name'] ?? ''),
                    'RecordType' => (string) ($attr['Type'] ?? 'A'),
                    'Address' => (string) ($attr['Address'] ?? ''),
                    'TTL' => (string) ($attr['TTL'] ?? '1800'),
                    'MXPref' => (string) ($attr['MXPref'] ?? '10'),
                ];
            }
        } elseif (self::startsWithDomainError($getResp['errors']) === false) {
            // Ошибка не «зона не на наших DNS», а связь/доступ — запись ставить нельзя.
            return ['ok' => false, 'message' => $getResp['errors'], 'ip_hint' => $getResp['ip_hint']];
        }

        $targets = $host === '@' ? ['@', 'www'] : [$host];
        $seen = [];
        foreach ($targets as $target) {
            $seen[$target] = false;
        }
        foreach ($records as &$r) {
            if (isset($seen[$r['HostName']]) && $r['RecordType'] === 'A') {
                $r['Address'] = $ip;
                $seen[$r['HostName']] = true;
            }
        }
        unset($r);
        foreach ($targets as $target) {
            if (!$seen[$target]) {
                $records[] = ['HostName' => $target, 'RecordType' => 'A', 'Address' => $ip, 'TTL' => '300', 'MXPref' => '10'];
            }
        }

        $setParams = ['SLD' => $sld, 'TLD' => $tld];
        foreach (array_values($records) as $idx => $r) {
            $i = $idx + 1;
            $setParams["HostName{$i}"] = $r['HostName'];
            $setParams["RecordType{$i}"] = $r['RecordType'];
            $setParams["Address{$i}"] = $r['Address'];
            $setParams["TTL{$i}"] = $r['TTL'];
            if ($r['RecordType'] === 'MX') {
                $setParams["MXPref{$i}"] = $r['MXPref'];
            }
        }
        // EmailType обязателен, когда в зоне есть MX-записи.
        if (!in_array('MX', array_column($records, 'RecordType'), true)) {
            $setParams['EmailType'] = 'NONE';
        }

        $setResp = self::request($cfg, 'namecheap.domains.dns.setHosts', $setParams);
        $label = implode(', ', $targets);
        return [
            'ok' => $setResp['ok'],
            'message' => $setResp['ok'] ? "A {$label} of {$sld}.{$tld} → {$ip}" : $setResp['errors'],
            'ip_hint' => $setResp['ip_hint'],
        ];
    }

    /** Ошибки вида «DNS 11/12…» — зона обслуживается не Namecheap BasicDNS. */
    private static function startsWithDomainError(string $errors): bool
    {
        return (bool) preg_match('/\bDNS\s*1[0-9]\b/', $errors);
    }

    /**
     * Эвристика host → {root, sub} для случаев, когда список аккаунта
     * недоступен (прямая синхронизация DNS). Составные TLD — по списку выше.
     * @return array{root:string,sub:string}
     */
    public static function parseHost(string $host): array
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = trim((string) preg_replace('~[/?#].*$~', '', $host), '.');
        $labels = array_filter(explode('.', $host), static fn ($l) => $l !== '');
        $n = count($labels);
        if ($n < 2) {
            return ['root' => $host, 'sub' => ''];
        }
        $last2 = $labels[$n - 2] . '.' . $labels[$n - 1];
        $tldLen = in_array($last2, self::MULTI_TLDS, true) && $n >= 3 ? 2 : 1;
        $rootParts = array_slice($labels, $n - 1 - $tldLen);
        $subParts = array_slice($labels, 0, $n - 1 - $tldLen);
        return ['root' => implode('.', $rootParts), 'sub' => implode('.', $subParts)];
    }

    /**
     * Зарегистрированный домен → SLD + TLD для DNS-команд: example.co.uk →
     * ['sld' => 'example', 'tld' => 'co.uk'] (составные TLD — по списку).
     * @return array{sld:string,tld:string}|null
     */
    public static function splitSldTld(string $registered): ?array
    {
        $parsed = self::parseHost($registered);
        $root = $parsed['root'];
        if ($root === '' || strpos($root, '.') === false) {
            return null;
        }
        $last2 = implode('.', array_slice(explode('.', $root), -2));
        if (in_array($last2, self::MULTI_TLDS, true) && substr_count($root, '.') >= 2) {
            $tld = $last2;
            $sld = implode('.', array_slice(explode('.', $root), 0, -2));
        } else {
            $tld = (string) substr($root, strrpos($root, '.') + 1);
            $sld = (string) substr($root, 0, strrpos($root, '.'));
        }
        return ['sld' => $sld, 'tld' => $tld];
    }
}
