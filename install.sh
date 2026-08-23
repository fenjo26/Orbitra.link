#!/bin/bash
# Orbitra v1.1.11 Tracker Auto-Installer
# Supported OS: Ubuntu 20.04, 22.04, 24.04 / Debian 11, 12
# Root privileges required (sudo)

set -e

echo "======================================================="
echo "       Starting Orbitra Tracker Installation           "
echo "======================================================="

# Check for root
if [ "$EUID" -ne 0 ]; then
  echo "ERROR: Please run this script as root (use sudo)"
  exit
fi

echo "[1/5] Updating system and installing packages (Nginx, PHP, SQLite)..."
apt-get update -y
# php-intl is optional at runtime — landing slugs fall back to a built-in
# transliteration table without it — but with it installed every alphabet
# transliterates, not just the ones the table covers.
# php-bcmath is NOT optional: ip2location/ip2location-php and ip2location/ip2proxy-php
# both declare "ext-bcmath" as a hard requirement, so without it `composer install`
# refuses the lock file entirely ("Your lock file does not contain a compatible set
# of packages") and every install and in-panel update dies at the dependency step.
apt-get install -y ca-certificates apt-transport-https software-properties-common curl git unzip nginx php-fpm php-cli php-sqlite3 php-curl php-mbstring php-xml php-zip php-intl php-bcmath

# Determine installed PHP-FPM version
PHP_V=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")
PHP_FPM_SOCK="/var/run/php/php${PHP_V}-fpm.sock"

# The generic "php-bcmath" above resolves to the distribution's default PHP, which
# is not necessarily the version the CLI actually runs (a server with the ondrej
# PPA can have several). Composer checks the CLI's extensions, so verify there and
# install the version-pinned package if the generic one landed somewhere else.
if ! php -m 2>/dev/null | grep -qix 'bcmath'; then
    echo "  > Enabling PHP bcmath for PHP ${PHP_V} (required by the IP2Location readers)..."
    apt-get install -y "php${PHP_V}-bcmath" || apt-get install -y php-bcmath || true
fi

# Install Node.js 20.x (required for frontend build)
echo "[2/5] Installing Node.js 20.x for frontend build..."
if command -v node &> /dev/null; then
    CURRENT_NODE_V=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
    if [ "$CURRENT_NODE_V" -lt 20 ]; then
        echo "  > Removing old Node.js $CURRENT_NODE_V..."
        apt-get remove -y nodejs npm
        echo "  > Installing Node.js 20.x..."
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
        apt-get install -y nodejs
    else
        echo "  > Node.js $(node -v) already installed (version 20+) - skipping"
    fi
else
    echo "  > Installing Node.js 20.x..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

echo "Node.js version: $(node -v)"
echo "npm version: $(npm -v)"

# Install Certbot for SSL certificates
echo "[2.5/5] Installing Certbot for automatic SSL certificates..."
if ! command -v certbot &> /dev/null; then
    echo "  > Installing Certbot..."
    apt-get install -y certbot python3-certbot-nginx
else
    echo "  > Certbot already installed - skipping"
fi

# Configure sudoers for www-data to run Certbot (auto-SSL via UI)
echo "  > Configuring sudoers for automatic SSL & Nginx management..."
SUDOERS_FILE="/etc/sudoers.d/orbitra-ssl"
echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t" > $SUDOERS_FILE
echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload nginx" >> $SUDOERS_FILE
echo "www-data ALL=(ALL) NOPASSWD: /bin/cp /etc/nginx/sites-available/orbitra /tmp/orbitra_nginx_update.conf" >> $SUDOERS_FILE
echo "www-data ALL=(ALL) NOPASSWD: /bin/cp /tmp/orbitra_nginx_update.conf /etc/nginx/sites-available/orbitra" >> $SUDOERS_FILE
echo "www-data ALL=(ALL) NOPASSWD: /usr/bin/certbot" >> $SUDOERS_FILE
chmod 0440 $SUDOERS_FILE

