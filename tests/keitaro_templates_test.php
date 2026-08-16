<?php
/**
 * tests/keitaro_templates_test.php
 *
 * Guards the template packs in data/keitaro_*.json. Run from the project root:
 *
 *     php tests/keitaro_templates_test.php
 *
 * The packs are generated from the Keitaro exports by cli/generate_keitaro_templates.py.
 * They exist because hand-written macros looked plausible and tracked nothing: a wrong
 * external_id macro means every conversion arrives without a click to attach to. The
 * spot checks below are copied from the Keitaro export, so a regenerated pack that
 * drifts from the source data fails here instead of in production.
 */

require_once __DIR__ . '/../core/PostbackMacros.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function loadPack(string $file): array
{
    $path = __DIR__ . '/../data/' . $file;
    if (!is_file($path)) {
        return [];
    }
    $rows = json_decode((string) file_get_contents($path), true);
    return is_array($rows) ? $rows : [];
}

function findTpl(array $pack, string $displayName): ?array
{
    foreach ($pack as $tpl) {
        if (strcasecmp((string) ($tpl['display_name'] ?? ''), $displayName) === 0) {
            return $tpl;
        }
    }
    return null;
}

function macroOf(array $tpl, string $alias): string
{
    foreach ($tpl['parameters'] ?? [] as $p) {
        if (($p['alias'] ?? '') === $alias) {
            return (string) ($p['macro'] ?? '');
        }
    }
    return '';
}

echo "\n== traffic source pack ==\n";

$sources = loadPack('keitaro_traffic_sources.json');
check('pack present and sizeable', count($sources) > 150, 'got ' . count($sources));

$badRows = [];
$names = [];
$dupes = [];
foreach ($sources as $i => $tpl) {
    if (empty($tpl['name']) || empty($tpl['display_name']) || empty($tpl['parameters'])) {
        $badRows[] = $tpl['display_name'] ?? "#$i";
        continue;
    }
    if (isset($names[$tpl['name']])) {
        $dupes[] = $tpl['name'];
    }
    $names[$tpl['name']] = true;
    foreach ($tpl['parameters'] as $p) {
        // A macro can legitimately be the constant "0" (skakapp's isBackUrl), so
        // compare against the empty string rather than using empty().
        if (($p['alias'] ?? '') === '' || ($p['param'] ?? '') === '' || ($p['macro'] ?? '') === '') {
            $badRows[] = $tpl['display_name'] . ' / ' . json_encode($p);
        }
    }
}
check('every source has name, display_name and complete parameters', $badRows === [],
    implode('; ', array_slice($badRows, 0, 3)));
check('source template names are unique', $dupes === [], implode(', ', $dupes));

// Spot checks straight out of the Keitaro export.
$popads = findTpl($sources, 'popads.net');
check('PopAds click id is [IMPRESSIONID], not the site id',
    $popads && macroOf($popads, 'external_id') === '[IMPRESSIONID]',
    $popads ? macroOf($popads, 'external_id') : 'template missing');

$hilltop = findTpl($sources, 'hilltopads.com');
check('HilltopAds click id is {{ctoken}}',
    $hilltop && macroOf($hilltop, 'external_id') === '{{ctoken}}',
    $hilltop ? macroOf($hilltop, 'external_id') : 'template missing');

$adsterra = findTpl($sources, 'Adsterra.com');
check('Adsterra click id keeps the ##SUB_ID_SHORT(action)## form',
    $adsterra && macroOf($adsterra, 'external_id') === '##SUB_ID_SHORT(action)##',
    $adsterra ? macroOf($adsterra, 'external_id') : 'template missing');

$galaksion = findTpl($sources, 'galaksion.com');
check('Galaksion postback points at postback.report',
    $galaksion && str_contains((string) $galaksion['postback_url'], 'postback.report'),
    $galaksion['postback_url'] ?? 'template missing');

