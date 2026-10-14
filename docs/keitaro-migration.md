# Migrating from Keitaro

Orbitra transfers settings from Keitaro — affiliate networks, offers, domains, campaigns with their Click API tokens, streams with filters, campaign postbacks, landing pages, and sources. Click statistics are not transferred: this is a fresh start.

## What gets transferred

| Keitaro | Orbitra | What is preserved |
|---|---|---|
| Affiliate networks | Affiliate networks | Postback URL, offer parameters |
| Offers | Offers | URL, payout, geo |
| Domains | Domains | Parking, HTTPS-only |
| Groups | Campaign groups | Name |
| Campaigns | Campaigns | Alias, **Click API token**, state, source |
| Streams | Streams | Type, position, weight, schema, action, filters (including `sub_id_N` and parameter filters), "allow / deny" modes, associations with offers and landing pages |
| Campaign postbacks | S2S Postbacks | URL, method, statuses |
| Landing pages | Landing pages | Names and URLs |
| Sources | Sources | Parameters |

Not transferred: clicks and conversions, users, local landing files (only records about them). Filters that cannot be transferred are listed in the import report.

Keitaro's source and affiliate-network templates are already built into Orbitra — there is no need to transfer them ([Traffic sources and affiliate networks](sources-and-networks.md)).

## 1. Dump on the Keitaro server

Connect to the Keitaro server over SSH as root and run the command in its entirety. It takes database credentials from `/etc/keitaro/env/inventory.env`, works both with a MariaDB client on the host and with MariaDB inside a Keitaro Docker container, dumps only the settings tables (no click logs), and refuses to run if the tables are not found — so you never accidentally dump the whole database.

```bash
bash <<'ORBITRA_DUMP'
set -eo pipefail

# Sourced before set -u: the env file is Keitaro's, not ours.
source /etc/keitaro/env/inventory.env
set -u

# Password goes through the environment, never through argv or a file left on disk.
export MYSQL_PWD="$MARIADB_KEITARO_PASSWORD"
DB="$MARIADB_KEITARO_DATABASE"

# MariaDB client on the host, or inside the Keitaro MariaDB container.
if CLI=$(command -v mariadb || command -v mysql) && DUMP=$(command -v mariadb-dump || command -v mysqldump); then
  sql()  { "$CLI"  -u"$MARIADB_KEITARO_USER" -h127.0.0.1 -P3306 --protocol=tcp "$@" </dev/null; }
  dump() { "$DUMP" -u"$MARIADB_KEITARO_USER" -h127.0.0.1 -P3306 --protocol=tcp "$@" </dev/null; }
else
  CT=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i -m1 -E 'mariadb|mysql' || true)
  if [ -z "$CT" ]; then
    echo "ERROR: no MariaDB client on this server and no MariaDB container found" >&2
    exit 1
  fi
  echo "Using MariaDB inside container: $CT"
  sql()  { docker exec -e MYSQL_PWD "$CT" sh -c 'exec $(command -v mariadb || command -v mysql) "$@"' sh -u"$MARIADB_KEITARO_USER" "$@" </dev/null; }
  dump() { docker exec -e MYSQL_PWD "$CT" sh -c 'exec $(command -v mariadb-dump || command -v mysqldump) "$@"' sh -u"$MARIADB_KEITARO_USER" "$@" </dev/null; }
fi

# Settings tables only: campaigns, offers, domains, streams... - no click logs.
TABLES=$(sql -N "$DB" -e "
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'keitaro_affiliate_networks', 'keitaro_groups', 'keitaro_offers',
    'keitaro_domains', 'keitaro_campaigns', 'keitaro_campaign_postbacks',
    'keitaro_landings', 'keitaro_streams', 'keitaro_stream_filters',
    'keitaro_stream_offer_associations', 'keitaro_stream_landing_associations',
    'keitaro_traffic_sources', 'keitaro_ref_sources')
ORDER BY table_name" | tr '\n' ' ')

# Without a table list mysqldump would dump the WHOLE database, click logs included.
if [ -z "$(echo $TABLES)" ]; then
  echo "ERROR: no Keitaro tables found in database $DB" >&2
  exit 1
fi

OUT=/root/keitaro_orbitra_full.sql.gz
echo "Dumping tables: $TABLES"
dump --single-transaction --quick --skip-lock-tables "$DB" $TABLES | gzip -1 > "$OUT"

ls -lah "$OUT"
echo "DONE: $OUT"
ORBITRA_DUMP
```

At the end you will see `DONE: /root/keitaro_orbitra_full.sql.gz`. The file is usually 1–5 MB.

## 2. Download the file

On the **⚙ → Migrations** page, enter the Keitaro server's IP — the panel will fill it into ready-made commands. Run the command on your computer, not on the server:

```bash
# macOS / Linux
scp root@IP_KEITARO:/root/keitaro_orbitra_full.sql.gz ~/Downloads/
```

```powershell
# Windows (PowerShell)
scp root@IP_KEITARO:/root/keitaro_orbitra_full.sql.gz $env:USERPROFILE\Downloads\
```

## 3. Import into Orbitra

**⚙ → Migrations → Import from Keitaro (SQL dump)**:

1. Choose a `.sql.gz` or `.sql` file. The dump is not executed as SQL — it is only parsed.
2. Check what to import. **Keep campaign IDs** (Orbitra ID = Keitaro ID) only works on a clean installation where the campaign table is empty, including the archive.
3. **Show preview** — a dry run without writing: how many objects of each type will be imported and which filters do not transfer.
4. **Import into Orbitra**.

For repeated imports on a test server there is a **Cleanup** block: it deletes the tracker's entire configuration (domains, offers, affiliate networks, campaigns, streams, postbacks, pixels, sources, landing pages, groups) without touching statistics or users. You must type `DELETE` to confirm.

## 4. After the import

- **Tokens.** Open a couple of campaigns — the Click API token should match Keitaro's. Then the integration scripts on your sites (`click.php`, KClient) keep working; you only need to change the tracker address in them.
- **Domains.** Point the domains' A records at the new server (or connect Cloudflare / Namecheap / Dynadot — the records will be set automatically) and wait for the certificates ([Domains and SSL](domains.md)).
- **Affiliate network postbacks.** The Postback URL in Orbitra is different: `https://your-domain/<postback_key>/postback?…`. Update it in the networks' dashboards ([Postbacks](postbacks.md)).
- **Geo database.** Without it, country filters do not work — check **⚙ → Geo databases**.
- **Routing check.** The campaign test link with `&_dbg=1` shows which stream is selected and why, without recording a click ([Campaigns and streams](campaigns.md#test-link)).

## Common problems

| Symptom | Cause and solution |
|---|---|
| The dump command says `no Keitaro tables found` | Wrong database in `inventory.env` or a non-standard Keitaro installation. Check `MARIADB_KEITARO_DATABASE` |
| `no MariaDB client … and no MariaDB container found` | No MariaDB client and no running MariaDB container — start Keitaro or install the client |
| The import found nothing | The dump is empty or from the wrong database — check the file size and the `Dumping tables:` line in the command output |
| Streams were not transferred | The "Import streams" option was not checked, or the dump lacks `keitaro_streams` and the association tables |
| The domain answers 404 | DNS does not point at the new server yet, or the domain has no Index campaign assigned |
