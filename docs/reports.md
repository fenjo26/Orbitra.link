# Reports, logs and metrics

## Time zone and period

All reports, charts and logs show time in a single zone: the one selected in the period calendar, or the profile zone when nothing is selected there. The list has about 350 zones grouped by region, with the profile zone pinned to the top. The calendar's first day of the week comes from the profile. In tables, hovering over a time shows UTC.

## Dashboard

- **Cards and chart** — visits, clicks, conversions, revenue, cost, profit for the period. The set of cards and the chart metric are configured in the dashboard settings.
- **"Campaigns / Offers / Landings / Sources" blocks** — top 10. Clicking a column header sorts by clicks, uniques, conversions or name — and the full list is sorted before the top is cut. The sorting is remembered per block.
- **Latest clicks** — by default: event ID, date and time, campaign, OS (logo), browser (logo), IP, destination. The gear icon opens column selection (geo, device type, raw User-Agent and domain are also available). Columns can be dragged by the handle and resized; double-clicking the edge resets the width.
- If no geo database is installed, or only Sypex Geo is, a banner at the top suggests connecting one.

## Campaign list

Columns are chosen in the report customizer; ready-made presets exist (for example, "Traffic", "Landing → Offer"). Columns can be dragged and resized; ID, status and name are pinned left and stay put when the wide table scrolls. On a phone, rows turn into cards, and the set of metrics in a card is chosen separately.

The click log button in a campaign row opens the full traffic log filtered to that campaign.

## Campaign report

A builder: grouping by one or several dimensions, filters, a set of metrics, CSV export.

**Dimensions:** campaign, stream, landing, offer, source; country, region, city; device type, OS, browser, language; day, hour; time to offer (buckets), time on landing (buckets); first and last PWA screen; campaign / ad group / ad ID, keyword, creative ID, external ID, `sub_id_1` … `sub_id_5`; campaign, adset and ad names; UTM placement; ISP and ASN; popup variant (design × text).

Streams deleted before the version with stable IDs show up as "Deleted stream #N", and unnamed streams as "Untitled stream".

## Trends and cohorts

**Trends** — metric charts by day, week, month, hour or day of week with filters by campaign, offer, source and parameters. Multi-select of campaigns and offers. **Cohorts** — campaigns are grouped by launch month or quarter, and you can see how each cohort behaves over its months of life (M0, M1…).

## Logs

**⚙ → Logs**, tabs: traffic, postbacks, S2S, system, audit.

### Traffic log

- **Columns:** the basic ones plus any click parameter as its own column (`ad_id`, `campaign_id`, `sub_id_N`, `ttclid`…). Suggestions come from parameters actually seen in clicks over the last 14 days. The column selection and order are remembered in the browser.
- **Filters:** campaign, stream, cloaking route and reason, period; for parameters — equals / not equals / in list / not in list / empty / not empty, up to 5 conditions. "Empty" answers the question "which clicks arrived without this parameter".
- **Loading:** "Show more" with no 100-row ceiling. Pagination is cursor-based, so new clicks do not shift the page.
- **CSV:** the same filters and visible columns, up to 100,000 rows, `;` separator, UTF-8 with BOM (Excel opens it in columns). Cells starting with `= + - @` are escaped.
- **The "Sub ID 1" column** — the incoming `sub_id_1` parameter, not the click ID. The click ID that goes to the network is the event ID.
- **OS and browser** — logo and version; in-app browsers (Facebook, Instagram, TikTok…) and bot names are recognized.

### Click card

Opens from a click ID in the dashboard, logs and postback journal:

- device: OS and browser with versions, brand and model, in-app browser, bot name — parsed from the stored User-Agent on open, so it works for old clicks too;
- the stream that took the click ("EU (regular)"), uniqueness (campaign / stream / global), proxy flag, ISP / ASN;
- **Destination** — the actual redirect URL with the network's parameters and the click ID, or the stream action (404, text, safe page, "to campaign #N");
- the domain the click arrived on;
- time in the panel's zone with the zone name plus a separate UTC line; landing view time, offer click time, and time to offer;
- time on landing and scroll depth;
- all click parameters, with ad network IDs first;
- the cloaking verdict and reasons — only if cloaking judged the click.

## How metrics are calculated

| Metric | Definition |
|---|---|
| **Visits** | All campaign hits, including landing and safe page views (unless safe hits are excluded from reports) |
| **Clicks** | The offer funnel: direct offers clicked (from the catalog or by direct URL) plus landing views from which the visitor actually went to the offer. "Show text / 404" actions and safe pages are not clicks |
| **Uniques** | Separate per campaign, stream and globally |
| **CPC, CR, EPC** | Divided by clicks |
| **CPV, EPV** | Divided by visits |
| **ROI** | Profit ÷ cost |
| **Margin** | Profit ÷ revenue |
| **Real LP / offer clicks, Real LP CTR** | Only clicks with a recorded landing → offer transition |
| **Time on landing, LP bounces, scroll depth** | From the timer on the landing page. Only visible time counts; the denominator is visits where the timer ran. Visits without a timer (a foreign domain without the script, bots without JS) are "Unknown", not zero |
| **Possible bots** | Visits where the landing timer ran but there was not a single mouse movement or touch. This is a hint to look closer at the source, not an exclusion from statistics |
| **Approved** revenue / profit / ROI | Only conversions of approved types |
| **Popup shown / CTR / clicks / closed / conversions** | See [Conversion popups](conversion-popups.md) |
| **PWA screen views** | See [PWA Funnel](pwa-funnel.md) |

Custom formulas — **Settings → Custom Metrics**.

If "Exclude Safe Page clicks from reports" is enabled in the campaign, safe hits leave visits and everything computed from them ([Cloaking](cloaking.md#recording-and-reports)).

## System status

**⚙ → Status** — disk, CPU, RAM, database size and recommendations. On hostings that hide `/proc`, RAM shows as N/A.
