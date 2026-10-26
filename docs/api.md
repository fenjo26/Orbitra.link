# REST API

The panel talks to the backend through a single file — `api.php`. Every request passes `?action=<name>`; the POST body is JSON. The same API is used by the [MCP server](mcp.md), the Ads Manager extension and your own scripts.

## Authorization

**API key** (for scripts). Header `Authorization: Bearer <key>` or `X-Api-Key: <key>`. An admin creates keys in **⚙ → Users → API keys**:

- `read` — read-only: any GET plus a few extension POST actions that only submit long lists of IDs;
- `write` — read and modify.

No CSRF token is needed with a key. A key acts on behalf of its user, so that user's resource permissions apply too.

**Session** (browser). `POST ?action=login` sets a session cookie and returns `csrf_token`; every mutating request (POST / PUT / PATCH / DELETE) must carry the `X-CSRF-TOKEN` header.

```bash
curl -s -H "Authorization: Bearer $ORBITRA_KEY" \
  "https://tracker.example.com/api.php?action=metrics&date_from=2026-10-01&date_to=2026-10-14"

curl -s -X POST -H "Authorization: Bearer $ORBITRA_KEY" -H "Content-Type: application/json" \
  -d '{"items":[{"id":12,"domain_id":3}]}' \
  "https://tracker.example.com/api.php?action=bulk_set_campaign_domain"
```

## Responses and errors

The response is JSON like `{"status": "success", "data": …}` or `{"status": "error", "message": "…"}`.

| Code | When |
|---|---|
| `400` | Invalid parameters (for some actions) |
| `401` | No session or key (for `login` — wrong login or password) |
| `403` | No rights to the resource, read key attempting a write, bad CSRF, IP not in the panel access list |
| `404` | Panel disabled on this domain (domain flag "Admin panel: deny") |
| `429` | Too many failed logins |
| `500` | Unhandled error, text in `message` |
| `503` | Database busy with a background task (`"code": "db_locked"`, `Retry-After` header), nothing was changed — retry in a few seconds |

An unknown action responds with code 200 and `{"status": "error", "message": "Invalid action"}` — check the `status` field, not just the HTTP code.

## Commonly used actions

| Action | Method | What it does |
|---|---|---|
| `metrics`, `chart` | GET | Dashboard summary and chart. `date_from`, `date_to`, `timezone` |
| `campaigns_brief` | GET | Compact campaign list: id, name, alias, domain, link — no stats. Filters `id_from`, `id_to`, `domain_id`, `domain_suffix`, `archived`; `limit` (up to 1000, default 200) and `offset` |
| `campaigns` | GET | Campaigns with full stats (a heavy response on large accounts) |
| `get_campaign` | GET | A campaign with its streams, `id=` |
| `save_campaign` | POST | Create or save a campaign together with its `streams` array. IDs of existing streams are preserved |
| `bulk_set_campaign_domain` | POST | Move campaigns to other domains: `items: [{id, domain_id}]` or `ids` + `domain_id`. Only the domain changes |
| `campaign_report` | GET | Campaign report with groupings |
| `campaign_stream_stats` | GET | Visits, uniques and bots per stream |
| `logs` | GET | Logs: `type` = `traffic` / `postbacks` / `s2s` / `system` / `audit`, `limit` (1–500), `cursor` (from `next_cursor`) or `offset`; for traffic — `campaign_id`, `stream_id`, `route`, `reason`, `date_from` / `date_to` and parameter filters. Response: `data`, `has_more`, `next_cursor` |
| `logs_export` | GET | The same as CSV (`;`, UTF-8 BOM), up to 100,000 rows |
| `click_details` | GET | Click details card |
| `conversions` | GET | Conversion log |
| `offers`, `get_offer`, `save_offer` | GET / POST | Offers |
| `domains_brief`, `save_domain` | GET / POST | Domains |
| `ping` | GET | Liveness check and version, no authorization |

The easiest way to see the field set of a `save_*` action is in the panel: DevTools → Network while saving the object you need.

## Other HTTP endpoints

These are not `api.php` actions but separate tracker URLs:

| URL | Purpose |
|---|---|
| `/<alias>`, `/r/<alias>` | Campaign link |
| `/<postback_key>/postback` | Incoming postback ([Postbacks](postbacks.md)) |
| `/click.php`, `/click_api/v3` | Click API for integration scripts and `kclient.php` ([Tracking Client (PHP)](tracking-client-php.md)) |
| `/?_lp=1` | Landing-to-offer hop ([Landings](landing-pages.md)) |
| `/pixel.gif` | Pixel and beacons (time on landing, `action=matching` for Meta, `action=rr` session-recording batches — [Session recordings](session-recordings.md)) |
| `/lander/<slug>/`, `/offers/<id>/` | Local landings and offers, PWA |
| `/push_subscribe` | Receives Web Push subscriptions |
| `/crm-ingest` | Receives LeadForge leads (optionally with HMAC) |
| `/admin_api/v1/campaigns/<id>/update_costs` | Keitaro-compatible cost intake ([Dolphin / Fbtool](dolphin-fbtool.md)) |
| `/mcp.php` | MCP over HTTP ([MCP](mcp.md)) |
| `/telegram_bot.php` | Telegram webhook |

