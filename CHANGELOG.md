# Changelog

All notable changes to **Orbitra Tracker** are listed here. The full release
notes for each version also live in [README.md](README.md) (English) and
[README.ru.md](README.ru.md) (Russian) under the *What's New* / *Что нового*
sections.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.5] — 2026-08-19

SSL management rebuilt around per-domain verification and custom certificates, IP access control reworked with SUBID extraction in the traffic log, and a shared bulk-file-upload component across the panel.

### Added
- 🔐 **SSL management and verification (ORB-014)** — `core/ssl_manager.php` verifies certificate coverage per domain, the generated nginx vhost gains an HTTPS server block with a self-signed certificate for domains that are neither Let's Encrypt nor behind Cloudflare, and the Cloudflare Full (Strict) setup is documented in `docs/CLOUDFLARE_FULL_STRICT_SSL.md`. A 279-line regression test (`tests/nginx_config_regression_test.php`) locks the generated vhost shape, including server-block parsing and listen matching. **An existing nginx server needs `sudo php /var/www/orbitra/cli/nginx_sync.php` once** for the new vhost blocks.
- 📜 **Custom SSL certificates per domain** — upload your own certificate and key in domain settings (Domains UI, all 7 locales).
- 🧠 **SUBID extraction in the traffic log** — SUBID values are read from the click's parameters on the click path and in LeadForge; `tests/traffic_log_subid_test.php` and `tests/verify_traffic_subid.php` cover missing keys, malformed JSON and NULL parameters.
- 📤 **Bulk file upload** — a shared `BulkFileUpload` component with CSV parsing and per-file error handling, wired into Bot Settings, Branding, Campaigns, Landings, Offers and Traffic Sources, with matching `api.php` endpoints and a `cli/check_landings.php` inspector.

### Changed
- 🛡️ **IP access control reworked** (`core/ip_access.php`) alongside the traffic-log SUBID work.
- 📦 **Frontend bundle rebuilt** — the SSL and bulk-upload interfaces had been committed without a `dist` rebuild; this release ships them.

## [1.1.4] — 2026-08-19

Generated integration snippets spoke Russian to every user, regardless of the panel language.

### Fixed
- 🔤 **Tracking snippets now generate with English comments** — the code blocks in the campaign editor's Connection method (KClient JS/PHP, tracking script, banner blocks, click and conversion pixels, countdown / back-button / exit-intent widgets, WordPress shortcodes) carried Russian how-to comments hardcoded in the snippet templates, so an English- or German-language panel still produced pasteable code with Russian instructions. All template comments are now English, translated in place — deliberately not behind locale keys, because this is code the user pastes into their own site, not panel UI. The one remaining Russian string is intentional: `text_ru="Купить"` in the WordPress shortcode example demonstrates the multilingual parameter itself.

## [1.1.3] — 2026-08-19

Eight palette themes for the panel, hardcoded grays retired in favour of theme variables, and the repair of a frontend bundle that had shipped broken.

