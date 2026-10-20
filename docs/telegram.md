# Telegram bot

The bot sends conversion notifications and a daily digest, and answers commands with statistics. It speaks the same seven languages as the panel.

## Connecting

1. In Telegram, open `@BotFather`, run `/newbot` and get a token like `1111:AAABBB…`.
2. **⚙ → Integrations → Telegram Bot** — paste the token and press **Connect**.
3. On the same page, **Connect chat → Get code** and send `/start CODE` to the bot (or open the "Open bot" link). The code is one-time and valid for 15 minutes. The bot does not accept chats without a code; a chat can be disconnected on the same page.
4. Enable the notifications you need.

After `/start` the bot pins a keyboard menu: Stats, Top, Campaigns, Conversions, Notifications, Digest, Language, Help — no need to type the commands.

## How updates reach the bot

The panel picks the mode itself and shows it on the status card; you can also set it manually.

- **Webhook** — Telegram calls `https://<domain>/telegram_bot.php`. Works only over HTTPS, on a domain name, with a certificate Telegram trusts. The request is verified by the Telegram secret token.
- **Polling** — the `telegram_poll_cron.php` cron fetches updates once a minute. No inbound connection needed: the bot works on a bare IP, over `http://` and behind a proxy. The installer adds the cron:

  ```
  * * * * * php /var/www/orbitra/telegram_poll_cron.php --quiet >> /var/www/orbitra/var/logs/telegram_poll.log 2>&1
  ```

  When a webhook is active, the job exits immediately without doing anything.

If the bot is silent, the status card will show the mode, the error returned by Telegram itself (`getWebhookInfo`), and the poller's last run time. An empty time in "polling" mode means there is no crontab entry. Manual check:

```
sudo -u www-data php /var/www/orbitra/telegram_poll_cron.php --once
```

## Commands

| Command | What it does |
|---|---|
| `/stats` | Today's statistics; `/stats yesterday`, `/stats 1d`, `/stats 7d`, `/stats 30d` |
| `/campaigns` | Active campaigns, with a button for each |
| `/campaign ID` | Campaign details |
| `/top` | Top 5 campaigns by revenue today |
| `/conversions` | Last 10 conversions |
| `/notify on\|off` | Conversion notifications; without an argument — current state and a button |
| `/daily on\|off` | Daily digest |
| `/lang en\|ru\|uk\|es\|zh\|fr\|de` | Bot language; without an argument — choose with buttons |
| `/sources` | Traffic sources and the status of their URL check |
| `/checksources` | Check all sources' URLs |
| `/allow ID` | Allow one more chat by its ID |
| `/help` | Help and re-pinning the menu |

Commands without an argument change nothing: `/notify` shows the current state instead of turning notifications off.

## Notifications

- **On a conversion** — campaign, status, amount and currency, country with a flag, time.
- **Daily digest** — clicks, conversions, revenue, cost, profit and the top 3 campaigns. Send time is `telegram_daily_time` in the integration settings. The digest is sent by the poller (for webhook mode too), at most once a day.

## Telegram channels as offers — separate funnel bots

The admin bot is never the destination of a *Telegram channel* offer. Ad visitors talk to separate **funnel bots** (Admin → Telegram funnel bots), polled by their own worker; the admin bot keeps answering only the chats you admitted. See [External PWA & Telegram channels](external-pwa-telegram.md).

## Conversion alerts to channels

**Notifications → Telegram → Conversion alerts to channels** (the bot connection stays in Integrations → Telegram) sends chosen conversions to a channel or group through the admin bot: pick the chat (the bot must be an admin of the channel or a member of the group, press *Check*), the statuses (deposit, registration, sale, `tg_join`…), the campaigns, and an HTML template with macros — `{campaign}`, `{offer}`, `{status}`, `{payout}`, `{currency}`, `{country}`, `{flag}`, `{source}`, `{ad_campaign_name}`, `{adset_name}`, `{ad_name}` (ad-platform names from the cost sync), `{sub1}`–`{sub3}`, `{click_id}`, `{time}`, `{date}`. *Send test* posts sample values; the log keeps the last 200 sends per alert. The per-chat `/notify` switch keeps working as before.

