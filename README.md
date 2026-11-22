# Orbitra v2.1.0 Tracker

**🌐 Language: English | [Русский](README.ru.md)**

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-8.1%2B_(8.4%2B_installed)-777BB4?logo=php)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react)
![Vite](https://img.shields.io/badge/Vite-7-646CFF?logo=vite)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite)
![Status](https://img.shields.io/badge/Status-Production_Ready-brightgreen)

Orbitra is a modern traffic management and conversion tracking system. A simpler and faster alternative to Keitaro Tracker, while keeping full API and feature compatibility.

## 🆕 What's New in v2.1.0

Statistics survive their retention window: raw clicks fold into compact hourly rollups, reports span any period without losing numbers, and a disk-space safety valve protects the database under extreme load.

- 📊 **Hourly rollups** — closed hours outside the raw-retention window fold into compact per-bucket rows (~150 bytes). Reports over any period read the rollups for the frozen part and raw clicks for the fresh part, with identical numbers. Dimensions available over frozen periods: campaign, stream, offer, landing, source, country, device/OS/browser; other dimensions (sub_id, parameters) show a clear "available for the last N days" marker
- ⏱ **Separate retention windows** — raw clicks (default 60 days), converted clicks (365 days), conversions (forever). Existing installs keep their current value; no data is lost on update
- 🔗 **Conversions survive their clicks** — conversion rows are never deleted by age; the foreign key cascade is gone
- 🎯 **Unique clicks by flags** — uniques are now the insert-time uniqueness flags (honouring the campaign's uniqueness window), not a period-wide COUNT(DISTINCT ip). Cross-campaign totals count a person once; campaign-scoped views count per campaign. Long-period numbers change accordingly — this is a deliberate semantics change
- 📌 **Traffic-source stats at click time** — re-pointing a campaign to a new source no longer moves its click history
- 💾 **Incremental vacuum** (optional) — a one-time background VACUUM converts the database to auto_vacuum = INCREMENTAL; freed space returns to the OS in small doses
- 🛡 **WAL disk-space divert** — when the journal threatens to fill the partition under sustained load, clicks go to the on-disk queue, the journal folds, the queue drains in chunks. Postbacks for queued clicks do not expire
- 🩺 **System Status** — freeze watermark, rollup backlog, vacuum state, WAL divert + queue depth

### v2.0.4

Analytics reports get a Total row, click-to-sort columns and a built-in "Show me on an example" walkthrough.

- ➕ **Total row** — the Trends report and the cohort summary end with a pinned Total. CR, LP CTR and ROI are recalculated from the sums (not averaged), and unique clicks are counted once over the whole period — a visitor who came three days in a row is one unique, not three
- ↕️ **Sort by any column** — click a column header in the Trends report or the cohort summary; click again to reverse. The Total row stays at the bottom
- 🎓 **"Show me on an example"** — a button in the Trends and Cohort hints switches the page to clearly labelled example data and walks through it step by step: grouping, metrics, how to read the chart, sorting and the Total. Your data is not touched; even after you close the hint the button stays one click away
- 🛠 **Fixes** — CTR in Trends was a placeholder that always read 100%: it is now the real landing CTR (as in campaign reports); toggling a metric redraws the chart at once; percentages get their own axis so a CR trend is no longer a flat line at zero; weekdays are named in the interface language

### v2.0.3

The Trends page now counts exactly what the dashboard counts.

- 📈 **Trends = dashboard** — days are cut in your report timezone (not UTC), the campaign's "Exclude Safe Page clicks from reports" setting applies, hourly grouping shows real numbers instead of empty hours, and hourly over several days covers every day (2.0.2 field report)
- 🌍 **Countries and languages by name** — logs, reports, click details and the push base show "Germany" / "Russian" in your interface language instead of DE / ru

### v2.0.2

Long sustained traffic no longer pauses clicks to compact the database log — the log folds when traffic subsides.

- ⚡ **Sustained load without pauses** — during a peak the write-ahead log is allowed to grow into gigabytes by design; it folds back at a quiet moment (a forced fold exists for disk-space safety alone: log over 50% of free space), and web requests never truncate it. Stand-proven: 480 rps × 15 min — 0 errors in 432k clicks, p99 146 ms, a 17.5 GB log folded right after the load without a pause
- 🩺 **System status shows the log** — WAL size against free disk space, ⚠ past 10% of free space, ⏳ "waiting for a quiet moment to compact the log" while a big log is deliberately held through load
- 🔧 **Stream filters: string payloads fixed** — a filter saved through the API, imports or hand-edited rows with a string payload ("US, DE") no longer turns every click on the campaign into HTTP 500 (2.0.0 field report)

📚 Older releases — see the [full changelog](CHANGELOG.md).

## 🖥 Live Demo

Try the full panel — no install required:

- **URL:** [https://demo.orbitra.link/admin.php](https://demo.orbitra.link/admin.php)
- **Login:** `admin`
- **Password:** `password`

> Shared demo instance — please don't store anything sensitive; data may be reset at any time.

## 🚀 Quick Install (Ubuntu 22.04+ / Debian 12+)

To install automatically on a clean Linux server, run:

```bash
wget -O orbitra-install.sh https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh && bash orbitra-install.sh
```

The download is a separate step on purpose: `wget -qO- … | bash` fails
silently when the download fails (a fresh server's DNS is sometimes not ready
in its first minute), and bash runs whatever arrived — in the worst case,
nothing at all. This way a failed download stops before bash starts, and
wget says why.

The installer automatically:
- Downloads the source code from GitHub
- Installs Nginx, a current PHP (8.5, fallback 8.4) with FPM, SQLite3 and Node.js 20
- Builds and deploys the React/Vite frontend
- Configures a Let's Encrypt SSL certificate for your domain

## 🖥 System Requirements

Orbitra is deliberately lightweight — it runs on plain **PHP + SQLite** behind Nginx, with no heavy frameworks and **no separate database server** (no ClickHouse, Redis or MySQL). Because of that it needs far less RAM than ClickHouse-based trackers and comfortably fits on the smallest VPS plans.

### Baseline (mandatory)

- **CPU:** 1 vCPU (x86_64)
- **RAM:** 1 GB (2 GB recommended)
- **Disk:** 20 GB SSD (a cloud network volume is fine — the load-test server below had a network disk with only ~700 random-write IOPS)
- **OS:** Ubuntu 22.04 through 26.04, or Debian 12 / 13 (minimal images are fine — the installer adds cron itself)
- Clean server, no control panel; root/sudo access

### Sizing by traffic (guideline)

| Clicks per day | RAM | CPU |
|---|---|---|
| up to ~1,000,000 | 1–2 GB | 1–2 vCPU |
| ~1,000,000 – 5,000,000 | 2–4 GB | 2 vCPU |
| 5,000,000+ or heavy reporting during peaks | 4 GB+ | 4 vCPU |

The CPU column follows from the load tests (~2.5–3.5 ms of CPU per click, depending on the processor).

### Disk: plan for your click history

Since **2.1**, raw clicks are kept for a configurable window (**raw click retention**, default 60 days, minimum 31), then folded into compact hourly rollups — statistics for any period are preserved indefinitely (~0.6 MB/day per 240,000 clicks). Clicks that carry conversions live longer (**converted click retention**, default 365 days); conversion rows are never deleted. Both settings live under **System Settings → Raw click retention / Converted click retention**.

A raw click takes about **0.8 KB** in the database (measured on a live install: 230,000 clicks/day ≈ 185 MB/day). Lowering the raw window frees disk after the rollups are built; reports over frozen periods read from the compact rollup tables, not the raw rows.

**Disk ≈ clicks per day × 0.8 KB × raw retention days** + rollups (~0.6 MB/day per 240k clicks × forever), plus free space for traffic peaks:

| Clicks per day | 60 days raw | 90 days raw |
|---|---|---|
| 50,000 | ~2.5 GB + 0.1 GB/year | ~3.7 GB + 0.1 GB/year |
| 250,000 | ~12 GB + 0.5 GB/year | ~18 GB + 0.5 GB/year |
| 1,000,000 | ~48 GB + 2 GB/year | ~72 GB + 2 GB/year |

Keep spare room for peaks: under a long burst of hundreds of new clicks per second, the SQLite write journal (WAL) grows by up to ~1 GB per minute and is compacted automatically once traffic subsides. As a safety net, the journal is compacted right away if it takes more than half of the free disk space — and if it still cannot converge under sustained load, a **disk-space divert** sends clicks to the on-disk queue (they replay after the journal folds; uniqueness checks cannot see queued clicks during this emergency mode). The journal size, free space, divert state and queue depth are shown under **System Status**.

> ✅ **Field-tested:** Orbitra runs well on a **2 vCPU / 2 GB RAM / 20 GB SSD** VPS (Ubuntu 24.04) for low traffic — a comfortable, inexpensive starting point. The higher rows above are headroom for heavier traffic, not a hard requirement.

**Software (installed automatically by `install.sh`):** Nginx, PHP 8.4/8.5 with FPM from ondrej/php or packages.sury.org (distro PHP 8.1+ as a fallback; `php-sqlite3`, `php-curl`, `php-mbstring`, `php-xml`, `php-zip`), SQLite 3, cron (background tasks: click queue, journal compaction, postbacks, SSL, geo databases), Node.js 20 (build only), Certbot for SSL.

> 📈 **Measured, not guessed.** v2.0.2 passed the full load-test methodology that [cpa.rip](https://cpa.rip/services/free-trackers-load-test/) uses to compare trackers: steps from 20 to 480 clicks per second (30 s ramp + 3 min hold, 5 s timeout), a unique event ID in every request, and a database check after each step that every click was stored exactly once. On a modest **4 vCPU** server with a network disk, every step passed with **zero errors and no lost or duplicated clicks** — both for unique visitors and for repeat visits with duplicate filtering turned off. At 480 clicks/s, p99 was **48–88 ms** across three repeats, with the CPU about 40% busy. A separate **15-minute nonstop run at 480 clicks/s** also had zero errors (p99 146 ms). 480 clicks/s is roughly **40 million clicks per day at peak rate**; one million clicks a day averages about 12 clicks per second. Our server and network differ from the ones in the cpa.rip articles, so compare steps passed rather than milliseconds.
>
> In an earlier [load test](https://orbitra.link/blog/load-test/) on a **2 vCPU / 7 GB** server with a **3.9-million-click** database, the ceiling was ~550–600 clicks/s, set by CPU rather than SQLite locks: a write spool queue absorbs lock contention, so overload slows responses down but does not drop clicks.

> 💡 **Why lower requirements than Keitaro?** Keitaro stores clicks in ClickHouse + Redis + MySQL, and its RAM requirements grow steeply with volume (up to 64 GB). Orbitra keeps everything in one SQLite file in WAL mode, with no separate database server to feed. **Where a ClickHouse-based tracker is genuinely stronger:** a cluster of several tracker servers writing to one database, and very heavy reports over months of tens of millions of rows while peak traffic is running. Heavy reports share the server with click processing (in the test, a reports page refreshed every 2 s lowered the ceiling to ~320 clicks/s).

> 💡 **Note on the 1 GB plan:** running the tracker needs very little memory, but the installer builds the frontend on the server with Vite, which is the most memory-hungry step. On a 1 GB box add ~1–2 GB of swap before installing (or build the frontend elsewhere) so the build doesn't run out of memory.

## ✨ Key Features

### 1. **Full Keitaro Compatibility**
- **Click API with tokens** — full compatibility with existing integration scripts
- **Import from Keitaro** — migrate campaigns, offers, domains and streams while preserving tokens
- **API compatibility** — works with existing postbacks and webhooks

### 2. **Modern Architecture**
- **Backend**: PHP 8.1+ without heavy frameworks (clean code)
- **Database**: SQLite 3 in WAL mode with a write spool queue (single file, automatic schema creation; [480 clicks/s with zero errors on 2 vCPU](https://orbitra.link/blog/load-test/))
- **Frontend**: React 19 + Vite 7 + Tailwind CSS 4
- **UI/UX**: Modern design with multiple built-in themes (Light, Dark, Green, Neon) and a custom palette

### 3. **Campaign Management**
- **6 payout models**: CPC, CPuC, CPM, CPA, CPS, RevShare
- **30+ parameters**: keyword, sub_id_1...30, cost, creative_id and more
- **Advanced stream logic**: Intercept → Regular → Fallback with weights and positions
- **Advanced filtering**: GEO, Device (Desktop / Mobile / Tablet taxonomy shared by the tracker, Click API and reports), OS, Browser, ISP, IP, Language, Referer, any URL parameter (incl. "empty")
- **A/B testing**: built-in split-test support with weighted rotation
- **Play/Pause from the panel** — one click pauses an internal campaign (a disabled campaign stops serving immediately) or an actual Facebook ad / ad set / campaign right from the table or a report row, via the Meta Marketing API
- **Per-stream "Collect clicks"** — fallback and white-page streams can serve their destination without writing a click row, so unwanted traffic stops polluting CR and CPA

### 4. **Integrations**
- **S2S Postbacks** — Server-to-Server postbacks from affiliate networks
- **Affiliate network templates**: platform-level (Everflow, CAKE, HitPath, Affise, TUNE/HasOffers) plus networks Leadbit, M4Leads, Dr.Cash, AdCombo and others
- **Source templates**: Facebook, Google, TikTok, Yandex, Taboola, Outbrain, Email and others
- **Click API** — tokens for working with integration scripts
- **Facebook cost import** — daily ad spend pulled from the Meta Marketing API and attributed to clicks by ad / adset / campaign ID, converted into the tracker's currency ([docs](docs/facebook.md))
- **TikTok Ads & Google Ads cost import** — the same attributed-spend pipeline for the other major networks
- **External cost API** — Dolphin and Fbtool push spend straight into Orbitra through a Keitaro-compatible Admin API route ([docs](docs/dolphin-fbtool.md))
- **Push & pop network spend** — AdMaven, PropellerAds, HilltopAds, Adsterra, Clickadu, Kadam, Galaxion in a *Push/Pop networks* card, per campaign × zone where the network reports zones, with **Zone Killer** rules that surface — and in auto mode block through the network's API — the zones burning money
- **Facebook Conversions API** — conversions sent to Meta server-side, deduplicated against the browser pixel, so the events ad blockers and iOS strip out still reach the optimiser
- **Ads Manager extension** — a browser overlay that injects real profit / ROI / CPA pills into Facebook Ads Manager rows, with a per-entity drill-down: daily history, landings and offers breakdown, and Pixel/CAPI delivery accuracy (auto-provisioned read key, no page permissions on other sites)
- **Cloudflare & Namecheap** — DNS parking and SSL through the Cloudflare API; buy, park and import domains through Namecheap without leaving the panel
- **Revenue aggregators** — Affilka, ReferOn and generic S2S APIs feed real player revenue back into reports
- **Telegram Bot** — real-time monitoring and notifications

### 5. **Analytics & Reports**
- **Dashboard** — aggregated statistics for clicks, conversions and revenue
- **Trends** — detailed analytics with charts across 8 metrics
- **Campaign Reports** — campaign reports grouped by any parameter, with saveable column templates and a Keitaro-parity column set (visits, LP CTR, leads/sales/rejected/trash, Approve %, EPC/EPV, ROI/ROI(conf), CPV)
- **Cohort analysis** — how campaigns launched on different dates hold up over time
- **High-density tables** — compact sticky-header tables for Campaigns / Landers / Offers with zebra striping, pagination and a TOTAL row that always stays visible
- **Conversion Log** — detailed conversion log with filters
- **Traffic Simulation** — click simulation for testing streams

### 6. **Session Recordings & Heatmaps**
- **Session recordings** — Clarity-style replays of real visits (mouse, scroll, clicks, taps) on landings, whole sites and local offers served by the tracker, switched on per landing with a share of visitors and a recording window; a player with click marks, 1–8× speed and skip-inactivity; recordings stay on your own server
- **Click heatmap & scroll map** per landing — desktop and mobile separately, rage clicks ringed; the scroll map covers every measured visit
- **Safe by design** — form fields are masked in the visitor's browser, bots and safe-page visitors are never recorded, disk caps with the oldest recordings overwritten first

### 7. **Landing Pages, PWA & Popups**
- **Local landings** — upload and edit HTML landings right in the panel (editor, files, groups)
- **PWA landings & web push** — turn a landing into an installable PWA with push campaigns, click tracking and delivery diagnostics
- **Conversion popups** — each campaign's own popup with the offer button (template, text & amount, image, timing), injected by `kclient.php`, with popup funnel metrics
- **Media gallery** — a central image library with a picker inside the editors

### 8. **Cloaking & Bot Protection**
- **Money / safe page split** — suspicious visitors get the safe page; layered detection (IP lists, datacenter / VPN ASN, UA heuristics, bot-ISP blocklists) with per-stream control over what lands in the stats
- **JavaScript check** — the visitor's browser has to run the step, so the verdict is known before the money page is served
- **Browser check** — bots, moderators' virtual machines and emulators caught by contradictions in what the browser reports about itself (software GPU, a phone User-Agent on a desktop platform, a phone without touch); Off / Observe / Soft / Strict modes; never used for uniqueness
- **Anti-Bot Challenge** — per-campaign reCAPTCHA v2 / v3 or a custom widget; clicks are logged only after a successful challenge (state signed with HMAC-SHA256)
- **Social link preview** — messenger and social preview bots get your `og:*` card instead of the click flow; search crawlers are not touched

### 9. **Multilingual**
- **7 languages**: 🇬🇧 English, 🇷🇺 Russian, 🇺🇦 Ukrainian, 🇪🇸 Spanish, 🇨🇳 Chinese (Simplified), 🇫🇷 French, 🇩🇪 German
- **Full i18n coverage** — every UI element is localized, with 100% key parity across all locales
- **Language switching** — in profile settings, without a page reload

### 10. **Telegram Bot**
- **10+ commands**: `/stats`, `/campaigns`, `/top`, `/conversions` and others
- **Notifications**: instant conversion notifications
- **Daily summary**: automatic campaign report
- **Multilingual**: the bot speaks all 7 interface languages (EN, RU, UK, ES, ZH, FR, DE) via `/lang`

### 11. **Domain Management**
- **Domain groups** — organise parked domains ("FB Nutra", "TikTok Landers", …) with inline group creation from the domain modal
- **Per-domain controls** — admin panel access (deny = panel and API answer 404 on that host while tracking keeps working), HTTPS-only redirect, Cloudflare proxy (SSL from the CF edge, Let's Encrypt issuance skipped), crawler indexing
- **Index page routing & Catch 404** — serve a campaign on the domain root or catch unknown paths
- **Registrar / DNS metadata** — registrar, DNS provider and manual status (Disabled serves 404 on the whole host)
- **Bulk add with URL cleanup** — paste `https://track.example.com/` or a comma-separated list; HTTP(S), slashes and spaces are cleaned automatically
- **DNS check** — automatic A-record verification with caching
- **Automatic SSL** — Let's Encrypt via Certbot with retry backoff and chain-completeness checks; zero-config parking writes the A record when the Cloudflare or Namecheap integration is connected

### 12. **Migration from Keitaro & Binom**
- **Keitaro: full data migration** — campaigns, offers, domains, streams, affiliate networks, sources, landings; an in-UI guide builds the backup, Click API tokens are carried over
- **Binom: import via the v2 Public API** — no dump file: the panel pulls campaigns with paths, rules and weights straight from your live tracker; connection test, dry-run preview, idempotent re-runs ([docs](docs/binom-migration.md))
- **Preview mode** — preview before the real import, for either source

### 13. **Roles & Security**
- **RBAC roles** — one-click role templates (Admin / Media Buyer / Video Editor / Developer / Custom) fill the whole per-resource permission matrix
- **Server-side financial masking** — users without `show_costs` / `show_revenue` / `show_payout` see money fields nulled across metrics, charts, campaigns and offers, with save-guards so a masked editor load can never wipe stored amounts
- **Personal API keys** — per-user keys with `read` / `write` scopes for MCP, the Admin API and the Ads Manager extension

## 🤖 AI Assistant Integration (MCP)

Since v0.9.5.0 Orbitra ships an **MCP server** ([`mcp/`](mcp/README.md)) — connect Claude Desktop or any other MCP client and drive the tracker in plain language:

> "How did my campaigns do over the last 7 days?"
> "Create 10 campaigns for offer #4 — one per GEO: US, CA, GB, DE, FR."
> "Add track.example.com and point its root at campaign 12."

- **41 tools** — reads (metrics, campaigns, conversions, reports) and management (create / bulk-create / edit / delete campaigns, offers, domains, sources, landings)
- **Scoped API keys** — `read` (analytics only) and `write` (management), generated under **Users → API keys**
- **Safe by design** — the key only ever goes to your own tracker address; read keys physically cannot change data

Details: [docs/mcp.md](docs/mcp.md) and [mcp/README.md](mcp/README.md).

## 📁 Project Structure

```
Orbitra/
├── api.php                    # REST API (330+ actions — full catalogue in docs/api.md)
├── index.php                  # Main tracker: click flow, cloaking, landings
├── admin.php / admin_api.php  # Admin panel + Keitaro-compatible Admin API
├── postback.php               # Postback handler
├── click.php                  # Click API endpoint
├── kclient.php / kclient.js   # Client library for landings (offers, popups,
│                              #   session recording, browser check)
├── tracking.js / banner.js    # Tracking scripts for external pages
├── replay.js / bc.js          # Session recorder + browser-check collector
├── mcp.php                    # MCP bridge for AI assistants
├── config.php                 # DB, settings, migrations
├── database.sql               # DB schema documentation
├── version.php / version.json # Version files
├── router.php                 # PHP built-in server router
├── install.sh                 # Auto-installer
├── *_cron.php                 # Cron jobs (aggregator, rotation, costs, zones,
│                              #   postback queue, Telegram…)
│
├── core/                      # 80+ modules: click_api, click_logger,
│                              #   CloakDetector, BrowserCheck, SessionReplay,
│                              #   PopupConfig, keitaro_import, SxGeo…
├── frontend/                  # React + Vite frontend
│   ├── src/
│   │   ├── App.jsx           # Main component with routing
│   │   ├── components/       # 115+ React components (campaigns, reports,
│   │   │                     #   replay player, heatmaps, PWA, integrations…)
│   │   ├── contexts/         # LanguageContext (i18n)
│   │   └── locales/          # 7 languages, 100% key parity
│   └── replay/recorder.js    # Source of /replay.js (npm run build:replay)
│
├── mcp/                       # MCP server for AI assistants (Node.js)
├── docs/                      # Documentation (English)
├── docs/ru/                   # Documentation (complete Russian mirror)
├── tests/                     # PHP test suite (140+ test files)
├── aggregator_engines/        # Stats aggregation engines
├── cli/                       # CLI utilities (geo updates, wiki sync…)
├── landings/                  # Uploaded landings
└── vendor/                    # Composer dependencies
```

## 🚀 Quick Start for Developers

### Local Development

```bash
# Clone the repository
git clone https://github.com/fenjo26/Orbitra.link.git
cd Orbitra

# Install PHP dependencies
composer install --no-dev

# Install frontend dependencies
cd frontend
npm install
npm run dev  # Start the dev server (http://localhost:5173)
```

### Running the Backend

```bash
# In the project root
php -S localhost:8080 router.php
```

### Production Frontend Build

```bash
cd frontend
npm run build
```

## 🔐 First Login and Setup

Orbitra has **no** default account (`admin`/`admin`) — you set the administrator credentials yourself on first run.

The first time you open the admin panel (`/admin.php`), the system detects that no users exist yet and launches the **initial setup wizard**. In it you create your own administrator:

- **Username** — at least 3 characters
- **Password** — at least 8 characters (with confirmation)
- **Timezone** and **interface language** (one of 7 languages)

After the administrator is created the wizard no longer appears, and you log in with the username and password you set.

## 📚 Documentation

Full documentation is available in the [docs/](docs/) folder (English; [docs/ru/](docs/ru/) is the complete Russian mirror):

- **[Overview](docs/index.md)** — documentation home and navigation
- **[Getting started](docs/getting-started.md)** — from a clean server to the first conversion
- **[Installation & updates](docs/deployment.md)** — requirements, installer, PHP upgrades
- **[Campaigns & streams](docs/campaigns.md)** — filters, schemas, rotation, test links
- **[Cloaking](docs/cloaking.md)** — detection layers, sensitivity, diagnostics; **[Browser check](docs/browser-check.md)** — bots, VMs and emulators by browser contradictions
- **[Session recordings](docs/session-recordings.md)** — replays, click heatmaps, scroll maps
- **[Postbacks & conversions](docs/postbacks.md)** — incoming postbacks, statuses, S2S queue
- **[Costs](docs/costs.md)** — Facebook, TikTok, Google Ads spend import; push & pop networks and Zone Killer
- **[Reports & logs](docs/reports.md)** — dashboard, report builder, click log, metric definitions
- **[Domains & SSL](docs/domains.md)** — Let's Encrypt, Cloudflare, Namecheap, Dynadot
- **[Administration](docs/administration.md)** — users, roles, settings, geo databases
- **[PWA & Push](docs/pwa-push.md)** — PWA landings, web push campaigns, diagnostics
- **[API](docs/api.md)** — REST API and the full action catalogue
- **[MCP](docs/mcp.md)** — connecting AI assistants
- **[Architecture](docs/architecture.md)** — internals, crons, database, release process

## 🔌 Main API Endpoints

### Migration from Keitaro
- `POST ?action=keitaro_import_sql` — import a Keitaro dump

### Campaigns
- `GET ?action=campaigns` — list campaigns
- `GET ?action=get_campaign&id=X` — campaign data
- `POST ?action=save_campaign` — save a campaign
- `POST ?action=delete_campaign` — delete a campaign
- `GET ?action=campaign_report` — campaign report

### Analytics
- `GET ?action=metrics` — aggregated statistics
- `GET ?action=chart` — chart data
- `GET ?action=trends` — detailed analytics
- `GET ?action=conversions` — conversion log

### Integrations
- `GET ?action=affiliate_networks` — affiliate networks
- `GET ?action=traffic_sources` — traffic sources
- `GET ?action=telegram_settings` — Telegram bot settings

> 📖 **Full API list**: see [docs/api.md](docs/api.md)

## 🎯 Main Features

### CampaignEditor
A full-screen campaign editor with tabs:
- **General**: name, alias, domain, source
- **Finance**: 6 payout models (CPC, CPuC, CPM, CPA, CPS, RevShare)
- **Parameters**: 30+ parameters (sub_id_1...30, keyword, cost and more)
- **Integrations**: ready-made scripts for Facebook, Google, TikTok, VK, Yandex
- **S2S Postbacks**: configure postbacks from affiliate networks
- **Notes**: text notes for the campaign
- **Actions**: reports, conversion log, traffic simulation

### Telegram Bot
**10 monitoring commands:**
- `/stats [period]` — statistics (today, 1d, 7d, 30d, yesterday)
- `/campaigns` — list campaigns with metrics
- `/campaign ID` — detailed statistics
- `/top` — TOP-5 campaigns by revenue
- `/conversions` — last 10 conversions
- `/notify on|off` — conversion notifications
- `/daily on|off` — daily summary
- `/lang en|ru|uk|es|zh|fr|de` — bot language

### Traffic Simulation
Testing streams and filters:
- **IP** — set the click's IP address
- **User Agent** — set the User-Agent
- **Country** — choose a country (US, RU, DE, GB, FR and more)
- **Device** — desktop, mobile, tablet
- **Language** — browser language (en, ru, de, fr, es, pt, zh)

## 📊 Payout Models

| Model | Description |
|--------|----------|
| **CPC** | Pay per click |
| **CPuC** | Pay per unique click |
| **CPM** | Pay per 1000 impressions |
| **CPA** | Pay per action (lead) |
| **CPS** | Pay per sale |
| **RevShare** | Percentage of revenue |

## 🔄 Import from Keitaro

### Preparing a Keitaro Dump

On the Keitaro server, run:

```bash
# Connect to the Keitaro server
ssh root@YOUR_KEITARO_SERVER_IP

# Create the dump
bash -lc '
source /etc/keitaro/env/inventory.env

# Config for connecting to the DB
cat > /root/keitaro-mariadb.cnf <<EOF
[client]
user=$MARIADB_KEITARO_USER
password=$MARIADB_KEITARO_PASSWORD
host=127.0.0.1
port=3306
protocol=tcp
EOF
chmod 600 /root/keitaro-mariadb.cnf

# Dump the tables
TABLES="keitaro_affiliate_networks keitaro_groups keitaro_offers keitaro_domains keitaro_campaigns keitaro_campaign_postbacks keitaro_landings keitaro_streams keitaro_stream_filters keitaro_stream_offer_associations keitaro_stream_landing_associations keitaro_traffic_sources keitaro_ref_sources"

mysqldump --defaults-extra-file=/root/keitaro-mariadb.cnf \
  --single-transaction --quick --skip-lock-tables \
  "$MARIADB_KEITARO_DATABASE" $TABLES \
  | gzip > /root/keitaro_orbitra_full.sql.gz

ls -lah /root/keitaro_orbitra_full.sql.gz
'

# Download the file
scp root@YOUR_KEITARO_SERVER_IP:/root/keitaro_orbitra_full.sql.gz .
```

### Importing into Orbitra

1. Open **Migrations** in the admin menu
2. Follow the instructions in the "How to create a Keitaro backup" block
3. Upload the `keitaro_orbitra_full.sql.gz` file
4. Choose what to import (campaigns, offers, domains, etc.)
5. Click "Show preview" to verify
6. Click "Import Into Orbitra" for the real import

## 🔐 Google Ads 1-Click OAuth Setup

Orbitra supports seamless 1-Click Google Ads connection (similar to Keitaro tracker UX). To enable this feature, configure the following environment variables on your server:

### Server Configuration

Add these environment variables to your server configuration (e.g., in `/etc/environment`, systemd service file, or `.env` file):

```bash
# Google OAuth2 Client ID (Web application)
ORBITRA_GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com

# Google OAuth2 Client Secret
ORBITRA_GOOGLE_CLIENT_SECRET=your-client-secret-here

# Google Ads API Developer Token
ORBITRA_GOOGLE_DEVELOPER_TOKEN=your-developer-token
```

### How to Obtain Google Ads API Credentials

1. **Create Google Cloud Project:**
   - Go to [Google Cloud Console](https://console.cloud.google.com/)
   - Create a new project or select existing one
   - Enable the **Google Ads API** in the API Library

2. **Create OAuth 2.0 Credentials:**
   - Navigate to **APIs & Services → Credentials**
   - Click **Create Credentials → OAuth 2.0 Client ID**
   - Application type: **Web application**
   - Add this redirect URI (replace with your domain):
     ```
     https://your-domain.com/api.php?action=google_ads_oauth_callback
     ```
   - Copy the **Client ID** and **Client Secret**

3. **Get Developer Token:**
   - Go to [Google Ads API Center](https://ads.google.com/aw/apicenter)
   - Apply for or copy your existing Developer Token
   - Paste it into the configuration

### User Experience

Once configured, users can:
1. Click **"Sign in with Google"** button in **Integrations → Google Ads Costs**
2. Select their Google account from the account chooser (all logged-in Gmail profiles appear)
3. Automatically discover all accessible Google Ads accounts (including MCC hierarchies)
4. Select which accounts to connect and save

### Fallback: Manual Token Connection

If server-level OAuth credentials are not configured, the UI automatically shows the **"Direct Token Connection"** tab where users can manually enter:
- Developer Token
- OAuth2 Client ID
- OAuth2 Client Secret
- OAuth2 Refresh Token
- Customer ID

## 🎨 Customization

### Themes
Orbitra ships with several built-in theme presets — **Light**, **Dark**, **Green** and **Neon** — plus a fully **Custom** theme where you set your own color palette (primary, backgrounds, text). Pick a theme in **Settings → Branding**.

### Branding
Configure the logo, colors and name in **Settings → Branding**.

### Interface Language
Switch the language in **Profile → Settings**. Seven languages are available: English, Russian, Ukrainian, Spanish, Chinese, French and German.

## 🛠 Technologies

| Category | Technology |
|-----------|------------|
| **Backend** | PHP 8.1+ |
| **Database** | SQLite 3 |
| **Frontend** | React 19.2.0 |
| **Build Tool** | Vite 7.3.1 |
| **UI Framework** | Tailwind CSS 4.2.0 |
| **Icons** | Lucide React 0.575.0 |
| **HTTP Client** | Axios 1.18.0 |
| **Charts** | Chart.js 4.5.1 |
| **Date Utils** | date-fns 4.1.0 |
| **PHP Deps** | Composer |

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a branch for your feature (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

## 📞 Support

- **GitHub Issues**: https://github.com/fenjo26/Orbitra.link/issues
- **Documentation**: [docs/](docs/)
- **Email**: info@orbitra.link

---

**Orbitra** — a modern tracker for affiliate marketers and webmasters.

**Tags**: `tracker`, `affiliate-marketing`, `keitaro-alternative`, `php-tracker`, `react-admin`, `cpa-network`, `traffic-management`, `split-testing`, `conversion-tracking`
