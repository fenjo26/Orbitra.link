<?php
// core/ExternalPwa.php
//
// Внешние PWA-конструкторы (этап 4.1): сторонний сервис (PWA.Group, TSL.app)
// как цель потока. Редирект уходит с subid; после установки сервис возвращает
// посетителя на /eflow/<campaign-alias>?subid=<clickId>&eid=<eid> — трекер
// пишет external_install, запоминает eid (исходящий постбэк сервиса получает
// {eid}) и ведёт на оффер по потоку, как «свой» PWA после установки.
//
// Каталог сервисов лежит в data/external_pwa_services.json (как шаблоны
// источников): url_template с {subid}, postback_url_template с {subid}/{eid}/
// {status}/{payout}, status_map внутренний статус → статус сервиса.

class ExternalPwa
{
    /** Каталог data/external_pwa_services.json (кэш на процесс). */
    public static function services(): array
    {
        static $services = null;
        if ($services !== null) {
            return $services;
        }
        $path = __DIR__ . '/../data/external_pwa_services.json';
        $decoded = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $services = [];
        if (is_array($decoded)) {
            foreach ($decoded as $service) {
                if (is_array($service) && !empty($service['key'])) {
                    $services[(string) $service['key']] = $service;
                }
            }
        }
        return $services;
    }

    /**
     * Строка external_pwas → URL, по которому посетитель уходит в сервис
     * (redirect потока). {subid} в url_template заменяется на click id.
     */
    public static function redirectUrl(array $row, string $clickId): string
    {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '') {
            $service = self::services()[(string) ($row['service_key'] ?? '')] ?? [];
            $url = trim((string) ($service['url_template'] ?? ''));
        }
        if ($url === '') {
            return '';
        }
        return str_replace('{subid}', urlencode($clickId), $url);
    }

    /**
     * URL исходящего постбэка для конверсии на клике этого сервиса.
     * {subid}/{eid}/{status}/{payout} подставляются; статус переводится
     * status_map-ом сервиса. Пустой шаблон → постбэка нет.
     */
    public static function postbackUrl(array $row, string $clickId, string $eid, string $internalStatus, float $payout): string
    {
        $template = trim((string) ($row['postback_url'] ?? ''));
        if ($template === '') {
            $service = self::services()[(string) ($row['service_key'] ?? '')] ?? [];
            $template = trim((string) ($service['postback_url_template'] ?? ''));
        }
        if ($template === '') {
            return '';
        }
        $map = [];
        $service = self::services()[(string) ($row['service_key'] ?? '')] ?? [];
        if (!empty($service['status_map']) && is_array($service['status_map'])) {
            $map = $service['status_map'];
        }
        $status = (string) ($map[$internalStatus] ?? $internalStatus);
        return str_replace(
            ['{subid}', '{eid}', '{status}', '{payout}'],
            [urlencode($clickId), urlencode($eid), urlencode($status), urlencode(rtrim(rtrim(number_format($payout, 2, '.', ''), '0'), '.') ?: '0')],
            $template
        );
    }
}
