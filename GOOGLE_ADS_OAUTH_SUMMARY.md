# Google Ads 1-Click OAuth Implementation Summary

## Overview

This document summarizes the implementation of the seamless 1-Click Google Ads OAuth connection for Orbitra Tracker, matching the Keitaro tracker UX.

## Implementation Status

✅ **Core OAuth Flow** - Already fully implemented in the codebase
✅ **OAuth URL Updated** - Added `select_account` to prompt parameter
✅ **Documentation Added** - README files updated with setup instructions
✅ **Environment Configuration** - Example .env file created
✅ **Verification Script** - Helper script to check configuration

## Key Changes Made

### 1. OAuth URL Enhancement (`api.php`)

**File**: `api.php` (line ~14687)
**Change**: Updated the OAuth authorization URL to include `select_account` prompt

```php
// Before:
'prompt' => 'consent',

// After:
'prompt' => 'consent select_account',
```

**Effect**: When users click "Sign in with Google", they will see all logged-in Gmail accounts in the account chooser, not just the currently active one.

### 2. Documentation Updates

**Files Created/Modified**:
- `.env.example` - New file with Google Ads OAuth environment variables
- `README.md` - Added "Google Ads 1-Click OAuth Setup" section
- `README.ru.md` - Added Russian translation of the setup guide
- `check_google_ads_oauth.php` - Verification script for configuration

### 3. Environment Variables

Required environment variables for 1-Click OAuth:

```bash
ORBITRA_GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
ORBITRA_GOOGLE_CLIENT_SECRET=your-client-secret-here
ORBITRA_GOOGLE_DEVELOPER_TOKEN=your-developer-token
```

## How the Flow Works

### 1. User Initiates OAuth
- User clicks "Sign in with Google" button in **Integrations → Google Ads Costs**
- Frontend opens popup to `?action=google_ads_oauth_start`

### 2. Server Validates Configuration
- `orbitraGoogleAdsOAuthCredentials()` checks credentials in order:
  1. Environment variables (`ORBITRA_GOOGLE_*`)
  2. Database settings table
  3. Existing manual connection credentials

### 3. Google OAuth Authorization
- User is redirected to Google's OAuth consent screen
- `prompt=consent select_account` ensures:
  - User sees all logged-in accounts
  - Refresh token is always returned

### 4. Account Discovery
- After authorization, callback exchanges code for tokens
- API calls discover:
  - Direct accessible accounts (`listAccessibleCustomers`)
  - Account metadata (name, currency, timezone, manager status)
  - MCC hierarchies (`customer_client` query)

### 5. Account Selection UI
- Discovered accounts are displayed grouped by manager
- User selects which accounts to connect
- Each account creates a separate `aggregator_connections` row

### 6. Automatic Synchronization
- Connected accounts are automatically scheduled for spend sync
- Default interval: 2 hours
- Uses `aggregator_cron.php` for periodic sync

## Fallback: Manual Token Connection

When server OAuth credentials are not configured:
- UI shows "Direct Token Connection" tab
- User manually enters:
  - Developer Token
  - OAuth2 Client ID
  - OAuth2 Client Secret
  - OAuth2 Refresh Token
  - Customer ID
- These credentials are then reused for the 1-Click flow

## Testing Checklist

### 1. Server Configuration Test
```bash
php check_google_ads_oauth.php
```

Expected output with credentials configured:
```
✓ ORBITRA_GOOGLE_CLIENT_ID = xxx.apps.googleusercontent.com
✓ ORBITRA_GOOGLE_CLIENT_SECRET = xxxx...
✓ ORBITRA_GOOGLE_DEVELOPER_TOKEN = xxxxx...
✓ Google Ads OAuth is CONFIGURED
```

### 2. OAuth Flow Test
1. Navigate to **Integrations → Google Ads Costs**
2. Click **Add Account → 1-Click OAuth** tab
3. Click **"Sign in with Google"**
4. Verify account chooser appears with all logged-in Gmail profiles
5. Select account and authorize
6. Verify discovered accounts list appears
7. Select accounts and click **Connect**
8. Verify connections appear in the list

### 3. Sync Test
1. Click **Sync Now** on a connected account
2. Verify spend data is imported
3. Verify currency conversion works
4. Verify campaign attribution works

## Architecture Details

### Credential Storage Priority
```
Environment Variables → Settings Table → Manual Connection Credentials
```

### Session Flow
- OAuth state stored in `$_SESSION['google_ads_oauth_states']`
- OAuth flow (with refresh token) stored in `$_SESSION['google_ads_oauth_flows']`
- Sessions expire after 15 minutes (900 seconds)

### Account Tree Building
- File: `core/google_ads_tree.php`
- Function: `orbitraGoogleAdsBuildAccountTree()`
- Handles:
  - Direct accessible accounts
  - MCC hierarchies
  - Hidden/test account filtering
  - CID formatting (123-456-7890)

## Files Involved

| File | Purpose |
|------|---------|
| `api.php` | OAuth endpoints (`google_ads_oauth_start`, `google_ads_oauth_callback`, `google_ads_connect_accounts`, `google_ads_oauth_status`) |
| `frontend/src/components/IntegrationsPage.jsx` | UI for Google Ads connection |
| `aggregator_engines/GoogleAdsEngine.php` | Cost import engine |
| `core/google_ads_tree.php` | Account discovery and tree building |
| `aggregator_cron.php` | Periodic spend sync |

## Security Considerations

1. **Refresh Token Security**: Never exposed to frontend, stays server-side in session
2. **State Validation**: OAuth state validated to prevent CSRF
3. **Session Timeout**: OAuth states and flows expire after 15 minutes
4. **User Validation**: Session user_id verified throughout flow
5. **Origin Validation**: Redirect URI validated against request origin

## Known Limitations

1. **Developer Token Required**: Each Google Ads manager needs a developer token
2. **Account Limits**: Up to 500 accounts can be connected per OAuth flow
3. **Refresh Token Lifetime**: Long-lived, but can be revoked by user
4. **MCC Hierarchies**: Only immediate children are discovered (not full nested tree)

## Future Enhancements

Potential improvements for future versions:

1. **Nested MCC Support**: Discover accounts beyond first-level MCC children
2. **Credential Rotation**: Support for rotating OAuth credentials
3. **Bulk Account Operations**: Enable/disable multiple accounts at once
4. **Sync Status Dashboard**: Real-time sync status across all accounts
5. **Test Account Filtering**: Better UI for excluding test accounts

## Troubleshooting

### Issue: "OAuth is not configured" message appears
**Solution**: Set environment variables on the server:
```bash
export ORBITRA_GOOGLE_CLIENT_ID="..."
export ORBITRA_GOOGLE_CLIENT_SECRET="..."
export ORBITRA_GOOGLE_DEVELOPER_TOKEN="..."
```

### Issue: "Google did not return a refresh token"
**Solution**: User has previously authorized without offline access. They need to:
1. Go to https://myaccount.google.com/permissions
2. Revoke Orbitra's access
3. Connect again

### Issue: "No Google Ads accounts were discovered"
**Solution**: Verify the Google account has access to Google Ads accounts and the Developer Token is valid.

## References

- Google Ads API Documentation: https://developers.google.com/google-ads/api/docs
- OAuth 2.0 for Google: https://developers.google.com/identity/protocols/oauth2
- Google Ads API Center: https://ads.google.com/aw/apicenter

---

**Implementation Date**: 2026-08-18
**Version**: v1.0.9+
**Status**: Complete ✅
