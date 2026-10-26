# Архитектура

Orbitra — PHP + SQLite без фреймворка и React-панель. Никаких отдельных серверов БД, очередей и кэшей: всё состояние в одном файле SQLite, фоновые задачи — кроны.

## Стек

| Слой | Что |
|---|---|
| Сервер | Nginx + PHP-FPM (установщик ставит PHP 8.5, при недоступности 8.4; работает от 8.1) |
| База | SQLite 3 в режиме WAL, файл `orbitra_db.sqlite` в корне проекта |
| PHP-зависимости | Composer (версия закреплена в `install.sh` и проверяется по SHA-256): GeoIP2, IP2Location / IP2Proxy |
| Панель | React 19, Vite 7, Tailwind CSS 4, Chart.js, lucide-react, axios |
| MCP | Node.js-сервер в `mcp/` (stdio) и PHP-эндпоинт `mcp.php` (HTTP) |

Собранная панель (`frontend/dist`) лежит в репозитории: сервер не запускает `npm`, сборка делается на машине релиза.

## Как проходит клик

```
посетитель ─► nginx ─► index.php (роутер)
                         ├─ домен: статус, admin_access, HTTPS-only, index-кампания, PWA-привязка
                         ├─ кампания по алиасу (/алиас, /r/алиас) или по токену (click.php, /click_api/v3)
                         ├─ контекст посетителя: гео (Sypex / MaxMind / IP2Location), ASN, IP2Proxy, UA, языки
                         ├─ уникальность и защита от дублей (core/click_logger.php)
                         ├─ выбор потока: перехватывающие → обычные (позиция / вес) → замыкающие
                         │    фильтры — streamMatchesFilters(), логика И/ИЛИ — core/StreamFilters.php
                         │    клоака — core/CloakDetector.php
                         ├─ запись клика (при «database is locked» — в var/spool/clicks.log)
                         └─ ответ: редирект на оффер (core/OfferUrl.php), лендинг, действие, белая страница
```

Те же правила выполняются в `click.php`, Click API v3 (`core/click_api.php`) и симуляторе, поэтому общие части вынесены в `core/`.

## Точки входа

| Файл | Назначение |
|---|---|
| `index.php` | Роутер трекера: ссылки кампаний, постбек `/<key>/postback`, `/pixel.gif`, `/lander/…`, `/offers/…`, `/push_subscribe`, `/crm-ingest`, переход `/?_lp=1`, статика лендингов |
| `router.php` | Роутер для встроенного сервера PHP (локальная разработка) |
| `admin.php` | Оболочка панели |
| `api.php` | REST API панели (~290 действий) — [REST API](api.md) |
| `session_bootstrap.php` | Сессия, срок жизни, HTTPS-детект за прокси |
| `click.php`, `kclient.php` | Click API для интеграционных скриптов и PHP-клиент |
| `postback.php` | Приём конверсий |
| `admin_api.php` | Keitaro-совместимый Admin API (приём расходов) |
| `mcp.php` | MCP Streamable HTTP |
| `telegram_bot.php`, `telegram_notify.php` | Бот и уведомления |
| `config.php` | Подключение к БД, миграции схемы, общие функции |
| `install.sh` | Установщик и обновлятор сервера |

## Фоновые задачи

| Скрипт | Расписание | Ставит | Что делает |
|---|---|---|---|
| `cli/click_spool_cron.php` | каждую минуту | установщик, `server_setup.sh`, панель | Дописывает клики из спула; строит тяжёлые индексы вне веб-запросов |
| `postback_queue_cron.php` | каждую минуту | установщик, панель | Очередь исходящих S2S-постбеков и событий CAPI |
| `cli/push_cron.php` | каждую минуту | установщик | Рассылка Web Push |
| `rotation_optimiser_cron.php` | каждую минуту | установщик | Автооптимизация весов ротации |
| `telegram_poll_cron.php` | каждую минуту | установщик | Опрос Telegram, ежедневная сводка |
| `cli/ssl_installer.php` | каждые 5 минут | установщик | Выпуск и подключение сертификатов |
| `aggregator_cron.php` | каждые 15 минут | установщик | Импорт расходов и доходов |
| `ipranges_cron.php` | ежедневно | установщик | Диапазоны IP облаков и краулеров |
| `cli/geo_update.php` | ежемесячно | установщик | Обновление гео-баз |
| `backorder_cron.php` | каждые 3 минуты | панель (Автоматизация) | Проверка доменов backorder |
| `cli/cleanup_cron.php` | по желанию | вручную | Очистка по срокам хранения |
| `conversion_monitor_cron.php` | по желанию | вручную | Алерт о доле неудачных постбеков |
| `check_sources_cron.php` | по желанию | вручную | Проверка URL источников |

