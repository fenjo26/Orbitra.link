<?php
// core/CampaignQuickActions.php
//
// Narrow writes for the changes a media buyer makes several times a day: the
// traffic share of a campaign's streams, whether a stream is on, whether the
// campaign itself is running, and the weights inside one stream's rotation.
//
// save_campaign replaces the whole campaign (every field of every stream, the
// whole postback list). That is what the editor needs and the wrong tool for
// "give stream 3 seventy percent": a caller that sends one stream with one
// field resets the rest of that stream and drops the streams it did not send.
// Each function here touches only what it is given, checks it against what is
// stored, and refuses what would leave the campaign with nothing to serve.
//
// The api.php cases are thin: parse, call, answer. Every refusal is an
// OrbitraQuickActionError with a code and a sentence that can be shown as is.

require_once __DIR__ . '/resource_access.php';
require_once __DIR__ . '/stream_stats.php';
require_once __DIR__ . '/RotationOptimiser.php';

class OrbitraQuickActionError extends RuntimeException
{
    public string $errorCode;
    public array $detail;

    public function __construct(string $errorCode, string $message, array $detail = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->detail = $detail;
    }
}

/** Top weight a stream or a rotation item may carry — the editor's own ceiling. */
const ORBITRA_QUICK_MAX_WEIGHT = 100;

/** A whole number out of what JSON may carry (7, 7.0, "7"); null when it is none. */
function orbitraQuickInt($v): ?int
{
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return $v == floor($v) ? (int) $v : null;
    }
    if (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v)) {
        return (int) $v;
    }
    return null;
}

/**
 * The campaign the call is about ($in carries it as campaign_id or id).
 * Scope-limited users (own / selected campaigns) may only change what is
 * theirs — the same check save_campaign makes.
 */
function orbitraQuickCampaign(PDO $pdo, array $in): array
{
    $cid = orbitraQuickInt($in['campaign_id'] ?? ($in['id'] ?? null));
    if ($cid === null || $cid <= 0) {
        throw new OrbitraQuickActionError('campaign_required', 'Campaign id is required (a positive number).');
    }
    $st = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
    $st->execute([$cid]);
    $campaign = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$campaign) {
        throw new OrbitraQuickActionError('campaign_not_found', "Campaign {$cid} not found.");
    }
    orbitraAssertCampaignInScope(orbitraCampaignScope($pdo), $campaign, true);
    if (!empty($campaign['is_archived'])) {
        throw new OrbitraQuickActionError('campaign_archived', "Campaign {$cid} is in the archive; restore it first.");
    }
    return $campaign;
}