echo "[3/5] Downloading Orbitra source code to /var/www/orbitra..."
TMP_SRC_DIR="$(mktemp -d /tmp/orbitra_src.XXXXXX)"
# Runs on EVERY exit, including a failure under `set -e`. The ownership handover at
# the end of the script is what makes the in-panel update button work: every step
# here runs as root, and `git pull` later runs as www-data, which cannot replace a
# root-owned file. When an intermediate step aborted the script (a failed Composer
# install, for instance) the handover never happened, and the panel reported
# "часть каталога принадлежит другому пользователю" for the rest of that install's
# life. Doing it from the trap means a half-finished install is still updatable.
cleanup_tmp() {
    rm -rf "$TMP_SRC_DIR"
    if [ -d /var/www/orbitra ]; then
        chown -R www-data:www-data /var/www/orbitra 2>/dev/null || true
    fi
}
trap cleanup_tmp EXIT

# A previous run that died before restoring (a failed clone, for instance) leaves
# these behind, and "cp -r src dst" copies INTO an existing dst — turning the next
# backup into var/var, geo/geo, landings/landings and losing part of the restore.
rm -rf /tmp/orbitra_db_backup.sqlite /tmp/orbitra_var_backup /tmp/orbitra_geo_backup /tmp/orbitra_landings_backup /tmp/orbitra_offers_backup

if [ -f "/var/www/orbitra/orbitra_db.sqlite" ]; then
    echo "  > Backing up database..."
    cp /var/www/orbitra/orbitra_db.sqlite /tmp/orbitra_db_backup.sqlite
fi
if [ -d "/var/www/orbitra/var" ]; then
    echo "  > Backing up var directory..."
    cp -r /var/www/orbitra/var /tmp/orbitra_var_backup
fi
if [ -d "/var/www/orbitra/geo" ]; then
    echo "  > Backing up geo directory..."
    cp -r /var/www/orbitra/geo /tmp/orbitra_geo_backup
fi
# Uploaded landing pages are user data too — the repository only ships an empty
# landings/ with a .gitkeep, so without this the rm -rf below silently destroys
# every landing the user uploaded.
if [ -d "/var/www/orbitra/landings" ]; then
    echo "  > Backing up landings directory..."
    cp -r /var/www/orbitra/landings /tmp/orbitra_landings_backup
fi
# Local offer archives live in offers/<id>/ — same story as landings/: the fresh
# clone ships an empty offers/, so a reinstall without this backup would delete
# every uploaded local offer's files.
if [ -d "/var/www/orbitra/offers" ]; then
    echo "  > Backing up offers directory..."
    cp -r /var/www/orbitra/offers /tmp/orbitra_offers_backup
fi

# Clone the repository into a temporary directory first to avoid downtime on clone failure.
git clone https://github.com/fenjo26/Orbitra.link.git "$TMP_SRC_DIR" || {
    echo "ERROR: Failed to download repository. Please check the github link."
    exit 1
}

# Replace old folder only after successful clone
rm -rf /var/www/orbitra
mv "$TMP_SRC_DIR" /var/www/orbitra

# Restore backups
if [ -f "/tmp/orbitra_db_backup.sqlite" ]; then
    echo "  > Restoring database..."
    mv /tmp/orbitra_db_backup.sqlite /var/www/orbitra/orbitra_db.sqlite