$unknownMacros = [];
foreach ($sources as $tpl) {
    $url = (string) ($tpl['postback_url'] ?? '');
    if ($url === '') {
        continue;
    }
    // Conversion-revenue flavours must have been rewritten to {payout} at generation time.
    if (preg_match('/\{conversion_revenue(:[a-z]{3})?\}/', $url)) {
        $unknownMacros[] = $tpl['display_name'];
    }
}
check('no {conversion_revenue} left in source postbacks', $unknownMacros === [],
    implode(', ', array_slice($unknownMacros, 0, 5)));

echo "\n== affiliate network pack ==\n";

$networks = loadPack('keitaro_affiliate_networks.json');
check('pack present and sizeable', count($networks) > 300, 'got ' . count($networks));

$badNets = [];
$netNames = [];
$netDupes = [];
foreach ($networks as $i => $tpl) {
    if (empty($tpl['name']) || empty($tpl['display_name']) || !isset($tpl['offer_params_template'])) {
        $badNets[] = $tpl['display_name'] ?? "#$i";
        continue;
    }
    if (isset($netNames[$tpl['name']])) {
        $netDupes[] = $tpl['name'];
    }
    $netNames[$tpl['name']] = true;
    $pb = (string) ($tpl['postback_url_template'] ?? '');
    if ($pb === '') {
        continue; // offer-parameters-only entry, same as the platform templates
    }
    if (!str_starts_with($pb, 'http://{domain}/{postback_key}/postback?') || !str_contains($pb, 'subid=')) {
        $badNets[] = $tpl['display_name'] . ' / ' . $pb;
    }
}
check('every network row is well formed', $badNets === [], implode('; ', array_slice($badNets, 0, 3)));
check('network template names are unique', $netDupes === [], implode(', ', $netDupes));

$maxbounty = findTpl($networks, 'Maxbounty.com');
check('MaxBounty passes the click id in s2 and reads #S2# back',
    $maxbounty && $maxbounty['offer_params_template'] === '&s2={subid}'
        && str_contains($maxbounty['postback_url_template'], 'subid=#S2#'),
    $maxbounty ? $maxbounty['offer_params_template'] . ' | ' . $maxbounty['postback_url_template'] : 'template missing');

$zeydoo = findTpl($networks, 'zeydoo.com');
check('Zeydoo payout is {amount} and the click id is ymid',
    $zeydoo && $zeydoo['offer_params_template'] === '&ymid={subid}'
        && str_contains($zeydoo['postback_url_template'], 'payout={amount}'),
    $zeydoo['postback_url_template'] ?? 'template missing');

$clickbank = findTpl($networks, 'Clickbank.com');
check('ClickBank payout is {affiliate_earnings}',
    $clickbank && str_contains($clickbank['postback_url_template'], 'payout={affiliate_earnings}'),
    $clickbank['postback_url_template'] ?? 'template missing');

$ignoreLeft = array_values(array_filter($networks, fn($t) => str_contains((string) ($t['postback_url_template'] ?? ''), 'ignore_status=')));
check('ignore_status dropped (the tracker has no such outcome)', $ignoreLeft === [],
    implode(', ', array_map(fn($t) => $t['display_name'], array_slice($ignoreLeft, 0, 3))));

echo "\n== status transform ==\n";

check('maps the internal status to the source vocabulary',
    orbitraApplyStatusTransform('https://x.test/p?e={status: lead=reg sale=dep}', 'sale')
        === 'https://x.test/p?e=dep');
check('falls back to the internal status when unlisted',
    orbitraApplyStatusTransform('https://x.test/p?e={status: lead=reg sale=dep}', 'trash')
        === 'https://x.test/p?e=trash');
check('urlencodes the mapped value',
    orbitraApplyStatusTransform('https://x.test/p?e={status: rejected=-1 lead=registration}', 'rejected')
        === 'https://x.test/p?e=-1');
check('leaves a plain {status} macro for the caller',
    orbitraApplyStatusTransform('https://x.test/p?e={status}', 'sale')
        === 'https://x.test/p?e={status}');

$withTransform = array_values(array_filter($sources, fn($t) => str_contains((string) ($t['postback_url'] ?? ''), '{status:')));
check('the pack really does ship transforms worth supporting', count($withTransform) >= 10,
    'got ' . count($withTransform));

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
