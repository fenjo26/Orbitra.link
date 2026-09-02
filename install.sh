#!/bin/bash
# Orbitra Tracker Auto-Installer
# Supported OS: Ubuntu 22.04+ / Debian 12+ (20.04 and Debian 11 ship only PHP 7.4)
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

# ---------------------------------------------------------------------------
# First-boot package-lock guard.
#
# A freshly provisioned image boots straight into its own upgrade: both
# apt-daily.timer and apt-daily-upgrade.timer are Persistent=true, so a machine
# with no previous run stamp fires them within minutes of its first boot, and
# the unattended-upgrade they start holds /var/lib/dpkg/lock-frontend for as
# long as that first full upgrade takes. Waiting is not enough on its own — a
# real install sat through apt's ten-minute wait and still died with "Could not
# get lock /var/lib/dpkg/lock-frontend ... held by process N (unattended-upgr)".
#
# Three details decide whether this works, each one learned from a failed run:
#   * the TIMERS have to be stopped first. Stopping only the service lets the
#     timer start it again a minute later — which is exactly what happened: the
#     wait found nothing to wait for, and apt met the upgrade right afterwards.
#   * apt-daily-upgrade.service is KillMode=process, so `systemctl stop` signals
#     the wrapper script only and leaves the unattended-upgrade child — the
#     process that actually holds the lock — running. It has to be signalled by
#     name, not through the unit.
#   * SIGTERM, never SIGKILL. unattended-upgrade answers SIGTERM by finishing
#     the dpkg call in flight and exiting; killing it outright orphans a dpkg
#     that still holds the inner /var/lib/dpkg/lock (so the lock is not even
#     released) and can leave the package database half-configured.
# ---------------------------------------------------------------------------

# Belt to the braces below: every apt call in this script — and in any re-run —
# waits for the lock instead of failing outright. Understood by apt since
# 1.9.11, which is older than every release this installer supports.
echo 'DPkg::Lock::Timeout "600";' > /etc/apt/apt.conf.d/99orbitra-lock-wait

APT_TIMERS_STOPPED=""

# Automatic security updates are switched off for the length of the install
# only. cleanup_tmp further down calls this too, so the timers come back on
# every exit path, successful or not.
restore_apt_timers() {
    [ -n "$APT_TIMERS_STOPPED" ] || return 0
    systemctl start $APT_TIMERS_STOPPED >/dev/null 2>&1 || true
    APT_TIMERS_STOPPED=""
}
trap restore_apt_timers EXIT

# PID holding any apt/dpkg lock, nothing when all four are free. Reads
# /proc/locks rather than fuser or lsof — minimal images ship neither, and apt,
# the one way to install them, is the very thing that is blocked. Matching the
# lock file's inode also beats guessing process names: it sees whoever holds
# the lock, whatever that process happens to be called.
apt_lock_holder() {
    local f ino pid
    for f in /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock \
             /var/lib/apt/lists/lock /var/cache/apt/archives/lock; do
        [ -e "$f" ] || continue
        ino=$(stat -c %i "$f" 2>/dev/null) || continue
        # "1: POSIX ADVISORY WRITE 2803 fe:00:688134 0 EOF" — the PID is the
        # field before the device:inode one. A blocked lock adds a "->" column,
        # so the fields are found by pattern rather than counted.
        pid=$(awk -v ino="$ino" '{ for (i = 2; i <= NF; i++) if ($i ~ ("^[0-9a-f]+:[0-9a-f]+:" ino "$")) { print $(i-1); exit } }' /proc/locks 2>/dev/null)
        if [ -n "$pid" ] && [ "$pid" != "0" ]; then
            echo "$pid"
            return 0
        fi
    done
    return 1
}

if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; then
    for apt_unit in apt-daily.timer apt-daily-upgrade.timer; do
        if systemctl is-active --quiet "$apt_unit" 2>/dev/null; then
            if systemctl stop "$apt_unit" >/dev/null 2>&1; then
                APT_TIMERS_STOPPED="$APT_TIMERS_STOPPED $apt_unit"
            fi
        fi
    done
    # --no-block: apt-daily-upgrade.service is TimeoutStopSec=900, and a plain
    # stop would sit here for up to fifteen minutes waiting for a unit whose
    # child gets signalled below anyway.
    systemctl stop --no-block apt-daily.service apt-daily-upgrade.service unattended-upgrades.service >/dev/null 2>&1 || true
fi

