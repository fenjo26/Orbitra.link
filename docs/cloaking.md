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

Two exemptions keep real visitors out of the infrastructure layers:

- **iCloud Private Relay** — Apple's published egress ranges; a relay address is Apple's privacy infrastructure, not the visitor's network.
- **Chrome prefetch proxy** — the proxy's OWN geofeed (RFC 8805, `prefetch_proxy_geofeed`, downloaded by the same cron into `var/ipranges/prefetch_geofeed.txt`). The exemption needs BOTH halves: the IP in the geofeed AND the request marker `Sec-Purpose: Prefetch; anonymous-client-ip` (a forbidden header name — page JS cannot forge it). A real visitor clicking a search result can arrive through this proxy with an ordinary browser UA; without the exemption the merged cloud ranges (and IP2Proxy's `DCH`) would route live search traffic to the safe page. The marker requirement is what keeps the exemption narrow: Google's OTHER fetcher proxies — the Translate proxy a moderator may use among them — carry no such header and stay flagged as the datacenter traffic they are. The geofeed row also carries the visitor's real country, so geo targeting uses it instead of the egress IP's MaxMind answer. Until the feed is downloaded the exemption is simply off.

If a required database is missing, the campaign editor shows a warning right in the cloak card.

### Sensitivity

Signals are either **hard** (an explicit hit in a blocklist or reputation database) or **soft** (individually noisy heuristics).

| Level | Safe page when |
|---|---|
| Low | only a hard signal |
| Medium (default) | a hard signal, or two soft ones |
| High | any single signal |

### SEO-shield mode (verified search bots)

For cloak pages that must **stay indexed** — where the money page is what ranks in search — the default "every search bot is a bot" rule is wrong: the real crawler has to see the money page, only the pretenders must not. Turn on **"Verified search bots see the money page"** in **Settings → Bots → Search engines (SEO)** and a claimed Googlebot / Google-InspectionTool / Bingbot / YandexBot is verified, strongest evidence first:

1. **The engine's published IP list** — `common-crawlers.json` for Google, `bingbot.json` for Bing (downloaded daily by the `ipranges` cron). A hit is conclusive; this is Google's own recommended verification method at scale and costs no DNS on the click path.
2. **Reverse + forward DNS**, and only when the visitor's ASN already belongs to the engine (Google, Microsoft, Yandex) — the RFC procedure: the PTR hostname must end in the engine's domain (`googlebot.com` / `search.msn.com` / `yandex.*`) and resolve back to the same IP. Verdicts — including negative ones — are cached on disk for a day (`var/cache/cloak_dns_verify.json`): PHP-FPM wipes statics per request, and `gethostbyaddr` has no usable timeout. The ASN gate is the DoS answer: an attacker cannot source traffic from Google's network, so a flood of fake "Googlebot" UAs resolves to the cheap `spoofed_search_bot` verdict with zero DNS.

- A **verified** crawler gets the money page, bypasses the targeting filters and the JS challenge (reason `verified_search_bot` in the click log, evidence = matched CIDR or PTR hostname).
- A UA that matches neither is proof of spoofing — the hard signal `spoofed_search_bot`, the safe page at any sensitivity.

Off by default: with the setting off, every claimed search bot goes to the safe page exactly as before.

### Referral source (search referrer, soft signal)

A stream-level rule for SEO traffic: **Referral source → "From search only"** in the stream's cloak card. A visitor whose referer is not a search engine result page gets the SOFT signal `non_search_referrer` — on medium it takes a second soft signal to send them to the safe page, on high it acts alone. Deliberately soft: a missing referer is also a returning user with a bookmark, and a hard referrer rule is one of the divergences comparative cloaking detection looks for. Verified search bots never reach the rule.

### Targeting filters

Applied after the passive layers; a mismatch means the safe page (`TARGETING_SAFE`):

- **Countries** — allow or block a list. If the country cannot be determined, the reason is recorded as `geo_unknown` rather than `geo_country`. Without a geo database every visitor is `Unknown`, and the country filter will send 100% of traffic to the safe page; the editor warns about this.
- **Device types** — only the selected ones, or all except the selected ones.
- **Block bots and data-center providers** — the visitor's ISP and ASN compared against a list: the stream's local list or, if it is empty, the global one from **Settings → Bots**.

A **DNS-verified search crawler** (see below) skips the targeting filters and the JS challenge: Googlebot legitimately crawls from US IPs with a desktop User-Agent, and holding that against it would defeat the whole point of the SEO-shield mode.

### JavaScript verification

Off by default. The visitor first receives the safe page and moves to the money page via a one-time signed link (TTL 5 minutes) only after executing JS. `navigator.webdriver = true` stays on the safe page. So does anything that does not execute JS. The price is one extra hop for every live visitor.

