#!/bin/bash
# Orbitra Nginx Config Recovery
#
# Kept for compatibility — the recovery now lives in one place, so that the
# installer, the panel and these scripts can never generate configs that differ.
#
#   sudo bash /var/www/orbitra/fix_nginx.sh
#
# is the same as
#
#   sudo php /var/www/orbitra/cli/nginx_sync.php

set -e

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script as root (use sudo)"
  exit 1
fi

if [ ! -d "/var/www/orbitra" ]; then
    echo "ERROR: /var/www/orbitra not found"
    exit 1
fi

exec php /var/www/orbitra/cli/nginx_sync.php
