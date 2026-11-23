<?php
// core/LocalPageActions.php
//
// Narrow, safe actions for AI-assisted generation and adaptation of local
// landings and local offers:
//   - local_landing_create      create a new local landing (or copy an existing one)
//   - local_landing_write_file  create/update one static file in a local landing
//   - local_offer_create        create a new local offer (or copy an existing one)
//   - local_offer_write_file    create/update one static file in a local offer
//
// Safety invariants enforced here on every call:
//   1. Never overwrite files of a live landing/offer referenced by an active
//      campaign's active stream — the caller must copy it first.
//   2. Never write PHP or executable server-side files — strict text/asset
//      extension allowlist only.
//   3. Every file path stays strictly inside the entity's folder (no traversal).
//   4. User resource scopes (orbitraResourceScope / orbitraScopedGroupId) and
//      audit logging are honoured identically to panel actions.

require_once __DIR__ . '/landing_path.php';
require_once __DIR__ . '/resource_access.php';

/** Strict text/markup extensions the AI page tools may write. Never PHP. */
function orbitraLocalAiWritableExtensions(): array
{
    return ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md', 'xml', 'svg'];
}

/** Max single-file size the AI page tools accept (512 KB). */
const ORBITRA_LOCAL_AI_MAX_FILE_BYTES = 524288;

/**
 * Active campaigns whose active streams reference $entityId as a landing or offer.
 * Returns [['id' => int, 'name' => string], ...].
 */
function orbitraActiveCampaignsUsingEntity(PDO $pdo, string $kind, int $entityId): array
{
    if ($entityId <= 0 || !in_array($kind, ['landing', 'offer'], true)) {
        return [];
    }
    $stmt = $pdo->query(
        "SELECT c.id AS campaign_id, c.name AS campaign_name, s.offer_id, s.schema_custom_json
         FROM streams s
         JOIN campaigns c ON c.id = s.campaign_id
         WHERE LOWER(COALESCE(c.state, 'active')) = 'active'
           AND COALESCE(s.is_active, 1) = 1"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $found = [];
    $listKey = $kind === 'landing' ? 'landings' : 'offers';

    foreach ($rows as $row) {
        $cid = (int) $row['campaign_id'];
        if (isset($found[$cid])) {
            continue;
        }
        $matched = false;
        if ($kind === 'offer' && (int) ($row['offer_id'] ?? 0) === $entityId) {
            $matched = true;
        }
        if (!$matched && !empty($row['schema_custom_json'])) {
            $custom = json_decode((string) $row['schema_custom_json'], true);
            if (is_array($custom) && !empty($custom[$listKey]) && is_array($custom[$listKey])) {
                foreach ($custom[$listKey] as $item) {
                    if (is_array($item) && (int) ($item['id'] ?? 0) === $entityId) {
                        $state = strtolower((string) ($item['state'] ?? 'active'));
                        $active = !array_key_exists('is_active', $item) || !empty($item['is_active']);
                        if ($state !== 'disabled' && $state !== 'paused' && $active) {
                            $matched = true;
                            break;
                        }
                    }
                }
            }
        }
        if ($matched) {
            $found[$cid] = ['id' => $cid, 'name' => (string) ($row['campaign_name'] ?: ('#' . $cid))];
        }
    }
    return array_values($found);
}

/**
 * Copy static files from $srcDir to $dstDir, skipping PHP files and dotfiles
 * like .git/.DS_Store so AI-created copies stay static and safe.
 */
function orbitraCopyStaticDir(string $srcDir, string $dstDir): array
{
    $copied = [];
    if (!is_dir($srcDir)) {
        return $copied;
    }
    @mkdir($dstDir, 0775, true);
    $allowed = function_exists('orbitraLandingEditableExtensions')
        ? orbitraLandingEditableExtensions()
        : orbitraLocalAiWritableExtensions();
    $ignored = ['__MACOSX', '.DS_Store', 'Thumbs.db', '.git'];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($srcDir))), '/');
        if ($rel === '') {
            continue;
        }
        $parts = explode('/', $rel);
        if (array_intersect($parts, $ignored)) {
            continue;
        }
        $target = $dstDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
            continue;
        }
        if (!$item->isFile()) {
            continue;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'php' || !in_array($ext, $allowed, true)) {
            continue;
        }
        @mkdir(dirname($target), 0775, true);
        if (@copy($item->getPathname(), $target)) {
            $copied[] = $rel;
        }
    }
    sort($copied);
    return $copied;
}

