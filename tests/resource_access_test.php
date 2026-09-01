<?php
// tests/resource_access_test.php
//
// GitHub issue #6: resource scopes from the users-page permissions were
// stored in permissions_json but never enforced — a 'Read only' or 'None'
// account could list, edit and delete every campaign over the API.
//
// The gate under test is core/resource_access.php, wired into api.php right
// after the authentication middleware. The enforcement matrix:
//   admin                 → everything allowed;
//   access 'full'         → reads and writes allowed;
//   access 'read'         → reads allowed, writes 403;
//   access 'none'         → everything 403;
//   legacy 'selected'/'own' → still 'full' (they never meant anything else).
// 'save_*' calls are sent with an empty body on purpose: a passed gate lands
// in the handler's own validation (HTTP 200 + error JSON), a blocked one
// never gets there (HTTP 403).
//
// Run: php tests/resource_access_test.php

if (getenv('ORBITRA_RBAC_PROBE') === '1') {
    // Child process is not used by this test; guard kept for symmetry.
    exit(0);
}

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';

$failures = [];
$checks = 0;

$check = static function (string $name, $expected, $actual) use (&$failures, &$checks) {
    $checks++;
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$harness = new OrbitraTestHarness($repoRoot);
// index.php (the default router) knows nothing about /api.php — the panel
// path lives behind router.php.
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    // --- fixtures: one admin + scoped users -------------------------------
    $insertUser = static function (string $username, string $role, ?array $permissions) use ($pdo) {
        $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, ?, 1, ?)")
            ->execute([
                $username,
                password_hash('pass123', PASSWORD_DEFAULT),
                $role,
                $permissions === null ? '{}' : json_encode($permissions),
            ]);
        return (int) $pdo->lastInsertId();
    };

    $insertUser('rbac_admin', 'admin', null);
    $insertUser('rbac_full', 'user', ['campaigns' => ['access' => 'full']]);
    $insertUser('rbac_read', 'user', ['campaigns' => ['access' => 'read']]);
    $insertUser('rbac_none', 'user', ['campaigns' => ['access' => 'none']]);
    // campaigns writable, offers read-only — proves the gate is per-resource.
    $insertUser('rbac_mixed', 'user', [
        'campaigns' => ['access' => 'full'],
        'offers' => ['access' => 'read'],
    ]);
    // Legacy levels must keep their historical meaning (= full).
    $insertUser('rbac_legacy', 'user', ['campaigns' => ['access' => 'own']]);

    // A campaign row every scoped account can see if the gate lets it.
    $pdo->exec("INSERT INTO campaigns (name, alias, state) VALUES ('RBAC Target', 'rbac_target', 'active')");
    $campaignId = (int) $pdo->lastInsertId();

    // --- session helpers ---------------------------------------------------
    $login = static function (string $username) use ($harness) {
        // The panel's own login rate limit is 5 attempts / 5 min per IP; the
        // matrix needs one session per user, so clear the counter between.
        // The sandbox schema may not carry the table at all.
        try {
            $harness->getPdo()->exec('DELETE FROM rate_limits');
        } catch (\Throwable $e) {
        }
        $resp = $harness->postWithHeaders(
            '/api.php?action=login',
            json_encode(['username' => $username, 'password' => 'pass123']),
            ['Content-Type: application/json']
        );
        $body = json_decode($resp['body'], true);
        if (($body['status'] ?? '') !== 'success') {
            fwrite(STDERR, "login failed for $username: " . $resp['body'] . "\n");
            exit(1);
        }
        preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        return [
            'Cookie' => 'ORBITRASESSID=' . $m[1],
            'X-CSRF-TOKEN' => $body['data']['csrf_token'] ?? '',
            'Content-Type' => 'application/json',
        ];
    };

    $get = static function (string $action, array $ctx) use ($harness) {
        return $harness->getWithHeaders("/api.php?action=$action", [
            'Cookie: ' . $ctx['Cookie'],
        ]);
    };

    $post = static function (string $action, array $ctx, array $payload = []) use ($harness) {
        return $harness->postWithHeaders(
            "/api.php?action=$action",
            json_encode($payload),
            ['Cookie: ' . $ctx['Cookie'], 'X-CSRF-TOKEN: ' . $ctx['X-CSRF-TOKEN'], 'Content-Type: application/json']
        );
    };

    // --- the matrix ---------------------------------------------------------
    $admin = $login('rbac_admin');
    $full = $login('rbac_full');
    $read = $login('rbac_read');
    $none = $login('rbac_none');
    $mixed = $login('rbac_mixed');
    $legacy = $login('rbac_legacy');

    // Admin is never gated.
    $check('admin: campaigns list allowed', 200, $get('campaigns', $admin)['code']);
    $check('admin: save_campaign reaches handler', 200, $post('save_campaign', $admin, ['name' => 'x'])['code']);

    // 'full': reads and writes pass (empty save body → handler validation).
    $check('full: campaigns list allowed', 200, $get('campaigns', $full)['code']);
    $check('full: get_campaign allowed', 200, $get("get_campaign&id=$campaignId", $full)['code']);
    $check('full: save_campaign reaches handler', 200, $post('save_campaign', $full, ['name' => 'x'])['code']);

    // 'read': reads pass, writes are 403 — the actual hole from the issue.
    $check('read: campaigns list allowed', 200, $get('campaigns', $read)['code']);
    $check('read: campaigns_simple allowed', 200, $get('campaigns_simple', $read)['code']);
    $check('read: get_campaign allowed', 200, $get("get_campaign&id=$campaignId", $read)['code']);
    $check('read: save_campaign blocked', 403, $post('save_campaign', $read, ['name' => 'x'])['code']);
    $check('read: delete_campaign blocked', 403, $post('delete_campaign', $read, ['id' => $campaignId])['code']);
    $check('read: copy_campaign blocked', 403, $post('copy_campaign', $read, ['id' => $campaignId])['code']);
    $check('read: bulk_delete_campaigns blocked', 403, $post('bulk_delete_campaigns', $read, ['ids' => [$campaignId]])['code']);
    $check('read: clear_stats blocked', 403, $post('clear_stats', $read)['code']);
    // regenerate_campaign_token carries its own inline admin gate (pre-dating
    // the central one); legacy gates answer 200 + "Forbidden" instead of 403.
    $regenResp = $post('regenerate_campaign_token', $read, ['id' => $campaignId]);
    $check('read: regenerate_campaign_token blocked',
        'Forbidden',
        json_decode($regenResp['body'], true)['message'] ?? null);

    // 'none': nothing passes, not even the list — the tab is hidden anyway.
    $check('none: campaigns list blocked', 403, $get('campaigns', $none)['code']);
    $check('none: campaigns_simple blocked', 403, $get('campaigns_simple', $none)['code']);
    $check('none: get_campaign blocked', 403, $get("get_campaign&id=$campaignId", $none)['code']);
    $check('none: conversions blocked', 403, $get('conversions', $none)['code']);
    $check('none: campaign_groups blocked', 403, $get('campaign_groups', $none)['code']);
    $check('none: save_campaign blocked', 403, $post('save_campaign', $none, ['name' => 'x'])['code']);

    // Per-resource isolation: mixed user writes campaigns, only reads offers.
    $check('mixed: campaigns write reaches handler', 200, $post('save_campaign', $mixed, ['name' => 'x'])['code']);
    $check('mixed: offers list allowed', 200, $get('offers', $mixed)['code']);
    $check('mixed: save_offer blocked', 403, $post('save_offer', $mixed, ['name' => 'x'])['code']);
    $check('mixed: delete_offer blocked', 403, $post('delete_offer', $mixed, ['id' => 1])['code']);

    // Legacy 'own' keeps behaving like full.
    $check('legacy own: campaigns list allowed', 200, $get('campaigns', $legacy)['code']);
    $check('legacy own: save_campaign reaches handler', 200, $post('save_campaign', $legacy, ['name' => 'x'])['code']);

    // Archive family: listing spans five resources, restore is a write on the
    // item's own type only.
    $check('archive: none on campaigns blocks archive_items', 403, $get('archive_items', $none)['code']);
    $check('archive: read on campaigns blocks archive_restore', 403, $post('archive_restore', $read, ['type' => 'campaigns', 'id' => $campaignId])['code']);
    $check('archive: full on campaigns passes archive_restore', 200, $post('archive_restore', $full, ['type' => 'campaigns', 'id' => $campaignId])['code']);

    // Adjacent hole from the same audit: API keys are minted under an
    // arbitrary user_id — admin-only now, same as every other user endpoint.
    $check('api keys: non-admin generate blocked', 403, $post('generate_api_key', $read, ['user_id' => 1, 'key_name' => 'x'])['code']);
    $check('api keys: non-admin delete blocked', 403, $post('delete_api_key', $read, ['id' => 1])['code']);
    $check('api keys: admin generate allowed', 200, $post('generate_api_key', $admin, ['user_id' => 1, 'key_name' => 'rbac-test'])['code']);

    // Unmapped actions keep their behavior for every authenticated user:
    // dashboard analytics is open to all (finance masking still applies).
    $check('unmapped: metrics stays open for none-user', 200, $get('metrics', $none)['code']);
    $check('unmapped: chart stays open for none-user', 200, $get('chart', $none)['code']);

    // ---------------------------------------------------------------------
    // Per-campaign scoping (issue #6, "Own + Selected"): owner_user_id plus
    // permissions_json.campaigns.items narrow lists, details, writes and
    // analytics for 'own' and 'selected' users.
    // ---------------------------------------------------------------------
    $ownId = $insertUser('rbac_own', 'user', ['campaigns' => ['access' => 'own']]);
    $selId = $insertUser('rbac_sel', 'user', ['campaigns' => ['access' => 'selected']]);

    $insertCampaign = static function (string $name, string $alias, $ownerId) use ($pdo) {
        $pdo->prepare("INSERT INTO campaigns (name, alias, state, owner_user_id) VALUES (?, ?, 'active', ?)")
            ->execute([$name, $alias, $ownerId]);
        return (int) $pdo->lastInsertId();
    };
    $ownCampaign = $insertCampaign('RBAC Own', 'rbac_own_c', $ownId);       // owned by rbac_own
    $itemCampaign = $insertCampaign('RBAC Item', 'rbac_item_c', null);      // assigned via items
    $foreignCampaign = $insertCampaign('RBAC Foreign', 'rbac_foreign_c', null); // nobody's — invisible to scoped

    $ownPerms = ['campaigns' => ['access' => 'own', 'items' => [$itemCampaign]]];
    $selPerms = ['campaigns' => ['access' => 'selected', 'items' => [$itemCampaign]]];
    $pdo->prepare("UPDATE users SET permissions_json = ? WHERE id = ?")->execute([json_encode($ownPerms), $ownId]);
    $pdo->prepare("UPDATE users SET permissions_json = ? WHERE id = ?")->execute([json_encode($selPerms), $selId]);

    $own = $login('rbac_own');
    $sel = $login('rbac_sel');

    // Migrations stamped the sandbox DB and the scoping column exists. The
    // expected version is read from config.php so this check survives future
    // bumps (it drifted and failed on 43 and 44 before becoming dynamic).
    preg_match('/LATEST_SCHEMA_VERSION\s*=\s*(\d+)/', file_get_contents($repoRoot . '/config.php'), $schemaM);
    $check('config schema version parsed', true, (int) ($schemaM[1] ?? 0) > 0);
    $columns = array_column($pdo->query("PRAGMA table_info(campaigns)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $check('migration 42: owner_user_id column exists', true, in_array('owner_user_id', $columns, true));
    $check('migrations: schema stamped', (int) ($schemaM[1] ?? 0), (int) $pdo->query('PRAGMA user_version')->fetchColumn());

    $listIds = static function (array $ctx) use ($get) {
        $body = json_decode($get('campaigns', $ctx)['body'], true);
        $ids = [];
        foreach (($body['data'] ?? []) as $row) {
            $ids[] = (int) $row['id'];
        }
        sort($ids);
        return $ids;
    };

    // 'own' → owned + assigned; nobody else's campaigns, not even unowned ones.
    $ownExpected = [$itemCampaign, $ownCampaign];
    sort($ownExpected);
    $check('own: list shows owned + assigned only', $ownExpected, $listIds($own));
    $simpleIds = array_map(function ($r) {
        return (int) $r['id'];
    }, json_decode($get('campaigns_simple', $own)['body'], true)['data'] ?? []);
    sort($simpleIds);
    $check('own: campaigns_simple filtered too', $ownExpected, $simpleIds);

    $check('own: get_campaign on own campaign', 200, $get("get_campaign&id=$ownCampaign", $own)['code']);
    $check('own: get_campaign on foreign campaign blocked', 403, $get("get_campaign&id=$foreignCampaign", $own)['code']);

    // Writes inside the scope pass (handler validation answers 200), outside — 403.
    $check('own: save own campaign reaches handler', 200, $post('save_campaign', $own, ['id' => $ownCampaign, 'name' => 'x'])['code']);
    $check('own: save foreign campaign blocked', 403, $post('save_campaign', $own, ['id' => $foreignCampaign, 'name' => 'x'])['code']);
    $check('own: delete foreign campaign blocked', 403, $post('delete_campaign', $own, ['id' => $foreignCampaign])['code']);

    // Creation: 'own' users own what they create — verify the DB column.
    $post('save_campaign', $own, ['name' => 'RBAC Created', 'alias' => 'rbac_created']);
    $createdOwner = $pdo->query("SELECT owner_user_id FROM campaigns WHERE alias = 'rbac_created'")->fetchColumn();
    $check('own: created campaign is owned by creator', $ownId, (int) $createdOwner);

    // Global campaigns writes stay admin/full.
    $check('own: clear_stats blocked', 403, $post('clear_stats', $own)['code']);
    $check('own: import_conversions blocked', 403, $post('import_conversions', $own, ['csv_data' => 'x'])['code']);

    // 'selected' → only the assigned items, no creation.
    $check('selected: list shows assigned only', [$itemCampaign], $listIds($sel));
    $check('selected: create blocked', 403, $post('save_campaign', $sel, ['name' => 'x'])['code']);
    $check('selected: save assigned campaign reaches handler', 200, $post('save_campaign', $sel, ['id' => $itemCampaign, 'name' => 'x'])['code']);
    $check('selected: delete outside scope blocked', 403, $post('delete_campaign', $sel, ['id' => $ownCampaign])['code']);

    // Analytics: conversions and reports only aggregate the scoped campaigns.
    $insertClick = static function (string $clickId, int $campaignId) use ($pdo) {
        $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip) VALUES (?, ?, '203.0.113.9')")
            ->execute([$clickId, $campaignId]);
    };
    $insertConversion = static function (string $clickId, int $campaignId, string $status) use ($pdo) {
        $pdo->prepare("INSERT INTO conversions (click_id, status, campaign_id) VALUES (?, ?, ?)")
            ->execute([$clickId, $status, $campaignId]);
    };
    $insertClick('rbac_click_own', $ownCampaign);
    $insertClick('rbac_click_foreign', $foreignCampaign);
    $insertConversion('rbac_click_own', $ownCampaign, 'sale');
    $insertConversion('rbac_click_foreign', $foreignCampaign, 'sale');

    $convCampaigns = array_map(function ($r) {
        return (int) $r['campaign_id'];
    }, json_decode($get('conversions', $own)['body'], true)['data'] ?? []);
    $convCampaigns = array_values(array_unique($convCampaigns));
    sort($convCampaigns);
    $check('own: conversions filtered to scope', [$ownCampaign], $convCampaigns);

    $reportResp = $get("campaign_report&group_by=campaign_id", $own);
    $reportBody = json_decode($reportResp['body'], true);
    $reportCampaigns = [];
    foreach (($reportBody['data']['rows'] ?? []) as $row) {
        $cid = (int) (($row['dim_ids'][0] ?? $row['dim_1'] ?? 0));
        if ($cid > 0) {
            $reportCampaigns[] = $cid;
        }
    }
    $reportCampaigns = array_values(array_unique($reportCampaigns));
    sort($reportCampaigns);
    // Both seeded campaigns have clicks; only the owned one may surface.
    $check('own: campaign_report shows only scoped campaigns', [$ownCampaign], $reportCampaigns);
} finally {
    $harness->stop();
}

echo "resource_access_test: $checks checks, " . count($failures) . " failures\n";
foreach ($failures as $f) {
    echo "  FAIL $f\n";
}
exit($failures ? 1 : 0);
