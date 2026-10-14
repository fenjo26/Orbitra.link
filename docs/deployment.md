# Installation and update

Orbitra is optimized for a quick start without complex environment setup. It is built on a lightweight **PHP + SQLite + Nginx** stack — no separate DB server (no ClickHouse, Redis or MySQL).

## System requirements

Orbitra stores everything in a single SQLite file, so it needs significantly less RAM than ClickHouse-based trackers and runs on the cheapest VPS plans.

**Basic (required):**

- **CPU:** 1 vCPU (x86_64)
- **RAM:** 1 GB (2 GB recommended)
- **Disk:** 20 GB SSD
- **OS:** Ubuntu 22.04 through 26.04, or Debian 12 / 13
- A clean server without a control panel; root/sudo access

**Sizing by traffic volume (a guideline):**

| Clicks per day | RAM | CPU | Disk |
|---|---|---|---|
| up to ~100,000 | 1–2 GB | 1–2 vCPU | 20 GB SSD |
| ~100,000 – 500,000 | 2–4 GB | 2 vCPU | 40 GB SSD |
| ~500,000 – 1,000,000 | 4–8 GB | 4 vCPU | 80 GB SSD |

> ✅ **Verified in practice:** Orbitra runs stably on a **2 vCPU / 2 GB RAM / 20 GB SSD** VPS (Ubuntu 24.04) with light traffic — a convenient and inexpensive starting point. The upper rows of the table are headroom for higher traffic, not a hard requirement.

> 💡 **Why less than Keitaro?** Keitaro stores clicks in ClickHouse + Redis + MySQL, so its RAM requirements grow up to 64 GB at millions of clicks per day. In Orbitra everything lives in a single SQLite file — the bottleneck is not RAM but disk I/O and SQLite's single-threaded writes. For low and medium volumes (in WAL mode) this is enough; for sustained **millions of clicks per day** with heavy analytics, a columnar-DB tracker (like Keitaro) is architecturally the better fit.

> 💡 When installing on a 1 GB RAM server, add 1–2 GB of swap: building the frontend (Vite) is the most memory-hungry step. The tracker itself runs fine on 1 GB.

## Quick Automatic Installation (Production)

The fastest and proper way to launch the project on a server is the built-in installer.

1. Make sure you have a "clean" Linux server (Ubuntu 22.04+ or Debian 12+).
2. Run the command as root/sudo:
   ```bash
   wget -O orbitra-install.sh https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh && bash orbitra-install.sh
   ```
   The download is a separate step on purpose: with `wget -qO- … | bash`, a failed
   download silently pipes emptiness into bash, and the installation "completes"
   without a single line of output. This way it stops at `&&`, and wget reports the reason.
3. The script installs Nginx, a current PHP (8.5, falling back to 8.4 from the Ondřej Surý repository; if that is unavailable too — the distro one), SQLite, downloads the frontend code and sets up HTTPS certificates.

> **First sign-in:** there is no "default" account. After installation, open `http://<your-domain>/admin.php` — on first run Orbitra starts the initial setup wizard, where you create your own administrator (login ≥ 3 characters, password ≥ 8 characters, time zone and language).

### Panel access by IP

The panel is always available at the server address — `http://<ip>/admin.php` — no matter how many domains you bind and which of them you later delete. This is handled by a catch-all block that Orbitra puts first in the Nginx config; certificates are issued via `certbot certonly --webroot`, so Certbot does not rewrite the config.

`https://<ip>/admin.php` works too, but with a browser warning: Let's Encrypt does not issue certificates for bare IPs, so a self-signed one is used.

If the panel still stops responding — for example, the Nginx config was edited by hand — rebuild it from the database:

```bash
sudo php /var/www/orbitra/cli/nginx_sync.php
```

The command also repairs Certbot renewal configs left over from the previous issuing method and generates a self-signed certificate. **After updating from a version below 0.9.6.4, run it once.**

### What the installer sets up

