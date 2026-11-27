# REST API

Панель общается с бэкендом через один файл — `api.php`. Каждый запрос передаёт `?action=<имя>`; тело POST — JSON. Тот же API используют [MCP-сервер](mcp.md), расширение для Ads Manager и ваши скрипты.

## Авторизация

**API-ключ** (для скриптов). Заголовок `Authorization: Bearer <ключ>` или `X-Api-Key: <ключ>`. Ключи создаёт админ в **⚙ → Пользователи → API-ключи**:

- `read` — только чтение: любые GET и несколько POST-действий расширения, которые лишь передают длинные списки ID;
- `write` — чтение и изменение.

С ключом CSRF-токен не нужен. Ключ действует от имени своего пользователя, поэтому права этого пользователя по ресурсам тоже применяются.

**Сессия** (браузер). `POST ?action=login` ставит cookie сессии и возвращает `csrf_token`; все изменяющие запросы (POST / PUT / PATCH / DELETE) должны нести заголовок `X-CSRF-TOKEN`.

```bash
curl -s -H "Authorization: Bearer $ORBITRA_KEY" \
  "https://tracker.example.com/api.php?action=metrics&date_from=2026-10-01&date_to=2026-10-14"

curl -s -X POST -H "Authorization: Bearer $ORBITRA_KEY" -H "Content-Type: application/json" \
  -d '{"items":[{"id":12,"domain_id":3}]}' \
  "https://tracker.example.com/api.php?action=bulk_set_campaign_domain"
```

## Ответы и ошибки

Ответ — JSON вида `{"status": "success", "data": …}` или `{"status": "error", "message": "…"}`.

| Код | Когда |
|---|---|
| `400` | Неверные параметры (у части действий) |
| `401` | Нет сессии или ключа (у `login` — неверный логин или пароль) |
| `403` | Нет прав на ресурс, read-ключ на запись, неверный CSRF, IP не в списке доступа к панели |
| `404` | Панель на этом домене запрещена (флаг домена «Админ-панель: запретить») |
| `429` | Слишком много неудачных входов |
| `500` | Необработанная ошибка, текст в `message` |
| `503` | База занята фоновой задачей (`"code": "db_locked"`, заголовок `Retry-After`), ничего не изменено — повторите через пару секунд |

Неизвестное действие отвечает кодом 200 с `{"status": "error", "message": "Invalid action"}` — проверяйте поле `status`, а не только HTTP-код.

## Часто используемые действия

