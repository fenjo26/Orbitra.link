# LeadForge Subid Validation (ORB-011 Fix)

## Overview

As of ORB-011, LeadForge no longer fabricates subids for leads with no verifiable click context. This prevents the "Click ID not found in database" error on postbacks when leads are submitted from preview routes or with stale cookies.

## Behavior

### Before ORB-011
- When no subid was found in request/cookies/session/query string, LeadForge would invent one: `lead_<random>`
- This invented subid was sent to the affiliate network
- The network's postback would fail with "Click ID not found in database" because the click never existed

### After ORB-011
- A subid is either a real `clicks.id` or it is absent
- Before sending a lead to the network, the subid is verified against the `clicks` table (tri-state verification)
- If verification confirms the click doesn't exist, the lead is rejected with a 422 error
- Stale cookies pointing to deleted clicks are treated as invalid
- **Remote deployments without database access** are handled gracefully (allow with warning)

## Tri-State Verification

`lf_verify_click_exists()` returns a tri-state to handle different scenarios:

| Return Value | Meaning | Action |
|--------------|---------|--------|
| `true` | Subid verified and exists in `clicks` table | **Allow** - valid click context |
| `false` | Subid does NOT exist (verified against DB) | **Reject** - invalid click (stale/missing) |
| `null` | Cannot verify (no DB available) | **Allow** - remote deployment, log warning |

This tri-state approach ensures:
- Local tracker deployments reject invalid clicks
- Remote deployments (bundles on external hosting) continue to work without database access
- All non-verifiable cases are logged for visibility

## Rejection Scenarios

A lead will be rejected with HTTP 422 in these cases:

1. **No click context** (`empty subid`): Submitted from `/offers/<id>/` or `/lander/<slug>/` preview routes without entering through a campaign link
2. **Stale cookie**: The `orbitra_click` cookie contains a click ID that no longer exists in the database (e.g., after a database reset or retention purge)
3. **Invalid subid**: The subid from any source doesn't match any row in the `clicks` table

**Note**: Remote deployments without database access will NOT reject leads - they will proceed with a warning logged to system_logs.

## Exemptions

**QA mode is exempt** from subid verification:
- Leads with `orbitra_qa=1` or subid starting with `qa_test_` bypass verification
- These are explicitly for testing and are managed by the `runQa` mechanism

## Customer-Facing Error Message

When a lead is rejected (invalid click context), the customer sees:

```
Unable to process your order. Please contact customer service or try again later.
```

This neutral message avoids exposing internal diagnostics (QA flags, preview routes, etc.) to end users.

## System Logs

Two types of events are logged to `system_logs`:

### Rejection (Invalid Click)
```json
{
  "level": "warning",
  "message": "LeadForge: Lead rejected due to invalid click context",
  "context": {
    "reason": "Subid does not correspond to a real click (stale cookie or invalid subid)",
    "subid": "b3ebefb2-db23-4541-a83a-d049d5f9d07f",
    "ip": "87.232.72.54",
    "offer_id": "123"
  }
}
```

### Warning (Cannot Verify - Remote Deployment)
```json
{
  "level": "warning",
  "message": "LeadForge: Subid could not be verified (no database available)",
  "context": {
    "subid": "b3ebefb2-db23-4541-a83a-d049d5f9d07f",
    "ip": "87.232.72.54",
    "offer_id": "123",
    "context": "remote_deployment_or_db_unavailable"
  }
}
```

## Testing

### Correct Testing Flow
1. Clear cookies (important: `orbitra_click` persists 24 hours)
2. Enter through the campaign link: `http://<host>/<campaign_alias>`
3. Follow through to the landing page
4. Submit the form

### Incorrect Testing (Will Be Rejected)
- Opening `/offers/<id>/` directly - this is a preview route with no click context
- Submitting with a stale cookie from a deleted click

### QA Mode Testing
Use the QA mode flag for testing without a real click:
```html
<input type="hidden" name="orbitra_qa" value="1">
```

### Remote Deployment Testing
Bundles deployed to external hosts without database access will:
- Accept leads (subid cannot be verified, not invalid)
- Log warnings to system_logs (if available) or continue silently
- Not show errors to customers

## Code Changes

### Removed
- `core/LeadForge.php` line 1155: `$subid = 'lead_' . bin2hex(random_bytes(8));` - subid fabrication fallback

### Added
- `lf_verify_click_exists($subid)`: Tri-state verification against `clicks` table (returns `true`/`false`/`null`)
- `lf_log_event($level, $message, $context)`: Logs to `system_logs`
- Verification logic before network call:
  - `true` → allow
  - `false` → reject with neutral customer message
  - `null` → allow with warning log
