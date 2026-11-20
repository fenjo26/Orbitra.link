
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
