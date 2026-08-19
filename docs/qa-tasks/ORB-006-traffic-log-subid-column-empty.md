# ORB-006 — The SUBID column in Logs → Traffic is hardcoded empty

- **Severity:** Medium
- **Run:** wave 2, worktree `../orbitra-orb006`, branch `orb-006`
- **Owns:** `api.php` (case `'logs'`, traffic branch only), `frontend/src/components/LogsPage.jsx` (traffic table only), `tests/traffic_log_subid_test.php`
- **Do not touch:** `index.php`, `postback.php`, `config.php`, `api.php` domain handlers, `Domains.jsx`, locales
- **No migration**

Small task. If it grows past these files, you have misread it.

## Problem

`api.php`, `case 'logs'`, traffic branch (~line 6222) selects a literal:

```sql
    o.url as redirect_url,
    '' as subid          -- always empty
FROM clicks cl
```

So the column shows `-` on every row of every install. This is not cosmetic:
the tester used this column to check whether the sub id had arrived, concluded
it had not, and the investigation went the wrong way for a day. Click Details
displays the same data correctly, which proves the value is stored and only the
log query is wrong.

## Fix

Select the real `sub_id_1` from `clicks.parameters_json`. SQLite has JSON1 on
the versions `install.sh` provisions:

```sql
COALESCE(json_extract(cl.parameters_json, '$.sub_id_1'), '') as subid
```

Handle NULL and malformed `parameters_json` on older rows without breaking the
query — if JSON1 cannot be guaranteed, decode in PHP after the fetch instead.

The header means a sub dimension, so `sub_id_1` is the right reading; the
tracker click id already has its own column beside it.

## Acceptance

- [ ] A click sent with `sub_id_1=abc` shows `abc` in Logs → Traffic.
- [ ] A click with no sub ids shows `-`, not an error.
- [ ] A click with NULL or malformed `parameters_json` shows `-` and the query still returns.
- [ ] The value matches Click Details for the same row.
- [ ] `tests/traffic_log_subid_test.php` covers present / absent / malformed.
