<?php
/**
 * CloudflareApi — управление DNS-записями доменов трекера через Cloudflare.
 *
 * Зачем: домен, проксированный Cloudflare (оранжевое облако), получает SSL на
 * краю CF мгновенно — без ожидания certbot и без ручной A-записи. Трекер сам
 * прописывает/обновляет запись домена на текущий IP сервера, а при переносе
 * трекера на новый IP один клик перепарковывает все домены (как интеграция
 * Cloudflare в Keitaro).
 *
 * Авторизация: API Token (My Profile → API Tokens → Create Token → шаблон
 * "Edit zone DNS" + Permission Zone / Zone / Edit + Zone Resources: All zones).
 * Bearer-токен, почта не нужна.
 *
 * API: https://api.cloudflare.com/client/v4 — зоны, DNS-записи, SSL-режим зоны.
 */

class CloudflareApi
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * @return array{ok:bool,result:array|mixed,errors:string}
     */
    public static function request(string $token, string $method, string $path, ?array $json = null, int $timeout = 20): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'result' => null, 'errors' => 'Missing API token'];
        }

        $ch = curl_init(self::BASE . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($json !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
            }
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        // curl_close() is a no-op since PHP 8 and deprecated since 8.5.

        if ($curlErr !== '') {
            return ['ok' => false, 'result' => null, 'errors' => 'HTTP transport error: ' . $curlErr];
        }
        if (!is_string($body)) {
            return ['ok' => false, 'result' => null, 'errors' => 'Empty response from Cloudflare'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'result' => null, 'errors' => "HTTP $code: unreadable response"];
        }

        $ok = !empty($decoded['success']);
        $errors = '';
        if (!$ok) {
            $parts = [];
            foreach ((array) ($decoded['errors'] ?? []) as $err) {
                if (is_array($err)) {
                    $parts[] = trim(($err['code'] ?? '?') . ' ' . ($err['message'] ?? ''));
                } elseif (is_string($err)) {
                    $parts[] = $err;
                }
            }
            $errors = $parts ? implode('; ', $parts) : ("HTTP $code");
        }

        return ['ok' => $ok, 'result' => $decoded['result'] ?? null, 'errors' => $errors];
    }

    /** Проверка токена без побочных эффектов. @return array{ok:bool,message:string} */
    public static function verifyToken(string $token): array
    {
        $resp = self::request($token, 'GET', '/user/tokens/verify');
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => $resp['errors'] ?: 'Invalid token'];
        }
        return ['ok' => true, 'message' => 'Token is valid'];
    }

    /**
     * Зона для хоста: sub.dom.example.com → пробуем sub.dom.example.com,
     * dom.example.com, example.com — зона в CF всегда зарегистрированный домен.
     * @return array|null
     */
    public static function findZoneForHost(string $token, string $host): ?array
    {
        $host = strtolower(trim($host));
        $labels = explode('.', $host);
        $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            if ($count - $i < 2) {
                break; // нужен хотя бы домен+TLD
            }
            $candidate = implode('.', array_slice($labels, $i));
            $resp = self::request($token, 'GET', '/zones?per_page=1&name=' . urlencode($candidate));
            if ($resp['ok'] && is_array($resp['result']) && !empty($resp['result'][0]['id'])) {
                return $resp['result'][0];
            }
        }
        return null;
    }

    /**
     * Приводит DNS-записи хоста к одной A-записи на $ip: существующую первую
     * обновляет, лишние A/AAAA удаляет (Keitaro-семантика «остаются только
     * нужные записи»). CNAME у корня домена тоже заменяется — Cloudflare его
     * не даёт обновить на A, только удалить и создать.
     * @return array{ok:bool,message:string}
     */
    public static function upsertDnsRecord(string $token, array $zone, string $host, string $ip, bool $proxied): array
    {
        $zoneId = (string) ($zone['id'] ?? '');
        $host = strtolower(trim($host));
        if ($zoneId === '' || $host === '') {
            return ['ok' => false, 'message' => 'Zone or host is empty'];
        }

        $list = self::request($token, 'GET', '/zones/' . urlencode($zoneId) . '/dns_records?per_page=100&type=A&name=' . urlencode($host));
        $aRecords = $list['ok'] && is_array($list['result']) ? $list['result'] : [];
        $listAaaa = self::request($token, 'GET', '/zones/' . urlencode($zoneId) . '/dns_records?per_page=100&type=AAAA&name=' . urlencode($host));
        $aaaaRecords = $listAaaa['ok'] && is_array($listAaaa['result']) ? $listAaaa['result'] : [];

        // Лишние записи (A кроме первой, все AAAA) — в мусор: они перебивают
        // нашу запись по round-robin и ломают перепарковку.
        $toDelete = array_slice($aRecords, 1);
        foreach ($aaaaRecords as $rec) {
            $toDelete[] = $rec;
        }
        foreach ($toDelete as $rec) {
            self::request($token, 'DELETE', '/zones/' . urlencode($zoneId) . '/dns_records/' . urlencode((string) ($rec['id'] ?? '')));
        }

        if (!empty($aRecords[0]['id'])) {
            $upd = self::request($token, 'PUT', '/zones/' . urlencode($zoneId) . '/dns_records/' . urlencode((string) $aRecords[0]['id']), [
                'type'    => 'A',
                'name'    => $host,
                'content' => $ip,
                'proxied' => $proxied,
                'ttl'     => 1, // auto — у проксированных записей TTL управляет CF
            ]);
            if ($upd['ok']) {
                return ['ok' => true, 'message' => "A {$host} → {$ip} (updated, proxied=" . ($proxied ? 'on' : 'off') . ')'];
            }
            // Обновление могло упасть из-за несовместимого типа записи — пересоздаём.
            self::request($token, 'DELETE', '/zones/' . urlencode($zoneId) . '/dns_records/' . urlencode((string) $aRecords[0]['id']));
        }

        $create = self::request($token, 'POST', '/zones/' . urlencode($zoneId) . '/dns_records', [
            'type'    => 'A',
            'name'    => $host,
            'content' => $ip,
            'proxied' => $proxied,
            'ttl'     => 1,
        ]);
        if ($create['ok']) {
            return ['ok' => true, 'message' => "A {$host} → {$ip} (created, proxied=" . ($proxied ? 'on' : 'off') . ')'];
        }
        return ['ok' => false, 'message' => $create['errors'] ?: 'DNS record create failed'];
    }

    /** SSL-режим зоны. flexible = SSL сразу (CF сам ходит на 80), full = нужен сертификат на сервере. */
    public static function setSslMode(string $token, string $zoneId, string $mode): array
    {
        if (!in_array($mode, ['flexible', 'full', 'strict', 'off'], true)) {
            $mode = 'flexible';
        }
        $resp = self::request($token, 'PATCH', '/zones/' . urlencode($zoneId) . '/settings/ssl', ['value' => $mode]);
        return ['ok' => $resp['ok'], 'message' => $resp['ok'] ? "SSL mode: {$mode}" : $resp['errors']];
    }

    /** @return array{ok:bool,count:int,message:string} */
    public static function listZones(string $token): array
    {
        $resp = self::request($token, 'GET', '/zones?per_page=50');
        if (!$resp['ok']) {
            return ['ok' => false, 'count' => 0, 'message' => $resp['errors']];
        }
        return ['ok' => true, 'count' => count((array) $resp['result']), 'message' => ''];
    }

    /**
     * Все зоны аккаунта с именами — источник для «Import & Auto-DNS»: зоны
     * отмечаются в диалоге и паркуются в трекер с A-записью. Пагинация —
     * страницами по 50, пока CF не вернёт короткую (последнюю) страницу;
     * страховочный потолок 10 страниц = 500 зон.
     * @return array{ok:bool,zones:array<int,array{id:string,name:string,status:string}>,count:int,message:string}
     */
    public static function listZonesDetailed(string $token): array
    {
        $zones = [];
        $page = 1;
        while ($page <= 10) {
            $resp = self::request($token, 'GET', '/zones?per_page=50&page=' . $page);
            if (!$resp['ok']) {
                return ['ok' => false, 'zones' => [], 'count' => 0, 'message' => $resp['errors']];
            }
            $batch = is_array($resp['result']) ? $resp['result'] : [];
            foreach ($batch as $z) {
                if (!empty($z['name'])) {
                    $zones[] = [
                        'id' => (string) ($z['id'] ?? ''),
                        'name' => strtolower((string) $z['name']),
                        'status' => (string) ($z['status'] ?? ''),
                    ];
                }
            }
            if (count($batch) < 50) {
                break;
            }
            $page++;
        }
        return ['ok' => true, 'zones' => $zones, 'count' => count($zones), 'message' => ''];
    }
}