fi
if [ -d "/tmp/orbitra_var_backup" ]; then
    echo "  > Restoring var directory..."
    mkdir -p /var/www/orbitra/var
    cp -r /tmp/orbitra_var_backup/* /var/www/orbitra/var/ 2>/dev/null || true
    rm -rf /tmp/orbitra_var_backup
fi
if [ -d "/tmp/orbitra_geo_backup" ]; then
    echo "  > Restoring geo directory..."
    mkdir -p /var/www/orbitra/geo
    cp -r /tmp/orbitra_geo_backup/* /var/www/orbitra/geo/ 2>/dev/null || true
    rm -rf /tmp/orbitra_geo_backup
fi
if [ -d "/tmp/orbitra_landings_backup" ]; then
    echo "  > Restoring landings directory..."
    mkdir -p /var/www/orbitra/landings
    cp -r /tmp/orbitra_landings_backup/. /var/www/orbitra/landings/ 2>/dev/null || true
    rm -rf /tmp/orbitra_landings_backup
fi
if [ -d "/tmp/orbitra_offers_backup" ]; then
    echo "  > Restoring offers directory..."
    mkdir -p /var/www/orbitra/offers
    cp -r /tmp/orbitra_offers_backup/. /var/www/orbitra/offers/ 2>/dev/null || true
    rm -rf /tmp/orbitra_offers_backup
fi


echo "[4/5] Configuring permissions for SQLite Database..."
# Create necessary subdirectories first
mkdir -p /var/www/orbitra/var/geoip/SxGeoCity
mkdir -p /var/www/orbitra/geo
mkdir -p /var/www/orbitra/core
# Webroot for Let's Encrypt HTTP-01 challenges. Certbot writes here as root;
# nginx serves it for every hostname, including the bare server IP.
mkdir -p /var/www/orbitra/var/acme/.well-known/acme-challenge

# Allow Nginx to write to the folder so SQLite can create the DB
chown -R www-data:www-data /var/www/orbitra
find /var/www/orbitra -type d -exec chmod 775 {} \;
find /var/www/orbitra -type f -exec chmod 664 {} \;
# Deliberately NOT chmod +x on cli/*.php. Those scripts have no shebang and are
# always run as `php /path/script.php`, so the bit buys nothing — but git tracks
# it, so setting it made every one of them look locally modified and aborted the
# next `git pull` with "your local changes would be overwritten". Telling git to
# ignore the mode as well, so a stray chmod from anywhere never blocks an update.
git -C /var/www/orbitra config core.fileMode false 2>/dev/null || true

echo "[5/5] Configuring Nginx web server and building frontend..."

SERVER_IP=$(curl -s --max-time 5 http://checkip.amazonaws.com || hostname -I | awk '{print $1}')

# Self-signed certificate for the server IP.
#
# Let's Encrypt does not issue certificates for bare IP addresses, so without
# this an https:// request to the IP is answered by whichever parked domain owns
# the first 443 block — the browser shows a name-mismatch error and the panel is
# effectively unreachable over HTTPS. A self-signed certificate still warns, but
# the panel opens, which is the point: access by IP must never depend on domains.
echo "  > Generating self-signed certificate for IP access..."
mkdir -p /etc/orbitra/ssl
if [ ! -f /etc/orbitra/ssl/self-signed.crt ]; then
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout /etc/orbitra/ssl/self-signed.key \
        -out /etc/orbitra/ssl/self-signed.crt \
        -subj "/CN=$SERVER_IP" -addext "subjectAltName=IP:$SERVER_IP" >/dev/null 2>&1 \
    || openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout /etc/orbitra/ssl/self-signed.key \
        -out /etc/orbitra/ssl/self-signed.crt \
        -subj "/CN=$SERVER_IP" >/dev/null 2>&1 \
    || echo "  > NOTE: could not generate a self-signed certificate; HTTPS by IP will be unavailable."
    chmod 600 /etc/orbitra/ssl/self-signed.key 2>/dev/null || true
fi

# Baseline config: a single catch-all server block. This alone makes the panel
# reachable at the server IP; cli/nginx_sync.php below adds the parked domains.
write_baseline_nginx_config() {
    cat > /etc/nginx/sites-available/orbitra << EOF
# Auto-generated by Orbitra - DO NOT EDIT MANUALLY
# Regenerate with: sudo php /var/www/orbitra/cli/nginx_sync.php

server {
    listen 80 default_server;
    server_name _;

    root /var/www/orbitra;
    index index.php admin.php index.html;

    # ORB-013: Internal location for X-Accel-Redirect (flattened, no nested regex).
    # PHP resolves landing asset paths with security checks, then hands
    # off to nginx via X-Accel-Redirect. This location serves the file
    # with sendfile (zero-copy) while PHP is freed for the next request.
    # A landing page with 30 assets no longer means 30 PHP processes.
    #
    # CRITICAL: No nested location ~* block! Nginx breaks alias inheritance
    # when a nested regex location is used, causing redirect loops.
    # PHP handles all security checks and MIME type validation.
    location /_internal_assets/ {
        internal;
        alias /var/www/orbitra/;
        expires 1h;
        add_header Cache-Control "public, immutable";
    }

    # Let's Encrypt HTTP-01 challenge.
    # Must precede the dotfile deny below, which would otherwise swallow it.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/orbitra/var/acme;
        default_type "text/plain";
        try_files \$uri =404;
    }

    # Access to React/Vite static files
    location /frontend/dist/ {
        alias /var/www/orbitra/frontend/dist/;
        try_files \$uri \$uri/ /frontend/dist/index.html;
    }

    # Router handling (API and clicks)
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Allow large file uploads for Geo DB
    client_max_body_size 256m;

    # Uploaded landing/offer bundles are content, not tracker code.
    #
    # A LeadForge form posts to a relative order.php, which the browser resolves
    # against the campaign URL ("/pr6sxv41") and therefore sends to /order.php —
    # a path with no file behind it. snippets/fastcgi-php.conf ends in
    # "try_files \$fastcgi_script_name =404", so the PHP handler below answered
    # that POST with nginx's own 404 and index.php's order bridge never ran.
    # These paths go to the front controller instead: it resolves which bundle
    # the visitor is on and runs the handler in-process, gated by the same
    # "Allow PHP landings" switch and execution budget as the rest of an
    # uploaded archive. Keep this before the generic PHP handler — nginx tries
    # regex locations in the order they are written.
    location ~ ^/(?:order|thank_you|success|send|lucky|lemon)\.php\$ {
        rewrite ^ /index.php last;
    }

    # The bundles' own routes, /offers/<id>/... and /lander/<slug>/... . Same
    # reason, plus the one that matters more: without this, any .php a bundle
    # ships is executable straight off disk by URL, outside the switch and the
    # budget that exist precisely because uploaded code is not trusted code.
    location ~ ^/(?:offers|lander)/[^/]+/.*\.php\$ {
        rewrite ^ /index.php last;
    }

    # /landings/<id>/ is the storage directory behind /lander/<slug>/, not a
    # public route. Nothing under it is ever executed.
    location ~ ^/landings/.*\.php\$ {
        return 404;
    }

    # PHP processing
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to SQLite DB and configurations
    location ~ \.sqlite$ {
        deny all;
    }
    location ~ /\. {
        deny all;
    }

    # ORB-013: Compression for static assets.
    # Debian's default gzip_types is text/html only. CSS, JS, SVG, JSON
    # leave uncompressed on Cloudflare cache misses without this.
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript
               application/json application/javascript application/xml+rss
               application/rss+xml font/truetype font/opentype
               application/vnd.ms-fontobject image/svg+xml;
}
EOF
}

write_baseline_nginx_config

ln -sf /etc/nginx/sites-available/orbitra /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Increase PHP upload limits for Geo databases (approx 30-50MB)
sed -i "s/upload_max_filesize = .*/upload_max_filesize = 256M/" /etc/php/${PHP_V}/fpm/php.ini
sed -i "s/post_max_size = .*/post_max_size = 256M/" /etc/php/${PHP_V}/fpm/php.ini

