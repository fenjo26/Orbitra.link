# Push & pop networks

Spend import from push and pop advertiser networks, plus **Zone Killer** — rules that find (and, if you allow them, block) the zones burning money. Everything lives in one place: the **Push/Pop networks** card in **⚙ → Integrations**, in the ad networks group next to Facebook / TikTok / Google costs.

## Networks

| Network | Spend level | API key |
|---|---|---|
| **AdMaven** | campaign × zone | Advertiser panel → API section → API token |
| **PropellerAds** | campaign × zone | SSP → Profile → API token |
| **Kadam** | campaign × zone | Advertiser account → API section → API token |
| **Galaxion** | campaign × zone | E-mail + password of the account (the tracker gets a token itself), or a ready API token |
| **HilltopAds** | campaign only | My Account → API tab → API key |
| **Adsterra** | campaign only | Settings → API tab → Generate new token (only the newest token works) |
| **Clickadu** | campaign only | Advertiser account → API section → API key |

Networks marked *campaign only* do have a zone breakdown in their API, but its rows carry no campaign id — a zone there cannot be tied to the campaign whose clicks it bought. Their spend is spread over the campaign's clicks, and the connection card warns about this before you connect.

## Connecting

Pick a network tile, paste the key, press **Check connection** — it shows the account's real balance — then save. Each connection has its own update interval; the buttons in the list: **Update spend** (now), pause, edit, delete. Already imported spend stays in the reports after a delete.

Sync runs in the same `aggregator_cron.php` pass as the other cost connections (see [Costs](costs.md#cron)): every pass re-reads the last 5 days, the first pass 30. Re-syncing a day is safe — spend is recalculated, not accumulated.

## How spend lands on a click

**The main requirement** is the same as for Facebook or TikTok: the campaign must use the network's traffic source template, so the link passes the network's campaign id (into `ad_campaign_id`) and the zone id (the Keitaro source templates write it into `source`, the built-in PropellerAds template into `zone`). Without the zone macro spend still lands — but per campaign only, never split by zone.

Matching, from the most specific level to the broadest:

1. **campaign × zone** — a zone row is attributed only to clicks of the same day that carry **both** the zone id and the network campaign id. A bare zone id is not enough: zone ids are not unique across networks.
2. **campaign** — a zone row whose zone matched no clicks sums onto the campaign's clicks without a zone (it never climbs onto clicks already priced by their own zone rows — several unmatched zones add up instead of overwriting each other).

The click parameter keys that count as "the zone": `zone`, `zone_id`, `zoneid`, `sub_source_id`.

If a sync answers "records received, but none could be attached to clicks", the campaign most likely does not use this network's source template — the link brings no campaign id to match on. The "Received N, matched 0" diagnostics from [Costs](costs.md) apply here too.

## Zone Killer

A card on the campaign editor's **General** tab (the campaign must be saved first). A rule binds the campaign to one push/pop cost connection and decides which zones are candidates:

- **window** — how many days back to judge (spend and conversions are summed per zone over the window);
- **min spend** — a zone that spent less is not worth touching;
- **zero conversions** and/or **max ROI** — the kill conditions;
- **mode** — `dry_run` (default) or `auto` with a per-run block cap.

Candidates are computed from `clicks.cost` / `clicks.revenue` per zone — the same distributed numbers the reports show, with the same zone keys the cost import matched on, so a rule can never judge a zone the importer priced differently.

- **Dry-run** lists the candidates; you select and press **Block selected**.
- **Auto** lets `zone_rules_cron.php` execute the candidates itself (capped per run) and sends a Telegram notification about what it blocked.

Blocking goes through the network's write API for **AdMaven, HilltopAds, PropellerAds, Kadam and Galaxion**. **Adsterra and Clickadu** have no usable zone write API: their rules stay dry-run, and the card copies the selected zone ids so you can paste them into the network's blacklist by hand.

Every block and unblock — manual or automatic — lands in an append-only journal with the metrics that stood behind the decision; the current blocked set is a zone whose latest successful action is "block". The journal is rendered under the rule.

> For *campaign only* networks (HilltopAds, Adsterra, Clickadu) Zone Killer judges zones approximately — by their share of the campaign's clicks, because the API gives no per-zone spend. Keep their rules in dry-run and check the list before blocking.

## Cron

```
*/15 * * * * php /var/www/orbitra/aggregator_cron.php >> /var/www/orbitra/var/logs/aggregator.log 2>&1
*/30 * * * * php /var/www/orbitra/zone_rules_cron.php  >> /var/www/orbitra/var/logs/zone_rules.log 2>&1
```

The first line is the cron the installer adds for cost sync. The second one — the Zone Killer executor — you add yourself, and only if you use `auto` rules; a pass is cheap (a rule with no candidates is a single GROUP BY over the campaign's clicks). Flags: `--campaign=12` (one campaign), `--quiet`.