LOCK_PID="$(apt_lock_holder || true)"
if [ -n "$LOCK_PID" ]; then
    echo "  > A first-boot system update is holding the package lock. Asking it to finish (up to 5 minutes)..."
    WAITED=0
    while [ -n "$LOCK_PID" ] && [ "$WAITED" -lt 300 ]; do
        LOCK_NAME="$(cat /proc/$LOCK_PID/comm 2>/dev/null || echo 'a package process')"
        case "$LOCK_NAME" in
            dpkg*)
                # dpkg is never signalled: interrupting it mid-transaction is
                # how a package database ends up broken. A single dpkg call is
                # seconds, so waiting it out costs nothing.
                : ;;
            unattended-upgr*|apt.systemd*)
                # Signalled by PID, and only when the holder is the automatic
                # updater: a lock held by a human's own apt session, or by
                # anything else, is waited out instead of being killed. Re-sent
                # periodically rather than once, so an upgrade that started
                # after the timers were stopped is caught as well.
                # `pkill -f` is deliberately not used here — it matches command
                # lines, and a command line that mentions unattended-upgrade
                # (this installer's own, piped from a shell, for instance) would
                # make the script kill its own parent.
                if [ $((WAITED % 30)) -eq 0 ]; then
                    kill -TERM "$LOCK_PID" 2>/dev/null || true
                    pkill -TERM -x unattended-upgr >/dev/null 2>&1 || true
                fi ;;
            *)
                # A human's apt/apt-get, or something unidentified: never
                # signalled. apt's own lock timeout covers this case.
                : ;;
        esac
        if [ $((WAITED % 15)) -eq 0 ] && [ "$WAITED" -gt 0 ]; then
            echo "  > ...still waiting for $LOCK_NAME (pid $LOCK_PID), ${WAITED}s"
        fi
        sleep 5
        WAITED=$((WAITED + 5))
        LOCK_PID="$(apt_lock_holder || true)"
    done
    if [ -n "$LOCK_PID" ]; then
        echo "  > Still locked after 5 minutes. Carrying on — apt waits as well;"
        echo "    if this run does fail, simply run the installer again."
    else
        echo "  > Package lock released."
    fi
fi

# An upgrade that stopped mid-action can leave dpkg half-configured; a no-op
# when nothing was interrupted, and it unblocks apt when something was.
dpkg --configure -a >/dev/null 2>&1 || true

echo "[1/5] Updating system and installing packages (Nginx, PHP, SQLite)..."
apt-get update -y
# php-intl is optional at runtime — landing slugs fall back to a built-in
# transliteration table without it — but with it installed every alphabet
# transliterates, not just the ones the table covers.
# php-bcmath is NOT optional: ip2location/ip2location-php and ip2location/ip2proxy-php
# both declare "ext-bcmath" as a hard requirement, so without it `composer install`
# refuses the lock file entirely ("Your lock file does not contain a compatible set
# of packages") and every install and in-panel update dies at the dependency step.
apt-get install -y ca-certificates apt-transport-https curl git unzip nginx php-fpm php-cli php-sqlite3 php-curl php-mbstring php-xml php-zip php-intl php-bcmath
# software-properties-common is an Ubuntu package; Debian 13 dropped it, and
# nothing in this installer needs it there — it must not fail the whole
# package step on a Debian release.
if grep -qi '^ID=ubuntu' /etc/os-release 2>/dev/null; then
    apt-get install -y software-properties-common
fi

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
        if curl -fsSL https://deb.nodesource.com/setup_20.x -o /tmp/nodesource_setup.sh; then
            bash /tmp/nodesource_setup.sh
        else
            echo "  > WARNING: could not reach deb.nodesource.com — falling back to the distribution's Node.js."
        fi
        rm -f /tmp/nodesource_setup.sh
        apt-get install -y nodejs
    else
        echo "  > Node.js $(node -v) already installed (version 20+) - skipping"
    fi
else
    echo "  > Installing Node.js 20.x..."
    if curl -fsSL https://deb.nodesource.com/setup_20.x -o /tmp/nodesource_setup.sh; then
        bash /tmp/nodesource_setup.sh
    else
        echo "  > WARNING: could not reach deb.nodesource.com — falling back to the distribution's Node.js."
    fi
    rm -f /tmp/nodesource_setup.sh
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
# Reading the PUBLIC half of /etc/letsencrypt back.
#
# certbot creates live/ and archive/ as 0700 root, so the web user cannot even
# traverse to the certificate it has to read — and PHP's file_exists() then
# answers "no certificate" for one that exists, which is how a healthy domain
# gets classified self-signed and shows ERR_CERT_AUTHORITY_INVALID. Opening the
# two directory levels exposes nothing on its own: the private keys are 0600
# root-only FILES inside them, and nginx reads those as root.
chmod 0755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || true
# The directories are created by the first issuance, which happens after this
# script, and certbot re-applies 0700 on some renewals — so the same two chmods
# run again after every issuance and renewal.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/00-orbitra-readable.sh <<'ORBITRA_HOOK'
#!/bin/sh
# Orbitra: keep the public half of /etc/letsencrypt readable by the web user.
# Directory bits only — private key files keep their own 0600 root-only mode.
chmod 0755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || true
ORBITRA_HOOK
chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/00-orbitra-readable.sh

