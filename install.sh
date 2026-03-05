#!/bin/bash
# Orbitra v0.9 Tracker Auto-Installer
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

# Определяем установленную версию PHP-FPM
PHP_V=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")
PHP_FPM_SOCK="/var/run/php/php${PHP_V}-fpm.sock"

echo "[2/4] Downloading Orbitra source code to /var/www/orbitra..."
# Удаляем старую папку, если вдруг есть
rm -rf /var/www/orbitra
# Клонируем репозиторий
# Пример: git clone https://github.com/fenjo26/Orbitra.link.git /var/www/orbitra
# Пока для примера создаем просто структуру (в реальном скрипте раскомментируйте git clone)
git clone https://github.com/fenjo26/Orbitra.link.git /var/www/orbitra || {
    echo "ERROR: Failed to download repository. Please check the github link."
    exit 1
}

echo "[3/4] Configuring permissions for SQLite Database..."
# Разрешаем Nginx писать в папку, чтобы SQLite мог создать БД
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

    # Доступ к статике React/Vite
    location /frontend/dist/ {
        alias /var/www/orbitra/frontend/dist/;
        try_files \$uri \$uri/ /frontend/dist/index.html;
    }

    # Роутинг трекера (API и клики)
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Обработка PHP 
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Запрет доступа к БД SQLite и логам конфигураций
    location ~ \.sqlite$ {
        deny all;
    }
    location ~ /\. {
        deny all;
    }
}
EOF

# Активация конфигурации Nginx
ln -sf /etc/nginx/sites-available/orbitra /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

systemctl restart php${PHP_V}-fpm
systemctl restart nginx

# Получаем внешний IP сервера для вывода
SERVER_IP=$(curl -s http://checkip.amazonaws.com || echo "ваш_ip_сервера")

echo "======================================================="
echo " ✅ INSTALLATION COMPLETED SUCCESSFULLY!                "
echo "======================================================="
echo " Complete the setup and create the first administrator:"
echo " 🔗 http://$SERVER_IP/admin.php                        "
echo "======================================================="
