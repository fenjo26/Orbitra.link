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

## Вариант A. Удалённое подключение по URL (с версии 0.9.6.2)

Самый быстрый способ, и единственный, который работает в браузерной версии Claude: трекер сам отдаёт MCP по HTTP через `mcp.php`.

1. **Пользователи → API-ключи**, создайте ключ (Read — только аналитика, Write — ещё и управление).
2. Нажмите рядом с ключом кнопку со ссылкой — в буфер попадёт готовый адрес:

```
https://ваш-трекер.example.com/mcp.php?k=ВАШ_КЛЮЧ
```

3. В Claude: **Settings → Connectors → Add custom connector**, вставьте адрес, задайте имя, «Add».

Ставить ничего не нужно — ни Node, ни репозиторий на своей машине.

> 🔑 **Ключ едет прямо в адресе**, потому что диалог коннектора не имеет поля для ключа и умеет только OAuth. Считайте такой URL паролем: не публикуйте его, а чтобы закрыть доступ — удалите ключ в **Пользователи → API-ключи**, адрес сразу перестанет работать. Read-ключ физически не может ничего изменить.

**Как это устроено.** `mcp.php` реализует MCP Streamable HTTP без сессий: каждый POST — самодостаточный обмен JSON-RPC. Список инструментов он берёт из `mcp/tools.json`, который генерируется из Node-сервера (`node mcp/src/manifest.js`), поэтому два подключения показывают ровно один и тот же набор из 31 инструмента. Вызов инструмента выполняется коротким CLI-процессом `cli/api_invoke.php` — трекер не ходит сам к себе по HTTP, иначе на VPS с двумя PHP-FPM воркерами запрос мог бы заблокировать сайт.

**Требования:** PHP CLI (он и так нужен для крона) и разрешённый `proc_open`. Если `proc_open` отключён в `php.ini`, эндпоинт скажет об этом прямым текстом — тогда используйте вариант B. Путь к PHP можно задать вручную настройкой `mcp_php_binary`.

## Вариант B. Локальный сервер для Claude Desktop

> ⚠️ **Это не диалог «Add custom connector».** Раздел Connectors в Claude принимает только удалённые MCP-серверы по HTTPS и авторизует их через OAuth — поэтому там нет поля для API-ключа. Сервер Orbitra работает по stdio: он запускается локально рядом с ассистентом и прописывается в файле конфигурации ниже. Адрес трекера, вставленный в диалог коннектора, работать не будет.

Открыть файл можно прямо из приложения: **Settings → Developer → Edit Config**.

Добавьте сервер `orbitra` (укажите абсолютный путь к `mcp/src/index.js`):

```json
{
  "mcpServers": {
    "orbitra": {
      "command": "/usr/local/bin/node",
      "args": ["/absolute/path/to/Orbitra/mcp/src/index.js"],
      "env": {
        "ORBITRA_URL": "https://tracker.example.com",
        "ORBITRA_API_KEY": "ваш-api-ключ"
      }
    }
  }
}
```

Полностью перезапустите Claude Desktop (⌘Q на macOS) — инструменты Orbitra появятся в списке. Закрыть окно недостаточно, конфиг читается только при старте.

**Если сервер не появился:**

- **Путь к Node.** В `command` нужен абсолютный путь: приложение стартует не из вашего шелла, поэтому Node из nvm или Homebrew в его `PATH` не попадает и сервер молча не запускается. Узнать путь — `which node` (macOS/Linux) или `where node` (Windows).
- **Зависимости не установлены.** `cd mcp && npm install`.
- **Ключ или URL неверны.** Проверьте отдельно: `ORBITRA_URL=... ORBITRA_API_KEY=... npm run smoke -- --ping`.
- **`403 API key is read-only`** на создание или изменение — нужен Write-ключ, Read-ключ отдаёт только аналитику.

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
