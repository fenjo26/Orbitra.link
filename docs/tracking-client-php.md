# Tracking Client (PHP)

`kclient.php` puts the tracker inside your own PHP page. The page runs on your
hosting, on your domain; the tracker only tells it what to do with the visitor.

It is the server-side twin of `kclient.js`: same campaign, same streams, same
click, but nothing about the tracker is visible in the page source and no
JavaScript has to run for the click to be registered.

Download it from the campaign's **Tracking** tab (*Download kclient.php*) or
straight from your tracker:

```
https://your-tracker/kclient.php?download=1
```

Put it next to the `index.php` it will be required from.

---

## The snippet

The campaign editor generates this for you, with your tracker URL and the
campaign token already filled in. Paste it at the very top of `index.php`,
before the doctype and before any other output.

```php
<?php
require_once dirname(__FILE__) . '/kclient.php';
$client = new KClickClient('https://your-tracker', 'CAMPAIGN_TOKEN');
$client->sendAllParams();
// $client->debug();            // show errors while testing
// $client->execute();          // run, print the output, keep the page going
$client->executeAndBreak();     // stop the page if the tracker redirects or returns content
?>
```

That is the whole integration. There is no mode to pick: **what the visitor
gets — your local page, a redirect to the offer, or the white page — is decided
by the campaign's streams**, in the tracker, where you can change it after the
code is already live on the site.

The three commented lines are the variations, kept next to the code because
that is where you will want them:

| Line | What it does |
|---|---|
| `debug()` | Prints tracker errors instead of swallowing them. Testing only — take it out before traffic. |
| `execute()` | Runs the campaign and returns; your page keeps rendering underneath. Use it when the page itself is the landing and you only want the click logged. |
| `executeAndBreak()` | Runs the campaign and stops the page when the tracker answered with a redirect or with content of its own. This is the default, and the one that makes a redirect stream work with no further edits. |

`sendAllParams()` forwards the page's query string — UTM labels, sub IDs,
whatever your traffic source appended — to the tracker. Turn it off in the panel
only if you have a reason to keep those parameters out of the click.

---

## Code for secondary pages (optional)

A landing that has more than one page — a quiz step, a form page, a
"more info" page — must not register a second click when the visitor moves on.
Put this at the top of those pages instead of the main snippet:

```php
<?php
require_once dirname(__FILE__) . '/kclient.php';
$client = new KClickClient('https://your-tracker', 'CAMPAIGN_TOKEN');
$client->restoreFromQuery();
// $client->restoreFromSession();   // when internal links do not carry _subid
?>
```

`restoreFromQuery()` picks the click back up from the `_subid` parameter the
tracker appends to the URL it sends the visitor to — carry that parameter
forward in the links between your own pages. If your links are built by hand
and carry no `_subid`, use `restoreFromSession()` instead: it reads the click
out of the PHP session the entry page started.

Either way `$client` is now the same click as on the entry page, so
`getConversionUrl()` keeps pointing at the right visit. `getOffer()` works here
too as long as the entry page and this one share a PHP session — the offer is
read back from that session, and only when the session's click id is the same
one the URL named. On a page with no session to read (a different host, or
`disableSessions()`), `getOffer()` comes back `null` and you should carry the
offer link forward yourself.

---

## How to link to the offer

`getOffer()` returns the offer URL the stream resolved for this click:

```php
<a href="<?= $client->getOffer() ?>">link</a>
```

`getOffer(42)` picks offer 42 of the stream instead of the one the tracker
chose — useful when one landing shows several offers and each button has to
lead somewhere specific. The array form `getOffer(array('offer_id' => 42))`
does the same thing. Either form *replaces* the `offer_id` already on the link
rather than adding a second one, so the URL never carries two.

If you would rather hold the URL and build the markup yourself:

```php
<?php
$offerLink = $client->getOffer();
// $offerLink = $client->getOffer(42);   // a specific offer of the stream
?>
```

Note that this is a *recipe*, not a mode: the snippet at the top of the page is
unchanged. `getOffer()` performs the click on first call if `execute()` has not
already, so a page that only ever calls `getOffer()` still tracks its visitor.

---

## Time on the page

`getOffer()` already tells the tracker how long the visitor took before pressing
the offer button. It says nothing about the visitor who read two lines and left
— and that one is usually the reason a landing is not converting. Echo this once
before `</body>` and every visit reports its time (and scroll depth), bounces
included:

```php
<?php echo $client->timerScript(); ?>
</body>
```

It returns an empty string when no click was registered, so it is safe to leave
in the template unconditionally. The numbers land in the **Time on LP** column
of the Logs and in the *Time on LP* / *LP bounce rate* / *LP scroll depth*
report metrics.

## Reporting a conversion

On the thank-you page, after the click has been restored:

```php
<?php
$url = $client->getConversionUrl('sale', 49.90);
if ($url) {
    // fire it server-side, or drop it into the page as a pixel
    file_get_contents($url);
}
?>
```

`getConversionUrl()` returns `null` when there is no click to attach the
conversion to — an unrestored secondary page, or a visitor who arrived
directly. Check before firing.

---

## Full API

| Method | Purpose |
|---|---|
| `sendAllParams()` | Forward the page's whole query string to the tracker |
| `params($queryString)` / `param($name, $value)` | Forward specific parameters |
| `keyword($keyword)` | Set the click's keyword |
| `sendUtmLabels()` | Forward only the UTM labels |
| `currentPageAsReferrer()` | Report this page as the referrer |
| `forceRedirectOffer()` | Make `execute()` redirect too, not only `executeAndBreak()` |
| `disableSessions()` | Do not start a PHP session |
| `debug()` | Print tracker errors |
| `restoreFromQuery()` / `restoreFromSession()` | Continue an existing click |
| `execute()` / `executeAndBreak()` | Run the campaign |
| `getOffer($idOrOpts, $default)` | The stream's offer URL |
| `getContent()` / `getBody()` | Stream content, for "show as HTML" streams |
| `getSubid()` | The click id |
| `getHeaders()` | Response headers from the tracker |
| `getConversionUrl($status, $payout)` | Conversion URL for this click |

`isBot()` and `isUnique()` exist for Keitaro source compatibility and currently
return `null`: those verdicts are decided tracker-side on redirect visits and
the Click API does not expose them yet.

---

## Troubleshooting

**The page renders normally and no redirect happens.** The stream that matched
is not a redirect stream, or none matched. Check the campaign's streams and the
click in **Recent clicks** — the snippet is doing its job either way.

**Nothing appears in the tracker at all.** Add `$client->debug();` and reload.
The usual causes are a wrong campaign token, a tracker URL the hosting cannot
reach outbound, and output sent before the snippet (a stray blank line above
`<?php` is enough to break the redirect).

**A second click per visitor.** A secondary page is running the main snippet.
Give it the `restoreFromQuery()` snippet instead.

**Offer link empty.** The stream resolved no offer — an offer-less landing
stream, or a filter that sent the click elsewhere. `getOffer()` takes a second
argument used as the fallback: `getOffer(null, 'https://example.com')`.

---

See also: [Landing pages](landing-pages.md) · [Cloaking](cloaking.md) ·
[API](api.md)
