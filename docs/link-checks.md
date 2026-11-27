# Link checks

**Link checks** open your offers and domains on a schedule, follow the redirects and tell you when something stops working: an offer switched off, a tracker answering 404, a redirect leading to another domain, a dead domain.

Off by default: every check of an offer link is a click in the partner network's statistics, so an update never starts sending them on its own. Switch it on under **Integrations → Link checks**.

## Where it lives in the panel

| What | Where |
|---|---|
| Setup: on/off, what to check, interval, proxies, worker | Integrations → **Link checks** |
| The list: what is broken and why, the redirect chain, the history | Notifications → **Checks** |
| How many links need attention | The indicator in the top bar (red when something does) and next to "Notifications" |
| A broken offer or domain | A red dot next to its name on the **Offers** and **Domains** pages; a click opens the link under Checks |
| Summary | The **Link checks** strip on the dashboard |
| Alerts | Telegram: the chats that have "Link checks" switched on (Notifications → Telegram) |

## What is checked

- **Offers** — every external offer with a link (not local ones, not archived). The link is opened as a visitor receives it, with the network's parameters and macros filled in; the sub id is `orbitra-check`, so the clicks can be told from real ones in the partner's statistics.
- **Domains** — every tracking domain, over HTTPS once it has a certificate. A domain is up when its web server answers at all: a 404 is a configured answer, a dead host is not.

## How a check works

The worker runs once a minute and checks what is due, as many links as its time budget allows — what does not fit waits for the next minute.

1. The link is opened without letting curl follow redirects on its own: every hop is first checked to be a public address (the server is never used as a door into its own network), then followed. Up to five redirects, plus the redirects a page performs itself (meta refresh, `location.href = …`).
2. Every hop is recorded: URL, status code, time.
3. The verdict:
    - **Broken** — the site answers 4xx/5xx, does not answer in time, the domain does not resolve, the certificate is broken, a redirect loop.
    - **Warning** — the link now leads to another domain than it used to; the site's protection (Cloudflare and the like) did not let the check through; a redirect from HTTPS to HTTP; a very slow answer.
    - **OK** — the final page answers 2xx and leads where it is expected to.

### Confirmed before reported

One failed check is not a failure: the link is checked again two minutes later, and only the second failure marks it **Broken** and sends the alert. One good check is enough to recover.

### Expected domain

For offers, the domain the link leads to at its first successful check is remembered. When the link later leads to another domain (the offer was replaced, the traffic is sent to a stub), the status becomes **Warning**. You can list the allowed domains for a link yourself, or switch the check off for it.

### Proxies

Optional. Without a proxy the check goes from the server's own address. A proxy in the offer's country makes a geo-restricted offer answer as it would for a visitor.

Under **Integrations → Link Monitoring**, you can add a single proxy or paste a whole list (one per line, up to 200 at once) in `socks5://user:pass@host:port`, `http://…` or `host:port:user:pass` format. Small additions (up to 3) are tested right away; larger lists are saved immediately and probed in the background by the worker, then re-tested every ten minutes. The proxy's address has to be public: the panel does not accept a proxy on the server itself or in its network.

You can use proxies in two ways:
- **Pool rotation** (global setting **Default proxy mode → Rotate through working proxies**): links without a dedicated proxy cycle through healthy proxies in the list automatically.
- **Per-link proxy** (gear icon in the list): pin a specific proxy to a particular offer or domain.

A link checked through a proxy that is down is not reported as broken. The failure is the proxy's: the proxy gets one message of its own, and the links behind it are not blamed.

## Settings

**Global** (Integrations → Link checks):

- **Check offers / check domains** — what is in scope.
- **How often to check** — the default interval for every link, from 5 minutes to 7 days.
- **User-Agent** — what the check calls itself; the default is Chrome on Windows.
- **Pause an offer once it is confirmed broken** — the offer is paused once; switching it back on is up to you.

**Per link** (the gear in the list): its own interval, proxy, User-Agent and expected domain, or switching the check off for this one link.

**Check now** (admins) books the link for the worker's next run. Capped at 100 per ten minutes, so a held-down button cannot turn into a flood of clicks.

## Alerts

Telegram, on a change of status — never on every failed check:

- 🔴 the link is not opening, with the reason in words and the chain of hosts;
- 🟡 the link needs attention: another domain, site protection;
- 🟢 the link is working again, and for how long it was down;
- ⚠️ the proxy is not responding — one message per proxy, not per link — and when it is back.

When many links fail in the same run (a network drop), one digest is sent instead of a storm of messages. The link's URL is left out of messages on purpose: in a group chat it would be public.

## The worker

`link_checks_cron.php`, once a minute:

```
* * * * * php /var/www/orbitra/link_checks_cron.php >> /var/www/orbitra/var/logs/link_checks.log 2>&1
```

A fresh install schedules it with the rest. An existing one gets the line the moment the feature is switched on, when the panel can manage the crontab; otherwise the Integrations card shows the line to add by hand. While the worker is not running and the feature is on, the panel says so in two places: the Integrations card and the background-workers banner. The worker is idle while the feature is off.

Set `ORBITRA_CRON_SELF_HEAL=0` in the environment when the crontab is managed from outside the panel.

## API and MCP

Actions: `link_checks_summary`, `link_checks_list` and `link_checks_log` for every signed-in user; `link_check_now`, `link_checks_settings`, `save_link_checks_settings`, `save_link_check`, `check_proxies`, `save_check_proxy`, `delete_check_proxy` and `test_check_proxy` for admins.

MCP tools: `orbitra_link_checks_summary`, `orbitra_link_checks_list`, `orbitra_link_check_now`.

The history of checks is kept for 30 days.
