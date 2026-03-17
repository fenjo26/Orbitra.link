#!/bin/bash
# Orbitra Nginx Config Recovery Script
# Run this script if Certbot broke your Nginx configuration

set -e

echo "Orbitra Nginx Configuration Recovery"
echo "====================================="

# Check if running as root
if [ "$EUID" -ne 0 ]; then
  echo "Please run this script as root (use sudo)"
  exit 1
fi

# Check if orbitra directory exists
if [ ! -d "/var/www/orbitra" ]; then
    echo "ERROR: /var/www/orbitra not found"
    exit 1
fi

echo "Checking Nginx configuration..."

# Get PHP version
PHP_V=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")
PHP_FPM_SOCK="/var/run/php/php${PHP_V}-fpm.sock"

# Check if config is broken
CONFIG_FILE="/etc/nginx/sites-available/orbitra"
NEEDS_FIX=false

# Check for "return 404"
if grep -q "return 404" "$CONFIG_FILE" 2>/dev/null; then
    echo "  > Found 'return 404' in config (Certbot breakage)"
    NEEDS_FIX=true
fi

# Check for missing "listen 80" in first server block
if ! awk '/^server \{/,/^}/ {if (/[[:space:]]*listen[[:space:]]+80/) {found=1; exit}} END {exit !found}' "$CONFIG_FILE" 2>/dev/null; then
    echo "  > Missing 'listen 80' in first server block (Certbot breakage)"
    NEEDS_FIX=true
fi

if [ "$NEEDS_FIX" = false ]; then
    echo "  > Configuration looks OK!"
    echo "No changes needed."
    exit 0
fi

echo "Regenerating Nginx configuration..."

cat > "$CONFIG_FILE" << EOF
server {
    listen 80 default_server;
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

# Test and reload Nginx
echo "Testing Nginx configuration..."
nginx -t

echo "Reloading Nginx..."
systemctl reload nginx

echo ""
echo "✅ Nginx configuration fixed successfully!"
echo ""
echo "Test your API:"
echo "  curl http://$(curl -s http://checkip.amazonaws.com)/api.php?action=domains"
echo ""