/**
 * Resolve $relPath strictly inside $baseDir, rejecting any '..' traversal or
 * symlink escape. Self-contained so core/LocalPageActions.php works both from
 * api.php and in standalone unit tests.
 */
function orbitraSafeFilePathUnder(string $baseDir, string $relPath, bool $mustExist = false): ?string
{
    if (!is_dir($baseDir) && !$mustExist) {
        @mkdir($baseDir, 0775, true);
    }
    $root = realpath($baseDir);
    if ($root === false) {
        return null;
    }
    $segments = [];
    foreach (explode('/', str_replace('\\', '/', $relPath)) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..' || strpos($seg, "\0") !== false) {
            return null;
        }
        $segments[] = $seg;
    }
    if (!$segments) {
        return null;
    }
    $target = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    $real = realpath($target);
    if ($real === false) {
        return $mustExist ? null : $target;
    }
    return ($real === $root || strpos($real, $root . DIRECTORY_SEPARATOR) === 0) ? $real : null;
}

/** Validate and write one static file inside $baseDir. */
function orbitraWriteValidatedStaticFile(string $baseDir, string $relPath, string $content): array
{
    $relPath = ltrim(str_replace('\\', '/', trim($relPath)), '/');
    if ($relPath === '') {
        return ['ok' => false, 'message' => 'File path is required'];
    }
    $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, orbitraLocalAiWritableExtensions(), true)) {
        return [
            'ok' => false,
            'message' => 'File extension not allowed for AI writes (.' . ($ext ?: 'none') . '). Allowed: '
                . implode(', ', orbitraLocalAiWritableExtensions()) . '. PHP is not allowed.',
        ];
    }
    $bytes = strlen($content);
    if ($bytes > ORBITRA_LOCAL_AI_MAX_FILE_BYTES) {
        return ['ok' => false, 'message' => 'File is too large (max 512 KB per file). Split CSS/JS into separate files.'];
    }
    $target = orbitraSafeFilePathUnder($baseDir, $relPath, false);
    if ($target === null) {
        return ['ok' => false, 'message' => 'Invalid file path (access denied)'];
    }
    $existed = is_file($target);
    @mkdir(dirname($target), 0775, true);
    if (@file_put_contents($target, $content) === false) {
        return ['ok' => false, 'message' => 'Could not write file (check folder permissions)'];
    }
    return ['ok' => true, 'path' => $relPath, 'bytes' => $bytes, 'created' => !$existed];
}

/**
 * Collect optional initial files from $in['html'] and/or $in['files'].
 * Returns [['path' => string, 'content' => string], ...].
 */
function orbitraExtractInitialFiles(array $in): array
{
    $out = [];
    if (isset($in['html']) && is_string($in['html']) && $in['html'] !== '') {
        $out['index.html'] = $in['html'];
    }
    if (!empty($in['files']) && is_array($in['files'])) {
        foreach (array_slice($in['files'], 0, 8) as $f) {
            if (is_array($f) && !empty($f['path']) && array_key_exists('content', $f)) {
                $out[(string) $f['path']] = (string) $f['content'];
            }
        }
    }
    $list = [];
    foreach ($out as $path => $content) {
        $list[] = ['path' => $path, 'content' => $content];
    }
    return $list;
}

/**
 * POST action=local_landing_create
 * Creates a brand-new local landing (or a static copy of an existing local landing).
 */
