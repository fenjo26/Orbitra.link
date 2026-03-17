#!/bin/bash
# Orbitra v0.9.3.0 Tracker Auto-Installer
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
apt-get install -y ca-certificates apt-transport-https software-properties-common curl git unzip nginx php-fpm php-sqlite3 php-curl php-mbstring php-xml php-zip

# Determine installed PHP-FPM version
PHP_V=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")
PHP_FPM_SOCK="/var/run/php/php${PHP_V}-fpm.sock"

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

echo "[3/5] Downloading Orbitra source code to /var/www/orbitra..."
TMP_SRC_DIR="$(mktemp -d /tmp/orbitra_src.XXXXXX)"
cleanup_tmp() {
    rm -rf "$TMP_SRC_DIR"
}
trap cleanup_tmp EXIT

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


echo "[4/5] Configuring permissions for SQLite Database..."
# Create necessary subdirectories first
mkdir -p /var/www/orbitra/var/geoip/SxGeoCity
mkdir -p /var/www/orbitra/geo
mkdir -p /var/www/orbitra/core

# Allow Nginx to write to the folder so SQLite can create the DB
chown -R www-data:www-data /var/www/orbitra
find /var/www/orbitra -type d -exec chmod 775 {} \;
find /var/www/orbitra -type f -exec chmod 664 {} \;

# Configure sudoers for www-data to reload nginx (auto-domain management)
echo "  > Configuring sudoers for automatic Nginx reload..."
SUDOERS_FILE="/etc/sudoers.d/orbitra-nginx"
echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t" > $SUDOERS_FILE
echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload nginx" >> $SUDOERS_FILE
chmod 0440 $SUDOERS_FILE

echo "[5/5] Configuring Nginx web server and building frontend..."
cat > /etc/nginx/sites-available/orbitra << EOF
server {
    listen 80;
    server_name _;
    root /var/www/orbitra;
    index index.php admin.php index.html;

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
}
EOF

ln -sf /etc/nginx/sites-available/orbitra /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Increase PHP upload limits for Geo databases (approx 30-50MB)
sed -i "s/upload_max_filesize = .*/upload_max_filesize = 256M/" /etc/php/${PHP_V}/fpm/php.ini
sed -i "s/post_max_size = .*/post_max_size = 256M/" /etc/php/${PHP_V}/fpm/php.ini

systemctl restart php${PHP_V}-fpm
systemctl restart nginx

# Build frontend
echo "  > Building frontend..."
cd /var/www/orbitra/frontend
if [ -f "package.json" ]; then
    echo "  > Installing npm dependencies..."
    npm install --silent
    echo "  > Building production bundle..."
    npm run build
    echo "  > Frontend built successfully!"
else
    echo "  > WARNING: package.json not found, skipping frontend build"
fi

# Get public IP for output
SERVER_IP=$(curl -s http://checkip.amazonaws.com || echo "your_server_ip")

echo "======================================================="
echo " ✅ INSTALLATION COMPLETED SUCCESSFULLY!                "
echo "======================================================="
echo " Complete the setup and create the first administrator:"
echo " 🔗 http://$SERVER_IP/admin.php                        "
echo "======================================================="
