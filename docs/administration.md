# Administration

Everything opened from the ⚙ menu in the panel's top right corner.

## Users and access

**⚙ → Users**. Two tabs: **Users** (the accounts table, visible to admins) and **Profile** (your own account — for every user).

### Roles

There are two roles: **admin** (full access) and **user** (per-resource permissions). The account created at installation cannot be deleted, nor can the last remaining admin. Users have permission templates: media buyer, video editor / designer, developer, or custom permissions.

Permissions are granted per resource: campaigns, offers, landings, sources, affiliate networks, domains, logs, gallery, push. Levels are **full**, **read-only**, **no access**. For campaigns there are also **own + assigned** and **assigned only** — then the user sees and edits only those campaigns everywhere: in lists, reports, logs, conversions, on the dashboard. Financial data can be hidden separately: costs, revenue, payouts.

Permissions are enforced on the server. Actions not assigned to any resource are admin-only (full list in [REST API](api.md#action-catalog)). Globally dangerous operations (clearing statistics, Keitaro import, conversion import, retro-remapping) are unavailable to users with "own + assigned" access.

### Profile

Login, email, interface language, time zone (about 250 zones with the current UTC offset), first day of the week, password change (requires the current password; minimum 8 characters).

**Two-factor authentication** — TOTP via an authenticator app. Codes are one-time.

### API keys

Created by an admin for a user: **read** — read-only, **write** — also management. The key is passed in the `Authorization: Bearer <key>` header or `X-Api-Key`. Used in [MCP](mcp.md), [REST API](api.md), receiving costs from Dolphin / Fbtool, and the Ads Manager extension.

### Sign-in

Failed-attempt limits: 5 per 5 minutes from one IP, and 20 per 15 minutes per account (reset after a successful sign-in). Session lifetime is `session_lifetime` (a day by default, from 5 minutes to 30 days; the `ORBITRA_SESSION_LIFETIME` environment variable takes priority over the setting).

## Settings

**⚙ → Settings**, tabs:

| Tab | What's there |
|---|---|
| **General** | Postback key, default currency, manual server IP, PHP landings permission (off by default) and their 1–9 s timeout |
| **Bots** | [Browser check](browser-check.md) mode (Off / Observe only / Soft / Strict), bot IPs and signatures, the global Bot ISP list, datacenter ranges, VPN ASN as a bot signal — see [Cloaking](cloaking.md#global-settings-settings--bots) |
| **Conversion types** | Custom statuses and what they mean, unmapped statuses — see [Postbacks](postbacks.md#conversion-types) |
| **Custom metrics** | Custom formulas for reports |
| **Automation** | Crons: postback queue, backorder |
| **System** | Statistics collection on/off, ignore prefetch, new version checks, log and archive retention periods, panel access by IP/subnets, admin panel path |
| **Privacy** | Scan protection: what to answer when a working link is hit directly without campaign parameters — a 302 to your URL, 404, or an empty page |

### Panel path

`/admin.php` is the first thing any scanner checks. In **System → Admin panel path** you can replace it with your own (`/my-panel`), after which `/admin.php` starts answering 404. This hides the login form but does not replace the password. If the path is forgotten:

```bash
php /var/www/orbitra/cli/admin_path.php          # print the current path
php /var/www/orbitra/cli/admin_path.php reset    # вернуть /admin.php
```

### Panel access by IP

A list of IPs and subnets (IPv4 and IPv6, one per line). Empty — the panel is available from anywhere. Filled — signing in to the panel and authorized `api.php` calls work only from those addresses. `X-Forwarded-For` headers are trusted by the tracker only from Cloudflare and private proxies. Careful: you can lock yourself out. To lift the restriction from the server:

```bash
sudo -u www-data php -r '$db = new PDO("sqlite:/var/www/orbitra/orbitra_db.sqlite"); $db->exec("UPDATE settings SET value = \x27\x27 WHERE key = \x27admin_ip_access\x27");'
```

### Retention periods

The "Log retention period" and "Archive resource retention period" fields only work when the cleanup cron is running. **The installer does not set it up** — add it manually:

```
0 3 * * * php /var/www/orbitra/cli/cleanup_cron.php >> /var/www/orbitra/var/logs/cleanup.log 2>&1
```

Deletion runs in batches so the database is not blocked.

## Personalization

**⚙ → Personalization** — logo, name, light or dark theme and palettes: Cobalt, Canary, Parchment, Indigo (in light and dark variants), Terminal, Aurora. The theme is applied before the first paint, so the dark theme is dark from the very first frame.

The interface language is in the profile: English, Русский, Українська, Español, Deutsch, Français, 中文.

## Geo databases

**⚙ → Geo Databases**. Without a geo database, country filters and country cloaking do not work (all visitors are `Unknown`); without an ASN database — the datacenter and ISP list layers.

| Database | Key | Provides |
|---|---|---|
| Sypex Geo City | not needed | Country, region, city. Installed by the installer |
| MaxMind GeoLite2 City / ASN | MaxMind license key | City, ASN and provider |
| IP2Location DB11 | IP2Location token | Geo, time zone |
| IP2Proxy PX12 | IP2Location token | VPN, proxy, TOR, residential, fraud score |

The databases are updated monthly by the `cli/geo_update.php` cron (tagged `# orbitra-geo`; MaxMind and IP2 — only if keys are saved). A panel update installs Sypex by itself when there are no databases at all. A banner on all tabs warns when there are no databases or only Sypex.

**⚙ → Geo profiles** — ready-made country sets for filters (EU, CIS, etc.) and custom ones.

## Update

**⚙ → Update** — checks for a new version on GitHub and updates with a button (`git pull` as the web user). The check is cached for an hour, for 10 minutes on error; disabled in **System → Notify about new versions**.

After an update the panel by itself:

- restores the postback queue cron if missing;
- downloads Sypex Geo when there are no geo databases;
- warns if the server runs PHP below 8.4 (how to update PHP — [Deployment](deployment.md#updating-php)).

Changes that need root (sudo rules, SSL helpers) cannot be done by the button — the panel shows a banner with the `cli/server_setup.sh` command ([Deployment](deployment.md#one-time-server-step-after-updating-160)).

If `exec` is disabled on the hosting, the page shows commands for manual updating via Git.

## Misc

| Menu item | What it does |
|---|---|
| **Archive** | Deleted campaigns, offers, landings, sources, affiliate networks: restore or erase forever |
| **Clear statistics** | Delete clicks and conversions for a period or all of them |
| **Migrations** | Database schema version, Keitaro import ([Keitaro Migration](keitaro-migration.md)) |
| **Status** | Disk, CPU, RAM, database size |
| **Logs** | Traffic, postbacks, S2S, system, audit ([Reports and logs](reports.md#logs)) |
| **Gallery** | Media library for PWA, landings and offers ([Content Gallery](content-gallery.md)) |
| **AI / MCP** | Connecting an AI assistant ([MCP](mcp.md)) |
| **Support and feedback** | Contacts and the partners block |

## Ads Manager extension

An extension for Chrome and Chromium-based antidetect browsers shows Orbitra metrics next to campaign, ad group and ad rows in Facebook Ads Manager (refreshing every 30 seconds), and on click — a three-day breakdown: daily history, landings and offers, Pixel / CAPI delivery accuracy.

1. **⚙ → Integrations → Chrome & Antidetect Extension** — download the archive and unpack it.
2. Load the folder as an unpacked extension (or a ZIP, where supported).
3. In the extension popup, paste the tracker URL and the highlighted read key Orbitra shows.

The extension only needs a read key. Safe hits are excluded the same way as in reports.
