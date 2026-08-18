# Security Operations Checklist - Credential Rotation

**Date**: _______________
**Performed by**: _______________

---

## Server Credentials

### Linux Root Password
- [ ] Rotate root password
- [ ] Update any password managers/vaults storing the old password
- [ ] Test new password login
- [ ] Document new password in secure location

**Command**:
```bash
sudo passwd root
```

---

## Application Credentials

### Admin Panel Access
- [ ] Update mainadmin credentials
- [ ] Change from default `12345qweasd`
- [ ] Update any additional admin accounts
- [ ] Test login with new credentials

**Database Update** (if stored in DB):
```sql
-- Check current admin users
SELECT id, username, email FROM admins;

-- Update admin password (hash varies by implementation)
-- Use panel interface to change passwords
```

---

## API Keys and Secrets

### Orbitra API Keys
- [ ] Regenerate API Key 1
- [ ] Regenerate API Key 2
- [ ] Regenerate API Key 3
- [ ] Update `.env` file with new keys
- [ ] Update integration services using old keys
- [ ] Test API access with new keys

**Location**: `/var/www/orbitra/.env`

**Keys to rotate**:
```
ORBITRA_API_KEY_1=***
ORBITRA_API_KEY_2=***
ORBITRA_API_KEY_3=***
POSTBACK_KEY=***
JWT_SECRET=***
```

---

## Database Credentials

### Database Access
- [ ] Rotate database user password
- [ ] Update `.env` DB_PASSWORD
- [ ] Test database connectivity
- [ ] Update any backup scripts with new password

**Config location**: `/var/www/orbitra/.env`
```bash
# Test database connection after password change
mysql -u orbitra_user -p orbitra_db
```

---

## Integration Services

### Affiliate Networks
- [ ] Review API credentials for connected networks
- [ ] Rotate any exposed API keys
- [ ] Update connection settings in panel

### Cloudflare
- [ ] Review Cloudflare API token
- [ ] Rotate if compromised or expired
- [ ] Update in Settings → Integrations

### Third-party Services
- [ ] Review all connected services
- [ ] Rotate any exposed credentials
- [ ] Document all service credentials in secure vault

---

## Access Control

### SSH Access
- [ ] Audit `~/.ssh/authorized_keys` files
- [ ] Remove unused/unknown keys
- [ ] Disable password authentication (key-only)
- [ ] Review sudo access lists

**Audit SSH keys**:
```bash
# Check root SSH keys
sudo cat /root/.ssh/authorized_keys

# Check other users
for user in $(cut -d: -f1 /etc/passwd); do
  if [ -f "/home/$user/.ssh/authorized_keys" ]; then
    echo "=== $user ==="
    cat "/home/$user/.ssh/authorized_keys"
  fi
done
```

### Sudo Access
- [ ] Review `/etc/sudoers`
- [ ] Review `/etc/sudoers.d/`
- [ ] Remove unnecessary sudo grants
- [ ] Audit orbitra SSL sudo permissions

---

## Application-Level Security

### Session Management
- [ ] Review session timeout settings
- [ ] Verify secure cookie flags
- [ ] Check for active sessions

### File Permissions
- [ ] Verify `.env` is not web-accessible
- [ ] Check log file permissions (should not be public)
- [ ] Verify sensitive directories are protected

**Check web-accessible files**:
```bash
# Test if .env is accessible
curl https://your-domain.com/.env

# Test if log files are accessible (should return 404 after fix)
curl https://your-domain.com/var/logs/php_errors.log
```

---

## Post-Rotation Verification

- [ ] All services functioning normally
- [ ] API calls working with new keys
- [ ] Admin login functional
- [ ] Database connectivity confirmed
- [ ] No errors in application logs
- [ ] No errors in nginx logs
- [ ] SSL certificates valid
- [ ] Cron jobs running

---

## Emergency Rollback

If issues arise after credential rotation:

1. **Restore database access** - Reset DB password if connection fails
2. **Restore API keys** - Revert to previous keys in `.env`
3. **Check logs** - Review `/var/www/orbitra/var/logs/` for errors

```bash
# Check application logs
tail -f /var/www/orbitra/var/logs/php_errors.log

# Check nginx logs
tail -f /var/log/nginx/error.log

# Restart services if needed
sudo systemctl restart php-fpm
sudo systemctl reload nginx
```

---

## Notes

**Previous credentials**:
- Store securely in password manager until confirmed working

**New credentials**:
- Document in secure vault
- Share only with authorized personnel

**Completed by**: _______________
**Date**: _______________
**Reviewed by**: _______________