function orbitraLocalLandingCreate(PDO $pdo, array $in): array
{
    $copyFromId = (int) ($in['copy_from_id'] ?? 0);
    $source = null;
    if ($copyFromId > 0) {
        $scope = orbitraResourceScope($pdo, 'landings', true);
        if ($scope !== null && !in_array($copyFromId, $scope['ids'], true)) {
            return ['ok' => false, 'message' => 'Source landing not found or outside your scope'];
        }
        $st = $pdo->prepare("SELECT * FROM landings WHERE id = ? LIMIT 1");
        $st->execute([$copyFromId]);
        $source = $st->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            return ['ok' => false, 'message' => 'Source landing #' . $copyFromId . ' not found'];
        }
        if (($source['type'] ?? '') !== 'local') {
            return ['ok' => false, 'message' => 'Source landing #' . $copyFromId . ' is not a local landing'];
        }
    }

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' && $source) {
        $name = trim((string) $source['name']) . ' (Copy)';
    }
    if ($name === '') {
        return ['ok' => false, 'message' => 'name is required'];
    }

    $rawGroup = array_key_exists('group_id', $in)
        ? (!empty($in['group_id']) ? (int) $in['group_id'] : null)
        : ($source && !empty($source['group_id']) ? (int) $source['group_id'] : null);
    $groupId = orbitraScopedGroupId($pdo, 'landings', null, $rawGroup);

    // Derive or validate slug
    $slugRaw = trim((string) ($in['slug'] ?? ''));
    $slugWasTyped = $slugRaw !== '';
    if ($slugRaw === '') {
        $slugRaw = orbitraSlugify($name);
    }
    $slugCheck = orbitraValidateLandingSlug($pdo, $slugRaw, null);
    if (!$slugCheck['ok'] && !$slugWasTyped && $slugRaw !== '') {
        $base = rtrim(substr($slugRaw, 0, 60), '-_');
        for ($n = 2; $n <= 50; $n++) {
            $candidate = orbitraValidateLandingSlug($pdo, $base . '-' . $n, null);
            if ($candidate['ok']) {
                $slugCheck = $candidate;
                break;
            }
        }
        if (!$slugCheck['ok']) {
            $slugCheck = ['ok' => true, 'value' => '', 'error' => ''];
        }
    }
    if (!$slugCheck['ok']) {
        return ['ok' => false, 'message' => $slugCheck['error']];
    }
    $slug = $slugCheck['value'];

    $stmt = $pdo->prepare(
        "INSERT INTO landings (name, group_id, type, url, action_payload, action_type, state, slug, redirect_type)
         VALUES (?, ?, 'local', '', NULL, '', 'active', ?, 'redirect')"
    );
    $stmt->execute([$name, $groupId, $slug]);
    $newId = (int) $pdo->lastInsertId();
    orbitraStampOwner($pdo, 'landings', $newId);

    $newDir = orbitraLandingDir($pdo, $newId);
    @mkdir($newDir, 0775, true);

    $files = [];
    if ($source) {
        $srcDir = orbitraLandingDir($pdo, $copyFromId);
        $files = orbitraCopyStaticDir($srcDir, $newDir);
    }

    foreach (orbitraExtractInitialFiles($in) as $f) {
        $w = orbitraWriteValidatedStaticFile($newDir, $f['path'], $f['content']);
        if (!$w['ok']) {
            return ['ok' => false, 'message' => $w['message']];
        }
        if (!in_array($w['path'], $files, true)) {
            $files[] = $w['path'];
        }
    }
    sort($files);

    if (function_exists('logAudit')) {
        logAudit($pdo, 'CREATE', 'Landing', $newId, 'AI local landing: ' . $name . ($copyFromId > 0 ? " (copy of #{$copyFromId})" : ''));
    }

    return [
        'ok' => true,
        'data' => [
            'id' => $newId,
            'name' => $name,
            'type' => 'local',
            'slug' => $slug,
            'copied_from' => $copyFromId > 0 ? $copyFromId : null,
            'files' => $files,
        ],
    ];
}

/**
 * POST action=local_landing_write_file
 * Writes a single static file inside a local landing that is not used by any
 * active campaign.
 */
