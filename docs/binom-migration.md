# Migrating from Binom

Orbitra pulls settings straight from a Binom v2 tracker over its Public API — affiliate networks, offers, landing pages, domains, traffic sources, and campaigns with their whole traffic structure (paths, rules, weights). Click statistics are not transferred: this is a fresh start.

## What gets transferred

| Binom | Orbitra | What is preserved |
|---|---|---|
| Affiliate networks | Affiliate networks | Name (archived flag) |
| Offers | Offers | URL, payout, auto-payout, geo, network |
| Landing pages | Landing pages | Name, URL |
| Domains | Domains | Host, HTTPS flag |
| Traffic sources | Traffic sources | Name, postback URL, token macros |
| Campaign groups | Campaign groups | Name |
| Campaigns | Campaigns | Name, **tracking key (as Click API token)**, cost model and value, rotation mode, domain, group, source, deleted state |
| `defaultPaths` | Streams (fallback) | Position, weight, landings + offers with weights |
| `rules` | Streams (regular, with filters) | Criteria `country` / `language` / `device_type` → filters, `is` / `is_not` → allow / deny |
| Direct-URL offers | Offers | Created as real offers (`[Binom] direct URL offer`), weights preserved |

Not transferred: clicks and conversions (Binom's API exposes neither as rows), campaign postbacks, SMART hide-referrer modes (imported with the default redirect — see the report). Rule criteria with no Orbitra equivalent are counted in the import report, never dropped silently. A `markAsBot` rule is imported as an ordinary stream and noted in the report.

## 1. Get the API key

In the Binom panel: **Settings → User** — every user has its own key. Read permission is enough for the import; Binom recommends a dedicated restricted user for third-party access, and so do we.

The tracker URL and the key are entered in Orbitra per import and are never stored on the Orbitra side.

## 2. Import

Admin → Migrations → **Import from Binom (API)**:

1. Enter the tracker URL (e.g. `https://track.example.com`) and the API key.
2. Press **Test connection** — Orbitra reports the timezone and how many networks, offers, landings, domains, sources and campaigns it can see.
3. Pick what to import. Campaigns bring their paths and rules along as streams; a preview runs the whole pull in dry-run mode and writes nothing.
4. Press **Import**.

A repeated import updates instead of duplicating: entities are matched by their Binom ids (`binom_id` columns) and by natural keys (offer URL, landing URL, domain name, campaign token).

## Notes on mapping

- **Rotation.** `distributionType` `NORMAL` / `SMART_PATHS*` → weighted rotation; `TOP_TO_BOTTOM` / `FIX_ON_PATH*` → position-based.
- **Order.** Binom evaluates rules first, then the default paths; Orbitra streams get the same order (rules at early positions, default paths pushed to the end as fallback streams).
- **Aliases.** Binom has no campaign aliases, so Orbitra generates one from the campaign name and keeps it unique.
- **Tokens.** The Binom campaign `key` becomes the Orbitra campaign token, so postback URLs built around the old key keep resolving.