## Action catalog

Generated from the code: action names come from `api.php`, groups and permissions from the access map in `core/resource_access.php`. Admins can do everything. Refresh it after adding an action:

```bash
php cli/api_catalog.php             # print the catalog
php cli/api_catalog.php --check     # exit 1 when this file is stale
```

<!-- api-catalog:begin — generated by `php cli/api_catalog.php`, do not edit by hand -->

Total actions in `api.php`: **329**.

### Without authorization

`check_setup`, `login`, `logout`, `ping`, `setup_first_user`

### Campaigns, campaign reports, conversions — resource `campaigns`

- **Read:** `ad_entity_statuses`, `campaign_cost_match`, `campaign_logs`, `campaign_pixels`, `campaign_remote_links`, `campaign_report`, `campaign_stream_stats`, `campaigns`, `campaigns_brief`, `campaigns_simple`, `click_details`, `cloak_summary`, `conversion_monitoring`, `conversions`, `get_campaign`, `popup_overview`, `popup_template`, `postback_logs`, `postback_url`, `rotation_status`, `test_postback`
- **Write:** `ad_entity_toggle_status`, `bulk_delete_campaigns`, `bulk_import_campaigns`, `bulk_set_campaign_domain`, `clear_campaign_stats`, `clear_stats`, `copy_campaign`, `delete_campaign`, `delete_campaign_group`, `delete_campaign_pixel`, `import_conversions`, `keitaro_import_sql`, `rename_campaign_group`, `retroactive_remap`, `save_campaign`, `save_campaign_pixel`, `update_costs`
- **GET is read, POST is write:** `campaign_groups`, `groups`

### Offers — resource `offers`

- **Read:** `all_offers`, `get_offer`, `get_offer_file`, `offer_file_content`, `offer_files`, `offer_groups`, `offers`, `offers_simple`, `tg_funnel_bots_brief`
- **Write:** `bulk_delete_offers`, `bulk_import_offers`, `copy_offer`, `delete_offer`, `delete_offer_group`, `offer_create_file`, `offer_delete_file`, `offer_file_op`, `offer_rename_file`, `offer_save_file`, `rename_offer_group`, `save_offer`, `save_offer_file`, `upload_offer`, `upload_offer_file`
- **GET is read, POST is write:** `offer_groups`

### Landings and PWA — resource `landings`

- **Read:** `get_landing`, `get_landing_file`, `landing_files`, `landing_groups`, `landings`, `landings_simple`, `pwa_config_get`, `pwa_preview`, `replay_events`, `replay_heatmap`, `replay_landing_config`, `replay_storage`, `replays`
- **Write:** `bulk_delete_landings`, `bulk_import_landings`, `copy_landing`, `delete_landing`, `delete_landing_group`, `landing_file_op`, `pwa_config_save`, `rename_landing_group`, `replay_delete`, `replay_landing_config_save`, `replay_storage_save`, `save_landing`, `save_landing_file`, `upload_landing`, `upload_landing_file`
- **GET is read, POST is write:** `landing_groups`

### Traffic sources — resource `sources`

- **Read:** `check_all_source_urls`, `check_source_url`, `get_traffic_source`, `traffic_source_templates`
- **Write:** `bulk_delete_traffic_sources`, `bulk_import_sources`
- **GET is read, POST is write:** `traffic_sources`

### Affiliate networks — resource `networks`

- **Read:** `affiliate_network_templates`, `get_affiliate_network`
- **Write:** `bulk_delete_affiliate_networks`, `delete_affiliate_network`
- **GET is read, POST is write:** `affiliate_networks`

### Domains, SSL, registrars, backorder — resource `domains`

- **Read:** `backorder_cron_info`, `backorder_domains`, `check_cloudflare_status`, `check_domain_dns`, `check_ssl_status`, `cloudflare_account_zones`, `cloudflare_accounts_list`, `cloudflare_status`, `domain_groups`, `domains`, `domains_brief`, `dynadot_account_balance`, `dynadot_accounts_list`, `dynadot_check_domain`, `dynadot_domains`, `dynadot_status`, `force_check_all_dns`, `namecheap_account_balance`, `namecheap_accounts_list`, `namecheap_addresses`, `namecheap_check_domain`, `namecheap_domains`, `namecheap_status`, `postback_queue_info`, `ssl_environment`
- **Write:** `backorder_check_batch`, `backorder_check_now`, `backorder_delete`, `backorder_delete_selected`, `backorder_import`, `backorder_install_cron`, `backorder_install_user_cron`, `backorder_remove_cron`, `backorder_remove_user_cron`, `backorder_update`, `cloudflare_account_delete`, `cloudflare_account_import`, `cloudflare_account_repoint`, `cloudflare_account_save`, `cloudflare_options_save`, `cloudflare_save`, `cloudflare_sync_all`, `cloudflare_sync_domain`, `cloudflare_test`, `delete_domain`, `delete_domain_group`, `dynadot_account_delete`, `dynadot_account_save`, `dynadot_register_domain`, `dynadot_sync_domain`, `fix_nginx`, `namecheap_account_delete`, `namecheap_account_save`, `namecheap_register_domain`, `namecheap_save`, `namecheap_sync_domain`, `namecheap_test`, `postback_queue_install_user_cron`, `postback_queue_remove_user_cron`, `regenerate_nginx`, `reissue_ssl`, `rename_domain_group`, `run_ssl_worker`, `save_domain`