function orbitraLocalLandingWriteFile(PDO $pdo, array $in): array
{
    $id = (int) ($in['id'] ?? $in['landing_id'] ?? 0);
    $path = (string) ($in['path'] ?? '');
    $content = (string) ($in['content'] ?? '');
    if ($id <= 0 || trim($path) === '') {
        return ['ok' => false, 'message' => 'id and path are required'];
    }

    $scope = orbitraResourceScope($pdo, 'landings', true);
    if ($scope !== null && !in_array($id, $scope['ids'], true)) {
        return ['ok' => false, 'message' => 'Landing not found or outside your scope'];
    }

    $st = $pdo->prepare("SELECT id, name, type FROM landings WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Landing #' . $id . ' not found'];
    }
    if (($row['type'] ?? '') !== 'local') {
        return ['ok' => false, 'message' => 'Landing #' . $id . ' is not a local landing'];
    }

    $activeCampaigns = orbitraActiveCampaignsUsingEntity($pdo, 'landing', $id);
    if ($activeCampaigns) {
        $names = implode(', ', array_map(static fn($c) => '#' . $c['id'] . ' "' . $c['name'] . '"', $activeCampaigns));
        return [
            'ok' => false,
            'code' => 'live_entity_protected',
            'message' => "Landing #{$id} is used by active campaign(s): {$names}. Never overwrite a live page — create a copy with orbitra_create_local_landing (copy_from_id: {$id}) first, or use a text A/B test.",
        ];
    }

    $dir = orbitraLandingDir($pdo, $id);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $res = orbitraWriteValidatedStaticFile($dir, $path, $content);
    if (!$res['ok']) {
        return $res;
    }

    if (function_exists('logAudit')) {
        logAudit($pdo, 'UPDATE', 'Landing', $id, 'AI wrote landing file ' . $res['path']);
    }

    return [
        'ok' => true,
        'data' => [
            'id' => $id,
            'name' => (string) $row['name'],
            'path' => $res['path'],
            'bytes' => $res['bytes'],
            'created' => $res['created'],
        ],
    ];
}

/**
 * POST action=local_offer_create
 * Creates a brand-new local offer (is_local=1), or a static copy of an existing
 * local offer.
 */
function orbitraLocalOfferCreate(PDO $pdo, array $in): array
{
    $copyFromId = (int) ($in['copy_from_id'] ?? 0);
    $source = null;
    if ($copyFromId > 0) {
        $scope = orbitraResourceScope($pdo, 'offers', true);
        if ($scope !== null && !in_array($copyFromId, $scope['ids'], true)) {
            return ['ok' => false, 'message' => 'Source offer not found or outside your scope'];
        }
        $st = $pdo->prepare("SELECT * FROM offers WHERE id = ? LIMIT 1");
        $st->execute([$copyFromId]);
        $source = $st->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            return ['ok' => false, 'message' => 'Source offer #' . $copyFromId . ' not found'];
        }
        if (empty($source['is_local'])) {
            return ['ok' => false, 'message' => 'Source offer #' . $copyFromId . ' is not a local offer'];
        }
    }

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' && $source) {
        $name = trim((string) $source['name']) . ' (Copy)';
    }
    if ($name === '') {
        return ['ok' => false, 'message' => 'name is required'];
    }

    $rawGroup = array_key_exists('group_id', $in)
        ? (!empty($in['group_id']) ? (int) $in['group_id'] : null)
        : ($source && !empty($source['group_id']) ? (int) $source['group_id'] : null);
    $groupId = orbitraScopedGroupId($pdo, 'offers', null, $rawGroup);

    $geo = array_key_exists('geo', $in) ? (string) $in['geo'] : (string) ($source['geo'] ?? '');
    $payoutType = array_key_exists('payout_type', $in) ? (string) $in['payout_type'] : (string) ($source['payout_type'] ?? 'cpa');
    $payoutValue = array_key_exists('payout_value', $in) ? (float) $in['payout_value'] : (float) ($source['payout_value'] ?? 0.0);
    $notes = array_key_exists('notes', $in) ? (string) $in['notes'] : (string) ($source['notes'] ?? '');

    $stmt = $pdo->prepare(
        "INSERT INTO offers
         (name, group_id, affiliate_network_id, url, redirect_type, is_local, geo,
          payout_type, payout_value, payout_auto, allow_rebills, capping_limit,
          capping_timezone, alt_offer_id, notes, values_json, country_values,
          tg_channel_id, tg_window_days, tg_welcome, tg_bot_id, state)
         VALUES (?, ?, NULL, '', 'redirect', 1, ?, ?, ?, 0, 0, 0, 'UTC', NULL, ?, '[]', '{}', '', 0, '', 0, 'active')"
    );
    $stmt->execute([$name, $groupId, $geo, $payoutType, $payoutValue, $notes]);
    $newId = (int) $pdo->lastInsertId();
    orbitraStampOwner($pdo, 'offers', $newId);

    $offersBase = dirname(__DIR__) . '/offers';
    $newDir = $offersBase . '/' . $newId;
    @mkdir($newDir, 0775, true);

    $files = [];
    if ($source) {
        $srcDir = $offersBase . '/' . $copyFromId;
        $files = orbitraCopyStaticDir($srcDir, $newDir);
    }

    foreach (orbitraExtractInitialFiles($in) as $f) {
        $w = orbitraWriteValidatedStaticFile($newDir, $f['path'], $f['content']);
        if (!$w['ok']) {
            return ['ok' => false, 'message' => $w['message']];
        }
        if (!in_array($w['path'], $files, true)) {
            $files[] = $w['path'];
        }
    }
    sort($files);

    if (function_exists('logAudit')) {
        logAudit($pdo, 'CREATE', 'Offer', $newId, 'AI local offer: ' . $name . ($copyFromId > 0 ? " (copy of #{$copyFromId})" : ''));
    }

    return [
        'ok' => true,
        'data' => [
            'id' => $newId,
            'name' => $name,
            'is_local' => 1,
            'geo' => $geo,
            'copied_from' => $copyFromId > 0 ? $copyFromId : null,
            'files' => $files,
        ],
    ];
}

