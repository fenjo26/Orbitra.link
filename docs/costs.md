# Costs: import from ad accounts

Orbitra pulls daily spend from ad accounts and distributes it across clicks, so ROI and profit in reports are calculated from real money, not from a manual cost per click.

Connections are in **⚙ → Integrations**, in the ad networks group: **Facebook Costs**, **TikTok Costs**, **Google Ads Costs**. The campaign editor's **Integrations** tab shows whether the campaign's clicks carry IDs for matching, and lets you add a connection.

## How spend lands on a click

1. The `aggregator_cron.php` cron (the installer adds it every 15 minutes) walks the connections whose update interval is due. Every pass re-reads the last 5 days, and the first pass reads 30: ad accounts revise spend retroactively, and the current day grows until midnight.
2. Records land in `cost_records` with updates keyed by `(connection_id, external_id)`. Re-syncing the same day is safe: spend is recalculated, not accumulated.
3. For each record, clicks of the same day are matched, from the most specific level to the broadest:
   1. `ad_id` (also `creative_id`, `creative`);
   2. `adset_id` / `adgroup_id` (also `adgroup`, `adgroupid`, `ad_group_id`);
   3. `campaign_id` (also `campaign`, `campaignid`, `ad_campaign_id`).
4. The day's spend is divided evenly among the matched clicks.
5. Spend is converted to the tracker's currency (rates from `open.er-api.com`, cached for 12 hours; custom rates via the `fx_rates_manual_json` setting, units per 1 USD).

**The main requirement:** the ad's link must pass the ad, group or campaign ID into the tracker. The easiest way is to pick a template traffic source (Facebook, TikTok, Google Ads) in the campaign — the template adds the needed macros itself.

Records that found no clicks retry matching on every sync: a late click, a fixed link or a new `adgroup_id` will pull the spend in.

### "Received N, matched 0"

The **Refresh costs** button on a connection shows the specific reason:

| Code | Meaning |
|---|---|
| `no_spend` | records arrived, but the spend in them is zero |
| `no_clicks` | there are no clicks in Orbitra on those days |
| `no_ids` | the clicks carry no ad, group or campaign ID — the link does not lead straight to the Orbitra campaign, or parameters get lost along the way |
| `macros` | clicks contain unresolved macros like `__CID__` — the source did not substitute values |
| `ids_differ` | IDs in the clicks and in the ad account report do not match; both are shown |

In the click card, the "Parameters" section shows all captured parameters, with ad network IDs first.

## Facebook / Meta

A Long-lived User Token or System User Token with `ads_read` + the ad account ID (`act_…`). You can specify the API version and a proxy. For details, see [Facebook / Meta](facebook.md).

## TikTok

Three ways to connect:

| Method | What you need |
|---|---|
| **Connect via TikTok MCP** | Only a TikTok login. No developer app or token needed — the tracker registers itself through TikTok's official MCP server. Imports spend; does not import pixels. **The panel must be opened over HTTPS** (`https://` on a bare IP works too) |
| **1-Click (Sign in with TikTok)** | App ID and App Secret of your own TikTok for Business app: the `ORBITRA_TIKTOK_APP_ID` / `ORBITRA_TIKTOK_APP_SECRET` environment variables, or the manual connection fields. Finds ad accounts and pixels, imports pixels into Pixel Vault |
| **Manual** | A Marketing API token from a free developer app (Developer Portal → Apps & Agents → Direct Advertiser, Ads Management and Reporting permissions) + Advertiser ID |

An Events Manager token does not work for spend import — it only sends conversion events. If the ad account list cannot be read through MCP, you can paste Advertiser IDs manually, comma-separated.

## Google Ads

- **1-Click (Sign in with Google)** — finds direct accounts and MCC child accounts, creating a connection for each one you select. Requires a Developer Token (from the MCC) and an OAuth Client ID + Secret (Google Cloud Console, type "Web application", redirect URI `https://your-domain/api.php?action=google_ads_oauth_callback`).
- **Manual** — Customer ID, Developer Token, Client ID, Client Secret, Refresh Token.

The 1-Click keys are picked up in order: the `ORBITRA_GOOGLE_CLIENT_ID`, `ORBITRA_GOOGLE_CLIENT_SECRET`, `ORBITRA_GOOGLE_DEVELOPER_TOKEN` environment variables → settings → fields of an already saved manual connection. The simplest way is to save a manual connection once — after that the 1-Click button works too. To check the server setup: `php check_google_ads_oauth.php`.

> Environment variables are read from the PHP-FPM environment (`env[ORBITRA_GOOGLE_CLIENT_ID] = …` in the pool config), not from the `.env` file — PHP code does not load it. `.env.example` is only a list of names.

Google and TikTok do not accept OAuth redirects to `http://`. On a bare IP without a certificate, use a manual connection.

Spend is matched by the ValueTrack parameters `{campaignid}` and `{creative}` — the Google Ads source template adds them.

## Dolphin and Fbtool

These services can only send spend to Keitaro via its Admin API. Orbitra accepts the same request at `POST /admin_api/v1/campaigns/<ID>/update_costs`. For details, see [Dolphin / Fbtool](dolphin-fbtool.md).

## Manual spend

The **Refresh costs** button in the campaign menu sets spend for a period manually (action `update_costs`).

## Revenue aggregator

**⚙ → Revenue Aggregator** — the inverse task: pull **revenue** from affiliate program accounts that do not send postbacks. Engines: ReferOn, Affilka, Generic API. For each connection you set the network, deal type, sync interval, and the name of the parameter where the affiliate program stores the Orbitra click ID. Revenues and sync logs are on the tabs of the same page.

## Agencies and the commission

Rented ad accounts cost more than the cabinet reports: the agency's percent is written **into every import** (`cost_records.fee_amount`; `clicks.cost` carries spend *with* the fee, `clicks.fee` the fee share) — so ROI/CPC are honest the moment spend lands, and changing the percent later never rewrites history.

- The *Agencies* tab of the aggregator section manages agencies (name, fee %, contacts, payment terms, notes) and compares them by spend, CPC, CPM and ROI over a period.
- Each cost connection picks its agency in the editor.
- Reports gain *Spend (cabinet)* and *Agency fee* columns; every grouping splits both.

## Ad names, statuses, platform metrics

The cost sync also collects what the platform knows about the ads themselves: names and statuses into `ad_entities` (statuses refreshed hourly, in the same cron pass), impressions and the platform's own click counts onto the spend rows. In campaign reports grouped by ad / adset / campaign you get names instead of bare ids, a status chip, and the platform-side columns: **Impressions, Clicks (platform), CPM, CTR (platform)** and **Click loss** = 1 − tracker visitors / platform clicks — the number that immediately exposes a cloaking or domain problem, which adset.pro does not have.

A top banner in the reports shows the share of revenue that cannot be tied to an ad id (an unreplaced `__CID__` / `{{ad.id}}` macro counts as missing) and jumps into the offending source's editor.

## Cron

```
*/15 * * * * php /var/www/orbitra/aggregator_cron.php >> /var/www/orbitra/var/logs/aggregator.log 2>&1
```

The interval is checked per connection, so a frequent cron is cheap; for intervals under 15 minutes, run it more often. Flags: `--force` (ignore the interval), `--connection=5`, `--days=30`.