| Действие | Метод | Что делает |
|---|---|---|
| `metrics`, `chart` | GET | Сводка и график дашборда. `date_from`, `date_to`, `timezone` |
| `top_countries` | GET | Сводка по странам для блока дашборда **«Страны (Топ 10)»** с теми же фильтрами, что у списков: `date_range`, `custom_from` / `custom_to`, `campaign_id`, `timezone`. Все страны, начиная с самой активной: `country_code`, `clicks`, `unique_clicks`, `conversions`, `cost`, `revenue` и производные метрики |
| `campaigns_brief` | GET | Компактный список кампаний: id, название, алиас, домен, ссылка — без статистики. Фильтры `id_from`, `id_to`, `domain_id`, `domain_suffix`, `archived`; `limit` (до 1000, по умолчанию 200) и `offset` |
| `campaigns` | GET | Кампании со всей статистикой (тяжёлый ответ на больших аккаунтах) |
| `get_campaign` | GET | Кампания с потоками, `id=` |
| `save_campaign` | POST | Создать или сохранить кампанию вместе с массивом `streams`. ID существующих потоков сохраняются |
| `bulk_set_campaign_domain` | POST | Перенести кампании на другие домены: `items: [{id, domain_id}]` или `ids` + `domain_id`. Меняется только домен |
| `campaign_set_stream_weights` | POST | Доля трафика у перечисленных потоков кампании: `id`, `weights: [{stream_id, weight}]`, необязательный `rotation_type`. Неперечисленные потоки сохраняют свою долю |
| `campaign_set_stream_active` | POST | Включить или выключить потоки: `id`, `streams: [{stream_id, is_active}]`. Последний включённый поток не выключается |
| `campaign_set_state` | POST | Запустить или остановить кампанию: `id`, `state: active \| paused`, необязательный `sync_ads` — приостановить или запустить объявления в рекламной сети |
| `stream_set_rotation_weights` | POST | Веса офферов или лендингов внутри одного потока: `campaign_id`, `stream_id`, `offers` / `landings: [{id, weight}]`, необязательный `turn_off_auto` |
| `campaign_report` | GET | Отчёт по кампании с группировками |
| `campaign_stream_stats` | GET | Визиты, уникальные и боты по потокам |
| `logs` | GET | Логи: `type` = `traffic` / `postbacks` / `s2s` / `system` / `audit`, `limit` (1–500), `cursor` (из `next_cursor`) или `offset`; для трафика — `campaign_id`, `stream_id`, `route`, `reason`, `date_from` / `date_to` и фильтры по параметрам. Ответ: `data`, `has_more`, `next_cursor` |
| `logs_export` | GET | То же в CSV (`;`, UTF-8 BOM), до 100 000 строк |
| `click_details` | GET | Карточка клика |
| `conversions` | GET | Лог конверсий. Фильтры: `search`, `status`, `country` (код страны), `campaign_id`, `offer_id`, `landing_id` (сайт), `date_from` / `date_to` (дни в поясе `timezone`); `page`, `per_page` |
| `conversions_countries` | GET | Страны, в которых есть конверсии в рамках доступа пользователя к кампаниям, — варианты фильтра по гео в логе конверсий |
| `text_tests`, `text_test_candidates`, `text_test_save`, `text_test_delete` | GET / POST | Тесты текста A/B для страницы, которую трекер отдаёт сам: локальный лендинг (`landing_id`) или страница локального оффера (`offer_id`) — запрос с обоими id считается запросом по лендингу. `text_test_candidates` — тексты, которые трекер находит на странице; `text_test_save` принимает `original_text` и `variants`, `mode` `auto` / `equal` |
| `offers`, `get_offer`, `save_offer` | GET / POST | Офферы |
| `domains_brief`, `save_domain` | GET / POST | Домены |
| `ping` | GET | Проверка живости и версия, без авторизации |

Набор полей `save_*` проще всего подсмотреть в панели: DevTools → Network при сохранении нужного объекта.

## Другие HTTP-точки

Это не действия `api.php`, а отдельные адреса трекера:

| Адрес | Назначение |
|---|---|
| `/<алиас>`, `/r/<алиас>` | Ссылка кампании |
| `/<postback_key>/postback` | Входящий постбек ([Постбеки](postbacks.md)) |
| `/click.php`, `/click_api/v3` | Click API для интеграционных скриптов и `kclient.php` ([Tracking Client (PHP)](tracking-client-php.md)) |
| `/?_lp=1` | Переход с лендинга на оффер ([Лендинги](landing-pages.md)) |
| `/pixel.gif` | Пиксель и маяки (время на лендинге, `action=matching` для Meta, `action=rr` — пачки записей сессий, [Записи сессий](session-recordings.md), `action=bc` — сигналы проверки браузера, [Проверка браузера](browser-check.md)) |
| `/lander/<slug>/`, `/offers/<id>/` | Локальные лендинги и офферы, PWA |
| `/push_subscribe` | Приём подписок Web Push |
| `/crm-ingest` | Приём лидов LeadForge (опционально с HMAC) |
| `/admin_api/v1/campaigns/<id>/update_costs` | Keitaro-совместимый приём расходов ([Dolphin / Fbtool](dolphin-fbtool.md)) |
| `/mcp.php` | MCP по HTTP ([MCP](mcp.md)) |
| `/telegram_bot.php` | Вебхук Telegram |

