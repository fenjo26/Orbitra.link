# Session recordings and click heatmap

Watch how a visitor behaved on your landing: mouse movement, scrolling, clicks and taps, played back like a video. This works much like Microsoft Clarity or Yandex Webvisor, except the recordings stay on your own server. Each landing also gets a **click heatmap** and a **scroll map**.

Since v1.8.3 recordings also cover **whole sites**, not just landings: an organic site (SEO traffic, your own service pages) with the tracker script on every page records across pages — one visit is one recording, with page transitions in the player and a click heatmap per page. See [Recording a whole site](#recording-a-whole-site-organic-traffic) below.

## Switching it on

1. Open the landing → **Session recording** tab.
2. Tick **Record sessions on this landing**.
3. Choose **what share of visitors to record**: 5 / 10 / 25 / 50 / 100 %. Visitors are picked at random, but the choice is fixed per click (one click is either always recorded or never).
4. Choose **how long to record**: until you switch it off, or 1 hour / 24 hours / 3 days / 7 days. When the time runs out, recording stops by itself and the recordings already made stay.
5. Click **Save**, the usual landing save button.

The tab shows a live estimate built from the landing's real traffic over the last 24 hours, for example: "about 120 recordings a day, roughly 18 MB".

Bots and safe-page visitors are **never** recorded. The server decides before the page is sent, so those visitors don't even get the recorder script.

## Recording a whole site (organic traffic)

For a site that is not a landing — a service site living on SEO, direct and social traffic — recording is switched on at the **campaign** level:

1. Put `tracking.js` on **every page** of the site, between `<head>` and `</head>` (the snippet is in the campaign's **Tracking** tab). Use `tracking.js` for this — `kclient.js` and `kclient.php` don't support multi-page sessions or the consent API.
2. Open the campaign → **Integrations** tab → **Session recordings** card.
3. Same settings as a landing: the share of visitors (for a busy site 5–10 % is usually enough — every page of a visit costs disk), how long to record, and a live estimate from the campaign's real traffic.
4. Tick **Multi-page site: one click per visit** if you also want the visits glued — see the next section.

A visit is then recorded across pages: the player shows the transitions, the list shows how many pages the visitor opened and which was first and last, the click heatmap gets a **page selector** (each page has its own map), and the recordings list can filter by campaign, by traffic source (Google / Bing / Yandex / social / direct), by a page substring and by *multi-page only*.

The campaign switch also covers clicks that reach a landing without its own recording settings — but a landing you have ever configured for recordings keeps full control of its own clicks (its switch off means off).

## One click per visit

Without this, every page of the site registers its own click in the campaign — the same as it always was. With **Multi-page site: one click per visit** ticked, a visitor moving through the site's pages is **one** click: statistics stop being inflated by page views, and the recording glues into one session.

The glue has guards, so a paid click is never swallowed by a remembered one:

- the tracker glues only when the visitor came from **the same site** (an internal navigation) and less than **30 minutes** passed since the previous page;
- a request carrying **new or changed ad markers** (`fbclid`, `gclid`, `ttclid`, `msclkid`, `utm_*`, `sub_id_N`, …) is a new campaign entry even within those 30 minutes — the second ad click gets its own click, its own parameters and its own CAPI attribution. A site that propagates its *same* markers through internal links still glues fine;
- the switch is **off by default**: an update never changes anyone's click numbers until you tick it yourself.

## Cookie consent (EU sites)

For EU visitors, session recording needs the visitor's consent. Tick **Start only after cookie consent** in the campaign card, and until the visitor agrees **nothing is written — not even clicks for the heatmap**. The recorder wakes up from your cookie banner with one call:

```js
window.OrbitraReplay && OrbitraReplay.consent();
```

The `window.OrbitraReplay && …` guard matters: `tracking.js` defines the stub `OrbitraReplay` on every page, but your banner can fire before it loads — the guard makes that safe. Two equivalent alternatives:

- your consent manager can simply set the localStorage key `orbitra_replay_consent` to `1` — the recorder polls it and starts on the current page, not just the next one;
- `OrbitraReplay.revoke()` withdraws the consent and stops the recording on the fly; your banner should call it when the visitor refuses.

Once given, the consent is remembered, so later pages of the visit record from the start. Clearing it when the visitor withdraws is your banner's job. Consent mode gates what is written; the form-field masking described under [Privacy](#privacy) works in every mode.

## Where recording works

| Where | How |
|---|---|
| A landing uploaded to Orbitra (ZIP / editor), or an action landing "Show HTML" | Automatic |
| An external landing with `tracking.js` / `kclient.js` | Automatic: the script loads `/replay.js` when the Click API response says this click is recorded |
| An external landing with `kclient.php` | Automatic: a current `kclient.php` prints the recorder tag at the end of the page. You can also place it yourself with `<?php echo $client->replayScript(); ?>` |
| A whole site with `tracking.js` on every page | Automatic (v1.8.3): the campaign-level switch in the campaign → **Integrations** covers every page of the visit |
| A campaign that redirects straight to the offer | Not possible: there is no page to record |

Multi-page gluing (`?_subid=` continuation) and the consent stub exist only in `tracking.js`; `kclient.js` and `kclient.php` record their single page but don't glue visits and don't expose `OrbitraReplay`.

## Watching recordings

**⚙ → Logs → Session recordings.** Filters: landing, campaign, period, device (desktop / mobile), traffic source (Google / Bing / Yandex / social / direct, derived from the referrer), a page substring (matches the entry or the last page of the visit), *multi-page only*, minimum duration, *went to offer*, *converted*, *with rage clicks*. Each row shows the time, country and device, landing and campaign, duration, clicks (with a 🔥 badge for rage clicks), scroll depth, the outcome (conversion / went to offer / left) and the file size; multi-page visits show a "N pages" badge with the first → last path.

Every column header sorts the list (click again to flip the direction), and the list is paged with a page-size selector at the bottom.

**Deleting.** Tick rows to select them, or tick the header box to select the whole page. A bar appears with *Delete (N)* and *Clear selection*. When there are more pages, it also offers *Select all recordings matching these filters (N)*, which selects every recording behind the current filters on all pages at once. So "delete all mobile bounces under 5 seconds from landing X" is: set the filters → tick the header → *Select all matching* → *Delete*. Each row also has its own trash icon, and the storage panel has *Delete this landing's recordings* and *Delete all recordings*.

The player has play/pause, a timeline with red marks at every click, speeds of 1× to 8×, and **Skip inactivity**, which jumps over pauses where nothing happened. The click card in the traffic log also gets a **Session recording** button when that click was recorded.

**Rage click**: three clicks within 0.7 s on the same spot. Usually it means a button or element doesn't respond, which is worth fixing.

## Click heatmap and scroll map

Open it from the landing's recording tab, from the campaign's recordings card, or with the **Click heatmap** button in the recordings list once a landing or campaign is selected.

- **Clicks**: where visitors click, from blue (rare) to red (often). Red rings mark rage clicks. Desktop and mobile are separate maps, because the layout is different.
- **Scroll**: the page split into 10 bands, each labeled "X % reached Y %". This uses the dwell timer, so it covers **every measured visitor**, not only the recorded ones.
- **Page selector** (multi-page sites): each page of the site gets its own click map — pick a path and both the points and the background frame switch to that page. Without it, pages would merge into one meaningless coordinate space.

The page behind the map is drawn from the newest recording of that device type (for a picked page — from its frame inside that recording). No request goes to the live site and no click is logged.

## What to look for

A few ways recordings pay off in day-to-day buying:

- **A landing that doesn't convert.** Filter the landing, set *Duration ≥ 15 s*, leave *Went to offer* unticked, and watch 5–10 visits. Visitors who read and still don't click usually tell you why: the CTA is below the fold on mobile, a timer or popup covers the button, the form looks broken.
- **Rage clicks.** Tick *With rage clicks*. A cluster on one spot nearly always means an element that looks clickable and isn't (a fake video, a picture of a button, a dead anchor link). The heatmap shows the same thing at a glance as red rings.
- **Mobile vs desktop.** Open the heatmap for both. If phone visitors never reach the order block on the scroll map, shorten the page or repeat the CTA higher.
- **Traffic quality.** Real people move the cursor in curves, pause, and scroll unevenly. A stream of recordings with no movement, instant perfect scrolls or clicks within a second of loading points to poor or bot-heavy traffic from that source or placement, even when it passed the cloaking filters.
- **Before and after.** Change the landing, delete its old recordings (filter by the landing → tick the header → *Select all matching* → *Delete*), and compare the new heatmap against the old one.

Recording 10 % for a day or two is usually enough to see the pattern. You don't need 100 % running all the time.

## Storage limits

The limits are shared by everything that records — landings and campaigns alike. They are shown on the landing's recording tab, on the campaign's card and under the size button in the recordings list:

- **Keep recordings for** 1 / 3 / 7 / 14 / 30 days. Older recordings are deleted automatically.
- **Maximum disk space**: 256 MB to 20 GB. When it fills up, the **oldest recordings are deleted** and new ones take their place.

Safety rails that cannot be switched off:

- one recording is capped at 30 minutes and 5 MB;
- when the server disk has less than 1 GB free, no new recordings are started;
- recordings that never received any data are cleaned up after 2 hours.

A typical recording takes 50–300 KB (it is the page structure plus changes, not video). The card shows the average of your real recordings and how many fit in the chosen limit.

Cleanup runs at most once a minute, driven by incoming recordings and by opening the list. There is **no cron to add** and no server command to run after the update.

## Privacy

- **Every form field is masked in the visitor's browser.** What they type (phone, name, password) never leaves the device; the replay shows asterisks.
- Give any block the class `orbitra-no-record` to hide it completely (it replays as an empty box). Use `orbitra-mask` to mask its text.
- Recordings live in `var/replays/` on your server, which the nginx config never serves publicly. They are read only through the panel, by users with access to landings.
- If you record visitors from the EU, either switch on [consent mode](#cookie-consent-eu-sites) or mention session recording in your site's privacy policy.

## Troubleshooting

**The tab says "Recording is on" but the list stays empty.**
- Make sure visits actually reach **this landing**: the stream must send traffic to it, and the visits must not be bots or safe-page visitors (those are never recorded).
- With a low share (5 %) and little traffic, the first recording can take a while. For a quick test, set 100 % and open the campaign link from your phone.
- Check that the recording window hasn't run out: the landing's tab shows "Recording stopped…" when it has.
- External landing: the page must have `tracking.js`, `kclient.js` or a current `kclient.php`. An old copy of `kclient.php` doesn't know about recordings; download it again from the campaign's **Tracking** tab (*Download kclient.php*).

**"The recording is too short to play".** The visitor left before the first page snapshot was sent (usually under a second). This is normal for a share of bounces.

**Multi-page visits still show as separate clicks.** The *One click per visit* glue needs all three: the switch ticked in the campaign card, `tracking.js` (not `kclient`) on every page, and the visitor actually navigating the site — an entry from an ad, a bookmark or another site starts a new click on purpose, so paid clicks are never glued. Also check the 30-minute window: after a long pause the next page starts a new click.

**The replay looks unstyled or images are missing.** The replay loads images and fonts from the landing's own URLs. If the landing's files were deleted or replaced after the visit, old recordings can't show them.

**New recordings stopped.** Check the storage card: if the server disk has less than 1 GB free, recording pauses until you free space or lower the limit.

**A recording shows LIVE.** The visitor is still on the page. Batches arrive every few seconds, so the recording keeps growing until they leave (30 minutes at most).

## Technical notes

- Recorder: [rrweb](https://github.com/rrweb-io/rrweb) (`@rrweb/record`), built into `/replay.js` (≈25 KB gzip). Loaded with `defer` only for recorded clicks, and batches are gzip-compressed in the browser.
- Ingest: `POST /pixel.gif?action=rr`. A batch is accepted only for a click the server armed and only with its HMAC signature.
- Storage: one `.ndjson.gz` file per recording, plus the tables `session_replays`, `replay_clicks` (heatmap points, kept 30 days) and `replay_landing_settings`, added by migration 76.
- Organic sites (v1.8.3, migration 78): `replay_campaign_settings` (the campaign switch, consent mode, *one click per visit*), the `entry_url` / `pages` columns for multi-page sessions, per-page heatmap points, and Click API v3 visit continuation — `tracking.js` sends `?_subid=` for the same-site pages of a visit; the server re-checks the ad markers against the remembered click's parameters. Source: `core/click_api.php` (the continuation branch), `core/SessionReplay.php`.
- Source: `frontend/replay/recorder.js` (rebuild with `npm run build:replay`), `frontend/src/components/replay/`, `tracking.js`.
