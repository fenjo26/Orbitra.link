<?php
/**
 * Bug 4 (docs/TZ_SSL_CHAIN_AND_PRIVACY.md): four networks were selectable in
 * the UI with no send adapter in order.php, whose default branch fabricated
 * {"status":"ok"} — silent lead loss. Pins the post-fix contract:
 *
 *  - networks() is the SSOT and every adapter-less network is marked as such
 *    (and the never-implemented ones are gone entirely);
 *  - the generated order.php carries the AdCombo adapter and NO fabricated
 *    success anywhere — an adapter-less network fails honestly instead
 *    (error log + CRM snapshot + 502 to the visitor, no thank-you);
 *  - buildBundle refuses to build for an adapter-less network or a custom
 *    network without an endpoint URL.
 */
require_once __DIR__ . '/../core/LeadForge.php';

$fails = 0;
$checks = 0;

function check(string $name, bool $ok): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $name\n";
    }
}

// --- SSOT list ---------------------------------------------------------
$nets = LeadForge::networks();

check('adcombo has an adapter', !empty($nets['adcombo']['adapter']));
check('drcash has an adapter', !empty($nets['drcash']['adapter']));
check('custom has an adapter (URL passthrough)', !empty($nets['custom']['adapter']));
check('m1 is marked adapter-less (cabinet-only API, not fabricated)', empty($nets['m1']['adapter']));
check('monsterleads is marked adapter-less', empty($nets['monsterleads']['adapter']));
check('offercify removed (never had a case)', !array_key_exists('offercify', $nets));
check('trafficlight absent (frontend-only entry never had a case)', !array_key_exists('trafficlight', $nets));
foreach ($nets as $id => $net) {
    if (!empty($net['adapter']) && $id !== 'custom') {
        // Every adapter must map to a case in the generated order.php — this
        // is exactly the list/UI/switch divergence that caused the bug.
        $src = LeadForge::orderPhp(['network' => $id, 'offer_id' => '123', 'geo' => 'IT']);
        check("order.php carries a case for adapter '$id'", strpos($src, "case '" . ($id === 'luckyonline' ? 'lucky' : $id) . "':") !== false);
    }
}

// --- Generated order.php: honest failure, no fabricated success ---------
$src = LeadForge::orderPhp(['network' => 'm1', 'offer_id' => '642', 'geo' => 'IT']);
check('no fabricated 200 {"status":"ok"} anywhere in order.php',
    strpos($src, "['http_code' => 200, 'body' => '{\"status\":\"ok\"}']") === false);
check('adapter-less network logs an error event',
    strpos($src, "no send adapter for network") !== false);
check('adapter-less failure marks sendFailed', strpos($src, '$sendFailed = true') !== false);
check('honest failure gate rejects the visitor instead of a thank-you',
    strpos($src, 'Honest failure gate') !== false && strpos($src, 'http_response_code(502)') !== false);
check('CRM snapshot still synced before the gate (vault keeps the lead)',
    strpos($src, 'CRM Vault Sync') !== false && strpos($src, 'orbitraCrmRecordLead') !== false);

$srcAdcombo = LeadForge::orderPhp(['network' => 'adcombo', 'offer_id' => '29314', 'geo' => 'IT']);
check('adcombo endpoint baked in', strpos($srcAdcombo, 'api.adcombo.com/lead/create') !== false);
check('adcombo case exists', strpos($srcAdcombo, "case 'adcombo':") !== false);
check('adcombo payload carries country_code + api_key',
    strpos($srcAdcombo, "'country_code'") !== false && strpos($srcAdcombo, "'api_key' => \$LF['api_key']") !== false);

// URL passthrough in default must survive (custom mode relies on it)
check('default keeps the URL form-passthrough',
    strpos($src, 'FILTER_VALIDATE_URL') !== false && strpos($src, 'form passthrough') !== false);

// --- buildBundle validation --------------------------------------------
function buildFor(array $opts): array
{
    // Minimal HTML bundle: a single file, no ZIP needed.
    $tmp = tempnam(sys_get_temp_dir(), 'lf_b4_') . '.html';
    file_put_contents($tmp, '<html><body><form><input name="name"><input name="phone"></form></body></html>');
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_php_landings', '1')");
    // The build needs landings/offers tables only when saving; a validation
    // failure must return BEFORE any persistence, so their absence is fine.
    return LeadForge::buildBundle($pdo, $tmp, null, $opts);
}

$r = buildFor(['network' => 'm1', 'offer_id' => '642', 'mode' => 'auto', 'generate_order_php' => true]);
check('build refused: no adapter for m1', ($r['message'] ?? '') === 'no_adapter_for_network');

$r = buildFor(['network' => 'custom', 'offer_id' => 'not-a-url', 'mode' => 'auto', 'generate_order_php' => true]);
check('build refused: custom needs endpoint URL', ($r['message'] ?? '') === 'custom_endpoint_required');

$r = buildFor(['network' => 'nosuchnet', 'offer_id' => '1', 'mode' => 'auto', 'generate_order_php' => true]);
check('build refused: unknown network', ($r['message'] ?? '') === 'unknown_network');

$r = buildFor(['network' => 'custom', 'offer_id' => 'https://api.example.com/lead', 'mode' => 'auto', 'generate_order_php' => true, 'auto_save_tracker' => false]);
check('build passes: custom with endpoint URL', ($r['ok'] ?? false) === true);

echo "leadforge_bug4_test: " . ($fails === 0 ? "OK" : "FAILED") . " ($checks checks)\n";
exit($fails === 0 ? 0 : 1);
