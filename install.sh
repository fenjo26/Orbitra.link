#!/bin/bash
# Orbitra v0.9.2 Tracker Auto-Installer
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

echo "[1/4] Updating system and installing packages (Nginx, PHP, SQLite)..."
apt-get update -y
apt-get install -y ca-certificates apt-transport-https software-properties-common curl git unzip nginx php-fpm php-sqlite3 php-curl php-mbstring php-xml php-zip

# Determine installed PHP-FPM version
PHP_V=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")
PHP_FPM_SOCK="/var/run/php/php${PHP_V}-fpm.sock"

echo "[2/4] Downloading Orbitra source code to /var/www/orbitra..."
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

# Remove old folder if it exists
rm -rf /var/www/orbitra
# Clone the repository
git clone https://github.com/fenjo26/Orbitra.link.git /var/www/orbitra || {
    echo "ERROR: Failed to download repository. Please check the github link."
    exit 1
}

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


echo "[3/4] Configuring permissions for SQLite Database..."
# Create necessary subdirectories first
mkdir -p /var/www/orbitra/var/geoip/SxGeoCity
mkdir -p /var/www/orbitra/geo
mkdir -p /var/www/orbitra/core

# Allow Nginx to write to the folder so SQLite can create the DB
chown -R www-data:www-data /var/www/orbitra
find /var/www/orbitra -type d -exec chmod 775 {} \;
find /var/www/orbitra -type f -exec chmod 664 {} \;

echo "[4/4] Configuring Nginx web server..."
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

# Get public IP for output
SERVER_IP=$(curl -s http://checkip.amazonaws.com || echo "your_server_ip")

echo "======================================================="
echo " ✅ INSTALLATION COMPLETED SUCCESSFULLY!                "
echo "======================================================="
echo " Complete the setup and create the first administrator:"
echo " 🔗 http://$SERVER_IP/admin.php                        "
echo "======================================================="
