# FB Comment Cleaner

Hides (or deletes) spam comments under your Facebook ad posts on a schedule. Dry-run by default, hide by default — both because hide is reversible.

## Wiring

- **Token**: a user or System User token on the existing Facebook cost connection. The cleaner resolves page tokens through `/me/accounts` (cached 24 h) — comment moderation needs the *page* token, not the user's. Required permissions: `pages_read_user_content`, `pages_manage_engagement`.
- **Posts**: nothing to configure. The creative sync writes every ad's `creative.effective_object_story_id` into `comment_posts` — the post list is a by-product of the cost sync.
- **Worker**: `cli/comment_cleaner_cron.php` (install.sh schedules it every 10 minutes). Reads new comments since the last check (`filter=stream&order=reverse_chronological&since=…`), applies the rule set, writes the journal, reschedules each post: new comments reset the delay, quiet posts back off exponentially (15 min → doubling, cap 24 h), ads that stopped running are checked once a day.

## Rule sets

One rule set serves several FB connections. Checks run in a fixed order — stop words → admin regexes → links → contacts → emoji-only → char repeats → min length — and **exceptions always win**: author id (`id:123`), author name (`name:…`), or a phrase. The page's own comments are never touched.

- **Stop words** (≤500, one per line) match on the *normalized* text: lowercase, Cyrillic/Greek homoglyphs folded to Latin (`sсаm` with a Cyrillic с still matches `scam`), leetspeak decoded (`fr33 b0nus` → `free bonus`), zero-width characters removed. The *whole word* mode wraps each word in boundaries — `кид` does not fire on `кидай мяч` (the exact trap adset.pro falls into).
- **Regexes** (≤50) are admin-only — a non-admin saving the set gets them stripped. Each pattern runs on a 500-char subject with a lowered backtrack limit: that is the timeout against catastrophic backtracking.
- **Links** are detected on the *raw* text — `spamlink .com` with a space before the dot still fires, messenger deep links (`t.me/…`, `wa.me/…`) count as links. The whitelist (domains, one per line) covers subdomains.
- **Contacts**: phones (7–15 digits with separators), e-mails, and messenger invitations (`DM me`, `пиши в лс`, …).
- **Emoji-only**: no letters or digits at all after stripping emoji/punctuation.
- **Char repeats**: the same character 4+ times in a row.
- **Min length**: below N characters.

Presets ship ready: *Links & contacts*, *DM funnel*, *Scam claims*, *Junk & bots* (dictionaries in EN/RU/EL/PT). The **Test the text** button shows exactly which rule fires on a pasted comment.

Two modes: *rules only* (default) and *everything except exceptions*. A separate toggle decides whether comments under inactive (paused/deleted) ads are cleaned at all.

## Journal and safety

The *Comments* tab (Aggregator section) shows every action: time, comment text, author, matched rule, action, status, with **Restore / Hide / Delete** buttons per row. Comment texts live 60 days, then the text is wiped and only the action record remains.

- **Dry run** writes the journal and touches nothing on Graph. When you switch it off, the panel shows how many comments the dry run would have hidden in the last 24 h.
- **Auto-pause**: an error rate above 30% in a cycle (minimum 10 actions) or a Graph rate-limit code (4, 17, 32, 613) pauses the worker with a banner and a Telegram message; resume is one button.
- A worker banner appears when posts are due but the cron has not run lately.

## API

`comment_rulesets` (CRUD), `comment_ruleset_test`, `comment_log` (+24 h stats), `comment_log_action` (restore/hide/delete), `comment_cleaner_status`, `comment_cleaner_resume`.
