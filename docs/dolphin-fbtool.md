# Dolphin / Fbtool — cost intake (Keitaro Admin API)

Dolphin and Fbtool.pro have no tracker integrations of their own. Both services
do exactly one thing: send costs to Keitaro via its Admin API. So "Dolphin/Fbtool
support" in Orbitra is a compatible incoming endpoint that the services call,
believing they are talking to Keitaro:

```
POST https://your-tracker.com/admin_api/v1/campaigns/CAMPAIGN_ID/update_costs
Authorization: Bearer <API_KEY>
Content-Type: application/json

{
  "start_date": "2026-08-15",
  "end_date": "2026-08-15",
  "cost": 12.34,
  "currency": "USD",
  "timezone": "Europe/Berlin",
  "filters": { "sub_id_4": "120212558973560058" }
}
```

The response follows the Keitaro shape: `{"success":true, ...}` or an HTTP code + `{"success":false,"error":"..."}`.

The endpoint is implemented in `admin_api.php`; routing lives in `.htaccess` (Apache) and
`core/nginx_config.php` (nginx). Regenerating the nginx config after an update
picks up the location automatically.

---

## 1. Creating an API key

The endpoint accepts the tracker's personal keys (table `user_api_keys`) — the same ones
MCP uses.

1. An admin opens **⚙ → Users → API keys**.
2. Creates a key with **write** permissions (read-only keys get `403`).
3. The key is passed in the `Authorization: Bearer <key>` or `X-Api-Key: <key>` header.

## 2. Dolphin setup

Dolphin → **Settings → Cost export → Keitaro**:

- **Tracker URL**: `https://your-tracker.com`
- **API key**: the key from step 1
- **Matching**: by Adset ID (default) — then the `sub_id_3` filter will match the
  click's `adset_id` parameter automatically.

## 3. Fbtool.pro setup

Fbtool → **Costs → Keitaro**:

- **Admin panel URL**: `https://your-tracker.com`
- **API key**: the key from step 1
- Fbtool matches by `ad.id` and sends the `sub_id_4` filter — it will match the
  click's `ad_id` parameter automatically.

## 4. What must be configured on the campaign

Filters match against **click parameters** (`clicks.parameters_json`). The Facebook
traffic source template puts those parameters on each click: the campaign must receive
traffic with a link containing

```
ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}
```

In the campaign editor this is done by selecting the "Facebook Ads" source: the parameters
are filled in automatically (the "Parameters" tab, the link with macros).

## 5. How the cost is distributed

- The campaign's clicks over `[start_date .. end_date]`, narrowed by the filters.
- The filter key is tried as a parameter name as-is (`ad_id`, `adset_id`,
  `campaign_id`, …) plus the Keitaro FB-template defaults: `sub_id_3`→`adset_id`,
  `sub_id_4`→`ad_id`. Multiple filters are combined with AND.
- The sum is converted to the tracker currency (`core/CurrencyRates.php`) and split
  evenly across the matching clicks (flat CPC) — the same model used by
  `core/CostImporter.php`.
- Re-sending the same period **overwrites** the distribution instead of
  adding to it (the services send costs every 60–90 minutes).
- If no clicks were found, the sum is stored in `cost_records` under a hidden
  `external_api` connection without attribution — so the cost does not vanish silently.
  Check the filters and the campaign's parameters if the cost "never arrives".

## 6. Verification

```bash
curl -X POST 'https://your-tracker.com/admin_api/v1/campaigns/10/update_costs' \
  -H 'Authorization: Bearer <API_KEY>' \
  -H 'Content-Type: application/json' \
  -d '{"start_date":"2026-08-15","end_date":"2026-08-15","cost":10,"currency":"USD","filters":{"ad_id":"123456"}}'
```

The response `{"success":true,"clicks":N,...}` means the cost was recorded and distributed across N
clicks. `clicks:0` — the cost was parked without attribution: the filter matched no
click in the period.

---

*Added in v0.9.7.7.*
