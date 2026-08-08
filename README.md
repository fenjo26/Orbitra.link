# Orbitra v0.9.6.5 Tracker

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
- **Advanced filtering**: GEO, Device, OS, Browser, ISP, IP, Language, Referer
- **A/B testing**: built-in split-test support with weighted rotation

### 4. **Integrations**
- **S2S Postbacks** — Server-to-Server postbacks from affiliate networks
- **Affiliate network templates**: platform-level (Everflow, CAKE, HitPath, Affise, TUNE/HasOffers) plus networks Leadbit, M4Leads, Dr.Cash, AdCombo and others
- **Source templates**: Facebook, Google, TikTok, Yandex, Taboola, Outbrain, Email and others
- **Click API** — tokens for working with integration scripts
- **Telegram Bot** — real-time monitoring and notifications

### 5. **Analytics & Reports**
- **Dashboard** — aggregated statistics for clicks, conversions and revenue
- **Trends** — detailed analytics with charts across 8 metrics
- **Campaign Reports** — campaign reports grouped by any parameter
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
- **DNS check** — automatic A-record verification
- **HTTPS-only** — forced redirect to HTTPS
- **Bot protection** — intercepts `/robots.txt` and `X-Robots-Tag`
- **Parking mode** — domain parking with protection

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
