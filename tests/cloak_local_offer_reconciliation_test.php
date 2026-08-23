<?php
// tests/cloak_local_offer_reconciliation_test.php
//
// Reconciliation test for cloak campaigns with LOCAL offers on the money page.
// Four surfaces must agree for the same time window:
//   a) clicks table: M rows with is_safe_page=0, N rows with is_safe_page=1
//   b) api.php?action=campaigns (date_from/date_to, like the Campaigns list) → clicks = M
//   c) api.php?action=cloak_summary → money=M, safe=N, total=M+N
//   d) api.php?action=logs&type=traffic → route=money has M rows, route=safe has N rows
//
// Setup mirrors the reported production case: cloak stream, white redirect
// landing on the safe page, LOCAL offer on the money page, "Log Safe Page
// clicks" ON (dont_record_safe_clicks=false), "Exclude Safe Page clicks from
// reports" ON (exclude_safe_from_reports=true).
//
// Sandbox notes (why the vectors differ from the ticket wording):
//   - The test sandbox has no geo databases, so country allow-lists see
//     "Unknown". W4's geo_unknown_action='money' keeps the allowed-geo intent:
//     an unresolved geo is routed through instead of silently sinking the
//     money traffic (that knob exists for exactly this situation).
//   - The "blocked-ISP" safe vector needs ISP data from a geo DB; without one
//     the ISP haystack is empty and the bot-ISP layer cannot trigger. A second
//     tool UA (python-requests) stands in for it, so N still covers both
//     passive layers (UA heuristics + targeting).
//   - One money hit replays the orbitra_lo cookie a real browser holds from an
//     earlier visit, and enters via the campaign alias — the exact "second and
//     later visits" path the reconciliation is about. A repeat ASSET request
//     with the same cookie must NOT log (guarded below).
//
// Run: php tests/cloak_local_offer_reconciliation_test.php

$testPassed = true;

