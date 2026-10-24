# Campaigns and Streams

A campaign is the URL traffic arrives on plus a set of **streams** that decide where each visitor goes. The **Campaigns** section is in the top menu.

## The campaign editor

The editor opens full-screen. On a wide screen (1280px and up) it is split into two panels: campaign settings on the left, streams on the right; on a narrower screen it becomes a single column.

### Settings tabs

| Tab | What is there |
|---|---|
| **General** | Name, alias (6 characters, generated automatically), campaign URL, domain, group, traffic source, state, test link; lower on the tab — the [conversion popup](conversion-popups.md) override, the [social link preview](#social-link-preview) card and the [Zone Killer](push-pop-networks.md#zone-killer) rule |
| **Finance** | Cost model, uniqueness, stream rotation type |
| **Parameters** | Which link parameters the tracker saves into the click (`keyword`, `cost`, `external_id`, `creative_id`, `ad_campaign_id`, `source`, `sub_id_1` … `sub_id_30`) and which source parameters to take them from |
| **Integrations** | Pixels (Facebook / TikTok etc., see [Facebook / Meta](facebook.md)) and connecting cost synchronization ([Costs](costs.md)) |
| **Tracking** | Integration code generator: the tracking script, `kclient.js`, `kclient.php`, the pixel, banners (script / iframe), the WordPress plugin, widgets (a countdown timer, the "back button", exit-intent). Available after the first save — the code contains the campaign token |
| **S2S Postbacks** | Outgoing postbacks to the traffic source, a postback tester, re-sending missed conversions — see [Postbacks and conversions](postbacks.md) |
| **Notes** | Free-form text |

### Cost models

CPC, CPV, CPuC (per unique click), CPM, CPA, CPS, RevShare. Manual cost is overwritten by cost import if it is connected ([Costs](costs.md)).

### Uniqueness

- **Method:** IP, IP + User-Agent, or Cookies.
- **Period:** in hours.
- Uniqueness is computed in three scopes — per campaign, per stream, and globally; all three are visible in reports.

### Campaign state

A stopped campaign (`paused` / `disabled`) responds with `503`, an archived one with `404`. This applies to the alias URL, `click.php`, and the Click API.

## Social link preview

A card on the editor's **General** tab. When enabled, a social or messenger preview bot hitting the campaign URL — `facebookexternalhit`, `telegrambot`, `whatsapp`, `twitterbot` and the other link-preview fetchers — gets a minimal `og:*` card with your title, description and image instead of the click flow. Every human is routed exactly as always: no delay, no different page.

Search crawlers (googlebot & co) deliberately keep seeing the real campaign answer — this is only the messengers' preview fetchers; full bot handling is [cloaking](cloaking.md)'s job. The one-time disclaimer records that platform rules on custom previews are the operator's responsibility.

## Streams

A stream is a routing rule: filters (who it matches) + a schema (what to show).

### Types and order

1. **Intercepting** — checked first. Suited for bots, white pages, exceptions.
2. **Regular** — the main traffic. Selected by position or by weight (the rotation type on the "Finance" tab).
3. **Default** — when nothing above matched.

Saving a campaign does not recreate its streams: each stream keeps a stable ID, so click history does not scatter across old numbers. New streams are named "Stream 1", "Stream 2" … The chevron on a card collapses the stream; the state is remembered per campaign.

Each stream card shows visits, uniques, bots, and the share of campaign traffic for today / yesterday / 7 / 30 days. This makes it easy to see how much traffic went to the white stream and how much to the money stream.

### Filters

| Filter | How it works |
|---|---|
| **Country** | A list of countries or a geo profile (EU, CIS…). If the country cannot be determined and "include" mode is on, the filter does not let the visitor through — but only when a geo database is installed; without one the filter abstains |
| **Device** | Desktop / mobile / tablets |
| **Operating system**, **Browser** | Values are chosen from the list the tracker actually recognizes, including in-app browsers (TikTok, Facebook, Instagram, Telegram, WebView). You can type your own value and press Enter |
| **Browser language** | `en` — any English, `en-IN` — English (India) only. The entire `Accept-Language` is considered, not just the first language |
| **Bot** | The bot detector at maximum sensitivity (the same one used in cloaking) |
| **IP address** | Addresses and CIDR, IPv4 and IPv6 |
| **Provider (ISP)** | Compared against the visitor's ISP and ASN (requires the ASN database) |
| **Connection type** | From geo-database data |
| **Referer**, **Keyword** | Compared against the click's value |
| **URL parameter** | Any link parameter by name. Case-insensitive exact match, `*` — any characters, "Empty" — the parameter is missing or empty |
| **Day of week**, **Time** | By the visitor's local time (time zone from the geo database; without it, server time) |

A stream's filters are combined with **AND** (all must match) or **OR** (one is enough). A filter that cannot reach a decision (no geo database, no ISP data) does not vote: under "AND" it does not block the stream, under "OR" it does not open it.

**A trick against TikTok moderation** via "URL parameter": add `xxx=__PLACEMENT__` to the campaign URL; on an intercepting stream with a white page, set a "URL parameter" filter on `xxx` with the values `__PLACEMENT__` and "Empty". In a real click the network substitutes the macro's value, while a moderator opening the bare link arrives with `__PLACEMENT__` or without the parameter.

### Schemas

| Schema | What happens |
|---|---|
| **Direct redirect to offer** | A redirect to an offer from the catalog (several offers rotated by weight) or to a direct URL. Every such transition is a click |
| **Landing + Offer** | Shows a landing from which the visitor goes on to the offer. The offer is optional |
| **Action** | Do nothing, 404, show text, show HTML, send to another campaign (the click is written to both) |
| **Cloaking** | Splits traffic between a safe page and a money page — see [Cloaking](cloaking.md) |

**Offer selection** in the "Landing + Offer" schema:

- **After the click** (the default for new streams) — the offer is chosen when the visitor actually leaves the landing. LP clicks, LP CTR, and time to offer are honest.
- **Before the click** — every landing view counts as a click to the offer (legacy behavior, LP CTR around 100%).

How to build the offer link on the landing itself (`{offer}`, `/?_lp=1`, passing `sub_id_N` to the offer) is covered in [Landing pages](landing-pages.md).

### Offer redirect types

Set on the offer. Macros and the SSRF check are the same for all types.

| Type | Behavior |
|---|---|
| HTTP redirect | 302, the default |
| JS redirect | An HTML page that navigates via JavaScript |
| Meta Refresh | A `<meta http-equiv="refresh">` tag |
| Iframe | The offer in a full-page iframe on your domain |
| Form Submit | An auto-submitted POST form: tracking data in the request body |
| curl proxy | The offer page served through your server with a `<base>` tag |

### Rotation with auto-optimization

The **Auto** switch next to a stream's offer or landing list enables automatic weight recalculation by a metric: confirmed sales, CR, EPV, EPC, or ROI (ROI requires costs). Configurable: the window in days, the minimum number of sales before taking part in the split, the lower and upper bounds of the share, and the recalculation interval. Recalculation is done by the `rotation_optimiser_cron.php` cron; the decision log is visible in the stream card. While "Auto" is on, manual weight entry is locked.

### Other stream settings

- **Collect clicks** — when disabled, the visitor still gets a direction, but the click is not written (there is nothing to attach a conversion to).

**Bot Challenge** is a campaign setting, not a stream one: a human check (reCAPTCHA v2/v3 or your own code) before a stream is chosen.

## Test link

The **Test link** row on the "General" tab produces a campaign URL signed with `?_t=<id>:<hmac>`. Real routing works along it — streams, filters, rotation, landings, offers — but the click is not recorded, so statistics stay clean.

- `&_geo=DE` — override the visitor's country: test geo streams without a VPN.
- `&_dbg=1` — instead of a redirect, a trace page: every stream with its type, schema, and each filter's vote (matched / did not match / abstained and why), the selected stream, and the final URL with macros substituted.

The signature is derived from the installation's postback key, so it survives a campaign token change; changing the postback key revokes all test links. An invalid or foreign signature turns the request into an ordinary click.

## Traffic simulation

**⚙ → Traffic simulation**: set an IP, User-Agent, country, device, language, and URL parameters — the panel will show which stream fired and why, without recording anything.

## Click API and campaign tokens

Every campaign has a token (32 hex characters). Integration scripts (`click.php`, `kclient.php`, `tracking.js`, Click API v3 `/click_api/v3`) address the campaign by token, not by ID. Tokens are transferred on import from Keitaro, so already-installed scripts keep working. The token can be reissued in the editor. For details on the PHP client, see [Tracking Client (PHP)](tracking-client-php.md).

## Duplicate protection

- A repeat click from the same visitor (campaign + IP + User-Agent) within 2 seconds is not written as a second row; the redirect receives the ID of the already-recorded click.
- Browser prefetch/prerender requests are served but not written.
- If the database is busy (`database is locked`), the click goes to `var/spool/clicks.log`, and the `cli/click_spool_cron.php` cron writes it within a minute.

## Bulk operations

Campaign copying, bulk deletion, import, moving a group of campaigns to another domain (`bulk_set_campaign_domain`, also in [MCP](mcp.md)), renaming groups right in the list.