### Content Gallery — resource `media`

- **Read:** `media_folders`, `media_list`
- **Write:** `media_folder_op`, `media_op`, `media_upload`

### Web Push — resource `push`

- **Read:** `push_messages`, `push_queue_list`, `push_subscribers`, `push_subscribers_export`, `push_vapid_status`
- **Write:** `push_message_delete`, `push_message_save`, `push_send_now`, `push_subscribers_op`, `push_test_send`, `push_vapid_contact_save`, `push_vapid_generate`

### Logs — resource `logs`

- **Read:** `logs`, `logs_export`, `logs_param_keys`

### Available to any logged-in user

Not tied to a resource; allowed explicitly for non-admins (`orbitraUnmappedAllowedActions()`). Actions marked "GET only" answer 403 on POST for non-admins.

- **GET and POST:** `admin_push_settings_save`, `admin_push_state`, `admin_push_subscribe`, `admin_push_test`, `admin_push_unsubscribe`, `chart`, `check_update`, `cohort`, `extension_ads_stats`, `extension_credentials`, `extension_deep_stats`, `metrics`, `profile_settings`, `save_pixel_profile`, `totp_disable`, `totp_enable`, `totp_setup`, `trends`, `worker_health`
- **GET only:** `conversion_types`, `countries_list`, `global_settings`, `pixel_profiles_list`, `pwa_funnel_stats`

### Archive

`archive_items`, `archive_purge`, `archive_restore` — listing requires read access to all five archive resources; restore and delete require full access to the resource of the item being deleted.

### Cost and revenue aggregator (admin only)

`aggregator_agencies`, `aggregator_agencies_report`, `aggregator_connection_detail`, `aggregator_connections`, `aggregator_engine_fields`, `aggregator_revenue`, `aggregator_revenue_export`, `aggregator_sync`, `aggregator_sync_logs`, `aggregator_test_connection`

### Admin only

Everything not listed above is denied to non-admins by default.

`app_configs`, `attach_pixel_profile`, `bot_ips`, `bot_ips_feed`, `bot_signatures`, `campaign_creatives`, `comment_cleaner_resume`, `comment_cleaner_status`, `comment_log`, `comment_log_action`, `comment_ruleset_test`, `comment_rulesets`, `conversion_statuses`, `creative_detail`, `creative_tag_op`, `creatives_list`, `crm_lead`, `crm_lead_update`, `crm_leads`, `custom_metrics`, `delete_api_key`, `delete_app_config`, `delete_geo_profile`, `delete_pixel_profile`, `delete_traffic_source`, `delete_user`, `external_pwas`, `facebook_capi_list`, `facebook_capi_meta`, `facebook_capi_test`, `facebook_connect_accounts`, `facebook_extend_token`, `facebook_oauth_callback`, `facebook_oauth_start`, `facebook_oauth_status`, `generate_api_key`, `geo_db_update`, `geo_db_upload`, `geo_dbs`, `geo_profiles`, `get_geo_profile`, `get_user`, `google_ads_connect_accounts`, `google_ads_oauth_callback`, `google_ads_oauth_start`, `google_ads_oauth_status`, `init_geo_templates`, `integration_code`, `ipranges_update`, `leadforge_analyze`, `leadforge_build_batch`, `leadforge_download`, `leadforge_forge_landing`, `leadforge_live_qa`, `leadforge_networks`, `leadforge_profiles`, `migrations`, `php_security`, `pixel_profile_test_event`, `postback_backfill`, `postback_test`, `purge_metadata`, `push_macro_preview`, `pwa_domain_options`, `regenerate_campaign_token`, `revenue_attribution_health`, `run_migration`, `run_update`, `save_app_config`, `save_geo_profile`, `save_pwa_domain_binding`, `save_settings`, `save_telegram_settings`, `save_user`, `server_setup_status`, `settings`, `simulate_traffic`, `system_status`, `telegram_chat_flags`, `telegram_chat_remove`, `telegram_link_code`, `telegram_settings`, `telegram_test`, `tg_funnel_bots`, `tg_notify_rules`, `tiktok_connect_accounts`, `tiktok_mcp_connect`, `tiktok_mcp_oauth_callback`, `tiktok_mcp_oauth_start`, `tiktok_oauth_callback`, `tiktok_oauth_start`, `tiktok_oauth_status`, `unmapped_statuses`, `users`, `zone_block`, `zone_blocks`, `zone_candidates`, `zone_rule`, `zone_unblock`

⚠️ — the name exists in the access map but has no handler in `api.php` (a request will answer with an error).

<!-- api-catalog:end -->
