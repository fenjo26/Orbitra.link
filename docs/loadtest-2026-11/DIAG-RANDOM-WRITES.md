# DIAG — random page writes per click (ТЗ 1.9.8, measurement-only, no code changes)

Date: 2026-11-20. Follows DIAG_IO.md: the stand's disk does ~700 random 4K
IOPS (p50 46 ms) vs 600 MB/s sequential, and at 480 rps the checkpoint
transfer of WAL pages into the database is what saturates it (85–99% util,
711 w/s). This report measures HOW MANY DISTINCT database pages one click
dirties — that transfer is the product of clicks × distinct pages — and what
each candidate change would save. Schema untouched; all numbers from
lab databases with the REAL schema, prefilled to 2,000,000 clicks (stand
scale: every index 8k–27k pages) with `idx_clicks_ip_ua_created` built the
way the spool worker builds it on real installs.

Method: 2×10 000 measured clicks per variant (autocommit, realistic row
shape), unique page numbers parsed from the WAL frames after each batch,
attributed to b-trees via `dbstat`. Two IP patterns: **monotonic** (lab
artifact) and **shuffled pool** (real traffic) — the ip/ip_ua indexes differ
between them, everything else does not. Full lab: /tmp/dt197/pglab2.php,
pglab3.php.

*(Русская версия: `DIAG-RANDOM-WRITES-RU.md`.)*

## 1. Index inventory — key nature

| b-tree | key | nature |
|---|---|---|
| clicks (heap) | rowid | **monotonic** (append) |
| sqlite_autoindex_clicks_1 (PK) | id = UUIDv4 | **RANDOM** |
| idx_clicks_ip_ua_created | (ip, ua_hash, created_at) | **RANDOM** (ip leads) |
| idx_clicks_ip_created | (ip, created_at) | **RANDOM** (ip leads) |
| idx_clicks_ad_ref | (ad_ref) | **quasi-random** (per-ad append groups) |
| idx_clicks_campaign_created | (campaign_id, created_at) | near-monotonic (per-campaign append) |
| idx_clicks_safe | (campaign_id, is_safe_page, created_at) | near-monotonic |
| idx_clicks_created_at | (created_at) | monotonic |
| idx_clicks_created_date | (date(created_at)) | monotonic (one key per day) |

## 2. Baseline: distinct dirty pages per click (2M-row base, shuffled IPs)

| b-tree | dirty pages / 20k clicks | per click |
|---|---|---|
| **PK unique on UUIDv4** | **15 728** | **0.79** |
| idx_clicks_ad_ref | 5 821 | 0.29 |
| clicks heap (rowid append) | 4 014 | 0.20 |
| idx_clicks_ip_ua_created | 1 074 | 0.05 |
| idx_clicks_ip_created | 927 | 0.05 |
| idx_clicks_safe | 181 | 0.01 |
| idx_clicks_campaign_created | 176 | 0.01 |
| idx_clicks_created_at | 164 | 0.01 |
| idx_clicks_created_date | 113 | 0.01 |
| **TOTAL** | **~28.6k** | **1.95** |

Caveat on the two ip indexes: the lab recycles a 65k-IP pool, so a returning
IP appends next to its previous entries (group tails ≈ 1k pages). On the
stand's "uniques" profile nearly every IP is new → each insert lands in a
random leaf → their true contribution is up to **~1 page/click each**. The
1.95 total is therefore a LOWER bound for the stand; the realistic range is
**1.95–3.8 pages/click**.

Cross-check with DIAG_IO: baseline 1.95 × 4 KB = ~8 KB of random writes per
click ⇒ at 480 rps ≈ 3.8 MB/s ≈ 930 random IOPS — matches the observed
85–99% util / 711 w/s ceiling. With the stand-realistic upper estimate
(~3.8 pages) the transfer alone would need ~1 800 IOPS — the disk never had
a chance, which is exactly what rounds 3–4 showed.

