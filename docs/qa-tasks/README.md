# Orbitra QA tasks — wave plan (2026-08-19, rev 2)

Previous batch (ORB-001…004) is done and archived in `_to_delete/`, with one
exception: ORB-001's fix was destroyed by another agent's `git checkout` and is
re-issued here as **ORB-009**.

## Rules for every session

- Each agent gets its **own git worktree**. Four agents in one working tree is
  what cost us ORB-001.
- **Never** run `git checkout <file>`, `git restore` or `git stash` to undo your
  own work. Revert by editing. A file-level revert takes other agents' changes
  with it.
- Verify by **running the thing**, not by grepping the source for a line you just
  wrote. The ORB-001 test passed against a file that had already been reverted.
- Stay inside the files your prompt lists. If the fix seems to need a file you
  don't own, stop and report.

## Waves

| wave | task | run | files |
|---|---|---|---|
| 1 | ORB-009 restore postback route + pixel path | alone, on `main` | index.php, postback.php |
| 2 | ORB-005 server IP detection | parallel | api.php domain handlers, core/server_ip.php |
| 2 | ORB-006 traffic log SUBID | parallel | api.php logs branch, LogsPage.jsx |
| 2 | ORB-007 client IP behind Cloudflare | parallel | index.php ~35, click.php, click_api.php |
| 3 | ORB-008 router.php dead code | alone | index.php, router.php, install.sh, nginx |

ORB-009 blocks everything — it restores the fix for the tester's actual blocker.
ORB-007 and ORB-008 both touch index.php, so they never run at the same time.

Worktrees for wave 2, created after ORB-009 is merged:

```bash
git worktree add ../orbitra-orb005 -b orb-005
git worktree add ../orbitra-orb006 -b orb-006
git worktree add ../orbitra-orb007 -b orb-007
```

No task in this batch needs a schema migration. `$LATEST_SCHEMA_VERSION` is 35
and stays 35.
