# Orbitra v0.9.8.2 Tracker

**🌐 Language: English | [Русский](README.ru.md)**

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react)
![Vite](https://img.shields.io/badge/Vite-7-646CFF?logo=vite)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite)
![Status](https://img.shields.io/badge/Status-Production_Ready-brightgreen)

Orbitra is a modern traffic management and conversion tracking system. A simpler and faster alternative to Keitaro Tracker, while keeping full API and feature compatibility.

## 🖥 Live Demo

Try the full panel — no install required:

- **URL:** [https://demo.orbitra.link/admin.php](https://demo.orbitra.link/admin.php)
- **Login:** `admin`
- **Password:** `password`

> Shared demo instance — please don't store anything sensitive; data may be reset at any time.

## 🚀 Quick Install (Ubuntu 20.04 / 22.04 / 24.04)

To install automatically on a clean Linux server, run:

```bash
wget -qO- https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh | bash
```

The installer automatically:
- Downloads the source code from GitHub
- Installs Nginx, PHP 8.0+ (FPM), SQLite3 and Node.js 20
- Builds and deploys the React/Vite frontend
- Configures a Let's Encrypt SSL certificate for your domain

## 🖥 System Requirements

Orbitra is deliberately lightweight — it runs on plain **PHP + SQLite** behind Nginx, with no heavy frameworks and **no separate database server** (no ClickHouse, Redis or MySQL). Because of that it needs far less RAM than ClickHouse-based trackers and comfortably fits on the smallest VPS plans.

### Baseline (mandatory)

- **CPU:** 1 vCPU (x86_64)
- **RAM:** 1 GB (2 GB recommended)
- **Disk:** 20 GB SSD
- **OS:** Ubuntu 20.04 / 22.04 / 24.04 or Debian 11 / 12
- Clean server, no control panel; root/sudo access

### Sizing by traffic (guideline)

| Clicks per day | RAM | CPU | Disk |
|---|---|---|---|
| up to ~100,000 | 1–2 GB | 1–2 vCPU | 20 GB SSD |
| ~100,000 – 500,000 | 2–4 GB | 2 vCPU | 40 GB SSD |
| ~500,000 – 1,000,000 | 4–8 GB | 4 vCPU | 80 GB SSD |

> ✅ **Field-tested:** Orbitra runs well on a **2 vCPU / 2 GB RAM / 20 GB SSD** VPS (Ubuntu 24.04) for low traffic — a comfortable, inexpensive starting point. The higher rows above are headroom for heavier traffic, not a hard requirement.

**Software (installed automatically by `install.sh`):** Nginx, PHP 8.0+ with FPM (`php-sqlite3`, `php-curl`, `php-mbstring`, `php-xml`, `php-zip`), SQLite 3, Node.js 20 (build only), Certbot for SSL.

> 💡 **Why lower than Keitaro?** Keitaro stores clicks in ClickHouse + Redis + MySQL, so its RAM requirements scale steeply (up to 64 GB for millions of clicks/day). Orbitra keeps everything in a single SQLite file, so RAM is not the bottleneck — disk I/O and SQLite's single-writer model are. SQLite (in WAL mode) handles low-to-mid volume comfortably; for sustained **millions of clicks per day** with heavy analytics, a columnar-DB tracker like Keitaro is architecturally a better fit.

> 💡 **Note on the 1 GB plan:** running the tracker needs very little memory, but the installer builds the frontend on the server with Vite, which is the most memory-hungry step. On a 1 GB box add ~1–2 GB of swap before installing (or build the frontend elsewhere) so the build doesn't run out of memory. Disk usage stays small — it grows mainly with the SQLite click/conversion logs over time.

## ✨ Key Features

### 1. **Full Keitaro Compatibility**
- **Click API with tokens** — full compatibility with existing integration scripts
- **Import from Keitaro** — migrate campaigns, offers, domains and streams while preserving tokens
- **API compatibility** — works with existing postbacks and webhooks

### 2. **Modern Architecture**
- **Backend**: PHP 8.3+ without heavy frameworks (clean code)
- **Database**: SQLite 3 (single file, automatic schema creation)
- **Frontend**: React 19 + Vite 7 + Tailwind CSS 4
- **UI/UX**: Modern design with multiple built-in themes (Light, Dark, Green, Neon) and a custom palette

### 3. **Campaign Management**
- **6 payout models**: CPC, CPuC, CPM, CPA, CPS, RevShare
- **30+ parameters**: keyword, sub_id_1...30, cost, creative_id and more
- **Advanced stream logic**: Intercept → Regular → Fallback with weights and positions
- **Advanced filtering**: GEO, Device (Desktop / Mobile / Tablet taxonomy shared by the tracker, Click API and reports), OS, Browser, ISP, IP, Language, Referer
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

### 6. **Multilingual**
- **7 languages**: 🇬🇧 English, 🇷🇺 Russian, 🇺🇦 Ukrainian, 🇪🇸 Spanish, 🇨🇳 Chinese (Simplified), 🇫🇷 French, 🇩🇪 German
- **Full i18n coverage** — every UI element is localized, with 100% key parity across all locales
- **Language switching** — in profile settings, without a page reload

### 7. **Telegram Bot**
- **10+ commands**: `/stats`, `/campaigns`, `/top`, `/conversions` and others
- **Notifications**: instant conversion notifications
- **Daily summary**: automatic campaign report
- **Multilingual**: the bot speaks all 7 interface languages (EN, RU, UK, ES, ZH, FR, DE) via `/lang`

### 8. **Domain Management**
- **Domain groups** — organise parked domains ("FB Nutra", "TikTok Landers", …) with inline group creation from the domain modal
- **Per-domain controls** — admin panel access (deny = panel and API answer 404 on that host while tracking keeps working), HTTPS-only redirect, Cloudflare proxy (SSL from the CF edge, Let's Encrypt issuance skipped), crawler indexing
- **Index page routing & Catch 404** — serve a campaign on the domain root or catch unknown paths
- **Registrar / DNS metadata** — registrar, DNS provider and manual status (Disabled serves 404 on the whole host)
- **Bulk add with URL cleanup** — paste `https://track.example.com/` or a comma-separated list; HTTP(S), slashes and spaces are cleaned automatically
- **DNS check** — automatic A-record verification with caching
- **Automatic SSL** — Let's Encrypt via Certbot with retry backoff and chain-completeness checks; zero-config parking writes the A record when the Cloudflare or Namecheap integration is connected

### 9. **Migration from Keitaro**
- **Full data migration**: campaigns, offers, domains, streams, affiliate networks, sources, landings
- **Token preservation** — Click API tokens are carried over for compatibility
- **In-UI guide** — step-by-step instructions for creating a Keitaro backup
- **Preview mode** — preview before the real import

### 10. **Anti-Bot Challenge**
- **Per-campaign human verification** — stop corporate email security crawlers and clickbots from polluting your statistics
- **reCAPTCHA v2** — classic "I'm not a robot" checkbox
- **reCAPTCHA v3** — invisible, score-based with a configurable threshold
- **Custom code** — paste any HTML/JS verification widget
- **Clean stats** — clicks are logged only after a successful challenge, so bots never appear in reports; challenge state is signed (HMAC-SHA256) and expires in 15 minutes to prevent replay

### 11. **Roles & Security**
- **RBAC roles** — one-click role templates (Admin / Media Buyer / Video Editor / Developer / Custom) fill the whole per-resource permission matrix
- **Server-side financial masking** — users without `show_costs` / `show_revenue` / `show_payout` see money fields nulled across metrics, charts, campaigns and offers, with save-guards so a masked editor load can never wipe stored amounts
- **Personal API keys** — per-user keys with `read` / `write` scopes for MCP, the Admin API and the Ads Manager extension
- **Bot & cloak protection** — datacenter/VPN ASN detection, UA heuristics, bot-ISP blocklists, safe-page serving for suspicious visitors with per-stream control over what lands in the stats

## 🤖 AI Assistant Integration (MCP)

Since v0.9.5.0 Orbitra ships an **MCP server** ([`mcp/`](mcp/README.md)) — connect Claude Desktop or any other MCP client and drive the tracker in plain language:

> "How did my campaigns do over the last 7 days?"
> "Create 10 campaigns for offer #4 — one per GEO: US, CA, GB, DE, FR."
> "Add track.example.com and point its root at campaign 12."

- **31 tools** — reads (metrics, campaigns, conversions, reports) and management (create / bulk-create / edit / delete campaigns, offers, domains, sources, landings)
- **Scoped API keys** — `read` (analytics only) and `write` (management), generated under **Users → API keys**
- **Safe by design** — the key only ever goes to your own tracker address; read keys physically cannot change data

Details: [docs/mcp.md](docs/mcp.md) and [mcp/README.md](mcp/README.md).

## 📁 Project Structure

```
Orbitra/
├── api.php                    # REST API (60+ endpoints)
├── index.php                  # Main tracker (click handling)
├── admin.php                  # Admin panel entry point
├── postback.php               # Postback handler
├── click.php                  # Click API
├── telegram_bot.php           # Telegram bot webhook handler
├── config.php                 # DB configuration and migrations
├── database.sql               # DB schema documentation
├── version.php                # System version
├── router.php                 # PHP built-in server router
├── install.sh                 # Auto-installer
├── *_cron.php                 # Cron jobs (aggregator, backorder, source checks)
├── .htaccess                  # Apache rewrite rules
│
├── core/                      # System modules
│   ├── keitaro_import.php     # Import from Keitaro
│   ├── click_api.php          # Click API implementation
│   ├── backorder.php          # Domain monitoring
│   └── SxGeo.php              # Geo IP database
│
├── frontend/                  # React + Vite frontend
│   ├── src/
│   │   ├── App.jsx           # Main component with routing
│   │   ├── main.jsx          # Entry point
│   │   ├── components/       # 53 React components
│   │   │   ├── CampaignEditor.jsx    # Campaign editor (~130KB)
│   │   │   ├── IntegrationsPage.jsx # Integrations
│   │   │   ├── MigrationsPage.jsx   # Migrations and import
│   │   │   ├── ConversionsLog.jsx   # Conversion log
│   │   │   ├── CampaignReports.jsx  # Campaign reports
│   │   │   └── ...               # Other components
│   │   ├── contexts/
│   │   │   └── LanguageContext.jsx  # i18n context
│   │   └── locales/           # 7 languages, 100% key parity
│   │       ├── en.js          # English
│   │       ├── ru.js          # Russian
│   │       ├── uk.js          # Ukrainian
│   │       ├── es.js          # Spanish
│   │       ├── zh.js          # Chinese
│   │       ├── fr.js          # French
│   │       └── de.js          # German
│   ├── package.json
│   ├── vite.config.js
│   └── index.html
│
├── docs/                      # Documentation
│   ├── index.md              # Documentation overview
│   ├── architecture.md       # Architecture and technologies
│   ├── features.md          # Feature descriptions
│   ├── api.md               # REST API documentation
│   ├── deployment.md        # Deployment instructions
│   └── keitaro-migration.md # Keitaro migration guide
│
├── aggregator_engines/        # Stats aggregation engines
├── cli/                       # CLI utilities
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
- **Password** — at least 6 characters (with confirmation)
- **Timezone** and **interface language** (one of 7 languages)

After the administrator is created the wizard no longer appears, and you log in with the username and password you set.

## 📚 Documentation

Full documentation is available in the [docs/](docs/) folder:

- **[Overview](docs/index.md)** — documentation navigation
- **[Architecture](docs/architecture.md)** — technology stack and DB structure
- **[Features](docs/features.md)** — detailed feature descriptions
- **[API](docs/api.md)** — REST API documentation
- **[Deployment](docs/deployment.md)** — installation and configuration instructions

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
| **Backend** | PHP 8.3+ |
| **Database** | SQLite 3 |
| **Frontend** | React 19.2.0 |
| **Build Tool** | Vite 7.3.1 |
| **UI Framework** | Tailwind CSS 4.2.0 |
| **Icons** | Lucide React 0.575.0 |
| **HTTP Client** | Axios 1.13.5 |
| **Charts** | Chart.js 4.5.1 |
| **Date Utils** | date-fns 3.6.0 |
| **PHP Deps** | Composer |

## 📝 What's New in v0.9.8.2

### Added
- 🔐 **RBAC role templates + server-side financial masking.** One-click presets
  (Admin / Media Buyer / Video Editor / Developer / Custom) fill the role and
  the whole permission matrix in the user modal. New *Financial data* switches
  — `show_costs` / `show_revenue` / `show_payout` — mask the money families
  server-side across metrics, charts, campaigns and offers, and save-guards
  restore hidden amounts so a masked editor load can't wipe them on save. Nav
  tabs hide on `None` permission and gear menus filter per item.
- 🌐 **Keitaro-style Namecheap integration** — zero-config DNS parking, domain
  purchasing, import and subdomain parking.
- 🕵️ **Quick targeting filters on the cloak card** — GEO, devices and a
  bot-ISP blocklist, plus a global `bot_isp_list` setting.
- 📡 **CAPI per-pixel `event_source_url`** with `{campaign_url}` /
  `{landing_url}` / `{clickid}` macros (migration 22).
- 🎛️ **Redesigned Tracking tab** — two-column layout, per-method options, live
  widget preview and install hints.
- 📊 **Keitaro-parity columns for Landings & Offers** — visits/uVisits, LP
  clicks/CTR, leads/sales/rejected/trash, Approve %, CR, cost/revenue (conf),
  profit, CPC/EPC/EPV, ROI/ROI(conf) — plus compact report headers with
  full-name tooltips.

### Fixed
- 🚨 **"Prefetch ignored." blank screen** — Chrome/Edge omnibox preloading
  cached the old stub response and showed it as the page until a manual
  refresh. Prefetch requests now serve the campaign normally, skip only the
  click INSERT and answer with `Cache-Control: no-store`, so the real
  navigation is counted properly.
- 🧩 **Generated tracking snippets were broken code** — the kclient-php
  `get_link` snippet had a nested open tag, the back-button trap fired on
  Forward instead of Back, and link/iframe/script snippets died with
  SyntaxError from an unterminated concat. All snippets are now verified with
  `php -l` / `node --check`; `EXIT_BUTTON_COLORS` is wired into the editor.
- 🔗 **Encoded ad-network macros never substituted** — a campaign URL that
  kept `%7B%7Bad.id%7D%7D` percent-encoded passed the macro through untouched.
- 📘 **Facebook Ads template ships real Meta macros** (`{{placement}}`,
  `{{site_source_name}}`) and drops the fabricated `{{site.name}}`.
- 🌍 **i18n** — country names were hardcoded Russian regardless of the UI
  language; es/zh/uk machine-translation howlers fixed (CPC read as
  "Communist Party", IP as "intellectual property", GEO as "orbital").
- 🎨 **Themes** — neon buttons were unreadable (duplicate `.btn-primary`
  block), Geo Profiles and GeoSelector colors were hardcoded light-only, and
  the country dropdown was clipped inside the filter modal.

## 📝 What's New in v0.9.8.1

### Fixed
- ✅ **Stream names were wiped on every campaign save** — the editor's
  stream-name field collected the name, but the save INSERT never carried the
  `name` column, so every save turned it into NULL. Found live while testing
  0.9.8.0; if a stream lost its name, re-enter it once after updating.

## 📝 What's New in v0.9.8.0

### Added
- 🎯 **Keitaro-style landing/offer picker in campaign streams.** The
  "Add landing pages / Add offers" split button opens a selector modal: instant
  search by name/URL/id, Group / Affiliate Network / Country filters,
  multi-select with Select all, "Already added" badges. Picked entities enter
  the stream with even weight redistribution (sum always 100%); the dropdown's
  second action creates a new landing/offer right from the stream.
- 🔀 **AND / OR stream filter logic** — a per-stream `[AND|OR]` switcher in the
  FILTERS header. Abstaining filters (unknown country, no ISP data) block
  nothing under AND and satisfy nothing under OR; the mode survives
  duplicate/export (migration 21).
- 🧩 **"Select and Order Columns" for Landings and Offers tables** —
  checkboxes, Select All, drag-to-reorder, localStorage persistence, Restore to
  default (Name stays required).
- 📊 **Report-grade offer columns**: leads, sales, rejected, conversions (as
  events, CR can exceed 100% again), CR, EPC/CPC, revenue / cost / P&L / ROI
  confirmed — computed by the same engine as the verified 64-metric reports and
  covered by a reference-dataset test suite.

### Fixed
- ✅ **Full postback URL for affiliate networks** — the template's macros are
  baked in, the field is editable and copies in one click; the networks list
  shows the saved URL.
- ✅ **Campaign groups were saved into offer_groups** and the filter loaded a
  non-existent endpoint — groups never appeared. Now saved and loaded
  correctly; `groups` aliases `campaign_groups` for older clients.
- ✅ **Column customizer clicks** — draggable rows swallowed every click,
  reorder scrambled rows under an active search, Select All ignored the filter.
- ✅ **Local landings open on the first click after upload** — nested-folder
  ZIPs flatten automatically (`__MACOSX` junk removed), legacy nested uploads
  resolve with their assets, and Save blocks with "Uploading archive…" until
  the archive lands.
- ✅ Unreadable active toggles on light-accent themes (Neon) — text follows
  `--color-text-inverse` per theme.
- ✅ Offer/Landing editors unified (Create titles, Parameters tab, single type
  switcher, matching footers); empty stream rows became clickable placeholders
  with empty states; `Asia/Kolkata` (IST, UTC+5:30) added to every timezone
  dropdown.

## 📝 What's New in v0.9.7.9

### Added
- 📚 **196 traffic-source + 395 affiliate-network templates from Keitaro's own
  exports**, built into every install (209 sources / 438 networks in the
  dropdowns). They replace a hand-written batch whose macros were invented —
  PopAds swapped the click id for the site id, several networks posted to hosts
  that don't exist. Keitaro's `{status: lead=reg sale=dep}` transform and the
  `{clickid}`/`{offer_id}`/`{conversion_revenue}` postback macros now resolve.
- 🎛 **Report customizer**: searchable column picker with presets (COD,
  Lander→Offer, Finance & ROI), up to **5** group-by levels incl. URL-param
  dimensions, eq/neq/contains filters, and drag-and-drop column reordering.
- 📏 **30+ metrics** (LP CTR, approve rate, revenue by status, real aggregator
  revenue/ROI, CPC/CPA/EPC/UEPC) with a sticky totals row; purchases count
  conversions, not clicks.
- 📅 **Date-range picker with a working timezone** — the selection shifts every
  date condition server-side, not just the label. Interactive calendar with
  quick presets.
- 🔗 **Direct URL streams** with `{subid}`/`{clickid}`/`{ip}`/`{country}`
  substitution; refined cloaking UI (per-layer toggles, segmented safe page);
  every redirect method on the landing editor; Cost Sync connection modal and
  manual spend entry; chunked bot-list import for 50k+ entries.

### Fixed
- 📈 Migration 19 indexes the conversions/revenue_records/clicks joins every
  report uses — dashboards stop scanning tables end to end.
- 🔄 The update check no longer claims "no update" when GitHub is unreachable;
  it explains the check failed and gives the manual `git pull` command.
- 🌍 `npm run check:i18n` green — full parity across all 7 locales.

## 📝 What's New in v0.9.7.8

### Added
- 🎯 **Tracking tab in the campaign editor.** KClient JS (base64 option), KClient
  PHP (download button), Tracking Script, banner blocks, Campaign URL,
  link/iframe/script, pixel, countdown, back-button trap, exit intent and
  WordPress — all generated with the campaign's token baked in. And the clients
  are real now: `kclient.php`, `kclient.js`, `tracking.js`, `banner.js` and
  `/pixel.gif` ship with the tracker (the old snippets pointed at files that
  404'd). Click API v3 serves Show-as-HTML stream bodies with `{offer}` resolved
  and captures `ad_id`/`adset_id`/`campaign_id`, so cost matching works for
  KClient traffic.
- 💰 **Cost Sync on the campaign's Integrations tab** — spend connections with
  *Sync now*, a diagnostic that tells you whether this campaign's clicks carry
  the IDs cost import matches on, and the Dolphin/Fbtool push URL for the
  campaign.
- 🏷 **Local offers**: ZIP upload (same security pipeline as landings) and
  serving in place of the redirect, including through `/?_lp=1`.
- ☁️ **Cloudflare integration**: parked domains get their A records managed
  automatically, proxied domains take SSL from the CF edge instead of certbot,
  and one click re-points everything when the tracker moves.
- 🛡 **Cloaking**: 50+ new bot UA signatures and daily-updated datacenter/crawler
  IP ranges (~20k CIDRs) that catch a perfect browser UA on a cloud IP — with a
  self-healing background download, so existing installs need no cron. Stream
  actions add *Send to campaign* and *Show text* (empty = blank page).
- 📊 **Layered reports**: up to three stacked group-by layers (Country →
  Campaign → adset ID) with subtotals, new dimensions (offer, landing, OS,
  browser, day, Sub ID 1–10), a global across-campaigns report and aligned
  tabular columns with a sticky total row.

### Fixed
- 💸 Report cost was hardcoded to zero — profit/ROI were wrong whenever spend
  was imported; cost is now the real `SUM(clicks.cost)`.
- 🗂 `install.sh` deleted uploaded local offers on a re-run over an existing
  installation; `offers/` is backed up and restored like `landings/`.

## 📝 What's New in v0.9.7.7

### Added
- 🎯 **Traffic sources are selectable from the campaign editor.** The '+' next to
  the traffic-source select opens the source editor, so a Facebook source can be
  created from a template right there — previously Facebook only existed as a
  template and could not be picked at all. Choosing a source auto-fills the
  campaign's URL parameters with the source's macros (Keitaro behaviour: switching
  replaces the set), parameters persist across save/reopen, and the campaign URL
  comes out ready to paste into the ad network. Keitaro-style link/iframe/script
  integration snippets are on the campaign's Integrations tab.
- 🎵 **TikTok Ads cost import.** New aggregator engine (Access Token + Advertiser
  ID) pulls daily spend and attributes it to clicks by the IDs the TikTok
  traffic-source template captures (`__CID__`/`__AID__`/`__CAMPAIGN_ID__`,
  `ttclid`).
- 📥 **Dolphin / Fbtool cost intake.** `POST /admin_api/v1/campaigns/{id}/update_costs`
  is wire-compatible with the Keitaro Admin API, so Dolphin and Fbtool.pro push
  Facebook spend into Orbitra exactly as they do into Keitaro. Filters match
  click parameters (`sub_id_4`=ad_id, `sub_id_3`=adset_id by default), spend is
  currency-converted, split across matched clicks and re-sent periods overwrite.
  See `docs/dolphin-fbtool.md`.

### Fixed
- 🪟 The '+' buttons next to group/source/network selects and the 'Группы'/
  'Источники' buttons on the Campaigns/Landings pages were dead (no click handler
  since the first commit) — all now open the right dialog and auto-pick the
  created item. Group deletion works for campaign and landing groups too, and the
  groups modal follows the theme in dark mode.
- 🛬 Keitaro migration: traffic-source parameters were imported as the raw
  Keitaro blob and never captured on clicks, which silently broke cost matching —
  they are now normalized into Orbitra's `{alias, param, macro}` format, and
  landing groups import with landings attached.

## 📝 What's New in v0.9.7.6

### Added
- 📤 **Facebook Conversions API.** Conversions are now sent to Meta from the
  server, not only from the browser pixel — the events ad blockers, ITP and iOS
  strip out reach the optimiser again. Set it up per campaign (Integrations tab):
  pixel ID, Conversions API token, a status→event mapping, optional test event
  code and proxy. Events carry `fbc`/`fbp`, the click's IP and user agent, and
  SHA-256 hashed email/phone/name/geo, with an `event_id` that deduplicates
  against the browser pixel. Delivery rides the existing S2S retry queue, so a
  slow answer from Meta never delays the reply to the affiliate network's
  postback. `rejected` and `trash` are unmapped by default — feeding a rejected
  lead back as a conversion teaches the algorithm to buy the wrong traffic.
- 💱 **Ad spend is converted into the tracker's currency.** Meta and Google bill
  in the ad account's currency, which was previously written into `clicks.cost`
  as-is and mixed with revenue in another currency. Rates are cached for 12
  hours; `fx_rates_manual_json` pins them by hand.
- 🧭 **Both integrations are managed from the Integrations page** — their own
  entries with an account list (status, next update), add/edit, manual *Update
  spend*, pause/resume, clone, delete and search. Cost connections remain visible
  on the Aggregators page and a campaign's pixel remains editable from the
  campaign; these are views of the same records.
- 📖 **[docs/facebook.md](docs/facebook.md)** — tokens, macros, mapping,
  troubleshooting. Plus `tests/facebook_integration_test.php`, which covers the
  whole path without touching the network.

### Fixed
- 💰 **Facebook cost import fetched nothing at all.** The insights request asked
  for a field named `currency`, which does not exist on that endpoint — Meta
  rejects the entire request when one field identifier is wrong, so every sync
  returned zero rows. The field is `account_currency`.
- 🔇 **A failing sync reported success.** Transport and API errors were swallowed
  and returned as an empty array, so an expired token showed up as "success, 0
  records". Errors now land in `last_sync_error` with Meta's own message.
- 🎯 **Clicks never recorded the ad IDs cost import matches on.** The traffic
  source templates advertised `{{ad.id}}` and `{{adset.id}}`, but click logging
  kept only a fixed list of `sub_id_*` keys — so imported spend could not attach
  to any click and campaigns showed cost 0. Capture now lives in one shared
  helper used by both the redirect path and the Click API, and covers the
  ad-network IDs, the platform click identifiers (`fbclid`, `gclid`, `ttclid`, …),
  the `_fbp`/`_fbc` cookies and any parameter the campaign's source declares.
- 🎯 **Spend was never attributed at adset level.** Matching jumped straight from
  ad ID to campaign ID, so `{{adset.id}}` did nothing. The chain is now
  ad → adset → campaign, and a connection can point each level at a different
  click parameter for traffic that passes through an app.
- 📅 **The sync window was too short to be correct.** Platforms restate spend for
  days afterwards; two days of lookback froze it wrong. Cost connections now
  re-read the last 5 days, and 30 days on their first sync.
- 📄 **Pagination could stop early** — the cursor was rebuilt by hand instead of
  following Meta's `paging.next`.

### Changed
- Facebook connections gained **API version** and **proxy** settings — Meta
  periodically geo/IP-filters requests from a tracker's server IP.
- **Test connection** reads the ad account (name, currency, timezone, status)
  instead of an insights report, which can be empty and valid for a dead token.
- Pixels can be **edited**, not only created and deleted.
- All new interface strings are translated in every locale (en, ru, uk, es, de,
  fr, zh); aggregator field labels moved out of the PHP engines into the locale
  files.

## 📝 What's New in v0.9.7.5

### Fixed
- 📦 **Fresh installs no longer break on a missing `bcmath` extension.** The
  IP2Location and IP2Proxy readers require `ext-bcmath`; without it Composer
  refused the lock file outright, so `install.sh` now installs the extension and
  falls back to the version-pinned package when the PHP CLI is not the
  distribution default.
- 🔐 **A failed step can no longer leave the directory root-owned.** The Composer
  failure above aborted the installer under `set -e`, skipping the closing
  ownership handover — which is why the update button then reported that part of
  the directory belongs to another user. The `chown` now runs from an `EXIT` trap,
  and the Composer and frontend build steps are non-fatal.
- 🔄 **Admin updates report the fix instead of the failure.** A dependency install
  blocked by `bcmath` is retried without that platform check, and the panel shows
  the one command that installs the extension.

## 📝 What's New in v0.9.7.4

### Added
- 🛡️ **Cloaking now uses real network-security data.** Separate IP2Proxy LITE
  PX12, IP2Location ASN LITE and MaxMind GeoLite2-ASN readers provide proxy,
  VPN, Tor, datacenter, threat, fraud-score, ASN and ISP signals to live traffic
  routing and Traffic Simulation.
- 🤖 **The campaign editor now exposes a Keitaro-style `Bot: Yes` filter.** An
  intercepting `Bot: Yes` + `Do nothing` stream consumes suspicious traffic,
  while clean visitors continue to regular streams. The same verdict is used by
  direct campaign URLs, Traffic Simulation and the Click API used by KClient.
- 🔄 **Admin updates install Composer dependencies automatically**, so existing
  Git installations receive new database readers without an extra SSH step.

### Fixed
- 🗄️ **Geo database files can no longer overwrite the wrong provider slot.**
  DB11, ASN, PX12, MMDB and Sypex formats are classified and validated before
  activation; an older PX12 file misplaced as DB11 is migrated automatically.
- 🔗 **Short campaign links work directly at `/campaign-alias` on Apache.**
- 📦 **The manual Git-update hint now shows the complete safe command:** a
  fast-forward-only pull followed by the locked production Composer install.
  The updated guidance is available in all seven interface languages.

## 📝 What's New in v0.9.7.3

### Added
- 🕵️ **Traffic Simulation now runs the real Cloak detector** and reports the
  safe/money-page decision with ASN, ISP, passive detection reasons, JavaScript
  execution and `navigator.webdriver` inputs.

### Fixed
- 🔄 **Admin updates recover from unfinished Git conflicts automatically.** The
  updater detects the exact `unmerged files` state before pulling, aborts stale
  merge/rebase operations and restores a clean working tree. If restoring local
  code changes from stash conflicts with the new release, those changes remain
  safely stored in Git stash and the partial conflict is removed, so the next
  update is not blocked again.
  > An installation already stuck on `0.9.7.2` must receive the repair once over
  > SSH: `sudo -u www-data git -C /var/www/orbitra reset --hard HEAD && sudo -u www-data git -C /var/www/orbitra pull --ff-only origin main`.
  > After `0.9.7.3` is installed, future conflicts are repaired from the panel.
- 🛡️ **Residential traffic is no longer mistaken for hosting traffic.** Precise
  provider matching keeps Comcast, CloudMTS and InterServer on the money-page path
  while known datacenter ASNs and cloud providers still resolve to the safe page.

## 📝 What's New in v0.9.7.2

### Added
- 📊 **Landing-to-Offer transition metrics** (`LP Clicks` and `LP CTR`) added to Landings analytics API and UI table view.
- 🛡️ **Cloudflare Turnstile** anti-bot verification support in bot challenges.
- ✏️ **Direct stream filter editing**: click any filter item in Campaign Editor to modify values without deleting and re-creating.
- 🌐 Full **100% key parity** across all 7 supported UI locales (`ru`, `en`, `de`, `es`, `fr`, `uk`, `zh`).

### Fixed
- 🩹 **Intercepting streams URL resolution**: fixed `$finalUrl` and redirect type calculation in `landing_offer` and `cloak` schemes so missing landing URLs fall back to the offer URL instead of raising `URL not found.`.
- 🛡️ **Prefetch false positives**: removed broad `no-cors` check in `core/prefetch.php` that improperly blocked legitimate user navigations over VPNs and mobile browsers.

## 📝 What's New in v0.9.7.1

### Added
- 📊 **Cohort analysis** (the *Trends* tab is now *Analytics*). Campaigns are grouped by the month or quarter they were created, and each cohort is tracked across its lifetime periods (M0 = launch month, M1 = next month, …). Three views work together: **retention curves** (one line per cohort decaying across M0..Mn), a **heatmap matrix** with an **Absolute / % of M0** toggle so cohorts of different sizes can be compared by decay shape, and a **per-cohort summary** with totals and ROI. Revenue and conversions are attributed to the period the event actually occurred — delayed payouts no longer collapse into the launch month — and CR is the share of clicks that converted (0–100%). An in-page guide explains what cohorts are, why they matter, and how to read the matrix, in all seven languages.
- 🌐 **First-time visitors open in their browser's language**, not a hard-coded Russian default. The same `'ru'` fallback was removed across user creation, login and profile settings.

### Fixed
- 🩹 **click.php returned a bare HTTP 500 on any campaign without a configured stream/offer** (`FOREIGN KEY constraint failed` on `offer_id = 0`). Clicks now log with `offer_id NULL`, and the click path is wrapped in try/catch so any failure returns a JSON error plus a `system_logs` row instead of a silent empty-body 500.
- 📈 **The Trends chart only plotted days that had traffic**, so a single active day looked "stuck" at the X origin. Days and hours are now zero-filled across the selected range, matching the dashboard.
- 🌐 **Hard-coded Russian labels in the Trends tooltip, and browser-locale date/number formatting in Cohort**, now follow the selected UI language across all seven locales. The heatmap intensity reads `--color-primary` via `color-mix`, so it adapts to every theme instead of a hard-coded coral hex.

## 📝 What's New in v0.9.7.0

### Added
- 🌐 **A local landing is served at its own `/lander/<slug>/`, matching Keitaro.** The Folder field advertised that URL from the day slugs arrived, but nothing answered it — a landing's files were reachable only during a real click, resolved from the `orbitra_lp` cookie, so a landing could not be looked at without pushing traffic through a campaign. As Keitaro does, the served HTML gets a `<base>` tag injected so the page's relative paths (`img/a.png`) resolve inside its folder, which is exactly why Keitaro's requirements say the landing must not ship a `<base>` of its own. Assets go through the same extension whitelist and path containment the click flow uses; `.php` is neither served nor executed there, since a PHP landing needs the click context this route has none of. Nothing is logged — this is a look at the landing, not a visit to a campaign.
- 👁 **Code / Preview toggle in the landing editor.** Preview loads the landing from `/lander/<slug>/` in an iframe, so it is the page as a visitor receives it — images, video, CSS and scripts included — not a rendering of the HTML on its own. Switching to it forces a reload, so it never shows the state from before the last save or upload, and a button opens the same URL in a new tab.
- 🛡️ **The Domains page says why SSL is unavailable.** On shared hosting where `shell_exec` is removed or Certbot is not installed, a parked domain used to sit at "waiting for certificate" with no hint that the server can never issue one. The page now checks the server's capability once on load and shows a banner naming the blocker (no shell, no Certbot, no nginx config, non-writable ACME dir) so the operator knows to use a dedicated VPS or issue through their hosting instead of waiting.

### Changed
- 🧩 **The campaign stream no longer carries its own copy of the landing form.** `CampaignEditor` held a 271-line duplicate of `LandingEditor` — which is exactly why the two behaved differently: the stream's copy accepted a ZIP while creating a landing and the Landings page did not, and every fix had to be written twice. The stream now renders the same `LandingEditor` and receives the saved id through a new `onSaved` callback to wire it into the rotation. `CampaignEditor` sheds 309 lines along with the state that existed only to feed the copy (landing groups, the campaign list, the postback key, the offer-link hint) and three API calls it made on every open.

### Fixed
- 🗜 **Creating a local landing from the Landings page left nowhere to upload the archive.** The form said "save the landing settings first" and then closed the window, so the file panel could only be reached by finding the landing in the list and reopening it. The editor now stays open after saving and switches to edit mode in place, and the create form takes a ZIP directly — held until the landing has an id, then uploaded.
- 🔒 **A certificate was attempted once and never again.** Issuance was a single background shot fired when a domain was saved with HTTPS-only ticked, and nothing was scheduled to run it again — `install.sh` installs no cron at all. Minutes after pointing an A record DNS has not propagated, so that one attempt failed, the domain was marked `failed`, and it stayed on a broken certificate until a human reopened and re-saved it. `core/ssl_manager.php` now owns this: every parked domain is a candidate, the A record is checked against this server's address *before* Certbot runs so a domain that cannot validate never spends one of Let's Encrypt's five failures per hostname per hour, and a failure is rescheduled on a widening ladder (1h, 1h, 2h, 6h, then 12h) instead of being final. The certificate is still requested the moment a domain is added; the hourly pass only finishes what could not be issued then.
- 🔓 **Issuance was tied to the HTTPS-only toggle.** A domain added without it sat at `none` and never had a certificate requested at all, and turning the toggle off later reset the status and dropped the domain out of the queue. Parking a domain is now the request for a certificate, as in Keitaro; HTTPS-only only decides whether `http://` redirects to `https://`.
- 🔐 **The update button in the panel could never replace the frontend bundle.** `install.sh` chowned the tree to `www-data` and only afterwards built the frontend as root — and since Vite empties and recreates `frontend/dist` on every build, the bundle and its directory ended up root-owned. Replacing a file needs write permission on its *directory*, so `git pull` running as the web user died with `unable to unlink old 'frontend/dist/assets/index.js': Permission denied`. The chown now runs last, after the frontend build and the MCP dependency install, so the whole tree — `.git` included — belongs to the web server. **Existing installs need one manual fix:** `sudo chown -R www-data:www-data /var/www/orbitra`.
- 💬 **A permission failure during update showed git's raw wording.** It now reports the cause and the exact `chown` command, the way "dubious ownership" already did.
- 📦 **"ZIP upload error" was all a failed archive upload ever said.** Every non-2xx answer landed in a `catch` that alerted a fixed string, so an oversized archive, a missing PHP extension and a read-only directory looked identical. `upload_landing` now names each failure: `post_max_size` exceeded (with the actual sizes), each `UPLOAD_ERR_*` code in words, a missing `zip` or `fileinfo` extension with the package to install, a `landings/` directory the web server cannot write to with the `chown` command, and the MIME type actually detected when the file is not a ZIP. The handler is wrapped, so a fatal returns JSON instead of a 500.
- 🕳 **A failed extraction was reported as a successful upload.** The return values of `mkdir()` and `ZipArchive::extractTo()` were discarded, so on a permissions problem the panel claimed success while the landing quietly served nothing. Both are checked now.
- 🗜 **An archive PHP cannot decompress looked like a permissions problem.** The "maximum compression" preset in 7-Zip and WinRAR writes LZMA, BZip2 or PPMd entries, and libzip is normally built with Store and Deflate only — so the archive opens, the file list reads fine, and only extraction fails. A failed extraction now inspects each entry's compression method, names the unsupported one and says to repack with Deflate.
- 🔗 **A half-chain certificate showed as installed.** A `fullchain.pem` holding only the leaf — missing the intermediate — opens in Firefox (which fills the gap from its own store) but fails in Chrome and curl with *unable to get local issuer certificate*. `core/ssl_manager.php` now counts the certificates in the chain on every run and marks the domain `failed` with an `incomplete_chain` reason when fewer than two are present, so the browser never gets served a chain it cannot close — both right after issue and later, catching a file that went wrong after the fact.
- 🌐 **Server error messages were hard-coded in Russian.** `upload_landing` and the SSL worker returned whole Russian sentences; in a panel that ships seven locales a backend string the frontend cannot translate is a bug. The backend now returns machine codes plus a `detail` object of the measured facts, and the frontend phrases them per locale — Certbot's own output passes through untranslated.

## 📝 What's New in v0.9.6.8

### Fixed
- 🧯 **"Network error" when creating a landing, on any server without `php-intl`.** A local landing with the *Folder* field left empty derives its slug from the name, and that derivation called `transliterator_transliterate()` — a function only the `intl` extension provides, which `install.sh` never installed. On PHP 8 a call to a missing function raises an `Error` that `@` does not suppress, so the save died as a 500 and the panel could only report it as a network failure. Slugs now transliterate through a built-in Cyrillic/Latin table when `intl` is absent, and `php-intl` has been added to the installer for the wider coverage it still gives.
- 🔎 **Every failed landing save looked the same.** `save_landing` could let a fatal escape as a 500, and the forms alerted one fixed string for any thrown request — a PHP error, a rejected folder name and an unreachable server were indistinguishable. The endpoint now returns a real JSON error (and logs the fatal), the forms show the server's message, and slug problems are translated in all seven languages instead of showing raw codes like `landing_slug_taken`.
- 🗂 **An auto-generated folder name that collided blocked the save.** A landing named after an existing one was refused for a folder the operator never chose. A derived slug now falls back to `name-2`, `name-3`, …, and to `landings/<id>/` if nothing is free; a folder typed by hand still reports the conflict.

## 📝 What's New in v0.9.6.6

### Added
- 🪟 **Create and edit a landing without leaving the campaign stream.** The old 3-field quick form (name / type / URL) is now the full landing editor — four type tabs, group and status, a named folder for local landings, a redirect-method selector, the complete Action block (send-to-campaign, 404, text, HTML, nothing), and the offer-link hint with copy button and HTML/JS/PHP formats. A new **Edit** button next to each landing in the stream opens the existing landing in the same modal, so the full form is reachable from the place it is actually wired up. The misleading `window.location.replace('{offer}')` placeholder has been removed from the Action text/HTML fields.

## 📝 What's New in v0.9.6.5

### Added
- 🗂 **The landing form now matches Keitaro.** The landing type is chosen from four tabs (Local / Redirect / Preload / Action) instead of a dropdown. A local landing gets a named folder — `/lander/<slug>` — so its files unpack into a readable directory rather than `landings/<id>/`. Existing landings are backfilled a slug from their name, and renaming the slug moves the folder on disk. A redirect landing can pick its redirect method (HTTP 302 / JavaScript / Meta refresh), the way an offer already could. The offer-link hint now carries a copy button on every snippet and, for redirect landings, the three integration shapes an external page needs: plain HTML, `document.write` JS, and server-side PHP with `_token`. The hint is also shown for redirect landings, not only local/preload. Localised across all seven UI languages.

## 📝 What's New in v0.9.6.4

### Added
- 🔒 **The admin panel path is configurable** — *Settings → System → Admin panel path* moves the panel from `/admin.php` to `/your-path`, after which `/admin.php` answers 404. The point is the bare server IP: `/admin.php` is the first thing anything walking a hosting provider's IP range tries. It hides the panel rather than replacing the password — `/api.php` still answers and still enforces its own authentication. Forgot the path? `php /var/www/orbitra/cli/admin_path.php reset`.
- 🛠 **`cli/nginx_sync.php`** — one command that rebuilds the web-server config from the database, repairs Certbot renewal configs left behind by the old issuance mode, and generates the self-signed certificate used for HTTPS on the IP: `sudo php /var/www/orbitra/cli/nginx_sync.php`. Run it once after updating. `fix_nginx.sh` and `restore_https.sh` are now wrappers around it.
- 🔗 **The `{offer}` macro in local landings** — the buy button is now written exactly as it is in Keitaro, `<a href="{offer}">Buy</a>`, and the tracker substitutes the URL of the offer bound to the stream, click id included. The macro used to reach the browser verbatim, so the button led nowhere and `/?_lp=1` was the only thing that worked — with nothing in the interface saying so. `{offer}`, `{offer_id}`, `{clickid}`, `{subid}` and every click parameter (`{keyword}`, `{sub_id_1}` … `{sub_id_30}`) are substituted, with values from the URL escaped. No other braces are touched, so JS template literals, Vue and Angular syntax inside a landing survive. With no offer on the stream `{offer}` becomes `/?_lp=1` rather than an empty link. The landing editor now shows the snippet.

### Fixed
- 🌐 **The panel became unreachable at the server IP once a domain was parked** — and stayed that way, so deleting the domain you always used meant remembering which other domains were parked before you could get back in. Two causes behind one symptom. The generated Nginx config listed only the parked domains in `server_name`, with no catch-all block owning requests addressed to the bare IP; and certificates were issued with `certbot --nginx`, whose installer plugin edits that same file — narrowing `server_name` to the domain being issued and appending `return 404`, which is what an IP request then landed on. It came back on every renewal. The config now always begins with a `listen 80 default_server; server_name _;` block, so access by IP is structural rather than incidental, and certificates are obtained with `certbot certonly --webroot`, which never touches Nginx. Orbitra writes the HTTPS server blocks itself, as it already did.
- 🔐 **HTTPS on the server IP served a parked domain's certificate** — Let's Encrypt does not issue for bare IP addresses, so `https://<ip>/admin.php` matched whichever domain owned the first 443 block and failed on a name mismatch. A self-signed certificate for the IP now backs a `listen 443 ssl default_server` block: the browser warns, but the panel opens.
- 📜 **`/.well-known/acme-challenge/` was swallowed by the dotfile deny rule**, which made webroot certificate issuance impossible. It is now served from an explicit `location ^~` placed above the deny.
- ⚙️ **The config hardcoded `php8.3-fpm.sock`** — on any other PHP version the first domain save produced a config that failed `nginx -t`. The socket is detected.
- 🧯 **A config that failed `nginx -t` was installed anyway** — the writer staged it as `orbitra.tmp`, a file Nginx does not include, so the test ran against the *old* config and the untested file was renamed into place. A bad generation could leave a server that would not come back up after a restart. The new config is now tested where Nginx actually reads it, and the previous one is restored if the test fails.
- 🧩 **The installer, the panel and the recovery scripts each generated their own Nginx config** and had drifted apart. They now share `core/nginx_config.php`.
- 💾 **Reinstalling destroyed uploaded landings** — `install.sh` backs up the database, `var/` and `geo/` before it `rm -rf`s the directory, but `landings/` was not on that list, so every uploaded landing disappeared without warning. It is now preserved alongside the database. Also: a run that died before the restore step left a backup in `/tmp`, and `cp -r` copies INTO an existing destination — the next run produced `var/var`, `geo/geo` and lost part of the restore. Stale backups are cleared first.
- ⬆️ **The update button said nothing useful about "dubious ownership"** — pulling an update over SSH as root once changes who owns the tracker directory, after which git refuses to touch it as the web user. The panel surfaced that as `Unsafe branch: fatal: detected dubious ownership...`, because the error text landed in the branch check instead of a branch name. The condition is now detected before any other git call, and the reply names the directory owner, the user the update runs as, and the exact `chown` to fix it.

### For developers
- 🔤 **`npm run check:i18n`** — checks the translations against the code rather than against each other: it resolves every `t('...')` used in the components in all seven languages and exits non-zero. Key parity cannot catch this — the raw keys that shipped in 0.9.6.3 were identically wrong in all seven files.

## 📝 What's New in v0.9.6.3

### Fixed
- 🔌 **MCP connector returned 0 tools** — `mcp.php` read `tools.json` with `json_decode(..., true)`, which turns an empty `{}` into an empty PHP array, and `json_encode` turns an empty PHP array back into `"[]"` instead of `"{}"`. So every parameter-less tool served `"properties":[]` and `"additionalProperties":[]`, both of which the MCP spec defines as records, and the client rejected the whole `tools/list` with *"expected record, received array"* — no tools loaded at all. `inputSchema` is now normalised at manifest load: `properties` / `patternProperties` / `$defs` / `definitions` become objects, and an array-valued `additionalProperties` becomes `true`. The fix holds regardless of which `tools.json` revision is deployed, and is recursive over nested schemas.
- 🤖 **Every save in the panel 503'd** — the bulk edit in 0.9.6.2 rewrote the request-body helper so it recursed on itself instead of reading `php://input`, so every real POST (login, bot lists, every form save) spun until the PHP-FPM worker died and nginx answered 503. The helper reads the stream again.
- 🤖 **The bot IP table rendered blank rows** — the panel read `item.value` while the table column is `ip_or_cidr`, so every row was empty. The API now returns a stable `value` alias alongside the field. Bot lists are also searched and paged in SQL now: a flat first-1000 fetch left everything past row 1000 invisible and impossible to delete, and blacklists routinely run to tens of thousands of rows. The panel gained a search box, a *shown-of-total* counter and a *load more* button.
- 💾 **Reinstalling destroyed uploaded landings** — `install.sh` backs up the database, `var/` and `geo/` before it `rm -rf`s the directory, but `landings/` was not on that list, so every uploaded landing disappeared without warning: the repository only carries an empty `landings/` with a `.gitkeep`. It is now backed up and restored alongside the database.
- ⬆️ **The update button said nothing useful about "dubious ownership"** — pulling an update over SSH as root once changes who owns the tracker directory, after which git refuses to touch it as the web user. The panel surfaced that as `Unsafe branch: fatal: detected dubious ownership...`, because the error text landed in the branch check instead of a branch name. The condition is now detected before any other git call, and the reply names the directory owner, the user the update runs as, and the exact `chown` to fix it.
- 🔤 **Raw translation keys showed across the panel** — `t()` returns the key itself when a translation is missing and no fallback is passed, and 39 keys had no entry: the bot-list keys had been inserted under the wrong locale section (anchored on `"noRecords"`, which occurs earlier in the file), so the Bots page rendered literal key names; and the payout-model dropdown, campaign parameters, stream device types, all 15 admin tile descriptions and six common labels had no translation at all. Re-anchored and added across all seven locales (de / en / es / fr / ru / uk / zh); parity at 1838 keys.

## 📝 What's New in v0.9.6.2

### Added
- 🔌 **Remote MCP over a URL** — the tracker now serves MCP over HTTP from a new `mcp.php`, so an AI assistant connects with a single address: **Users → API Keys** grew a button next to each key that copies a ready-made `https://your-tracker/mcp.php?k=KEY` to paste into Claude → Settings → Connectors → Add custom connector. No Node, no cloning the repo onto your own machine — and it is the only way to reach Orbitra from Claude in the browser, where local stdio servers are not supported at all. The key rides in the URL because that dialog has no field for one and speaks only OAuth, so treat the URL as a credential; revoking the key cuts access, and a Read key still cannot change anything. Both routes expose the same tools: `mcp/tools.json` is generated from the Node server and `node mcp/src/manifest.js --check` fails if they drift.

### Fixed
- 🖼 **Uploaded landings showed no images, video or fonts** — a local landing's files sit in `/landings/<id>/`, but the page itself is served at the campaign URL, so every relative path in it (`<img src="hero.jpg">`) was requested from the domain root, where nothing exists. The landing arrived as bare text. Such requests are now resolved against the landing the visitor was actually shown, the same way Keitaro does it, so an uploaded archive works with no edits: relative paths, `srcset`, `url()` inside stylesheets, anchors and forms all keep working because the HTML is passed through untouched. Byte ranges are supported (Safari refuses to play a `<video>` without them), along with `ETag`/`304` and browser caching.
- 🎞 **Media requests could log a phantom click** — the guard that stops background asset fetches from being counted as clicks listed `.jpg` and `.png` but not `.webp`, `.mp4`, `.avif` or `.webm`. Those requests fell through into campaign matching, so a landing with five videos could add bogus clicks to a campaign and hand the browser HTML where it asked for a file.
- 🤖 **The bot blacklists did nothing at all** — on **Settings → Bots**, adding IPs or user-agent signatures, deleting one entry and clearing the list were all no-ops, because the panel and `api.php` disagreed about the request body and the HTTP method. Worse, the page reported success either way: after pasting a list you were told "Records added: 40" while the table stayed empty. All three operations work now, the counter reports what actually landed (with duplicates listed separately), and a failure is shown instead of swallowed. If you added a blacklist before this release, add it again — it was never stored.
- 🤖 **The MCP page pointed people at the wrong dialog** — nothing said that the server is wired up through the Claude Desktop config file rather than through "Add custom connector". That dialog only takes remote MCP servers over HTTPS with OAuth and has no field for an API key, so people pasted their tracker URL into it and hit a dead end. The page now says so outright, shows the Settings → Developer → Edit Config path, puts an absolute path to `node` in the template instead of a bare `node` (the app runs with a different `PATH`, so with nvm or Homebrew the server failed to start silently), and warns that a Read key only returns analytics. The same went into `docs/mcp.md` and `mcp/README.md`, along with a "server didn't show up?" checklist.
- 🔒 **CSRF was only enforced on POST** — `PUT`, `PATCH` and `DELETE` skipped the check, and API keys skipped the read-only check with them. No endpoint accepted those methods before, so nothing was exploitable, but the guard now covers every method that can change data.

## 📝 What's New in v0.9.6.1

> ⚠️ **If you are on 0.9.6.0, update.** Outbound S2S postbacks were queued but never delivered unless you installed the delivery cron by hand, so postbacks have been piling up undelivered. After updating, open **Settings → Automation → Postback queue** and press **Install delivery cron** — everything still in the queue goes out on the next run.

### Fixed
- ♻️ **Postback queue never ran** — there was no UI to install the delivery cron, so on a default install nothing left the queue. The Automation settings page now has a Postback queue panel with install/remove buttons, per-status counters, worker health and the ready-made crontab line.
- 📋 **S2S log marked every postback as an error** — the log read the legacy `status_code` column that the queue no longer writes. It now shows real delivery status, attempt count, HTTP code, next retry and last error; pre-0.9.6 rows still render correctly.
- 💀 **Rows abandoned by a crashed worker were stuck forever** — a postback claimed as `in_flight` when the worker died was invisible to the queue from then on. A stale claim is now returned to the queue after 10 minutes.
- ⏱ **Retry ladder stopped one step short** — the final 24h retry was unreachable, so a row died after 2h instead. The full 60s → 5m → 30m → 2h → 24h schedule now runs.
- 💰 **Today's ad spend froze at the first sync** — re-syncing Facebook/Google Ads for the current day was discarded as a duplicate, so spend accrued after the first sync never landed and ROI was wrong. Cost records are now upserted and attribution is recomputed, which also makes repeated syncs idempotent.
- 🕵️ **Cloaking sensitivity had two levels, not three** — `medium` behaved identically to `high`. Signals are now split into hard (blocklist/ASN hits, missing or crawler UA) and soft (hosting keyword in ISP, missing `Accept-Language`): low = hard only, medium = hard or two soft, high = any single signal.
- 🔒 **SSRF re-check could be bypassed** — the check before delivery skipped hosts written as a bare IP, so `http://127.0.0.1/` slipped through the second gate. Also hardened `curl proxy`, which fetched a stored URL with no validation at all. A failed DNS lookup is now a retryable error instead of a permanent failure.
- 🔗 **`form_submit` dropped the port** — an offer on a non-standard port was posted to the default one.

### Added
- 🤖 **JS fingerprint check for cloaking** (optional, off by default) — the layer 0.9.6.0's notes promised but did not ship. The visitor gets the safe page first; background browser checks decide whether to forward them to the money page over a signed, short-lived link. Anything that doesn't run JavaScript stays on the safe page.

## 📝 What's New in v0.9.6.0

### Added
- 🔀 **Redirect types honored at runtime** — offers now actually use their `redirect_type` setting. In addition to the default HTTP 302, you can choose **JS redirect** (bypasses server-side redirect blockers, keeps referrer), **Meta refresh** (maximum compatibility), **Iframe / frame** (renders the offer inside a full-page iframe), **Form submit** (posts data in the body instead of the URL), and **curl proxy** (serves a remote page through your server with an injected `<base>` tag). Each option explains when to use it right in the editor.
- 🕵️ **Cloaking (safe page / money page)** — a new `cloak` stream mode routes bots, moderators and datacenter/VPN traffic to a safe page, while real visitors see the money page. Detection layers: free datacenter & hosting ASN lists, VPN/proxy flags, the existing bot IP/UA blocklists, and an optional active JS fingerprint check (safe page first, forward to the money page only once the browser proves itself). Per-stream sensitivity and layer toggles.
- ♻️ **Durable S2S postback queue with retry** — outbound postbacks are no longer fire-and-forget. Each one is persisted and delivered by a background worker with exponential backoff (60s → 5m → 30m → 2h → 24h; 1 initial attempt plus 5 retries) and full status logging. **Install the delivery cron in Settings → Automation** — nothing leaves the queue until it runs. Fixes along the way: POST postbacks now send a body, and the macro set is extended (`{sub_id_1..30}`, `{campaign_id}`, `{cost}`, `{revenue}`, `{profit}`).
- 💰 **Automated cost import (Facebook Ads / Google Ads)** — two new aggregator engines pull daily spend and attribute it to clicks via the ad IDs your traffic-source templates already capture, so ROI/profit dashboards are no longer zero-cost. Token-based auth now (long-lived/system tokens); built on the existing `aggregator_connections` pattern with an OAuth-ready `oauth_tokens` table for later.

## 📝 What's New in v0.9.5.0

### Added
- 🤖 **AI assistant integration (MCP)** — a new **Model Context Protocol** server in [`mcp/`](mcp/README.md) connects Orbitra to Claude Desktop and other MCP clients. Ask in plain language to analyse performance, or manage the tracker: create campaigns in bulk, connect domains, edit offers, add traffic sources and more. 31 tools covering read (metrics, campaigns, conversions, reports) and management (create/update/delete). See [docs/mcp.md](docs/mcp.md).
- 🔑 **API-key authentication** — the API now accepts a personal key via `Authorization: Bearer <key>` or `X-Api-Key: <key>`, with **read** (analytics only) and **write** (management) scopes. Generate keys in **Users → API Keys**; write keys are what the MCP server uses for management actions. Browser sessions + CSRF are unchanged.

## 📝 What's New in v0.9.4.2

### Fixed
- 🎨 **Navbar alignment** — top menu items no longer wrap to a second line (`whitespace-nowrap`), and the two-word labels were made more compact across all 7 languages, so the bar stays neatly aligned. Full page titles are unchanged.

## 📝 What's New in v0.9.4.1

### Added
- 🤖 **Multilingual Telegram bot** — the bot now speaks all 7 interface languages (🇬🇧 English, 🇷🇺 Russian, 🇺🇦 Ukrainian, 🇪🇸 Spanish, 🇨🇳 Chinese, 🇫🇷 French, 🇩🇪 German). Switch with `/lang en|ru|uk|es|zh|fr|de`.

### Docs
- 🖥 Added a **System Requirements** section to the README (runs comfortably on 1 vCPU / 1 GB RAM / 20 GB SSD).
- 📚 Refreshed the project documentation to the current version and feature set (7 languages, Bot Challenge, platform templates).
- ✉️ Updated the support contact to **info@orbitra.link**.

## 📝 What's New in v0.9.4.0

### Added
- 🌍 **Full Multi-Language support** — expanded translations beyond 🇷🇺 Russian and 🇬🇧 English. The tracker is now fully localized with 100% key parity in 🇺🇦 Ukrainian, 🇪🇸 Spanish, 🇨🇳 Chinese (Simplified), 🇫🇷 French, and 🇩🇪 German.
- 🤖 **Bot Challenge system** — per-campaign human verification to stop corporate email security bots and clickbots from polluting your stats. Enable in the campaign editor and choose from:
  - **reCAPTCHA v2** — classic "I'm not a robot" checkbox
  - **reCAPTCHA v3** — invisible, score-based (configurable threshold)
  - **Custom code** — paste any HTML/JS verification widget (fully flexible)
- ✉️ **Email source editor improvements** — collapsible ESP merge-tag reference table for Mailchimp, Klaviyo, ActiveCampaign, GetResponse, Brevo, and SendGrid in the source editor.
- ⚙️ **reCAPTCHA settings** — Site Key + Secret Key for v2 and v3 configurable from **Integrations → reCAPTCHA**.

### Technical
- Clicks are logged **only after** a successful challenge — bots never appear in statistics at all.
- Challenge state is signed with HMAC-SHA256 (using the existing postback key) and expires in 15 minutes, preventing replay attacks.
- All new UI strings are fully i18n-covered across all 7 locales with 100% key parity.

## 📝 What's New in v0.9.3.9

### Added
- 🤖 **Bot Challenge system** — per-campaign human verification to stop corporate email security bots and clickbots from polluting your stats. Enable in the campaign editor and choose from:
  - **reCAPTCHA v2** — classic "I'm not a robot" checkbox
  - **reCAPTCHA v3** — invisible, score-based (configurable threshold)
  - **Custom code** — paste any HTML/JS verification widget (fully flexible)
- ✉️ **Email source editor improvements** — when editing a traffic source based on the Email template, a collapsible ESP merge-tag reference table shows example macros for Mailchimp, Klaviyo, ActiveCampaign, GetResponse, Brevo, and SendGrid so you know exactly what to paste into the macro field.
- ⚙️ **reCAPTCHA settings** — Site Key + Secret Key for v2 and v3 configurable from **Integrations → reCAPTCHA**. Score threshold for v3 is also configurable.

### Technical
- Clicks are logged **only after** a successful challenge — bots never appear in statistics at all.
- Challenge state is signed with HMAC-SHA256 (using the existing postback key) and expires in 15 minutes, preventing replay attacks.
- All new UI strings are fully i18n-covered in both 🇷🇺 Russian and 🇬🇧 English locales.

## 📝 What's New in v0.9.3.8

### Changed
- 🌍 i18n cleanup: hardcoded Russian UI strings moved into the translation system (`en.js`/`ru.js`) across the bulk-import dialog, traffic sources, source editor, domains and migrations pages, so the interface follows the selected language everywhere. Traffic-source and affiliate-network template names (including the new Email and platform templates) are now localized instead of being hardcoded on the backend. English and Russian locales are now at full key parity.
- 📖 The README has been fully translated to English for the international audience.

## 📝 What's New in v0.9.3.7

### Added
- ✉️ **Email** traffic source template — for email marketers. Comes with pre-configured sub-parameters `subscriber_id`, `campaign_id`, `list_id`, `broadcast_id`, `esp`, which you map to your ESP's merge tags.
- 🌐 **Platform-level affiliate network templates**: Everflow, CAKE, HitPath, Affise, TUNE/HasOffers. Any smaller network running on these platforms can now be connected by selecting the platform template — without a separate entry per company. The click-id parameter appended to the offer is filled in with each platform's standard field (Everflow `sub1`, CAKE `s1`, HitPath `c1`, Affise `sub1`, TUNE `aff_sub`).

## 📝 What's New in v0.9.3.6

### Fixed
- 🐛 HTTP 500 error on landing-only streams (a stream with a landing and no offer). The click log required an offer (`offer_id NOT NULL` + foreign key), so a no-offer click failed with a DB error before the landing could load. The `clicks.offer_id` column is now nullable (automatic DB migration), no-offer clicks are logged with NULL, and a logging failure can no longer break the page. Landing statistics keep working; only the offer is left unattributed — as expected.

## 📝 What's New in v0.9.3.5

### Fixed
- 🐛 More reliable auto-update: when locally modified code files blocked `git pull` (the "Your local changes would be overwritten" error), the updater now resets those changes itself and retries the update. Data is not affected — the database, uploaded landings and geo databases live outside git, and `config.php` is preserved.

## 📝 What's New in v0.9.3.4

### Added
- ✨ Transition from a local landing to an offer via the `/?_lp=1` link (Keitaro-compatible). On the landing page, set the offer button as `<a href="/?_lp=1">Offer</a>` — on click the tracker finds the offer linked to the click and redirects with macro substitution (`{clickid}`, `{sub_id_1}`, etc.). Selecting a specific offer is supported: `/?_lp=1&offer_id=10`.

### Fixed
- 🐛 The "Landing + Offer" stream scheme now also works with a single landing and no offer. Previously, removing the offer could prevent the landing from opening — now the selected landing is always used as the destination, and the offer is optional.

## 📝 What's New in v0.9.3.3

### Fixed
- 🐛 Stream filters are now actually applied. Previously only `Country`, `Device`, `Bot` and `Language` filters were processed, while `Browser`, `OS`, `IP`, `Referer`, `Keyword`, `Weekday` and `Time` silently passed all traffic (for example, a "Browser = TikTok" filter in include mode still opened in every browser). All of these filters are now checked.
- 🐛 Browser detection recognizes in-app browsers (TikTok, Facebook, Instagram, etc.) by user-agent signatures — TikTok filtering works correctly.
- 🐛 The `IP` filter supports masks (`10.0.0.*`); `Country`/`Device`/`OS` matching is now case-insensitive.
- 🐛 If the IP is not resolved by the free geo database (country `Unknown`), the country filter passes such a visitor instead of blocking — so you don't lose real traffic.

### Added
- ✨ `ISP` filter (by provider/network) via the free **MaxMind GeoLite2-ASN** database. Upload `GeoLite2-ASN.mmdb` into the `/geo/` folder (using the same MaxMind key as City) — and the filter works, matching the network organization and AS number. Instructions and a link have been added to the "Geo Databases" page.

> ℹ️ Without the GeoLite2-ASN database, the `ISP` filter simply passes traffic (nothing breaks). The `Connection` filter (wifi/mobile/cable) is still unsupported — there is no free data source for it.

## 📝 What's New in v0.9.3.2

### Fixed
- 🐛 Local landings (ZIP) are now served correctly on click. Previously the click handler looked for files in `/api/landings/{id}`, while uploads saved them to `/landings/{id}` — which caused a "Local landing files not found" error and prevented the transition to the landing. The paths have been unified.

## 📝 What's New in v0.9.3.1

### Added
- ✨ Keitaro Migration UI with step-by-step instructions
- ✨ Click API tokens for campaigns (Keitaro compatibility)
- ✨ Backup command copy button
- ✨ Campaign Reports with grouping by parameters
- ✨ Traffic Simulation with click parameter configuration
- ✨ Token preservation on import from Keitaro
- ✨ Fixed terminology (affiliate networks)
- ✨ Full localization of modal dialogs

### Fixed
- 🐛 Fixed `loadConversionLogs is not defined`
- 🐛 Fixed modal positioning (the navbar no longer overlaps)
- 🐛 Fixed CampaignReports styles for a consistent design

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
