<?php

// Central resource-access enforcement for non-admin panel users
// (GitHub issue #6: campaign scopes were stored but never enforced).
//
// The users-page modal stores an access level per resource:
//   'none'            — every action of that resource is denied (403);
//   'read'            — read actions pass, write actions are denied (403);
//   'full'            — allowed.
// Campaigns additionally carry real per-campaign scoping ('own' and
// 'selected'): orbitraCampaignScope() below resolves the user's visible
// campaign ids from campaigns.owner_user_id plus the ids explicitly assigned
// in permissions_json.campaigns.items, and the api.php surfaces (lists,
// mutations, reports, logs, dashboard aggregates) filter through
// orbitraCampaignScopeCondition()/orbitraCampaignScopeInSql(). For every
// other resource 'own'/'selected' have no per-item meaning (no owner column),
// so they behave exactly like 'full' — the modal no longer offers them there.
//
// Admins bypass the gate entirely. Actions that don't belong to a
// permission-keyed resource (settings, integrations, LeadForge suite,
// extension reporting…) are intentionally unmapped and keep their pre-gate
// behavior.

/**
 * Action → resource map. 'read' actions expose a resource, 'write' actions
 * create/modify/delete it, 'hybrid' actions are list endpoints whose POST
 * handler also saves (GET counts as read, POST as write).
 */
function orbitraResourceAccessMap(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        'campaigns' => [
            'read' => [
                'campaigns', 'campaigns_simple', 'get_campaign', 'campaign_pixels',
                'campaign_remote_links', 'rotation_status', 'cloak_summary',
                'campaign_cost_match', 'campaign_report', 'campaign_logs', 'click_details',
                'test_postback', 'postback_url', 'postback_logs', 'conversions',
                'conversion_monitoring', 'ad_entity_statuses',
            ],
            'write' => [
                'save_campaign', 'delete_campaign', 'bulk_delete_campaigns', 'copy_campaign',
                'bulk_import_campaigns', 'rename_campaign_group', 'delete_campaign_group',
                'save_campaign_pixel', 'delete_campaign_pixel', 'clear_stats',
                'clear_campaign_stats', 'update_costs', 'import_conversions',
                'retroactive_remap', 'keitaro_import_sql', 'ad_entity_toggle_status',
            ],
            'hybrid' => ['campaign_groups', 'groups'],
        ],
        'offers' => [
            'read' => [
                'offers', 'offers_simple', 'all_offers', 'get_offer', 'offer_groups',
                'offer_files', 'offer_file_content', 'get_offer_file',
            ],
            'write' => [
                'save_offer', 'delete_offer', 'bulk_delete_offers', 'bulk_import_offers',
                'copy_offer', 'rename_offer_group', 'delete_offer_group', 'offer_save_file',
                'save_offer_file', 'offer_create_file', 'offer_delete_file',
                'offer_rename_file', 'offer_file_op', 'upload_offer', 'upload_offer_file',
            ],
            'hybrid' => ['offer_groups'],
        ],
        'landings' => [
            'read' => [
                'landings', 'landings_simple', 'get_landing', 'landing_groups',
                'landing_files', 'get_landing_file', 'pwa_config_get', 'pwa_preview',
            ],
            'write' => [
                'save_landing', 'delete_landing', 'bulk_delete_landings',
                'bulk_import_landings', 'upload_landing', 'rename_landing_group',
                'delete_landing_group', 'landing_file_op', 'upload_landing_file',
                'save_landing_file', 'pwa_config_save',
            ],
            'hybrid' => ['landing_groups'],
        ],
        'sources' => [
            'read' => [
                'get_traffic_source', 'traffic_source_templates', 'check_source_url',
                'check_all_source_urls',
            ],
            'write' => ['bulk_delete_traffic_sources', 'bulk_import_sources'],
            'hybrid' => ['traffic_sources'],
        ],
        'networks' => [
            'read' => ['get_affiliate_network', 'affiliate_network_templates'],
            'write' => ['delete_affiliate_network', 'bulk_delete_affiliate_networks'],
            'hybrid' => ['affiliate_networks'],
        ],
        'domains' => [
            'read' => [
                'domains', 'domain_groups', 'check_domain_dns', 'check_cloudflare_status',
                'force_check_all_dns', 'check_ssl_status', 'ssl_environment',
                'cloudflare_status', 'cloudflare_accounts_list', 'cloudflare_account_zones',
                'namecheap_status', 'namecheap_accounts_list', 'namecheap_addresses',
                'namecheap_domains', 'namecheap_check_domain', 'namecheap_account_balance',
                'backorder_domains', 'backorder_cron_info', 'postback_queue_info',
            ],
            'write' => [
                'save_domain', 'delete_domain', 'rename_domain_group', 'delete_domain_group',
                'reissue_ssl', 'run_ssl_worker', 'cloudflare_save', 'cloudflare_test',
                'cloudflare_sync_domain', 'cloudflare_sync_all', 'cloudflare_options_save',
                'cloudflare_account_save', 'cloudflare_account_delete',
                'cloudflare_account_import', 'cloudflare_account_repoint', 'namecheap_save',
                'namecheap_test', 'namecheap_register_domain', 'namecheap_sync_domain',
                'namecheap_account_save', 'namecheap_account_delete', 'backorder_import',
                'backorder_update', 'backorder_delete', 'backorder_delete_selected',
                'backorder_check_now', 'backorder_check_batch', 'backorder_install_cron',
                'backorder_remove_cron', 'backorder_install_user_cron',
                'backorder_remove_user_cron', 'postback_queue_install_user_cron',
                'postback_queue_remove_user_cron', 'fix_nginx', 'regenerate_nginx',
            ],
            'hybrid' => [],
        ],
        'media' => [
            'read' => ['media_list', 'media_folders'],
            'write' => ['media_upload', 'media_op', 'media_folder_op'],
            'hybrid' => [],
        ],
        'logs' => [
            'read' => ['logs'],
            'write' => [],
            'hybrid' => [],
        ],
    ];

    return $map;
}