## Каталог действий

Сгенерирован из кода: имена действий взяты из `api.php`, группы и права — из карты доступа `core/resource_access.php`. Админу доступно всё. Обновить после добавления действия:

```bash
php cli/api_catalog.php             # напечатать каталог
php cli/api_catalog.php --check     # выход 1, если этот файл устарел
```

<!-- api-catalog:begin — generated by `php cli/api_catalog.php`, do not edit by hand -->

Всего действий в `api.php`: **374**.

### Без авторизации

`check_setup`, `login`, `logout`, `ping`, `setup_first_user`

### Кампании, отчёты по кампаниям, конверсии — ресурс `campaigns`

- **Чтение:** `ad_entity_statuses`, `browser_check_click`, `campaign_cost_match`, `campaign_logs`, `campaign_pixels`, `campaign_remote_links`, `campaign_report`, `campaign_rule_log`, `campaign_rule_preview`, `campaign_rules`, `campaign_stream_stats`, `campaigns`, `campaigns_brief`, `campaigns_simple`, `click_details`, `cloak_summary`, `conversion_monitoring`, `conversions`, `conversions_countries`, `get_campaign`, `popup_overview`, `popup_template`, `postback_logs`, `postback_url`, `rotation_status`, `test_postback`
- **Запись:** `ad_entity_toggle_status`, `bulk_delete_campaigns`, `bulk_import_campaigns`, `bulk_set_campaign_domain`, `campaign_rule_delete`, `campaign_rule_save`, `campaign_set_state`, `campaign_set_stream_active`, `campaign_set_stream_weights`, `clear_campaign_stats`, `clear_stats`, `copy_campaign`, `delete_campaign`, `delete_campaign_group`, `delete_campaign_pixel`, `import_conversions`, `keitaro_import_sql`, `rename_campaign_group`, `retroactive_remap`, `save_campaign`, `save_campaign_pixel`, `stream_set_rotation_weights`, `update_costs`
- **GET — чтение, POST — запись:** `campaign_groups`, `groups`

### Офферы — ресурс `offers`

- **Чтение:** `all_offers`, `get_offer`, `get_offer_file`, `offer_download`, `offer_file_content`, `offer_files`, `offer_groups`, `offers`, `offers_simple`, `tg_funnel_bots_brief`
- **Запись:** `bulk_delete_offers`, `bulk_import_offers`, `copy_offer`, `delete_offer`, `delete_offer_group`, `local_offer_create`, `local_offer_write_file`, `offer_create_file`, `offer_delete_file`, `offer_file_op`, `offer_rename_file`, `offer_save_file`, `rename_offer_group`, `save_offer`, `save_offer_file`, `upload_offer`, `upload_offer_file`
- **GET — чтение, POST — запись:** `offer_groups`

### Лендинги и PWA — ресурс `landings`

- **Чтение:** `get_landing`, `get_landing_file`, `landing_download`, `landing_files`, `landing_groups`, `landings`, `landings_simple`, `pwa_config_get`, `pwa_preview`, `replay_events`, `replay_heatmap`, `replay_landing_config`, `replay_storage`, `replays`, `text_test_candidates`, `text_tests`
- **Запись:** `ai_text_variants`, `bulk_delete_landings`, `bulk_import_landings`, `copy_landing`, `delete_landing`, `delete_landing_group`, `landing_file_op`, `local_landing_create`, `local_landing_write_file`, `pwa_config_save`, `rename_landing_group`, `replay_delete`, `replay_landing_config_save`, `replay_storage_save`, `save_landing`, `save_landing_file`, `text_test_delete`, `text_test_save`, `upload_landing`, `upload_landing_file`
- **GET — чтение, POST — запись:** `landing_groups`

### Источники трафика — ресурс `sources`

