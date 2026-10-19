# External PWA & Telegram Channels

Two ways to widen the funnel beyond Orbitra's own PWA builder: third-party PWA services as stream targets, and Telegram channels as offer destinations with attributed subscribers.

## External PWA builders

A third-party service (PWA.Group, TSL.app, or any other) can be a stream target exactly like a landing:

1. **Create the builder** — ⚙ → Integrations → External PWA. Pick a service from the catalog (`data/external_pwa_services.json`: redirect URL template, postback template, status map) or fill your own; your values win over the catalog's. **The shipped catalog entries are templates — verify every parameter against the service's current docs before going live.**
2. **Point the service back**: in its settings, set the post-install redirect to
   `https://<tracker-domain>/eflow/<campaign-alias>?subid={subid}&eid={eid}`
   (the page shows the exact shape for copy-paste).
3. **Add it to a stream** — in the campaign editor's landings block, *Add external PWA*. External builders ride the same weighted pool as landings, so the split gives you *"own PWA vs theirs"* A/B in one funnel.

What happens on traffic: the click leaves for the service with `subid` = click id (`clicks.external_pwa_id` remembers which service). When the visitor returns to `/eflow/<alias>`, the tracker stamps the install (`external_install_at`), stores the service's `eid` (kept 7 days), and continues to the offer through the campaign's own selection — exactly like "own" PWA after install.

Conversions on those clicks are posted back to the service through the same reliable s2s queue as partner postbacks, with `subid` + `eid` + the service's status word (mapped per service) + payout. Campaign reports gain *external installs*.

## Telegram channels as offers

A *Telegram channel* offer sends ad visitors into a Telegram channel through a **funnel bot** and credits the join as a conversion. Funnel bots are **separate from the admin bot** (Integrations → Telegram): the admin bot only answers the chats you connected and reads your statistics, so strangers from an ad must never reach it. The panel refuses the admin bot's token as a funnel bot.

Setup:

1. Create a new bot in @BotFather and add its token in **⚙ → Integrations → Telegram funnel bots**.
2. Add that bot to your channel as an admin with the **Invite users via link** right. The channel appears on the bot's card by itself (or add it there by `@name`, `t.me/…` or `-100…`).
3. Create an offer of the **Telegram** type, pick the bot and the channel, set the join window (1–7 days) and, optionally, a welcome text.
4. Put the offer into a campaign stream and run traffic through the campaign link. Never hand out a direct channel link — those joins cannot be attributed.

The funnel:

1. Click → `https://t.me/<funnel bot>?start=<subid>` — `/start` binds the Telegram user to the click (`tg_start`).
2. The bot answers with the welcome text and a **join-request invite** (`creates_join_request=true`, expiring with the offer's window).
3. `chat_join_request` → the bot approves only users it invited within the window and writes a `tg_join` conversion (with campaign and offer) on the bound click. Requests from anyone else are left to the channel's moderators.
4. A network that knows only the subscriber posts to the **regular postback URL** with `tg_user_id` and `channel_id` instead of `subid`:
   `https://<domain>/<postback key>/postback?tg_user_id={tg_user_id}&channel_id=-100…&status=deposit&payout=50`
   — resolved to the click of that user's last join of the channel within 30 days. The offer editor's Integration tab shows the exact URL.

Funnel bots are polled by `cli/tg_funnel_poll_cron.php` (every minute, all bots in parallel, no webhook — works without a domain). `install.sh` schedules it; the bots page shows the crontab line when the poller is not running.
