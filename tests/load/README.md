# Load test (k6)

Reproduces the 2026-09-25 stress test (method of cpa.rip "Binom vs Keitaro"): constant-arrival-rate steps, 5 s timeout, no redirect following, then reconciles every request against `clicks` (and the spool).

Needs on the tracker host: `k6`, `sqlite3`, `python3`. Run it on a test server, never on a production tracker — it writes real click rows.

1. Create a campaign with alias `loadtest`: one regular stream → offer redirect, uniqueness 24h IP_UA. Note its id.
2. `CAMPAIGN_ID=<id> bash tests/load/run.sh A unique 60s 20 40 80 160 320 480`
3. Returning visitors (50 IP/UA pairs, the case that broke Keitaro): `CAMPAIGN_ID=<id> bash tests/load/run.sh A repeat 60s 480`

Columns: `sent` requests, `ok302` good redirects, `fail` errors/timeouts, `dropped` k6 could not keep the rate, `db` rows found in `clicks`, `spool` rows parked in `var/spool/clicks.log`, `cpu` whole-host CPU (k6 included).
In `repeat` mode `db` < `sent` is expected: the 2 s debounce collapses repeats by design.

Visitors come with random public IPs through `X-Forwarded-For` (leftmost public IP is trusted by `orbitraClientIp()`); private ranges are avoided on purpose — they fall back to REMOTE_ADDR and all collapse into one IP.

For an aged database, bulk-insert history first (3M rows ≈ 1.8 GB, ~75 s on a test copy):

```sql
PRAGMA synchronous=OFF;
WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i < 3000000)
INSERT INTO clicks (id, campaign_id, ip, user_agent, referer, country, country_code, device_type, os, browser, language,
                    parameters_json, created_at, uniq_campaign, uniq_stream, uniq_global, is_bot, is_proxy)
SELECT lower(hex(randomblob(16))), (i % 40) + 1,
  ((abs(random()) % 200)+20)||'.'||(abs(random())%255)||'.'||(abs(random())%255)||'.'||(abs(random())%254+1),
  'Mozilla/5.0 (Linux; Android 14) Chrome/124.0 Mobile v'||(i%997), 'https://src.example/p?'||i,
  'Greece','GR','mobile','Android','Chrome','el',
  '{"sub1":"hist'||i||'","sub2":"zone'||(i%500)||'"}',
  datetime('now', '-'||(abs(random()) % 2592000)||' seconds'), 1,1,1,0,0
FROM n;
```
(`campaign_id` 1..40 must exist; adjust to your test DB.)
