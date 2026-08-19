# Cloudflare Full Strict SSL Configuration

## Overview

Orbitra supports all Cloudflare SSL modes including **Full Strict**. This document explains how to configure each mode and the requirements for each.

## Cloudflare SSL Modes

### Flexible (Not Recommended)

**Configuration:**
- Domain: Proxied through Cloudflare (orange cloud)
- Cloudflare SSL: **Flexible**
- Origin: HTTP only (port 80)

**Warning:** The connection between Cloudflare and your origin travels unencrypted. This mode is NOT recommended for security reasons.

### Full (Recommended)

**Configuration:**
- Domain: Proxied through Cloudflare (orange cloud)
- Cloudflare SSL: **Full**
- Origin: HTTPS on port 443 with self-signed certificate

**Setup:**
Orbitra automatically generates a self-signed certificate for the origin and creates an HTTPS server block on port 443. No additional configuration required.

**How it works:**
1. Visitors connect to Cloudflare over HTTPS (trusted Cloudflare certificate)
2. Cloudflare connects to your origin over HTTPS (accepts self-signed certificate)
3. Orbitra serves the landing page with proper HTTPS

**Status:** ✓ Fully supported, automatic setup

### Full Strict

**Configuration:**
- Domain: Proxied through Cloudflare (orange cloud)
- Cloudflare SSL: **Full (Strict)**
- Origin: HTTPS on port 443 with valid certificate

**Setup requires one of the following:**

#### Option 1: Cloudflare Origin CA Certificate (Recommended)

Cloudflare provides free Origin CA certificates valid for 15 years that are trusted only by Cloudflare.

**Steps:**
1. Generate a Cloudflare Origin CA certificate:
   - Go to Cloudflare Dashboard → SSL/TLS → Origin Server
   - Click "Create Certificate"
   - Select the domain (or use a wildcard *.yourdomain.com)
   - Copy the certificate and private key

2. Save certificate files on your server:
   ```bash
   # Save the certificate
   sudo tee /etc/orbitra/ssl/cloudflare_origin_yourdomain.com.crt > /dev/null
   # Paste the certificate content, then Ctrl+D

   # Save the private key
   sudo tee /etc/orbitra/ssl/cloudflare_origin_yourdomain.com.key > /dev/null
   # Paste the key content, then Ctrl+D

   # Secure the key file
   sudo chmod 600 /etc/orbitra/ssl/cloudflare_origin_yourdomain.com.key
   ```

3. Configure in Orbitra domain settings:
   - Edit the domain in Orbitra panel
   - **Custom SSL Certificate:** `/etc/orbitra/ssl/cloudflare_origin_yourdomain.com.crt`
   - **Custom SSL Key:** `/etc/orbitra/ssl/cloudflare_origin_yourdomain.com.key`
   - **SSL Source:** `cloudflare_origin`
   - Save

Orbitra will regenerate the nginx config to use these certificates.

#### Option 2: Custom/Public Certificate

You can use your own publicly trusted certificate (e.g., from another CA):

**Steps:**
1. Place your certificate and key on the server:
   ```bash
   sudo tee /etc/orbitra/ssl/yourdomain.com.crt > /dev/null
   # Paste your certificate (include full chain if available)

   sudo tee /etc/orbitra/ssl/yourdomain.com.key > /dev/null
   # Paste your private key

   sudo chmod 600 /etc/orbitra/ssl/yourdomain.com.key
   ```

2. Configure in Orbitra:
   - Edit the domain in Orbitra panel
   - **Custom SSL Certificate:** `/etc/orbitra/ssl/yourdomain.com.crt`
   - **Custom SSL Key:** `/etc/orbitra/ssl/yourdomain.com.key`
   - **SSL Source:** `custom`
   - Save

#### Option 3: Let's Encrypt

Orbitra automatically issues Let's Encrypt certificates for non-proxied domains. For Cloudflare Full Strict:

1. Temporarily disable Cloudflare proxy (DNS-only, grey cloud)
2. Add domain to Orbitra (will issue Let's Encrypt cert)
3. Wait for SSL to show "Installed"
4. Re-enable Cloudflare proxy (orange cloud)
5. Set Cloudflare SSL to "Full (Strict)"

## Verification

After configuration, verify SSL is working correctly:

1. **Check in Orbitra panel:** Navigate to Domains and verify SSL status shows "SSL installed"

2. **Test via API:**
   ```bash
   curl -I https://yourdomain.com
   # Should return 200 with proper headers
   ```

3. **Test origin certificate:**
   ```bash
   openssl s_client -connect yourdomain.com:443 -servername yourdomain.com
   # Should show the certificate you configured
   ```

## Troubleshooting

### "Answered by another vhost"

If you see this error in SSL status checks:
- Another nginx vhost is capturing port 443 traffic for this domain
- Run: `sudo php /var/www/orbitra/cli/nginx_sync.php`
- This ensures Orbitra owns the default server on port 443

### "No certificate on origin"

The nginx config for this domain is missing the 443 block:
- Run: `sudo php /var/www/orbitra/cli/nginx_sync.php`
- Verify custom certificate paths are correct and files exist

### Certificate not updated after saving

Orbitra regenerates nginx config automatically. If it doesn't update:
1. Check permissions: `ls -la /etc/nginx/sites-available/orbitra`
2. Verify sudoers entry: `cat /etc/sudoers.d/orbitra-ssl`
3. Manual sync: `sudo php /var/www/orbitra/cli/nginx_sync.php`

## Security Notes

- **Private keys must be readable only by root:** `chmod 600`
- **Origin CA certificates are only trusted by Cloudflare:** Browsers accessing your origin directly will show warnings
- **For public origin access:** Use Let's Encrypt or a public CA certificate
- **Never commit private keys to version control**

## See Also

- [CERTBOT_SETUP.md](./CERTBOT_SETUP.md) - Let's Encrypt configuration
- ORB-014 - HTTPS on the origin for parked domains
