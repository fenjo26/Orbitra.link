# Orbitra Ads Manager Overlay

Manifest V3 extension for Google Chrome and Chromium-based antidetect browsers.

1. Open Orbitra → Integrations → Chrome & Antidetect Extension.
2. Download and unzip the extension.
3. Load this folder as an unpacked extension, or upload the ZIP where supported.
4. Open the extension popup and paste the Tracker API URL and dedicated read API key shown by Orbitra.
5. Open Facebook Ads Manager. Orbitra metrics appear beside detected campaign, ad set, and ad rows and refresh every 30 seconds by default.

An always-on Orbitra widget sits in the top-right corner of Ads Manager. It shows the live tracker connection status and opens the account-level detail view — every campaign detected on the current page fused into one report. Rows that are still drafts (no numeric Meta id yet) get a muted "Draft · awaiting traffic" badge so you can see the extension is watching the table.

Click an Orbitra metric badge to open the three-day detail view with daily history, landing/offer performance, and Pixel/CAPI delivery accuracy.

The extension requests access to Facebook Ads Manager plus the single tracker host entered in its popup. It never needs a write API key.
