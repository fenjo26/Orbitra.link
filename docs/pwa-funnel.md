# PWA Funnel: per-screen configuration & statistics

Renderer v16 · migration 51. The in-browser visit to a PWA landing is now a
**configurable funnel**: an ordered list of screens where the first enabled
step is what a cold visitor sees first, every screen activation is beaconed
and reported, and each screen can carry its own tracking script.

## The funnel model

The `landings.config_json` gains one key, `funnel` — an ordered array of
steps:

```json
"funnel": [
  { "id": "scr_k3x9_2a", "type": "screen", "enabled": true,
    "template": "lobby", "title": "...", "text": "...",
    "button": "...", "image": "...", "custom_html": "...", "custom_js": "..." },
  { "id": "store", "type": "store", "enabled": true },
  { "id": "instructions", "type": "instructions", "enabled": true },
  { "id": "push", "type": "push", "enabled": true }
]
```

Step types:

| type | what it renders |
|---|---|
| `store` | The Google Play / App Store listing (existing markup; UA picks the layout) |
| `screen` | An operator screen: template `lobby` / `slot` / `wheel` / `custom` HTML — the same templates the installed-app screen uses. Up to **5** per funnel |
| `instructions` | A full-page install-instructions screen. Both platform lists render; CSS keyed on `data-store` (the UA sniff in `<head>`) shows the Safari steps on iOS and the Chrome-menu steps elsewhere |
| `push` | The push opt-in card placed in the browser flow. Only when **Push enabled** — without the VAPID key or browser support the step would be a dead end, so the flow skips it at runtime too |

Rules (`PwaLanding::normalizeFunnel()` is the whitelist, the editor only
produces shapes it accepts):

- ids match `^[a-z0-9_]{1,32}$`, are unique, and never collide with the
  builtin ids; invalid ones are regenerated server-side;
- one `store` / one `instructions` / one `push` per funnel (duplicates drop);
- max 5 `screen` steps;
- absent or invalid `funnel` → `[store]`. **Every pre-funnel config renders
  exactly what it rendered before** — the store is still the first screen,
  `ios_flow` still governs the install-click overlay, the push card still
  belongs to the installed app.

### Flow semantics (page JS)

- The **first enabled step** shows on a cold visit; a non-store first step
  hides the store layouts from the very first paint (`data-flow-first`, set
  by the `<head>` script before the body exists).
- Any `.install-trigger` CTA inside the flow **advances to the next enabled
  step**; when the flow is out of steps the CTA falls through to the legacy
  install attempt (native prompt / iOS overlay / redirect per config). The
  operator decides where the install prompt fires by ordering the funnel.
- The flow's push card: *Allow* runs the standard permission machinery (the
  handover to sw.js is untouched), *Not now* answers the step and advances.
  When the flow runs out after a push answer, the visitor goes to the offer
  (`flowExhausted`); with `app_action = screen` the installed-app screen
  shows instead.
- The installed app (standalone open) is **not** part of the flow: the
  standalone branch — open beacon, iOS install confirmation, push sync, then
  `app_action` — behaves exactly as before.
- `auto_redirect` / `decline_redirect` / `install_redirect` timers keep their
  global meaning and fire into the offer regardless of the visible step.

### Per-screen tracking

Every activation of a flow screen beacons
`/pixel.gif?action=pwa&kind=screen&screen=<id>&subid=<click>`:

- `clicks.pwa_entry_screen` — first screen the click ever saw (NULL-guarded;
  the click row is the dedup gate like every `pwa_*` column);
- `clicks.pwa_last_screen` — the step the click was last on (last-write-wins);
- `pwa_screen_views` — the raw 1:N event log
  (`click_id, landing_id, screen, created_at`), capped at **100 rows per
  click** so a parked tab cycling screens cannot pump the table. Migration 51
  creates it; the always-on self-heal DDL guarantees it exists on every
  schema version because the report SQL references it unconditionally.

Each `screen` step can also carry `custom_js` (the "Screen tracking script"
field in the editor). It executes on the screen's **first activation**, not
at page parse — an ad pixel must not fire for a screen the visitor never
reached. The template's interactive engines (slot reels, bonus wheel) were
refactored into per-container factories so several copies of the same
template can coexist on one page; element ids are prefixed per step
(`fs<id>_…`). Helpers available to custom scripts: `window.orbitraFlow.next()`,
`window.orbitraFlow.goto(id)`, plus the existing `orbitraRedirect` /
`orbitraBeacon`.

## Statistics

- **`pwa_screen_views` metric** — total flow-screen views, aggregated in the
  dashboard cards, the campaigns list, `campaign_report`, and the Landings
  and Offers tables (report == campaigns parity). A click legitimately
  contributes several views; there is no derived ratio on top.
- **`pwa_entry_screen` / `pwa_last_screen` dimensions** — plain clicks
  columns registered in `$allowed_dimensions` (api.php, `campaign_report`),
  so both work as Group By layers and as filters. NULL (pre-feature rows,
  non-PWA traffic) groups as *Unknown*.
- **`pwa_funnel_stats` endpoint** — per-landing funnel card on the Landings
  page (the bar-chart button on PWA rows): views/uniques per screen, entry
  and exit distributions (an exit = the click's last screen with no offer
  transition), and the screen→screen transition matrix walked from the
  ordered event log. Screen order mirrors the funnel config first.

## Editor & preview

The PWA constructor's step 1 gained the **Visit funnel** section: a
reorderable step list (grip drag, ↑/↓ buttons), enable toggles, the
first-screen badge, per-screen editors (template, title/text/button, hero
image via MediaPicker, custom HTML + tracking JS), and a cap meter (0/5).
The live preview can open the funnel **at any enabled step** — `pwa_preview`
accepts a step id as `view` and forces it via `window.__PWA_FORCE_SCREEN`.

## Renderer version & self-heal

`RENDERER_VERSION` bumped 15 → 16: the lander route and the domain-root
server regenerate statics written by the older renderer on the next view, so
already-created PWAs pick up the flow boot (and the store screen-view beacon)
without a re-save. Legacy configs regenerate with the `[store]` funnel and
stay visually identical.

## Tests

- `tests/pwa_funnel_http_test.php` — the funnel config model, flow markup,
  beacon dedup/cap/attribution, and the funnel-stats endpoint (HTTP, harness
  sandbox).
- `tests/report_metrics_test.php` — hand-computed pins for
  `pwa_screen_views` on the dashboard, landings and offers SQL (1:N views:
  a click contributes every screen it saw, revisits included).
