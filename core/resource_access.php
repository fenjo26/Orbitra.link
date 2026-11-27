<?php

// Central resource-access enforcement for non-admin panel users
// (GitHub issue #6: campaign scopes were stored but never enforced).
//
// The users-page modal stores an access level per resource:
//   'none'            — every action of that resource is denied (403);
//   'read'            — read actions pass, write actions are denied (403);
//   'full'            — allowed.
// Campaigns, offers, landings and domains additionally carry real per-item
// scoping ('selected', and the legacy campaigns-only 'own'):
//   permissions_json.<resource> = {
//       access: 'selected',
//       mode:   'full' | 'read',     // may the user change what they see
//       groups: [group ids],          // whole groups, resolved live
//       items:  [entity ids],         // single entities, whatever their group
//   }
// orbitraResourceScope() resolves the visible ids: every member of a granted
// group (dynamic — whatever sits in the group at request time), every
// explicitly assigned item, plus everything the user created themselves
// (<table>.owner_user_id). Campaign surfaces (lists, mutations, reports,
// logs, dashboard aggregates) filter through orbitraCampaignScope() and its
// SQL helpers; offers, landings and domains are narrowed by the central
// per-id gate in orbitraEnforceScopedAction() plus list filtering in the
// handlers. Statistics follow the campaign scope only.
// For every other resource 'own'/'selected' have no per-item meaning, so they
// behave exactly like 'full' — the modal does not offer them there. The same
// holds for a pre-1.10 offers/landings/domains entry that says 'selected' but
// carries no 'groups' key: it was never enforced and keeps meaning 'full'.
//
// Admins bypass the gate entirely. Actions that don't belong to a
// permission-keyed resource are default-deny for non-admins: the explicit
// whitelist in orbitraUnmappedAllowedActions() passes (profile, dashboard,
// reports, reference lists), everything else unmapped is admin-only.

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
                'campaigns', 'campaigns_simple', 'campaigns_brief', 'get_campaign', 'campaign_pixels',
                'campaign_remote_links', 'rotation_status', 'cloak_summary',
                'campaign_cost_match', 'campaign_report', 'campaign_logs', 'click_details',
                'test_postback', 'postback_url', 'postback_logs', 'conversions',
                'conversions_countries',
                'conversion_monitoring', 'ad_entity_statuses', 'campaign_stream_stats',
                'popup_template', 'popup_overview', 'browser_check_click',
                'campaign_rules', 'campaign_rule_preview', 'campaign_rule_log',
            ],
            'write' => [
                'save_campaign', 'delete_campaign', 'bulk_delete_campaigns', 'bulk_set_campaign_domain', 'copy_campaign',
                'bulk_import_campaigns', 'rename_campaign_group', 'delete_campaign_group',
                'save_campaign_pixel', 'delete_campaign_pixel', 'clear_stats',
                'clear_campaign_stats', 'update_costs', 'import_conversions',
                'retroactive_remap', 'keitaro_import_sql', 'ad_entity_toggle_status',
                'campaign_rule_save', 'campaign_rule_delete',
                // Narrow routine writes; each checks the campaign against the
                // caller's scope itself (orbitraQuickCampaign).
                'campaign_set_stream_weights', 'campaign_set_stream_active', 'campaign_set_state',
                'stream_set_rotation_weights',
            ],
            'hybrid' => ['campaign_groups', 'groups'],
        ],
        'offers' => [
            'read' => [
                'offers', 'offers_simple', 'all_offers', 'get_offer', 'offer_groups',
                'offer_files', 'offer_file_content', 'get_offer_file', 'offer_download',
                'tg_funnel_bots_brief',
            ],
            'write' => [
                'save_offer', 'delete_offer', 'bulk_delete_offers', 'bulk_import_offers',
                'copy_offer', 'rename_offer_group', 'delete_offer_group', 'offer_save_file',
                'save_offer_file', 'offer_create_file', 'offer_delete_file',
                'offer_rename_file', 'offer_file_op', 'upload_offer', 'upload_offer_file',
                'local_offer_create', 'local_offer_write_file',
            ],
            'hybrid' => ['offer_groups'],
        ],
        'landings' => [
            'read' => [
                'landings', 'landings_simple', 'get_landing', 'landing_groups',
                'landing_files', 'get_landing_file', 'landing_download', 'pwa_config_get', 'pwa_preview',
                'replays', 'replay_events', 'replay_heatmap', 'replay_storage', 'replay_landing_config',
                'text_tests', 'text_test_candidates',
            ],
            'write' => [
                'save_landing', 'delete_landing', 'bulk_delete_landings',
                'bulk_import_landings', 'upload_landing', 'rename_landing_group',
                'delete_landing_group', 'landing_file_op', 'upload_landing_file',
                'save_landing_file', 'pwa_config_save', 'copy_landing',
                'replay_landing_config_save', 'replay_delete', 'replay_storage_save',
                'text_test_save', 'text_test_delete', 'ai_text_variants',
                'local_landing_create', 'local_landing_write_file',
            ],
            'hybrid' => ['landing_groups'],
        ],
        'sources' => [
            'read' => [
                'traffic_sources_simple', 'get_traffic_source', 'traffic_source_templates', 'check_source_url',
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
                'domains', 'domains_brief', 'domain_groups', 'check_domain_dns', 'check_cloudflare_status',
                'force_check_all_dns', 'check_ssl_status', 'ssl_environment',
                'cloudflare_status', 'cloudflare_accounts_list', 'cloudflare_account_zones',
                'namecheap_status', 'namecheap_accounts_list', 'namecheap_addresses',
                'namecheap_domains', 'namecheap_check_domain', 'namecheap_account_balance',
                'dynadot_status', 'dynadot_accounts_list', 'dynadot_domains',
                'dynadot_check_domain', 'dynadot_account_balance',
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
                'dynadot_account_save', 'dynadot_account_delete', 'dynadot_register_domain',
                'dynadot_sync_domain',
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
        'push' => [
            'read' => [
                'push_subscribers', 'push_subscribers_export', 'push_vapid_status',
                'push_messages', 'push_queue_list',
            ],
            'write' => [
                'push_vapid_generate', 'push_vapid_contact_save', 'push_subscribers_op',
                'push_message_save', 'push_message_delete', 'push_send_now', 'push_test_send',
            ],
            'hybrid' => [],
        ],
        'logs' => [
            // logs_param_keys: the param-column suggestion list for the traffic
            // log (GET, read-only, bounded in logs_query.php) — without it here
            // a non-admin with logs read access got 403 and the column picker
            // lost its suggestions while the log itself loaded fine.
            'read' => ['logs', 'logs_export', 'logs_param_keys'],
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

/**
 * Resources with per-item scoping → [entity table, group table].
 * Every entity table carries group_id and owner_user_id.
 */
function orbitraScopedResourceTables(): array
{
    return [
        'campaigns' => ['campaigns', 'campaign_groups'],
        'offers' => ['offers', 'offer_groups'],
        'landings' => ['landings', 'landing_groups'],
        'domains' => ['domains', 'domain_groups'],
    ];
}

/**
 * Stored level of a scoped resource: 'none'|'read'|'full'|'own'|'selected'.
 * 'own' is the legacy campaigns-only level (own + assigned items, writable).
 * Offers/landings/domains honour 'selected' only when the entry carries a
 * 'groups' key — the marker of an entry saved by the 1.10+ modal; older
 * entries said 'selected' without it ever being enforced.
 */
function orbitraResourceAccessLevel($raw, string $resource): string
{
    $access = is_array($raw) ? ($raw['access'] ?? 'full') : 'full';
    if ($access === 'none' || $access === 'read') {
        return $access;
    }
    if (!isset(orbitraScopedResourceTables()[$resource])) {
        return 'full';
    }
    if ($resource === 'campaigns') {
        return ($access === 'own' || $access === 'selected') ? $access : 'full';
    }
    if ($access === 'selected' && is_array($raw['groups'] ?? null)) {
        return 'selected';
    }
    return 'full';
}

/** Campaigns keep all five stored levels: 'own'/'selected' have real
 * per-campaign semantics (owner_user_id + permissions items/groups). */
function orbitraCampaignAccessLevel($raw): string
{
    return orbitraResourceAccessLevel($raw, 'campaigns');
}

/** A 'selected' entry in view-only mode ("Read only + Selected"). */
function orbitraScopedEntryIsReadOnly($raw): bool
{
    return is_array($raw) && ($raw['access'] ?? '') === 'selected' && ($raw['mode'] ?? 'full') === 'read';
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
 * Resolve the requesting user's enforced blanket access for a resource
 * ('full'|'read'|'none'). A scoped entry counts as 'full' or — in view-only
 * mode — 'read'; which entities it covers is the scope's business.
 * permissions_json is read once per request and shared across lookups.
 */
function orbitraUserAccessForResource(PDO $pdo, int $userId, string $resource): string
{
    $raw = orbitraRequestPermissions($pdo)[$resource] ?? null;
    $level = orbitraResourceAccessLevel($raw, $resource);
    if ($level === 'none' || $level === 'read') {
        return $level;
    }
    if ($level === 'selected' && orbitraScopedEntryIsReadOnly($raw)) {
        return 'read';
    }
    return 'full';
}

/** Campaign-specific access level of the requesting user (five levels). */
function orbitraCampaignAccessLevelForUser(PDO $pdo): string
{
    return orbitraCampaignAccessLevel(orbitraRequestPermissions($pdo)['campaigns'] ?? null);
}

/**
 * Write actions that touch campaigns beyond the user's own scope (global
 * stats wipes, cross-campaign imports/remaps, remote ad management, group
 * management) — denied for scope-limited users even though their own
 * campaigns are writable.
 */
function orbitraCampaignScopedDeniedActions(): array
{
    return [
        'clear_stats', 'import_conversions', 'keitaro_import_sql',
        'retroactive_remap', 'ad_entity_toggle_status', 'test_postback',
        'rename_campaign_group', 'delete_campaign_group',
    ];
}

/** Positive integer ids out of a stored list (items / groups). */
function orbitraScopeIntList($raw): array
{
    $ids = [];
    foreach ((array) $raw as $v) {
        if (is_scalar($v) && (int) $v > 0) {
            $ids[] = (int) $v;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Per-item visibility scope of the requesting user for a scoped resource, or
 * null when the request is unrestricted (admin, or access 'full'/'read' — the
 * write side of 'read' stays blocked by the action gate):
 *   ['ids' => int[], 'write' => bool, 'level' => 'none'|'own'|'selected',
 *    'user_id' => int, 'groups' => int[], 'owned' => int[]]
 * ids = members of the granted groups (live) + assigned items + everything
 * the user owns. 'write' is false for "Read only + Selected".
 */
function orbitraResourceScope(PDO $pdo, string $resource, bool $refresh = false): ?array
{
    static $cache = [];
    if (!$refresh && array_key_exists($resource, $cache)) {
        return $cache[$resource];
    }

    $scope = null;
    $tables = orbitraScopedResourceTables()[$resource] ?? null;
    if ($tables !== null && ($_SESSION['role'] ?? '') !== 'admin') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId) {
            $raw = orbitraRequestPermissions($pdo)[$resource] ?? null;
            $level = orbitraResourceAccessLevel($raw, $resource);
            if ($level === 'none') {
                $scope = ['ids' => [], 'write' => false, 'level' => 'none', 'user_id' => $userId, 'groups' => [], 'owned' => []];
            } elseif ($level === 'own' || $level === 'selected') {
                $table = $tables[0];
                $ids = orbitraScopeIntList($raw['items'] ?? []);
                $groups = orbitraScopeIntList($raw['groups'] ?? []);
                $owned = [];
                try {
                    $stmt = $pdo->prepare("SELECT id FROM $table WHERE owner_user_id = ?");
                    $stmt->execute([$userId]);
                    $owned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                } catch (\Throwable $e) {
                    // Pre-migration DB without the owner column: nothing is owned.
                }
                if ($groups) {
                    // Group ids are strictly integers by construction.
                    $stmt = $pdo->query("SELECT id FROM $table WHERE group_id IN (" . implode(',', $groups) . ")");
                    $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                }
                $scope = [
                    'ids' => array_values(array_unique(array_merge($ids, $owned))),
                    'write' => !orbitraScopedEntryIsReadOnly($raw),
                    'level' => $level,
                    'user_id' => $userId,
                    'groups' => $groups,
                    'owned' => $owned,
                ];
            }
            // 'full' / 'read' → unrestricted visibility.
        }
    }
    $cache[$resource] = $scope;
    return $scope;
}

/** Per-campaign scope (issue #6) — the campaigns instance of orbitraResourceScope(). */
function orbitraCampaignScope(PDO $pdo): ?array
{
    return orbitraResourceScope($pdo, 'campaigns');
}

/** Keep only the rows whose id is inside the scope (no-op when unrestricted). */
function orbitraScopeFilterRows(?array $scope, array $rows, string $key = 'id'): array
{
    if ($scope === null) {
        return $rows;
    }
    $allowed = array_flip($scope['ids']);
    return array_values(array_filter($rows, static function ($row) use ($allowed, $key) {
        return is_array($row) && isset($allowed[(int) ($row[$key] ?? 0)]);
    }));
}

/** 403 unless the entity is inside the scope (and writable when asked). */
function orbitraAssertInScope(?array $scope, int $id, bool $needWrite = true): void
{
    if ($scope === null) {
        return;
    }
    if ($needWrite && !$scope['write']) {
        orbitraDenyResourceAccess();
    }
    if (!in_array($id, $scope['ids'], true)) {
        orbitraDenyResourceAccess();
    }
}

/**
 * Groups a scope-limited user may see and pick: the granted ones plus the
 * groups their visible entities sit in. null = unrestricted.
 */
function orbitraScopeVisibleGroupIds(PDO $pdo, string $resource): ?array
{
    $scope = orbitraResourceScope($pdo, $resource);
    if ($scope === null) {
        return null;
    }
    $groups = $scope['groups'];
    if ($scope['ids']) {
        $table = orbitraScopedResourceTables()[$resource][0];
        $stmt = $pdo->query("SELECT DISTINCT group_id FROM $table WHERE group_id IS NOT NULL AND id IN (" . implode(',', $scope['ids']) . ")");
        $groups = array_merge($groups, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    return array_values(array_unique($groups));
}

/** Group rows narrowed to what the scope may see (no-op when unrestricted). */
function orbitraScopeFilterGroups(PDO $pdo, string $resource, array $rows): array
{
    $visible = orbitraScopeVisibleGroupIds($pdo, $resource);
    if ($visible === null) {
        return $rows;
    }
    $allowed = array_flip($visible);
    return array_values(array_filter($rows, static function ($row) use ($allowed) {
        return is_array($row) && isset($allowed[(int) ($row['id'] ?? 0)]);
    }));
}

/**
 * The group_id a save may store. Unrestricted callers get what they asked
 * for. A scope-limited user regroups only what they own (or are creating),
 * and only into a group they can see; an entity that was merely shared with
 * them keeps its group — moving it would take it away from everyone the
 * group was granted to.
 */
function orbitraScopedGroupId(PDO $pdo, string $resource, ?int $entityId, ?int $requestedGroupId): ?int
{
    $scope = orbitraResourceScope($pdo, $resource);
    if ($scope === null) {
        return $requestedGroupId;
    }
    $stored = null;
    if ($entityId) {
        $table = orbitraScopedResourceTables()[$resource][0];
        $stmt = $pdo->prepare("SELECT group_id FROM $table WHERE id = ?");
        $stmt->execute([$entityId]);
        $value = $stmt->fetchColumn();
        $stored = ($value !== false && $value !== null) ? (int) $value : null;
        if (!in_array((int) $entityId, $scope['owned'], true)) {
            return $stored;
        }
    }
    if ($requestedGroupId === null) {
        return null;
    }
    $visible = orbitraScopeVisibleGroupIds($pdo, $resource) ?? [];
    return in_array($requestedGroupId, $visible, true) ? $requestedGroupId : $stored;
}

/**
 * Mark a freshly created entity as owned by its non-admin creator, so a
 * scope-limited user keeps seeing what they made ("allowed + own"). Admin
 * creations stay unowned. Refreshes the request's scope cache.
 */
function orbitraStampOwner(PDO $pdo, string $resource, $id): void
{
    $tables = orbitraScopedResourceTables()[$resource] ?? null;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($tables === null || !$userId || (int) $id <= 0 || ($_SESSION['role'] ?? '') === 'admin') {
        return;
    }
    try {
        $pdo->prepare("UPDATE {$tables[0]} SET owner_user_id = ? WHERE id = ? AND owner_user_id IS NULL")
            ->execute([$userId, (int) $id]);
        orbitraResourceScope($pdo, $resource, true);
    } catch (\Throwable $e) {
        // Pre-migration DB without the owner column — nothing to stamp.
    }
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
 * Actions outside the resource map that every signed-in non-admin may call.
 * Everything unmapped and unlisted below is admin-only from now on (default
 * deny) — the previous fall-through let any user reach integration, settings
 * and infrastructure endpoints.
 *
 * value `true`  → allowed for any method (profile saves, POST-transport reads);
 * value `false` → read-only (GET/HEAD): reference lists the resource pages load.
 * $publicActions are not listed here — they resolve before authentication.
 */
function orbitraUnmappedAllowedActions(): array
{
    return [
        // Dashboard, analytics and panel chrome (campaign-scope aware server-side).
        'metrics' => true,
        'chart' => true,
        // Dashboard "Countries" block: GET only, campaign-scope aware server-side.
        'top_countries' => false,
        // Own chart colours/line styles (session-pinned server-side).
        'dashboard_chart_prefs' => true,
        'trends' => true,
        'analytics_compare' => true,
        'creatives_simple' => false,
        // Campaign-launch cohorts (Analytics page): GET, read-only, the same
        // class as trends — outside the map it defaulted to admin-only and the
        // Cohort view 403'd for non-admins with analytics access.
        'cohort' => true,
        'check_update' => true,
        // Built-in AI assistant: own chats only (handlers pin user_id to the
        // session); its tools run under the user's own key and permissions.
        // ai_settings / ai_test stay admin-only (not listed).
        'ai_status' => false,
        'ai_greeting' => false,
        'ai_chats' => false,
        'ai_chat' => false,
        'ai_chat_send' => true,
        'ai_chat_step' => true,
        'ai_chat_confirm' => true,
        'ai_chat_delete' => true,
        'worker_health' => true,
        // Link checks: the badge on the bell and the "Checks" sub-tab are
        // read-only for everyone. Booking a check stays admin-only — every
        // check of an offer link is a click in the partner network's
        // statistics — and so do settings, per-link edits and proxies.
        'link_checks_summary' => false,
        'link_checks_list' => false,
        'link_checks_log' => false,
        // Read-only for non-admins: the POST side writes server-wide settings.
        'global_settings' => false,
        // Own profile only — the handler pins the id to the session.
        'profile_settings' => true,
        // Two-factor login management (audit item #20): the user acts on their
        // own row only — setup/enroll the authenticator, confirm and disable it.
        'totp_setup' => true,
        'totp_enable' => true,
        'totp_disable' => true,
        // Reference lists the resource editors and reports load.
        'conversion_types' => false,
        'countries_list' => false,
        'pwa_funnel_stats' => false,
        'pixel_profiles_list' => false,
        // Per-user extension key and the extension's read-only reporting.
        'extension_credentials' => true,
        'extension_ads_stats' => true,
        'extension_deep_stats' => true,
        // Pixel picker inside the campaign editor. save_pixel_profile lets a
        // non-admin CREATE a profile only — editing or duplicating an existing
        // one is admin-only inside the handler, because a profile is shared by
        // every campaign attached to it and carries the CAPI token.
        'save_pixel_profile' => true,
        // Own-device web-push notifications (the panel PWA): subscribe this
        // browser, list/retire own devices, own alert settings. Handlers pin
        // every row to the session user.
        'admin_push_state' => true,
        'admin_push_subscribe' => true,
        'admin_push_unsubscribe' => true,
        'admin_push_settings_save' => true,
        'admin_push_test' => true,
    ];
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
        // An unknown or absent type (purge_all!) maps to no resource: default
        // deny instead of silently bypassing the gate.
        if ($resource === null
            || orbitraUserAccessForResource($pdo, $userId, $resource) !== 'full') {
            orbitraDenyResourceAccess();
        }
        // Scope-limited users restore/purge one in-scope item at a time —
        // never the "all of this type" variants.
        $scope = orbitraResourceScope($pdo, $resource);
        if ($scope !== null) {
            $itemId = $data['id'] ?? null;
            if (!is_scalar($itemId) || !ctype_digit((string) $itemId)) {
                orbitraDenyResourceAccess();
            }
            orbitraAssertInScope($scope, (int) $itemId, true);
        }
        return;
    }

    // Default deny for actions outside the resource map: whitelisted ones
    // pass (method-aware), everything else is admin-only.
    $allowed = orbitraUnmappedAllowedActions()[$action] ?? null;
    if ($allowed !== null) {
        $isReadMethod = !in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($allowed === true || $isReadMethod) {
            return;
        }
        orbitraDenyResourceAccess();
    }

    // Text A/B tests live on landings and on local offers' pages alike. For a
    // request that names an offer the resource, its scope and the read/write
    // rule are the offer's — the map files these actions under landings.
    if (in_array($action, ['text_tests', 'text_test_candidates', 'text_test_save', 'text_test_delete', 'ai_text_variants'], true)
        && orbitraTextTestNamesOffer()) {
        $ttNeeded = in_array($action, ['text_test_save', 'text_test_delete', 'ai_text_variants'], true) ? 'write' : 'read';
        $ttAccess = orbitraUserAccessForResource($pdo, $userId, 'offers');
        if ($ttAccess === 'none' || ($ttNeeded === 'write' && $ttAccess !== 'full')) {
            orbitraDenyResourceAccess();
        }
        orbitraEnforceScopedAction($pdo, 'offers', $action);
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
            $access = orbitraUserAccessForResource($pdo, $userId, 'campaigns');
            if ($access === 'none') {
                orbitraDenyResourceAccess();
            }
            if ($needed === 'write' && $access !== 'full') {
                orbitraDenyResourceAccess();
            }
            if ($needed === 'write'
                && ($level === 'own' || $level === 'selected')
                && (in_array($action, orbitraCampaignScopedDeniedActions(), true)
                    // creating a group (POST on the hybrid list endpoints)
                    || in_array($action, $groups['hybrid'], true))) {
                orbitraDenyResourceAccess();
            }
            return;
        }
        $access = orbitraUserAccessForResource($pdo, $userId, $resource);
        if ($access === 'none' || ($needed === 'write' && $access !== 'full')) {
            orbitraDenyResourceAccess();
        }
        // Offers, landings, domains: a scope-limited user additionally has
        // every id in the request checked against their scope.
        orbitraEnforceScopedAction($pdo, $resource, $action);
        return;
    }

    // The action matched no resource and no whitelist entry: admin-only.
    orbitraDenyResourceAccess();
}

/** Does this text A/B request name an offer's page — offer_id and no landing_id? */
function orbitraTextTestNamesOffer(): bool
{
    $in = $_GET;
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $decoded = json_decode(orbitraRequestBody(), true);
        if (is_array($decoded)) {
            $in = array_merge($in, $decoded);
        }
    }
    return (int) ($in['landing_id'] ?? 0) <= 0 && (int) ($in['offer_id'] ?? 0) > 0;
}

/**
 * What a scope-limited user may call on offers / landings / domains, and
 * where each action carries the entity id. Default deny: an action of these
 * resources that is not listed here is refused for scoped users (group
 * management, registrar/DNS accounts, nginx, storage settings, …) — a new
 * endpoint is closed until someone decides how it is scoped.
 *   'list'   — the handler filters its rows through the scope;
 *   'open'   — reference data without per-entity content;
 *   'create' — creates entities (the handler stamps the owner);
 *   'groups' — GET lists the visible groups, POST (create) is refused;
 *   [keys]   — request keys holding entity id(s); every value present in the
 *              query string, the form body or the JSON body must be in scope.
 */
function orbitraScopedActionTable(): array
{
    $id = ['id'];
    return [
        'offers' => [
            'offers' => 'list', 'offers_simple' => 'list', 'all_offers' => 'list',
            'offer_groups' => 'groups', 'tg_funnel_bots_brief' => 'open',
            'get_offer' => $id, 'offer_files' => $id, 'offer_file_content' => $id, 'get_offer_file' => $id,
            'offer_download' => $id,
            'save_offer' => $id, 'delete_offer' => $id, 'copy_offer' => $id,
            'offer_save_file' => $id, 'save_offer_file' => $id, 'offer_create_file' => $id,
            'offer_delete_file' => $id, 'offer_rename_file' => $id, 'offer_file_op' => $id,
            'upload_offer' => $id, 'upload_offer_file' => $id,
            'local_offer_create' => 'create', 'local_offer_write_file' => $id,
            // Text A/B tests of a local offer's page: the page is the id.
            'text_tests' => ['offer_id'], 'text_test_candidates' => ['offer_id'],
            'text_test_save' => ['offer_id'], 'text_test_delete' => ['offer_id'], 'ai_text_variants' => ['offer_id'],
            'bulk_delete_offers' => ['ids'], 'bulk_import_offers' => 'create',
        ],
        'landings' => [
            'landings' => 'list', 'landings_simple' => 'list', 'landing_groups' => 'groups',
            'get_landing' => $id, 'landing_files' => $id, 'get_landing_file' => $id, 'landing_download' => $id,
            'pwa_config_get' => $id, 'pwa_preview' => 'open',
            'save_landing' => $id, 'delete_landing' => $id, 'copy_landing' => $id,
            'landing_file_op' => $id, 'save_landing_file' => $id, 'pwa_config_save' => $id,
            'upload_landing' => $id, 'upload_landing_file' => $id,
            'local_landing_create' => 'create', 'local_landing_write_file' => $id,
            'bulk_delete_landings' => ['ids'], 'bulk_import_landings' => 'create',
            // Recordings are statistics: they follow the campaign scope
            // (core/SessionReplay.php). Per-landing recording settings are
            // tied to the landing itself.
            'replays' => 'list', 'replay_events' => 'list', 'replay_heatmap' => 'list',
            'replay_delete' => 'list', 'replay_storage' => 'open',
            'replay_landing_config' => ['landing_id'], 'replay_landing_config_save' => ['landing_id'],
        ],
        'domains' => [
            'domains' => 'list', 'domains_brief' => 'list', 'check_ssl_status' => 'list',
            'domain_groups' => 'groups',
            'check_domain_dns' => $id, 'check_cloudflare_status' => $id,
            'save_domain' => $id, 'delete_domain' => $id,
            // What the Domains page and the add-domain form load: connection
            // state and account names, no per-domain content.
            'ssl_environment' => 'open', 'cloudflare_status' => 'open', 'cloudflare_accounts_list' => 'open',
            'namecheap_status' => 'open', 'namecheap_accounts_list' => 'open',
            'dynadot_status' => 'open', 'dynadot_accounts_list' => 'open',
            'postback_queue_info' => 'open', 'backorder_cron_info' => 'open',
        ],
    ];
}

/**
 * Per-entity gate for scope-limited users of offers / landings / domains.
 * Runs after the blanket read/write check; unrestricted users return at once.
 */
function orbitraEnforceScopedAction(PDO $pdo, string $resource, string $action): void
{
    $scope = orbitraResourceScope($pdo, $resource);
    if ($scope === null) {
        return;
    }
    $spec = orbitraScopedActionTable()[$resource][$action] ?? null;
    if ($spec === null) {
        orbitraDenyResourceAccess();
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($spec === 'groups') {
        if ($method === 'POST') {
            orbitraDenyResourceAccess();
        }
        return;
    }
    if (!is_array($spec)) {
        return; // list / open / create
    }
    // A domain addressed by name instead of id has no scope to check against.
    if ($action === 'check_cloudflare_status' && (isset($_GET['domain']) || isset($_POST['domain']))) {
        orbitraDenyResourceAccess();
    }

    $body = null;
    if ($method === 'POST') {
        $decoded = json_decode(orbitraRequestBody(), true);
        $body = is_array($decoded) ? $decoded : null;
    }
    foreach ($spec as $key) {
        $candidates = [];
        foreach ([$_GET, $_POST, $body] as $source) {
            if (is_array($source) && array_key_exists($key, $source)
                && $source[$key] !== null && $source[$key] !== '' && $source[$key] !== 0 && $source[$key] !== '0') {
                $candidates[] = $source[$key];
            }
        }
        foreach ($candidates as $value) {
            $values = ($key === 'ids' && is_array($value)) ? $value : [$value];
            foreach ($values as $one) {
                // Anything that is not a plain positive integer could be read
                // differently by the handler than by this check — refuse it.
                if (!is_scalar($one) || !ctype_digit((string) $one) || (int) $one <= 0) {
                    orbitraDenyResourceAccess();
                }
                if (!in_array((int) $one, $scope['ids'], true)) {
                    orbitraDenyResourceAccess();
                }
            }
        }
    }
}


/**
 * Offer / landing ids a list of streams points at. Accepts posted streams
 * (schema_custom as array) and stored rows (schema_custom_json) alike.
 *
 * @return array{offers:int[], landings:int[]}
 */
function orbitraStreamEntityRefs(array $streams): array
{
    $refs = ['offers' => [], 'landings' => []];
    foreach ($streams as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        if (!empty($stream['offer_id'])) {
            $refs['offers'][] = (int) $stream['offer_id'];
        }
        $custom = $stream['schema_custom'] ?? ($stream['schema_custom_json'] ?? null);
        if (is_string($custom)) {
            $custom = json_decode($custom, true);
        }
        if (!is_array($custom)) {
            continue;
        }
        // A bare list is the legacy "offers only" shape.
        if ($custom !== [] && array_is_list($custom)) {
            $custom = ['offers' => $custom];
        }
        foreach (['offers' => 'safe_offer_id', 'landings' => 'safe_landing_id'] as $kind => $safeKey) {
            foreach ((array) ($custom[$kind] ?? []) as $item) {
                if (is_array($item) && !empty($item['id'])) {
                    $refs[$kind][] = (int) $item['id'];
                }
            }
            if (!empty($custom[$safeKey])) {
                $refs[$kind][] = (int) $custom[$safeKey];
            }
        }
    }
    foreach ($refs as $kind => $ids) {
        $refs[$kind] = array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
    }
    return $refs;
}

/**
 * A scope-limited user may leave what a campaign already uses in place, but
 * can only ADD offers / landings / a domain from their own scope — otherwise
 * a guessed id would pull a hidden entity into their campaign.
 */
function orbitraAssertCampaignRefsInScope(PDO $pdo, ?int $campaignId, $streams, ?int $domainId): void
{
    $scopes = [
        'offers' => orbitraResourceScope($pdo, 'offers'),
        'landings' => orbitraResourceScope($pdo, 'landings'),
    ];
    if (is_array($streams) && ($scopes['offers'] !== null || $scopes['landings'] !== null)) {
        $posted = orbitraStreamEntityRefs($streams);
        $stored = ['offers' => [], 'landings' => []];
        if ($campaignId) {
            $stmt = $pdo->prepare("SELECT offer_id, schema_custom_json FROM streams WHERE campaign_id = ?");
            $stmt->execute([$campaignId]);
            $stored = orbitraStreamEntityRefs($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        foreach ($scopes as $kind => $scope) {
            if ($scope === null) {
                continue;
            }
            foreach (array_diff($posted[$kind], $stored[$kind]) as $newId) {
                if (!in_array((int) $newId, $scope['ids'], true)) {
                    orbitraDenyResourceAccess();
                }
            }
        }
    }

    $domainScope = orbitraResourceScope($pdo, 'domains');
    if ($domainScope !== null && $domainId !== null && !in_array($domainId, $domainScope['ids'], true)) {
        $storedDomain = null;
        if ($campaignId) {
            $stmt = $pdo->prepare("SELECT domain_id FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $value = $stmt->fetchColumn();
            $storedDomain = ($value !== false && $value !== null) ? (int) $value : null;
        }
        if ($domainId !== $storedDomain) {
            orbitraDenyResourceAccess();
        }
    }
}