systemctl restart php${PHP_V}-fpm
systemctl restart nginx

# Rebuild the config from the database so a reinstall restores the parked domains
# and their HTTPS server blocks. Never fatal: the baseline config above already
# serves the panel, and the user can re-run this command at any time.
echo "  > Syncing Nginx config with parked domains..."
php /var/www/orbitra/cli/nginx_sync.php || {
    echo "  > NOTE: domain sync failed. The panel is still reachable by IP."
    echo "  >       Retry later with: sudo php /var/www/orbitra/cli/nginx_sync.php"
    write_baseline_nginx_config
    nginx -t && systemctl reload nginx
}

# The sync runs as root and may have created the database on a fresh install,
# which would leave it root-owned and read-only for the web server.
chown -R www-data:www-data /var/www/orbitra/orbitra_db.sqlite /var/www/orbitra/var 2>/dev/null || true


# Install locked PHP readers (MaxMind, IP2Location and IP2Proxy). vendor/ is
# intentionally not committed, so both fresh installs and admin updates must
# materialise it from composer.lock.
#
# Never fatal. This step used to run bare under `set -e`, so a single missing PHP
# extension aborted the whole installer here — before the frontend build, before
# the cron job, and before the ownership handover — which is what turned a
# recoverable dependency problem into a permanently un-updatable installation.
echo "  > Installing PHP dependencies..."
cd /var/www/orbitra
# Composer refuses to load plugins when it detects it is running as root, and the
# warning it prints looks like an error in the installer log. We genuinely are root
# here and the packages are locked, so say so explicitly.
export COMPOSER_ALLOW_SUPERUSER=1
if php composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader; then
    echo "  > PHP dependencies installed."