WAL volume is a different axis: ~11.2 WAL frames/click (~44 KB) regardless
of key nature (a page rewritten in every commit costs a frame per commit
even if it is the SAME page). Ordering changes (uuid7) reduce the checkpoint
transfer, NOT the WAL volume; dropping indexes reduces both.

## 3. What each index is for (what breaks without it)

| index | used by | without it |
|---|---|---|
| PK (id) | conversions/postbacks/pixel/replay joins by click_id — everywhere | nothing to discuss: keep (shape can change) |
| ip_ua_created | debounce (2 s), uniqueness IP_UA, ClickFlags probes — core/click_logger.php:269,296 | probes degrade to scans; uniqueness window wrong under load. NOT droppable without a replacement (APCu covers only the 2 s debounce, not the 24 h uniqueness) |
| ip_created | IP-only uniqueness — click_logger.php:424 | the probe can run on the ip_ua prefix (ip = ?) + filter — per-IP row counts are tiny for unique traffic; low risk |
| ad_ref | creative library aggregation (migration 63: json_extract per row was too slow — the aggregate is the only reader path) | creative reports/cost join degrade to full scans; high risk |
| safe (campaign_id, is_safe_page, created_at) | report filtering of safe pages | planner can use campaign_created + filter; low risk, verify with EXPLAIN QUERY PLAN on a large base |
| campaign_created | campaign reports, rotation optimiser, admin_api | core for reports; keep (near-monotonic — cheap anyway) |
| created_at | cleanup_cron retention deletes, time-range reports | keep (monotonic — free) |
| created_date | admin_api + CostImporter date() filters | keep (monotonic — free) |

## 4. Variants — measured savings (shuffled-IP batches)

| variant | pages/click | vs baseline | frames/click | risk |
|---|---|---|---|---|
| baseline (uuid4, all) | **1.95–1.98** | — | 11.2 | — |
| **uuid7-shaped id** (PK monotonic) | **0.97–1.00** | **−50%** | 11.2 | none visible: id stays a 36-char TEXT UUID; external {clickid} macros unchanged |
| drop ip_created | 1.87 | −4% (lab; up to −1 page on stand-unique IP) | 10.1 | low (ip_ua prefix probe) |
| drop ip_ua_created | 1.86 | −5% (lab; up to −1) | 10.1 | high without a uniqueness replacement |
| uuid7 + drop ad_ref | 0.43–0.45 | −78% | 10.3 | high (creative library) |
| uuid7 − ip_created − ip_ua | 0.80 | −59% | 8.7 | high (uniqueness) |
| **uuid7 − ip_created − ip_ua − ad_ref** | **0.25** | **−87%** | 7.5 | high (see above) |

Reading for the disk: the checkpoint's random-write demand scales with
pages/click — uuid7 alone brings 480 rps from ~930 IOPS to ~470 (inside the
700 budget); the full combo to ~120. The WAL volume (frames/click) only
drops with fewer indexes (11.2 → 7.5, −33%).

## 5. Migration notes (for the decision, not implemented)

- **uuid7 id**: new clicks get time-prefixed ids; old uuid4 rows stay (the
  index stays mixed, but INSERTS all land in the monotonic right edge — the
  benefit is immediate, no rewrite needed). Zero downtime, one function
  change + tests. External format identical (36 chars).
- **drop ip_created**: rewrite the IP-only probe to the ip_ua prefix;
  EXPLAIN QUERY PLAN check; drop the index in a spool-worker migration
  (like migration 55 did for the build).
- **debounce via APCu**: only covers the 2 s debounce (key ip|ua hash,
  TTL 2 s); the 24 h uniqueness still needs the index. APCu adds an
  extension dependency — check the install base first.
- **ad_ref**: if the creative library must stay fast, alternatives are a
  partial index on recent rows or accepting the scan on historical ranges;
  needs its own measurement before touching.

## Bottom line

One line: **half of every click's random-write cost is the UUIDv4 primary
key alone (0.79 of 1.95 pages/click measured, −50% total), and the cheap
first move for 1.9.8 is a time-ordered id; index drops buy more only with
report trade-offs.** No code was changed for this report.
