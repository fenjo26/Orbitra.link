<?php
/**
 * Acceptance round 2, blocker 3: the campaign pixel /pixel.gif must write
 * clicks again.
 *
 * The ua_hash work added a column to the pixel's INSERT without adding a
 * placeholder to its hand-maintained VALUES list (23 columns, 22 values), and
 * the pixel's catch swallowed the failure with no log line — clicks stopped
 * silently, and no test covered the path, so the whole suite stayed green.
 *
 * The INSERT itself is now generated from one array (column list, placeholder
 * list and values can no longer disagree), the catch leaves an error_log
 * trace, and this test drives the real HTTP route: every variant of the
 * campaign lookup must land a clicks row carrying the right ua_hash.
 *
 * Usage: php tests/pixel_click_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
try {
    $seed = $harness->seedTestData();
    $campaignId = (int) $seed['campaign_id'];
    $work = $harness->getWorkingDir();
    require_once $work . '/core/click_logger.php';

    $pdo = $harness->getPdo();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();

    $ua = 'Mozilla/5.0 PixelTest/1.0 Chrome/124';
    // An iPhone UA: v1.6.2 recorded device_type = Mobile with os/browser
    // 'Unknown' — the swap found in acceptance round 3 must stay fixed.
    $iphoneUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
    $getPixel = static function (string $query, string $ua = 'Mozilla/5.0 PixelTest/1.0 Chrome/124') use ($harness): array {
        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "User-Agent: $ua\r\nX-Forwarded-For: 203.0.113.90\r\n",
        ]]);
        $body = @file_get_contents($harness->getBaseUrl() . "/pixel.gif?$query", false, $ctx);
        $code = 0;
        $type = '';
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
            if (stripos($h, 'content-type:') === 0) {
                $type = trim(substr($h, 13));
            }
        }
        return ['code' => $code, 'type' => $type, 'body' => (string) $body];
    };
    $clicksFor = static function () use ($pdo, $campaignId): array {
        $stmt = $pdo->prepare("SELECT id, ip, user_agent, ua_hash, uniq_campaign FROM clicks WHERE campaign_id = ? ORDER BY created_at, id");
        $stmt->execute([$campaignId]);
        // seedTestData() plants its own click in this campaign — drop it.
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            static fn($r) => $r['id'] !== (string) $GLOBALS['seedClickId']));
        $stmt->closeCursor();
        return $rows;
    };
    $GLOBALS['seedClickId'] = (string) $seed['click_id'];

    // --- by campaign_id: the snippet-generated path ---------------------------
    $resp = $getPixel("campaign_id=$campaignId&sub1=px");
    check($resp['code'] === 200 && strpos($resp['type'], 'image/gif') !== false,
        'pixel answers 200 image/gif (got ' . $resp['code'] . ' ' . $resp['type'] . ')');
    $rows = $clicksFor();
    check(count($rows) === 1, 'the pixel click landed in clicks (got ' . count($rows) . ')');
    if ($rows) {
        check($rows[0]['user_agent'] === $ua, 'the click carries the visitor user agent');
        check((int) $rows[0]['ua_hash'] === crc32($ua), 'the click carries ua_hash = crc32(UA)');
        check((int) $rows[0]['uniq_campaign'] === 1, 'uniqueness flags were written for the pixel click');
    }

    // --- by token: the JS-client path -----------------------------------------
    $resp = $getPixel('token=' . urlencode((string) $seed['postback_key']) . '&sub1=px2');
    // seedTestData's token column is its campaign token:
    $resp2 = $getPixel('token=test-token-' . $campaignId . '&sub1=px2');
    $rows = $clicksFor();
    check(count($rows) === 2, 'the token lookup also lands a click (got ' . count($rows) . ')');

    // --- the pixel does not debounce: every impression is a row ---------------
    $getPixel("campaign_id=$campaignId&sub1=px3");
    $rows = $clicksFor();
    check(count($rows) === 3, 'a second identical impression still writes its own row (got ' . count($rows) . ')');
    $hashes = array_unique(array_map(static fn($r) => (string) $r['ua_hash'], $rows));
    check($hashes === [(string) crc32($ua)], 'every pixel click row carries the same correct hash');

    // --- device columns match v1.6.2 (acceptance round 3) ---------------------
    // v1.6.2 recorded an iPhone as device_type = Mobile, os = Unknown,
    // browser = Unknown. The round-2 rewrite swapped the detected kind into
    // browser and left device_type 'Unknown' — device reports would have
    // shown the whole pixel traffic as Unknown.
    $getPixel("campaign_id=$campaignId&sub1=px4", $iphoneUa);
    $stmt = $pdo->prepare("SELECT device_type, os, browser FROM clicks WHERE campaign_id = ? AND user_agent = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$campaignId, $iphoneUa]);
    $deviceRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    check($deviceRow !== false, 'the iPhone impression landed a row');
    if ($deviceRow) {
        check($deviceRow['device_type'] === 'Mobile',
            "iPhone recorded as device_type = Mobile (got '{$deviceRow['device_type']}')");
        check($deviceRow['os'] === 'Unknown' && $deviceRow['browser'] === 'Unknown',
            "os and browser stay 'Unknown' like v1.6.2 (got '{$deviceRow['os']}' / '{$deviceRow['browser']}')");
    }
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Pixel click tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