# Fallback for hosts where an administrator keeps /etc/letsencrypt closed.
#
# This used to be a list of sudoers rules of the form
#   www-data ALL=(ALL) NOPASSWD: /bin/cat /etc/letsencrypt/live/*/fullchain.pem
# and on Ubuntu 25.10 and newer every one of them is a parse error. Those
# releases ship sudo-rs as the default sudo, and sudo-rs does not implement
# wildcards in command arguments at all ("wildcards are not allowed in command
# arguments"). The rules were dropped — so the panel could not read a
# certificate it had just issued — and the parse errors were printed on the
# stderr of every sudo call the panel made, including the certbot runs whose
# output it parses. A helper with a fixed path needs no wildcard in sudoers:
# the argument check lives in the script, where it can be exact.
cat > /usr/local/bin/orbitra-catcert <<'ORBITRA_CATCERT'
#!/bin/sh
# Print ONE public certificate file from /etc/letsencrypt, and nothing else.
# Reached through a NOPASSWD sudoers rule, so it must police its own argument:
# a sudoers rule without arguments allows any. Private keys are not listed
# below and `..` is refused outright — in `case`, a * matches slashes too, so
# without that check "live/../../root/x/fullchain.pem" would pass the pattern.
set -eu
[ $# -eq 1 ] || { echo "usage: orbitra-catcert <path>" >&2; exit 2; }
case "$1" in
    *..*) exit 2 ;;
esac
case "$1" in
    /etc/letsencrypt/live/*/fullchain.pem) ;;
    /etc/letsencrypt/live/*/chain.pem) ;;
    /etc/letsencrypt/live/*/cert.pem) ;;
    /etc/letsencrypt/archive/*/fullchain*.pem) ;;
    /etc/letsencrypt/archive/*/chain*.pem) ;;
    /etc/letsencrypt/archive/*/cert*.pem) ;;
    *) exit 2 ;;
esac
exec cat -- "$1"
ORBITRA_CATCERT
chmod 0755 /usr/local/bin/orbitra-catcert
echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/orbitra-catcert" >> $SUDOERS_FILE
chmod 0440 $SUDOERS_FILE

# A sudoers file with a parse error is skipped rule by rule and its complaint
# goes to the stderr of every later sudo call, so a bad edit here is expensive
# and silent. Check it while there is still a human watching the install.
if command -v visudo >/dev/null 2>&1 && ! visudo -c -f $SUDOERS_FILE >/dev/null 2>&1; then
    echo "  > WARNING: $SUDOERS_FILE did not pass visudo -c. Certificate reads may fall back to"
    echo "    the file permissions above. Output of the check:"
    visudo -c -f $SUDOERS_FILE 2>&1 | sed 's/^/    /'
fi

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
    # This trap replaces the earlier `trap restore_apt_timers EXIT`, so the
    # timers stopped by the package-lock guard are restarted from here.
    restore_apt_timers
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

# Application error_log() must actually land somewhere. The stock www.conf
# ships with `;catch_workers_output = yes` commented out, so every app-level
# error goes to a discarded stderr — hours of silent-failure diagnosis
# (dead proxies, unqueued postbacks, deprecated Graph params) trace back to
# this one line. Enable it in every fpm pool config present.
for POOL_CONF in /etc/php/${PHP_V}/fpm/pool.d/*.conf; do
    [ -f "$POOL_CONF" ] || continue
    sed -i "s/^;[[:space:]]*catch_workers_output[[:space:]]*=.*/catch_workers_output = yes/" "$POOL_CONF"
done

systemctl restart php${PHP_V}-fpm
systemctl restart nginx

# A fresh cloud VM often ships with ufw active and only SSH allowed — nginx
# comes up perfectly and the panel is still unreachable from outside
# (ERR_CONNECTION_TIMED_OUT on 80/443 while port 22 answers). install.sh
# owns the web stack, so it opens the web ports too. Hosts without ufw, or
# with it inactive, are left untouched; a provider-level firewall is beyond
# reach and the panel output says so below.
WEB_PORTS_OPENED=0
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 80/tcp  >/dev/null 2>&1 && WEB_PORTS_OPENED=1
    ufw allow 443/tcp >/dev/null 2>&1 && WEB_PORTS_OPENED=1
    [ "$WEB_PORTS_OPENED" -eq 1 ] && echo "  > ufw is active — opened 80/tcp and 443/tcp."
