# Cloaking

The **Cloaking** stream schema splits traffic into two branches: suspicious visitors (bots, moderators, data centers, VPNs) see the **safe page**, real people the **money page**. One module (`core/CloakDetector.php`) makes the decision for all entry points: the campaign URL (`index.php`), `click.php`, and Click API v3.

A detailed technical write-up in English: [How the Orbitra Cloaker Works](cloak-how-it-works.md).

## Stream setup

### Safe page

Built-in HTML, an external URL, a local landing, or a local offer. Landings and offers in the list are grouped by group, with a search.

### Money page

Works like the "Landing + Offer" schema, including "before the click / after the click" offer selection.

### Clean visitors to the next stream

The **"Send clean visitors to the next stream"** checkbox turns the cloak into an entry filter. Suspicious visitors get the safe page, while clean ones continue down the stream list — geo, devices, and languages are split up by regular streams. This stream's money page is not used in this mode. Put such a stream first or make it intercepting so that every visitor passes the check.

### Detection layers

| Layer | Source | Does not work without |
|---|---|---|
| **Data centers / hosting** | An ASN blocklist (`core/data/asn_blocklist.json`), published cloud and crawler ranges, hosting keywords in the ISP name | The ASN database (GeoLite2-ASN) |
| **VPN / proxy** | IP2Proxy PX12 (VPN, TOR, residential, threat, fraud score ≥ 80), a VPN-ASN list | IP2Proxy PX12 / the ASN database |
| **Bot blacklists** | IPs and User-Agent signatures from **Settings → Bots** | — |
| **User-Agent heuristics** | Crawlers and tools (`facebookexternalhit`, `meta-externalagent`, `curl`, `python`, headless…), an empty User-Agent, a missing `Accept-Language` | — |

If a required database is missing, the campaign editor shows a warning right in the cloak card.

### Sensitivity

Signals are either **hard** (an explicit hit in a blocklist or reputation database) or **soft** (individually noisy heuristics).

| Level | Safe page when |
|---|---|
| Low | only a hard signal |
| Medium (default) | a hard signal, or two soft ones |
| High | any single signal |

### Targeting filters

Applied after the passive layers; a mismatch means the safe page (`TARGETING_SAFE`):

- **Countries** — allow or block a list. If the country cannot be determined, the reason is recorded as `geo_unknown` rather than `geo_country`. Without a geo database every visitor is `Unknown`, and the country filter will send 100% of traffic to the safe page; the editor warns about this.
- **Device types** — only the selected ones, or all except the selected ones.
- **Block bots and data-center providers** — the visitor's ISP and ASN compared against a list: the stream's local list or, if it is empty, the global one from **Settings → Bots**.

### JavaScript verification

Off by default. The visitor first receives the safe page and moves to the money page via a one-time signed link (TTL 5 minutes) only after executing JS. `navigator.webdriver = true` stays on the safe page. So does anything that does not execute JS. The price is one extra hop for every live visitor.

With the [browser check](browser-check.md) on (Settings → Bots), the same step also collects browser signals: in Soft or Strict mode a virtual machine, emulator or spoofed phone gets the safe page here, with a `bc_*` reason. The stream's *Browser check* field can turn it off for this stream only.

### Recording and reports

| Setting | Default | What it does |
|---|---|---|
| **Record Safe Page clicks** | on | Safe-page hits are written with `is_safe_page = 1`. Turn it off and they will not reach the database |
| **Exclude Safe Page clicks from reports** | on | Hits stay in the click log but do not count as visits or anything derived from them (uniques, bots, empty referers, cost, CPV). They never appear in "Clicks" |

Even when hits are not written, the suppressed-hit counter (`cloak_suppressed_stats`) counts them per campaign, stream, day, and verdict — "zero clicks" will not hide the real traffic.

## Global settings: Settings → Bots

- **Bot IP addresses** — IPs and CIDR, IPv4 and IPv6. Every entry has a source (manual or from a feed); cleanup can delete entries of one source only.
- **Signatures (User-Agent)** — substrings.
- **Global Bot ISP blacklist** — one provider per line. Too-short and too-generic strings (`Inc`, `LLC`) are ignored: they would match almost every ISP.
- **Data-center and crawler IP ranges** — published ranges of AWS, Google Cloud and Googlebot, Bing, Azure, Oracle, DigitalOcean, Facebook, Telegram, OpenAI, Cloudflare, Apple Private Relay, and others from [lord-alfred/ipranges](https://github.com/lord-alfred/ipranges). Updated daily by the `ipranges_cron.php` cron (manually via the "Update now" button or `php ipranges_cron.php --force`), stored in `var/ipranges/`. Until the lists are downloaded, the layer is inactive.
- **Browser check** — Off / Observe only / Soft / Strict: bots, VMs and emulators caught by contradictions in the browser. → [Browser check](browser-check.md)
- **Treat VPN ASN as a bot signal** — on by default. Turn it off if your audience widely sits behind a commercial VPN.

## Diagnostics

- **The cloak card in the editor** — how many hits went to money and to safe over the selected period, the top reasons, and a link into the click log with the same window. If almost all traffic goes to the safe page, the card warns.
- **The click log** — the "Route" and "Reason" columns appear automatically when there are cloak clicks on the page, with a filter by route and reason. The click card shows the verdict and reasons, but only for clicks the cloak actually judged.
- **The test link with `_dbg=1`** — a routing trace without recording a click; `_geo=XX` overrides the country ([Campaigns and streams](campaigns.md#test-link)).
- **The PHP log** — lines like `Orbitra cloak [campaign=12 stream=34]: stage=PASSIVE_SAFE ... reasons=[crawler_or_tool_ua]`.

### Verdicts and reasons

Verdicts: `money`, `passive_safe`, `targeting_safe`, `js_safe`. Reasons are written to `clicks.cloak_reasons`; the main ones:

| Code | Meaning |
|---|---|
| `geo_country` / `geo_unknown` | country not in the list / country could not be determined |
| `device_type` | device type not allowed |
| `bot_isp`, `isp_blocklist` | ISP in the bot and data-center list |
| `datacenter_asn`, `asn_datacenter` | data-center or hosting ASN |
| `vpn_proxy_asn` | VPN or proxy provider ASN |
| `iprange_datacenter` | IP in a published cloud or crawler range |
| `hosting_isp` | hosting keyword in the ISP name |
| `ip2proxy_*` | an IP2Proxy signal: `vpn_proxy`, `datacenter`, `residential`, `tor`, `bot`, `threat`, `high_fraud` |
| `bot_blocklist` | IP in the bot list |
| `crawler_or_tool_ua`, `suspicious_ua`, `no_user_agent`, `missing_accept_language` | User-Agent and header heuristics |
| `webdriver`, `js_missing`, `js_fingerprint` | JavaScript verification |

## Common problems

| Symptom | What to check |
|---|---|
| The ad dashboard shows clicks, the tracker shows zero | Whether Safe Page click recording is off; the suppressed-hit counter |
| 100% of traffic on the safe page | Whether the geo database is installed (**⚙ → Geo databases**); `geo_unknown` reasons in the click log |
| Live mobile users on the safe page | Reason `ip2proxy_vpn_proxy` — remove the VPN/proxy layer (it is a hard signal; low sensitivity will not cancel it) |
| Meta or Telegram reviewers reach the offer | Their networks are not flagged as data centers by ASN; a single range hit at medium sensitivity is only a soft signal. Raise the sensitivity or add a "Bot" filter to an intercepting stream |
