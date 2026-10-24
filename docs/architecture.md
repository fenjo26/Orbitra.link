# Architecture

Orbitra is PHP + SQLite with no framework, plus a React panel. No separate DB servers, queues or caches: all state lives in a single SQLite file, background tasks are cron jobs.

## Stack

| Layer | What |
|---|---|
| Server | Nginx + PHP-FPM (the installer installs PHP 8.5, falling back to 8.4 if unavailable; runs from 8.1) |
| Database | SQLite 3 in WAL mode, file `orbitra_db.sqlite` in the project root |
| PHP dependencies | Composer (version pinned in `install.sh` and verified by SHA-256): GeoIP2, IP2Location / IP2Proxy |
| Panel | React 19, Vite 7, Tailwind CSS 4, Chart.js, lucide-react, axios |
| MCP | Node.js server in `mcp/` (stdio) and PHP endpoint `mcp.php` (HTTP) |

The built panel (`frontend/dist`) is committed to the repository: the server never runs `npm`, builds happen on the release machine.

## How a click flows

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

The same rules run in `click.php`, Click API v3 (`core/click_api.php`) and the simulator, so the shared parts live in `core/`.

## Entry points

| File | Purpose |
|---|---|
| `index.php` | Tracker router: campaign links, postback `/<key>/postback`, `/pixel.gif`, `/lander/…`, `/offers/…`, `/push_subscribe`, `/crm-ingest`, the `/?_lp=1` hop, landing static files |
| `router.php` | Router for the PHP built-in server (local development) |
| `admin.php` | Panel shell |
| `api.php` | Panel REST API (~290 actions) — [REST API](api.md) |
| `session_bootstrap.php` | Session, lifetime, HTTPS detection behind a proxy |
| `click.php`, `kclient.php` | Click API for integration scripts and the PHP client |
| `postback.php` | Receives conversions |
| `admin_api.php` | Keitaro-compatible Admin API (receives costs) |
| `mcp.php` | MCP Streamable HTTP |
| `telegram_bot.php`, `telegram_notify.php` | Bot and notifications |
| `config.php` | DB connection, schema migrations, shared functions |
| `install.sh` | Server installer and updater |

## Background tasks

| Script | Schedule | Installed by | What it does |
|---|---|---|---|
| `cli/click_spool_cron.php` | every minute | installer, `server_setup.sh`, panel | Flushes clicks from the spool; builds heavy indexes outside web requests |
| `postback_queue_cron.php` | every minute | installer, panel | Queue of outbound S2S postbacks and CAPI events |
| `cli/push_cron.php` | every minute | installer | Web Push delivery |
| `rotation_optimiser_cron.php` | every minute | installer | Auto-optimises rotation weights |
| `telegram_poll_cron.php` | every minute | installer | Telegram polling, daily digest |
| `cli/ssl_installer.php` | every 5 minutes | installer | Certificate issuance and wiring |
| `aggregator_cron.php` | every 15 minutes | installer | Cost and revenue import |
| `ipranges_cron.php` | daily | installer | Cloud and crawler IP ranges |
| `cli/geo_update.php` | monthly | installer | Geo database updates |
| `backorder_cron.php` | every 3 minutes | panel (Automation) | Backorder domain checks |
| `cli/cleanup_cron.php` | optional | manually | Retention-based cleanup |
| `conversion_monitor_cron.php` | optional | manually | Alert on the failed-postback share |
| `check_sources_cron.php` | optional | manually | Checks source URLs |

Crons run as `www-data` and write logs to `var/logs/`.

## `core/` modules

| Area | Files |
|---|---|
| Click and routing | `click_logger.php`, `click_api.php`, `StreamFilters.php`, `ClickParams.php`, `ClickFlags.php`, `ClickDestination.php`, `OfferUrl.php`, `Device.php`, `UserAgent.php`, `prefetch.php`, `test_links.php`, `landing_path.php`, `PhpLanding.php`, `RotationOptimiser.php`, `stream_stats.php` |
| Cloaking and bots | `CloakDetector.php`, `IpRanges.php`, `CloudDetector.php`, `data/asn_blocklist.json`, `geo_databases.php`, `SxGeo.php` |
| Conversions | `ConversionAttribution.php`, `PostbackMacros.php`, `PostbackDelivery.php`, `CurrencyRates.php`, `ConversionMonitor.php`, `FacebookConversions.php`, `TikTokConversions.php`, `MetaCapiResponse.php`, `MetaMatching.php` |
| Costs | `CostImporter.php`, `google_ads_tree.php`, `TikTokMcpClient.php`, `../aggregator_engines/*` |
| Reports | `ReportMetrics.php`, `logs_query.php`, `ExtensionStats.php`, `ExtensionAdsStats.php`, `finance_masking.php` |
| Domains and server | `ssl_manager.php`, `SslConfig.php`, `nginx_config.php`, `server_ip.php`, `server_setup.php`, `DomainDnsResolver.php`, `CloudflareApi.php`, `NamecheapClient.php`, `DynadotClient.php`, `backorder.php`, `shell.php`, `git_update.php` |
| PWA and push | `PwaLanding.php`, `PushBase.php`, `PushQueue.php`, `PushSender.php`, `PushMacros.php` |
| Access and security | `resource_access.php`, `admin_access.php`, `admin_path.php`, `ip_access.php`, `Totp.php`, `CrmVault.php`, `db_retry.php` |
| Misc | `keitaro_import.php`, `LeadForge.php`, `telegram_api.php`, `branding/` |