fi

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
# Every 5 minutes: the queue gates every certbot call on DNS and the retry
# ladder, so a tight cadence cannot burn Let's Encrypt's failure budget — it
# only shortens the wait for a domain whose DNS landed a minute after save.
# An existing hourly line from an older install is upgraded in place.
if crontab -u www-data -l 2>/dev/null | grep -qF "$SSL_CRON_MARKER"; then
    crontab -u www-data -l 2>/dev/null | grep -vF "$SSL_CRON_MARKER" | { cat; echo "*/5 * * * * php /var/www/orbitra/cli/ssl_installer.php >> /var/www/orbitra/var/logs/ssl_installer.log 2>&1 $SSL_CRON_MARKER"; } | crontab -u www-data - 2>/dev/null \
      && echo "  > Worker schedule upgraded to every 5 minutes." \
      || echo "  > NOTE: could not rewrite the crontab."
else
    {
        crontab -u www-data -l 2>/dev/null
        echo "*/5 * * * * php /var/www/orbitra/cli/ssl_installer.php >> /var/www/orbitra/var/logs/ssl_installer.log 2>&1 $SSL_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Worker scheduled (every 5 minutes)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: */5 * * * * php /var/www/orbitra/cli/ssl_installer.php"
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
# and non-due streams are skipped cheaply, so a per-minute cadence is safe.
# Streams without auto enabled never match the prefilter — this is a no-op
# for them. Every minute (not */5): a stream set to a 5-minute re-check
# interval must actually be re-checked every 5 minutes.
echo "  > Scheduling the rotation optimiser..."
ROTATION_CRON_MARKER="# orbitra-rotation"
if ! crontab -u www-data -l 2>/dev/null | grep -qF "$ROTATION_CRON_MARKER"; then
    {
        crontab -u www-data -l 2>/dev/null
        echo "* * * * * php /var/www/orbitra/rotation_optimiser_cron.php >> /var/www/orbitra/var/logs/rotation_optimiser.log 2>&1 $ROTATION_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Optimiser scheduled (every minute)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: * * * * * php /var/www/orbitra/rotation_optimiser_cron.php"
fi

# Outbound postback / CAPI queue worker. Every server-side conversion -- affiliate
# postbacks and Facebook/TikTok Conversions API events alike -- is written to
# s2s_postbacks_log as 'pending' and delivered from there. Without this worker the
# queue never drains and nothing sends, while the Pixel Vault test button (which
# posts directly) keeps reporting healthy. Every minute: the worker takes a lock,
# so overlapping runs are free.
echo "  > Scheduling the postback / CAPI queue worker..."
QUEUE_CRON_MARKER="# orbitra-postback-queue"
if ! crontab -u www-data -l 2>/dev/null | grep -qF "$QUEUE_CRON_MARKER"; then
    {
        crontab -u www-data -l 2>/dev/null
        echo "* * * * * php /var/www/orbitra/postback_queue_cron.php >> /var/www/orbitra/var/logs/postback_queue.log 2>&1 $QUEUE_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Queue worker scheduled (every minute)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: * * * * * php /var/www/orbitra/postback_queue_cron.php"
fi

# Cost aggregator. Pulls spend from the connected ad platforms and attributes it to
# clicks. Not scheduled, spend only moves when someone presses Sync -- and nothing
# in the panel says so, which reads as "the integration is broken".
echo "  > Scheduling the cost aggregator..."
AGGREGATOR_CRON_MARKER="# orbitra-aggregator"
if ! crontab -u www-data -l 2>/dev/null | grep -qF "$AGGREGATOR_CRON_MARKER"; then
    {
        crontab -u www-data -l 2>/dev/null
        echo "*/15 * * * * php /var/www/orbitra/aggregator_cron.php >> /var/www/orbitra/var/logs/aggregator.log 2>&1 $AGGREGATOR_CRON_MARKER"
    } | crontab -u www-data - 2>/dev/null \
      && echo "  > Aggregator scheduled (every 15 minutes)." \
      || echo "  > NOTE: could not write the crontab. Add this line manually: */15 * * * * php /var/www/orbitra/aggregator_cron.php"
fi

echo "  > Handing the installation over to www-data..."
chown -R www-data:www-data /var/www/orbitra
# Permissions are re-applied only where a root step created files. node_modules
# is deliberately left alone: a blanket chmod 664 would strip the executable bit
# from the binaries npm installed (esbuild among them) and break the next build.
find /var/www/orbitra/frontend/dist -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/orbitra/frontend/dist -type f -exec chmod 664 {} \; 2>/dev/null || true

# Postback key. The default that ships in the repository is public — anyone
# can read fd12e72 in the open repo — so a fresh install must not keep it:
# /fd12e72/postback accepts forged conversions from anyone who knows the
# install is unmodified. The CLI below boots the database (a fresh install has
# none until the first request) and swaps the default for a random key; a key
# the operator already changed is kept, so re-running the installer never
# breaks live postback URLs. Runs as www-data so a database created here is
# owned by the web server, not root.
echo "  > Generating a private postback key..."
PB_KEY=$(sudo -u www-data php /var/www/orbitra/cli/generate_postback_key.php 2>/dev/null || true)
if [[ "$PB_KEY" =~ ^[0-9a-f]{24}$ ]]; then
    echo "  > ✓ Postback key generated (printed in the summary below)"
else
    PB_KEY=""
    echo "  > NOTE: could not generate a postback key automatically. Change it"
    echo "  >       manually in Settings -> Postback after setup."
fi

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

# Test 5: the sudoers file this installer just wrote actually parses. A rule
# rejected here is dropped silently and the panel loses a privilege it believes
# it has — this is exactly how the wildcard `cat` rules died on Ubuntu 25.10,
# where the default sudo is sudo-rs and rejects wildcards in command arguments.
if command -v visudo >/dev/null 2>&1; then
    if visudo -c -f "$SUDOERS_FILE" >/dev/null 2>&1; then
        echo "  > ✓ sudo rules for the panel are valid"
    else
        echo "  > ⚠ WARNING: $SUDOERS_FILE has rules this sudo rejects:"
        visudo -c -f "$SUDOERS_FILE" 2>&1 | sed 's/^/      /'
    fi
fi

# Test 6: the web user can really run certbot without a password. Everything
# about automatic SSL depends on this one answer.
if sudo -u www-data sudo -n /usr/bin/certbot --version >/dev/null 2>&1; then
    echo "  > ✓ The panel can run Certbot (automatic SSL will work)"
else
    echo "  > ⚠ WARNING: the panel cannot run Certbot without a password."
    echo "      Automatic SSL will not work until $SUDOERS_FILE is fixed."
fi

# Test 7: the web user can read a certificate once one exists. Tested on the
# directories, because that is where it fails: certbot makes them 0700 root,
# and an unreadable certificate is reported by the panel as a broken one.
if [ -d /etc/letsencrypt/live ]; then
    if sudo -u www-data test -x /etc/letsencrypt/live; then
        echo "  > ✓ The panel can read issued certificates"
    else
        echo "  > ⚠ WARNING: /etc/letsencrypt/live is closed to the web user —"
        echo "      certificates would be reported as broken. Fix:"
        echo "      sudo chmod 0755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive"
    fi
fi

# Test 8: the panel answers over HTTP on this machine. Everything above can be
# green while PHP-FPM is not actually serving, and the operator finds out only
# when the browser shows a blank page.
LOCAL_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/admin.php || echo 000)"
case "$LOCAL_CODE" in
    200|302) echo "  > ✓ The panel answers on this server (HTTP $LOCAL_CODE)" ;;
    000)     echo "  > ⚠ WARNING: the panel did not answer on 127.0.0.1 — check: systemctl status nginx php${PHP_V}-fpm" ;;
    *)       echo "  > ⚠ WARNING: the panel answered HTTP $LOCAL_CODE on 127.0.0.1 — check /var/www/orbitra/var/logs/php_errors.log" ;;
esac

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
echo ""
if [ -n "$PB_KEY" ]; then
echo " Your postback endpoint — give this URL to your ad network:"
echo " 🔗 http://$SERVER_IP/$PB_KEY/postback?subid={subid}&status={status}&payout={payout}"
echo "    Your own parked domains (https) work the same way, and the key"
echo "    stays changeable any time in Settings -> Postback."
fi
echo ""
echo " If the address does not open from outside (times out):"
echo "   - a provider-level firewall must allow inbound 80 and 443;"
echo "   - with ufw active this installer opened them already."
echo ""
echo " If the panel ever stops responding, rebuild the"
echo " web-server config from the database:"
echo "   sudo php /var/www/orbitra/cli/nginx_sync.php"
echo "======================================================="
echo " 🤖 AI assistant (MCP): connect Claude Desktop to your"
echo "    tracker to analyse & manage campaigns in plain text."
echo "    1) In the UI: Users -> API Keys -> generate a key."
echo "    2) See mcp/README.md for the Claude Desktop config."
echo "======================================================="
