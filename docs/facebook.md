# Facebook / Meta integration

Orbitra has two independent Facebook integrations. They solve opposite halves of
the same loop and are configured in different places:

| | What it does | Direction | Where |
|---|---|---|---|
| **Facebook Costs** | Imports ad spend from Meta into the tracker | Meta → Orbitra | Integrations → **Facebook Costs** |
| **Facebook Conversions API** | Sends conversions from the tracker to Meta | Orbitra → Meta | Integrations → **Facebook Conversions** |

Both are also reachable from where the underlying records live: cost connections
appear on the **Aggregators** page (they are `aggregator_connections` rows with
engine `facebook`), and a campaign's pixel can be edited from the campaign's own
**Integrations** tab. Same data either way — the Integrations page is the entry
point, the other two are the context-specific views.

Neither depends on the other. Cost import gives you real ROI; the Conversions API
gives Meta's optimiser the events the browser pixel loses.

---

## 1. Campaign setup (required by both)

Both integrations key off the parameters the ad carries into the tracker. Set the
campaign up once and both work.

Create the campaign, pick the **Facebook** traffic-source template, and use the
generated URL parameters in the ad's *URL parameters* field:

```
ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}&ad_name={{ad.name}}&adset_name={{adset.name}}&campaign_name={{campaign.name}}&site={{site_source_name}}
```

Rules that matter:

- **`{{adset.id}}` is not optional.** It is the fallback cost import uses when an
  ad ID is not on the click — for example when creatives rotate under Advantage+.
- **Do not add `fbclid={fbclid}`.** Meta appends `fbclid` itself. Declaring it as
  a macro produces an empty value that overwrites the real one, and the
  Conversions API then has nothing to match on.
