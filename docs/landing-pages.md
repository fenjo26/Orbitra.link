# Landing pages

A landing is the page a visitor sees on the way to an offer. Orbitra serves four kinds, and the differences matter mostly for one question: **how the buy button reaches the offer**.

| Type | Where it lives | Offer link |
|---|---|---|
| **Local** | uploaded ZIP, served by the tracker | `{offer}` or `/?_lp=1` |
| **Redirect** | your own hosting, another domain | `/?_lp=1` + the JS adapter |
| **Preload** | fetched by the tracker, shown under your domain | `{offer}` |
| **Action** | nothing is shown; the tracker does something | — |

---

## Local landings

The archive is unpacked into `landings/<landing id>/`. The entry point is `index.html`, or `index.php` if PHP landings are enabled.

### Requirements

- The entry page must be named `index.html` (or `index.php`).
- Use relative paths: `img/hero.jpg`, not `/img/hero.jpg`. Both work, but relative paths also work when you open the archive locally.
- No internal redirect to `index.html` — the visitor would leave the tracked URL.

Unlike Keitaro, Orbitra does **not** add a `<base>` tag and does not rewrite your markup. The page is served byte for byte as you uploaded it, and its files are resolved against the landing you were shown. Two consequences worth knowing:

- A `<base>` tag of your own is left alone.
- Anchor links (`href="#form"`), popup scripts and smooth scrolling keep working. In Keitaro these break, because it rewrites every `href`.

### Linking to the offer

The simplest form, and the one to reach for:

```html
<a href="{offer}">Buy</a>
```

`{offer}` is replaced with the URL of the offer the stream picked, click id included.

**With several offers on one page**, the choice has to go through the tracker:

```html
<a href="/?_lp=1&offer_id=10">Offer 10</a>
<a href="/?_lp=1&offer_id=22">Offer 22</a>
```

Appending `&offer_id=` to `{offer}` does nothing useful: `{offer}` already expands to the advertiser's URL, so the parameter is just passed along to them. `/?_lp=1&offer_id=N` picks the offer inside the tracker and re-attributes the click, so a conversion lands on the right offer.

### Macros

Substituted in the HTML of a local landing:

| Macro | Value |
|---|---|
| `{offer}` | URL of the offer bound to this click |
| `{offer_id}` | its id |
| `{clickid}`, `{subid}` | the click id |
| `{token}` | signed token, used by the JS adapter |
| `{keyword}`, `{sub_id_1}` … `{sub_id_30}` | click parameters captured from the traffic source |

Values taken from the URL are HTML-escaped. Nothing else is touched — `{unknown}`, JS template literals, Vue and Angular syntax all survive.

With no offer on the stream, `{offer}` becomes `/?_lp=1`, which explains the misconfiguration when clicked instead of silently reloading the page.

---

## Redirect (external) landings

The landing sits on your own hosting. The tracker sends the visitor there and appends two parameters:

```
https://your-landing.example/promo?_subid=<click id>&_token=<signed token>
```

The token is what makes the offer link work at all. A landing on another domain cannot read the tracker's cookies, so without it the tracker has no way to tell which click is coming back. It is signed with HMAC-SHA256 and expires after 24 hours; a bare `_subid` from the URL is **not** accepted on its own, because that would let anyone attribute a visit to any click id they like. A local or preload landing recovers the click from its cookie instead, so it does not need the token.