else
    echo "  > NOTE: Composer refused the lock file. Retrying without the bcmath platform check..."
    if php composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader --ignore-platform-req=ext-bcmath; then
        echo "  > NOTE: dependencies installed, but PHP's bcmath extension is missing."
        echo "  >       IPv6 lookups in the IP2Location/IP2Proxy databases need it. Install it with:"
        echo "  >         sudo apt-get install -y php${PHP_V}-bcmath && sudo systemctl restart php${PHP_V}-fpm"
    else
        echo "  > WARNING: Composer dependencies could not be installed."
        echo "  >          The tracker still starts; the IP2Location/IP2Proxy geo readers stay unavailable."
        echo "  >          Retry later with:"
        echo "  >            cd /var/www/orbitra && sudo -u www-data php composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader"
    fi
fi

# Build frontend. Also non-fatal for the same reason as the Composer step above:
# a broken build must not cost the installation its ownership handover.
echo "  > Building frontend..."
cd /var/www/orbitra/frontend
if [ -f "package.json" ]; then
    echo "  > Installing npm dependencies..."
    if npm install --silent && npm run build; then
        echo "  > Frontend built successfully!"
    else
        echo "  > WARNING: frontend build failed. Rebuild later with:"
        echo "  >            cd /var/www/orbitra/frontend && npm install && npm run build"
    fi
else
    echo "  > WARNING: package.json not found, skipping frontend build"
fi

# Prepare MCP server (optional AI-assistant integration).
# Runs client-side (e.g. Claude Desktop), but we install deps here so it is ready to use.
# Failures are non-fatal — the tracker works fine without it.
echo "  > Preparing MCP server (AI assistant integration)..."
if [ -d "/var/www/orbitra/mcp" ]; then
    cd /var/www/orbitra/mcp
    if npm install --silent --no-audit --no-fund; then
        echo "  > MCP server ready (see mcp/README.md to connect Claude Desktop)."
    else
        echo "  > NOTE: MCP dependency install failed — run 'cd mcp && npm install' later. Skipping."
    fi
else
    echo "  > NOTE: mcp/ folder not found, skipping MCP setup."
fi

