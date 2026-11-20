
## 6. Step-1 decision measurement (the CPA.RIP uniques profile, added same day)

Every click a FRESH random public IP (no repeats), UA from a pool of 41,
on a 2M-row base with UNIFORMLY RANDOM prefilled IPs (the earlier templates
recycled a 65k pool, which understated the ip indexes; this base fills the
whole address space — the honest stand shape). Per-index pages/click:

| variant | pages/click | dominant contributors |
|---|---|---|
| baseline (uuid4, all indexes) | 2.11 | PK 0.79, ad_ref 0.29, heap 0.20, ip_ua 0.16, ip 0.16 |
| **b) uuid7 + no ip_created (decided)** | **1.82–1.83** | **ip_ua 0.82**, ad_ref 0.29, heap 0.20, PK 0.01 |
| c) b + INTEGER hash(ip\|ua) instead of TEXT ip_ua | 1.29–1.61 (stationary ~1.3) | hash-idx 0.47, ad_ref 0.29, heap 0.20 |

The decided variant (b) lands at **~1.8 pages/click** — above the 1.3
target, so the ip_ua compaction proposal: a single INTEGER column
`ipua_hash = crc32(ip . '|' . user_agent)` indexed as `(ipua_hash,
created_at)` instead of the TEXT (ip, ua_hash, created_at). Measured: the
hash index is 16 257 pages vs 26 533 for the text one (−39% size — better
page-cache residency) and its random-leaf churn is 0.47 vs 0.82 pages/click
(−0.35). Probes change shape: `WHERE ipua_hash = ? AND created_at >= ?`
with the exact ip/ua re-check in PHP (the pattern the legacy ua-probe
branch already uses — a crc32 collision only costs a candidate row, never
a wrong verdict). Migration = backfill in spool-worker batches (the
migration-55 recipe). NOT implemented in step 1; numbers above are the
justification for a possible step 2.

## 7. EXPLAIN QUERY PLAN — retiring idx_clicks_ip_created (migration 82)

2M-row random-IP base, every consumer of the retired index:

| query | before | after (dropped) |
|---|---|---|
| uniqueness IP-only (`ip=? AND created_at>=? AND campaign_id=?`) | SEARCH USING INDEX idx_clicks_ip_created (ip=? AND created_at>?) | SEARCH USING INDEX **idx_clicks_ip_ua_created (ip=?)** |
| ClickFlags global (`ip=? AND created_at>=datetime(...)`) | SEARCH COVERING INDEX idx_clicks_ip_created | SEARCH **COVERING** INDEX idx_clicks_ip_ua_created (ip=?) |
| ClickFlags campaign-scoped | SEARCH idx_clicks_ip_created | SEARCH idx_clicks_ip_ua_created (ip=?) |
| ClickFlags stream-scoped | SEARCH idx_clicks_ip_created | SEARCH idx_clicks_ip_ua_created (ip=?) |

No SCAN anywhere after the drop; the IP-only uniqueness probe's WHERE was
reordered (ip/created_at first) so the planner binds the index prefix
cleanly. Fresh installs no longer build the retired index (the historical
CREATE in the pre-55 block is retired with a pointer to migration 82).

## 8. Arithmetic audit — the full per-b-tree breakdown (corrects §6's baseline)

The §6 "baseline 2.11" was measured on a base prefilled from the COUNTER IP
pattern (only the measured clicks had random IPs): in such a base the two
ip-index b-trees hold a narrow key band, and fresh random inserts
concentrate their dirt instead of spreading it — the baseline was
understated by ~1.6 pages/click. Variant b's 1.82 was unaffected (its
remaining random index sees random measured keys either way). This section
re-measures BOTH variants on identically-shaped bases (2M rows, uniformly
random IPs, uuid4/uuid7 ids), with per-BATCH attribution — the checkpoint
transfers each batch separately, so per-batch distinct pages / batch size is
the honest pages/click (§6's union-then-divide-by-2× undercounted pages that
repeat across batches, e.g. the ad_ref group tails). Interior pages split
out; freelist shown explicitly (0 in both — INSERTs free nothing; the 17 936
freelist pages in variant b are the dropped ip_created index).

Baseline (uuid4, all indexes) — per click, mean of two 10k batches:

| b-tree | leaf | internal |
|---|---|---|
| idx_clicks_ip_ua_created | **0.980** | 0.035 |
| PK (sqlite_autoindex_clicks_1, uuid4) | **0.960** | 0.032 |
| idx_clicks_ip_created | **0.941** | 0.029 |
| idx_clicks_ad_ref | **0.543** | 0.004 |
| clicks (heap, rowid append) | 0.200 | 0.001 |
| safe / campaign / created_at / created_date | 0.009 / 0.009 / 0.008 / 0.006 | 0.001 each |
| sqlite_schema | — | 0.000 |
| **TOTAL (sums to the measured value)** | | **batch1 3.744–3.772, batch2 3.765–3.773** |

Variant b (uuid7 + no ip_created) — the decided step 1:

| b-tree | leaf | internal |
|---|---|---|
| idx_clicks_ip_ua_created | **0.995** | 0.036 |
| idx_clicks_ad_ref | **0.543** | 0.004 |
| clicks (heap) | 0.200 | 0.001 |
| PK (uuid7 → right edge) | 0.013 | 0.001 |
| safe / campaign / created_at / created_date | 0.009 / 0.009 / 0.008 / 0.006 | 0.001 each |
| **TOTAL** | | **batch1 1.822, batch2 1.831** |

The audit closes: baseline − b = 3.75 − 1.83 = **1.93 = PK leaf+internal
(0.99) + ip_created leaf+internal (0.97)**, exactly the two contributors the
step-1 change removes. Every row is per-batch; the sums equal the measured
totals digit-for-digit (0 pages unattributed in both runs).

Other tables a click writes (from the code, the CPA.RIP profile): the click
path's only per-click INSERT is `clicks`. `meta_matching_keys` is written
once per install (first click that mints a matching token; cached in a
WeakMap after), `click_browser_checks` only when a check actually ran,
`system_logs` only on errors, `session_replays`/`pwa_screen_views` only with
recording armed, `settings` only on config changes — all ≈ 0 per click in
this profile, which the attribution confirms (nothing outside the
clicks-family appears).

Reading for the disk: the corrected baseline is 3.75 pages × 4 KB × 480 rps
≈ 7.2 MB/s ≈ **1 760 random IOPS** — the stand's 700-IOPS disk was never in
the game, exactly as DIAG_IO showed. Step 1 alone cuts it to 1.83 × 4 KB ×
480 ≈ **860 IOPS** — still above budget; the remaining random contributors
are ip_ua (0.99) and ad_ref (0.54), which is what the §6 step-2 proposal
(ipua_hash INTEGER, measured 0.47/click) and a possible ad_ref follow-up
would address.