### Added
- 🎨 **Eight palette themes** — four color schemes, each in a light and a dark variant, selectable in Personalization: **Cobalt** (signal blue on white / on pure black), **Canary** (black CTAs on white; canary yellow on charcoal), **Parchment** (warm cream canvas with ink CTAs; ink canvas with cream surfaces), **Indigo** (violet-blue on a light canvas / on deep indigo). The palettes are ported from published brand design analyses under neutral color names — no brand names, marks, or fonts ship with them; the analyses themselves list Inter (already the app's stack) as the sanctioned substitute for their proprietary faces.

### Fixed
- 🌗 **Hardcoded grays retired** — leftover `bg-white` / `gray-*` Tailwind colors in Automation Settings, Admin Status and the campaign Tracking tab now use theme variables (`var(--color-bg-card)`, `var(--color-border)`, `var(--color-text-muted)`, …), so every theme — including the new dark palettes — paints those surfaces correctly instead of flashing light grays. The Tracking tab got more compact along the way: the options panel only renders for methods that have options, and the generated-code block went full-width with a compact copy button in its header.
- ➕ **A doubled "+ +" on the Add Cost Connection button** — the label string itself carried a literal `+` next to the `<Plus />` icon, in every locale.
- 🧱 **The Conversion Types page shipped broken since 1.1.2 — silently** — commit `7483d75` placed sibling JSX inside the page's `{!showForm ? (…) : (…)}` ternary without a fragment, so `ConversionTypesSettings.jsx` stopped compiling from that moment. Nothing flagged it because the shipped `frontend/dist` bundle predated the breakage: the 1.1.2 notes advertised the unmapped-statuses section, but the bundle users actually downloaded never contained it (0 references to its strings in the 1.1.2 `index.js`). The ternary's true branch is now wrapped in `<>…</>` and the bundle rebuilt — the unmapped-statuses UI reaches users for the first time.

## [1.1.2] — 2026-08-19

Postback observability and honest status mapping, routing consolidated into a single front controller, and performance work on the click path and analytics. Existing nginx servers need one `nginx_sync.php` run (see below).

### Added
- 📜 **Incoming postbacks log (ORB-001)** — every postback the tracker receives is now recorded as it arrives, before status mapping and attribution run, so a conversion that went missing or landed on the wrong status can be traced back to the exact request the network sent.
- 🧩 **Unmapped conversion statuses + retroactive mapping** — the Facebook and TikTok engines check custom status mappings before falling back to defaults, and a status that no rule maps is stored unmapped instead of being forced into a guess. A new section in Conversion Types Settings lists the unmapped statuses collected so far and lets you define a mapping for them; a mapping defined later is applied retroactively, reclassifying conversions already stored with that status.
- ⚡ **Landing assets via nginx X-Accel-Redirect (ORB-013)** — PHP resolves the asset path with its security checks, then hands the file to nginx through `X-Accel-Redirect`: nginx serves it with sendfile (zero-copy) while PHP is freed for the next request, so a landing page with 30 assets no longer ties up 30 PHP workers. The internal location serves only whitelisted asset types — everything else, including `.php`, gets `deny all`. **An existing nginx server needs `sudo php /var/www/orbitra/cli/nginx_sync.php` once** — the internal location lives in the vhost, not in the code. Apache installs need nothing manual.
- 🗂️ **Analytics performance indexes** — a new migration adds covering indexes on the `clicks` table for the analytics and trends filters, so grouped reports over large click volumes stop scanning the whole table.

### Fixed
- 🎯 **LeadForge no longer fabricates subids (ORB-011)** — the `bin2hex(random_bytes(8))` fallback that invented a subid for leads with no click context is gone. The subid is now verified tri-state against the `clicks` table: a lead with a stale or missing subid is rejected with a neutral customer-facing message and an entry in `system_logs`, while a lead is still accepted when the database is unreachable (remote deployments, DB errors) so availability never depends on the check.
- 🔒 **Cloudflare Flexible SSL redirect loop** — HTTPS detection goes through `orbitraIsHttps()`, which honours the `CF-Visitor` header, so the panel no longer bounces between HTTP and HTTPS behind Flexible SSL.
- 🌐 **One source of truth for the server's public IP (ORB-005)** — features that need the server's public address (SSL provisioning, integration hints) no longer each run their own detection with their own caching quirks.
- 🧱 **IntegrationsPage JSX fragment** — multiple sibling elements behind a conditional were wrapped in a fragment, fixing a render error on that page.

### Changed
- 🧭 **Routing consolidated into `index.php`** — `router.php` is deleted; `index.php` is the sole front controller and carries the two access guards ported from it: the Disabled Domain Guard (a 404 for domains marked disabled) and the Admin Access Guard (admin routes restricted per the domain's `admin_access` setting). The postback route and the conversion pixel path work through the front controller as well, returning a valid GIF with proper error codes, and are now covered by real-HTTP integration tests (ORB-010) instead of fakes.
- ⏱️ **Geo enrichment off the click path** — synchronous calls to external geo APIs during click processing are disabled; a click no longer waits on a third-party response before being redirected.

## [1.1.1] — 2026-08-18

A local offer hosted on the tracker could take a lead and then answer the buyer with a 404: the form's relative `order.php` never reached PHP at all.

### Fixed
- 🚨 **A local offer's form POST returned 404** — an uploaded offer is served inline at the campaign URL (`/pr6sxv41`), so the browser resolved the `action="order.php"` LeadForge writes against the domain root and posted to `/order.php`. Nginx claimed that path first: `snippets/fastcgi-php.conf` ends in `try_files $fastcgi_script_name =404` and nothing named `order.php` exists at the document root, so nginx answered with its own 404 and index.php's order bridge never ran. The generated vhost (`core/nginx_config.php`) and `install.sh`'s baseline now hand `/order.php`, `/offers/<id>/*.php` and `/lander/<slug>/*.php` to the front controller, which resolves the bundle and runs the handler in-process as it always intended to. **An existing server needs `sudo php /var/www/orbitra/cli/nginx_sync.php` once** — half of this fix lives in the vhost, not in the code.
- 🔁 **`success.php` was missing from the handler whitelist** — the generated `order.php` finishes with a relative `Location: success.php`, so the network could accept a lead and the buyer still land on a 404 one hop later. `orbitraBundleHandlers()` is now the single list every bridge reads: `order.php`, `thank_you.php`, `success.php`, `send.php`, `lucky.php`, `lemon.php` — plus `api.php` on the routes that carry the bundle's id in the URL, never at the domain root, where that name is the tracker's own admin API.
- 🎯 **Form actions are pinned to the offer's own URL** — a relative `*.php` action in a served offer becomes `/offers/<id>/order.php`, so the lead POST carries the offer id instead of depending on a cookie or a Referer surviving, and an Ezaff bundle's `api.php` sender can no longer collide with `/api.php`. In-page `#anchor` links, assets, and any absolute or external action are left alone — deliberately not a `<base>` tag, which would turn every "#order" button into a navigation off the campaign URL.
- 🔒 **Uploaded PHP is no longer executable straight off disk** — `/offers/<id>/order.php` used to go to PHP-FPM directly, running a file out of an uploaded archive outside the "Allow PHP landings" switch and its execution budget. Both bundle routes go through index.php now, and `/landings/<id>/*.php` — the storage directory behind `/lander/<slug>/`, not a public route — returns 404.

### Added
- 🧭 **`ORBITRA_OFFER_ID` / `ORBITRA_OFFER_URL` / `ORBITRA_OFFER_PATH`** — defined before any of a local offer's own PHP runs, on all three paths that execute it. A bundle that wants an absolute URL for itself can write `defined('ORBITRA_OFFER_URL') ? ORBITRA_OFFER_URL . 'order.php' : 'order.php'` and still work standalone.
- 🧪 **`tests/lander_order_route_test.php` grew to 50 checks** — `success.php` on both bridges, the offer-context constants, `/api.php` never claimed from a bundle, twelve form-action rewrite cases, and an assertion that the vhost generator and `install.sh` agree on the bundle routes *and* place them before the generic PHP handler.

## [1.1.0] — 2026-08-18

Conversion attribution for affiliate-network postbacks (Dr. Cash and every other `?subid=` integration) and the report query that had stopped parsing, plus conversion-failure monitoring, cloud-aware SSL provisioning, a Google Ads OAuth walkthrough, TikTok cost-connection fixes, and outbound TLS verification restored.

### Fixed
- 🚨 **Grouped reports returned a SQL error** — the `campaign_report` statement carried a `//` comment inside the SQL string. SQLite only understands `--` and `/* */`, so it rejected the whole statement and *every* layered report (Sub1…Sub30, Country, Day, Campaign, Offer…) failed instead of rendering. The guard it was attached to is now `HAVING COUNT(click_id) > 0`, and `tests/postback_attribution_test.php` fails if a `//` comment reappears there.
- 🔗 **Postback conversions were stored unlinked** — `postback.php` wrote only `click_id`/`status`/`payout`, leaving `conversions.campaign_id`, `offer_id`, `sub_id_1..5`, `ip` and `user_agent` NULL even though the matched click had all of them. The conversions log showed no campaign or offer, and its campaign/offer filters matched nothing. Every conversion is now stamped with its click's dimensions at ingestion (`core/ConversionAttribution.php`), and a repeat postback that only changes the status no longer rewrites the original attribution.
- 🎯 **`sub_id_1` is the click's own sub1, never the postback's `subid`** — `subid` is the tracker's click id (Dr. Cash carries it in `&sub1={subid}`); copying it into the sub1 dimension would give every conversion a unique Sub1 value. The sub dimensions are read from the click's `parameters_json`.
- 🔤 **Status words are matched case-insensitively** — a network sending `Approved` or `PENDING` used to be recorded as a conversion that belonged to no status group, which is how a campaign could show `Conversions: 1` with `Sales`, `Leads`, `Rejected` and `Trash` all at 0. Both `mapStatus()` and the report aggregates now lowercase before comparing, and the groups additionally cover `approve/accepted/paid`, `new/wait/waiting/processing`, `reject/decline/cancelled` and `spam/duplicate`.
- ↔️ **A status's own mapping rule wins** — with `trash_status=trash` *and* `rejected_status=rejected,trash` in the same postback URL, `status=trash` now stays Trash instead of going to whichever type happened to list it first.
- 📥 **CSV conversion import attributes rows too**, so a manual import cannot reintroduce unlinked records.
- 🧹 **Migration 33 backfills history** — existing conversions get `campaign_id`/`offer_id`/`sub_id_1..5`/`ip`/`user_agent` copied from their click. Rows whose click no longer exists are left untouched.

- 🔐 **Outbound TLS certificate verification restored** — `telegram_notify.php`, `telegram_bot.php`, `postback_queue_cron.php` and `core/LeadForge.php` issued cURL requests with `CURLOPT_SSL_VERIFYPEER => false`, accepting any certificate. Verification is now on by default and relaxed only when the environment explicitly says it is local (`APP_ENV=local`, `ORBITRA_ENV=dev`, `ORBITRA_SKIP_SSL_VERIFY=1`).
- 📊 **TikTok cost connection against API v1.3** — `advertiser/info/` is called with the `advertiser_ids` JSON array the current API expects, the Advertiser ID is accepted only as digits (smart quotes, zero-width characters and stray spaces from copy-paste are stripped), and failures name the cause instead of surfacing a bare code. The token field states that a **Marketing API** token with Ads Management / Reporting scope is required — an Events Manager token will not work.
- 🧯 **`core/SslConfig.php` did not parse** — it declared `private const` at file scope, outside any class, so the file was a syntax error from the moment it was added. Nothing required it yet, so the panel was unaffected, but it would have taken down any request that loaded it.

### Added
- 📡 **Conversion failure monitoring** — `core/ConversionMonitor.php` records why a conversion could not be created (unknown click, database error) to `var/logs/conversion_failures.log`, `api.php?action=conversion_monitoring` reports failure counts and rates over a window, and `conversion_monitor_cron.php` alerts through Telegram once the failure rate crosses a threshold. Run it every 5–15 minutes.
- 🔒 **Cloud-aware SSL provisioning** — `core/CloudDetector.php` recognises Cloudflare-proxied domains by IP range and response headers so Certbot is skipped where it cannot validate through the edge, `core/ssl_manager.php` checks DNS and passwordless-sudo prerequisites before requesting a certificate, and Domains gained a reissue action that reports why issuance failed. See `CERTBOT_SETUP.md` and `SECURITY_OPERATIONS_CHECKLIST.md`.
- 🛡 **`core/SslConfig.php`** — locates the system CA bundle across Debian, RedHat, Alpine and macOS layouts and builds stream contexts from it, for hosts whose PHP ships without a usable default.
- 🔑 **Google Ads 1-click OAuth setup guide** — an in-panel walkthrough for the Cloud project, redirect URI and developer token, plus `check_google_ads_oauth.php` as a CLI preflight that names the missing credentials. `.env.example` documents the variables.
- 🌐 **Locale coverage for the above** — SSL, Namecheap whitelist, TikTok token and Google Ads setup strings added across all seven languages.
- 🧪 **`tests/postback_attribution_test.php`** — the Dr. Cash path end to end: attribution payload, non-destructive re-postback, backfill, one-group-per-status, mixed-case status counting, and a Sub1 report row that carries clicks and conversions together.

## [1.0.9] — 2026-08-18

LeadForge 2.0 Reference Synchronization, 150-GEO validation engine, local PHP landing macro resolution, and complete OAuth setup guides.

### Added
- 🌍 **LeadForge 2.0 Reference Sync & 150-GEO Validation Engine** — validation rules for 150 countries with mobile operator prefixes, dynamic country switcher, live input digit-counter badges, Unicode name sanitation, and haptic feedback on mobile.
- 🔤 **Local PHP Landings & Offers Macro Resolution** — output-buffering macro substitution (`{subid}`, `{sub1}`, `{click_id}`, `{{subid}}`, `{data1}`, …) with cross-page Click ID hydration via cookies and sessions.
- 🌉 **Fail-Safe CPA Order Bridge (`order.php`)** — native support for 10 CPA networks (Dr.Cash, Webvork, Lucky.online, KMA.biz, TerraLeads, Leadbit, LemonAD, Everad, Ezaff, Custom Webhooks) with E.164 phone normalization, dual CRM logging, and multi-source Click ID resolution.
- 📖 **Setup guides & keys reference** — in-UI step-by-step instructions for **Google Ads**, **TikTok for Business** and **Facebook / Meta**, with 1-click copyable Authorized Redirect URIs and a Direct Token connection fallback.
- 🌐 **100% multilingual coverage** — translation parity across all 7 supported languages (EN, RU, UK, DE, ES, FR, ZH).

## [1.0.8] — 2026-08-18

LeadForge 2.0 Reference Synchronization, complete 150-GEO validation engine, dynamic country switching, interactive live counter badges, and universal multi-network CPA order bridge.

### Added
- 🌍 **Full 150-GEO Validation Dataset (`core/data/leadforge_geo_rules.json`)** — complete dataset derived directly from the reference LeadForge platform (`87.232.72.54`), covering 150 countries with exact international and national regexes, mobile network prefixes, min/max length constraints, dialing prefixes, trunk rules, and localized messages in 33 languages. Enriched with full rules for CIS countries (`RU`, `KZ`, `BY`, `UZ`, `UA`, `MD`, `GE`, `AM`, `AZ`, `KG`, `TJ`), India (`IN` +91), Europe, LATAM, and MENA/Asia.
- ⚡ **Reference JavaScript Validation Engine (`orbitra_adapter.js`)** — based on the reference validation template (`core/data/leadforge_validation_template.js`):
  - **Dynamic Country Switching**: Automatically updates validation rules, input masks, and counter helpers when a user changes the `<select name="country">` dropdown.
  - **Interactive Live Input Badge Counter**: Visual progress indicator showing digits entered and remaining (*«3 cifre inserite, 7 mancanti»* → *«Numero complete»*).
  - **Strict Unicode Name Validation**: Real-time filtering preventing numbers and invalid symbols in customer name fields.
  - **Haptic Vibration Feedback**: Device vibration on invalid input attempts on mobile browsers.
  - **Comprehensive Parameter Bridge**: Hydrates hidden tracking fields (`subid`, `click_id`, `sub1`..`sub5`, `utm_*`, `fbp`, `fbc`, `pub_sub_id`, etc.) across pages with cookie and `sessionStorage` fallback.
- 🚀 **Universal Multi-Network CPA Order Bridge (`order.php`)** — generated standalone handler with native integration for 10 affiliate networks:
  - **Dr.Cash**: `https://order.drcash.sh/v1/order` (Bearer token, client JSON, `sub1`..`sub5`).
  - **Webvork**: `https://api.webvork.com/v1/new-lead` & `api2` fallback (`utm_campaign` = `{subid}`).
  - **Lucky.online**: `https://lucky.online/api/v1/lead-create/webmaster` (`campaign_hash`, `subid1` / `subid`).
  - **KMA.biz**: `https://api.kma.biz/lead/add` (Bearer token, `data1`..`data5`).
  - **TerraLeads**: `https://t-api.org/api/lead/create` (SHA1 json+key checksum verification).
  - **Leadbit**: `http://wapi.leadbit.com/api/pub/new-order/{token}` (`flow_hash`, `sub1`..`sub5`).
  - **LemonAD**: `https://lemonad.com/api/v2/lead/create` (`click_id`).
  - **Everad**: `https://api.everad.com/campaigns/{offer_id}/order` (`X-Api-Key`, `sid1`).
  - **Ezaff**: `https://api.ezaff.com/send` (`click_id`, `pub_sub_id`).
  - **Custom Webhooks**: Full POST payload passthrough.
- 📱 **Automated E.164 Phone Normalization (`lf_normalize_phone`)** — standardizes local phone inputs into clean E.164 format with automatic prefix handling (stripping leading zeros, country dial code reconciliation).
- 💾 **Dual Logging & Failsafe Lead Storage** — simultaneously submits leads to the CPA network and logs complete raw lead snapshots into the Orbitra CRM Vault (`orbitraCrmRecordLead` / `/crm-ingest`), alongside local append logs (`leadforge.leads.log` & `orbitra_leads_backup.log`).
- 🧪 **Automated Synchronization Test Suite (`tests/leadforge_sync_test.php`)** — verified 150 GEO rules, adapter JS generation, order PHP generation across all 10 networks, and router containment.
- 📦 **Remote Reference Server Backup** — full `/opt/leadforge` directory archived locally to `remote_leadforge_backup/`.

## [1.0.7] — 2026-08-18

Modern Integrations Card Hub architecture, in-browser IDE & File Manager for local offers, and secure file operations API.

### Added
- 🗂️ **Modern Integrations Card Hub** — complete UI redesign of the Integrations page (`IntegrationsPage.jsx`) into a dual-mode Grid/Card Hub (Vercel & Stripe style). Replaces the old nested 240px double sidebar with a spacious responsive catalog. 25 integration services are organized across 4 categories (`Ads & Costs`, `Domains & SSL`, `Tracking & Sites`, `Tools & API`).
- ⚡ **Real-Time Live Status & Metrics** — dynamic pulsing status indicators on integration tiles display live connected account counts, real-time Namecheap balances, Cloudflare zone totals, Telegram bot connection state, and active API keys. Parallel data prefetching on mount (`loadOverviewData`) keeps every tile fresh immediately.
- 🔎 **Instant Hub Search & Category Filtering** — interactive category pills and real-time search filtering by title, subtitle, description, and keywords.
- 🖥️ **100% Full-Width Detail Configuration View** — opening any integration provides full screen width for complex forms, tables, and code snippets, complete with top breadcrumbs and `← Back to all integrations` navigation.
- 💻 **In-Browser IDE & File Manager for Local Offers** — `OfferEditor.jsx` now provides a full-featured code editor and asset manager for direct local offers (`is_local=1`): interactive file tree with file type icons, Monaco-style editor for HTML, CSS, JavaScript, PHP, JSON, XML with syntax highlighting, search & replace, code beautification, live multi-device preview (desktop/mobile iframe toggle), image preview, direct upload, replace, delete, download, and ZIP extraction with validation.
- 🛡️ **Secure Backend File Operations API** — new endpoints in `api.php` (`offer_files`, `offer_file_content`, `save_offer_file`, `offer_file_op`, `upload_offer_file`) equipped with tiered PHP security scanning, strict `realpath` containment to prevent path traversal, file whitelist validation, and automated test suite (`tests/offer_file_ops_test.php`).
- 🌐 **Full 7-Locale Parity** — all new hub headings, category filters, action buttons, and status texts localized across English (`en`), Russian (`ru`), German (`de`), Spanish (`es`), French (`fr`), Ukrainian (`uk`), and Chinese (`zh`).

## [1.0.6] — 2026-08-18

Multi-accounting for the DNS/registrar layer, delete safety for serving entities, and upload hardening — plus in-modal copy feedback the tester could actually see.

### Added
- ☁️ **Cloudflare multi-account** (migration 32) — the legacy single token
  (cf_api_token) becomes account #1 of a new `cloudflare_accounts` table and
  domains gain `dns_account_id`: the Domains page picks the account per domain,
  Integrations manages accounts (add / edit / delete, per-account SSL and proxy
  modes, zone import and repoint via the API).
- 🌍 **Namecheap multi-account** (migration 31) — the registrar mirror: the
  legacy `nc_api_key` becomes account #1 of `namecheap_accounts`; Integrations
  lists accounts with live balance checks plus add / rename / delete.
- 🔐 **OAuth preflight for TikTok and Google Ads** — `<engine>_oauth_status`
  joins Facebook's: without a configured app the 1-Click button disables with
  an amber card instead of dying mid-flow, and each network also takes a direct
  token connection (manual mode is the default for new credentials).
- 🛡️ **Delete guard for landings and offers in use** — deleting an entity still
  referenced by an active campaign stream is refused with `entity_in_use` plus
  the campaign list; the frontend localizes the refusal
  (utils/entityInUseError.js) instead of treating the HTTP-200 error body as a
  silent success.
- ⏱️ **Sub-hour cost sync** — `sync_interval_hours` accepts fractional values
  (0.5 / 0.333) for near-realtime aggregator ticks.

### Changed
- 🎨 **Custom theme contrast** — a picked primary now derives its inverse text
  colour by perceived brightness (the neon-lime primary gets dark text instead
  of unreadable white); leaving the custom theme clears the inline override so
  the built-in themes win again.
- 📋 **In-modal copy feedback (Users → API keys)** — the connector-URL and
  API-key copy buttons swap to a green check for 2 s and confirm with a banner
  inside the modal; the old page-level toast rendered behind the overlay, which
  read as "the button does nothing". Generate/delete-key notices moved in-modal
  for the same reason.
- 🧩 **Chrome extension 1.0.1** — RU/EN localization for the live overlay, a
  draft badge for campaigns without traffic yet, and the MutationObserver now
  ignores the extension's own injections so re-rendering stops scheduling
  refresh loops; rebuilt orbitra-extension.zip.

### Fixed
- 🔒 **Local offer upload hardening** — uploads pass a tiered PHP scan (hard
  block for dangerous constructs, soft sanitization for the rest); the
  `T_NAME_FULLY_QUALIFIED` hole — namespaced function calls invisible to the
  scanner — is closed, and archives land via stage-then-swap with realpath
  containment so symlink tricks can't escape the offer directory.
- 🔗 **order.php / thank_you.php bridge route** — bridge files of a local offer
  are served through a realpath containment check instead of a bare path join.

## [1.0.5] — 2026-08-17

Safe Page white pages: grouped selects, a Local Offer safe mode, and a critical fix that made direct local offers unreachable.

### Added
- 🛡️ **Safe Page: grouped selects** — the "Tracker Landing" picker in the cloaking
  Safe Page block now groups landings by their group (`<optgroup>`, groups sorted
  alphabetically, "No group" collected at the bottom), so a library of dozens of
  whites stays navigable; a 🔍 button next to the select opens the entity picker
  (instant search + group filter) in a new single-select mode — one click picks.
- 📦 **Safe Page: Local Offer mode** — a fourth tab "Local Offer" lets a LeadForge
  direct local offer (`is_local=1`) act as the white page: the new
  `safe_mode='offer'` + `safe_offer_id` stream fields are honoured by all three
  entry points (index.php serves the archive inline with full macro/cookie
  context; click.php and the Click API redirect to the new public
  `/offers/<id>/` address). A missing or non-local offer falls back to the
  default page, exactly like a missing safe landing. The picker hides the
  network/GEO columns when only local offers are listed.
- 🔗 **Public `/offers/<id>/` route** — the offer twin of `/lander/<slug>/`:
  serves a local offer's uploaded index (with `<base>` injection and the
  `orbitra_lo` cookie so relative assets and the order.php bridge keep working),
  404s unknown offers, never executes PHP indexes, and routes non-page files
  through the same extension whitelist and path containment as the click flow.

### Fixed
- 🚨 **Direct local offer streams died with "URL not found."** — the
  `die()` on an empty `$finalUrl` fired before the local-offer serving branch,
  so v1.0.4's marquee "Direct Local Offer" destination never actually worked
  for offer-only streams. The uploaded page is now served as intended; a
  `null` url no longer trips PHP 8.5's `str_replace(null)` deprecation, and the
  Click API answers with the offer's `/offers/<id>/` address instead of an
  empty URL on both the cloak money side and the redirect schema.
- ⚙️ **`allow_php_landings` seed missed on upgrades** — the v1.0.4 default rows
  were added without a schema bump, so databases already at schema 29
  (installed with 1.0.2) never received them and LeadForge builds failed with
  `php_landings_disabled`. Migration 30 inserts
  `allow_php_landings`/`php_landing_timeout` (`INSERT OR IGNORE`, an explicit
  `'0'` survives), and `PhpLanding::enabled()` now treats a missing row as
  enabled; Settings → General and the LeadForge panel follow the same
  default-on semantics.

## [1.0.4] — 2026-08-17

LeadForge tracker destinations, calendar viewport fix, Facebook OAuth preflight.

### Added
- 🎯 **LeadForge: Tracker destination** — every bundle can now be saved as a
  local lander (Landings), a **direct local offer** (Offers, `is_local=1`, files
  served from the tracker's own `/offers/<id>/` route with no landing record),
  or the linked lander+offer pair; an explicit "ZIP only" state replaces the
  old auto-save/auto-create checkboxes. Legacy API flags keep working.
- 📁 **Groups on the fly** — the group picker follows the destination (landing
  groups vs offer groups) and "+ New group" creates one inline without leaving
  the panel; a duplicate name selects the existing group. `new_group_name` is
  resolved once per batch in the API (per-bundle creation would trip
  `UNIQUE(name)`), with a fallback to the existing id.
- 💰 **Opt-in fixed payout** — LeadForge no longer hardcodes a payout into the
  build by default; real revenue comes from the network's S2S postback unless
  "Fixed payout" is checked.
- 🔵 **Facebook OAuth preflight** — `facebook_oauth_status` tells the
  Integrations UI whether a Meta app is configured at all: the 1-Click button
  disables with a warning instead of opening a popup guaranteed to fail; a
  collapsible hint explains where a long-lived token comes from.
- ⚙️ **PHP landings on by default** — LeadForge bundles live on
  order.php/thank_you.php and were unusable on a fresh install; the toggle and
  timeout are now exposed in Settings → General (both API whitelist spots were
  already in place).

### Fixed
- 📅 **Date-range calendar off-screen** — the 540px panel was right-aligned to
  its trigger, so on Landings (picker at the left edge of the toolbar) it
  opened ~300px past the left edge of the window. The panel now measures the
  viewport on open (and on resize) and hangs from the side that fits;
  Campaigns/Offers/CampaignReports keep their previous look.
- 🗄️ **Offer-group FK failure** — `auto_create_offer` copied a landing-group id
  into `offers.group_id` (`FOREIGN KEY … offer_groups`, `PRAGMA foreign_keys=ON`),
  so building with a group selected could fail the whole bundle; the offer now
  links a same-named offer group when one exists, else none.
- 📧 **landing_groups duplicate 500** — POSTing an existing group name returned
  a 500 (missing try/catch, unlike offer_groups); it now returns a clean error.

## [1.0.3] — 2026-08-17

Editor tooling, layout consistency and worldwide GEO coverage.

### Added
- 🔍 **Find & Replace in the landing code editor** — a VS Code-style widget
  over the textarea: `Ctrl/Cmd+F` find, `Ctrl/Cmd+H` replace, `Aa` / `\b` /
  `.*` modifiers, live `N of M` counter, Enter/Shift+Enter navigation with
  scroll-to-match (exact vertical math for the fixed line height, measured
  horizontal reveal for minified one-liners), single Replace and
  count-verified Replace All (literal mode splices the match list, so an
  empty-matching regex never smears the replacement between characters), and
  the query is pre-seeded from the editor selection.
- 🌍 **79-GEO phone-mask coverage** — `geoMasks()` grows from 13 to 79
  countries with mobile patterns and digit bounds; the LeadForge picker
  mirrors it grouped by Europe / Americas / Asia / MENA & Africa
  (`optgroup`s, localized region labels). Masks feed order.php generation,
  adapter validation and Auto QA scoring.
- 🧾 **CRM hover-copy** — SubID and phone copy buttons on row hover with
  check-mark feedback; Approval Rate moved under the Approved Sales KPI
  card; the Shave Suspects card got a rose tint and reports only
  lost-in-transit.

### Changed
- 🖥️ **Full-width CRM & LeadForge** — `max-w-7xl` dropped (the only two
  capped pages in the app), matching the dashboard; App.jsx no longer
  renders its generic `h1` above their hero headers.

### Fixed
- 🔐 **API key + browser session coexistence** — a key arriving on a
  signed-in browser request is honored only when it belongs to the same
  user (403 otherwise); the live session is never re-identified as
  `api_key`, so `extension_credentials` stays reachable for the panel
  browser; invalid keys now 401 even alongside a session.

## [1.0.2] — 2026-08-17

LeadForge grows from a one-shot forge into a two-stage compiler, and the CRM
becomes an anti-shaving evidence vault.

### Added
- 🔬 **LeadForge 2.0: Analyze → Build** — `leadforge_analyze` inspects up to
  15 ZIP/HTML/PHP bundles without touching a byte (source-network detection
  across 13 CPA signatures, forms and input-name inventory, foreign counters,
  UTF-8 checks, GEO from the `lang` attribute, ready-for-build cards), stages
  them for 24h, and `leadforge_build_batch` compiles the selected ones with a
  live execution console.
- 🔀 **Three integration modes** — **Auto** (detect + route to the recognized
  network), **Cross-Network** (cut the old network's `order.php` / `send.php`
  / `api.php` / `sender.php` handlers and re-seat the landing on the chosen
  target), **Raw** (strip FB/TikTok/GA/Yandex counters and hostile snippets —
  DevTools blockers, right-click disablers, back-redirects — inject the
  ClickID bridge and `{offer}` macros, no backend generated).
- 🛡️ **Live Auto QA with Confidence Score** — after each build a
  `QA-Test-Lead` (phone by GEO mask, e.g. `+39 333 000 1122` for IT) is
  posted through the real `/order.php` bridge; four checks worth 25% each
  (form structure, bridge response, dual logging, thank-you redirect) score
  the bundle 0–100%, re-runnable per landing via `leadforge_live_qa`. QA
  clicks and conversions are removed afterwards — analytics stays pristine,
  the vault row stays as reviewable evidence.
- 🗄️ **CRM Anti-Shaving Vault (migration 28)** — `crm_leads` stores the full
  snapshot: raw phone as typed vs the E.164 actually delivered, UTM / adset /
  ad attribution, product & price, and the exact network request/response
  dump (endpoint, payload, HTTP code, body, network lead id). Fed three ways:
  in-process from the generated order.php, via the public `POST /crm-ingest`
  endpoint for bundles hosted on foreign hosting, and manually from the
  panel. `leadforge_profiles` ships seeded with the six networks the engine
  speaks.
- 🚨 **S2S reconciliation & shave detector** — postbacks move every CRM row of
  the click to the network's verdict (`&reason=` is stored verbatim); a lead
  is flagged **Suspected Shave** only when provable: delivered a well-formed
  E.164 number, the network answered HTTP 200, and the verdict is still
  negative. Leads silent for 24h after a 200 flag as **Missing Network ACK**,
  same-phone-same-network repeats within 30 days mark as **Duplicate**. The
  Lead Inspector modal shows the three evidence tabs (Raw Lead Data / Network
  Transaction / Tracking Attribution) with an exportable evidence pack for a
  support ticket.
- 🧷 **orbitra_adapter.js 2.0** — the ClickID bridge reads the tracker's
  `orbitra_click` cookie as the last-resort subid, accepts `clickid` /
  `sub_id` aliases, persists captures across pages (sessionStorage + cookie)
  and enforces the real GEO phone mask (placeholder, input filtering,
  submit-blocking validation).
- 🌐 Full i18n for both pages across the 7 locales.

### Fixed
- 🧯 **order.php bridge died on the execution budget** — the generic PHP
  landing budget (3s default) killed generated handlers mid-network-call
  (curl waits up to 15s for the CPA network); bridge files now run with
  `max(PhpLanding::timeout, 25s)`.
- 🧭 **router.php swallowed service paths** — `/postback.php`, `/order.php`
  and `/crm-ingest` fell into the single-segment alias branch and answered
  empty 200s on the dev server (masked on Apache by the file-exists rewrite);
  the reserved-path list now matches index.php's.
- 🎨 **LeadForge & CRM follow the global theme system** — hardcoded
  amber/indigo/sky gradients replaced with theme primaries (`btn-primary` /
  `btn-secondary`, `color-mix` tints), text on primary uses
  `--color-text-inverse` (white vanished into neon's lime), the «3-in-1»
  header badges are gone and 17 dead icon imports pruned.

## [1.0.1] — 2026-08-17

TikTok gets the same 1-click entry Facebook has, plus fixes for the 1.0.0
follow-ups found in the field.

### Added
- 🎵 **1-Click TikTok for Business (OAuth 2.0)** — a popup login from the
  Pixel Vault discovers every accessible ad account and pixel
  (`/oauth2/advertiser/get/`, `/pixel/list/`), auto-imports the pixels into
  the vault and saves one spend-sync connection per selected cabinet
  (engine `tiktok`, checkboxes + sync interval). Tokens never reach the
  browser: the popup only carries an opaque flow id, mirroring the Facebook
  flow.
- 🔄 **Automatic TikTok token refresh** — TikTok access tokens live ~24h and
  the refresh token is single-use; `TikTokAdsEngine::ensureFreshToken()`
  refreshes on every scheduled/manual cost sync, writes the new pair to all
  sibling connections of the same login and propagates the access token to
  the imported pixel profiles and campaign pixel copies (old-token match).
  A dead refresh token throws, so the sync log names the real reason.
- 🗂️ **Pixel Vault source tabs** — All / Facebook / TikTok / Google Ads…
  filter chips with live counts, next to the existing niche filter.
- 🎯 **Pixel options name their network** — the campaign editor's pixel
  picker shows `pixel_id ( TikTok · Nutra / Name )`, so mixed-network vaults
  stay distinguishable.
- 📄 **TikTok Pixel snippet** — ready-made landing code in the Integrations
  library: the `{pixel}` campaign parameter (TikTok source template's
  `__PIXEL__`) is stored in a cookie and boots `ttq` on the landing or
  thank-you page.
- ⚙️ Optional App ID / App Secret fields on TikTok cost connections — they
  authorize the app for 1-click login and keep token refresh working
  (env `ORBITRA_TIKTOK_APP_ID`/`_APP_SECRET` also honoured).

### Fixed
- ⬜ **Campaigns white screen (TDZ)** — shipped in 1.0.0: the
  traffic-source filter state sat in an effect's dependency array above its
  `const` declarations, so every visit to Campaigns threw
  *Cannot access 'selectedGroupId' before initialization*. The state block
  moved above the pagination-reset effect.
- 🎨 **Dashboard stat cards** — 32px of air above Traffic Dynamics, active
  glow softened to `color-mix(… 25%, transparent)` with a 1px lift,
  theme-safe across all 5 themes.
- 🔍 **Traffic Sources search** — clearer icon sizing, a clear button and
  card-consistent input styling.

## [1.0.0] — 2026-08-17

The 1.0 milestone: Orbitra grows from a click tracker into a full funnel
suite — forge the landings, track the traffic, collect the leads.

### Added
- 🏗️ **LeadForge (landing forge)** — generates ready-to-upload landing
  packages; the order.php / thank_you.php form handlers are served through an
  in-process bridge in index.php (static hosting works), subid is carried via
  a hidden field or the orbitra_click cookie, and finished packages download
  through a tokenized endpoint.
- 🧲 **CRM capsule** — leads arrive via api.php `crm_lead` (a minimal click is
  created when the form post has none); LeadForge / Tracker / CRM are unified
  by a suite switcher in the navbar.
- 🎯 **TikTok Conversions API** — server-side events through
  core/TikTokConversions.php alongside the Facebook CAPI; pixel profiles
  (traffic source, niche, event set, test codes) drive both engines, and a
  schema_migrations panel reports applied versions (schema version 26, SQL
  reference kept in migrations/).
- 🔍 **Inline mini search** in Offers / Campaigns / Landings — the input lives
  in the header action group next to Create / Groups, filters live by name,
  URL, group, network and exact ID, with a one-click ✕ clear.
- 📄 **Universal table pagination** — shared PaginationToolbar (Showing X-Y
  of N rows · Page Size 25/50/100/250/All · First/Prev/pages/Next/Last) under
  the Campaigns, Offers and Landings tables; the page size is remembered in
  localStorage across tables while TOTAL rows stay computed over the whole
  filtered list.
- 🔀 **Traffic source filter in Campaigns** — an "All traffic sources"
  dropdown next to the group filter narrows the table instantly (client-side;
  rows already ship source_id/source_name), and TOTAL recomputes with it.

### Fixed
- **Navbar no longer clips on laptops** — the desktop menu moves to the xl
  breakpoint (1280px) with a compact NavItem; below it the hamburger opens the
  full drawer (suite switcher, navigation, settings) instead of links
  overflowing the right edge on 768–1280px screens.
- **Dashboard horizontal scroll is gone** — StatCards drop the negative-margin
  bleed for a responsive grid (2→7 columns by viewport), and the
  DashboardHeader campaign/date selectors stretch fluidly instead of the fixed
  300px that overlapped on tablets.
- **Stream weights are visible again** — with By-weight rotation every stream
  header carries the weight input plus a live share badge (e.g. 70.0%), a
  Total Stream Weight bar warns when the sum ≠ 100%, and Split Evenly hands
  out 100% across active streams in one click.
- **Traffic Sources page is fast** — the stats query aggregates clicks per
  source in a single subquery pass instead of joining the whole clicks table
  per source row (semantics unchanged), and the source editor caches its
  template list at module level so the modal opens instantly.
- **Copy buttons work on plain HTTP/IP installs** — the postback URL
  (Affiliate Networks), source URL and campaign-link copies now route through
  the shared execCommand-fallback util instead of raw navigator.clipboard
  (blocked outside HTTPS), and the postback code block is click-to-copy.
- Carries everything from the unreleased 0.9.9.2: universal CPV/EPV
  direct-vs-Lander funnel semantics (see the 0.9.9.2 entry below).

## [0.9.9.2] — 2026-08-17

### Fixed
- **Direct vs Lander funnel semantics in the report math** — CPV / EPV (and
  EPV confirmed) are now computed over ALL inbound visits, making them the
  universal unit economics: exact when a campaign mixes Lander streams with
  direct-to-offer streams, and no longer zeroed out on pure direct traffic
  where LP views don't exist. In a pure Lander flow every visit IS an LP
  view, so those values are unchanged.
- **LP CTR on direct offers** is now a dash (`null`) — there is no CTA to
  measure; the UI renders `—` instead of a made-up 0%/100%, and CSV leaves
  the cell empty. Division-by-zero guards cover every denominator.

### Changed
- Metric tooltips (column customizer) explain the dual semantics — universal
  CPV/EPV vs Lander-funnel EPC/CPC / LP CTR — in all 7 languages.
- `tests/report_metrics_test.php` pins the new math with hand-computed
  reference cases: a direct-flow stream, a mixed campaign row, and the
  canonical funnel (Lander parity proof).

## [0.9.9.1] — 2026-08-17

### Added
- 🛑 **Campaign switch stops real spend** — pausing a tracker campaign can now
  pause the linked Facebook campaigns / ad sets / ads through the Meta
  Marketing API (`sync_remote_ads` on `ad_entity_toggle_status`, entities
  resolved from `clicks.parameters_json` with the `ad_campaign_id` →
  `campaign_id` fallback); new `campaign_remote_links` endpoint powers a
  safety confirmation that lists the ad entities before stopping them.
- 📊 **Column grid (Line System)** — light vertical dividers across the
  high-density Campaigns / Reports / Landers / Offers tables (headers, cells
  and the totals footer); the Reports table adopted the tracker styling.
- 🪟 **Tabbed editors** — Affiliate Network and Traffic Source editors split
  into General / Parameters / Notes with a pinned header and footer.
- 📈 **EPV metric** (earnings per landing-page visit) with column hints in
  the report customizer.

### Changed
- The pause switch moved to a dedicated sortable **Status column**, away from
  the campaign name — a stray click on the name can no longer stop live ads.
- **EPC / CPC redefined per LP click** (was: all clicks), aligning the
  metrics with the landing-page funnel.

### Fixed
- **Modals fit the screen at 100% zoom** — the 100px overlay inset that
  pushed Save buttons below the fold is gone; only the modal body scrolls.
- **Copy buttons on plain-HTTP/IP installs** — `navigator.clipboard` is
  unavailable outside secure contexts; a shared `utils/clipboard.js` helper
  falls back to `execCommand` (network editor postback URL + macros,
  integration keys, landing snippets).
- Reports: the pinned name column no longer slides under sticky metric
  headers during horizontal scroll.

## [0.9.9.0] — 2026-08-17

### Added
- 🎮 **RedTrack-style play/pause** — toggle internal campaigns (a disabled
  campaign stops serving immediately, `index.php` answers 503) and real
  Facebook ads / ad sets / campaigns right from the Campaigns table and report
  rows (`campaign_id` / `ad_id` / `adset_id` / `ad_campaign_id` layers) via
  `FacebookAdsEngine::updateEntityStatus`; the owning ad account is resolved
  through `cost_records`, falling back to a single connected account. Reports
  now return raw `dim_ids` alongside display names.
- 🚦 **Per-stream "Collect clicks"** (schema v24, `streams.collect_clicks`,
  default on) — fallback / white-page streams serve their destination without
  writing a `clicks` row, consistently across `index.php`, `click.php` and the
  Click API; stream-header checkbox with a warning note in the editor, 7 locales.
- 🧩 **Ads Manager overlay extension** — row pills fused with attributed spend
  and verified revenue (`extension_ads_stats`), a per-entity deep-stats modal
  with daily history, landings & offers breakdown and Pixel/CAPI delivery
  accuracy (`extension_deep_stats`, 36-assertion fixture test), an
  auto-provisioned read key from the Integrations page, and a downloadable
  `data/orbitra-extension.zip`.
- 🏷️ **Keitaro-style domain panel** (schema v23) — dedicated `domain_groups`
  (seeded from used offer groups under the same ids, FK rebuilt with a
  restore-on-failure guard), per-domain admin access (deny → panel/API 404 on
  that host while tracking keeps working; `status=Disabled` → whole host 404),
  Cloudflare proxy flag (skips the Certbot queue — the SSL worker previously
  retried proxied domains forever), registrar/DNS metadata, bulk add with URL
  cleanup, "Add more" workflow; 7 locales, migration fixture test.
- 📊 **High-density tables** — Campaigns / Landers / Offers get compact 38px
  rows, sticky header and TOTAL footer inside a scrolling container, theme-safe
  zebra striping, single-line campaign rows (switch + name + alias), a ⋮ row
  menu (Edit / Costs / Copy link / Duplicate / Clear / Delete) and pagination
  (25/50/100/All) with filter-wide totals.
- 🖥️ **Device taxonomy** (`core/Device.php`) shared by the tracker, Click API
  and reports; CPV metric; editor preference presets; affiliate-network
  template presets.

- 📈 **Dashboard redesign** — settings modal rebuilt on theme variables with
  four metric groups and three presets; 27 selectable StatCards wired to real
  aggregated metrics (cost no longer hard-coded to zero); finance masking
  extended to CPL, CPS and EPV; dashboard translations across all 7 locales.
- 🎨 **Theme sweep** — hardcoded Tailwind grays/blues purged panel-wide
  (admin header, AutomationSettings, ClickDetailsModal, Login, SetupWizard,
  dashboard modals, RecentClicks and more); ClickDetailsModal's white-on-white
  fixed; semantic status colors on --color-success/--color-danger.
- 📚 **Docs refresh** — READMEs updated to the current feature set and halved
  (58 embedded per-version note blocks dropped; CHANGELOG is the history).

### Changed
- All monetary and unit metrics render as `$0.00` (2 decimals) everywhere,
  including CSV export — previously CPC/EPC/CPV showed four decimals.
- `var/` (sessions, locks, caches) is gitignored.

### Fixed
- Schema v25 adds `campaigns.state` — the column never existed in any DDL or
  migration; the new play/pause toggle surfaced it on live testing with
  "no such column: state".

## [0.9.8.2] — 2026-08-16

### Added
- 🔐 **RBAC role templates** (Admin / Media Buyer / Video Editor / Developer /
  Custom) — one-click role + permission matrix in the user modal — plus
  server-side financial masking: `show_costs` / `show_revenue` / `show_payout`
  null the money families across metrics, chart, campaign_report, campaigns,
  offers endpoints; save-guards restore hidden amounts. Nav tabs hide on
  `None` permission; gear menus filter per item.
- 🌐 **Namecheap integration** — Keitaro-style zero-config DNS parking, domain
  purchasing, import & subdomain parking.
- 🕵️ **Cloaking quick targeting filters** — GEO, devices, bot-ISP blocklist on
  the cloak card + global `bot_isp_list` setting.
- 📡 **CAPI per-pixel `event_source_url`** with `{campaign_url}` /
  `{landing_url}` / `{clickid}` macros (migration 22).
- 🎛️ **Redesigned Tracking tab** — two-column layout, per-method options, live
  widget preview, install hints.
- 📊 **Keitaro-parity Landings & Offers columns** — visits/uVisits, LP
  clicks/CTR, leads/sales/rejected/trash, Approve %, CR, cost/revenue(conf),
  profit, CPC/EPC/EPV, ROI/ROI(conf); compact `shortLabel` report headers with
  full-name tooltips.

### Fixed
- 🚨 **"Prefetch ignored." blank screen** — the prefetch guard killed the page
  instead of the click; all three entry points now serve the campaign, skip
  only the click INSERT and send `Cache-Control: no-store`.
- 🧩 **Generated snippets were broken code** — kclient-php nested open tag,
  back-button trap firing on Forward, unterminated-concat SyntaxErrors in
  link/iframe/script snippets; `EXIT_BUTTON_COLORS` wired into the editor.
- 🔗 Campaign URL kept ad-network macros percent-encoded (`%7B%7Bad.id%7D%7D`)
  so they were never substituted.
- 📘 Facebook Ads template: real Meta macros `{{placement}}` /
  `{{site_source_name}}`, fabricated `{{site.name}}` dropped.
- 🌍 i18n: country names hardcoded Russian in `countries_list`; es/zh/uk
  machine-translation howlers (CPC → "Communist Party", IP → "intellectual
  property", GEO → "orbital").
- 🎨 Themes: duplicate `.btn-primary` made neon buttons unreadable; Geo
  Profiles / GeoSelector hardcoded light-only colors; country dropdown clipped
  by the filter modal; columns-modal checkbox and drag reliability.

## [0.9.8.1] — 2026-08-16

### Fixed
- ✅ **Stream names were wiped on every campaign save** — the stream INSERT in
  `save_campaign` never carried the `name` column, so anything the editor's
  stream-name field collected became NULL on save. Found live while testing
  0.9.8.0 on the demo (a stream saved as "MCP stream" came back as null).
  Lost names need re-entering once after the update.

## [0.9.8.0] — 2026-08-16

### Added
- 🎯 **Keitaro-style landing/offer picker for streams.** The "Add landing pages /
  Add offers" split button opens a selector modal with instant search (name, URL,
  id), Group / Affiliate Network / Country filters, multi-select with Select all,
  and "Already added" badges. Picked entities enter the stream with even weight
  redistribution (floor(100/N), remainder on the first item). The dropdown's
  second action creates a new landing/offer without leaving the stream; the
  stripped-down name+URL offer form is now the full OfferEditor.
- 🔀 **AND / OR filter logic per stream.** The FILTERS header grows an
  `[AND|OR]` switcher (from the second filter on). Abstaining filters
  (undetermined country, missing ISP data) block nothing under AND and satisfy
  nothing under OR. Saved with the campaign, carried by duplicate/export
  (migration 21).
- 🧩 **"Select and Order Columns" for Landings and Offers.** Checkboxes, Select
  All, drag-to-reorder by grip handle, localStorage persistence, Restore to
  default; Name stays required. The offers footer totals sum counters/money and
  recompute ratios from the totals.
- 📊 **Report-grade stats columns on the offers table** — leads, sales,
  rejected, conversions-as-events, CR, EPC/CPC (confirmed), revenue / cost /
  P&L / ROI (confirmed) — computed by the same status-group aggregate and
  derive function behind the verified 64-metric reports, pinned by
  `tests/offers_stats_test.php` (28 assertions). `conversions` counts events
  again, matching the reports.

### Fixed
- ✅ Affiliate-network postback URLs: the full template with macros is built
  (scheme follows the panel protocol), editable inline, copyable in one click,
  and shown in the networks list.
- ✅ Campaign groups: the Groups button on Campaigns wrote into `offer_groups`
  and loaded a non-existent `action=groups` — groups never appeared in the
  filter. `groups` is now an alias of `campaign_groups` for older clients.
- ✅ Column customizer (Campaigns): draggable rows swallowed every click, drag
  reorder reshuffled rows under an active search, and Select All selected all
  64 columns regardless of the filter.
- ✅ Local landings open on the first click after upload: single-nested-folder
  ZIPs flatten automatically (`__MACOSX` junk removed) in both landing and
  offer uploads; legacy nested uploads resolve at serve time — page and assets;
  statcache is dropped before entry lookup; the editor's Save blocks with
  "Uploading archive…" until the ZIP lands.
- ✅ Active toggle buttons (Trend/Cohort, metric and view switchers) became
  unreadable on light-accent themes — text now follows `--color-text-inverse`
  per theme (`.btn-primary` too, which exposed and fixed a wrong dark-theme
  inverse value).
- ✅ Offer/Landing editors unified: "Create Offer/Landing" titles, singular edit
  titles, the mislabeled double-"Notes" tab merged into Parameters, one type
  switcher (the method dropdown no longer fights the segments), matching
  footers, quick-create group "+" in both.
- ✅ Empty ghost rows in streams (`{id:'',weight:100}`) render as clickable
  placeholders that open the picker; empty landing/offer lists show dashed
  empty states.
- 🌏 `Asia/Kolkata` (IST, UTC+5:30) selectable in DateRangePicker, profile,
  setup wizard and offer capping; the CET row reads "Berlin / Paris / Rome".

## [0.9.7.9] — 2026-08-16

### Added
- 📚 **Traffic-source and affiliate-network templates from Keitaro.** 196 source and
  395 network templates ship in `data/keitaro_*.json` and merge into the built-in
  lists — 209 sources and 438 networks in the dropdowns, available to every install
  after an update, nothing to import by hand. They are generated from Keitaro's own
  exports by `cli/generate_keitaro_templates.py`, which replaces a hand-written batch
  whose macros and postback hosts were invented: PopAds mapped the click id to
  `[WEBSITEID]` (that is the site), MaxBounty used `&s1=` / `{rate}` instead of
  `s2=` / `#RATE#`, Zeydoo had payout and subid swapped, and RollerAds, Galaksion,
  Kadam and RichAds pointed at postback hosts that do not exist. Such a template looks
  right in the UI and tracks nothing.
- ↔️ **Keitaro status transforms in outgoing postbacks.** `{status: lead=reg sale=dep}`
  now resolves against the internal status (`core/PostbackMacros.php`) — 15 of the
  imported source templates use it and used to send the literal macro. `{clickid}`,
  `{click_id}`, `{offer_id}` and `{conversion_revenue}` joined the S2S macro set the
  same templates rely on.
- 🧪 **`php tests/keitaro_templates_test.php`** — checks both packs against values taken
  from the Keitaro export, so a regenerated pack that drifts fails in CI, not in
  production.
- 🎛 **Custom reports: column customizer, presets and drag-and-drop.**
  `ReportCustomizerModal` adds a searchable column picker with categories (traffic,
  conversions, financial, unit economics), presets (COD, Lander→Offer, Best,
  Finance & ROI, All), up to **five** group-by levels with URL-param dimensions,
  and eq/neq/contains/not-contains filters. Column headers on the campaigns table
  and the report drag-and-drop to reorder.
- 📏 **30+ report metrics** computed in `core/ReportMetrics.php`: unique rate,
  LP CTR, sales/holds/rejected/trash, approve rate (with and without trash),
  confirmed/hold/rejected revenue split, real (aggregator) revenue and ROI,
  CPC/UCPC/CPA/EPC/UEPC, earnings per conversion — with a sticky totals row.
- 📅 **Date-range picker with a real timezone.** Interactive calendar with quick
  presets and a timezone selector; the choice is sent with every dashboard and
  report request and the API shifts all date conditions by it (a fixed offset per
  check — DST edge days on long ranges can differ by an hour).
- 🔗 **Direct URL streams.** A redirect stream can point straight at an external
  URL instead of an offer record, with `{subid}`, `{clickid}`, `{ip}`, `{country}`
  and every captured click parameter substituted (`{subid}` used to travel to the
  affiliate network as a literal).
- 🧭 **Refined cloaking UI** — toggle pills per protection layer, segmented safe-page
  selector (external URL / tracker landing / inline HTML) — plus all redirect methods
  (JS, meta refresh, iframe, form POST, preload, cURL proxy) on the landing editor.
- 💰 **Cost Sync upgrades**: an *Add connection* modal (Facebook / Google Ads /
  TikTok: account id, token, proxy) and manual cost entry for a custom range,
  right from the campaign's Integrations tab.
- 🤖 **Bot list import**: transactional chunked inserts (500 per chunk backend,
  2000 frontend) with a .txt/.csv picker and progress — 50 000+ IPs/UAs import
  without timeouts.
- 🌍 **i18n hardening**: `npm run check:i18n` is green with full parity in all
  7 locales; the report customizer's hardcoded strings are translated.

### Fixed
- 📈 **Report performance**: migration 19 adds `conversions(click_id)`,
  `conversions(click_id, status)`, `clicks(campaign_id, created_at)` and
  `revenue_records(click_id)` — every report metric joins on those, and none had
  an index.
- 🧮 **Purchases counted clicks, not conversions** in the campaigns table; ROI at
  zero spend now shows "—" instead of a made-up 100%.
- 🔄 **The update check no longer pretends everything is fine when GitHub is
  unreachable**: a failed fetch used to silently report latest=current ("no
  update"); the Update page now explains the check failed and gives the manual
  `git pull` command.
- 🤖 **The placeholder metrics are real now** (migration 20): Bots and Bot %
  come from the cloaker's UA signatures checked per click, Proxies from the
  click's IP2Proxy verdict, unique clicks by campaign/flow/global and Visitors
  from honest uniqueness probes (IP + UA per the campaign's method, within its
  window), Empty referrers from SQL, and Time since LP click from the actual
  landing→offer timestamps (`landing_at`/`offer_at`).
- 🧮 **Conversions count events, not flagged clicks** — a click with three
  conversions counted once and CR could never exceed 100%; LP CTR no longer
  counts direct-linked offer clicks; **deposits stopped double-counting as
  sales**; registrations/deposits/revenue-by-status were dead zeros because the
  queries never selected them; eCPC lost its bogus ×1000.
- 🖥 **Reports open flat by default** (one grouping level; deeper drill-down is
  one "+" away) — the layered default made small reports look like duplicated
  subtotal rows. Wide column sets scroll horizontally with the name column
  pinned; the columns selector no longer blanks the screen (hooks order) and
  its buttons no longer show a doubled "+".
- 🎨 **AffiliateNetworkEditor theme-safe**: hardcoded light-only Tailwind
  colors replaced with the design-system variables — the modal now follows all
  five themes; the postback callout tints with the active theme's primary.

## [0.9.7.8] — 2026-08-15

### Added
- 🎯 **Tracking tab in the campaign editor (Keitaro parity).** Every connection
  method is generated with the campaign's id, alias and token baked in: KClient JS
  (with a base64 anti-adblock option), KClient PHP (download button included),
  Tracking Script (`KTracking.reportConversion` / `update` / `{offer}`), banner
  blocks (script + iframe, sized), Campaign URL, link/iframe/script, Tracking
  Pixel, Countdown Timer, Back Button Trap, Exit Intent and WordPress — with
  per-method options that update the code live.
- 📦 **The integration endpoints exist now.** `kclient.php` (KClickClient-
  compatible class over Click API v3, served for download via `?download=1`),
  `kclient.js`, `tracking.js` and `banner.js` ship with the tracker;
  `/pixel.gif` logs impression clicks, registers conversions by subid and merges
  click-parameter updates. The global Integrations page snippets pointed at
  files that did not exist — they 404'd.
- 🔌 **Click API v3: stream content.** Action landings ("Show as HTML/text")
  return their body with `{offer}` resolved to a signed landing→offer transition
  (`info.offer_link`), and clicks capture the full parameter whitelist via the
  shared `ClickParams` — `ad_id`/`adset_id`/`campaign_id` used to be dropped for
  API traffic, silently breaking cost matching.
- 💰 **Cost Sync on the campaign's Integrations tab.** Spend connections
  (Facebook / Google Ads / TikTok) with a *Sync now* button, a per-campaign
  match diagnostic — do the last 7 days of clicks carry the IDs cost import
  matches on? — and the ready-made Dolphin/Fbtool push URL for this campaign.
- 🏷 **Local offers.** ZIP upload with the same security pipeline as landings
  (mime/method checks, PHP gating, scan, cleanup), serving in place of the
  redirect — including the `/?_lp=1` transition — with macros, PHP offers via
  PhpLanding and asset passthrough (`orbitra_lo` cookie).
- ☁️ **Cloudflare integration.** One API token; parked domains whose zone is in
  the account get their A record written automatically (extra A/AAAA records
  cleaned up), proxied domains take SSL from the CF edge and leave the certbot
  queue, and *Re-point all domains* moves every A record when the tracker
  changes servers. New "Domains & SSL" group on the Integrations page;
  `docs/cloudflare.md`.
- 🛡 **Stronger cloaking.** 50+ field-supplied bot UA signatures; daily-updated
  datacenter/crawler IP ranges from lord-alfred/ipranges (~20k CIDRs, IPv4+IPv6
  binary search) that flag a perfect browser UA sitting on a cloud IP;
  self-healing download — the first cloak visit (or a status probe) fetches the
  lists in the background after the response is sent, so existing installs need
  no cron. Automation page gains a status card with an *Update now* button.
- 🔀 **Stream actions: "Send to campaign"** (the backend supported it, the
  editor never offered it) and **"Show text"** with a payload (empty = blank
  white page); `show_html` finally has a field for the HTML itself. Stored as
  `type:payload`, backward compatible.
- 📊 **Layered reports.** Up to three stacked group-by dimensions — Country →
  Campaign → adset_id drill-down with per-level subtotals — plus a global
  across-campaigns report from the Campaigns page. New dimensions: campaign,
  adset/ad/ad-campaign id, offer, landing, OS, browser, day, Sub ID 1–10 (ids
  resolved to names in batch). Columns right-aligned with tabular numerals,
  sticky header and a grand-total row; CSV export carries the layers.

### Fixed
- 💸 **Report cost was hardcoded to 0.00** — profit and ROI were fiction
  whenever spend was imported. Cost is now `SUM(clicks.cost)` and every
  derived metric is recomputed per row, subtotal and total.
- 🗂 **install.sh deleted uploaded local offers** on a re-run over an existing
  installation: `offers/` is backed up and restored exactly like `landings/`.

## [0.9.7.7] — 2026-08-15

### Added
- 🎯 **Facebook is now selectable in the campaign editor.** The '+' next to the
  traffic-source select opens the source editor, so a Facebook (or any) source can
  be created from a template without leaving the campaign. Picking a traffic source
  auto-fills the campaign's URL parameters with the source's macros
  (`ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}…`) — the
  Keitaro behaviour: switching a source replaces the parameter set. Parameters now
  persist (`campaigns.parameters_json`, migration 18) instead of living only in
  browser memory, and the built campaign URL carries them as a query string.
- 🧩 **Integration code snippets** on the campaign's Integrations tab: Keitaro-style
  link / iframe / script tags built from the campaign URL, with referrer/title/
  query-string pass-through, ready to paste into site builders.
- 🎵 **TikTok Ads cost engine.** `aggregator_engines/TikTokAdsEngine.php` pulls
  daily spend from the TikTok Business API (Access Token + Advertiser ID, optional
  proxy), attributes it to clicks by `ad_id`/`adgroup_id`/`campaign_id` — the keys
  the TikTok traffic-source template writes (`__CID__`, `__AID__`,
  `__CAMPAIGN_ID__`, plus `ttclid=__CLICKID__`). Same cron, same cost_records, same
  flat-CPC distribution as the Facebook engine.
- 📥 **Keitaro-compatible `update_costs` endpoint (Dolphin / Fbtool).**
  `POST /admin_api/v1/campaigns/{id}/update_costs` with
  `Authorization: Bearer <personal API key>` accepts the exact payload Dolphin and
  Fbtool.pro send to Keitaro: `{start_date, end_date, cost, currency, timezone,
  filters:{sub_id_4:…}}`. Filters match click parameters (`sub_id_3`→`adset_id`,
  `sub_id_4`→`ad_id` — the Keitaro Facebook-template defaults — or any param name
  directly); the amount is
  converted to the tracker currency and split evenly across matched clicks, and a
  re-sent period overwrites rather than accumulates. Unmatched amounts are parked
  in `cost_records` under a hidden `external_api` connection so nothing is lost
  silently. Routing lives in `.htaccess` and `core/nginx_config.php`;
  `docs/dolphin-fbtool.md` documents the setup on both services' sides.

### Fixed
- 🪟 **The '+' buttons that never worked.** Campaign editor (group, traffic
  source), offer editor (group, affiliate network) and the 'Группы'/'Источники'
  buttons on the Campaigns and Landings pages were committed without click
  handlers back in the initial commit; all now open the right dialog, refresh the
  select and auto-pick the created item.
- 🗑 **Group deletion for every type.** Only offer groups could be deleted;
  `delete_campaign_group` and `delete_landing_group` reset the linked rows and
  remove the group. GroupsModal was also hardcoded to light-theme Tailwind colors
  and rendered washed-out in dark themes — it now uses the app's CSS variables.
- 🛬 **Keitaro import: traffic-source parameters were stored as the raw Keitaro
  blob.** Keitaro keeps them PHP-serialized (or JSON, depending on the version)
  with its own field names; Orbitra's `ClickParams` could not read that shape, so
  imported sources never captured `ad_id`/`adset_id`/`campaign_id` on clicks and
  cost matching had nothing to match. Parameters are now normalized into
  `{alias, param, macro}` entries; landing groups (`keitaro_groups` type
  `landings`) are imported and attached to landings.

## [0.9.7.6] — 2026-08-15

### Added
- 📤 **Facebook Conversions API.** Conversions are now sent to Meta from the server,
  not only from the browser pixel — the events ad blockers, ITP and iOS strip out
  reach the optimiser again. Configured per campaign (Integrations tab): pixel ID,
  Conversions API token, a status→event mapping, optional test event code and proxy.
  Events carry `fbc`/`fbp`, the click's IP and user agent, and SHA-256 hashed
  email/phone/name/geo, with an `event_id` that deduplicates against the browser
  pixel. Delivery rides the existing S2S queue, so a slow answer from Meta never
  delays the reply to the affiliate network's postback. A pixel without a token
  stays browser-only, and `rejected`/`trash` are unmapped by default.
- 💱 **Ad spend is converted to the tracker's currency.** Meta and Google bill in the
  ad account's currency, which was previously written into `clicks.cost` as-is and
  silently mixed with revenue in another currency. Rates are fetched and cached for
  12 hours; `fx_rates_manual_json` pins them manually. The platform's original
  amount and currency are kept in `cost_records.raw_json` for audit.
- 🧪 `tests/facebook_integration_test.php` — end-to-end coverage of capture,
  attribution, currency, idempotency and CAPI payload construction. No network.
- 🧭 **Both integrations now live on the Integrations page**, as their own entries
  with full management: account list with status and next update, add/edit,
  *Update spend* (a manual sync), pause/resume, clone, delete and search — the
  place a Keitaro user actually looks for them. Cost connections still appear on
  the Aggregators page and a campaign's pixel is still editable from the campaign
  itself; these are views of the same records, not copies.
- 📖 `docs/facebook.md` — setup, macros, token issuance, mapping, troubleshooting.

### Fixed
- 💰 **Facebook cost import fetched nothing at all.** The insights request asked for a
  field named `currency`, which does not exist on that endpoint — Meta rejects the
  whole request when one field identifier is wrong (error 100), so every sync
  returned zero rows. The field is `account_currency`.
- 🔇 **A failing sync reported success.** `fetchRecords()` swallowed transport and API
  errors and returned an empty array, so an expired token showed up in the UI as
  "success, 0 records". Errors now propagate and land in `last_sync_error` with
  Meta's own message.
- 🎯 **Clicks never recorded the ad IDs cost import matches on.** The traffic-source
  templates advertised `{{ad.id}}` and `{{adset.id}}`, but click logging only kept a
  fixed list of `sub_id_*` keys and dropped everything else — so imported spend could
  not be attached to any click and campaigns showed cost 0. Capture now lives in one
  shared helper (`core/ClickParams.php`) used by both the redirect path and the Click
  API, and covers the ad-network IDs, the platform click identifiers (`fbclid`,
  `gclid`, `ttclid`, …), the `_fbp`/`_fbc` cookies, and any parameter the campaign's
  traffic source declares.
- 🎯 **Spend was never attributed at adset level.** Matching went straight from ad ID
  to campaign ID, so `{{adset.id}}` — the one parameter Keitaro requires — did
  nothing, and any campaign whose ad IDs the tracker had not seen fell back to
  campaign-level or went unmatched. The chain is now ad → adset → campaign, and a
  connection can point each level at a different click parameter (`ad_id_param`,
  `adset_id_param`, `campaign_id_param`) for traffic that passes through an app.
- 📅 **The sync window was too short to be correct.** Two days of lookback froze
  restated spend at whatever it was when first read. Cost connections now re-read
  the last 5 days, and 30 days on their first sync.
- 📄 **Pagination could stop early.** The cursor was rebuilt by hand instead of
  following Meta's `paging.next`, so a parameter set that drifted dropped pages
  silently. Page size raised from 200 to 500.

### Changed
- Facebook connections gained **API version** and **proxy** settings — Meta
  periodically geo/IP-filters requests coming from a tracker's server IP.
- **Test connection** now reads the ad account (name, currency, timezone, status)
  instead of an insights report, which could come back empty and valid for a dead
  token. A disabled or closed account is reported as a failure.
- Existing pixels can be **edited**, not only created and deleted.
- The cost sync log line reports the date window, unmatched count and currency, and
  warns explicitly when spend was imported but matched zero clicks.
- New indexes on `clicks(date(created_at))`, `cost_records(connection_id, external_id)`
  and the postback queue's pending lookup (schema v17).
- All new interface strings are translated in every locale (en, ru, uk, es, de, fr, zh);
  `npm run check:i18n` passes with all seven in parity. Aggregator connection fields now
  carry a `label_key` alongside their English text, so engine form labels come from the
  locale files instead of being hardcoded in the PHP engine.

## [0.9.7.5] — 2026-08-13

### Fixed
- **`install.sh` installs PHP's `bcmath` extension.** `ip2location/ip2location-php`
  and `ip2location/ip2proxy-php` both declare `ext-bcmath` as a hard requirement,
  so on a server without it Composer rejected the entire lock file with "Your lock
  file does not contain a compatible set of packages" and no dependency was
  installed. A version-pinned fallback covers servers whose PHP CLI is not the
  distribution default.
- **A failing step no longer leaves the installation permanently un-updatable.**
  The script runs under `set -e`, so the Composer failure above aborted it before
  the closing `chown -R www-data:www-data` — the tree stayed root-owned and the
  update button reported that part of the directory belongs to another user. The
  ownership handover now runs from an `EXIT` trap and therefore happens on every
  exit path, and the Composer and frontend build steps are no longer fatal.
- **Admin-panel updates survive a missing `bcmath`.** All three Composer call
  sites go through one helper that retries with `--ignore-platform-req=ext-bcmath`
  and reports the single command that installs the extension, instead of failing
  the update and printing every `php.ini` path on the server.

### Changed
- The installer exports `COMPOSER_ALLOW_SUPERUSER=1`, so the root warning no
  longer looks like an installation error in the log.
- The manual fallback command shown when `exec()` is disabled now begins with
  `sudo apt-get install -y php-bcmath`.

## [0.9.7.4] — 2026-08-11

### Added
- **IP2Proxy LITE PX12 and IP2Location ASN LITE support.** Both databases have
  separate status/update entries, use the existing IP2Location download token,
  and feed proxy/VPN/Tor/datacenter/threat/fraud-score and ASN/ISP signals into
  live cloaking and Traffic Simulation through the official provider readers.
- **Keitaro-style `Bot: Yes` stream filter.** It is available in the campaign
  editor and uses the same ASN, PX12, blocklist and browser detector in direct
  campaign traffic, Traffic Simulation and the Keitaro-compatible Click API.
  An intercepting `Bot: Yes` + `Do nothing` stream can therefore hide embedded
  content while clean traffic continues to the regular streams.
- Existing Git installations automatically install locked Composer dependencies
  after a successful update from the admin panel.

### Fixed
- **Universal Geo DB uploads no longer confuse provider formats.** Orbitra
  identifies DB11, ASN, PX12, MMDB and Sypex files before installation, stores
  each in its own slot, migrates PX12 files previously misplaced as DB11, and
  rejects impossible latitude/longitude values.
- **Apache campaign aliases now work at `/campaign-alias`.** The root short URL
  is forwarded to the tracker just like `/r/campaign-alias`; real files and
  directories remain untouched.
- Removed the Sypex-only hint beside the universal database upload control.

### Changed
- The manual Git-update card now shows the complete safe fallback: `git pull
  --ff-only` followed by the locked production Composer install. All seven
  interface locales contain the updated guidance.

## [0.9.7.3] — 2026-08-11

### Added
- **Real Cloak verdicts in Traffic Simulation.** The simulator accepts ASN/ISP,
  JavaScript-executed and `navigator.webdriver` inputs, runs the same passive
  detector as live traffic, and explains why the result is the safe or money page.
- A regression test reproduces an update followed by a conflicting `stash pop`,
  verifies automatic repair, and proves that the next pull succeeds normally.

### Fixed
- **Admin updates stuck on `unmerged files`.** Before pulling, the updater now
  detects unmerged index entries directly, aborts unfinished Git operations and
  restores a clean `HEAD`. It no longer depends only on matching Git's error text.
- **A failed stash restore poisoned every future update.** Local code changes stay
  saved in the Git stash, while the half-applied conflict is reset immediately so
  the repository never remains in an unresolved state.
- Installations already stuck in an unresolved state on `0.9.7.2` need one SSH
  recovery to receive this updater code:
  `sudo -u www-data git -C /var/www/orbitra reset --hard HEAD && sudo -u www-data git -C /var/www/orbitra pull --ff-only origin main`.
  Later conflicts are repaired by the admin panel automatically.
- **Cloaking false positives on residential traffic.** Generic substring matches
  no longer classify Comcast, CloudMTS or InterServer as hosting providers, while
  known datacenter ASNs and precise cloud-provider names remain blocked.

## [0.9.7.2] — 2026-08-11

### Added
- **Landing-to-Offer transition metrics** (`lp_clicks` & `lp_ctr`) in Landings analytics API and UI table view.
- **Cloudflare Turnstile** anti-bot verification support in bot challenges.
- **Direct stream filter editing**: click any filter item in Campaign Editor to modify values/countries without deleting and re-creating.
- Full 100% key parity across all 7 supported UI locales (`ru`, `en`, `de`, `es`, `fr`, `uk`, `zh`).

### Fixed
- **Intercepting streams URL resolution**: fixed `$finalUrl` and redirect type calculation in `landing_offer` and `cloak` (safe page & money page) schemes so missing/invalid landing URLs fall back to the offer URL instead of raising `URL not found.`.
- **Prefetch false positives**: removed broad `no-cors` check in `core/prefetch.php` that improperly blocked legitimate user navigations over VPNs and mobile browsers.

## [0.9.7.1] — 2026-08-10

### Added
- **Cohort analysis** (new *Analytics* tab, was *Trends*). Campaigns are grouped
  by the month or quarter they were created, and each cohort is tracked across
  its lifetime periods (M0, M1, M2…):
  - **Retention curves** — one line per cohort decaying/growing across M0..Mn,
    the canonical cohort chart.
  - **Heatmap matrix** with an **Absolute / % of M0** toggle. Retention mode
    normalises each cohort to its first period so cohorts of very different
    sizes can be compared by decay shape.
  - **Per-cohort summary** with totals, ROI, and first/last active period.
  - Revenue and conversions are attributed to the period the event occurred,
    not the click period — delayed payouts no longer collapse into the launch
    month. CR is the share of clicks that converted (0–100%).
- **Browser-language detection** for first-time visitors: the UI now opens in
  the language the browser reports instead of a hard-coded Russian default. The
  same `'ru'` fallback was removed across user creation, login and profile
  settings.

### Fixed
- **click.php** returned a bare HTTP 500 on any campaign without a configured
  stream/offer (`FOREIGN KEY constraint failed` on `offer_id = 0`). Clicks now
  log with `offer_id NULL`, and the click path is wrapped in try/catch so any
  future failure returns a JSON error + a `system_logs` row instead of a silent
  empty-body 500.
- **Trends** only plotted days that had traffic, so a single active day looked
  "stuck" at the X origin. Days and hours are now zero-filled across the
  selected range, matching the dashboard.
- **i18n**: hardcoded Russian labels in the Trends chart tooltip, and
  browser-locale date/number formatting in Cohort, now follow the selected UI
  language across all seven locales (en, ru, uk, es, zh, fr, de). The heatmap
  intensity scale reads `--color-primary` via `color-mix`, so it adapts to every
  theme (light/dark/green/neon/custom) instead of a hard-coded coral hex.

## [0.9.7.0] — 2026-08-10

### Added
- **A local landing is served at its own `/lander/<slug>/`, matching Keitaro.**
  The Folder field advertised that URL from the day slugs landed, but nothing
  answered it: a landing's files were reachable only during a real click,
  resolved from the `orbitra_lp` cookie, so there was no way to look at a landing
  without sending traffic through a campaign. Like Keitaro, the served HTML gets
  a `<base>` tag injected so the page's relative paths (`img/a.png`) resolve
  inside its folder — which is why Keitaro's own requirements say a landing must
  not ship a `<base>` of its own. Assets go through the same extension whitelist
  and path containment the click flow uses; `.php` is not served or executed
  there, since a PHP landing needs the click context this route has none of.
  Nothing is logged: this is a look at the landing, not a visit to a campaign.
  On Apache the route also needs a rewrite: `.htaccess` only forwarded `/r/` and
  the Click API to `index.php`, with no catch-all, so `/lander/<slug>/` matched
  nothing on disk and Apache answered its own 404 before PHP ran. Nginx installs
  were unaffected — their `try_files` already falls through to `index.php`.
- **Code / Preview toggle in the landing editor.** Preview loads the landing from
  `/lander/<slug>/` in an iframe, so it is the page as a visitor receives it —
  images, video, CSS and scripts included — rather than a rendering of the HTML
  on its own. Switching to it forces a reload, so it never shows the state from
  before the last save or upload, and a button opens the same URL in a new tab.
- **The Domains page says why SSL is unavailable instead of leaving it to guess.**
  On shared hosting where `shell_exec` is removed or Certbot is not installed, a
  parked domain sat at "waiting for certificate" with no indication that the
  server can never issue one. The page now checks the server's capability once
  on load — shell, Certbot, nginx config, writable ACME directory — and shows a
  banner naming the blocker when issuance is impossible, so the operator knows
  to use a dedicated VPS or issue through their hosting instead of waiting.


  `chmod +x /var/www/orbitra/cli/*.php`, and git tracks the executable bit — so
  those files were permanently "locally modified" and any update touching one of
  them stopped with *your local changes would be overwritten by merge*. The bit
  bought nothing: the scripts are invoked as `php <path>`. It is no longer set,
  `core.fileMode` is turned off for the repository, and the panel's update button
  turns it off too, so installs that already carry the mode change can update
  without being reinstalled.
- **PHP called `shell_exec` directly in 46 places.** On hosts where the function
  has been removed rather than merely disabled, PHP 8 raises an `Error` that `@`
  does not suppress — so saving a domain, syncing the nginx config or checking
  for Certbot died as a bare 500 with no explanation. Everything goes through
  `core/shell.php` now: `orbitraShell()` returns `null` instead of fataling,
  `orbitraShellAvailable()` lets a feature check before it starts, and
  `orbitraRemoveDirectory()` replaces the two `system('rm -rf …')` calls that had
  the same problem. Certificate issuance checks for a usable shell and for
  Certbot before touching any domain, rather than marking domains failed for a
  reason that has nothing to do with them.
- **Nothing could start issuance from the panel.** The queue was worked only by
  cron and by a process spawned in the background on save, both of which need
  `shell_exec` — disabled on a great many hosts. Where it is, every domain sat at
  "pending" with nothing to click and no indication why. The Domains page now has
  an *Issue SSL* button that runs the worker inside the request and reports back
  how many certificates were issued, how many are waiting on DNS and how many
  failed — naming the blocking reason outright when Certbot is missing,
  `shell_exec` is disabled, or the server is not running nginx.
- **A certificate was attempted once and never again.** Issuance was a single
  background shot fired when a domain was saved with HTTPS-only ticked, and
  nothing was ever scheduled to run it a second time — `install.sh` installs no
  cron at all. Minutes after pointing an A record, DNS has not propagated, so
  that one attempt failed, the domain was marked `failed`, and it stayed on a
  broken certificate until a human reopened and re-saved it. Certificates are now
  worked by `core/ssl_manager.php`: every parked domain is a candidate, the
  domain's A record is compared with this server's address *before* Certbot runs
  so a domain that cannot validate never spends one of Let's Encrypt's five
  failures per hostname per hour, and a failure is rescheduled on a widening
  ladder (1h, 1h, 2h, 6h, then 12h) rather than being final. A domain still gets
  its certificate immediately on being added; the hourly pass exists only to
  finish what could not be issued then.
- **Issuance was tied to the HTTPS-only toggle.** Adding a domain without it left
  `ssl_status` at `none`, which meant no certificate was ever requested, and
  turning it off later reset the status and dropped the domain out of the queue.
  Parking a domain is now the request for a certificate, as it is in Keitaro;
  HTTPS-only only decides whether `http://` redirects to `https://`.
- **A domain could show an SSL tick while the browser was served the catch-all's
  self-signed certificate.** `ssl_status` was set to `installed` the moment a
  certificate appeared under `/etc/letsencrypt/live/<domain>/`, and nothing ever
  checked whether nginx was actually serving it — the HTTPS server block for a
  domain is written only when the config is regenerated with its `fullchain.pem`
  already on disk. `cli/ssl_installer.php` made this reachable: when it found a
  certificate already present it marked the domain installed and `continue`d,
  skipping the config rebuild entirely. `check_ssl_status` now reconciles every
  answer against the filesystem and the live config, returns `cert_present` and
  `https_active` separately, and rebuilds the config once when a certificate
  exists that nothing is pointing at. The installer reconciles once per run
  instead of per domain, including on the path where the certificate was already
  there.

### Changed
- **The campaign stream no longer carries its own copy of the landing form.** It
  had one — 271 lines duplicating `LandingEditor` — which is why the two behaved
  differently: the stream's copy took a ZIP while creating a landing and the
  Landings page did not, and every fix had to be written twice. The stream now
  renders `LandingEditor` and receives the saved id through a new `onSaved`
  callback to wire the landing into its rotation. `CampaignEditor` loses 309
  lines along with the state that existed only to feed the copy (landing groups,
  the campaign list, the postback key, the offer-link hint) and three API calls
  it made on every open.

### Fixed
- **Creating a local landing from the Landings page gave no way to upload the
  archive.** The form said "save the landing settings first to upload archive
  files" and then closed the window, so the file panel it was pointing at could
  only be reached by finding the landing in the list and reopening it. Saving now
  keeps the editor open and switches it to edit mode in place, and the create
  form takes a ZIP directly — held until the landing has an id, then uploaded.
- **The update button failed with `unable to unlink old
  'frontend/dist/assets/index.js': Permission denied` on every install made by
  `install.sh`.** The script chowned the tree to `www-data` and *then* built the
  frontend as root. Vite empties and recreates `frontend/dist` on each build, so
  the bundle and its directory came out root-owned — and replacing a file
  requires write permission on its directory, not on the file, so `git pull`
  running as the web user could never get past it. The same applied to `.git`
  and `mcp/node_modules`. The chown now runs last, after the frontend build and
  the MCP dependency install. Existing installs need one manual
  `chown -R www-data:www-data /var/www/orbitra`; after that the button works.
- **`run_update` passed git's raw permission error through.** It already
  translates "dubious ownership" into an actionable message; a permission
  failure now gets the same treatment — the cause and the `chown` command to fix
  it, rather than git's wording. Anything stashed before the pull is restored
  before the handler returns.
- **"ZIP upload error" was the only thing a failed archive upload ever said.**
  `handleZipUpload` alerted a fixed string from its `catch`, so every non-2xx
  answer — a 500 from the handler, a 413 from nginx, a request that never
  finished — arrived as the same four words. `upload_landing` now names each
  failure: the body exceeding `post_max_size` (with the actual sizes), each
  `UPLOAD_ERR_*` code in words, a missing `zip` or `fileinfo` extension with the
  package to install, a `landings/` directory the web server cannot write to
  (with the `chown` command), and the MIME type actually detected when the file
  is not a ZIP. The whole handler is wrapped so a `Throwable` returns JSON
  instead of a 500.
- **A failed extraction was reported as a successful upload.** `mkdir()` and
  `ZipArchive::extractTo()` both had their return values discarded, so when the
  landing directory could not be created or written, the panel said the archive
  was extracted and the landing quietly served nothing. Both are checked now.
- **An archive PHP cannot decompress looked like a permissions problem.** The
  "maximum compression" preset in 7-Zip and WinRAR writes LZMA, BZip2 or PPMd
  entries; libzip is normally built with Store and Deflate only, so the archive
  opens and lists correctly and only `extractTo()` fails. A failed extraction
  now inspects each entry's compression method and, when it is one PHP cannot
  read, names it and says to repack with Deflate.
- **The landing editor swallowed the server's message on every file
  operation.** Reading, saving and uploading a file inside a landing each
  alerted a generic string. They all show what the server said, falling back to
  the HTTP status and only then to "network error".
- **A certificate the browser rejected could show as installed.** A
  `fullchain.pem` that holds only the leaf — missing the intermediate — is the
  single most confusing TLS state: Firefox fills the gap from its own store and
  opens the site, while Chrome and curl fail with *unable to get local issuer
  certificate*. That happens when a config pointed at `cert.pem`, a manual edit
  truncated the file, or a certbot run wrote leaf only. `core/ssl_manager.php`
  now counts the `BEGIN CERTIFICATE` blocks in the chain on every run: fewer
  than two marks the domain `failed` with a named reason (`incomplete_chain`)
  instead of `installed`, both right after issue and on the later pass that
  catches a file that went wrong after the fact. nginx is never pointed at a
  half-chain.
- **Server-side error messages were hard-coded in Russian.** `upload_landing`
  and the SSL worker returned whole sentences in Russian — the DNS mismatch
  detail, the Certbot-no-output fallback, every archive-upload error. In a panel
  that ships seven locales, a backend string the frontend cannot translate is a
  bug, not a stylistic preference. The backend now returns machine codes
  (`dns_mismatch`, `certbot_no_output`, `not_a_zip`, `zip_unsupported_compression`,
  …) plus a `detail` object of the measured facts (sizes, addresses, the MIME
  type detected), and the frontend phrases them per locale. Certbot's own output
  is diagnostic text and passes through untranslated.

## [0.9.6.8] — 2026-08-10

### Fixed
- **Creating a landing failed with "network error" on servers without the
  `intl` extension.** A local landing left with an empty *Folder* field derives
  its slug from the name, and that derivation called
  `transliterator_transliterate()` — a function only `php-intl` provides, which
  `install.sh` never installed. On PHP 8 calling a function that does not exist
  raises an `Error`, and `@` does not suppress it, so `save_landing` died as a
  bare 500. Slugs now transliterate through a built-in Cyrillic/Latin table
  (with `iconv` as a second pass) whenever `intl` is missing, producing the same
  slug it would have produced with `intl` for the alphabets that reach this code.
- **A failed landing save reported "network error" regardless of the cause.**
  `save_landing` could let a `Throwable` escape as a 500, and the landing forms
  alerted a fixed string on any thrown request — so a PHP fatal, a rejected slug
  and a genuinely unreachable server all read identically. The endpoint now
  answers `{status: "error", message: …}` and logs the fatal, and both landing
  forms show the server's own message, falling back to the HTTP status and only
  then to "network error".
- **Slug errors were shown as raw codes.** `landing_slug_taken` and friends are
  stable codes so each locale can phrase them, but the forms alerted the code
  itself. They are translated now, in all seven UI languages.
- **An auto-generated slug that collided rejected the save.** A landing named
  after an existing one — or named `lander` — was refused for a folder name the
  operator never typed. A derived slug that is taken or reserved now falls back
  to `name-2`, `name-3`, …, and to `landings/<id>/` if nothing is free. A slug
  typed by hand still reports the conflict rather than being silently changed.

### Changed
- `install.sh` installs `php-intl`. It is optional — the fallback table covers
  Russian, Ukrainian and Latin diacritics — but with it every alphabet
  transliterates. System status reports whether it is loaded.

## [0.9.6.7] — 2026-08-08

### Fixed
- **Creating a local or action landing failed with a NOT NULL constraint
  violation.** The `url` column on `landings` is `NOT NULL`, but local and
  action landings legitimately have no URL, and `save_landing` passed `null`
  through whenever the caller (the MCP tools, the old quick form, the new
  stream modal) did not send one. The field now defaults to an empty string,
  so every caller saves cleanly. Surfaced by the v0.9.6.6 stream modal, which
  sends no URL for those two types.

## [0.9.6.6] — 2026-08-08

### Added
- **Create and edit a landing from inside a campaign stream.** The quick modal a
  stream opened (name / type / URL) is now the full landing editor: four type
  tabs (Local / Redirect / Preload / Action), group and status, a named folder
  for local landings, a redirect-method selector, the complete Action block
  (send-to-campaign / 404 / text / HTML / nothing), and the offer-link hint with
  a copy button and HTML/JS/PHP formats for redirect landings. A new Edit button
  next to each landing in the stream opens the existing landing in the same
  modal, so the full form is reachable from where the landing is wired up — not
  only from the dedicated Landings page. The misleading
  `window.location.replace('{offer}')` placeholder has been removed from the
  Action text/HTML fields.

## [0.9.6.5] — 2026-08-08

### Added
- **The landing create/edit form now matches Keitaro.** The landing type is
  chosen from four tabs (Local / Redirect / Preload / Action) instead of a
  dropdown, matching the offer editor's tab pattern.
- **Named folders for local landings.** A local landing's files used to unpack
  into `landings/<id>/` — functional but opaque. Landings now carry a `slug`,
  and the directory is `landings/<slug>/` (shown as `/lander/<slug>` in the
  editor). The slug is the single source of truth resolved through one helper,
  `orbitraLandingDir()`, so every path-bearing endpoint (upload, file list,
  file operations, asset serving) follows it. The visitor never controls the
  slug: the cookie carries the landing's numeric id and the slug is looked up
  server-side, so a request cannot be aimed at an arbitrary directory. Existing
  landings are backfilled a slug from their name on migrate (transliterated,
  uniqueness ensured), and renaming the slug in the editor moves the folder.
  An empty slug falls back to `landings/<id>/`, so nothing ever breaks.
- **Redirect method for redirect landings.** A redirect landing can pick its
  method — HTTP 302, JavaScript, or Meta refresh — the way an offer already
  could. The chosen method applies on the final hop to the landing URL.
- **Copyable offer-link snippets in three formats.** The offer-link hint now
  carries a copy button on every snippet, is shown for redirect landings too
  (not only local/preload), and for a redirect landing offers the three
  integration shapes an external page needs: plain HTML (`<a href="{offer}">`),
  `document.write` JS (carrying `window.location.search`), and server-side PHP
  (carrying `_token`). Localised across all seven UI languages.

## [0.9.6.4] — 2026-08-08

### Security
- **Five System Settings fields were never saved.** `global_settings` had a
  hardcoded key whitelist that accepted eight settings and silently discarded
  five more that `SystemSettings.jsx` sends: `stats_enabled`,
  `stats_retention_days`, `archive_retention_days`, `admin_ip_access` and
  `ignore_prefetch`. The form showed them, the save reported success, and the
  values were thrown away — so every value the operator believed they had set
  was still the default. Three of them were never read by anything at all.
  All five are now whitelisted for read and write, validated (booleans
  normalised, retention windows clamped, the access list parsed before it is
  stored), and — where they describe behaviour — actually enforced.
- **`admin_ip_access` advertised access control that did nothing.** The panel
  showed it under a shield icon as an IP allow-list for the admin surface, but
  no code ever read it. It is now a real allow-list: an empty value leaves the
  panel open to everyone (the default, unchanged), a populated list restricts
  both `admin.php` and the authenticated `api.php` surface (sessions *and* API
  keys) to the listed IPv4/IPv6 addresses and CIDR ranges. The check runs
  before the session is created, so an unlisted client never reaches a login
  form. First-time setup (no users yet) is exempt, so the operator cannot lock
  themselves out before creating the first account.
- **Any signed-in user could read files outside a landing's folder.** The landing
  file endpoints interpolated the landing id into the path as a string, so
  `?action=get_landing_file&id=..&path=config.php` pointed the "allowed" root at
  the application directory and returned `config.php` — the postback signing key
  and the database path — as well as `api.php` itself. The containment test was
  also a bare string prefix, which treats `/landings/12` as living inside
  `/landings/1`. Both are gone: the id is cast to an integer, the relative path is
  normalised with any `..` segment rejected outright, and an existing file is
  re-checked through `realpath()` so a symlink cannot lead out either. The same
  resolver now serves every landing file operation.
- **A bare `_subid` in the URL was accepted at the landing→offer transition.**
  `/?_lp=1` resolved the original click first by signed `_token`, then by
  cookie, then — as a documented "Keitaro-shaped" fallback — by an unsigned
  `_subid` query parameter. That last step let anyone attribute a visit to any
  click id they liked, since `_subid` carried no proof. The fallback is
  removed: a signed `_token` (for landings on another domain) or the tracker's
  own cookie (for local/preload landings) are the only accepted sources. The
  documentation's claim that an unsigned click id is refused now matches the
  code.

### Added
- **The `{offer}` macro in local landings.** A landing's buy button is written the
  way it is in Keitaro — `<a href="{offer}">Buy</a>` — and the tracker substitutes
  the URL of the offer bound to the stream, click id included. `{offer_id}`,
  `{clickid}`, `{subid}`, `{token}` and every click parameter are substituted too,
  with values taken from the URL escaped. No other braces are touched, so JS
  template literals, Vue and Angular syntax inside a landing survive. With no offer
  on the stream `{offer}` becomes `/?_lp=1` rather than an empty link. Note that
  `{offer}` expands to the advertiser's URL, so choosing between several offers
  goes through `/?_lp=1&offer_id=N`, which also re-attributes the click.
- **Landing actions, as five choices instead of a free-text field.** Send to
  campaign, show a 404, show as text, show as HTML, do nothing — available both on
  a landing of type Action and on a stream with the Action schema, which
  previously understood two of them and silently did nothing for the rest.
  Existing action landings keep their behaviour: a payload becomes "show as HTML",
  an empty one becomes "do nothing".
- **`_token` for landings hosted elsewhere.** A redirect landing lives on another
  domain and cannot read the tracker's cookies, so its offer link had no way to
  say which click it belonged to and simply failed. The tracker now appends
  `_subid` and a signed `_token` (HMAC-SHA256, 24 hours) to the landing URL, and
  `/?_lp=1` accepts the token in the cookie's place. Unsigned click ids from
  strangers are refused.
- **JS adapter** (`js/orbitra-adapter.js`). Carries the click onto a landing's
  inner pages and forms, and exposes `orbitraPostback()` so a thank-you page can
  report a conversion with no affiliate network in between. Unlike its Keitaro
  counterpart it leaves `#anchors`, `mailto:`, `tel:` and `javascript:` links
  alone — rewriting those is what breaks popup forms and smooth scrolling — and
  never hands the click id to a third-party domain.
- **Create a landing or an offer without leaving the stream.** The schema editor
  gained "Create landing" and "Create offer" next to "Add"; the new item drops
  straight into the stream, and the cached dropdown is refreshed so it appears.
- **File operations in the landing editor**: create, upload, rename, move and
  delete. Writes are limited to a whitelist of extensions, so `.php` and
  `.htaccess` cannot be created, uploaded, or arrived at by renaming an HTML file.
- **Offer selection before or after the click.** A stream can defer the choice to
  the moment the visitor leaves the landing, so a slot that fills up while they
  read is not already spent on them. The click is logged without an offer and is
  attributed when `/?_lp=1` fires.
- **PHP landings**, off by default and enabled per instance in
  Settings → General. Entry point `index.php`, `$rawClick->get('parameter')` for
  the click, and an execution timeout of 1–9 seconds so one slow landing cannot
  tie up the workers the whole site shares. Uploads are scanned with PHP's own
  tokenizer for shell, `eval` and timeout-defeating calls, and a failing archive is
  removed rather than left half-installed. The scan is a speed bump, not a
  sandbox — `disable_functions` and `open_basedir` in `php.ini` are what actually
  contain a landing, and the documentation says so.
- **[docs/landing-pages.md](docs/landing-pages.md)** — one page covering all four
  landing types, the macros, the adapter, PHP landings and the mistakes that
  produce each error message.

### Fixed
- **The panel became unreachable at the server IP once a domain was parked.**
  Two causes, one symptom. `updateNginxConfig()` rewrote the site config with
  `server_name <parked domains>` and no catch-all block, so nothing explicitly
  owned requests addressed to the bare IP; and SSL was issued with
  `certbot --nginx`, whose installer plugin then edited that same file —
  narrowing `server_name` to the domain being issued and appending
  `return 404`, which the IP request landed on. It came back on every renewal.
  Deleting the domain you always used left no way in short of remembering which
  other domains were parked.

  The generated config now always begins with a
  `listen 80 default_server; server_name _;` block, so access by IP is
  structural rather than incidental, and certificates are obtained with
  `certbot certonly --webroot` — Certbot never touches nginx again. Orbitra
  writes the HTTPS server blocks itself, as it already did.
- **HTTPS on the server IP served a parked domain's certificate.** Let's Encrypt
  does not issue for bare IPs, so `https://<ip>/admin.php` matched whichever
  domain owned the first 443 block and failed on a name mismatch. The installer
  now generates a self-signed certificate for the IP and gives it a
  `listen 443 ssl default_server` block: the browser still warns, but the panel
  opens.
- **`/.well-known/acme-challenge/` was blocked by the dotfile deny rule**, so a
  webroot certificate could never be issued. It is now served from an explicit
  `location ^~` above the deny.
- **The config hardcoded `php8.3-fpm.sock`**, so on any other PHP version the
  first domain save produced a config that failed `nginx -t`. The socket is
  detected.
- **A failing config was installed anyway.** The old writer staged the new
  config as `orbitra.tmp` — a file nginx does not include — then ran `nginx -t`,
  which therefore tested the *old* config, and renamed the untested file into
  place. A bad generation could leave a server that would not come back up after
  a restart. The new config is tested where nginx actually reads it, and the
  previous one is restored if the test fails.
- **The installer, the panel and the recovery scripts each generated their own
  config**, and the three had drifted apart. They now share
  `core/nginx_config.php`.

### Added
- **`cli/nginx_sync.php`** — one command that rebuilds the web-server config
  from the database, repairs renewal configs left behind by the old
  `certbot --nginx` (otherwise the next renewal re-breaks IP access), and
  generates the self-signed certificate:

      sudo php /var/www/orbitra/cli/nginx_sync.php

  Existing installations should run it once after updating. `fix_nginx.sh` and
  `restore_https.sh` are now thin wrappers around it.
- **Configurable admin panel path.** *Settings → System → Admin panel path*
  moves the panel from `/admin.php` to `/your-path`, after which `/admin.php`
  answers 404 — so the login form is not sitting at the one URL every scanner
  tries against an IP range. This hides the panel; it does not replace the
  password, and `/api.php` still answers and enforces its own authentication.
  The way back in if the path is forgotten:

      php /var/www/orbitra/cli/admin_path.php reset

### Added
- **Data retention is now enforced.** The *Log retention* and *Archive retention*
  fields in System Settings were saved but never acted on — clicks, conversions
  and archived campaigns accumulated forever. `cli/cleanup_cron.php` purges them
  in chunks (so a multi-million-row table is not locked under one statement),
  honouring the configured windows, and is meant to run once daily:

      0 3 * * * php /var/www/orbitra/cli/cleanup_cron.php >> /var/log/orbitra_cleanup.log 2>&1

  Conversions are deleted both by their own `created_at` and via the
  `ON DELETE CASCADE` on `clicks(id)`, so neither table anchors the other.
- **`ignore_prefetch` is now enforced on every click entry point**, not only
  `index.php`. The header check also recognises modern `Sec-Purpose` /
  `Sec-Fetch-Mode` hints, so current Chrome and Edge prefetches are dropped
  instead of counted as real clicks.

### Fixed
- **The dead `fix_nginx` generator still shipped alongside the shared one.**
  `action=fix_nginx` hand-wrote its own nginx config, hardcoded the `php8.3`
  FPM socket, copied it into place via `sudo cp` and reloaded **without running
  `nginx -t`** — exactly the class of bug the `core/nginx_config.php` rewrite
  was written to eliminate. The action now delegates to that shared generator
  (the same path `regenerate_nginx` uses), so a "fix my nginx" call produces a
  tested, reload-safe config.
- **The landing editor's code examples ignored the UI language.** The
  `{offer}` / `/?_lp=1` snippets hardcoded Russian ("Купить", "Оффер 10")
  regardless of the selected locale; only the surrounding prose was translated.
  The example text is now localised across all seven languages.

## [0.9.6.3] — 2026-08-07

### Fixed
- **MCP connector returned 0 tools.** `mcp.php` decoded `tools.json` with
  `json_decode(..., true)`, which turns an empty `{}` into an empty PHP array,
  and `json_encode` then emits `"[]"` rather than `"{}"`. Every parameter-less
  tool therefore served `"properties":[]` and `"additionalProperties":[]`, both
  of which the MCP spec defines as records, so the client rejected the whole
  `tools/list` with *“expected record, received array”* and exposed no tools.
  `inputSchema` is now normalised at manifest load — `properties`,
  `patternProperties`, `$defs` and `definitions` are coerced to objects, and an
  array-valued `additionalProperties` becomes `true` — recursively over nested
  schemas. The fix holds regardless of which `tools.json` revision is deployed.
- **Every panel save returned 503.** The bulk edit shipped in 0.9.6.2 rewrote
  the request-body helper so it recursed on itself instead of reading
  `php://input`, so every real POST (login, bot lists, every form save) spun
  until the PHP-FPM worker died and nginx answered 503. The helper reads the
  stream again.
- **Bot IP table rendered blank rows.** The panel read `item.value` while the
  table column is `ip_or_cidr`, so every row was empty; the API now returns a
  stable `value` alias. Bot lists are also searched and paged in SQL — a flat
  first-1000 fetch left everything past row 1000 invisible and undeletable, and
  blacklists routinely reach tens of thousands of rows. The panel gained a
  search box, a shown-of-total counter and a *load more* button.
- **Raw translation keys showed across the panel.** `t()` returns the key
  itself when a translation is missing and no fallback is passed; 39 keys had
  no entry. The bot-list keys had landed in the wrong locale section (anchored
  on `"noRecords"`, which occurs earlier in the file), so the Bots page showed
  literal key names, and the payout-model dropdown, campaign parameters, stream
  device types, all 15 admin tile descriptions and six common labels had no
  translation at all. Re-anchored and added across all seven locales
  (de / en / es / fr / ru / uk / zh); parity at 1838 keys.

## [0.9.6.2] — 2026-08-07

See [README.md — What's New in v0.9.6.2](README.md#whats-new-in-v0962).

### Added
- Remote MCP over a URL (`mcp.php`).

### Fixed
- Uploaded landings showed no images, video or fonts (relative paths resolved
  against the domain root → 404). Assets now resolve against the shown landing.
- Media requests (`.webp`, `.mp4`, `.avif`, `.webm`) fell through into campaign
  matching, logging phantom clicks and returning HTML in place of the file.
- Bot blacklists on Settings → Bots were no-ops that reported success.
- The MCP page pointed at the wrong Claude dialog.
- CSRF was only enforced on POST; now covers every mutating method.

## [0.9.6.1] — 2026

See [README.md — What's New in v0.9.6.1](README.md#whats-new-in-v0961).

### Fixed
- Postback queue never ran (no UI to install the delivery cron).
- S2S log marked every postback as an error (read a legacy column).
- Rows abandoned by a crashed worker were stuck forever (stale `in_flight`).
- Retry ladder stopped one step short of the 24h attempt.
- Today's ad spend froze at the first sync (re-syncs discarded as duplicates).
- Cloaking sensitivity had two levels instead of three (`medium` == `high`).
- SSRF re-check bypassed bare-IP hosts; hardened `curl proxy`.
- `form_submit` dropped the port.

### Added
- JS fingerprint check for cloaking (optional, off by default).

## [0.9.6.0] — 2026

See [README.md — What's New in v0.9.6.0](README.md#whats-new-in-v0960).

### Added
- Redirect types at runtime (HTTP 302, JS, Meta refresh, iframe, form submit,
  curl-proxy).
- Cloaking with safe-page / money-page split.
- S2S postback queue with retry.
- Cost auto-import (Facebook Ads / Google Ads).
- Redirect/iframe/file redirect types, local landings.