function assertTrue($condition, $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: " . var_export($expected, true) . "\n");
        fwrite(STDERR, "  Actual:   " . var_export($actual, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $expected === $actual;
}

// Load the test harness
require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);

try {
    echo "Starting test server...\n";
    $harness->useProductionRouter(); // router.php knows /api.php and campaign aliases
    $harness->start();
    echo "Server started on " . $harness->getBaseUrl() . "\n\n";

    $pdo = $harness->getPdo();

    // Local offer archives live next to the server's index.php (orbitraOfferDir
    // resolves __DIR__ . '/offers/<id>'), i.e. inside the harness working dir.
    $dbPath = $pdo->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC)['file'];
    $workingDir = dirname($dbPath);

    // ===== Seed test campaign with cloak stream and LOCAL offer =====
    echo "Seeding test campaign with cloak stream and LOCAL offer...\n";

    $campaignId = random_int(10000, 99999);
    $cloakStreamId = random_int(10000, 99999);
    $localOfferId = random_int(10000, 99999);
    $safeLandingId = random_int(10000, 99999);
    $campaignAlias = 'rc' . bin2hex(random_bytes(4));
    $campaignToken = 'test_' . bin2hex(random_bytes(4));

    // Safe page: white redirect landing
    $pdo->prepare("
        INSERT INTO landings (id, name, slug, type, url, state, is_archived)
        VALUES (?, ?, ?, 'redirect', ?, 'active', 0)
    ")->execute([
        $safeLandingId,
        'Safe Landing',
        'safe_' . bin2hex(random_bytes(4)),
        'https://example.com/safe',
    ]);

    // Money page: LOCAL offer (uploaded archive, empty URL)
    $pdo->prepare("
        INSERT INTO offers (id, name, url, is_local, state, is_archived)
        VALUES (?, ?, '', 1, 'active', 0)
    ")->execute([
        $localOfferId,
        'Local Money Offer',
    ]);

    $offerDir = $workingDir . '/offers/' . $localOfferId;
    @mkdir($offerDir, 0755, true);
    file_put_contents($offerDir . '/index.html', '<!DOCTYPE html><html><body>Money Page ' . $localOfferId . '</body></html>');

    $pdo->prepare("
        INSERT INTO campaigns (id, name, alias, token, state, is_archived)
        VALUES (?, ?, ?, ?, 'active', 0)
    ")->execute([
        $campaignId,
        'Test Cloak Campaign',
        $campaignAlias,
        $campaignToken,
    ]);

    // Cloak stream: IN allow-list (geo_unknown_action=money for the geo-less
    // sandbox), mobile-only device allow-list, passive layers on. The money
    // route picks the LOCAL offer; the safe route picks the white landing.
    $cloakConfig = json_encode([
        'safe_landing_id' => $safeLandingId,
        'offers' => [['id' => $localOfferId, 'weight' => 100]],
        'landings' => [],
        'dont_record_safe_clicks' => false,   // "Log Safe Page clicks" ON
        'exclude_safe_from_reports' => true,  // "Exclude Safe Page clicks from reports" ON
        'detect_datacenter' => true,
        'detect_vpn' => true,
        'detect_bots' => true,
        'detect_ua' => true,
        'sensitivity' => 'medium',
        'countries' => 'IN',
        'geo_mode' => 'allow',
        'geo_unknown_action' => 'money', // sandbox has no geo DB (see header)
        'devices' => 'mobile',
        'device_mode' => 'allow',
    ]);

    $pdo->prepare("
        INSERT INTO streams (id, campaign_id, name, type, schema_type, schema_custom_json, is_active, collect_clicks, position)
        VALUES (?, ?, ?, 'regular', 'cloak', ?, 1, 1, 1)
    ")->execute([
        $cloakStreamId,
        $campaignId,
        'Cloak Stream',
        $cloakConfig,
    ]);

    // Settings the click path reads
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'testkey42')")->execute();

    // W1 cloak observability columns (migration v38) — the harness skips migrations
    $cloakColumns = [
        'cloak_verdict' => 'TEXT DEFAULT NULL',
        'cloak_reasons' => 'TEXT DEFAULT NULL',
        'is_safe_page' => 'INTEGER DEFAULT 0',
        'isp' => 'TEXT DEFAULT NULL',
        'asn' => 'TEXT DEFAULT NULL',
        'proxy_type' => 'TEXT DEFAULT NULL',
        'cloak_sensitivity' => 'TEXT DEFAULT NULL',
    ];
    foreach ($cloakColumns as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE clicks ADD COLUMN $col $def");
        } catch (PDOException $e) {
            // Column already present.
        }
    }
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clicks_safe ON clicks(campaign_id, is_safe_page, created_at)");
    } catch (PDOException $e) {
        // Index already exists.
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cloak_suppressed_stats (
                campaign_id INTEGER NOT NULL,
                stream_id   INTEGER,
                day         TEXT NOT NULL,
                verdict     TEXT NOT NULL,
                reason      TEXT NOT NULL DEFAULT '',
                hits        INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (campaign_id, stream_id, day, verdict, reason)
            )
        ");
    } catch (PDOException $e) {
        // Table already exists.
    }
    $pdo->exec("PRAGMA user_version = 38;");

    // api.php needs an authenticated principal: admin user with an API key.
    // Timezone mirrors the reported setup (Asia/Kolkata) so surface B filters
    // dates the same way the reporting user's panel does.
    $apiKey = 'test_key_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO users (username, password, role, timezone) VALUES (?, ?, ?, ?)')
        ->execute(['recon_test_admin', password_hash('x', PASSWORD_DEFAULT), 'admin', 'Asia/Kolkata']);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $apiKey, 'cloak-recon-test', 'full']);
    $apiGet = function (string $path) use ($harness, $apiKey): array {
        return $harness->getWithHeaders($path, ['Authorization: Bearer ' . $apiKey]);
    };

    echo "✓ Seeded campaign_id=$campaignId alias=$campaignAlias stream_id=$cloakStreamId local_offer_id=$localOfferId\n\n";

    // ===== Send test traffic =====
    $N_SAFE = 3;  // safe hits
    $M_MONEY = 3; // money hits

    echo "Sending $N_SAFE safe hits (curl UA, desktop UA, tool UA standing in for blocked-ISP)...\n";

    $safeResponses = [];
    // All hits enter via the campaign alias: under router.php an unparked
    // domain sends "/?campaign_id=" to admin.php (panel), so the alias is the
    // sandbox-faithful form of the production campaign URL — and a non-"/"
    // path is exactly what exercises the early exits on the money-page path.
    // Safe 1: curl UA (passive bot layer)
    $safeResponses[] = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: curl/8.4.0',
        'X-Forwarded-For: 103.212.120.1',
    ]);
    // Safe 2: desktop UA against a mobile-only allow-list (targeting layer)
    $safeResponses[] = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'X-Forwarded-For: 103.212.120.2',
    ]);
    // Safe 3: tool UA (second passive vector; ISP blocking needs a geo DB the sandbox lacks)
    $safeResponses[] = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: python-requests/2.31.0',
        'X-Forwarded-For: 103.212.120.3',
    ]);
    foreach ($safeResponses as $i => $r) {
        assertEquals(302, $r['code'], "Safe hit " . ($i + 1) . " redirects to the white landing");
        assertTrue(strpos($r['headers']['Location'] ?? '', 'example.com/safe') !== false,
            "Safe hit " . ($i + 1) . " Location points at the white landing");
    }
    echo "✓ Sent $N_SAFE safe hits\n\n";

    echo "Sending $M_MONEY money hits (mobile UA, allowed geo; one with orbitra_lo cookie, one via alias)...\n";

    // Money 1: campaign entry — fresh browser, no cookies.
    $moneyResp1 = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.10',
        'Accept-Language: en-IN,en;q=0.9',
    ]);
    assertEquals(200, $moneyResp1['code'], 'Money hit 1 serves the local offer inline (200)');
    assertTrue(strpos($moneyResp1['body'], 'Money Page') !== false, 'Money hit 1 body is the money page');

    // Money 2: SECOND VISIT — same browser now holds the orbitra_lo cookie the
    // first money page set; enters via the campaign alias. Must still log.
    $moneyResp2 = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'X-Forwarded-For: 103.212.120.11',
        'Accept-Language: en-IN,en;q=0.9',
        "Cookie: orbitra_lo=$localOfferId",
    ]);
    assertEquals(200, $moneyResp2['code'], 'Money hit 2 (orbitra_lo cookie, alias entry) serves the money page');
    assertTrue(strpos($moneyResp2['body'], 'Money Page') !== false, 'Money hit 2 body is the money page');

    // Money 3: alias entry, fresh browser (no cookie) — the plain ad-click shape.
    $moneyResp3 = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.13',
        'Accept-Language: en-IN,en;q=0.9',
    ]);
    assertEquals(200, $moneyResp3['code'], 'Money hit 3 (alias entry, no cookie) serves the money page');
    assertTrue(strpos($moneyResp3['body'], 'Money Page') !== false, 'Money hit 3 body is the money page');
    echo "✓ Sent $M_MONEY money hits\n\n";

    // Guard: a repeat ASSET request with the orbitra_lo cookie must NOT log.
    $clickCountBeforeAsset = $harness->getClickCount();
    $assetResp = $harness->getWithHeaders('/style.css', [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.10',
        "Cookie: orbitra_lo=$localOfferId",
    ]);
    assertEquals($clickCountBeforeAsset, $harness->getClickCount(), 'Repeat asset request with orbitra_lo cookie does NOT log a click');
    echo "\n";

    // Allow time for writes to complete
    usleep(150000);

    // ===== Measure all four surfaces =====
    echo "=== RECONCILIATION REPORT ===\n\n";

    // Surface A: clicks table (ground truth)
    echo "Surface A: clicks table\n";
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN COALESCE(is_safe_page, 0) = 0 THEN 1 ELSE 0 END) as money,
            SUM(CASE WHEN is_safe_page = 1 THEN 1 ELSE 0 END) as safe
        FROM clicks
        WHERE campaign_id = ?
    ");
    $stmt->execute([$campaignId]);
    $tableRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $surfaceA_money = (int) $tableRow['money'];
    $surfaceA_safe = (int) $tableRow['safe'];
    $tableTotal = (int) $tableRow['total'];
    echo "  Money (is_safe_page=0): $surfaceA_money\n";
    echo "  Safe (is_safe_page=1): $surfaceA_safe\n";
    echo "  Total: $tableTotal\n\n";

    // Surface B: campaigns list — same params the Campaigns page sends
    // (date_from/date_to in the user's report timezone, Asia/Kolkata here).
    echo "Surface B: api.php?action=campaigns\n";
    $kolkataNow = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $utcNow = new DateTime('now', new DateTimeZone('UTC'));
    $days = array_unique([$kolkataNow->format('Y-m-d'), $utcNow->format('Y-m-d')]);
    sort($days);
    $dayFrom = $days[0];
    $dayTo = $days[count($days) - 1];
    $apiResp = $apiGet("/api.php?action=campaigns&date_from=$dayFrom&date_to=$dayTo");
    $apiData = json_decode($apiResp['body'], true);
    $campaignClicks = null;
    if (($apiData['status'] ?? '') === 'success' && is_array($apiData['data'] ?? null)) {
        foreach ($apiData['data'] as $camp) {
            if ((int) $camp['id'] === $campaignId) {
                $campaignClicks = (int) $camp['clicks'];
                break;
            }
        }
    }
    $surfaceB_money = $campaignClicks;
    echo "  Clicks for campaign $campaignId: " . ($campaignClicks !== null ? $campaignClicks : 'NOT FOUND') . "\n\n";

    // Surface C: cloak diagnostics summary (the panel's source)
    echo "Surface C: api.php?action=cloak_summary\n";
    $summaryResp = $apiGet("/api.php?action=cloak_summary&campaign_id=$campaignId&from=$dayFrom&to=$dayTo");
    $summaryData = json_decode($summaryResp['body'], true);
    $summaryPayload = $summaryData['data'] ?? null;
    $surfaceC_money = isset($summaryPayload['money']) ? (int) $summaryPayload['money'] : null;
    $surfaceC_safe = isset($summaryPayload['safe']) ? (int) $summaryPayload['safe'] : null;
    $summaryTotal = isset($summaryPayload['total']) ? (int) $summaryPayload['total'] : null;
    echo "  Money: " . ($surfaceC_money !== null ? $surfaceC_money : 'NULL') . "\n";
    echo "  Safe: " . ($surfaceC_safe !== null ? $surfaceC_safe : 'NULL') . "\n";
    echo "  Total: " . ($summaryTotal !== null ? $summaryTotal : 'NULL') . "\n\n";

    // Surface D: traffic log with route filter
    echo "Surface D: api.php?action=logs&type=traffic\n";
    $logsMoneyData = json_decode($apiGet("/api.php?action=logs&type=traffic&route=money&campaign_id=$campaignId&limit=100")['body'], true);
    $logsSafeData = json_decode($apiGet("/api.php?action=logs&type=traffic&route=safe&campaign_id=$campaignId&limit=100")['body'], true);
    $surfaceD_money = is_array($logsMoneyData['data'] ?? null) ? count($logsMoneyData['data']) : null;
    $surfaceD_safe = is_array($logsSafeData['data'] ?? null) ? count($logsSafeData['data']) : null;
    echo "  route=money: " . ($surfaceD_money !== null ? $surfaceD_money : 'ERROR') . " rows\n";
    echo "  route=safe: " . ($surfaceD_safe !== null ? $surfaceD_safe : 'ERROR') . " rows\n\n";

    // ===== Assert consistency =====
    echo "=== CONSISTENCY CHECK ===\n\n";
    echo "Expected: M=$M_MONEY money hits, N=$N_SAFE safe hits\n\n";

    $aMoneyOk = assertEquals($M_MONEY, $surfaceA_money, "Surface A: clicks table money count matches expected");
    $aSafeOk = assertEquals($N_SAFE, $surfaceA_safe, "Surface A: clicks table safe count matches expected");
    assertEquals($M_MONEY + $N_SAFE, $tableTotal, "Surface A: clicks table total = M+N");

    $bOk = assertEquals($M_MONEY, $surfaceB_money, "Surface B: campaigns API clicks = M (safe excluded, exclude_safe_from_reports)");

    $cMoneyOk = assertEquals($M_MONEY, $surfaceC_money, "Surface C: cloak_summary money count matches expected");
    $cSafeOk = assertEquals($N_SAFE, $surfaceC_safe, "Surface C: cloak_summary safe count matches expected");
    assertEquals($M_MONEY + $N_SAFE, $summaryTotal, "Surface C: cloak_summary total = M+N");

    $dMoneyOk = assertEquals($M_MONEY, $surfaceD_money, "Surface D: logs route=money count matches expected");
    assertEquals($N_SAFE, $surfaceD_safe, "Surface D: logs route=safe count matches expected");

    echo "\n";

    // Cross-surface summary
    $allMoneyMatch = ($surfaceA_money === $surfaceB_money && $surfaceB_money === $surfaceC_money && $surfaceC_money === $surfaceD_money);
    $allSafeMatch = ($surfaceA_safe === $surfaceC_safe && $surfaceC_safe === $surfaceD_safe);

    if ($allMoneyMatch && $allSafeMatch) {
        echo "✅ All surfaces agree on counts\n";
    } else {
        echo "❌ DISCREPANCY DETECT:\n";
        if (!$allMoneyMatch) {
            echo "  Money counts disagree:\n";
            echo "    A (clicks table): $surfaceA_money\n";
            echo "    B (campaigns API): " . var_export($surfaceB_money, true) . "\n";
            echo "    C (cloak_summary): " . var_export($surfaceC_money, true) . "\n";
            echo "    D (logs money): " . var_export($surfaceD_money, true) . "\n";
        }
        if (!$allSafeMatch) {
            echo "  Safe counts disagree:\n";
            echo "    A (clicks table): $surfaceA_safe\n";
            echo "    C (cloak_summary): " . var_export($surfaceC_safe, true) . "\n";
            echo "    D (logs safe): " . var_export($surfaceD_safe, true) . "\n";
        }
    }

    echo "\n";

    // Diagnosis hints
    if (!$aMoneyOk) {
        echo "DIAGNOSIS: money clicks are not being written to the clicks table at all\n";
        echo "(candidate A — an early exit on the local-offer path swallows the visit before\n";
        echo " orbitraPersistClick; check the orbitra_lo bridge / asset / cookie-less fallback exits).\n";
    }
    if ($aMoneyOk && !$bOk) {
        echo "DIAGNOSIS: money clicks are written but the campaigns surface miscounts them\n";
        echo "(candidate B — safe clicks leak in (missing exclusion in the date branch) or money\n";
        echo " clicks are filtered off via 'is_safe_page = 0' excluding NULL rows).\n";
    }
    if ($aMoneyOk && $bOk && (!$cMoneyOk || !$cSafeOk)) {
        echo "DIAGNOSIS: clicks table and campaigns agree, but cloak_summary counts differently\n";
        echo "(window/timezone mismatch or NULL-unfriendly is_safe_page comparison).\n";
    }

    // Clean up
    @unlink($offerDir . '/index.html');
    @rmdir($offerDir);

    if ($testPassed) {
        echo "\n✅ Test complete: all four surfaces reconcile.\n";
    } else {
        echo "\n❌ Test failed. Review discrepancies above.\n";
    }

} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Test error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    echo "\nStopping test server...\n";
    $harness->stop();
}

exit($testPassed ? 0 : 1);