/** The campaign's streams in the order the panel lists them, with native types. */
function orbitraQuickStreams(PDO $pdo, int $campaignId): array
{
    $st = $pdo->prepare('SELECT id, name, weight, is_active, type, position FROM streams WHERE campaign_id = ? ORDER BY position ASC, id ASC');
    $st->execute([$campaignId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $out[] = [
            'id' => (int) $s['id'],
            'name' => (string) ($s['name'] ?? ''),
            'weight' => (int) ($s['weight'] ?? 100),
            'is_active' => (int) ($s['is_active'] ?? 1) === 1,
            'type' => (string) ($s['type'] ?? 'regular'),
            'position' => (int) ($s['position'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Each stream's share of the traffic in %, or null where it gets none.
 *
 * The router weighs the enabled regular streams against each other
 * (selectWeightedItem in index.php), so their weights are the sum. A fallback
 * or cloak stream and a switched-off one are outside that contest.
 */
function orbitraQuickStreamShares(array $streams): array
{
    $total = 0;
    foreach ($streams as $s) {
        if ($s['is_active'] && $s['type'] === 'regular') {
            $total += max(0, $s['weight']);
        }
    }
    $shares = [];
    foreach ($streams as $s) {
        $inContest = $s['is_active'] && $s['type'] === 'regular';
        $shares[$s['id']] = $inContest && $total > 0 ? round(max(0, $s['weight']) / $total * 100, 1) : null;
    }
    return $shares;
}

function orbitraQuickWeight($v, string $what): int
{
    $w = orbitraQuickInt($v);
    if ($w === null || $w < 0 || $w > ORBITRA_QUICK_MAX_WEIGHT) {
        throw new OrbitraQuickActionError('invalid_weight', "The weight for {$what} must be a whole number from 0 to " . ORBITRA_QUICK_MAX_WEIGHT . '.');
    }
    return $w;
}

/**
 * Traffic share of the campaign's streams. $in: campaign_id, weights:
 * [{stream_id, weight}], rotation_type ('weight' | 'position', optional).
 */
function orbitraSetStreamWeights(PDO $pdo, array $in): array
{
    $campaign = orbitraQuickCampaign($pdo, $in);
    $cid = (int) $campaign['id'];
    $streams = orbitraQuickStreams($pdo, $cid);
    $byId = array_column($streams, null, 'id');

    $changes = [];
    foreach (is_array($in['weights'] ?? null) ? $in['weights'] : [] as $w) {
        if (!is_array($w)) {
            throw new OrbitraQuickActionError('invalid_weights', 'weights must be a list of {stream_id, weight}.');
        }
        $sid = orbitraQuickInt($w['stream_id'] ?? null);
        if ($sid === null || !isset($byId[$sid])) {
            throw new OrbitraQuickActionError('stream_not_found', "Stream #" . ($sid ?? '?') . " does not belong to campaign {$cid}.", [
                'streams' => array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name']], $streams),
            ]);
        }
        $changes[$sid] = orbitraQuickWeight($w['weight'] ?? null, "stream #{$sid}");
    }
    if (!$changes) {
        throw new OrbitraQuickActionError('weights_required', 'Pass at least one {stream_id, weight}.');
    }

    $rotation = $in['rotation_type'] ?? null;
    if ($rotation !== null && !in_array($rotation, ['weight', 'position'], true)) {
        throw new OrbitraQuickActionError('invalid_rotation', "rotation_type must be 'weight' or 'position'.");
    }
    $rotationType = $rotation ?? (string) ($campaign['rotation_type'] ?? 'position');

    // Weights only matter with rotation by weight, but they must not leave
    // the campaign unable to pick a stream once it is switched to it.
    $left = 0;
    foreach ($streams as $s) {
        if ($s['is_active'] && $s['type'] === 'regular') {
            $left += $changes[$s['id']] ?? $s['weight'];
        }
    }
    if ($left <= 0) {
        throw new OrbitraQuickActionError('no_traffic_left', 'Every enabled stream would have weight 0, so the campaign could not send its traffic anywhere.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE streams SET weight = ? WHERE id = ? AND campaign_id = ?');
        foreach ($changes as $sid => $weight) {
            $st->execute([$weight, $sid, $cid]);
        }
        $st->closeCursor();
        if ($rotation !== null) {
            $pdo->prepare('UPDATE campaigns SET rotation_type = ? WHERE id = ?')->execute([$rotation, $cid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $after = orbitraQuickStreams($pdo, $cid);
    $shares = orbitraQuickStreamShares($after);
    return [
        'campaign_id' => $cid,
        'campaign' => (string) ($campaign['name'] ?? ''),
        'rotation_type' => $rotationType,
        'streams' => array_map(static fn($s) => $s + ['percent' => $shares[$s['id']]], $after),
        'changed' => array_map(static fn($sid) => [
            'stream_id' => (int) $sid,
            'what' => 'stream #' . $sid,
            'from' => (int) $byId[$sid]['weight'],
            'to' => $changes[$sid],
        ], array_keys($changes)),
        'warning' => $rotationType === 'position'
            ? 'The campaign rotates streams by position (their order), so these weights are not used yet. Pass rotation_type="weight" to switch it to rotation by weight.'
            : null,
    ];
}

/** Switch streams on or off. $in: campaign_id, streams: [{stream_id, is_active}]. */
function orbitraSetStreamActive(PDO $pdo, array $in): array
{
    $campaign = orbitraQuickCampaign($pdo, $in);
    $cid = (int) $campaign['id'];
    $streams = orbitraQuickStreams($pdo, $cid);
    $byId = array_column($streams, null, 'id');

    $changes = [];
    foreach (is_array($in['streams'] ?? null) ? $in['streams'] : [] as $u) {
        if (!is_array($u)) {
            throw new OrbitraQuickActionError('invalid_streams', 'streams must be a list of {stream_id, is_active}.');
        }
        $sid = orbitraQuickInt($u['stream_id'] ?? null);
        if ($sid === null || !isset($byId[$sid])) {
            throw new OrbitraQuickActionError('stream_not_found', "Stream #" . ($sid ?? '?') . " does not belong to campaign {$cid}.", [
                'streams' => array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name']], $streams),
            ]);
        }
        $on = $u['is_active'] ?? null;
        if (!is_bool($on) && $on !== 0 && $on !== 1 && $on !== '0' && $on !== '1') {
            throw new OrbitraQuickActionError('invalid_streams', "is_active for stream #{$sid} must be true or false.");
        }
        $changes[$sid] = $on === true || $on === 1 || $on === '1';
    }
    if (!$changes) {
        throw new OrbitraQuickActionError('streams_required', 'Pass at least one {stream_id, is_active}.');
    }

    // A campaign with every stream off serves nothing at all.
    $enabledAfter = 0;
    foreach ($streams as $s) {
        $enabledAfter += ($changes[$s['id']] ?? $s['is_active']) ? 1 : 0;
    }
    if ($enabledAfter === 0) {
        throw new OrbitraQuickActionError('last_active_stream', 'This would switch off the last enabled stream of the campaign, leaving it nothing to serve. Stop the campaign instead.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE streams SET is_active = ? WHERE id = ? AND campaign_id = ?');
        foreach ($changes as $sid => $on) {
            $st->execute([$on ? 1 : 0, $sid, $cid]);
        }
        $st->closeCursor();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'campaign_id' => $cid,
        'campaign' => (string) ($campaign['name'] ?? ''),
        'streams' => orbitraQuickStreams($pdo, $cid),
        'changed' => array_map(static fn($sid) => [
            'stream_id' => (int) $sid,
            'what' => 'stream #' . $sid,
            'from' => $byId[$sid]['is_active'],
            'to' => $changes[$sid],
        ], array_keys($changes)),
    ];
}

/**
 * Start or stop a campaign. $in: campaign_id, state ('active' | 'paused'), sync_ads
 * (also pause/resume the linked ad-network campaigns, as the panel's switch does).
 */
function orbitraSetCampaignState(PDO $pdo, array $in): array
{
    $campaign = orbitraQuickCampaign($pdo, $in);
    $cid = (int) $campaign['id'];
    $state = strtolower(trim((string) ($in['state'] ?? '')));
    if (!in_array($state, ['active', 'paused', 'disabled'], true)) {
        throw new OrbitraQuickActionError('invalid_state', "state must be 'active' or 'paused'.");
    }
    $new = $state === 'active' ? 'active' : 'disabled';
    $old = (string) ($campaign['state'] ?? 'active') === 'active' ? 'active' : 'disabled';

    $pdo->prepare('UPDATE campaigns SET state = ? WHERE id = ?')->execute([$new, $cid]);

    $ads = [];
    if (!empty($in['sync_ads']) && $new !== $old && function_exists('orbitraSyncCampaignRemoteAds')) {
        $ads = orbitraSyncCampaignRemoteAds($pdo, $cid, $new === 'active' ? 'ACTIVE' : 'PAUSED');
    }

    return [
        'campaign_id' => $cid,
        'campaign' => (string) ($campaign['name'] ?? ''),
        'state' => $new,
        'running' => $new === 'active',
        'changed' => $new !== $old,
        'ads_synced' => $ads,
        'note' => $new === 'disabled' && !$ads
            ? 'The campaign is stopped in the tracker only. The ads at the traffic source keep running; pass sync_ads=true to pause them too.'
            : null,
    ];
}

/**
 * Does the router take this rotation item into the weighted pick? Mirrors
 * selectWeightedItem() in index.php.
 */
function orbitraQuickItemEnabled($it): bool
{
    if (!is_array($it)) {
        return false;
    }
    if (isset($it['state']) && ($it['state'] === 'disabled' || $it['state'] === 'paused')) {
        return false;
    }
    if (isset($it['is_active']) && ($it['is_active'] === false || $it['is_active'] === 0 || $it['is_active'] === '0')) {
        return false;
    }
    return true;
}

/**
 * Weights of the offers or landings inside one stream's rotation. $in:
 * campaign_id, stream_id, offers: [{id, weight}], landings: [{id, weight}],
 * turn_off_auto. Items not listed keep their weight.
 */
function orbitraSetRotationWeights(PDO $pdo, array $in): array
{
    $campaign = orbitraQuickCampaign($pdo, $in);
    $cid = (int) $campaign['id'];
    $sid = orbitraQuickInt($in['stream_id'] ?? null);
    if ($sid === null || $sid <= 0) {
        throw new OrbitraQuickActionError('stream_required', 'stream_id is required (a positive number).');
    }
    $st = $pdo->prepare('SELECT * FROM streams WHERE id = ? AND campaign_id = ?');
    $st->execute([$sid, $cid]);
    $stream = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$stream) {
        throw new OrbitraQuickActionError('stream_not_found', "Stream #{$sid} does not belong to campaign {$cid}.");
    }

    $custom = json_decode((string) ($stream['schema_custom_json'] ?? ''), true);
    if (!is_array($custom)) {
        $custom = [];
    }
    if (array_is_list($custom)) {
        // What API clients sent before 1.8.9: a bare list of offers.
        $custom = orbitraNormalizeSchemaCustom($custom);
    }
    $turnOffAuto = !empty($in['turn_off_auto']);
    $turnedOff = [];
    $changes = [];

    foreach (['offers', 'landings'] as $list) {
        $items = $in[$list] ?? null;
        if ($items === null || $items === []) {
            continue;
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new OrbitraQuickActionError('invalid_weights', "{$list} must be a list of {id, weight}.");
        }
        $current = $custom[$list] ?? null;
        if (!is_array($current) || $current === []) {
            throw new OrbitraQuickActionError('list_not_found', "Stream #{$sid} has no {$list} to weigh: it sends everyone to one place.");
        }

        // While Auto is on, the weights of that list belong to the cron: a
        // campaign save hands the stored ones back and the optimiser's next
        // run overwrites whatever is written here.
        $auto = orbitraNormalizeRotationAutoConfig($custom['auto'][$list] ?? null);
        if ($auto['enabled']) {
            if (!$turnOffAuto) {
                throw new OrbitraQuickActionError('auto_enabled', "Auto-optimisation is on for the {$list} of stream #{$sid} and manages their weights; a manual weight would be overwritten at its next run. Turn it off in the stream's settings or pass turn_off_auto=true.", [
                    'list' => $list,
                ]);
            }
            $custom['auto'][$list]['enabled'] = false;
            $turnedOff[] = $list;
        }

        $index = [];
        foreach ($current as $i => $it) {
            if (is_array($it) && !empty($it['id'])) {
                $index[(int) $it['id']] = $i;
            }
        }
        foreach ($items as $w) {
            if (!is_array($w)) {
                throw new OrbitraQuickActionError('invalid_weights', "{$list} must be a list of {id, weight}.");
            }
            $iid = orbitraQuickInt($w['id'] ?? null);
            $weight = orbitraQuickWeight($w['weight'] ?? null, "{$list} #{$iid}");
            if ($iid === null || !isset($index[$iid])) {
                throw new OrbitraQuickActionError('item_not_in_list', ucfirst($list) . " #" . ($iid ?? '?') . " is not in the rotation of stream #{$sid}. It holds: " . implode(', ', array_map(static fn($i) => '#' . $current[$i]['id'], $index)) . '.', [
                    'list' => $list,
                    'items' => array_values(array_map(static fn($i) => (int) $current[$i]['id'], $index)),
                ]);
            }
            $changes[] = [
                'what' => ($list === 'offers' ? 'offer' : 'landing') . ' #' . $iid,
                'from' => (int) ($current[$index[$iid]]['weight'] ?? 0),
                'to' => $weight,
            ];
            $current[$index[$iid]]['weight'] = $weight;
        }
        $left = 0;
        foreach ($current as $it) {
            if (orbitraQuickItemEnabled($it)) {
                $left += (int) ($it['weight'] ?? 0);
            }
        }
        if ($left <= 0) {
            throw new OrbitraQuickActionError('no_traffic_left', "Every {$list} item of stream #{$sid} would have weight 0, so the stream could not send its traffic anywhere.");
        }
        $custom[$list] = $current;
    }
    if (empty($in['offers']) && empty($in['landings'])) {
        throw new OrbitraQuickActionError('weights_required', 'Pass offers or landings to re-weigh, as [{id, weight}].');
    }

    $pdo->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = ? AND campaign_id = ?')
        ->execute([json_encode($custom, JSON_UNESCAPED_UNICODE), $sid, $cid]);

    return [
        'campaign_id' => $cid,
        'campaign' => (string) ($campaign['name'] ?? ''),
        'stream_id' => $sid,
        'stream' => (string) ($stream['name'] ?? ''),
        'changed' => $changes,
        'lists' => orbitraQuickRotationView($pdo, $custom),
        'auto_turned_off' => $turnedOff,
    ];
}

/** One line for the audit log: what the call changed. */
function orbitraQuickAuditLine(array $res): string
{
    $line = 'campaign #' . $res['campaign_id'];
    if (isset($res['state'])) {
        return $line . ' state → ' . $res['state'] . (!empty($res['ads_synced']) ? ' + ads at the source' : '');
    }
    if (isset($res['stream_id'])) {
        $line .= ' stream #' . $res['stream_id'];
    }
    return $line . ': ' . implode(', ', array_map(static fn($c) => $c['what'] . ' ' . $c['from'] . '→' . $c['to'], $res['changed']));
}

/**
 * The rotation lists as the caller should show them: every item with its name
 * and its share among the items the router takes into the pick.
 */
function orbitraQuickRotationView(PDO $pdo, array $custom): array
{
    $out = [];
    foreach (['offers', 'landings'] as $list) {
        $items = $custom[$list] ?? null;
        if (!is_array($items) || $items === []) {
            continue;
        }
        $total = 0;
        foreach ($items as $it) {
            if (orbitraQuickItemEnabled($it)) {
                $total += max(0, (int) ($it['weight'] ?? 0));
            }
        }
        $table = $list === 'offers' ? 'offers' : 'landings';
        $names = [];
        $ids = array_values(array_filter(array_map(static fn($it) => is_array($it) ? (int) ($it['id'] ?? 0) : 0, $items)));
        if ($ids) {
            $st = $pdo->prepare("SELECT id, name FROM {$table} WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[(int) $r['id']] = (string) ($r['name'] ?? '');
            }
        }
        $rows = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int) ($it['id'] ?? 0);
            $enabled = orbitraQuickItemEnabled($it);
            $rows[] = [
                'id' => $id,
                'name' => $names[$id] ?? null,
                'weight' => (int) ($it['weight'] ?? 0),
                'enabled' => $enabled,
                'percent' => $enabled && $total > 0 ? round(max(0, (int) ($it['weight'] ?? 0)) / $total * 100, 1) : null,
            ];
        }
        $out[$list] = $rows;
    }
    return $out;
}
