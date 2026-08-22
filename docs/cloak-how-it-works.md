# How the Orbitra Cloaker Works

This document explains what the Cloak stream schema does on every click: how the
money/safe decision is made, what gets written to the database, and where to
look in the panel when traffic does not behave the way you expect.

Related: `docs/cloaking.md` (operator guide), `docs/architecture.md`.

---

## 1. What a cloak stream is

A stream with `schema_type = 'cloak'` has two faces:

- **Money page** — the real destination (local offers / landings / external URL from the rotation lists).
- **Safe page** ("white") — what everyone we do not trust sees: an inline HTML
  snippet, an external URL, a local landing or a local offer
  (`safe_mode` = `html` / `url` / `landing` / `offer`). A saved
  `safe_landing_id` / `safe_offer_id` selects the mode on legacy schemas that
  predate the explicit `safe_mode` key.

The decision runs on every tracker entry point — `index.php` (campaign URL),
`click.php` (integration script) and `/click_api/v3` (Keitaro-compatible API) —
through one shared module, `core/click_logger.php`, so the three paths cannot
drift apart.

## 2. The decision pipeline

For each visitor the detector (`core/CloakDetector.php`) evaluates, in order:

1. **Passive detection** — reputation signals that do not depend on your targeting:
   - **IP2Proxy PX12** (if installed): proxy type `DCH` → datacenter, `SES`/`AIC` → known bot,
     `VPN`/`TOR`/`PUB`/`WEB`/`RES`/`CPN`/`EPN` → VPN/proxy, any threat marker, fraud score ≥ 80.
   - **ASN blocklist** (`core/data/asn_blocklist.json`): 63 datacenter/hosting ASNs + 29 VPN ASNs.
   - **Literal cloud IP ranges** (`core/IpRanges.php`, refreshed daily by cron when active): AWS/GCP/Azure/Meta/… published space.
   - **ISP string keywords** (hosting provider names inside the ISP/organization text).
   - **UA heuristics**: crawler/tool user agents, missing UA, missing Accept-Language.
2. **Targeting filters** — hard routing rules from the cloak card, applied
   whatever the passive layers said: country allow/deny, device allow/deny,
   "block bot & datacenter ISPs". A miss here is `TARGETING_SAFE`.
3. **JS challenge** (optional, off by default): an extra round trip that proves
   a real browser; a webdriver failure is `JS_SAFE`.

Each signal is either **hard** (explicit blocklist/reputation match — near-certain)
or **soft** (individually noisy heuristic). Sensitivity decides how they combine:

| Sensitivity | Blocks on |
|---|---|
| `low` | hard signals only |
| `medium` (default) | hard signals, or two soft signals corroborating |
| `high` | any single signal |

## 3. Verdicts and reason codes

Every routed click ends up with a verdict — `money`, `passive_safe`,
`targeting_safe` or `js_safe` — plus the reason codes that produced it, e.g.
`geo_country`, `device_type`, `bot_isp`, `crawler_or_tool_ua`,
`ip2proxy_vpn_proxy`, `vpn_proxy_asn`, `iprange_datacenter`, `hosting_isp`.

The same line also lands in the server's PHP error log for post-mortems:

```
Orbitra cloak [campaign=12 stream=34]: stage=PASSIVE_SAFE ip=... reasons=[crawler_or_tool_ua] sensitivity=medium
```

## 4. Geo-targeting safety (the "zero clicks" footgun)

With a country allow-list configured and **no geo database installed**, every
visitor resolves as country `Unknown`. Older versions silently rejected 100% of
traffic to the safe page — real users included. Now:

- The reason is distinguishable: such visitors are marked `geo_unknown`, not `geo_country`.
- `geo_unknown_action` (`safe`, default | `money`) lets you decide what to do
  with visitors whose country simply cannot be determined.
- The campaign editor warns under the country selector, and the
  **Geo Databases** page shows a banner, whenever country filtering is active
  without a readable database (`global_settings` → `geo_targeting_ready`).
- The same degradation warnings cover the ASN database: the ISP blocklist is
  inactive without it.

## 5. What gets written to the database

A click row on a cloak stream carries the observability columns:

| Column | Meaning |
|---|---|
| `cloak_verdict` | `money` / `passive_safe` / `targeting_safe` / `js_safe` (NULL for money) |
| `cloak_reasons` | comma-separated reason codes |
| `is_safe_page` | 1 when the visitor saw the safe page — the cheap filter reports use |
| `isp`, `asn`, `proxy_type` | network facts the decision was based on |
| `cloak_sensitivity` | sensitivity level in effect |
| `landing_id` | the white landing for safe-page hits (money clicks bind their own landing/offer) |

## 6. Logging controls

| Control | Default | Effect |
|---|---|---|
| **Log Safe Page clicks** (`log_safe_clicks`) | ON for new streams | Safe hits are written with `is_safe_page=1`. Uncheck to drop them from the database entirely. |
| `dont_record_safe_clicks` (legacy) | — | Streams created before v1.1.9 keep their old behavior via the migration: safe hits were not logged. |
| **Collect clicks** (`collect_clicks`, any stream) | ON | OFF = the stream serves traffic but writes no rows. |
| Suppressed-hit counter | always on | Every suppressed hit (dropped by either control above) increments `cloak_suppressed_stats` per campaign/stream/day/verdict — "zero clicks" can never again hide real traffic. |
| Prefetch / debounce | settings | Speculative prefetches are answered but not logged; the 2-second debounce collapses duplicates. |

## 7. Reports

**Exclude Safe Page clicks from reports** (`exclude_safe_from_reports`):
logged-but-safe hits stay visible in the click log while being excluded from
campaign metrics, cost and CPC. ON by default (new streams and the v1.1.9
migration of existing ones), so reports stay clean while nothing is invisible.

## 8. Where to look in the panel

- **Analytics → Clicks**: Route (money/safe), Reason, Destination, ISP and ASN
  columns; filter by campaign, route and reason.
- **Campaign editor → Cloak card**: live diagnostics (`cloak_summary`) and the
  detection-layer degradation warnings of §4.
- **Settings → Geo Databases**: install/refresh GeoLite2-City / IP2Location
  DB11 / IP2Proxy PX12; the readiness banner.

## 9. Quick troubleshooting

| Symptom | First check |
|---|---|
| Ad account shows clicks, tracker shows zero | Suppressed-hit counter (§6) — was everything routed safe and dropped? Turn on safe-click logging. |
| 100% of traffic on the safe page | Geo Databases page: is a country DB installed? `geo_unknown` reasons in the click log? |
| Real mobile users on the white page | Reason codes: `ip2proxy_vpn_proxy` → uncheck the VPN/Proxy layer (a hard signal — sensitivity `low` will NOT override it). |
| Want to see raw decisions | Server PHP error log, `Orbitra cloak [...]` lines.
