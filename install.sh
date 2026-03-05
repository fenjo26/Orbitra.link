#!/bin/bash
# Orbitra v0.9.1 Tracker Auto-Installer
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
apt-get install -y ca-certificates apt-transport-https software-properties-common curl git unzip nginx php-fpm php-sqlite3 php-curl php-mbstring php-xml

# Determine installed PHP-FPM version
PHP_V=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")
PHP_FPM_SOCK="/var/run/php/php${PHP_V}-fpm.sock"

echo "[2/4] Downloading Orbitra source code to /var/www/orbitra..."
# Remove old folder if it exists
rm -rf /var/www/orbitra
# Clone the repository
git clone https://github.com/fenjo26/Orbitra.link.git /var/www/orbitra || {
    echo "ERROR: Failed to download repository. Please check the github link."
    exit 1
}

echo "[3/4] Configuring permissions for SQLite Database..."
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

# Activate Nginx configuration
ln -sf /etc/nginx/sites-available/orbitra /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

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
