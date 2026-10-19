<?php
/**
 * tests/stage4_funnel_test.php
 *
 * Этап 4 без сети: внешние PWA-конструкторы и Telegram-воронка.
 *
 *  1. ExternalPwa: каталог data/external_pwa_services.json, redirectUrl с
 *     {subid} (свой URL приоритетнее шаблона каталога), postbackUrl со
 *     статусным маппингом и {eid}/{payout};
 *  2. Telegram-оффер: валидация полей (канал/@name/-100…, окно 1-7).
 *     Сама воронка — tests/tg_funnel_test.php;
 *  3. {eid} в макросах исходящих постбэков.
 *
 * Запуск из корня проекта:
 *
 *     php tests/stage4_funnel_test.php
 */

require_once __DIR__ . '/../core/ExternalPwa.php';
require_once __DIR__ . '/../core/PostbackMacros.php';

// orbitraOfferTelegramFields живёт в api.php (file-scope); юниту нужна ровно
// она — вырезаем функцию из исходника до её закрывающей скобки.
{
    $apiSrc = (string) file_get_contents(__DIR__ . '/../api.php');
    $fnStart = strpos($apiSrc, 'function orbitraOfferTelegramFields');
    $braceStart = strpos($apiSrc, '{', $fnStart);
    $depth = 0;
    $fnEnd = $braceStart;
    for ($i = $braceStart, $n = strlen($apiSrc); $i < $n; $i++) {
        if ($apiSrc[$i] === '{') $depth++;
        if ($apiSrc[$i] === '}') { $depth--; if ($depth === 0) { $fnEnd = $i; break; } }
    }
    eval(substr($apiSrc, $fnStart, $fnEnd - $fnStart + 1));
}

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ---- Внешние PWA ------------------------------------------------------------

echo "external PWAs\n";

$services = ExternalPwa::services();
check('the catalog ships with PWA.Group, TSL.app and custom', isset($services['pwa_group'], $services['tsl_app'], $services['custom']));

$row = ['service_key' => 'pwa_group', 'url' => '', 'postback_url' => ''];
check('redirect URL from the catalog template with the click id',
    ExternalPwa::redirectUrl($row, 'abc-123') === 'https://pwa.group/?utm_source=abc-123',
    ExternalPwa::redirectUrl($row, 'abc-123'));

$rowOwn = ['service_key' => 'pwa_group', 'url' => 'https://pwa.group/c/42?s={subid}', 'postback_url' => ''];
check('own URL wins over the catalog template',
    ExternalPwa::redirectUrl($rowOwn, 'klikka') === 'https://pwa.group/c/42?s=klikka');

$pbRow = ['service_key' => 'tsl_app', 'url' => '', 'postback_url' => ''];
$pbUrl = ExternalPwa::postbackUrl($pbRow, 'c1', 'eid-9', 'deposit', 12.50);
check('service postback maps the status and carries eid/payout',
    strpos($pbUrl, 'click_id=c1') !== false && strpos($pbUrl, 'tx=eid-9') !== false
    && strpos($pbUrl, 'event=purchase') !== false && strpos($pbUrl, 'payout=') === false,
    $pbUrl);

$pbReg = ExternalPwa::postbackUrl($pbRow, 'c1', 'e2', 'registration', 0);
check('registration maps to the install event', strpos($pbReg, 'event=install') !== false, $pbReg);

$pbNone = ['service_key' => 'custom', 'url' => '', 'postback_url' => ''];
check('custom service without a postback template sends nothing', ExternalPwa::postbackUrl($pbNone, 'c', 'e', 'sale', 1) === '');

// ---- Оффер-телеграм: валидация полей ----------------------------------------
// Сама воронка (отдельные боты, привязка, заявки, входящий постбек) живёт в
// core/TelegramFunnel.php и покрыта tests/tg_funnel_test.php на мигрированной базе.

echo "telegram offer fields\n";

check('channel id validation: @name ok', orbitraOfferTelegramFields(['tg_channel_id' => '@my_channel'], 'tg_channel_id') === '@my_channel');
check('channel id validation: -100… ok', orbitraOfferTelegramFields(['tg_channel_id' => '-1001234567890'], 'tg_channel_id') === '-1001234567890');
check('channel id validation: garbage dropped', orbitraOfferTelegramFields(['tg_channel_id' => 'join my channel'], 'tg_channel_id') === '');
check('join window clamped to 1..7', orbitraOfferTelegramFields(['tg_window_days' => 30], 'tg_window_days') === 7 && orbitraOfferTelegramFields(['tg_window_days' => 0], 'tg_window_days') === 1);

// ---- {eid} в макросах постбэков ---------------------------------------------------

echo "postback macros\n";

$url = orbitraResolveS2SUrl('https://192.0.2.10/pb?u={subid}&e={eid}&s={status}', [
    'click_id' => 'zz-1', 'eid' => 'EID-7', 'status' => 'sale', 'payout' => 3,
]);
check('{eid} substitutes alongside {subid}/{status}', strpos($url, 'e=EID-7') !== false && strpos($url, 'u=zz-1') !== false, $url);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