# Ownership, last — every step above ran as root, and two of them create files
# the web server must be able to replace later. Vite empties and recreates
# frontend/dist on each build, so the bundle and its directory come out
# root-owned; the update button then runs `git pull` as www-data and fails with
# "unable to unlink old 'frontend/dist/assets/index.js': Permission denied",
# because unlinking a file needs write permission on its *directory*. The .git
# directory matters for the same reason — root-owned, git refuses to work with
# it at all ("dubious ownership"). Chowning here, after the last root-run step,
# is what makes in-panel updates work at all.
# Certificate worker. Issuance is not a one-shot: a domain pointed at this server
# minutes ago has DNS that has not propagated yet, so the first attempt fails and
# something has to try again. The certificate is requested immediately when the
# domain is added; this hourly pass only picks up what could not be issued then.
# Hourly on purpose — Let's Encrypt rate-limits failed validations, and a lockout
# costs far more than the minutes a tighter schedule would save.
echo "  > Scheduling the SSL certificate worker..."
SSL_CRON_MARKER="# orbitra-ssl-renew"
if ! crontab -u www-data -l 2>/dev/null | grep -qF "$SSL_CRON_MARKER"; then
    {
        crontab -u www-data -l 2>/dev/null
        echo "17 * * * * php /var/www/orbitra/cli/ssl_installer.php >> /var/www/orbitra/var/logs/ssl_installer.log 2>&1 $SSL_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Worker scheduled (hourly)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: 17 * * * * php /var/www/orbitra/cli/ssl_installer.php"
fi

# Cloaking feed. lord-alfred/ipranges refreshes its datacenter/crawler lists
# daily; this cron mirrors them into var/ipranges/ for CloakDetector. The job
# exits on its own when the files are younger than a day, so the exact minute
# does not matter.
echo "  > Scheduling the IP-ranges updater (cloaking feed)..."
IPRANGES_CRON_MARKER="# orbitra-ipranges"
if ! crontab -u www-data -l 2>/dev/null | grep -qF "$IPRANGES_CRON_MARKER"; then
    {
        crontab -u www-data -l 2>/dev/null
        echo "23 4 * * * php /var/www/orbitra/ipranges_cron.php >> /var/www/orbitra/var/logs/ipranges.log 2>&1 $IPRANGES_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Updater scheduled (daily)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: 23 4 * * * php /var/www/orbitra/ipranges_cron.php"
fi

# Stream rotation auto-optimiser. Recomputes landing/offer rotation weights
# from report metrics; every stream carries its own re-evaluation interval,
# and non-due streams are skipped cheaply, so a */5 cadence is safe. Streams
# without auto enabled never match the prefilter — this is a no-op for them.
echo "  > Scheduling the rotation optimiser..."
ROTATION_CRON_MARKER="# orbitra-rotation"
if ! crontab -u www-data -l 2>/dev/null | grep -qF "$ROTATION_CRON_MARKER"; then
    {
        crontab -u www-data -l 2>/dev/null
        echo "*/5 * * * * php /var/www/orbitra/rotation_optimiser_cron.php >> /var/www/orbitra/var/logs/rotation_optimiser.log 2>&1 $ROTATION_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Optimiser scheduled (every 5 minutes)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: */5 * * * * php /var/www/orbitra/rotation_optimiser_cron.php"
fi

echo "  > Handing the installation over to www-data..."
chown -R www-data:www-data /var/www/orbitra
# Permissions are re-applied only where a root step created files. node_modules
# is deliberately left alone: a blanket chmod 664 would strip the executable bit
# from the binaries npm installed (esbuild among them) and break the next build.
find /var/www/orbitra/frontend/dist -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/orbitra/frontend/dist -type f -exec chmod 664 {} \; 2>/dev/null || true

# Smoke tests to verify installation
echo "  > Running smoke tests..."
# Test 1: Verify _internal_assets location
if grep -q "location /_internal_assets/" /etc/nginx/sites-available/orbitra; then
    echo "  > ✓ Nginx config contains _internal_assets location"
else
    echo "  > ⚠ WARNING: _internal_assets location missing in nginx config"
fi

# Test 2: Verify ACME webroot is writable
if [ -w /var/www/orbitra/var/acme/.well-known/acme-challenge ]; then
    echo "  > ✓ ACME webroot is writable"
else
    echo "  > ⚠ WARNING: ACME webroot may not be writable"
fi

# Test 3: Check self-signed certificate exists
if [ -f /etc/orbitra/ssl/self-signed.crt ] && [ -f /etc/orbitra/ssl/self-signed.key ]; then
    echo "  > ✓ Self-signed certificate exists"
else
    echo "  > ⚠ WARNING: Self-signed certificate may be missing"
fi

# Test 4: Test nginx config syntax
if nginx -t 2>&1 | grep -q "successful"; then
    echo "  > ✓ Nginx configuration is valid"
else
    echo "  > ⚠ WARNING: Nginx configuration may have errors"
fi

echo "  > Smoke tests completed."

# Get public IP for output
SERVER_IP=${SERVER_IP:-$(curl -s http://checkip.amazonaws.com || echo "your_server_ip")}

echo "======================================================="
echo " ✅ INSTALLATION COMPLETED SUCCESSFULLY!                "
echo "======================================================="
echo " Complete the setup and create the first administrator:"
echo " 🔗 http://$SERVER_IP/admin.php                        "
echo ""
echo " This address keeps working after you park domains."
echo " If the panel ever stops responding, rebuild the"
echo " web-server config from the database:"
echo "   sudo php /var/www/orbitra/cli/nginx_sync.php"
echo "======================================================="
echo " 🤖 AI assistant (MCP): connect Claude Desktop to your"
echo "    tracker to analyse & manage campaigns in plain text."
echo "    1) In the UI: Users -> API Keys -> generate a key."
echo "    2) See mcp/README.md for the Claude Desktop config."
echo "======================================================="
