# Orbitra v1.3.4 Tracker

**🌐 Language: English | [Русский](README.ru.md)**

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react)
![Vite](https://img.shields.io/badge/Vite-7-646CFF?logo=vite)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite)
![Status](https://img.shields.io/badge/Status-Production_Ready-brightgreen)

Orbitra is a modern traffic management and conversion tracking system. A simpler and faster alternative to Keitaro Tracker, while keeping full API and feature compatibility.

## 🆕 What's New in v1.3.4

The tester's addendum III — the part targeting this repository (his fork-only dialog component, zone automation and click-spill file stay out).

### Fixed

- **📥 Import from Namecheap works again** — the toolbar button passed the React event as an account id, so the request never left the browser; the handler now guards with a `typeof` check
- **🔐 Opening and saving a domain no longer resets its SSL configuration** — the edit modal never loaded `ssl_source`/custom-certificate paths, and Save wrote the defaults (including empty cert paths) over the stored values
- **☁️ The Cloudflare proxy toggle actually switches the DNS record now** — `save_domain` reads the previous flag and calls `upsertDnsRecord()` on a real change, clears the cached DNS status and reports `cloudflare_sync`; before, it rewrote only the database row while the record kept its orange cloud, and the next queue run flipped the flag back
- **🎛️ `ssl_source` is the single source of truth for the SSL mode** — the Cloudflare button no longer lights up alongside Let's Encrypt, the proxy toggle and the mode buttons keep each other in step, Custom clears the proxy
- **🛡️ One locked SQLite write no longer aborts the whole hourly SSL queue run** — the Cloudflare auto-detect UPDATE in `orbitraProcessSslQueue()` is wrapped in `try`/`catch`
- **🔌 IPv4 pinned on the Namecheap / Cloudflare / CloudDetector / currency-rate calls** — matching the ad APIs; a stalled IPv6 connect used to burn the whole timeout budget before IPv4 was tried
- **🚨 Error messages show the real error** — 38 catch sites across the panel displayed a constant "network error"; all prefer `e.message` now (this is what made the Namecheap bug diagnosable in the first place)
- **🏷️ Certificate source labelled beside the SSL status icon**; the Domain Management toolbar wraps as a unit and Check DNS is themed like every other control
- **🌐 A duplicate i18n key (`domains.sslCloudflare`) is split** — the status tooltip silently overrode the SSL Mode button label in all 7 locales; `sslSelfSigned` added

### Previous Highlights (v1.3.3)

- 📦 **Production builds minified again** (5.26 MB → 3.16 MB / 891 kB gzip, byte-reproducible); 📊 seven new report dimensions (Campaign/AdSet/Ad Name, UTM Placement, Source, ISP, ASN) from a single `REPORT_DIMENSIONS` registry; 🎨 themed login screen; 🌗 no light-theme flash on cold load; 🟡 warning-token banners with version-scoped dismissal

Older releases (v1.3.2 and earlier): see the [full changelog](CHANGELOG.md).

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

## 📝 What's New

### Current release — v1.3.2 (2026-08-25)

**Fixed**
- ▶️ **Report play-pause toggles read real ad state** — new `ad_entity_statuses` endpoint (DB for tracker campaigns, Graph per-id reads for ad entities; Graph v26 killed the `?ids=` batch); server-wins merge so a pause survives reopening; **Disapproved / In review badges**; IPv4 pinned on every aggregator Graph call
- 🧹 **Landings saved-columns guard** (entity ids no longer leak); 🧱 **Campaigns colgroup matches render order**, Actions fifth, six fixed columns locked + stale widths purged, menu/alias trimmed; 📋 **copy-link toast + URL-modal fallback** (no more `window.alert`); **Postback/Feedback/MCP copy via utils/clipboard**; 📅 **Conversions Log stops clipping the date picker**; tz chip no-wrap

**Operations**
- 🪵 `install.sh` enables php-fpm `catch_workers_output` (app `error_log` was discarded); ⏱️ rotation optimiser cron every minute

**Changed**
- `floor_pct` removed from the optimiser conditions UI (default still applies, saved configs unchanged)

Previous releases — v1.3.1: 💸 cost in the ad account's timezone, 🧣 safe-page cost exclusion, 📤 CAPI silent drops, 🕐 shared timezone store, 🩺 worker-health banner, 🪟 modal layering, 🖱️ column-width persistence; v1.3.0: 🖱️ resizable columns everywhere, 📋 Click/Conversions Log modals, 🧩 kclient.php 2.0, 🏷️ KClient macros, 🔄 optimiser EPC fix; v1.2.0: 📱 mobile layout, 📲 PWA + hashed assets, 🔄 rotation auto-optimiser.

Full version history: [CHANGELOG.md](CHANGELOG.md).

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