Кроны работают от `www-data`, логи пишут в `var/logs/`.

Очистка записей сессий (срок хранения, лимит места) специально сделана **не** кроном: она запускается из приёма записей не чаще раза в минуту (`orbitraReplayMaybeGc()` в `core/SessionReplay.php`), поэтому существующим установкам не нужна новая строка в crontab.

## Модули `core/`

| Область | Файлы |
|---|---|
| Клик и маршрутизация | `click_logger.php`, `click_api.php`, `StreamFilters.php`, `ClickParams.php`, `ClickFlags.php`, `ClickDestination.php`, `OfferUrl.php`, `Device.php`, `UserAgent.php`, `prefetch.php`, `test_links.php`, `landing_path.php`, `PhpLanding.php`, `RotationOptimiser.php`, `stream_stats.php` |
| Клоака и боты | `CloakDetector.php`, `IpRanges.php`, `CloudDetector.php`, `data/asn_blocklist.json`, `geo_databases.php`, `SxGeo.php` |
| Конверсии | `ConversionAttribution.php`, `PostbackMacros.php`, `PostbackDelivery.php`, `CurrencyRates.php`, `ConversionMonitor.php`, `FacebookConversions.php`, `TikTokConversions.php`, `MetaCapiResponse.php`, `MetaMatching.php` |
| Расходы | `CostImporter.php`, `google_ads_tree.php`, `TikTokMcpClient.php`, `../aggregator_engines/*` |
| Отчёты | `ReportMetrics.php`, `logs_query.php`, `ExtensionStats.php`, `ExtensionAdsStats.php`, `finance_masking.php` |
| Домены и сервер | `ssl_manager.php`, `SslConfig.php`, `nginx_config.php`, `server_ip.php`, `server_setup.php`, `DomainDnsResolver.php`, `CloudflareApi.php`, `NamecheapClient.php`, `DynadotClient.php`, `backorder.php`, `shell.php`, `git_update.php` |
| PWA и пуши | `PwaLanding.php`, `PushBase.php`, `PushQueue.php`, `PushSender.php`, `PushMacros.php` |
| Доступ и безопасность | `resource_access.php`, `admin_access.php`, `admin_path.php`, `ip_access.php`, `Totp.php`, `CrmVault.php`, `db_retry.php` |
| Проверка браузера | `BrowserCheck.php` (оценка сигналов, слой JS-шага клоаки, маяк с лендинга; сборщик `../bc.js`) |
| Записи сессий | `SessionReplay.php` (решение о записи, приём, очистка хранилища, API панели) |
| Прочее | `keitaro_import.php`, `LeadForge.php`, `telegram_api.php`, `branding/` |

## База данных

- Файл `orbitra_db.sqlite`, режим WAL (`ORBITRA_SQLITE_JOURNAL_MODE` может переключить на `DELETE`).
- Схема создаётся и мигрирует в `config.php` при первом запросе. Текущая версия — `$LATEST_SCHEMA_VERSION` (77 на момент v1.8.2), хранится в `PRAGMA user_version`. Миграции пошаговые и идемпотентные.
- `database.sql` — справочный дамп схемы, приложение его не загружает.

Основные таблицы:

