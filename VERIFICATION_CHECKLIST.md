# Verification Checklist - Infrastructure Fixes

## 1. Log File Protection (Security)
```bash
# Test nginx config generation
sudo php /var/www/orbitra/cli/nginx_sync.php

# Test nginx config syntax
sudo nginx -t

# Reload nginx if config is valid
sudo systemctl reload nginx

# Verify log files are blocked
curl https://your-domain.com/orbitra_leads_backup.log
# Expected: 404 Not Found

curl https://your-domain.com/var/logs/php_errors.log
# Expected: 404 Not Found
```

## 2. {subid} Macro Injection
```bash
# 1. Deploy updated adapter (js/orbitra-adapter.js)
# 2. Load a local offer page with a form
# 3. Inspect form HTML - should see:
<input type="hidden" name="subid" value="...">
<input type="hidden" name="sub1" value="...">
<input type="hidden" name="click_id" value="...">

# 4. Submit form and verify order.php receives parameters
# Check PHP error log or add debug output
```

## 3. Cloudflare Auto-Detection
```bash
# Test API endpoint
curl "https://your-tracker.com/api.php?action=check_cloudflare_status&id=DOMAIN_ID"

# Expected response:
{
  "status": "success",
  "data": {
    "id": 123,
    "name": "example.com",
    "cloudflare_proxy": 0,
    "detected": true,
    "message": "Cloudflare detected but not flagged - SSL should use Cloudflare edge certificate"
  }
}

# Check SSL worker log
tail -f /var/www/orbitra/var/logs/ssl_installer.log

# Run SSL worker manually
sudo php /var/www/orbitra/cli/ssl_installer.php

# Check domain status in database
mysql -u root -p orbitra_db -e "SELECT id, name, cloudflare_proxy, ssl_status FROM domains;"
```

## 4. Quick All-in-One Test
```bash
# Regenerate nginx config (includes log protection)
sudo php /var/www/orbitra/cli/nginx_sync.php

# Test log access
curl -I https://your-domain.com/orbitra_leads_backup.log

# Check SSL worker status
sudo php /var/www/orbitra/cli/ssl_installer.php

# Verify panel still accessible at IP
curl http://YOUR_SERVER_IP/admin.php
```

## 5. Manual Cloudflare Flag Testing
```sql
-- Manually set Cloudflare flag for a domain
UPDATE domains SET cloudflare_proxy = 1, ssl_status = 'cloudflare' WHERE id = DOMAIN_ID;

-- Verify flag is respected (Certbot should skip)
SELECT id, name, cloudflare_proxy, ssl_status FROM domains WHERE id = DOMAIN_ID;
```

---

## Expected Behaviors After Fix

✅ **Log files** return 404 when accessed via browser
✅ **Local offer forms** have hidden inputs with correct parameter names
✅ **order.php** receives `subid` and `sub1` in POST data
✅ **Cloudflare domains** auto-detected and flagged
✅ **Certbot skips** Cloudflare-proxied domains
✅ **Panel remains accessible** at server IP after parking domains
✅ **SSL certificates** work correctly for direct domains
