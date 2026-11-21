# ТЗ 2.1 — Load-stand acceptance checklist

Branch `tz-2.1`, stage gate before any merge to `main`. The stand is the
mon-series rig (480 rps sustained, the 2.0.2 workload profile).

## Baseline (inherited from v2.0.2)

- [ ] 480 rps × 15 min: 0 errors, p99 not worse than 2.0.2.
- [ ] Click spool cron ticking every minute during the run; spool never
      grows unbounded.
- [ ] Memory: RSS flat over the run (no per-click leak in the new trigger
      path — conversions are rare but nonzero on the stand profile).

## Stage 1 additions (freeze + cleanup under load)

- [ ] **WRITER hold during an hour freeze under 480 rps.** Drive the freeze
      cron (it rides the spool cron) with a raw window shortened so hours
      freeze during the run; measure the BEGIN IMMEDIATE duration of
      `orbitraStatsCommitFrozenHour` (add a µs timer around it, log to the
      freeze state file). Target ≤ 100–200 ms (local 1 M-click hour:
      median ~260 ms on a noisy laptop, 88 ms warm isolated; the stand
      number is the binding one). Click p99 must not regress while freezes
      run.
- [ ] **Long reader vs the quiet WAL fold.** The freeze's read step holds a
      WAL snapshot for ~4.6 s per 1 M-click hour. Verify the post-load
      quiet fold (WAL RESTART+TRUNCATE after traffic subsides) still
      happens on time: the freeze read must not push the quiet-tier into
      starving. Watch wal_checkpoint.json: natural/quiet restart counters
      and the -wal file size after the run.
- [ ] Migration on a multi-million-click database: the conversions rebuild
      (batched copy + swap) runs under traffic with clicks writing without
      errors; the watermark is parked until it finishes; the panel's
      Statistics rollup component shows the rebuild state.
- [ ] Cleanup under load deletes only below the watermark; converted
      clicks survive; conversions never disappear.
- [ ] Reports over a frozen+raw period return identical numbers before and
      after the cleanup pass that deletes the unconverted raw clicks.

## Stage 2 additions (reports across the seam)

- [ ] Equivalence on the stand's real data: every migrated surface
      (dashboard cards, chart, campaign list) over a seam-crossing period
      matches the raw-only numbers captured before the freeze, to 1e-6 on
      money.
- [ ] The "available for the last N days" availability marker is present
      for raw-only report dimensions over old periods.

## Release gate

- [ ] Full suite green; check:i18n green; dist rebuilt.
- [ ] CHANGELOG + What's New entries (unique-clicks semantics change is
      user-visible: flags-based, CHANGELOG entry required).