**Install the [JS adapter](#js-adapter)** and link the offer as:

```html
<a href="/?_lp=1">Buy</a>
```

The adapter rewrites that into an absolute link to the tracker and adds `_subid` and `_token`.

---

## Preload landings

The tracker fetches your page server-side, injects `<base href>` so its images and styles still load from the original host, applies macros and serves the result under your domain — no redirect. Use `{offer}` for the offer link.

---

## Action landings

Nothing is shown. Pick what happens:

| Action | Result |
|---|---|
| **Send to campaign** | hands the visitor to another campaign; the click counts in both |
| **Show a 404 error** | blank page, status 404 |
| **Show as text** | the text you enter, as `text/plain` |
| **Show as HTML** | the HTML you enter |
| **Do nothing** | empty response; the visitor stays where they are |

`{offer}`, `{clickid}` and the click parameters are substituted in the text and HTML variants.

> Keitaro passes the visitor to another campaign without a redirect. Orbitra uses a redirect: re-entering the tracker in the same request would mean including its entry script twice, which PHP cannot do. The click is recorded in both campaigns either way — the visitor just makes one extra hop.

---

## JS adapter

```html
<script src="https://your-tracker/js/orbitra-adapter.js"
        data-postback="https://your-tracker/POSTBACK_KEY/postback"></script>
```

Paste it into `<head>` on every page. The panel shows the snippet with your own key filled in, under the landing's settings.

**Required** for a redirect landing. **Optional but useful** for a local one with several pages: it carries the click onto inner pages and forms, so page two still knows who the visitor is.

It deliberately leaves alone what Keitaro's adapter rewrites: `#anchors`, `mailto:`, `tel:` and `javascript:` links are untouched, and links to third-party domains never receive your click id.

### Reporting a conversion from the page

```html
<a href="{offer}" onclick="orbitraPostback(this, event, 'lead', 10)">Buy</a>
```

Arguments: the element, the event, a conversion status, an optional payout and an optional transaction id. Navigation proceeds even if the postback fails — losing the sale to a reporting hiccup would be the worse trade.

For a form handler, PHP is simpler:

```php
file_get_contents('https://your-tracker/POSTBACK_KEY/postback?subid=' . urlencode($_COOKIE['subid']) . '&status=lead');
```

---

## PHP landings

Off by default. Turn them on in **Settings → General → Allow PHP landings**, and only for landings whose code you trust: a PHP landing runs inside the tracker, in the web root.

### Requirements

1. The entry page is `index.php`.
2. These calls are rejected at upload: `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`, `pcntl_exec`, `pcntl_fork`, `dl`, `eval`, `assert`, `create_function`, `symlink`, `link`, `set_time_limit`, `ini_set`, `ini_alter`.
3. Include other files by absolute path:
   ```php
   require_once dirname(__FILE__) . '/src/lib.php';   // right
   require_once 'src/lib.php';                        // wrong
   ```
4. Keep execution under the timeout (3 seconds by default, 9 maximum). Set timeouts on every curl call:
   ```php
   curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
   curl_setopt($ch, CURLOPT_TIMEOUT, 3);
   ```

### Reading the click

```php
<?php
$clickId = $rawClick->get('click_id');
$keyword = $rawClick->get('keyword');
$offer   = $rawClick->get('offer');      // the offer URL, same as {offer}
```

`$rawClick` is read-only. To record a conversion, send a postback.

> **The scan is not a sandbox.** It reads the uploaded source with PHP's own tokenizer, so it sees real calls rather than words in comments — but `$f = 'sys' . 'tem'; $f();` defeats it, as it defeats the equivalent check in every other tracker. What actually contains the risk is that the feature is off by default, that only an admin can enable it, and that `disable_functions` and `open_basedir` in your `php.ini` apply to a landing exactly as they apply to everything else. Set them.

---

## LeadForge 2.0 (Landing Analyzer, JS Validation & Multi-Network Bridge)

LeadForge 2.0 is Orbitra's built-in engine for analyzing, cleaning, and reseating landing page bundles onto target affiliate networks.

### Integration Modes

- **Auto Mode**: Automatically detects the source network from code signatures and maps form submissions to the target network.
- **Cross-Network Mode**: Removes legacy network order handlers (`send.php`, `order.php`, `api.php`), cleans out foreign tracking pixels/scripts, and installs the universal multi-network bridge.
- **Raw Mode (Clone Patch)**: Strips foreign counters and scripts, injects the ClickID/SubID bridge and `{offer}` macros without generating backend handlers.

### 150-GEO Phone Validation & Client Adapter (`orbitra_adapter.js`)

When enabled, LeadForge injects `orbitra_adapter.js`, providing:
- **150 Country Rules**: Exact national & international regex patterns, mobile operator prefix checks, and min/max length constraints.
- **Dynamic Country Switching**: Automatically updates validation rules, length caps, and counter helpers when the user changes `<select name="country">`.
- **Interactive Live Input Badges**: Real-time counter showing digits entered and remaining (e.g. *«3 cifre inserite, 7 mancanti»* → *«Numero complete»*).
- **Strict Unicode Name Validation**: Real-time filtering preventing digits and spam symbols in customer names.
- **Haptic Vibration Feedback**: Vibrates mobile devices on invalid phone format attempts.
- **ClickID / SubID Bridge**: Captures all incoming tracking parameters (`subid`, `click_id`, `sub1`..`sub5`, `utm_*`, `fbp`, `fbc`, etc.) and hydrates form fields across multi-page funnels.

### Universal CPA Order Bridge (`order.php`)

Generates a standalone, self-contained order handler supporting 10 CPA networks:
- **Dr.Cash**, **Webvork** (with SuperClient fallback), **Lucky.online**, **KMA.biz**, **TerraLeads** (with SHA1 checksum verification), **Leadbit**, **LemonAD**, **Everad**, **Ezaff**, and **Custom Webhooks**.
- **Automated E.164 Normalization**: Standardizes local phone numbers to international format with country prefix reconciliation.
- **CRM Dual Logging**: Simultaneously dispatches the lead to the affiliate network and records a complete raw lead snapshot into the Orbitra CRM Vault (`orbitraCrmRecordLead` / `/crm-ingest`).
- **Failsafe Local Logging**: Appends leads to `leadforge.leads.log` and `orbitra_leads_backup.log`.

---

## Troubleshooting

**Images and video do not load.** Use relative paths. If they are correct and the page still comes up bare, check that you are opening the campaign URL rather than `landings/<id>/index.html` directly.

**The buy button goes nowhere.** The landing is probably still pointing at the advertiser directly — a landing copied from someone else's funnel keeps their affiliate link. Replace those `href`s with `{offer}`.

**`Landing transition failed: original click not found`.** A landing on another domain without the JS adapter, or a visitor with cookies disabled and no `_token` in the URL.

**`no offer attached to this stream`.** The stream has a landing but no offer. Add one, or leave the landing as the final page and drop the offer link.

**A PHP landing shows "disabled on this tracker".** The feature is off; see above.