- If traffic passes through an app or a prelander that repacks the macros, forward
  them as `sub_id_N` and tell the cost connection where they landed — see
  [Traffic through an app](#traffic-through-an-app).

Orbitra captures these on every click through `core/ClickParams.php`, which is
shared by the redirect path (`index.php`) and the Click API (`click.php`). It also
captures `fbclid` and the `_fbp` / `_fbc` cookies automatically — you never declare
those yourself.

---

## 2. Facebook Costs

### Getting an access token

A long-lived **User Access Token** or a **System User Token** with `ads_read`
works. The System User token is the one to use in production — it does not expire
with a person's session.

1. <https://business.facebook.com/settings/system-users> → add a system user
2. Assign the ad account to it with **View performance** (or full control)
3. **Generate token** → select your app → tick `ads_read` → copy it

The token is shown once. Store it before closing the dialog.

### Ad account ID

From [Ads Manager](https://www.facebook.com/adsmanager/manage/accounts) — the
`act_1234567890` value. Orbitra accepts it with or without the `act_` prefix.

### Creating the connection

**Integrations → Facebook Costs → Add account**:

| Field | Notes |
|---|---|
| Access Token | long-lived or system user token |
| Ad Account ID | `act_1234567890` |
| Facebook API version | defaults to the newest supported; older accounts can pin an older one |
| Proxy | optional, `scheme://user:pass@host:port`, also accepts `socks5://` |
| Update spend every | how often the cron re-reads spend |
| Advanced: parameter mapping | only for traffic through an app — see [below](#traffic-through-an-app) |

Each account row shows its status and next update, and carries **Update spend**
(one manual sync now), **Pause/Resume**, **Clone** and **Delete**.

**Test connection** reads the ad account itself (name, currency, timezone,
status), not an insights report — an empty report is a valid answer and would
otherwise hide a dead token.

### What gets synced, and when

- The scheduled sync (`aggregator_cron.php`) re-reads the **last 5 days** on every
  run, and **30 days** on a connection's first sync.
- Ad platforms restate the past: spend is attributed late, and the current day is
  a running total that grows all day. Records are therefore *upserted* on
  `(connection_id, external_id)` and attribution is recomputed, never accumulated
  — re-syncing the same day is safe and idempotent.
- Spend is fetched at **ad level** with `time_increment=1`, so every row is one
  ad on one day.

Cron:

```cron
0 */2 * * * php /var/www/orbitra/aggregator_cron.php >> /var/log/orbitra_aggregator.log 2>&1
```

Useful flags: `--force` (ignore the interval), `--connection=5`, `--days=30`.

### How spend reaches a click

For each imported record Orbitra resolves the clicks it belongs to, most specific
level first:

1. `ad_id` (also `creative_id`, `creative`)
2. `adset_id` (also `adgroup`, `adgroupid`, `ad_group_id`)
3. `campaign_id` (also `campaign`, `campaignid`, `ad_campaign_id`)

The day's spend is then split evenly across that day's matching clicks — the same
flat-CPC model manual cost entry uses.

### Currency

Meta reports in the **ad account's** currency. Orbitra converts to the tracker
currency (Settings → `currency`) before storing, so cost and revenue are always in
the same unit. Rates are fetched from `open.er-api.com` and cached for 12 hours.

To pin rates yourself (air-gapped install, or you want your bank's rate), set the
`fx_rates_manual_json` setting — values are units per 1 USD:

```json
{"EUR": 0.92, "RUB": 92.5}
```

Manual rates always win over the fetched table. The platform's original amount and
currency are kept in `cost_records.raw_json` under `_orbitra`, so a wrong rate can
be reconstructed after the fact.

### Traffic through an app

If the macros arrive repacked — Facebook → app → Orbitra — forward them as sub IDs:

1. In Facebook: `sub_id_3={{adset.id}}`
2. From the app to Orbitra: `sub_id_3={sub_id_3}`
3. In the account's **Advanced: parameter mapping**, set *Adset ID arrives in
   parameter* to `sub_id_3`

`ad_id_param` and `campaign_id_param` work the same way. Overrides are tried first
and the standard keys stay as a fallback, so a partial mapping degrades instead of
matching nothing.

### Troubleshooting

**"Fetched N, matched 0"** — spend arrived but nothing could be attached to a
click. The cron log says this explicitly. Almost always the campaign URL is missing
`ad_id` / `adset_id` / `campaign_id`. Check a recent click's parameters in
Reports → Clicks.

**Sync reports an error with a Meta message** — the message is Meta's own, verbatim.
Common ones:

- *code 190* — token expired or revoked. Reissue it.
- *code 100, "(#100) ... field"* — the API version no longer supports a field.
  Move the connection to a newer version.
- *code 17 / 613* — rate limited. Increase the sync interval.
- *empty or blocked responses* — Meta is geo/IP-filtering the tracker's server.
  Set a proxy on the connection.

**Day boundaries look shifted** — Meta reports days in the **ad account's**
timezone, the tracker stores clicks in server time. If the two differ, spend near
midnight lands on the neighbouring day. Align the ad account timezone with the
server, or accept the edge.

---

## 3. Facebook Conversions API

The browser pixel is blocked for a meaningful share of traffic — ad blockers, ITP,
iOS. Orbitra knows about every conversion the moment the affiliate network posts
back, and it knows the click's IP, user agent, `fbclid` and `_fbp`. Sending the
event server-side recovers what the pixel loses.

### Getting a Conversions API token

Events Manager → your pixel → **Settings** → *Conversions API* → **Generate access
token**. Copy it immediately; it is shown once.

The Pixel ID is on the same page (Events Manager → Data sources).

### Setting it up

**Integrations → Facebook Conversions → Add account** (or, from inside a campaign,
its own **Integrations** tab → **Facebook Pixel** — the same record):

| Field | Notes |
|---|---|
| Campaign | which campaign's conversions this pixel receives |
| Pixel ID | from Events Manager |
| Conversions API token | **this is what turns server-side sending on** |
| Status → Meta event | which tracker status produces which Meta event |
| Test event code | optional, from Events Manager → *Test events* |
| Proxy | optional, same format as the cost connection |

A pixel row **without** a token stays browser-only — the list marks it *Browser
pixel only* rather than showing it as a working integration. Nothing is sent
server-side until a token is present.

Default mapping:

| Tracker status | Meta event |
|---|---|
| `lead` | `Lead` |
| `sale` | `Purchase` |
| `deposit` | `Purchase` |
| `registration` | `CompleteRegistration` |
| `rejected` | *not sent* |
| `trash` | *not sent* |

`rejected` and `trash` are deliberately unmapped: feeding a rejected lead back as a
conversion teaches the optimiser to buy more of exactly the wrong traffic.

**Send test event** posts one event immediately, using your most recent real click
with an `fbclid` when there is one. Check it under Events Manager → *Test events*.

### What gets sent

```
event_name          from the mapping
event_time          when the postback arrived
event_id            <click_id>_<status>[_<tid>]   ← deduplicates against the browser pixel
action_source       website
event_source_url    the click's referer, when it is a URL
user_data
  client_ip_address the click's IP            (unhashed — Meta requires this)
  client_user_agent the click's user agent    (unhashed — Meta requires this)
  fbc               from _fbc, or rebuilt from fbclid
  fbp               from the _fbp cookie
  em, ph, fn, ln    SHA-256, from the postback
  ct, st, zp, country SHA-256, from the click's geo
custom_data
  value, currency   from the postback payout
```

Everything except IP and user agent is hashed before it leaves the server.

To send PII, include it in the incoming postback:

```
https://tracker.example/<postback_key>/postback?subid=3sh8so4er3&status=sale&payout=2.61&em=example@gmail.com&fn=John&ln=Snow&ph=+71234567890
```

Values are normalised the way Meta requires (email lowercased, phone reduced to
digits) *before* hashing — an unnormalised hash simply never matches.

### Delivery and retries

Events are queued in `s2s_postbacks_log` and delivered by
`postback_queue_cron.php` with exponential backoff, the same worker that delivers
outbound S2S postbacks. A slow response from Meta never delays the answer to the
affiliate network's postback — which matters, because a network that times out
will retry and double-count the conversion.

```cron
* * * * * php /var/www/orbitra/postback_queue_cron.php >> /var/log/orbitra_postback_queue.log 2>&1
```

Delivery status, HTTP code and Meta's error text are visible in the S2S logs UI.

### Troubleshooting

**Nothing arrives in Events Manager** — check, in order: the click has `fbclid`
(Reports → Clicks); `fbclid={fbclid}` is *not* in the ad's URL parameters; the
status is mapped to an event; the queue worker cron is running; the S2S log shows
the attempt.

**"Invalid parameter" from Meta** — usually the pixel ID and token belong to
different assets, or the token was generated for a pixel you no longer own.

**Events arrive but match quality is low** — the `_fbp` cookie is missing. It only
exists if the browser pixel also fires on the landing page; server-side events
alone carry `fbc` but not `fbp`.

---

## Files

| Path | Role |
|---|---|
| `aggregator_engines/FacebookAdsEngine.php` | Marketing API insights reader |
| `core/CostImporter.php` | upsert + attribution + currency |
| `core/CurrencyRates.php` | FX table, cache, manual overrides |
| `core/ClickParams.php` | what gets captured on a click |
| `core/FacebookConversions.php` | CAPI payload, hashing, queueing |
| `postback.php` | enqueues CAPI events on conversion |
| `postback_queue_cron.php` | delivers them with retries |
| `tests/facebook_integration_test.php` | end-to-end coverage, no network |