/**
 * POST action=local_offer_write_file
 * Writes a single static file inside a local offer that is not used by any
 * active campaign.
 */
function orbitraLocalOfferWriteFile(PDO $pdo, array $in): array
{
    $id = (int) ($in['id'] ?? $in['offer_id'] ?? 0);
    $path = (string) ($in['path'] ?? '');
    $content = (string) ($in['content'] ?? '');
    if ($id <= 0 || trim($path) === '') {
        return ['ok' => false, 'message' => 'id and path are required'];
    }

    $scope = orbitraResourceScope($pdo, 'offers', true);
    if ($scope !== null && !in_array($id, $scope['ids'], true)) {
        return ['ok' => false, 'message' => 'Offer not found or outside your scope'];
    }

    $st = $pdo->prepare("SELECT id, name, is_local FROM offers WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Offer #' . $id . ' not found'];
    }
    if (empty($row['is_local'])) {
        return ['ok' => false, 'message' => 'Offer #' . $id . ' is not a local offer'];
    }

    $activeCampaigns = orbitraActiveCampaignsUsingEntity($pdo, 'offer', $id);
    if ($activeCampaigns) {
        $names = implode(', ', array_map(static fn($c) => '#' . $c['id'] . ' "' . $c['name'] . '"', $activeCampaigns));
        return [
            'ok' => false,
            'code' => 'live_entity_protected',
            'message' => "Offer #{$id} is used by active campaign(s): {$names}. Never overwrite a live offer — create a copy with orbitra_create_local_offer (copy_from_id: {$id}) first.",
        ];
    }

    $dir = dirname(__DIR__) . '/offers/' . $id;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $res = orbitraWriteValidatedStaticFile($dir, $path, $content);
    if (!$res['ok']) {
        return $res;
    }

    if (function_exists('logAudit')) {
        logAudit($pdo, 'UPDATE', 'Offer', $id, 'AI wrote offer file ' . $res['path']);
    }

    return [
        'ok' => true,
        'data' => [
            'id' => $id,
            'name' => (string) $row['name'],
            'path' => $res['path'],
            'bytes' => $res['bytes'],
            'created' => $res['created'],
        ],
    ];
}