| Группа | Таблицы |
|---|---|
| Трекинг | `campaigns`, `streams`, `offers`, `landings`, `clicks`, `conversions`, `campaign_postbacks`, `campaign_pixels`, `stream_rotation_log`, `pwa_screen_views` |
| Справочники | `traffic_sources`, `affiliate_networks`, `conversion_types`, `custom_metrics`, `geo_profiles`, `*_groups` |
| Постбеки | `incoming_postbacks_log`, `s2s_postbacks_log` (очередь), `postback_logs` |
| Расходы и доходы | `aggregator_connections`, `cost_records`, `revenue_records`, `aggregator_sync_logs`, `oauth_tokens`, `pixel_profiles`, `meta_matching_keys`, `ad_entity_status_cache` |
| Домены | `domains`, `cloudflare_accounts`, `namecheap_accounts`, `dynadot_accounts`, `backorder_domains` |
| Пуши и медиа | `push_subscriptions`, `push_messages`, `push_queue`, `push_sends`, `media_folders`, `media_assets`, `app_configs` |
| Боты и клоака | `bot_ips`, `bot_signatures`, `cloak_suppressed_stats` |
| Система | `users`, `user_api_keys`, `settings`, `system_logs`, `audit_logs`, `telegram_bot_chats`, `schema_migrations` |
| LeadForge / CRM | `leadforge_profiles`, `crm_leads` |
| Проверка браузера | `click_browser_checks` (вердикт по проверенному клику) |
| Записи сессий | `session_replays` (строка на запись; события в `var/replays/<день>/<клик>.ndjson.gz`), `replay_clicks` (точки карты кликов), `replay_landing_settings` |

Ключевые индексы на `clicks`: `(campaign_id, created_at)` для отчётов, `(ip, ua_hash, created_at)` для проверок уникальности. Даты в отчётах сравниваются с границами локального дня в UTC, чтобы индекс работал.

## Безопасность сервера

- Веб-пользователь не имеет широкого sudo. Корневые действия идут через помощники с проверкой аргументов: `orbitra-issue-cert`, `orbitra-delete-cert`, `orbitra-install-nginx-conf` (принимает конфиг nginx только из разрешённых директив). Ставит их `cli/server_setup.sh`, версия — `/etc/orbitra/server-setup-version`.
- nginx и `.htaccess` закрывают `cli/`, `core/`, `vendor/`, `var/`, `data/`, файлы SQLite и `.sh/.md/.phar/.lock`. Скрипты `cli/*.php` отказываются работать из веба.
- Заголовки `X-Forwarded-*` учитываются только от Cloudflare и частных прокси.

## Панель

`frontend/src`:

- `App.jsx` — навигация по страницам (без роутера), загрузка сессии и темы;
- `components/` — страницы и редакторы (около 80), общие компоненты в `components/common/` (таблицы, перетаскивание и ширина колонок, MediaPicker и др.);
- `contexts/LanguageContext.jsx` + `locales/*.js` — 7 языков; все строки интерфейса только через `t()`;
- `utils/` — права, цвета конверсий, сниппеты интеграций.

Стили — CSS-переменные тем в `index.css` плюс Tailwind; раскладка (display, ширина, отступы) живёт в базовом слое Tailwind, поэтому классы в разметке работают поверх стилей панели.

## Разработка

```bash
# Бэкенд
php -S localhost:8080 router.php

# Панель с HMR (прокси на :8080 настроен в vite.config.js)
cd frontend && npm install && npm run dev     # http://localhost:5173

# Проверки
cd frontend && npm run check:i18n && npm run lint
php tests/<имя>_test.php                       # тесты — самостоятельные скрипты, судить по коду выхода
bash cli/os_matrix.sh                          # install.sh против всех поддерживаемых Ubuntu/Debian
```

Нагрузочный тест (k6) — `tests/load/README.md`; запускать только на тестовом сервере.

### Выпуск версии

1. Номер версии — одинаковый в `version.json` (с `release_notes` и `released_at`), `version.php` и `frontend/package.json`. Панель обновления читает `version.json`.
2. Раздел `[Unreleased]` в `CHANGELOG.md` переименовывается в версию с датой.
3. Блок «What's New / Что нового» в обоих README.
4. `npm run build` — собранный `frontend/dist` коммитится вместе с релизом. Если менялся `frontend/replay/recorder.js` или версия rrweb — ещё `npm run build:replay`: он пересобирает `/replay.js` в корне репозитория, который тоже коммитится (на серверах нет Node).
5. Если изменились действия API — `php cli/api_catalog.php`, и каталог вставляется в `docs/api.md` (`--check` проверит).
6. Если изменились страницы документации — их же правки в `docs/`. Отдельного списка «что нового» в документации нет: история версий только в `CHANGELOG.md`.

### Документация и вики

Источник документации — папка `docs/`. GitHub-вики собирается из неё скриптом `cli/wiki_sync.py` (инструкция в начале скрипта); править нужно `docs/`, а не вики: при следующей синхронизации ручные правки сгенерированных страниц перезапишутся. Новую страницу добавьте в `docs/`, в `docs/index.md` и в `docs/_Sidebar.md`.
