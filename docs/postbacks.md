# Postbacks and conversions

A conversion reaches Orbitra as a postback from the affiliate network and is attached to a click by its ID (`subid`). The tracker can then forward it to the traffic source (an outgoing S2S postback) and to the ad platform's Conversions API.

## Incoming postback

The postback address is **⚙ → Postback URL**. It looks like this:

```
https://your-domain/<postback_key>/postback
```

`postback_key` is the installation's secret key (protection against fake conversions). If it still equals the public default value from the repository, the panel warns you; generate a new one on the same page or with `php cli/generate_postback_key.php`. The installer generates its own key.

### Parameters

| Parameter | Required | Meaning |
|---|---|---|
| `subid` | yes | Tracker click ID — what the network received in `{subid}` / `{clickid}` |
| `status` | no | Conversion status: `lead`, `sale`, `rejected`, `registration`, `deposit`, `trash`, or any custom one |
| `payout` | no | Payout amount |
| `currency` | no | Payout currency |
| `tid` | no | Transaction ID — for repeated conversions on the same click |
| `sub_id_N` | no | Additional values |
| `<type>_status` | no | Map statuses right in the URL: `sale_status=approved,confirmed` |

Examples:

```
…/postback?subid={subid}&status=lead
…/postback?subid={subid}&status=sale&payout={payout}
…/postback?subid={subid}&status=sale&payout={payout}&tid={transaction_id}
…/postback?subid={subid}&status={status}&sale_status=approved,confirmed
```

If the network names its parameters differently, set up **parameter aliases** on the same page.

### Responses

`200` — the conversion is recorded. `4xx` — the request is rejected (wrong key, unknown click). `500` / `503` — a write error; the network should retry the postback. The conversion, its counters and the Conversions API events are written in a single transaction: an error can never produce a "200 and a lost event".

### How the status is determined

In order:

1. **Conversion types** from **Settings → Conversion Types** — if the status is in a type's value list.
2. A built-in type with the same name.
3. The `<type>_status=…` rule from the postback itself.
4. **Built-in aliases**: `approved`, `confirmed`, `accepted`, `converted` → `sale`; `declined`, `refused`, `cancelled`, `canceled` → `rejected`.
5. Otherwise — `custom`.

Statuses that did not match any type are listed under **Settings → Conversion Types → Unmapped statuses**: you can map them to a type there, and already recorded conversions get reclassified.

### Conversion types

Each type has: a name, a list of statuses from the network, a label color, and four flags — counts toward "Conversions", counts toward "Profit", send S2S to the source, counts toward the offer Cap. A **Revshare** type exists out of the box (the `revshare` status goes into profit only: no new conversion, no S2S, no Cap).

### Payouts in another currency

The payout is converted to the tracker's base currency (rates in `core/CurrencyRates.php`). The original amount, currency and rate are stored on the click. The outgoing postback macros `{payout}` / `{currency}` carry the value in the base currency; the original ones are `{fx_orig_payout}` / `{fx_orig_currency}`. An unknown currency pair is recorded as is.

### Incoming postback log

**⚙ → Logs → Postbacks** — every incoming request with its outcome: whether the click was found, the resulting status, the amount. The click ID opens the click card.

## Outgoing S2S postbacks (to the traffic source)

Configured in the campaign, on the **S2S Postbacks** tab. Each postback is a card: URL, GET or POST method, status chips (`sale`, `lead`, `rejected`, `registration`, `deposit`, `trash` plus custom ones). The "insert macro" menu inserts a macro at the cursor position.

If the selected traffic source has a `postback_url` set, a campaign with an empty list receives it automatically. If postbacks are already configured, a hint with an "add" button appears at the top of the tab.

The **status filter** passes a conversion through if the internal status matched (`sale`), the network's own word (`approved`), or the legacy `custom` chip for words that are now recognized by aliases.

### Macros

`{subid}` (also `{clickid}`, `{click_id}`), `{status}`, `{payout}` (also `{conversion_revenue}`), `{currency}`, `{tid}` (also `{external_id}`), `{campaign_id}`, `{offer_id}`, `{cost}`, `{revenue}`, `{profit}`, and any saved click parameter by name: `{sub_id_1}` … `{sub_id_30}`, `{fx_orig_payout}`, `{fx_orig_currency}`, etc.

`{status}` carries the internal status (`sale`), not the network's original word. To translate it into the source's vocabulary, use a Keitaro-style transformation: `{status: sale=purchase lead=lead}` — a status not in the list goes through unchanged.

### Delivery queue

Outgoing postbacks are not sent at the moment of conversion — they go into the `s2s_postbacks_log` queue, and the `postback_queue_cron.php` cron (once a minute) delivers them with retries:

- success — HTTP 2xx–3xx;
- retries: 60 s → 5 min → 30 min → 2 h → 24 h, after which the row is marked `failed`;
- SSRF check on enqueue and before sending: an address in a private network gets `failed` immediately;
- a row left in `in_flight` by a crashed worker returns to the queue after 10 minutes.

> ⚠️ Without the cron, the queue is never processed. The installer sets it up; to check or add it manually — **Settings → Automation → Postback Queue**. Updating through the panel also restores a missing cron.

Queue log: **⚙ → Logs → S2S** (status, attempts, next attempt, last error, HTTP code).

### Postback tester

The button on the **S2S Postbacks** tab (admin only) sends a real postback to this same server with a one-off click and shows a verdict for every step:

- which status the conversion was recorded with;
- whether each S2S postback's status filter passes it — and why: by internal status, by the network's word, or by the legacy chip;
- a trial delivery to the source: HTTP code and timing;
- macros that would not resolve and would go to the source as plain text (`{external_id}`, `{sub_id_N}`);
- placeholders from the source template (`bbg=xxx`, `[YOUR_…]`);
- whether the queue worker is alive and the cron is installed.

The test click, conversion and queue rows are deleted after the check.

### Re-sending missed conversions

The **"Re-send missed conversions"** button enqueues conversions from the last 72 hours that pass the current filter but never made it into the queue. Pressing it again duplicates nothing.

## Conversion import

**⚙ → Conversion Import** — upload a file or paste a list of click IDs when the network cannot send postbacks, or when they need to be restored.

## Conversions API

Sending conversions to Meta and TikTok servers is configured in the pixel profile and in the campaign (the "Integrations" tab). For details on Meta, see [Facebook / Meta](facebook.md). Events are enqueued together with the conversion and delivered with confirmation: success only on a 2xx response where `events_received` matches the batch size.

## Monitoring

`conversion_monitor_cron.php` computes the failed-postback share over a window (60 minutes by default) and, when the threshold (10%) is exceeded, writes an alert to `var/logs/conversion_alerts.log`; sending the alert to Telegram is not implemented yet. The installer does not set up this cron — add it if needed:

```
*/5 * * * * php /var/www/orbitra/conversion_monitor_cron.php --quiet
```