/** Archive surface spans five resources; map its item types back to them. */
function orbitraArchiveTypeResourceMap(): array
{
    return [
        'campaigns' => 'campaigns',
        'offers' => 'offers',
        'landings' => 'landings',
        'traffic_sources' => 'sources',
        'affiliate_networks' => 'networks',
    ];
}

/** stored access level → the level this gate enforces ('full'|'read'|'none'). */
function orbitraNormalizeResourceAccess($raw): string
{
    $access = is_array($raw) ? ($raw['access'] ?? 'full') : 'full';
    if ($access === 'none') {
        return 'none';
    }
    if ($access === 'read') {
        return 'read';
    }
    // full, selected, own — and anything unexpected — count as full.
    return 'full';
}

/** Campaigns keep all five stored levels: 'own'/'selected' have real
 * per-campaign semantics (owner_user_id + permissions items). */
function orbitraCampaignAccessLevel($raw): string
{
    $access = is_array($raw) ? ($raw['access'] ?? 'full') : 'full';
    if (in_array($access, ['none', 'read', 'own', 'selected'], true)) {
        return $access;
    }
    return 'full';
}

/** Decoded permissions_json of the requesting user, read once per request. */
function orbitraRequestPermissions(PDO $pdo): array
{
    static $permissions = null;
    if ($permissions === null) {
        $stmt = $pdo->prepare("SELECT permissions_json FROM users WHERE id = ?");
        $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $decoded = json_decode((string) $stmt->fetchColumn(), true);
        $permissions = is_array($decoded) ? $decoded : [];
    }
    return $permissions;
}

/**
 * Resolve the requesting user's enforced access for a resource.
 * permissions_json is read once per request and shared across lookups.
 */
function orbitraUserAccessForResource(PDO $pdo, int $userId, string $resource): string
{
    return orbitraNormalizeResourceAccess(orbitraRequestPermissions($pdo)[$resource] ?? null);
}

/** Campaign-specific access level of the requesting user (five levels). */
function orbitraCampaignAccessLevelForUser(PDO $pdo): string
{
    return orbitraCampaignAccessLevel(orbitraRequestPermissions($pdo)['campaigns'] ?? null);
}

/**
 * Write actions that touch campaigns beyond the user's own scope (global
 * stats wipes, cross-campaign imports/remaps, remote ad management) —
 * denied for scope-limited users even though their own campaigns are
 * writable.
 */
function orbitraCampaignScopedDeniedActions(): array
{
    return [
        'clear_stats', 'import_conversions', 'keitaro_import_sql',
        'retroactive_remap', 'ad_entity_toggle_status', 'test_postback',
    ];
}

/**
 * Per-campaign visibility scope of the requesting user, or null when the
 * request is unrestricted (admin, or campaigns access 'full'/'read' — the
 * write side of 'read' stays blocked by the action gate):
 *   ['ids' => int[], 'write' => bool, 'level' => 'own'|'selected', 'user_id' => int]
 * 'own'  → campaigns owned by the user plus the explicitly assigned items;
 * 'selected' → only the assigned items.
 */
