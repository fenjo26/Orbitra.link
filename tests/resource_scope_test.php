<?php
// tests/resource_scope_test.php
//
// Per-user access scoping for campaigns, offers, landings and domains
// (v1.10.0): "Full + Selected" / "Read only + Selected".
//
//   permissions_json.<resource> = {access:'selected', mode:'full'|'read',
//                                  groups:[group ids], items:[entity ids]}
//
// A scope-limited user sees: every member of a granted group (resolved live),
// every assigned item, plus what they created themselves (owner_user_id).
// The gate under test is core/resource_access.php: the central per-id check
// (orbitraEnforceScopedAction) for offers/landings/domains, list filtering in
// the api.php handlers, and the group / ownership rules of the save paths.
//
// As in resource_access_test.php, a passed gate lands in the handler (HTTP
// 200, whatever the JSON says) and a blocked one answers 403.
//
// Run: php tests/resource_scope_test.php

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
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    // --- helpers -----------------------------------------------------------
    $insertUser = static function (string $username, string $role, ?array $permissions) use ($pdo) {
        $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, ?, 1, ?)")
            ->execute([$username, password_hash('pass123', PASSWORD_DEFAULT), $role, $permissions === null ? '{}' : json_encode($permissions)]);
        return (int) $pdo->lastInsertId();
    };
    $setPerms = static function (int $userId, array $permissions) use ($pdo) {
        $pdo->prepare("UPDATE users SET permissions_json = ? WHERE id = ?")->execute([json_encode($permissions), $userId]);
    };
    $login = static function (string $username) use ($harness) {
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
        return ['Cookie' => 'ORBITRASESSID=' . $m[1], 'X-CSRF-TOKEN' => $body['data']['csrf_token'] ?? ''];
    };
    $get = static function (string $action, array $ctx) use ($harness) {
        return $harness->getWithHeaders("/api.php?action=$action", ['Cookie: ' . $ctx['Cookie']]);
    };
    $post = static function (string $action, array $ctx, array $payload = []) use ($harness) {
        return $harness->postWithHeaders(
            "/api.php?action=$action",
            json_encode($payload),
            ['Cookie: ' . $ctx['Cookie'], 'X-CSRF-TOKEN: ' . $ctx['X-CSRF-TOKEN'], 'Content-Type: application/json']
        );
    };
    $ids = static function (array $resp, string $key = 'id') {
        $body = json_decode($resp['body'], true);
        $out = [];
        foreach (($body['data'] ?? []) as $row) {
            $out[] = (int) $row[$key];
        }
        sort($out);
        return $out;
    };
    $sorted = static function (array $list) {
        sort($list);
        return $list;
    };
    $group = static function (string $table, string $name) use ($pdo) {
        $pdo->prepare("INSERT INTO $table (name) VALUES (?)")->execute([$name]);
        return (int) $pdo->lastInsertId();
    };
    $col = static function (string $sql, array $args = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    };

    // --- schema ------------------------------------------------------------
    foreach (['offers', 'landings', 'domains', 'campaigns'] as $table) {
        $columns = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $check("migration 81: $table.owner_user_id exists", true, in_array('owner_user_id', $columns, true));
    }

    // --- fixtures ----------------------------------------------------------
    $insertUser('scope_admin', 'admin', null);
    $selId = $insertUser('scope_sel', 'user', []);
    $roId = $insertUser('scope_ro', 'user', []);
    $fullId = $insertUser('scope_full', 'user', ['offers' => ['access' => 'full']]);
    $legacyId = $insertUser('scope_legacy', 'user', ['offers' => ['access' => 'selected', 'items' => []]]);

    $og1 = $group('offer_groups', 'Team A offers');
    $og2 = $group('offer_groups', 'Team B offers');
    $lg1 = $group('landing_groups', 'Team A landings');
    $lg2 = $group('landing_groups', 'Team B landings');
    $dg1 = $group('domain_groups', 'Team A domains');
    $dg2 = $group('domain_groups', 'Team B domains');
    $cg1 = $group('campaign_groups', 'Team A campaigns');
    $cg2 = $group('campaign_groups', 'Team B campaigns');

    $offer = static function (string $name, ?int $groupId, ?int $owner = null) use ($pdo) {
        $pdo->prepare("INSERT INTO offers (name, url, group_id, state, owner_user_id) VALUES (?, 'https://example.com/o', ?, 'active', ?)")
            ->execute([$name, $groupId, $owner]);
        return (int) $pdo->lastInsertId();
    };
    $landing = static function (string $name, ?int $groupId, ?int $owner = null) use ($pdo) {
        $pdo->prepare("INSERT INTO landings (name, url, group_id, type, state, owner_user_id) VALUES (?, 'https://example.com/l', ?, 'redirect', 'active', ?)")
            ->execute([$name, $groupId, $owner]);
        return (int) $pdo->lastInsertId();
    };
    $domain = static function (string $name, ?int $groupId, ?int $owner = null) use ($pdo) {
        $pdo->prepare("INSERT INTO domains (name, group_id, owner_user_id) VALUES (?, ?, ?)")->execute([$name, $groupId, $owner]);
        return (int) $pdo->lastInsertId();
    };
    $campaign = static function (string $name, string $alias, ?int $groupId, ?int $owner = null) use ($pdo) {
        $pdo->prepare("INSERT INTO campaigns (name, alias, state, group_id, owner_user_id) VALUES (?, ?, 'active', ?, ?)")
            ->execute([$name, $alias, $groupId, $owner]);
        return (int) $pdo->lastInsertId();
    };

    $oGroup = $offer('Scope offer in group A', $og1);
    $oItem = $offer('Scope offer item (group B)', $og2);
    $oForeign = $offer('Scope offer foreign (group B)', $og2);
    $oUngrouped = $offer('Scope offer ungrouped', null);
    $oOwned = $offer('Scope offer owned', null, $selId);

    $lGroup = $landing('Scope landing in group A', $lg1);
    $lItem = $landing('Scope landing item (group B)', $lg2);
    $lForeign = $landing('Scope landing foreign (group B)', $lg2);

    $dGroup = $domain('scope-a.example.test', $dg1);
    $dItem = $domain('scope-item.example.test', $dg2);
    $dForeign = $domain('scope-foreign.example.test', $dg2);

    $cGroup = $campaign('Scope campaign A', 'scope_camp_a', $cg1);
    $cForeign = $campaign('Scope campaign B', 'scope_camp_b', $cg2);

    $selPerms = [
        'campaigns' => ['access' => 'selected', 'mode' => 'full', 'groups' => [$cg1], 'items' => []],
        'offers' => ['access' => 'selected', 'mode' => 'full', 'groups' => [$og1], 'items' => [$oItem]],
        'landings' => ['access' => 'selected', 'mode' => 'full', 'groups' => [$lg1], 'items' => [$lItem]],
        'domains' => ['access' => 'selected', 'mode' => 'full', 'groups' => [$dg1], 'items' => [$dItem]],
    ];
    $setPerms($selId, $selPerms);
    $roPerms = $selPerms;
    foreach ($roPerms as $k => $v) {
        $roPerms[$k]['mode'] = 'read';
    }
    $setPerms($roId, $roPerms);

    $admin = $login('scope_admin');
    $sel = $login('scope_sel');
    $ro = $login('scope_ro');
    $full = $login('scope_full');
    $legacy = $login('scope_legacy');

    // =======================================================================
    // Offers
    // =======================================================================
    $expectedOffers = $sorted([$oGroup, $oItem, $oOwned]);
    $check('offers: list = group members + items + own', $expectedOffers, $ids($get('offers', $sel)));
    $check('offers: offers_simple filtered', $expectedOffers, $ids($get('offers_simple', $sel)));
    $check('offers: all_offers (stream picker) filtered', $expectedOffers, $ids($get('all_offers', $sel)));
    $check('offers: groups list = granted + groups of visible items', $sorted([$og1, $og2]), $ids($get('offer_groups', $sel)));
    $adminOffers = $ids($get('offers', $admin));
    $check('offers: admin sees everything', true, in_array($oForeign, $adminOffers, true) && in_array($oUngrouped, $adminOffers, true));
    $check('offers: full user sees everything', true, in_array($oForeign, $ids($get('offers_simple', $full)), true));
    $check('offers: pre-1.10 "selected" without groups stays full', true, in_array($oForeign, $ids($get('offers_simple', $legacy)), true));

    $check('offers: get in-scope (group)', 200, $get("get_offer&id=$oGroup", $sel)['code']);
    $check('offers: get in-scope (item)', 200, $get("get_offer&id=$oItem", $sel)['code']);
    $check('offers: get in-scope (own)', 200, $get("get_offer&id=$oOwned", $sel)['code']);
    $check('offers: get foreign blocked', 403, $get("get_offer&id=$oForeign", $sel)['code']);
    $check('offers: get ungrouped blocked', 403, $get("get_offer&id=$oUngrouped", $sel)['code']);
    $check('offers: files of foreign blocked', 403, $get("offer_files&id=$oForeign", $sel)['code']);
    // Ids the handler could read differently than the gate are refused.
    $check('offers: non-numeric id blocked', 403, $get("get_offer&id={$oGroup}abc", $sel)['code']);
    $check('offers: array id blocked', 403, $get("get_offer&id[]=$oGroup", $sel)['code']);
    $check('offers: mixed GET in-scope + body foreign blocked', 403, $harness->postWithHeaders(
        "/api.php?action=delete_offer&id=$oGroup",
        json_encode(['id' => $oForeign]),
        ['Cookie: ' . $sel['Cookie'], 'X-CSRF-TOKEN: ' . $sel['X-CSRF-TOKEN'], 'Content-Type: application/json']
    )['code']);

    $check('offers: update in-scope reaches handler', 200, $post('save_offer', $sel, ['id' => $oGroup, 'name' => 'Scope offer in group A', 'url' => 'https://example.com/o2', 'group_id' => $og1])['code']);
    $check('offers: update foreign blocked', 403, $post('save_offer', $sel, ['id' => $oForeign, 'name' => 'hijack', 'url' => 'https://evil.example/'])['code']);
    $check('offers: foreign url untouched', 'https://example.com/o', $pdo->query("SELECT url FROM offers WHERE id = $oForeign")->fetchColumn());
    $check('offers: delete foreign blocked', 403, $post('delete_offer', $sel, ['id' => $oForeign])['code']);
    $check('offers: copy foreign blocked', 403, $post('copy_offer', $sel, ['id' => $oForeign])['code']);
    $check('offers: bulk delete with one foreign id blocked', 403, $post('bulk_delete_offers', $sel, ['ids' => [$oGroup, $oForeign]])['code']);
    $check('offers: file write on foreign blocked', 403, $post('offer_save_file', $sel, ['id' => $oForeign, 'path' => 'index.html', 'content' => 'x'])['code']);

    // A shared (not owned) offer keeps its group whatever the form sends.
    $post('save_offer', $sel, ['id' => $oGroup, 'name' => 'Scope offer in group A', 'url' => 'https://example.com/o2', 'group_id' => $og2]);
    $check('offers: shared offer cannot be regrouped', $og1, $col("SELECT group_id FROM offers WHERE id = ?", [$oGroup]));
    $post('save_offer', $sel, ['id' => $oGroup, 'name' => 'Scope offer in group A', 'url' => 'https://example.com/o2']);
    $check('offers: shared offer cannot be ungrouped', $og1, $col("SELECT group_id FROM offers WHERE id = ?", [$oGroup]));

    // Creating: the creator owns the offer and keeps seeing it.
    $resp = $post('save_offer', $sel, ['name' => 'Scope offer created by sel', 'url' => 'https://example.com/new', 'group_id' => $og1]);
    $created = json_decode($resp['body'], true);
    $oCreated = (int) ($created['data']['id'] ?? 0);
    $check('offers: create passes', true, $resp['code'] === 200 && $oCreated > 0);
    $check('offers: creator stamped as owner', $selId, $col("SELECT owner_user_id FROM offers WHERE id = ?", [$oCreated]));
    $check('offers: created offer visible to creator', true, in_array($oCreated, $ids($get('offers_simple', $sel)), true));
    // An own offer may move between visible groups, never into a hidden one.
    $ogHidden = $group('offer_groups', 'Hidden offers');
    $post('save_offer', $sel, ['id' => $oCreated, 'name' => 'Scope offer created by sel', 'url' => 'https://example.com/new', 'group_id' => $ogHidden]);
    $check('offers: own offer cannot enter a hidden group', $og1, $col("SELECT group_id FROM offers WHERE id = ?", [$oCreated]));
    $post('save_offer', $sel, ['id' => $oCreated, 'name' => 'Scope offer created by sel', 'url' => 'https://example.com/new', 'group_id' => $og2]);
    $check('offers: own offer moves to a visible group', $og2, $col("SELECT group_id FROM offers WHERE id = ?", [$oCreated]));
    // Still theirs after leaving the granted group (ownership, not the group).
    $check('offers: own offer stays visible outside granted groups', 200, $get("get_offer&id=$oCreated", $sel)['code']);

    $copy = json_decode($post('copy_offer', $sel, ['id' => $oGroup])['body'], true);
    $check('offers: copy of a shared offer is owned by the copier', $selId, $col("SELECT owner_user_id FROM offers WHERE id = ?", [(int) ($copy['id'] ?? 0)]));
    $post('bulk_import_offers', $sel, ['items' => [['name' => 'Scope imported offer', 'url' => 'https://example.com/imp']]]);
    $check('offers: imported offer is owned by the importer', $selId, $col("SELECT owner_user_id FROM offers WHERE name = 'Scope imported offer'"));

    // A full-access (unscoped) non-admin creator is stamped too; an admin is not.
    $resp = json_decode($post('save_offer', $full, ['name' => 'Scope offer by full user', 'url' => 'https://example.com/f'])['body'], true);
    $check('offers: full user creation stamped', $fullId, $col("SELECT owner_user_id FROM offers WHERE id = ?", [(int) ($resp['data']['id'] ?? 0)]));
    $resp = json_decode($post('save_offer', $admin, ['name' => 'Scope offer by admin', 'url' => 'https://example.com/a'])['body'], true);
    $check('offers: admin creation stays unowned', null, $col("SELECT owner_user_id FROM offers WHERE id = ?", [(int) ($resp['data']['id'] ?? 0)]));

    // Groups are dynamic: what an admin drops into a granted group appears.
    $pdo->prepare("UPDATE offers SET group_id = ? WHERE id = ?")->execute([$og1, $oUngrouped]);
    $check('offers: entity added to a granted group becomes visible', 200, $get("get_offer&id=$oUngrouped", $sel)['code']);
    $pdo->prepare("UPDATE offers SET group_id = NULL WHERE id = ?")->execute([$oUngrouped]);
    $check('offers: entity removed from the group disappears', 403, $get("get_offer&id=$oUngrouped", $sel)['code']);

    // Group management is for full access only.
    $check('offers: create group blocked', 403, $post('offer_groups', $sel, ['name' => 'Sneaky'])['code']);
    $check('offers: rename group blocked', 403, $post('rename_offer_group', $sel, ['id' => $og1, 'name' => 'Renamed'])['code']);
    $check('offers: delete group blocked', 403, $post('delete_offer_group', $sel, ['id' => $og1])['code']);
    $check('offers: full user may create a group', 200, $post('offer_groups', $full, ['name' => 'By full user'])['code']);

    // Read only + Selected: same visibility (the granted group has grown —
    // it now also holds what scope_sel created in it), no writes at all.
    $inGroupOrItem = static function (string $table, int $groupId, array $items) use ($pdo) {
        $out = array_map('intval', $pdo->query("SELECT id FROM $table WHERE group_id = $groupId")->fetchAll(PDO::FETCH_COLUMN));
        $out = array_values(array_unique(array_merge($out, $items)));
        sort($out);
        return $out;
    };
    $roOffers = $ids($get('offers_simple', $ro));
    $check('offers ro: list = live group members + items', $inGroupOrItem('offers', $og1, [$oItem]), $roOffers);
    $check('offers ro: no foreign offer listed', false, in_array($oForeign, $roOffers, true));
    $check('offers ro: get in-scope', 200, $get("get_offer&id=$oGroup", $ro)['code']);
    $check('offers ro: get foreign blocked', 403, $get("get_offer&id=$oForeign", $ro)['code']);
    $check('offers ro: update in-scope blocked', 403, $post('save_offer', $ro, ['id' => $oGroup, 'name' => 'x', 'url' => 'https://evil.example/'])['code']);
    $check('offers ro: create blocked', 403, $post('save_offer', $ro, ['name' => 'x', 'url' => 'https://example.com/'])['code']);
    $check('offers ro: copy blocked', 403, $post('copy_offer', $ro, ['id' => $oGroup])['code']);
    $check('offers ro: delete blocked', 403, $post('delete_offer', $ro, ['id' => $oGroup])['code']);

    // =======================================================================
    // Landings
    // =======================================================================
    $expectedLandings = $sorted([$lGroup, $lItem]);
    $check('landings: list filtered', $expectedLandings, $ids($get('landings', $sel)));
    $check('landings: landings_simple filtered', $expectedLandings, $ids($get('landings_simple', $sel)));
    $check('landings: groups list filtered', $sorted([$lg1, $lg2]), $ids($get('landing_groups', $sel)));
    $check('landings: get in-scope', 200, $get("get_landing&id=$lGroup", $sel)['code']);
    $check('landings: get foreign blocked', 403, $get("get_landing&id=$lForeign", $sel)['code']);
    $check('landings: files of foreign blocked', 403, $get("landing_files&id=$lForeign", $sel)['code']);
    $check('landings: pwa config of foreign blocked', 403, $get("pwa_config_get&id=$lForeign", $sel)['code']);
    $check('landings: update foreign blocked', 403, $post('save_landing', $sel, ['id' => $lForeign, 'name' => 'hijack', 'type' => 'redirect', 'url' => 'https://evil.example/'])['code']);
    $check('landings: foreign url untouched', 'https://example.com/l', $pdo->query("SELECT url FROM landings WHERE id = $lForeign")->fetchColumn());
    $check('landings: delete foreign blocked', 403, $post('delete_landing', $sel, ['id' => $lForeign])['code']);
    $check('landings: copy foreign blocked', 403, $post('copy_landing', $sel, ['id' => $lForeign])['code']);
    $check('landings: bulk delete with a foreign id blocked', 403, $post('bulk_delete_landings', $sel, ['ids' => [$lForeign]])['code']);
    $check('landings: file op on foreign blocked', 403, $post('landing_file_op', $sel, ['op' => 'save', 'id' => $lForeign, 'path' => 'index.html', 'content' => 'x'])['code']);
    $check('landings: recording settings of foreign blocked', 403, $get("replay_landing_config&landing_id=$lForeign", $sel)['code']);
    $check('landings: recording settings in-scope pass', 200, $get("replay_landing_config&landing_id=$lGroup", $sel)['code']);
    $check('landings: global replay storage settings blocked', 403, $post('replay_storage_save', $sel, [])['code']);
    $check('landings: create group blocked', 403, $post('landing_groups', $sel, ['name' => 'Sneaky'])['code']);
    $check('landings: rename group blocked', 403, $post('rename_landing_group', $sel, ['id' => $lg1, 'name' => 'Renamed'])['code']);

    $resp = $post('save_landing', $sel, ['name' => 'Scope landing created by sel', 'type' => 'redirect', 'url' => 'https://example.com/nl', 'group_id' => $lg1]);
    $lCreated = (int) (json_decode($resp['body'], true)['data']['id'] ?? 0);
    $check('landings: create passes', true, $resp['code'] === 200 && $lCreated > 0);
    $check('landings: creator stamped as owner', $selId, $col("SELECT owner_user_id FROM landings WHERE id = ?", [$lCreated]));
    $check('landings: created landing visible', true, in_array($lCreated, $ids($get('landings_simple', $sel)), true));
    $post('save_landing', $sel, ['id' => $lGroup, 'name' => 'Scope landing in group A', 'type' => 'redirect', 'url' => 'https://example.com/l', 'group_id' => $lg2]);
    $check('landings: shared landing cannot be regrouped', $lg1, $col("SELECT group_id FROM landings WHERE id = ?", [$lGroup]));
    $post('bulk_import_landings', $sel, ['items' => [['name' => 'Scope imported landing', 'url' => 'https://example.com/il', 'type' => 'redirect']]]);
    $check('landings: imported landing is owned by the importer', $selId, $col("SELECT owner_user_id FROM landings WHERE name = 'Scope imported landing'"));

    $roLandings = $ids($get('landings_simple', $ro));
    $check('landings ro: list = live group members + items', $inGroupOrItem('landings', $lg1, [$lItem]), $roLandings);
    $check('landings ro: no foreign landing listed', false, in_array($lForeign, $roLandings, true));
    $check('landings ro: update in-scope blocked', 403, $post('save_landing', $ro, ['id' => $lGroup, 'name' => 'x', 'type' => 'redirect', 'url' => 'https://evil.example/'])['code']);
    $check('landings ro: create blocked', 403, $post('save_landing', $ro, ['name' => 'x', 'type' => 'redirect', 'url' => 'https://example.com/'])['code']);

    // =======================================================================
    // Domains
    // =======================================================================
    $expectedDomains = $sorted([$dGroup, $dItem]);
    $check('domains: domains_brief filtered', $expectedDomains, $ids($get('domains_brief', $sel)));
    $check('domains: groups list filtered', $sorted([$dg1, $dg2]), $ids($get('domain_groups', $sel)));
    $check('domains: admin brief sees foreign', true, in_array($dForeign, $ids($get('domains_brief', $admin)), true));
    $check('domains: delete foreign blocked', 403, $post('delete_domain', $sel, ['id' => $dForeign])['code']);
    $check('domains: foreign still exists', $dForeign, $col("SELECT id FROM domains WHERE id = ?", [$dForeign]));
    $check('domains: update foreign blocked', 403, $post('save_domain', $sel, ['id' => $dForeign, 'name' => 'scope-foreign.example.test'])['code']);
    $check('domains: dns check of foreign blocked', 403, $get("check_domain_dns&id=$dForeign", $sel)['code']);
    $check('domains: cloudflare check by name blocked', 403, $get('check_cloudflare_status&domain=scope-foreign.example.test', $sel)['code']);
    // Shared infrastructure stays with full access.
    $check('domains: zone listing of a DNS account blocked', 403, $get('cloudflare_account_zones&id=1', $sel)['code']);
    $check('domains: registrar inventory blocked', 403, $get('namecheap_domains', $sel)['code']);
    $check('domains: nginx regeneration blocked', 403, $post('regenerate_nginx', $sel, [])['code']);
    $check('domains: saving a DNS account blocked', 403, $post('cloudflare_account_save', $sel, ['name' => 'x', 'token' => 'y'])['code']);
    $check('domains: backorder list blocked', 403, $get('backorder_domains', $sel)['code']);
    $check('domains: account names for the add form stay readable', 200, $get('cloudflare_accounts_list', $sel)['code']);
    $check('domains: create group blocked', 403, $post('domain_groups', $sel, ['name' => 'Sneaky'])['code']);
    $check('domains: rename group blocked', 403, $post('rename_domain_group', $sel, ['id' => $dg1, 'name' => 'Renamed'])['code']);
    $check('domains ro: brief filtered', $expectedDomains, $ids($get('domains_brief', $ro)));
    $check('domains ro: delete in-scope blocked', 403, $post('delete_domain', $ro, ['id' => $dGroup])['code']);
    $check('domains ro: update in-scope blocked', 403, $post('save_domain', $ro, ['id' => $dGroup, 'name' => 'scope-a.example.test'])['code']);

    // =======================================================================
    // Campaigns: groups, creation, and what a campaign may pull in
    // =======================================================================
    $check('campaigns: list = members of the granted group', [$cGroup], $ids($get('campaigns_simple', $sel)));
    $check('campaigns: groups list filtered', [$cg1], $ids($get('campaign_groups', $sel)));
    $check('campaigns: get foreign blocked', 403, $get("get_campaign&id=$cForeign", $sel)['code']);
    $check('campaigns: delete foreign blocked', 403, $post('delete_campaign', $sel, ['id' => $cForeign])['code']);
    $check('campaigns: create group blocked', 403, $post('campaign_groups', $sel, ['name' => 'Sneaky'])['code']);
    $check('campaigns: rename group blocked', 403, $post('rename_campaign_group', $sel, ['id' => $cg1, 'name' => 'Renamed'])['code']);
    $check('campaigns: delete group blocked', 403, $post('delete_campaign_group', $sel, ['id' => $cg1])['code']);

    $resp = $post('save_campaign', $sel, ['name' => 'Scope campaign by sel', 'alias' => 'scope_camp_sel', 'group_id' => $cg1, 'streams' => []]);
    $cCreated = (int) (json_decode($resp['body'], true)['data']['id'] ?? 0);
    $check('campaigns: selected user creates', true, $resp['code'] === 200 && $cCreated > 0);
    $check('campaigns: creator stamped as owner', $selId, $col("SELECT owner_user_id FROM campaigns WHERE id = ?", [$cCreated]));
    $check('campaigns: created campaign visible', true, in_array($cCreated, $ids($get('campaigns_simple', $sel)), true));

    $stream = static function (int $offerId, array $custom = []) {
        return ['name' => 'S', 'type' => 'regular', 'position' => 0, 'weight' => 100, 'is_active' => 1,
            'schema_type' => 'redirect', 'offer_id' => $offerId, 'schema_custom' => $custom, 'filters' => []];
    };
    $base = ['id' => $cCreated, 'name' => 'Scope campaign by sel', 'alias' => 'scope_camp_sel', 'group_id' => $cg1];
    $check('campaigns: attaching an in-scope offer passes', 200, $post('save_campaign', $sel, $base + ['streams' => [$stream($oGroup)]])['code']);
    $check('campaigns: attaching a foreign offer blocked', 403, $post('save_campaign', $sel, $base + ['streams' => [$stream($oForeign)]])['code']);
    $check('campaigns: foreign offer inside schema_custom blocked', 403, $post('save_campaign', $sel, $base + ['streams' => [$stream($oGroup, ['offers' => [['id' => $oForeign, 'weight' => 100]]])]])['code']);
    $check('campaigns: foreign landing inside schema_custom blocked', 403, $post('save_campaign', $sel, $base + ['streams' => [$stream($oGroup, ['landings' => [['id' => $lForeign, 'weight' => 100]]])]])['code']);
    $check('campaigns: in-scope landing passes', 200, $post('save_campaign', $sel, $base + ['streams' => [$stream($oGroup, ['landings' => [['id' => $lGroup, 'weight' => 100]]])]])['code']);
    $check('campaigns: foreign domain blocked', 403, $post('save_campaign', $sel, $base + ['domain_id' => $dForeign, 'streams' => []])['code']);
    $check('campaigns: in-scope domain passes', 200, $post('save_campaign', $sel, $base + ['domain_id' => $dGroup, 'streams' => []])['code']);
    $check('campaigns: bulk parking on a foreign domain blocked', 403, $post('bulk_set_campaign_domain', $sel, ['ids' => [$cCreated], 'domain_id' => $dForeign])['code']);

    // What an admin attached earlier stays editable around: the user saves
    // the campaign without being able to add more hidden entities.
    $pdo->prepare("INSERT INTO streams (campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active) VALUES (?, ?, 'Admin stream', 'regular', 0, 'redirect', '{}', 1)")
        ->execute([$cGroup, $oForeign]);
    $check('campaigns: an already-attached foreign offer may stay', 200, $post('save_campaign', $sel, ['id' => $cGroup, 'name' => 'Scope campaign A', 'alias' => 'scope_camp_a', 'streams' => [$stream($oForeign)]])['code']);
    $check('campaigns: shared campaign cannot be regrouped', $cg1, $col("SELECT group_id FROM campaigns WHERE id = ?", [$cGroup]));

    $roCampaigns = $ids($get('campaigns_simple', $ro));
    $check('campaigns ro: list = live group members', $inGroupOrItem('campaigns', $cg1, []), $roCampaigns);
    $check('campaigns ro: no foreign campaign listed', false, in_array($cForeign, $roCampaigns, true));
    $check('campaigns ro: create blocked', 403, $post('save_campaign', $ro, ['name' => 'x', 'alias' => 'x'])['code']);
    $check('campaigns ro: update in-scope blocked', 403, $post('save_campaign', $ro, ['id' => $cGroup, 'name' => 'x', 'alias' => 'scope_camp_a'])['code']);
    $check('campaigns ro: get foreign blocked', 403, $get("get_campaign&id=$cForeign", $ro)['code']);

    // =======================================================================
    // Archive
    // =======================================================================
    $pdo->exec("UPDATE offers SET is_archived = 1, archived_at = datetime('now') WHERE id IN ($oItem, $oForeign)");
    $archive = json_decode($get('archive_items', $sel)['body'], true)['data'] ?? [];
    $check('archive: only in-scope offers listed', [$oItem], array_map('intval', array_column($archive['offers'] ?? [], 'id')));
    $check('archive: restore foreign blocked', 403, $post('archive_restore', $sel, ['type' => 'offers', 'id' => $oForeign])['code']);
    $check('archive: restore-all blocked', 403, $post('archive_restore', $sel, ['type' => 'offers', 'action' => 'restore_all'])['code']);
    $check('archive: purge foreign blocked', 403, $post('archive_purge', $sel, ['type' => 'offers', 'id' => $oForeign])['code']);
    $check('archive: restore in-scope passes', 200, $post('archive_restore', $sel, ['type' => 'offers', 'id' => $oItem])['code']);
    $check('archive: foreign offer still archived', 1, $col("SELECT is_archived FROM offers WHERE id = ?", [$oForeign]));
    $check('archive ro: restore in-scope blocked', 403, $post('archive_restore', $ro, ['type' => 'offers', 'id' => $oItem])['code']);

    // =======================================================================
    // Logs follow the campaign scope
    // =======================================================================
    require_once $repoRoot . '/core/logs_query.php';
    [$sqlScoped] = orbitraLogsBuildQuery('traffic', ['scope_campaign_ids' => [3, 5]], '+00:00', 0);
    $check('logs: traffic limited to the scope', true, strpos($sqlScoped, 'cl.campaign_id IN (3,5)') !== false);
    [$sqlEmpty] = orbitraLogsBuildQuery('traffic', ['scope_campaign_ids' => []], '+00:00', 0);
    $check('logs: empty scope selects nothing', true, strpos($sqlEmpty, '1 = 0') !== false);
    [$sqlAudit] = orbitraLogsBuildQuery('audit', ['scope_campaign_ids' => [3]], '+00:00', 0);
    $check('logs: tracker-wide logs closed to scoped users', true, strpos($sqlAudit, '1 = 0') !== false);
    [$sqlFree] = orbitraLogsBuildQuery('traffic', ['scope_campaign_ids' => null], '+00:00', 0);
    $check('logs: unrestricted user is not narrowed', false, strpos($sqlFree, '1 = 0') !== false || strpos($sqlFree, 'campaign_id IN') !== false);
    $check('logs: scoped user request passes', 200, $get('logs&type=traffic', $sel)['code']);
    $check('logs: scoped user audit log is empty', [], json_decode($get('logs&type=audit', $sel)['body'], true)['data'] ?? null);

    // =======================================================================
    // A deleted account loses its live session
    // =======================================================================
    $goneId = $insertUser('scope_gone', 'user', []);
    $gone = $login('scope_gone');
    $check('session: works before deletion', 200, $get('offers_simple', $gone)['code']);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$goneId]);
    $check('session: deleted account is signed out', 401, $get('offers_simple', $gone)['code']);
    $demotedId = $insertUser('scope_demoted', 'admin', null);
    $demoted = $login('scope_demoted');
    $check('session: admin reaches users list', true, (json_decode($get('users', $demoted)['body'], true)['status'] ?? '') === 'success');
    $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([$demotedId]);
    $check('session: demoted admin loses admin rights at once', false, (json_decode($get('users', $demoted)['body'], true)['status'] ?? '') === 'success');
} finally {
    $harness->stop();
}

echo "resource_scope_test: $checks checks, " . count($failures) . " failures\n";
foreach ($failures as $f) {
    echo "  FAIL $f\n";
}
exit($failures ? 1 : 0);