With the [browser check](browser-check.md) on (Settings → Bots), the same step also collects browser signals: in Soft or Strict mode a virtual machine, emulator or spoofed phone gets the safe page here, with a `bc_*` reason. The stream's *Browser check* field can turn it off for this stream only.

**Preloader.** While the JS step runs (a fraction of a second) the visitor sees a loading page, configured per stream under *JS Challenge Preloader*: the built-in page, or your own HTML with a live preview. Historically this step reused the safe page's HTML — that markup is still shown when no preloader is configured, so existing campaigns keep their look; pick *Custom HTML* to override it. The verification probe is injected into your HTML automatically — do not add your own redirect scripts, they race the signed link.

### Recording and reports

| Setting | Default | What it does |
|---|---|---|
| **Record Safe Page clicks** | on | Safe-page hits are written with `is_safe_page = 1`. Turn it off and they will not reach the database |
| **Exclude Safe Page clicks from reports** | on | Hits stay in the click log but drop out of every report metric — clicks, visits, uniques, bots, empty referers, cost, CPV. A safe hit is still a visit to the campaign link, so with the box off it counts in "Clicks" too (since 1.8.5 clicks count every visit) |

Even when hits are not written, the suppressed-hit counter (`cloak_suppressed_stats`) counts them per campaign, stream, day, and verdict — "zero clicks" will not hide the real traffic.

## Global settings: Settings → Bots

- **Bot IP addresses** — IPs and CIDR, IPv4 and IPv6. Every entry has a source (manual or from a feed); cleanup can delete entries of one source only.
- **Signatures (User-Agent)** — substrings.
- **Global Bot ISP blacklist** — one provider per line. Too-short and too-generic strings (`Inc`, `LLC`) are ignored: they would match almost every ISP.
- **Data-center and crawler IP ranges** — published ranges of AWS, Google Cloud and Googlebot, Bing, Azure, Oracle, DigitalOcean, Facebook, Telegram, OpenAI, Cloudflare, Apple Private Relay, and others from [lord-alfred/ipranges](https://github.com/lord-alfred/ipranges). The same update also downloads three targeted feeds: the Chrome prefetch proxy's **geofeed** (`var/ipranges/prefetch_geofeed.txt`, the exemption list) and the engines' **crawler IP lists** (`google_crawlers.txt`, `bing_crawlers.txt`, used by [SEO-shield mode](#seo-shield-mode-verified-search-bots)). Updated daily by the `ipranges_cron.php` cron (manually via the "Update now" button or `php ipranges_cron.php --force`). Until the lists are downloaded, the layer is inactive.
- **Browser check** — Off / Observe only / Soft / Strict: bots, VMs and emulators caught by contradictions in the browser. → [Browser check](browser-check.md)
- **Treat VPN ASN as a bot signal** — on by default. Turn it off if your audience widely sits behind a commercial VPN.
- **Search engines (SEO)** — a card of two opt-in switches, both off by default:
  - **Verified search bots see the money page** — see [SEO-shield mode](#seo-shield-mode-verified-search-bots).
  - **Opt out of the Chrome prefetch proxy (traffic-advice)** — publishes `/.well-known/traffic-advice` with `disallow` for `prefetch-proxy`. Chrome then loads the page from the visitor's own IP: clicks keep their real geo, and a prefetched KClient landing's click is no longer lost (the tracker skips prefetch hits by design, so a proxy-loaded landing never registered its click). Cost: the page opens a fraction of a second slower from search. The nginx config routes the path to the tracker (`location = /.well-known/traffic-advice`), because the generic dotfile deny would swallow it.

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
| `spoofed_search_bot` | a claimed Googlebot/Bingbot/YandexBot that failed verification — not in the engine's IP list, and either no PTR round-trip or not even the engine's ASN (SEO-shield mode, hard signal) |
| `verified_search_bot` | the opposite: a crawler whose verification **passed** — it went to the money page, recorded for observability |
| `non_search_referrer` | the stream's "From search only" rule: the referer is not a search engine (soft signal) |
| `crawler_or_tool_ua`, `suspicious_ua`, `no_user_agent`, `missing_accept_language` | User-Agent and header heuristics |
| `webdriver`, `js_missing`, `js_fingerprint` | JavaScript verification |

## Common problems

| Symptom | What to check |
|---|---|
| The ad dashboard shows clicks, the tracker shows zero | Whether Safe Page click recording is off; the suppressed-hit counter |
| 100% of traffic on the safe page | Whether the geo database is installed (**⚙ → Geo databases**); `geo_unknown` reasons in the click log |
| Live mobile users on the safe page | Reason `ip2proxy_vpn_proxy` — remove the VPN/proxy layer (it is a hard signal; low sensitivity will not cancel it) |
| Meta or Telegram reviewers reach the offer | Their networks are not flagged as data centers by ASN; a single range hit at medium sensitivity is only a soft signal. Raise the sensitivity or add a "Bot" filter to an intercepting stream |