function orbitraCampaignScope(PDO $pdo): ?array
{
    static $scope = false;
    if ($scope !== false) {
        return $scope;
    }

    $scope = null;
    if (($_SESSION['role'] ?? '') !== 'admin') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId) {
            $level = orbitraCampaignAccessLevelForUser($pdo);
            if ($level === 'none') {
                $scope = ['ids' => [], 'write' => false, 'level' => 'none', 'user_id' => $userId];
            } elseif ($level === 'own' || $level === 'selected') {
                $raw = orbitraRequestPermissions($pdo)['campaigns']['items'] ?? [];
                $ids = [];
                foreach ((array) $raw as $v) {
                    if ((int) $v > 0) {
                        $ids[] = (int) $v;
                    }
                }
                if ($level === 'own') {
                    $stmt = $pdo->prepare("SELECT id FROM campaigns WHERE owner_user_id = ?");
                    $stmt->execute([$userId]);
                    $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                }
                $scope = [
                    'ids' => array_values(array_unique($ids)),
                    'write' => true,
                    'level' => $level,
                    'user_id' => $userId,
                ];
            }
            // 'full' / 'read' → unrestricted visibility.
        }
    }
    return $scope;
}

/**
 * SQL fragment (and params) restricting a campaign-id column to the scope.
 * Returns ['', []] when unrestricted; '1 = 0' when the scope is empty.
 */
function orbitraCampaignScopeCondition(?array $scope, string $column): array
{
    if ($scope === null) {
        return ['', []];
    }
    $ids = $scope['ids'];
    if (empty($ids)) {
        return ['1 = 0', []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return ["$column IN ($placeholders)", $ids];
}

/**
 * Inline variant for handlers that build WHERE strings without placeholders.
 * '' when unrestricted, '1 = 0' when empty, else 'col IN (1,2,3)' — the ids
 * are strictly integers by construction, so interpolation is safe.
 */
function orbitraCampaignScopeInSql(?array $scope, string $column): string
{
    if ($scope === null) {
        return '';
    }
    if (empty($scope['ids'])) {
        return '1 = 0';
    }
    return $column . ' IN (' . implode(',', $scope['ids']) . ')';
}

/** AND-appended per-campaign scope check on a fetched row, 403 when outside. */
function orbitraAssertCampaignInScope(?array $scope, $row, bool $needWrite = true): void
{
    if ($scope === null) {
        return;
    }
    $campaignId = null;
    if (is_array($row)) {
        $campaignId = (int) ($row['campaign_id'] ?? $row['id'] ?? 0);
    } else {
        $campaignId = (int) $row;
    }
    if ($needWrite && !$scope['write']) {
        orbitraDenyResourceAccess();
    }
    if (!in_array($campaignId, $scope['ids'], true)) {
        orbitraDenyResourceAccess();
    }
}

function orbitraDenyResourceAccess(): void
{
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

/**
 * Block the request when the signed-in non-admin's resource access does not
 * cover the action: 'none' denies reads and writes, 'read' denies writes.
 * Must run after the authentication middleware (a valid session is assumed).
 */
function orbitraEnforceResourceAccess(PDO $pdo, string $action, string $method): void
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return;
    }
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if (!$userId) {
        return; // unauthenticated requests never reach the switch
    }

    // The archive family spans five resources: the listing needs read access
    // to every one of them, restore/purge need write on the item's own type.
    if ($action === 'archive_items') {
        foreach (array_values(orbitraArchiveTypeResourceMap()) as $resource) {
            if (orbitraUserAccessForResource($pdo, $userId, $resource) === 'none') {
                orbitraDenyResourceAccess();
            }
        }
        return;
    }
    if ($action === 'archive_restore' || $action === 'archive_purge') {
        $data = json_decode(orbitraRequestBody(), true);
        $resource = orbitraArchiveTypeResourceMap()[$data['type'] ?? ''] ?? null;
        if ($resource !== null
            && orbitraUserAccessForResource($pdo, $userId, $resource) !== 'full') {
            orbitraDenyResourceAccess();
        }
        return;
    }

    foreach (orbitraResourceAccessMap() as $resource => $groups) {
        $needed = null;
        if (in_array($action, $groups['write'], true)) {
            $needed = 'write';
        } elseif (in_array($action, $groups['read'], true)) {
            $needed = 'read';
        } elseif (in_array($action, $groups['hybrid'], true)) {
            $needed = strtoupper($method) === 'POST' ? 'write' : 'read';
        }
        if ($needed === null) {
            continue;
        }
        if ($resource === 'campaigns') {
            // Campaigns carry five real levels: own/selected pass the blanket
            // gate here and are narrowed per-campaign by the handlers via
            // orbitraCampaignScope(); global-write actions stay admin/full.
            $level = orbitraCampaignAccessLevelForUser($pdo);
            if ($level === 'none') {
                orbitraDenyResourceAccess();
            }
            if ($needed === 'write' && $level === 'read') {
                orbitraDenyResourceAccess();
            }
            if ($needed === 'write'
                && ($level === 'own' || $level === 'selected')
                && in_array($action, orbitraCampaignScopedDeniedActions(), true)) {
                orbitraDenyResourceAccess();
            }
            return;
        }
        $access = orbitraUserAccessForResource($pdo, $userId, $resource);
        if ($access === 'none' || ($needed === 'write' && $access !== 'full')) {
            orbitraDenyResourceAccess();
        }
        return;
    }
}
