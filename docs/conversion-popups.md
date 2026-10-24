# Conversion popups

A popup with an offer button on your landings — **Settings → Plugins**. Off by default: an existing install must not suddenly show popups, so nothing appears until you flip the global switch. Each campaign can override it (campaign editor, **General** tab → *Conversion popup*: As in Settings → Plugins / On / Off).

## Where the popup works

| Where | How |
|---|---|
| Landing uploaded to Orbitra, or a "Show HTML" stream | Automatic |
| External site with `tracking.js` or `kclient.js` | Automatic — the script reads the popup config from the Click API response |
| External site with `kclient.php` | One line before `</body>`: `<?php echo $client->popupScript(); ?>` |
| A campaign that redirects straight to the offer | Not possible — there is no page to show a popup on |

Bots and safe-page visitors never see the popup at all: the server decides before a single byte of popup code ships. A redirect-only campaign keeps the override enabled on purpose — it can still carry `tracking.js` on an external page, where the popup does work; the editor only warns.

## Settings

- **Text & image vertical** — neutral (universal), nutra, gambling, betting, e-commerce, finance, crypto. Sets the wording and the default illustration.
- **Delay, frequency, scroll depth** — the popup waits N seconds (frequency: once per visitor per N hours, 0 = every page view), and can additionally require the visitor to scroll to a share of the page.
- **Wait for real activity** — two *different* actions, one of them pointer/touch: moving the mouse or tapping. Scroll or keyboard alone never qualifies — the same rule as the "possible bots" hint, so a bot scripted to scroll still never sees the popup.
- **Exit-intent (desktop)** — show when the cursor leaves through the top of the window.
- **Designs** — Card, Gift (bottom sheet), Alert. A random enabled design is picked per show; reports split conversions by the design × text variant, so you can see what actually converts. Animation, position (center / bottom) and the accent color are shared.
- **Language** — auto (the visitor's browser language, 11 built-in, anything else falls back to English) or one forced language; a whitelist can narrow the built-in set.
- **Amount override** — for bonus verticals the number is set in US dollars and converted to the visitor's currency with a "nice" rounding (250 → 200 € / 7 500 000 ₫); for discount verticals it is a percentage. Empty = the vertical's default. Custom headline / body / button can use the `{amount}`, `{currency}` and `{country}` macros, resolved from the click's geo.
- **Image** — by default the vertical's picture (the Gallery ships with a "Popup images" folder); any Gallery image can replace it.
- **Preview** — an approximate look with the first enabled design.

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

The report builder also gains the **Popup variant** dimension (design × text) — group by it to compare variants.
