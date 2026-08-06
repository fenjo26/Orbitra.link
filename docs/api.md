# REST API Эндпоинты

Панель управления Orbitra полностью отделена от бэкенда и общается с ним через единую точку входа — файл `api.php`. Все запросы передают параметр `?action=X`, который маршрутизируется внутри PHP-скрипта.

## Аутентификация

Есть два способа:

- **Сессия + CSRF** (браузер/UI) — вход через `?action=login`, cookie-сессия, для POST требуется заголовок `X-CSRF-TOKEN`.
- **API-ключ** (программный доступ, MCP) — заголовок `Authorization: Bearer <api_key>` или `X-Api-Key: <api_key>`. Ключи создаются в **Пользователи → API-ключи** и имеют область прав: `read` (только GET) или `write` (GET + POST). Для ключей CSRF не требуется. Подробнее — [mcp.md](mcp.md).

## Метрики и статистика
- `GET ?action=metrics` — агрегированные метрики (клики, уники, конверсии, доход) для главного дашборда.
- `GET ?action=chart` — массив данных для построения графика по дням.
- `GET ?action=logs` — последние клики для таблицы 'Recent Clicks'.
- `GET ?action=trends` — агрегированные данные для графиков и таблиц на странице Analytics & Trends.

## Кампании (Campaigns)
- `GET ?action=campaigns` — список всех кампаний с их статистикой.
- `GET ?action=get_campaign&id={ID}` — получение полных данных конкретной кампании, включая все её потоки (Streams).
- `POST ?action=save_campaign` — создание новой или обновление существующей кампании. Ожидает JSON Payload с массивом `streams`.
- `POST ?action=delete_campaign` — мягкое удаление кампании (перенос в архив).
- `GET/POST ?action=campaign_groups` — управление группами кампаний (Categories).
- `GET ?action=campaign_logs&campaign_id={ID}` — детализированный лог кликов для выбранной кампании.
- `POST ?action=clear_campaign_stats` — полная очистка статистики для кампании со сбросом всех счетчиков.

## Офферы (Offers) & Лендинги (Landings)
- `GET ?action=offers` / `GET ?action=landings` — список элементов с базовой статистикой.
- `GET ?action=all_offers` — краткий список активных офферов для выпадающих списков.
- `GET ?action=get_offer&id={ID}` / `GET ?action=get_landing&id={ID}` — получение данных элемента.
- `POST ?action=save_offer` / `POST ?action=save_landing` — создание или обновление.
- `POST ?action=delete_offer` / `POST ?action=delete_landing` — удаление элемента.
- `POST ?action=upload_landing` — загрузка ZIP архива с локальным лендингом.

## Источники и Партнерки (Traffic Sources & Affiliate Networks)
- `GET/POST ?action=traffic_sources` — CRUD операций для источников трафика.
- `GET ?action=traffic_source_templates` — загрузка предустановленных шаблонов (Интеграций) (например: Facebook, TikTok, Google Ads).
- `GET/POST ?action=affiliate_networks` — CRUD операций для партнерских сетей.
- `GET ?action=affiliate_network_templates` — загрузка предустановленных CPA-сетей.

## Конверсии (Conversions)
- `GET ?action=conversions` — полный лог конверсий с поддержкой фильтров, пагинации и поиска.
- `GET ?action=conversion_statuses` — список зарегистрированных статусов (Lead, Sale, Rejected, Trash и т.д.).
- `POST ?action=import_conversions` — ручной импорт конверсий через загрузку файла или вставку Click IDs.

## Глобальные Настройки и Интеграции
- `GET ?action=settings` — получение системных параметров.
- `POST ?action=save_settings` — сохранение системных настроек (тема, валюта, postback keys).
- `GET/POST ?action=profile_settings` — настройки профиля текущего пользователя (Язык интерфейса, Часовой пояс, Первый день недели).
- `GET ?action=geo_profiles` / `GET ?action=countries_list` — справочники для Geo Selector (например: Страны Европы, СНГ).

## S2S Postback Queue (с версии 0.9.6.0)
- `POST ?action=postback_queue_install_user_cron` — устанавливает cron-задачу для воркера доставки постбеков (`postback_queue_cron.php`, запускается каждую минуту). Только admin.
- `POST ?action=postback_queue_remove_user_cron` — удаляет cron-задачу доставки постбеков. Только admin.
- `GET ?action=logs&type=s2s` — лог/очередь исходящих S2S-постбеков со статусами (`pending`, `in_flight`, `delivered`, `failed`), числом попыток, `next_retry_at`, `last_error`, `http_code`.

## Агрегатор расходов (Facebook Ads / Google Ads, с версии 0.9.6.0)
- `GET ?action=aggregator_engine_fields&engine=facebook|google_ads` — поля формы для подключения (возвращаются движком `getRequiredFields()`).
- `POST ?action=aggregator_test_connection` — проверка соединения с рекламным кабинетом (передаётся `engine` + `credentials`).
- `POST ?action=aggregator_sync` — импорт расходов за период и атрибуция к кликам по ad_id/campaign_id. Записывает в `cost_records`, обновляет `clicks.cost`.
- `GET ?action=aggregator_revenue` / `GET ?action=aggregator_sync_logs` — обзор импортированных записей и истории синхронизаций.

## Telegram Bot API 🤖
- `GET ?action=telegram_settings` — проверка статуса бота и получение списка подключенных чатов (`telegram_bot_chats`).
- `POST ?action=save_telegram_settings` — установка или обновление Telegram Token, автоматическая регистрация Webhook'а.
- `POST ?action=telegram_test` — инициирует отправку тестового сообщения в выбранный чат.

## Управление Пользователями и Ролями
- `GET ?action=users` — глобальный список пользователей административной панели.
- `POST ?action=save_user` — создание или обновление существующих доступов.
- `POST ?action=login` — авторизация (`/admin.php`). Возвращает токен сессии (JWT/Cookies).

## Система Архива (Trash/Soft Delete)
- `GET ?action=archive_items` — список всех мягко-удаленных сущностей различных таблиц (офферы, кампании, потоки).
- `POST ?action=archive_restore` — восстановление элемента из корзины.
- `POST ?action=archive_purge` — безвозвратное жесткое удаление из SQLite базы.
