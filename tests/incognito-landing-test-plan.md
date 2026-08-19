# Test Plan: Incognito Mode Landing/Offer Loading Fix

## Test Environment Setup

### Prerequisites
1. A working Orbitra installation with the fix applied
2. At least one local landing page created
3. At least one local offer created
4. A campaign with the local landing/offer attached

### Test Data Required
- Local landing: `/lander/test-landing/` (with CSS, JS, images)
- Local offer: `/offers/123/` (with CSS, JS, images)
- Campaign tracking link: e.g., `https://domain.com/abc123xy`

---

## Test Cases

### TC-01: Local Landing in Incognito - Chrome
**Priority:** P0 (Critical)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open Chrome Incognito window | Window opens |
| 2 | Navigate to campaign tracking link with local landing | Page loads |
| 3 | Inspect page appearance | **All styles rendered correctly** |
| 4 | Open DevTools → Network tab | Show all requests |
| 5 | Filter by "CSS" or "Stylesheet" | All CSS files loaded (200 status) |
| 6 | Filter by "Script" | All JS files loaded (200 status) |
| 7 | Filter by "Image" | All images loaded (200 status) |
| 8 | Look for any 404 errors | **Zero 404s for assets** |

**Pass Criteria:** Page renders perfectly, zero 404s for assets

---

### TC-02: Local Offer in Incognito - Chrome
**Priority:** P0 (Critical)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open Chrome Incognito window | Window opens |
| 2 | Navigate to campaign tracking link with local offer | Page loads |
| 3 | Inspect page appearance | **All styles rendered correctly** |
| 4 | Open DevTools → Console tab | No console errors |
| 5 | Open DevTools → Network tab | All assets loaded (200) |
| 6 | Verify fonts loaded correctly | **All fonts render** |

**Pass Criteria:** Full rendering, no 404s

---

### TC-03: Local Landing in Incognito - Safari
**Priority:** P0 (Critical)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Safari → File → New Private Window | Private window opens |
| 2 | Navigate to campaign link | Page loads |
| 3 | Visual inspection | **Page fully styled** |
| 4 | Develop → Show Web Inspector | Inspector opens |
| 5 | Network tab → filter resources | All assets (200) |
| 6 | Check for any blocked resources | **None blocked** |

**Pass Criteria:** Same as Chrome test

---

### TC-04: Local Offer in Incognito - Safari
**Priority:** P0 (Critical)

Same steps as TC-03, but using a campaign with local offer.

---

### TC-05: Local Landing in Incognito - Firefox
**Priority:** P0 (Critical)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Firefox → Open Private Window | Private window opens |
| 2 | Navigate to campaign link | Page loads |
| 3 | Visual inspection | **Page fully styled** |
| 4 | Web Developer → Network | Network monitor opens |
| 5 | Filter by CSS/JS/Images | All loaded (200) |
| 6 | Check console for errors | **No asset-related errors** |

**Pass Criteria:** Same as Chrome/Safari tests

---

### TC-06: Referer Fallback Test
**Priority:** P1 (High)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open Incognito window | Window opens |
| 2 | Navigate to preview URL `/lander/test-landing/` | Page loads with styles |
| 3 | Open DevTools → Network | See requests |
| 4 | Note asset URLs in Network tab | Should be `/lander/test-landing/css/...` |
| 5 | Direct load an asset in new tab: `https://domain.com/lander/test-landing/css/style.css` | Asset loads (200) |
| 6 | Clear all cookies (if any) | Cookies cleared |
| 7 | Reload asset URL | Still loads (200) via referer |

**Pass Criteria:** Assets load via referer even without cookies

---

### TC-07: Base Tag Injection Verification
**Priority:** P1 (High)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open Incognito → campaign link | Page loads |
| 2 | View Page Source | HTML source visible |
| 3 | Search for `<base` tag | **Tag present in head** |
| 4 | Verify base URL value | `/offers/123/` or `/lander/slug/` |
| 5 | Check no duplicate base tags | Only one base tag |

**Pass Criteria:** Base tag correctly injected

---

### TC-08: Mobile In-App WebView - Facebook
**Priority:** P1 (High)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Create test post on Facebook | Post ready |
| 2 | Include campaign link in post | Link attached |
| 3 | Open Facebook app → View post | Post visible |
| 4 | Click link in Facebook app | Opens in FB in-app browser |
| 5 | Verify page rendering | **Full styling, all assets** |

**Pass Criteria:** Works in FB in-app browser

---

### TC-09: Mobile In-App WebView - TikTok
**Priority:** P1 (High)

Same as TC-08, but using TikTok app and ads.

---

### TC-10: Mobile In-App WebView - Telegram
**Priority:** P1 (High)

Same as TC-08, but using Telegram app.

---

