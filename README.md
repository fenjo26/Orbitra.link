# Orbitra v0.9.3.8 Tracker

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react)
![Vite](https://img.shields.io/badge/Vite-7-646CFF?logo=vite)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite)
![Status](https://img.shields.io/badge/Status-Production_Ready-brightgreen)

Orbitra is a modern traffic management and conversion tracking system. A simpler and faster alternative to Keitaro Tracker, while keeping full API and feature compatibility.

## 🚀 Quick Install (Ubuntu 20.04 / 22.04 / 24.04)

To install automatically on a clean Linux server, run:

```bash
wget -qO- https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh | bash
```

The installer automatically:
- Downloads the source code from GitHub
- Installs Nginx, PHP 8.3+, SQLite3
- Deploys the built frontend
- Configures a Let's Encrypt SSL certificate for your domain

## ✨ Key Features

### 1. **Full Keitaro Compatibility**
- **Click API with tokens** — full compatibility with existing integration scripts
- **Import from Keitaro** — migrate campaigns, offers, domains and streams while preserving tokens
- **API compatibility** — works with existing postbacks and webhooks

### 2. **Modern Architecture**
- **Backend**: PHP 8.3+ without heavy frameworks (clean code)
- **Database**: SQLite 3 (single file, automatic schema creation)
- **Frontend**: React 19 + Vite 7 + Tailwind CSS 4
- **UI/UX**: Modern design with dark/light theme

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
- **Full i18n support**: Russian (RU) and English (EN)
- **1260+ translation keys** — every UI element is localized
- **Language switching** — in profile settings, without a page reload

### 7. **Telegram Bot**
- **10+ commands**: `/stats`, `/campaigns`, `/top`, `/conversions` and others
- **Notifications**: instant conversion notifications
- **Daily summary**: automatic campaign report
- **Multilingual**: the bot supports RU and EN

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

## 📁 Project Structure

```
Orbitra/
├── api.php                    # REST API (60+ endpoints)
├── index.php                  # Main tracker (click handling)
├── postback.php               # Postback handler
├── click.php                  # Click API
├── config.php                 # DB configuration and migrations
├── database.sql               # DB schema documentation
├── version.php                # System version
├── router.php                 # PHP built-in server router
├── install.sh                 # Auto-installer
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
│   │   ├── components/       # 46 React components
│   │   │   ├── Dashboard/    # Dashboard components
│   │   │   ├── CampaignEditor.jsx    # Campaign editor (120KB)
│   │   │   ├── IntegrationsPage.jsx # Integrations
│   │   │   ├── MigrationsPage.jsx   # Migrations and import
│   │   │   ├── ConversionsLog.jsx   # Conversion log
│   │   │   ├── CampaignReports.jsx  # Campaign reports
│   │   │   └── ...               # Other components
│   │   ├── contexts/
│   │   │   └── LanguageContext.jsx  # i18n context
│   │   └── locales/
│   │       ├── en.js          # English (~1100 keys)
│   │       └── ru.js          # Russian (~1260 keys)
│   ├── package.json
│   ├── vite.config.js
│   └── index.html
│
├── docs/                      # Documentation
│   ├── index.md              # Documentation overview
│   ├── architecture.md       # Architecture and technologies
│   ├── features.md          # Feature descriptions
│   ├── api.md               # REST API documentation
│   └── deployment.md        # Deployment instructions
│
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
- **Timezone** and **interface language** (RU/EN)

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
- `/lang ru|en` — bot language

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
Orbitra supports automatic theme switching (light/dark) based on the user's system settings.

### Branding
Configure the logo, colors and name in **Settings → Branding**.

### Interface Language
Switch the language in **Profile → Settings** (Russian/English).

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

## 📝 What's New in v0.9.3.7

### Added
- ✉️ **Email** traffic source template — for email marketers. Comes with pre-configured sub-parameters `subscriber_id`, `campaign_id`, `list_id`, `broadcast_id`, `esp`, which you map to your ESP's merge tags.
- 🌐 **Platform-level affiliate network templates**: Everflow, CAKE, HitPath, Affise, TUNE/HasOffers. Any smaller network running on these platforms can now be connected by selecting the platform template — without a separate entry per company. The click-id parameter appended to the offer is filled in with each platform's standard field (Everflow `sub1`, CAKE `s1`, HitPath `c1`, Affise `sub1`, TUNE `aff_sub`).

### Changed
- 🌍 i18n cleanup: hardcoded UI strings moved into the translation system (`en.js`/`ru.js`), and template names are now localized.

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
- **Email**: support@orbitra.link

---

**Orbitra** — a modern tracker for affiliate marketers and webmasters.

**Tags**: `tracker`, `affiliate-marketing`, `keitaro-alternative`, `php-tracker`, `react-admin`, `cpa-network`, `traffic-management`, `split-testing`, `conversion-tracking`
