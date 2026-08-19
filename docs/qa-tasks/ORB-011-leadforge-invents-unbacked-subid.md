# ORB-011 — LeadForge sends the network a subid with no click behind it

- **Severity:** High — it makes a working tracker look broken, and it is unfixable from the network side
- **Run:** alone; shares no files with ORB-006/007/008
- **Owns:** `core/LeadForge.php`, `index.php` (the `/offers/<id>/` and `/lander/<slug>/` preview routes only), `tests/`, `docs/`
- **Do not touch:** `postback.php`, `api.php`, `config.php`, `frontend/**`, locales
- **No migration**

## What happened

QA fired a Dr.Cash lead from `87.232.72.54:8750/offers/1/success.php`. The form
posted `sub1 = b3ebefb2-db23-4541-a83a-d049d5f9d07f`, Dr.Cash stored it and
returned it in the postback. The tracker answered:

```
404  Click ID not found in database.
```

The postback pipeline behaved correctly — this is ORB-002's log doing exactly
its job. The defect is upstream: that subid was never a click.

## Root cause

Two separate things combine.

**`/offers/<id>/` is a preview route that creates no click.** Its own comment in
`index.php` (`orbitraServeOfferPath`) says so:

> Same rules as the lander route: not a click, nothing logged

But the LeadForge order handler on that page still runs and still submits a lead
to the affiliate network.

**With no click, `core/LeadForge.php` supplies a subid anyway.** It walks the
request, then the `orbitra_click` / `orbitra_subid` / `subid` cookies, then the
session, then the query string (lines 1132–1152) — a stale cookie from a click
that has since been deleted, or from a previous database, passes all of these.
And when even that is empty (line 1155):

```php
if ($subid === '') {
    $subid = 'lead_' . bin2hex(random_bytes(8));
}
```

It invents one. The lead reaches the network carrying an identifier the tracker
is guaranteed to reject when it comes back.

## Why this matters more than it looks

The failure surfaces hours later, on the network's postback, as
"Click ID not found in database" — which reads as a tracker bug. The lead is
real, the payout is real, and there is no way to reattach it after the fact,
because nothing was ever recorded on our side. Nobody can debug this from the
network's UI: the subid is present and looks perfectly valid there.

## Fix

Decide what a lead with no verifiable click means, then make the code say it out
loud instead of inventing data.

1. **Never fabricate.** Delete the `'lead_' . bin2hex(...)` fallback. A subid is
   either a real `clicks.id` or it is absent.
2. **Verify before sending.** Before the lead goes to the network, check the
   resolved subid against `clicks`. If it does not resolve:
   - refuse and return a clear error to the form, **or**
   - send with an empty subid and record the lead locally as unattributed
   — pick one, write the choice down in `docs/`, and make the behaviour the same
   on every path (`/offers/<id>/`, `/lander/<slug>/`, the campaign flow).
3. **Do not trust a stale cookie.** `orbitra_click` outlives the click it names —
   it survives a database reset and the retention purge. Resolve it against
   `clicks` before use, exactly as in point 2.
4. **Make the preview route honest.** A page served from `/offers/<id>/` or
   `/lander/<slug>/` has no click context by design, so its order form should not
   be able to submit a live lead to a network. Either block submission there with
   a visible "preview mode — no click context" message, or force QA mode. Today
   the page is indistinguishable from the real thing, which is how this test was
   run in the first place.
5. Whatever is rejected or degraded must land in `system_logs` with the reason,
   so the next person sees it at the moment it happens rather than in a postback
   the next day.

## Acceptance

- [ ] Submitting the order form on `/offers/<id>/...` with no click context does **not** send a lead carrying an invented or unresolvable subid.
- [ ] `grep -n "bin2hex(random_bytes" core/LeadForge.php` returns nothing for the subid path.
- [ ] A stale `orbitra_click` cookie naming a click that no longer exists is treated as absent, not as a valid subid.
- [ ] A lead submitted through the real flow (`/<campaign alias>` → landing → form) still carries the real `clicks.id`, and its postback records a conversion. Verify end to end, not by reading code.
- [ ] The chosen behaviour for the no-click case is identical on `/offers/<id>/`, `/lander/<slug>/` and the campaign flow, and is documented in `docs/`.
- [ ] Every refusal or degradation writes a `system_logs` row naming the reason.
- [ ] `tests/leadforge_subid_test.php` covers: valid click, stale cookie pointing at a deleted click, no context at all, and QA mode.

## Note for whoever tests this

The correct way to test a conversion end to end is to enter through the campaign
link (`http://<host>/<alias>`), follow it to the landing, and submit there. Clear
cookies first — `orbitra_click` lives 24 hours and will otherwise supply a subid
from an earlier session. Opening `/offers/<id>/` directly previews the bundle's
markup and nothing more.