### TC-11: Normal Mode Regression Test
**Priority:** P0 (Critical)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open normal browser window | Normal mode |
| 2 | Navigate to campaign link | Page loads |
| 3 | Verify all assets load | All 200 status |
| 4 | Verify styles render correctly | **Fully styled** |
| 5 | Verify cookies are set | `orbitra_lo` or `orbitra_lp` present |

**Pass Criteria:** Fix doesn't break normal browsing

---

### TC-12: Preview Route Still Works
**Priority:** P1 (High)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Open `/lander/test-landing/` directly | Preview loads |
| 2 | Verify base tag injection | Tag present |
| 3 | Verify assets load | All 200 status |
| 4 | Open `/offers/123/` directly | Preview loads |
| 5 | Verify base tag injection | Tag present |
| 6 | Verify assets load | All 200 status |

**Pass Criteria:** Preview routes unaffected

---

### TC-13: Existing Base Tag Replacement
**Priority:** P2 (Medium)

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Upload landing with its own `<base href="https://old-domain.com/">` | Landing uploaded |
| 2 | Access via campaign link | Page loads |
| 3 | View Page Source | Only Orbitra's base tag present |
| 4 | Verify old base tag removed | **Old tag replaced** |

**Pass Criteria:** Conflicting base tags removed

---

## Test Results Template

Copy this for your test results:

```markdown
# Test Results - Incognito Fix

| TC ID | Browser/Platform | Status | Notes |
|-------|------------------|--------|-------|
| TC-01 | Chrome Incognito | ☐ Pass / ☐ Fail | |
| TC-02 | Chrome Incognito (Offer) | ☐ Pass / ☐ Fail | |
| TC-03 | Safari Private | ☐ Pass / ☐ Fail | |
| TC-04 | Safari Private (Offer) | ☐ Pass / ☐ Fail | |
| TC-05 | Firefox Private | ☐ Pass / ☐ Fail | |
| TC-06 | Referer Fallback | ☐ Pass / ☐ Fail | |
| TC-07 | Base Tag Injection | ☐ Pass / ☐ Fail | |
| TC-08 | FB In-App Browser | ☐ Pass / ☐ Fail | |
| TC-09 | TikTok In-App Browser | ☐ Pass / ☐ Fail | |
| TC-10 | Telegram In-App Browser | ☐ Pass / ☐ Fail | |
| TC-11 | Normal Mode Regression | ☐ Pass / ☐ Fail | |
| TC-12 | Preview Route | ☐ Pass / ☐ Fail | |
| TC-13 | Base Tag Replacement | ☐ Pass / ☐ Fail | |

## Overall Result: ☐ PASS / ☐ FAIL
```

---

## Quick Smoke Test (5 minutes)

For rapid verification during development:

1. **Chrome Incognito:** Open campaign link → Check if styled
2. **Network Tab:** Verify no 404s for CSS/JS/images
3. **Page Source:** Confirm `<base>` tag present
4. **Normal Mode:** Quick regression check

If all 4 pass → Fix is working! ✅

---

## Automated Test Snippet (Optional)

For automated testing, you can use this Playwright snippet:

```javascript
// test/incognito-landing.spec.js
import { test, expect } from '@playwright/test';

test('local landing loads correctly in incognito', async ({ context }) {
  // Create incognito context
  const incognitoContext = await browser.newContext({
    // Simulate incognito by rejecting all cookies
    storageState: { cookies: [], origins: [] }
  });
  
  const page = await incognitoContext.newPage();
  
  // Navigate to campaign link
  await page.goto('https://domain.com/abc123xy');
  
  // Wait for page load
  await page.waitForLoadState('networkidle');
  
  // Check for 404s in network requests
  const failedRequests = [];
  page.on('response', response => {
    if (response.status() === 404 && 
        response.url().match(/\.(css|js|png|jpg|gif|woff|woff2)$/i)) {
      failedRequests.push(response.url());
    }
  });
  
  // Verify base tag is present
  const baseTag = await page.locator('base').first();
  await expect(baseTag).toHaveAttribute('href', /\/(offers|lander)\//);
  
  // Verify styles are applied
  const computedStyle = await page.locator('body').evaluate(el => {
    return window.getComputedStyle(el).backgroundColor;
  });
  expect(computedStyle).not.toBe('rgba(0, 0, 0, 0)');
  
  // Assert no asset 404s
  expect(failedRequests).toHaveLength(0);
  
  await incognitoContext.close();
});
```

---

## Issue Escalation

If tests fail, collect:

1. **Browser and version:** Chrome 120, Safari 17, etc.
2. **Screenshot of network tab:** Show 404s
3. **Page source:** Show if `<base>` tag is present
4. **Console errors:** Any JavaScript errors
5. **Campaign configuration:** Landing/Offer setup

Post results with tag: `[Incognito Fix Test Results]`