- Nginx, PHP-FPM (the pool is sized from the amount of RAM, `fastcgi_read_timeout 180s`), SQLite, Composer with SHA-256 verification.
- The code in `/var/www/orbitra`, owned by `www-data`.
- Its own postback key.
- The Sypex Geo City database (free, no key). If the server could not reach sypexgeo.net, the installation still completes, and the database can be added later in **⚙ → Geo Databases**.
- SSL and nginx helpers with argument validation (`cli/server_setup.sh`).
- Crons: click spool, postback queue, Web Push, rotation auto-optimization, Telegram polling, SSL issuance, cost import, IP ranges, geo databases. Full table in [Architecture](architecture.md#background-tasks).
- MCP server dependencies, if npm is present on the server.
- Opens 80/443 in ufw if it is enabled.

Not installed automatically: retention-based cleanup (`cli/cleanup_cron.php`, see [Administration](administration.md#retention-periods)), the postback monitor, and source URL checks.

Re-running the installer on a live server is safe: it updates PHP, configs and crons without touching data.

Panel security settings after installation — a custom panel path, access by IP, two-factor sign-in — are covered in [Administration](administration.md).

## Updating PHP

Ubuntu 22.04 / Debian 12 / Ubuntu 24.04 ship PHP 8.1 / 8.2 / 8.3 out of the box — these versions no longer receive security updates (8.1 and 8.2 are EOL; 8.3 gets only security fixes until the end of 2027). Orbitra v1.5.10 runs on all of them, but a fresh install or a server upgrade should start from PHP 8.4+.

**The simplest path is to re-run the installer** (it is idempotent and supports upgrading over an existing installation):

```bash
sudo bash /var/www/orbitra/install.sh
```

The installer adds the current PHP builds repository (ondrej/php on Ubuntu, packages.sury.org on Debian), installs PHP 8.5 (falling back to 8.4) alongside the old one, switches the `php` CLI alternative (used by crons and Composer), rewrites the Nginx config to the new FPM socket, applies the pool settings and restarts the services. The old PHP version stays installed but stops serving traffic; you can remove it after checking, if you like.

**Manual (Ubuntu):**

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update
sudo apt-get install -y php8.5-fpm php8.5-cli php8.5-sqlite3 php8.5-curl php8.5-mbstring php8.5-xml php8.5-zip php8.5-intl php8.5-bcmath
sudo update-alternatives --set php /usr/bin/php8.5
sudo bash /var/www/orbitra/install.sh   # switches nginx/FPM/crons to the new version
```

**Manual (Debian):**

```bash
. /etc/os-release
curl -fsSL https://packages.sury.org/php/apt.gpg | sudo gpg --dearmor --yes -o /usr/share/keyrings/deb.sury.org-php.gpg
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" | sudo tee /etc/apt/sources.list.d/php-sury.list
sudo apt-get update
sudo apt-get install -y php8.5-fpm php8.5-cli php8.5-sqlite3 php8.5-curl php8.5-mbstring php8.5-xml php8.5-zip php8.5-intl php8.5-bcmath
sudo update-alternatives --set php /usr/bin/php8.5
sudo bash /var/www/orbitra/install.sh
```

After the update, check: `php -v` shows 8.4/8.5, the panel opens, *Logs → System log* has no `postback.php database error` entries, and the worker ping in the postback queue settings is fresh. When updating via the button, the panel will warn by itself if the server stays on PHP < 8.4.

## One-time server step after updating (1.6.0+)

The "Update" button in the panel runs as the web user and cannot modify
sudoers. Servers installed before 1.6.0 need a single run of
`cli/server_setup.sh` as root after updating — the panel will show a banner with the command:

```bash
curl -fsSL https://raw.githubusercontent.com/fenjo26/Orbitra.link/v1.6.0/cli/server_setup.sh | sudo ORBITRA_REF=v1.6.0 bash
```

The script is downloaded from the release tag, not from `/var/www/orbitra` (that directory
belongs to the web user, and root must not execute files the web user
can rewrite). It replaces the old sudo rules (`certbot` with any
arguments, and `cp` into `sites-available` — either one could let the web user
gain root) with helpers that validate arguments, installs the click queue
cron and writes the version to `/etc/orbitra/server-setup-version`. Running it
again is safe. `install.sh` calls the same script, so new
installations get the same thing.

## Update integrity

- `composer.phar` is not kept in the repository: the installer downloads the version pinned in `install.sh` (`COMPOSER_VER`) and verifies `COMPOSER_SHA256`; a mismatch stops the installation. An already existing `/var/www/orbitra/composer.phar` is used as is.
- The panel (`frontend/dist`) is built on the release machine and committed: the server never runs `npm install` / `npm run build`, and the working copy stays clean after installation.
- Release tags should be signed (`git tag -s`) so that updating from the panel pulls verifiable code.

## Local Development (React & PHP Server)

If you plan to modify the frontend source code (`React`) and the tracker's visual part, you will need a local PHP + Vite setup.

### Part 1: Starting the Backend API
Open a first terminal and start the built-in PHP server with the router:

```bash
cd Orbitra
php -S localhost:8080 router.php
```
*This server will handle API requests and redirects.*

### Part 2: Starting the Frontend (Vite)
Open a second terminal, install the npm packages and start the dev server:

```bash
cd Orbitra/frontend
npm install
npm run dev
```

Then open `http://localhost:5173` in your browser. All API requests from the frontend are automatically proxied (via the `vite.config.js` proxy) to `http://localhost:8080/api.php`, which avoids CORS and session issues in development mode.

## Building the panel

Only needed if you change the panel code:

```bash
cd Orbitra/frontend
npm run build
```

The build lands in `frontend/dist/`, which is committed to the repository — the server takes the ready-made panel via `git pull` or an update from the panel and builds nothing itself.

## Telegram bot

Connecting and operating modes — [Telegram bot](telegram.md).
