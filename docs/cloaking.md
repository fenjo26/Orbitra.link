# Клокинг: списки ботов и IP-диапазоны

Клокер (схема потока «Cloaking» в кампании) решает несколько слоёв: IP2Proxy,
ASN/провайдер, User-Agent, отсутствие заголовков браузера. С этой версии два
слоя усилены.

## User-Agent ботов

Список сигнатур дополнен полевым списком скреперов/превью-краулеров:
`facebookexternalhit`, `facebot`, `facebookcatalog`, `meta-externalagent`,
`meta-externalfetcher`, `zgrab`, `checkmarknetwork`, `google-inspectiontool`,
`googleother`, `bingpreview`, `kakaotalk-scrap`, `bandscraper`, `goscraper`,
`httpx`, `recon` и др. (полный список — `core/CloakDetector.php`,
`classifyUa()`). Любое совпадение — жёсткий сигнал `crawler_or_tool_ua`.

## IP-диапазоны датацентров (lord-alfred/ipranges)

Клокер сверяет IP посетителя с all-in-one списками диапазонов облаков и
краулеров — AWS, Google Cloud и GoogleBot, Bing, Microsoft/Azure, Oracle,
DigitalOcean, GitHub, Facebook, Twitter, Linode, Telegram, OpenAI (GPTBot),
Cloudflare, Vultr, Apple Private Relay, ProtonVPN и др. Источник обновляется
ежедневно: https://github.com/lord-alfred/ipranges

- **Обновление**: крон `ipranges_cron.php` (инсталлятор ставит его на daily;
  вручную — `php ipranges_cron.php --force`). Из панели —
  `api.php?action=ipranges_update`.
- **Хранение**: `var/ipranges/` (в git не попадает). Файлы старше суток
  считаются устаревшими.
- **Матчинг**: бинарный поиск по отсортированным диапазонам, IPv4 и IPv6.
  Проверяется ~20 000 диапазонов быстрее миллисекунды.
- **Поведение**: совпадение даёт причину `iprange_datacenter` (учитывается
  слоем «Datacenter / hosting» в настройках клокинга). Пока списки не
  скачаны — слой просто неактивен, ничего не ломается.

Пример: посетитель с безупречным браузерным UA с IP 8.8.8.8 (Google) будет
помечен `iprange_datacenter` и уведён в safe-страницу.

## Действия потока (схема «Действие»)

- **Ничего не делать** — страница отдаётся как есть (боты на KClient-сайте).
- **Показать 404**.
- **Показать текст** — произвольный текст; пустое поле = пустая белая страница.
- **Показать HTML** — произвольный HTML.
- **Отправить в кампанию** — посетитель передаётся другой кампании трекера
  (клик пишется в обе; классика клокинга — перелив ботов в отдельную воронку).

Формат хранения в потоке — `тип` или `тип:полезная нагрузка`
(HTML/текст/id кампании), совместим с прежними данными.

---

*Добавлено после v0.9.7.7.*
