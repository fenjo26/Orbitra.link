# Creatives Library

The Creatives tab of the Content Gallery answers one question: **which creatives actually earn**. It grows out of the existing gallery — platform-synced files (Facebook images and videos, TikTok covers) live side by side with your own uploads, every creative carries spend/revenue/ROI, and a draft you uploaded manually picks up metrics the moment an ad starts running it.

## How the data arrives

Nothing is configured by hand. The chain is:

1. **Cost sync** (aggregator cron or *Sync now*) pulls spend rows from Facebook/TikTok. Every row now also carries the ad/adset/campaign names and the platform's own impressions/clicks counters (`cost_records.impressions`, `cost_records.platform_clicks`), and upserts them into `ad_entities`.
2. **Creative metadata pass** (`core/CreativeSync.php`, throttled to one pass per 3 hours per connection) asks the platform what those ads contain: the creative's image hash / video id / ad text. Bindings land in `creative_usages`, download tasks in `creative_fetch_queue`.
3. **File worker** (`cli/creative_fetch_cron.php`, scheduled by install.sh every 5 minutes) downloads the actual files: originals from Facebook (`/act_X/adimages` by hash, video source + cover), covers and previews from TikTok (`/file/video/ad/info/`, `/file/image/ad/info/`). Dedup is exact by sha256; near-duplicates are matched by a 64-bit dHash with a Hamming threshold of ≤6 — **that is the "draft went live" link**: your manually uploaded draft is bound to the platform's copy instead of spawning a duplicate.
4. **Daily aggregate** (`core/CreativeStats.php::rebuildWindow`) rebuilds `creative_daily` after every cost sync: spend (with the agency fee), impressions, platform clicks, tracker clicks/visitors, installs, registrations, FTD and revenue — tracker metrics bucketed into the ad account's own timezone days via the indexed `clicks.ad_ref` column.

The worker validates downloads by signature (mp4 `ftyp` / webm EBML for video, `getimagesize` for images), enforces size caps (10 MB images, 100 MB video) by aborting the transfer mid-stream, and goes through the connection's proxy. A banner in the tab tells you when files are queued but the worker has not run.

## The tab

- **Filters**: period (default last 7 days), platform, format, tag, search, *active only* (creatives whose ads still run), sorting by spend/revenue/ROI/clicks.
- **KPI cards** over unique assets — a 3-image carousel is one creative, its day's spend split equally across the three files.
- **ROI without revenue is a dash, never −100%**: a creative that simply hasn't converted yet is not a burned one. Colors: green ≥ 30%, amber 0–30%, red below zero.
- **Creative card**: per-day series (spend bars, ROI marks), the ads that ran it with statuses and spend (the *Cabinet › Campaign › Adset* drill), the ad text, the near-duplicate family, your tags, download.
- Tags are free-form (`offer, geo, approach, creator`) — the report by tag answers "which approach works better".

## Where creatives surface elsewhere

- **Campaign reports** gain a *Creative* grouping (the file name instead of a bare ad id) and a preview thumbnail on ad rows.
- **Campaign editor** has a *Creatives* tab: what carried traffic into this campaign over the last 7 days, with tracker-wide ROI.
- **Telegram daily digest** lists the day's top-3 and worst-3 creatives and fires a *burnout* alert when ROI or platform CTR fell harder than the threshold (default −50%) over the last 7 days vs the 7 before, on spend above the minimum (default $50). Thresholds: settings `telegram_creative_burnout_drop_pct`, `telegram_creative_burnout_min_spend`.
- **Ads Manager extension** shows *CREATIVE ROI* (lifetime) next to an ad's day columns.
- **MCP**: `orbitra_creatives_top`, `orbitra_creative_detail`.
- Platform-synced assets are available in the **MediaPicker** for PWA/landing posters like any other file.

## Attribution health banner

The report's top banner ("Revenue linked to ads: 62%") comes from `action=revenue_attribution_health`: revenue counts as linked when any ad-level click parameter carries a real platform id — an unreplaced `__CID__` / `{{ad.id}}` macro counts as missing, same rule the cost importer's diagnostics use. The *Fix* button jumps into the offending source's editor with the parameters tab highlighted.

## Notes

- TikTok video files are not downloaded (the API needs separate asset rights); the cover is stored and the creative still carries full metrics.
- Second-queue items (AI text extraction, auto-tags) are intentionally not built yet.
