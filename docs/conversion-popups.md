# Conversion popups

A popup with an offer button on your landings. Every campaign owns its popup: it is switched on and tuned on the editor's **Popup** tab (a dot next to the tab name glows while it is on) and saves with the campaign's usual **Save** button. A copied campaign carries its popup along. **Settings → Plugins** is the overview page: which campaigns run a popup and how it is doing (template, shows, clicks, CTR over 7 days, a *Configure* button that opens the campaign straight at the Popup tab), plus the **template for new popups** — the starting point a campaign's popup is pre-filled from the first time you enable it.

> Upgrading from a pre-v1.8.0 install: the old global switch is gone. If it was on, every campaign that relied on it has its popup switched on after the update; otherwise popups stay off everywhere.

## Where the popup works

| Where | How |
|---|---|
| Landing uploaded to Orbitra, or a "Show HTML" stream | Automatic |
| External site with `tracking.js` or `kclient.js` | Automatic — the script reads the popup config from the Click API response |
| External site with `kclient.php` | Automatic — a current `kclient.php` appends the popup tag itself when the campaign has the popup on. Sites still running an **old copy** of the file need it updated once; the manual `<?php echo $client->popupScript(); ?>` line still works too |
| A campaign that redirects straight to the offer | Not possible — there is no page to show a popup on |

Bots and safe-page visitors never see the popup at all: the server decides before a single byte of popup code ships. A redirect-only campaign can still have the popup on — an external page of yours with `tracking.js` is a valid stage; the tab only explains where the popup can appear.

## The Popup tab

- A checkbox — *Show a popup to this campaign's visitors* — and under it a plain-language readiness line: "Ready: the popup will appear on this campaign's landing", or the reason it has nowhere to appear, with an *Open Integrations* button.
- A **live preview** of the popup as you tune it.
- Four steps:
  1. **Template** — one of seven vertical cards (Universal, Nutra, Gambling, Betting, E-commerce, Finance, Crypto): sets the wording, the default amount and the illustration.
  2. **Text & amount** — your own headline, body and button label; the `{amount}`, `{currency}` and `{country}` macros resolve from the click's geo. The amount is set in US dollars for bonus verticals and converted to the visitor's currency with a "nice" rounding (250 → 200 € / 7 500 000 ₫); discount verticals treat it as a percentage.
  3. **Image** — the vertical's picture by default, or any Gallery image.
  4. **When to show** — delay, once-per-N-hours frequency, scroll-depth and exit-intent triggers, and the activity gate: two *different* actions, one of them pointer/touch (mouse move or tap). Scroll or keyboard alone never qualifies — a bot scripted to scroll still never sees the popup.
- **Advanced** (collapsed): designs — Card, Gift (bottom sheet), Alert, a random enabled one per show; animation, position, accent color, the language mode (auto / forced / whitelist of the 11 built-in).
- **Reset to template** — undo everything the template didn't ask for.

## The button is a real funnel step

The popup button is the tracker's signed landing → offer transition — the same one a CTA on the landing uses. A press continues through the tracker (`offer_at`, conversions, the popup CTR metric all land on the same click) and carries `sub_id_30=popup`, so the network side can tell popup traffic apart.

## Statistics

Available from day one in the report customizer:

| Metric | Definition |
|---|---|
| **Popup shown** | Visitors the popup actually rendered for — after the delay/activity gates, bots excluded |
| **Popup CTR** | Popup clicks ÷ Popup shown × 100% |
| **Popup clicks** | Visitors who pressed the offer button |
| **Popup closed** | Visitors who dismissed the popup without clicking |
| **Popup conversions** | Conversions on clicks that came through the popup button |

The report builder also gains the **Popup variant** dimension (design × text) — group by it to compare variants. The Settings → Plugins list shows the short version (shows, clicks, CTR) per campaign.
