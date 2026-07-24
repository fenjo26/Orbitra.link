# MCP-сервер: работа с трекером через нейросеть

Начиная с версии **0.9.5.0** к Orbitra можно подключить ИИ-ассистента (Claude Desktop и другие
MCP-клиенты) через **MCP-сервер** — он лежит в папке [`mcp/`](../mcp/README.md). После подключения
трекером можно управлять на обычном языке:

> «Как отработали кампании за последние 7 дней?»
> «Создай 10 кампаний под оффер №4 — по одной на GEO: US, CA, GB, DE, FR.»
> «Добавь домен track.example.com и повесь на его корень кампанию 12.»

Сервер общается с вашим трекером по HTTPS с помощью персонального **API-ключа**. Ничего не хранит
на своей стороне, а ключи с правом только чтения физически не могут изменять данные.

## Как это устроено

```
Claude Desktop  ──stdio──►  mcp/src/index.js  ──HTTPS + API key──►  ваш Orbitra (api.php)
```

MCP-сервер — это тонкий клиент к обычному API трекера (`api.php`). Он лишь удобно оборачивает
действия API в инструменты, понятные нейросети, и добавляет защиту (read-ключи не могут писать).

## Шаг 1. Получите API-ключ

В интерфейсе: **Пользователи → ваш пользователь → API-ключи**.

- **Read key** — только аналитика (метрики, списки, отчёты).
- **Write key** — плюс управление (создание/редактирование/удаление кампаний, офферов, доменов…).

Ключ показывается один раз — скопируйте его.

## Шаг 2. Установите зависимости

При установке через `install.sh` зависимости MCP ставятся автоматически. Вручную:

```bash
cd mcp
npm install
```

Самопроверка (поднимает сервер и печатает список инструментов, живой трекер не нужен):

```bash
npm run smoke
```

Проверка против живого трекера:

```bash
ORBITRA_URL=https://tracker.example.com ORBITRA_API_KEY=ваш_ключ npm run smoke -- --ping
```

## Шаг 3. Подключите Claude Desktop

Файл настроек Claude Desktop:

- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

Добавьте сервер `orbitra` (укажите абсолютный путь к `mcp/src/index.js`):

```json
{
  "mcpServers": {
    "orbitra": {
      "command": "node",
      "args": ["/absolute/path/to/Orbitra/mcp/src/index.js"],
      "env": {
        "ORBITRA_URL": "https://tracker.example.com",
        "ORBITRA_API_KEY": "ваш-api-ключ"
      }
    }
  }
}
```

Перезапустите Claude Desktop — инструменты Orbitra появятся в списке.

## Инструменты (31)

**Чтение:** `orbitra_ping`, `orbitra_get_metrics`, `orbitra_get_chart`, `orbitra_list_campaigns`,
`orbitra_get_campaign`, `orbitra_campaign_report`, `orbitra_list_offers`, `orbitra_get_offer`,
`orbitra_all_offers`, `orbitra_list_domains`, `orbitra_list_traffic_sources`,
`orbitra_list_landings`, `orbitra_list_affiliate_networks`, `orbitra_list_conversions`,
`orbitra_recent_clicks`, `orbitra_system_status`.

**Управление (нужен write-ключ):** `orbitra_create_campaign`, `orbitra_update_campaign`,
`orbitra_bulk_create_campaigns`, `orbitra_delete_campaign`, `orbitra_copy_campaign`,
`orbitra_create_offer`, `orbitra_update_offer`, `orbitra_delete_offer`, `orbitra_create_domain`,
`orbitra_delete_domain`, `orbitra_check_domain_dns`, `orbitra_create_traffic_source`,
`orbitra_list_traffic_source_templates`, `orbitra_create_landing`.

**Продвинутое:** `orbitra_api_request` — прямой вызов любого действия `api.php` (запасной вариант).

Полный справочник по параметрам — в [mcp/README.md](../mcp/README.md).

## Безопасность

- Относитесь к API-ключу как к паролю. По умолчанию используйте **read**-ключ; **write** — только
  когда нужно управление.
- Ключ уходит только на **ваш** `ORBITRA_URL` (заголовки `Authorization: Bearer` / `X-Api-Key`).
- Действия записи проходят через штатный API и попадают в аудит-логи, как обычные операции.
- Read-ключ при попытке записи получает ответ `403` и ничего не меняет.
- `ORBITRA_INSECURE=1` отключает проверку TLS — только для локальной разработки.

## Обновление

MCP поставляется вместе с проектом, поэтому штатное обновление (**Настройки → Обновление** или
`git pull`) подтягивает новые файлы автоматически. После обновления, изменившего зависимости,
выполните `cd mcp && npm install`.
