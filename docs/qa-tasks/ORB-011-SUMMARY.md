# ORB-011 Fix Summary

## Issue
LeadForge was sending leads to affiliate networks with fabricated or unresolvable subids, causing postbacks to fail with "Click ID not found in database."

## Root Cause
1. The preview route `/offers/<id>/` creates no click but still processes lead forms
2. LeadForge would invent a subid with `bin2hex(random_bytes(8))` when no valid subid was found
3. Stale cookies from deleted clicks were accepted without verification

## Changes Made

### 1. core/LeadForge.php
- **Removed** line 1155: `$subid = 'lead_' . bin2hex(random_bytes(8));`
- **Added** `lf_verify_click_exists()`: **Tri-state** verification against clicks table
  - Returns `true`: click exists → allow
  - Returns `false`: click doesn't exist → reject
  - Returns `null`: cannot verify (no DB) → allow with warning
- **Added** `lf_log_event()`: Logs rejections and warnings to system_logs
- **Added** Verification logic: Rejects leads with invalid click context (422 error)
- **Exemption**: QA mode bypasses verification (controlled by orbitra_qa flag or qa_test_ prefix)
- **Critical fix**: Remote deployments (bundles without DB access) now work correctly - they allow leads and log warnings instead of rejecting

### 2. tests/leadforge_subid_test.php (NEW)
- Test 1: Valid click - should accept
- Test 2: Tri-state (cannot verify/no DB) - should allow with warning
- Test 3: Stale cookie/deleted click - should reject
- Test 4: No context at all - should reject
- Test 5: QA mode - should bypass verification
- Test 6: Verify fabrication is removed

### 3. docs/leadforge-subid-validation.md (NEW)
- Documents the behavior change
- Explains tri-state verification table
- Explains rejection scenarios
- Provides testing guidelines
- Documents remote deployment behavior

## Acceptance Criteria Met

✓ Submitting on `/offers/<id>/` with no click context no longer sends invented/unresolvable subids
✓ `bin2hex(random_bytes)` removed for subid path (only QA/job uses remain)
✓ Stale cookies are verified against clicks table before use
✓ Real flow still carries valid clicks.id (existing tests pass)
✓ Behavior is identical across all routes (uses same LeadForge.php handler)
✓ Rejections logged to system_logs with diagnostic detail
✓ Test coverage for all scenarios including tri-state
✓ **Remote deployments without DB work correctly** (allow with warning, not reject)
✓ **Customer-facing error message is neutral** (no internal diagnostics exposed)

## Behavior Change

### Before
- No subid → Invent `lead_<random>` → Send to network → Postback fails

### After (Local Tracker with DB)
- No subid → Verify → Reject with 422 → Log to system_logs

### After (Remote Deployment without DB)
- No subid → Cannot verify (null) → Allow → Log warning → Send to network

### Customer-Facing Error Message
```
Unable to process your order. Please contact customer service or try again later.
```
(Diagnostic details logged to system_logs only, not shown to customers)

## Testing Results
- All new tests pass (6/6) ✓
- All existing tests pass (leadforge2_test.php: 65/65 ✓)
- Lander order route test passes (50/50 ✓)

## Files Modified
- `core/LeadForge.php` (removed fabrication, added tri-state verification)

## Files Added
- `tests/leadforge_subid_test.php`
- `docs/leadforge-subid-validation.md`
- `docs/qa-tasks/ORB-011-SUMMARY.md`

## QA Notes
- The correct way to test a conversion: enter through campaign link, clear cookies first
- QA mode bypass: `<input type="hidden" name="orbitra_qa" value="1">`
- Preview routes (`/offers/<id>/`, `/lander/<slug>/`) now correctly reject submissions
- Remote deployments (bundles on external hosting) continue to work without database access
