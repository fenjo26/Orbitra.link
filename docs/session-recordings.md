# Session recordings and click heatmap

Watch how a visitor behaved on your landing: mouse movement, scrolling, clicks and taps, played back like a video. This works much like Microsoft Clarity or Yandex Webvisor, except the recordings stay on your own server. Each landing also gets a **click heatmap** and a **scroll map**.

## Switching it on

1. Open the landing → **Session recording** tab.
2. Tick **Record sessions on this landing**.
3. Choose **what share of visitors to record**: 5 / 10 / 25 / 50 / 100 %. Visitors are picked at random, but the choice is fixed per click (one click is either always recorded or never).
4. Choose **how long to record**: until you switch it off, or 1 hour / 24 hours / 3 days / 7 days. When the time runs out, recording stops by itself and the recordings already made stay.
5. Click **Save**, the usual landing save button.

The tab shows a live estimate built from the landing's real traffic over the last 24 hours, for example: "about 120 recordings a day, roughly 18 MB".

Bots and safe-page visitors are **never** recorded. The server decides before the page is sent, so those visitors don't even get the recorder script.

## Where recording works

| Where | How |
|---|---|
| A landing uploaded to Orbitra (ZIP / editor), or an action landing "Show HTML" | Automatic |
| An external landing with `tracking.js` / `kclient.js` | Automatic: the script loads `/replay.js` when the Click API response says this click is recorded |
| An external landing with `kclient.php` | Automatic: a current `kclient.php` prints the recorder tag at the end of the page. You can also place it yourself with `<?php echo $client->replayScript(); ?>` |
| A campaign that redirects straight to the offer | Not possible: there is no landing to record |

## Watching recordings

**⚙ → Logs → Session recordings.** Filters: landing, period, device (desktop / mobile), minimum duration, *went to offer*, *converted*, *with rage clicks*. Each row shows the time, country and device, landing and campaign, duration, clicks (with a 🔥 badge for rage clicks), scroll depth, the outcome (conversion / went to offer / left) and the file size.

Every column header sorts the list (click again to flip the direction), and the list is paged with a page-size selector at the bottom.

**Deleting.** Tick rows to select them, or tick the header box to select the whole page. A bar appears with *Delete (N)* and *Clear selection*. When there are more pages, it also offers *Select all recordings matching these filters (N)*, which selects every recording behind the current filters on all pages at once. So "delete all mobile bounces under 5 seconds from landing X" is: set the filters → tick the header → *Select all matching* → *Delete*. Each row also has its own trash icon, and the storage panel has *Delete this landing's recordings* and *Delete all recordings*.

The player has play/pause, a timeline with red marks at every click, speeds of 1× to 8×, and **Skip inactivity**, which jumps over pauses where nothing happened. The click card in the traffic log also gets a **Session recording** button when that click was recorded.

**Rage click**: three clicks within 0.7 s on the same spot. Usually it means a button or element doesn't respond, which is worth fixing.

## Click heatmap and scroll map

Open it from the landing's recording tab, or with the **Click heatmap** button in the recordings list once a landing is selected.

- **Clicks**: where visitors click, from blue (rare) to red (often). Red rings mark rage clicks. Desktop and mobile are separate maps, because the layout is different.
- **Scroll**: the page split into 10 bands, each labeled "X % reached Y %". This uses the landing's dwell timer, so it covers **every measured visitor**, not only the recorded ones.

The page behind the map is drawn from the newest recording of that device type. No request goes to the live landing and no click is logged.

## What to look for

A few ways recordings pay off in day-to-day buying:

- **A landing that doesn't convert.** Filter the landing, set *Duration ≥ 15 s*, leave *Went to offer* unticked, and watch 5–10 visits. Visitors who read and still don't click usually tell you why: the CTA is below the fold on mobile, a timer or popup covers the button, the form looks broken.
- **Rage clicks.** Tick *With rage clicks*. A cluster on one spot nearly always means an element that looks clickable and isn't (a fake video, a picture of a button, a dead anchor link). The heatmap shows the same thing at a glance as red rings.
- **Mobile vs desktop.** Open the heatmap for both. If phone visitors never reach the order block on the scroll map, shorten the page or repeat the CTA higher.
- **Traffic quality.** Real people move the cursor in curves, pause, and scroll unevenly. A stream of recordings with no movement, instant perfect scrolls or clicks within a second of loading points to poor or bot-heavy traffic from that source or placement, even when it passed the cloaking filters.
- **Before and after.** Change the landing, delete its old recordings (filter by the landing → tick the header → *Select all matching* → *Delete*), and compare the new heatmap against the old one.

Recording 10 % for a day or two is usually enough to see the pattern. You don't need 100 % running all the time.

## Storage limits

The limits are shared by all landings. They are shown on the landing's recording tab and under the size button in the recordings list:

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
- If you record visitors from the EU, mention session recording in your landing's privacy policy.

## Troubleshooting

**The tab says "Recording is on" but the list stays empty.**
- Make sure visits actually reach **this landing**: the stream must send traffic to it, and the visits must not be bots or safe-page visitors (those are never recorded).
- With a low share (5 %) and little traffic, the first recording can take a while. For a quick test, set 100 % and open the campaign link from your phone.
- Check that the recording window hasn't run out: the landing's tab shows "Recording stopped…" when it has.
- External landing: the page must have `tracking.js`, `kclient.js` or a current `kclient.php`. An old copy of `kclient.php` doesn't know about recordings; download it again from the campaign's **Tracking** tab (*Download kclient.php*).

**"The recording is too short to play".** The visitor left before the first page snapshot was sent (usually under a second). This is normal for a share of bounces.

**The replay looks unstyled or images are missing.** The replay loads images and fonts from the landing's own URLs. If the landing's files were deleted or replaced after the visit, old recordings can't show them.

**New recordings stopped.** Check the storage card: if the server disk has less than 1 GB free, recording pauses until you free space or lower the limit.

**A recording shows LIVE.** The visitor is still on the page. Batches arrive every few seconds, so the recording keeps growing until they leave (30 minutes at most).

## Technical notes

- Recorder: [rrweb](https://github.com/rrweb-io/rrweb) (`@rrweb/record`), built into `/replay.js` (≈25 KB gzip). Loaded with `defer` only for recorded clicks, and batches are gzip-compressed in the browser.
- Ingest: `POST /pixel.gif?action=rr`. A batch is accepted only for a click the server armed and only with its HMAC signature.
- Storage: one `.ndjson.gz` file per recording, plus the tables `session_replays`, `replay_clicks` (heatmap points, kept 30 days) and `replay_landing_settings`, added by migration 76.
- Source: `core/SessionReplay.php`, `frontend/replay/recorder.js` (rebuild with `npm run build:replay`), `frontend/src/components/replay/`.