- **Чтение:** `check_all_source_urls`, `check_source_url`, `get_traffic_source`, `traffic_source_templates`, `traffic_sources_simple`
- **Запись:** `bulk_delete_traffic_sources`, `bulk_import_sources`
- **GET — чтение, POST — запись:** `traffic_sources`

### Партнёрские сети — ресурс `networks`

- **Чтение:** `affiliate_network_templates`, `get_affiliate_network`
- **Запись:** `bulk_delete_affiliate_networks`, `delete_affiliate_network`
- **GET — чтение, POST — запись:** `affiliate_networks`

### Домены, SSL, регистраторы, бэкордер — ресурс `domains`

- **Чтение:** `backorder_cron_info`, `backorder_domains`, `check_cloudflare_status`, `check_domain_dns`, `check_ssl_status`, `cloudflare_account_zones`, `cloudflare_accounts_list`, `cloudflare_status`, `domain_groups`, `domains`, `domains_brief`, `dynadot_account_balance`, `dynadot_accounts_list`, `dynadot_check_domain`, `dynadot_domains`, `dynadot_status`, `force_check_all_dns`, `namecheap_account_balance`, `namecheap_accounts_list`, `namecheap_addresses`, `namecheap_check_domain`, `namecheap_domains`, `namecheap_status`, `postback_queue_info`, `ssl_environment`
- **Запись:** `backorder_check_batch`, `backorder_check_now`, `backorder_delete`, `backorder_delete_selected`, `backorder_import`, `backorder_install_cron`, `backorder_install_user_cron`, `backorder_remove_cron`, `backorder_remove_user_cron`, `backorder_update`, `cloudflare_account_delete`, `cloudflare_account_import`, `cloudflare_account_repoint`, `cloudflare_account_save`, `cloudflare_options_save`, `cloudflare_save`, `cloudflare_sync_all`, `cloudflare_sync_domain`, `cloudflare_test`, `delete_domain`, `delete_domain_group`, `dynadot_account_delete`, `dynadot_account_save`, `dynadot_register_domain`, `dynadot_sync_domain`, `fix_nginx`, `namecheap_account_delete`, `namecheap_account_save`, `namecheap_register_domain`, `namecheap_save`, `namecheap_sync_domain`, `namecheap_test`, `postback_queue_install_user_cron`, `postback_queue_remove_user_cron`, `regenerate_nginx`, `reissue_ssl`, `rename_domain_group`, `run_ssl_worker`, `save_domain`

### Галерея контента — ресурс `media`

- **Чтение:** `media_folders`, `media_list`
- **Запись:** `media_folder_op`, `media_op`, `media_upload`

### Web Push — ресурс `push`

- **Чтение:** `push_messages`, `push_queue_list`, `push_subscribers`, `push_subscribers_export`, `push_vapid_status`
- **Запись:** `push_message_delete`, `push_message_save`, `push_send_now`, `push_subscribers_op`, `push_test_send`, `push_vapid_contact_save`, `push_vapid_generate`

### Логи — ресурс `logs`

- **Чтение:** `logs`, `logs_export`, `logs_param_keys`

### Доступно любому вошедшему пользователю

Не привязаны к ресурсу; для не-админов разрешены явно (`orbitraUnmappedAllowedActions()`). Действия с пометкой «только GET» на POST для не-админа отвечают 403.

- **GET и POST:** `admin_push_settings_save`, `admin_push_state`, `admin_push_subscribe`, `admin_push_test`, `admin_push_unsubscribe`, `ai_chat_confirm`, `ai_chat_delete`, `ai_chat_send`, `ai_chat_step`, `analytics_compare`, `chart`, `check_update`, `cohort`, `dashboard_chart_prefs`, `extension_ads_stats`, `extension_credentials`, `extension_deep_stats`, `metrics`, `profile_settings`, `save_pixel_profile`, `totp_disable`, `totp_enable`, `totp_setup`, `trends`, `worker_health`
- **Только GET:** `ai_chat`, `ai_chats`, `ai_greeting`, `ai_status`, `conversion_types`, `countries_list`, `creatives_simple`, `global_settings`, `pixel_profiles_list`, `pwa_funnel_stats`, `top_countries`

