# Affiliate network offer parameters

An offer's **Affiliate Network** supplies tracking defaults from that network's
**Offer parameters** field. Orbitra combines them with the stored offer URL at
request time, then resolves macros using the current click. Existing offers,
new offers and imported offers use the same behavior; editing the network does
not require saving each offer again.

For example, with network parameters `&subid={subid}&campaign={sub_id_1}`:

```text
Stored offer: https://network.example/checkout?aff=123
Destination:  https://network.example/checkout?aff=123&subid=CLICK_ID&campaign=summer
```

## Composition rules

- Query parameters can start with `&`, `?`, or the parameter name. Orbitra adds
  the appropriate separator, placing the new parameters before any fragment.
- Explicit offer parameters take precedence, including empty values and bare
  flags. A manually configured `subid={subid}` therefore remains a single
  parameter when the network supplies the same key.
- Names are compared case-sensitively after URL decoding. Dots, spaces and
  brackets are not converted into PHP variable names. Existing URL bytes,
  encoding, parameter order and repeated values are preserved. Repeated network
  values are preserved when that key is absent from the offer URL.
- Path suffixes used by bundled templates, such as `/{subid}` and
  `/{source}/{subid}`, are appended to the path before the query and fragment.
  An identical literal suffix already at the end of the path is not appended
  again. Only the joining slash is normalized; the rest of the path is preserved.
- Composition precedes the existing macro substitution rules: `{subid}` and
  `{clickid}` use Orbitra's click ID; `{ip}`, `{country}`, `{offer_id}` and captured
  tracking parameters use the click context. Unavailable ordinary macros become
  empty values, as they do in manually configured offer URLs. This does not add
  support for other trackers' special macro syntax or change source mappings.
- Empty destinations, local offers, relative local paths and non-HTTP schemes
  receive no network suffix. Scheme-less hosts and protocol-relative HTTP URLs
  are supported. A whole endpoint URL stored for LeadForge is not a suffix;
  suffixes containing a fragment or control characters are also ignored.
- Missing networks or empty parameters leave the offer URL unchanged. Network
  state is not an additional traffic filter: the stored association determines
  the defaults. The existing archive/delete actions detach offers themselves.

## Runtime integration

`core/OfferUrl.php` reads the selected offer and its network in one `LEFT JOIN`,
replacing the existing offer lookup. No extra per-click network query, schema
migration, persistent cache or write to the offer is needed.

The shared composition is used by:

- `index.php`: landing-to-offer transitions, registered direct offers, and
  registered offers selected through landing/offer streams.
- Landing `{offer}` URLs, including HTML, PHP, action and redirect landings.
  The complete offer URL is macro-resolved before being embedded or URL-encoded.
- `core/click_api.php`: Click API destinations and nested offer URLs. Signed
  `offer_link` transitions still pass through the normal landing transition.
- `click.php`: the legacy endpoint's selected registered offer. Explicit `url=`
  overrides retain their existing behavior and domain validation.

Unassociated stream URLs, local-offer serving, offer selection, click attribution,
traffic filters and postback processing retain their existing responsibilities.
The tracking clients and local landing HTML need no additional adapter for this
feature. Multi-offer buttons can continue using `/?_lp=1&offer_id=N`.

## Regression checks

Run from the repository root with PHP and PDO SQLite enabled:

```sh
php tests/affiliate_network_offer_params_test.php
php tests/affiliate_network_offer_params_http_test.php
php tests/lp_offer_macros_test.php
php tests/lp_transition_timing_test.php
```

The new tests use synthetic data and isolated SQLite databases. HTTP checks use
a local server and inspect the first response without following affiliate
destinations. They do not create purchases or send conversion postbacks.
