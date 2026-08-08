#!/bin/bash
# Orbitra HTTPS Configuration Recovery
#
# Rebuilds /etc/nginx/sites-available/orbitra from the domains in the database,
# including an HTTPS server block for every domain that has a certificate, and a
# catch-all block so the panel stays reachable at the server IP.
#
# The generation itself lives in cli/nginx_sync.php, which is the same code the
# panel runs when you add or delete a domain — this script used to keep its own
# copy, and the two had drifted apart.

set -e

echo "Orbitra HTTPS Configuration Recovery"
echo "====================================="

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script as root (use sudo)"
  exit 1
fi

if [ ! -d "/var/www/orbitra" ]; then
    echo "ERROR: /var/www/orbitra not found"
    exit 1
fi

if [ ! -f "/var/www/orbitra/orbitra_db.sqlite" ]; then
    echo "ERROR: Database not found"
    exit 1
fi

echo "Rebuilding Nginx configuration from the database..."
php /var/www/orbitra/cli/nginx_sync.php

echo ""
echo "✅ Configuration restored."
echo ""
echo "If a domain still does not serve HTTPS, check its certificate:"
echo "  sudo certbot certificates"