### Архив

`archive_items`, `archive_purge`, `archive_restore` — список требует доступа на чтение ко всем пяти ресурсам архива, восстановление и удаление — полного доступа к ресурсу удаляемого элемента.

### Агрегатор расходов и доходов (только администратор)

`aggregator_agencies`, `aggregator_agencies_report`, `aggregator_connection_detail`, `aggregator_connections`, `aggregator_engine_fields`, `aggregator_revenue`, `aggregator_revenue_export`, `aggregator_sync`, `aggregator_sync_logs`, `aggregator_test_connection`

### Только администратор

Всё, что не перечислено выше, для не-админов закрыто по умолчанию.

`ai_models`, `ai_settings`, `ai_test`, `app_configs`, `attach_pixel_profile`, `binom_import`, `binom_test_connection`, `bot_ips`, `bot_ips_feed`, `bot_signatures`, `browser_check_settings`, `campaign_creatives`, `comment_cleaner_resume`, `comment_cleaner_status`, `comment_log`, `comment_log_action`, `comment_ruleset_test`, `comment_rulesets`, `conversion_statuses`, `convert_incremental_vacuum`, `creative_detail`, `creative_tag_op`, `creatives_list`, `crm_lead`, `crm_lead_evidence`, `crm_lead_update`, `crm_leads`, `custom_metrics`, `delete_api_key`, `delete_app_config`, `delete_geo_profile`, `delete_pixel_profile`, `delete_traffic_source`, `delete_user`, `external_pwas`, `facebook_capi_list`, `facebook_capi_meta`, `facebook_capi_test`, `facebook_connect_accounts`, `facebook_extend_token`, `facebook_oauth_callback`, `facebook_oauth_start`, `facebook_oauth_status`, `generate_api_key`, `geo_db_update`, `geo_db_upload`, `geo_dbs`, `geo_profiles`, `get_geo_profile`, `get_user`, `google_ads_connect_accounts`, `google_ads_oauth_callback`, `google_ads_oauth_start`, `google_ads_oauth_status`, `init_geo_templates`, `integration_code`, `ipranges_update`, `leadforge_analyze`, `leadforge_build_batch`, `leadforge_download`, `leadforge_forge_landing`, `leadforge_live_qa`, `leadforge_networks`, `leadforge_profiles`, `migrations`, `pixel_profile_test_event`, `postback_backfill`, `postback_test`, `purge_metadata`, `push_macro_preview`, `pwa_domain_options`, `regenerate_campaign_token`, `replay_campaign_config`, `replay_campaign_config_save`, `revenue_attribution_health`, `rotate_postback_key`, `run_migration`, `run_update`, `save_app_config`, `save_geo_profile`, `save_pwa_domain_binding`, `save_settings`, `save_telegram_settings`, `save_user`, `server_setup_status`, `settings`, `simulate_traffic`, `system_status`, `telegram_chat_flags`, `telegram_chat_remove`, `telegram_link_code`, `telegram_settings`, `telegram_test`, `tg_funnel_bots`, `tg_notify_rules`, `tiktok_connect_accounts`, `tiktok_mcp_connect`, `tiktok_mcp_oauth_callback`, `tiktok_mcp_oauth_start`, `tiktok_oauth_callback`, `tiktok_oauth_start`, `tiktok_oauth_status`, `unmapped_statuses`, `users`, `zone_block`, `zone_blocks`, `zone_candidates`, `zone_rule`, `zone_unblock`

⚠️ — имя есть в карте доступа, но обработчика в `api.php` нет (запрос ответит ошибкой).

<!-- api-catalog:end -->