## Database

- File `orbitra_db.sqlite`, WAL mode (`ORBITRA_SQLITE_JOURNAL_MODE` can switch it to `DELETE`).
- The schema is created and migrated in `config.php` on the first request. Current version — `$LATEST_SCHEMA_VERSION` (75 as of v1.8.0), stored in `PRAGMA user_version`. Migrations are step-by-step and idempotent.
- `database.sql` is a reference dump of the schema; the application never loads it.

Main tables:

| Group | Tables |
|---|---|
| Tracking | `campaigns`, `streams`, `offers`, `landings`, `clicks`, `conversions`, `campaign_postbacks`, `campaign_pixels`, `stream_rotation_log`, `pwa_screen_views` |
| Reference data | `traffic_sources`, `affiliate_networks`, `conversion_types`, `custom_metrics`, `geo_profiles`, `*_groups` |
| Postbacks | `incoming_postbacks_log`, `s2s_postbacks_log` (queue), `postback_logs` |
| Costs and revenue | `aggregator_connections`, `cost_records`, `revenue_records`, `aggregator_sync_logs`, `oauth_tokens`, `pixel_profiles`, `meta_matching_keys`, `ad_entity_status_cache` |
| Domains | `domains`, `cloudflare_accounts`, `namecheap_accounts`, `dynadot_accounts`, `backorder_domains` |
| Push and media | `push_subscriptions`, `push_messages`, `push_queue`, `push_sends`, `media_folders`, `media_assets`, `app_configs` |
| Bots and cloaking | `bot_ips`, `bot_signatures`, `cloak_suppressed_stats` |
| System | `users`, `user_api_keys`, `settings`, `system_logs`, `audit_logs`, `telegram_bot_chats`, `schema_migrations` |
| LeadForge / CRM | `leadforge_profiles`, `crm_leads` |

Key indexes on `clicks`: `(campaign_id, created_at)` for reports, `(ip, ua_hash, created_at)` for uniqueness checks. Dates in reports are compared against local-day boundaries expressed in UTC so the index stays usable.

## Server security

- The web user has no broad sudo. Root actions go through helpers with argument validation: `orbitra-issue-cert`, `orbitra-delete-cert`, `orbitra-install-nginx-conf` (accepts an nginx config built only from allowed directives). They are installed by `cli/server_setup.sh`; the version lives in `/etc/orbitra/server-setup-version`.
- nginx and `.htaccess` block `cli/`, `core/`, `vendor/`, `var/`, `data/`, SQLite files and `.sh/.md/.phar/.lock`. `cli/*.php` scripts refuse to run from the web.
- `X-Forwarded-*` headers are honored only from Cloudflare and private proxies.

## Panel

`frontend/src`:

- `App.jsx` — page navigation (no router), session and theme loading;
- `components/` — pages and editors (about 80), shared components in `components/common/` (tables, drag-and-drop and column widths, MediaPicker, etc.);
- `contexts/LanguageContext.jsx` + `locales/*.js` — 7 languages; all UI strings go exclusively through `t()`;
- `utils/` — permissions, conversion colors, integration snippets.

Styling is theme CSS variables in `index.css` plus Tailwind; layout (display, widths, spacing) lives in Tailwind's base layer, so classes in markup work on top of the panel styles.

## Development

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

Load testing (k6) is described in `tests/load/README.md`; run it only on a test server.

### Release process

1. The version number must be identical in `version.json` (with `release_notes` and `released_at`), `version.php` and `frontend/package.json`. The update panel reads `version.json`.
2. The `[Unreleased]` section in `CHANGELOG.md` is renamed to the version with its date.
3. The "What's New / Что нового" block in both READMEs.
4. `npm run build` — the built `frontend/dist` is committed together with the release.
5. If API actions changed — run `php cli/api_catalog.php`; the catalog is then inserted into `docs/api.md` (`--check` will verify).
6. If documentation pages changed — apply the same edits in `docs/`. There is no separate "what's new" list for documentation: version history lives only in `CHANGELOG.md`.

### Documentation and wiki

The source of truth for documentation is the `docs/` folder. The GitHub wiki is generated from it by `cli/wiki_sync.py` (instructions at the top of the script); edit `docs/`, not the wiki: manual edits to generated pages will be overwritten at the next sync. Add a new page to `docs/`, to `docs/index.md` and to `docs/_Sidebar.md`.
