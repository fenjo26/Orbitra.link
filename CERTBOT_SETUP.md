# Certbot Setup Guide for Ubuntu/Debian

This guide provides step-by-step instructions for installing Certbot, configuring sudoers, and setting up CA certificates on Ubuntu/Debian servers.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installing Certbot](#installing-certbot)
3. [Configuring Sudoers](#configuring-sudoers)
4. [Setting Up CA Certificates](#setting-up-ca-certificates)
5. [Obtaining SSL Certificates](#obtaining-ssl-certificates)
6. [Auto-Renewal Configuration](#auto-renewal-configuration)
7. [Troubleshooting](#troubleshooting)

---

## Prerequisites

Before starting, ensure you have:

- A server running Ubuntu 18.04+ or Debian 10+
- Root or sudo access
- A domain name pointed to your server's IP address
- Ports 80 and 443 open in your firewall

Check your system version:

```bash
# Ubuntu
lsb_release -a

# Debian
cat /etc/debian_version
```

---

## Installing Certbot

### Method 1: Using Snap (Recommended for Ubuntu)

Certbot is distributed as a snap package and receives automatic updates.

```bash
# Install snapd if not already installed
sudo apt update
sudo apt install snapd

# Ensure snapd is up to date
sudo snap install core
sudo snap refresh core

# Install certbot
sudo snap install --classic certbot

# Create symbolic link for backward compatibility
sudo ln -s /snap/bin/certbot /usr/bin/certbot

# Verify installation
certbot --version
```

### Method 2: Using APT (Debian/Ubuntu Alternative)

```bash
# Update package lists
sudo apt update

# Install certbot and plugins
sudo apt install certbot python3-certbot-nginx python3-certbot-apache

# Verify installation
certbot --version
```

### Method 3: Using Pip (For Advanced Users)

```bash
# Install Python pip and dependencies
sudo apt update
sudo apt install python3-pip python3-venv libaugeas0

# Create virtual environment
python3 -m venv /opt/certbot-venv
source /opt/certbot-venv/bin/activate

# Install certbot
pip install --upgrade pip
pip install certbot certbot-nginx certbot-apache

# Create symlink
sudo ln -s /opt/certbot-venv/bin/certbot /usr/bin/certbot
```

---

## Configuring Sudoers

To allow certbot to run with elevated privileges without requiring a password each time, configure sudoers.

### Option 1: Allow Specific Certbot Commands

```bash
# Edit sudoers file safely
sudo visudo

# Add the following line (replace 'username' with actual username)
username ALL=(ALL) NOPASSWD:/usr/bin/certbot
```

### Option 2: Allow Certbot Webroot Plugin

```bash
# Edit sudoers
sudo visudo

# Add for webroot method
username ALL=(ALL) NOPASSWD:/usr/bin/certbot certonly --webroot -d *
```

### Option 3: Allow Certbot Renewal

```bash
# Edit sudoers
sudo visudo

# Add for renewal operations
username ALL=(ALL) NOPASSWD:/usr/bin/certbot renew
```

### Verify Sudoers Configuration

```bash
# Test sudoers syntax
sudo visudo -c

# Test certbot with sudo
sudo certbot --version
```

---

## Setting Up CA Certificates

### Install CA Certificates Package

```bash
# Install ca-certificates bundle
sudo apt update
sudo apt install ca-certificates

# Update certificate store
sudo update-ca-certificates
```

### Configure Certbot Certificate Locations

Certbot stores certificates in the following locations by default:

```bash
/etc/letsencrypt/live/yourdomain.com/
├── privkey.pem      # Private key
├── fullchain.pem    # Full certificate chain
├── chain.pem        # Certificate chain only
└── cert.pem         # Certificate only
```

### Read Certificate Locations

```bash
# View all certificates
sudo ls -la /etc/letsencrypt/live/

# View certificate details
sudo openssl x509 -in /etc/letsencrypt/live/yourdomain.com/cert.pem -text -noout
```

### Configure System Trust for Custom CA Certificates

If using custom CA certificates:

```bash
# Copy custom CA certificate
sudo cp your-custom-ca.crt /usr/local/share/ca-certificates/

# Update certificates
sudo update-ca-certificates

# Verify
ls /etc/ssl/certs/ | grep your-custom-ca
```

---

## Obtaining SSL Certificates

### Standalone Method (Port 80 Required)

```bash
# Stop web server if running
sudo systemctl stop nginx
# or
sudo systemctl stop apache2

# Obtain certificate
sudo certbot certonly --standalone -d yourdomain.com -d www.yourdomain.com

# Restart web server
sudo systemctl start nginx
```

### Webroot Method (Web Server Running)

```bash
# For Nginx - ensure webroot is accessible
sudo certbot certonly --webroot -w /var/www/html -d yourdomain.com -d www.yourdomain.com

# For Apache
sudo certbot certonly --webroot -w /var/www/html -d yourdomain.com -d www.yourdomain.com
```

### Nginx Plugin (Automatic Configuration)

```bash
# Install plugin if not present
sudo apt install python3-certbot-nginx

# Obtain and configure automatically
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### Apache Plugin (Automatic Configuration)

```bash
# Install plugin if not present
sudo apt install python3-certbot-apache

# Obtain and configure automatically
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com
```

### DNS Method (Wildcard Certificates)

```bash
# Install DNS plugin (example for Cloudflare)
sudo apt install python3-certbot-dns-cloudflare

# Configure Cloudflare credentials
sudo mkdir -p /etc/letsencrypt
echo "dns_cloudflare_api_token = YOUR_API_TOKEN" | sudo tee /etc/letsencrypt/cloudflare.ini
sudo chmod 600 /etc/letsencrypt/cloudflare.ini

# Obtain wildcard certificate
sudo certbot certonly --dns-cloudflare -d yourdomain.com -d "*.yourdomain.com"
```

### Non-Interactive Mode

```bash
# Obtain certificate with pre-set email and agreement
sudo certbot certonly --standalone \
  --email admin@yourdomain.com \
  --agree-tos \
  --no-eff-email \
  -d yourdomain.com
```

---

## Auto-Renewal Configuration

Certbot includes a systemd timer and a cron job for automatic renewal.

### Check Renewal Status

```bash
# Test renewal configuration
sudo certbot renew --dry-run

# View scheduled timer
systemctl list-timers | grep certbot
```

### Configure Systemd Timer

```bash
# Enable and start certbot timer
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer

# Check timer status
sudo systemctl status certbot.timer
```

### Configure Cron Job (Alternative)

```bash
# Open crontab
sudo crontab -e

# Add renewal check twice daily
0 */12 * * * certbot renew --quiet --post-hook "systemctl reload nginx"
```

### Configure Pre and Post Hooks

```bash
# Edit renewal configuration
sudo nano /etc/letsencrypt/renewal/yourdomain.com.conf

# Add hooks
renew_hook = systemctl reload nginx
pre_hook = systemctl stop nginx
post_hook = systemctl start nginx
```

### Test Auto-Renewal

```bash
# Simulate renewal
sudo certbot renew --dry-run

# Force renewal (for testing, not recommended in production)
sudo certbot renew --force-renewal
```

---

## Troubleshooting

### Common Issues

#### 1. Permission Denied Errors

```bash
# Check certbot permissions
which certbot
ls -la $(which certbot)

# Fix ownership
sudo chown root:root /usr/bin/certbot
sudo chmod 755 /usr/bin/certbot
```

#### 2. Port 80 Already in Use

```bash
# Find process using port 80
sudo netstat -tlnp | grep :80
# or
sudo lsof -i :80

# Stop conflicting service
sudo systemctl stop nginx  # or apache2, etc.
```

#### 3. Rate Limiting (Too Many Requests)

Let's Encrypt has rate limits (5 certificates per domain per 7 days for exact domains).

```bash
# Use staging environment for testing
sudo certbot certonly --staging --standalone -d yourdomain.com

# Then obtain real certificate
sudo certbot certonly --force-renewal --standalone -d yourdomain.com
```

#### 4. Certificate Chain Issues

```bash
# Test certificate chain
curl -vI https://yourdomain.com

# Check certificate files
sudo ls -la /etc/letsencrypt/live/yourdomain.com/

# Re-obtain certificate
sudo certbot certonly --force-renewal --standalone -d yourdomain.com
```

#### 5. Firewall Blocking

```bash
# UFW - allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw reload

# iptables - allow HTTP/HTTPS
sudo iptables -A INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -j ACCEPT
sudo iptables-save | sudo tee /etc/iptables/rules.v4
```

### View Logs

```bash
# Certbot logs
sudo tail -f /var/log/letsencrypt/letsencrypt.log

# Nginx logs
sudo tail -f /var/log/nginx/error.log

# Apache logs
sudo tail -f /var/log/apache2/error.log
```

### Reset Certbot Configuration

```bash
# Stop services
sudo systemctl stop nginx

# Backup current certificates
sudo cp -r /etc/letsencrypt /etc/letsencrypt.backup

# Remove old certificates
sudo certbot delete --cert-name yourdomain.com

# Re-obtain certificate
sudo certbot certonly --standalone -d yourdomain.com

# Restart services
sudo systemctl start nginx
```

---

## Additional Resources

- [Official Certbot Documentation](https://certbot.eff.org/docs/)
- [Let's Encrypt Documentation](https://letsencrypt.org/docs/)
- [Let's Encrypt Rate Limits](https://letsencrypt.org/docs/rate-limits/)
- [Nginx SSL Configuration](https://nginx.org/en/docs/http/configuring_https_servers.html)
