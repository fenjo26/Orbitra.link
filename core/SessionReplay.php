<?php
// core/SessionReplay.php
//
// Session recordings (Clarity / Yandex Webvisor style) for landings.
//
// What the operator gets: a video-like replay of a visitor on the landing —
// mouse, scroll, clicks, taps — plus a click heatmap and a scroll map per
// landing. Recording is opt-in PER LANDING (landing editor → "Session
// recordings" tab) and sampled, because every replay costs disk.
//
// How it fits together:
//
//   1. Arming (server, at serve time). orbitraReplayArm() decides whether
//      THIS click is recorded: recording switched on for the click's landing
//      (or, since organic sites, for its CAMPAIGN — see the precedence note
//      inside orbitraReplayArm), recording window still open, click not a
//      bot / safe-page visitor, deterministic sample hit, disk not nearly
//      full. A hit inserts an 'armed' session_replays row and returns the
//      payload for /replay.js. A miss returns null and the page gets no
//      script at all — nothing to load, nothing to notice.
//
//   2. Recording (browser). /replay.js (rrweb's recorder, built from
//      frontend/replay/recorder.js) records DOM mutations and input, masks
//      every form field, and POSTs a batch every few seconds to
//      /pixel.gif?action=rr. Heatmap clicks ride the same batches.
//
//   3. Ingest (server). orbitraReplayIngest() accepts a batch only for an
//      existing armed/recording row and a valid signature, enforces the
//      per-recording caps, appends the batch to
//      var/replays/<YYYY-MM-DD>/<click>.ndjson.gz as one gzip member per
//      batch, and updates the row's counters.
//
//   4. Housekeeping. orbitraReplayGc() runs at most once a minute from the
//      ingest path (no extra cron to install on 1000+ existing servers):
//      deletes recordings older than the retention window, then the oldest
//      recordings until the total fits the storage cap — new recordings
//      overwrite the oldest ones, like a dashcam.
//
// Storage settings (settings table): replay_retention_days, replay_max_mb.

const ORBITRA_REPLAY_DEFAULT_RETENTION_DAYS = 3;
const ORBITRA_REPLAY_DEFAULT_MAX_MB = 1024;
const ORBITRA_REPLAY_DEFAULT_SAMPLE_PCT = 10;
/** Hard caps for one recording: a tab left open for hours must not eat the disk. */
const ORBITRA_REPLAY_MAX_SECONDS = 1800;
const ORBITRA_REPLAY_MAX_RECORDING_BYTES = 5 * 1024 * 1024;
/** One batch: compressed body and decompressed JSON limits. */
const ORBITRA_REPLAY_MAX_BODY_BYTES = 2 * 1024 * 1024;
const ORBITRA_REPLAY_MAX_JSON_BYTES = 12 * 1024 * 1024;
/** Never arm a recording when the disk has less than this left. */
const ORBITRA_REPLAY_MIN_FREE_BYTES = 1024 * 1024 * 1024;
/** Estimate shown before any recording exists (bytes per recording). */
const ORBITRA_REPLAY_ESTIMATE_BYTES = 150 * 1024;
/** Heatmap points outlive the recordings (they are tiny): days kept. */
const ORBITRA_REPLAY_CLICKS_KEEP_DAYS = 30;
/** Below this viewport width a recording counts as mobile. */
const ORBITRA_REPLAY_MOBILE_MAX_WIDTH = 768;
/**
 * Source filter → se_referrer substrings. Heuristic by design: a referrer is
 * whatever the visitor's browser sent, and the list covers the engines that
 * matter for organic traffic. Keys are the accepted ?source= values.
 */
const ORBITRA_REPLAY_SOURCE_LIKES = [
    'google' => ['%"se_referrer":"%google.%'],
    'bing' => ['%"se_referrer":"%bing.%'],
    'yandex' => ['%"se_referrer":"%yandex.%'],
    'social' => [
        '%"se_referrer":"%facebook.%', '%"se_referrer":"%instagram.%', '%"se_referrer":"%t.me%',
        '%"se_referrer":"%twitter.%', '%"se_referrer":"%x.com%', '%"se_referrer":"%linkedin.%',
        '%"se_referrer":"%tiktok.%', '%"se_referrer":"%vk.com%', '%"se_referrer":"%pinterest.%',
        '%"se_referrer":"%youtube.%', '%"se_referrer":"%reddit.%',
    ],
];

/** Idempotent DDL — called by migration 76/78 and by the tests. */
function orbitraReplayEnsureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS session_replays (
        click_id TEXT PRIMARY KEY,
        landing_id INTEGER,
        campaign_id INTEGER,
        status TEXT NOT NULL DEFAULT 'armed',
        file TEXT NOT NULL DEFAULT '',
        bytes INTEGER NOT NULL DEFAULT 0,
        chunks INTEGER NOT NULL DEFAULT 0,
        events INTEGER NOT NULL DEFAULT 0,
        first_ts INTEGER,
        last_ts INTEGER,
        duration_ms INTEGER NOT NULL DEFAULT 0,
        clicks INTEGER NOT NULL DEFAULT 0,
        rage_clicks INTEGER NOT NULL DEFAULT 0,
        viewport_w INTEGER,
        viewport_h INTEGER,
        page_url TEXT,
        entry_url TEXT,
        pages INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_session_replays_created ON session_replays(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_session_replays_landing ON session_replays(landing_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_session_replays_campaign ON session_replays(campaign_id, created_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS replay_clicks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        landing_id INTEGER NOT NULL,
        campaign_id INTEGER,
        click_id TEXT NOT NULL,
        device TEXT NOT NULL DEFAULT 'desktop',
        x REAL NOT NULL,
        y INTEGER NOT NULL,
        doc_w INTEGER,
        doc_h INTEGER,
        rage INTEGER NOT NULL DEFAULT 0,
        sel TEXT,
        page TEXT,
        created_at DATETIME NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_replay_clicks_landing ON replay_clicks(landing_id, device, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_replay_clicks_created ON replay_clicks(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_replay_clicks_campaign ON replay_clicks(campaign_id, device, created_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS replay_landing_settings (
        landing_id INTEGER PRIMARY KEY,
        enabled INTEGER NOT NULL DEFAULT 0,
        sample_pct INTEGER NOT NULL DEFAULT 10,
        until_at DATETIME,
        updated_at DATETIME
    )");
    // Organic sites (campaign-level switch, multi-page sessions): the columns
    // arrive with schema 78. ALTERs land here — not in the migration — because
    // this DDL also self-heals installs already stamped with version 78.
    foreach ([
        'ALTER TABLE session_replays ADD COLUMN entry_url TEXT',
        'ALTER TABLE session_replays ADD COLUMN pages INTEGER NOT NULL DEFAULT 1',
        'ALTER TABLE replay_clicks ADD COLUMN campaign_id INTEGER',
        'ALTER TABLE replay_clicks ADD COLUMN page TEXT',
        'ALTER TABLE replay_campaign_settings ADD COLUMN session_continue INTEGER NOT NULL DEFAULT 0',
    ] as $ddl78) {
        try {
            $pdo->exec($ddl78);
        } catch (\Throwable $e) {
            // Column already exists.
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS replay_campaign_settings (
        campaign_id INTEGER PRIMARY KEY,
        enabled INTEGER NOT NULL DEFAULT 0,
        sample_pct INTEGER NOT NULL DEFAULT 10,
        until_at DATETIME,
        consent INTEGER NOT NULL DEFAULT 0,
        session_continue INTEGER NOT NULL DEFAULT 0,
        updated_at DATETIME
    )");
}

/** Where recordings live. var/ is denied by the nginx vhost, so files are never public. */
function orbitraReplayDir(): string
{
    if (defined('ORBITRA_REPLAY_DIR')) {
        return rtrim((string) ORBITRA_REPLAY_DIR, '/');
    }
    return dirname(__DIR__) . '/var/replays';
}

function orbitraReplaySetting(PDO $pdo, string $key, $default)
{
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch (\Throwable $e) {
        return $default;
    }
}

/** Storage limits, clamped to sane ranges. */
function orbitraReplayStorageConfig(PDO $pdo): array
{
    $days = (int) orbitraReplaySetting($pdo, 'replay_retention_days', ORBITRA_REPLAY_DEFAULT_RETENTION_DAYS);
    $mb = (int) orbitraReplaySetting($pdo, 'replay_max_mb', ORBITRA_REPLAY_DEFAULT_MAX_MB);
    return [
        'retention_days' => max(1, min($days, 90)),
        'max_mb' => max(50, min($mb, 512000)),
    ];
}

/** Signature that ties ingest batches to a click the server armed. */
function orbitraReplayToken(PDO $pdo, string $clickId): string
{
    static $secret = null;
    if ($secret === null) {
        $secret = (string) orbitraReplaySetting($pdo, 'postback_key', 'orbitra_secret');
    }
    return substr(hash_hmac('sha256', 'replay|' . $clickId, $secret), 0, 20);
}

function orbitraReplayValidClickId(string $clickId): bool
{
    return $clickId !== '' && strlen($clickId) <= 64 && preg_match('/^[A-Za-z0-9_\-]+$/', $clickId) === 1;
}

/** A landing's recording switch, sampling and window; 'active' = records right now. */
function orbitraReplayLandingConfig(PDO $pdo, int $landingId): array
{
    $cfg = ['landing_id' => $landingId, 'enabled' => false, 'sample_pct' => ORBITRA_REPLAY_DEFAULT_SAMPLE_PCT, 'until_at' => null, 'active' => false];
    if ($landingId <= 0) {
        return $cfg;
    }
    try {
        $stmt = $pdo->prepare("SELECT enabled, sample_pct, until_at FROM replay_landing_settings WHERE landing_id = ? LIMIT 1");
        $stmt->execute([$landingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return $cfg;
    }
    if (!$row) {
        return $cfg;
    }
    $cfg['enabled'] = (int) $row['enabled'] === 1;
    $cfg['sample_pct'] = max(1, min((int) $row['sample_pct'], 100));
    $cfg['until_at'] = $row['until_at'] ?: null;
    $expired = $cfg['until_at'] !== null && strtotime($cfg['until_at'] . ' UTC') <= time();
    $cfg['active'] = $cfg['enabled'] && !$expired;
    return $cfg;
}

/** True once the operator has ever saved recording settings for this landing. */
function orbitraReplayLandingHasSettings(PDO $pdo, int $landingId): bool
{
    if ($landingId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM replay_landing_settings WHERE landing_id = ? LIMIT 1");
        $stmt->execute([$landingId]);
        return $stmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * A campaign's recording switch — the organic-site twin of the landing one.
 * 'consent' gates the recorder client-side: nothing is written before the
 * site calls OrbitraReplay.consent() (EU cookie-consent workflows).
 * 'session_continue' opts the campaign into click_api's ?_subid= visit
 * gluing (one click per site visit instead of one per page). Off by default:
 * an upgrade must not change anyone's numbers on its own.
 */
function orbitraReplayCampaignConfig(PDO $pdo, int $campaignId): array
{
    $cfg = ['campaign_id' => $campaignId, 'enabled' => false, 'sample_pct' => ORBITRA_REPLAY_DEFAULT_SAMPLE_PCT, 'until_at' => null, 'consent' => false, 'session_continue' => false, 'active' => false];
    if ($campaignId <= 0) {
        return $cfg;
    }
    try {
        $stmt = $pdo->prepare("SELECT enabled, sample_pct, until_at, consent, session_continue FROM replay_campaign_settings WHERE campaign_id = ? LIMIT 1");
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return $cfg;
    }
    if (!$row) {
        return $cfg;
    }
    $cfg['enabled'] = (int) $row['enabled'] === 1;
    $cfg['sample_pct'] = max(1, min((int) $row['sample_pct'], 100));
    $cfg['until_at'] = $row['until_at'] ?: null;
    $cfg['consent'] = (int) ($row['consent'] ?? 0) === 1;
    $cfg['session_continue'] = (int) ($row['session_continue'] ?? 0) === 1;
    $expired = $cfg['until_at'] !== null && strtotime($cfg['until_at'] . ' UTC') <= time();
    $cfg['active'] = $cfg['enabled'] && !$expired;
    return $cfg;
}

/** Path of a page URL ('/pricing'), the heatmap and page-counter key. */
function orbitraReplayPagePath(string $href): string
{
    $path = '';
    if (preg_match('#^[a-z][a-z0-9+.\-]*://[^/]+#i', $href)) {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?: '/');
    } elseif ($href !== '' && $href[0] === '/') {
        $path = $href;
    } else {
        return '/';
    }
    if ($path === '') {
        $path = '/';
    }
    return substr($path, 0, 500);
}

/** Deterministic sample: the same click always gets the same answer. */
function orbitraReplaySampleHit(string $clickId, int $pct): bool
{
    if ($pct >= 100) {
        return true;
    }
    if ($pct <= 0) {
        return false;
    }
    return (crc32('rr:' . $clickId) % 100) < $pct;
}

function orbitraReplayDiskHasRoom(): bool
{
    $dir = orbitraReplayDir();
    $probe = is_dir($dir) ? $dir : dirname($dir);
    $free = @disk_free_space($probe);
    if ($free === false) {
        return true; // cannot tell — the storage cap still bounds us
    }
    return $free >= ORBITRA_REPLAY_MIN_FREE_BYTES;
}

/**
 * Decide whether this click is recorded and, when it is, arm the recording
 * row. Returns the client payload (without endpoint/script — callers add the
 * origin they serve from) or null.
 */
function orbitraReplayArm(PDO $pdo, string $clickId): ?array
{
    static $memo = [];
    $clickId = trim($clickId);
    if (!orbitraReplayValidClickId($clickId)) {
        return null;
    }
    if (array_key_exists($clickId, $memo)) {
        return $memo[$clickId];
    }
    $memo[$clickId] = null;
    try {
        $stmt = $pdo->prepare("SELECT landing_id, campaign_id, is_bot, is_safe_page FROM clicks WHERE id = ? LIMIT 1");
        $stmt->execute([$clickId]);
        $click = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$click) {
        return null;
    }
    // A bot or a moderator on the safe page never even loads the recorder.
    if ((int) ($click['is_bot'] ?? 0) === 1 || (int) ($click['is_safe_page'] ?? 0) === 1) {
        return null;
    }
    // Precedence: a landing the operator has EVER configured for recordings
    // keeps full control of its own clicks (off means off, arbitrage unchanged).
    // A click whose landing was never touched — including the no-landing clicks
    // of an organic site's campaign — falls through to the campaign switch.
    $landingId = (int) ($click['landing_id'] ?? 0);
    $campaignId = (int) ($click['campaign_id'] ?? 0);
    $consent = false;
    if ($landingId > 0 && orbitraReplayLandingHasSettings($pdo, $landingId)) {
        $cfg = orbitraReplayLandingConfig($pdo, $landingId);
    } else {
        $cfg = orbitraReplayCampaignConfig($pdo, $campaignId);
        $consent = !empty($cfg['consent']);
    }
    if (!$cfg['active'] || !orbitraReplaySampleHit($clickId, (int) $cfg['sample_pct'])) {
        return null;
    }
    if (!orbitraReplayDiskHasRoom()) {
        return null;
    }
    try {
        // pages starts at 0: the first ingested meta event turns it into 1.
        $pdo->prepare(
            "INSERT OR IGNORE INTO session_replays (click_id, landing_id, campaign_id, status, pages, created_at)
             VALUES (?, ?, ?, 'armed', 0, ?)"
        )->execute([$clickId, $landingId ?: null, $campaignId ?: null, gmdate('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        return null; // no table yet (mid-upgrade) or DB locked: skip, never break the landing
    }
    $memo[$clickId] = [
        'v' => 1,
        'subid' => $clickId,
        'k' => orbitraReplayToken($pdo, $clickId),
        'maxSec' => ORBITRA_REPLAY_MAX_SECONDS,
    ];
    if ($consent) {
        $memo[$clickId]['consent'] = 1;
    }
    return $memo[$clickId];
}

/** <script> tag for /replay.js; '' when nothing to record. */
function orbitraReplayScriptTag(array $payload, string $scriptSrc): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        return '';
    }
    return '<script src="' . htmlspecialchars($scriptSrc, ENT_QUOTES, 'UTF-8')
        . '" data-orbitra-replay="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8')
        . '" defer charset="utf-8"></script>';
}

/** Append the recorder to a landing served by this tracker (no-op when not armed). */
function orbitraInjectReplay($html, $clickId)
{
    if (!is_string($html) || $html === '' || !isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        return $html;
    }
    if (stripos($html, 'data-orbitra-replay') !== false) {
        return $html;
    }
    $payload = orbitraReplayArm($GLOBALS['pdo'], (string) $clickId);
    if ($payload === null) {
        return $html;
    }
    $payload['endpoint'] = '/pixel.gif';
    $script = orbitraReplayScriptTag($payload, '/replay.js');
    if ($script === '') {
        return $html;
    }
    if (preg_match('/<\/body>/i', $html)) {
        return preg_replace('/<\/body>/i', $script . '</body>', $html, 1);
    }
    return $html . $script;
}

// ---------------------------------------------------------------------------
// Ingest
// ---------------------------------------------------------------------------

function orbitraReplayRespond(int $code): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
    }
}

/**
 * POST /pixel.gif?action=rr&subid=S&k=TOKEN&n=SEQ[&z=1]
 * Body: JSON {"e":[rrweb events], "c":[heatmap clicks]} — gzip when z=1.
 * Always answers quickly; the recorder ignores the response.
 */
function orbitraReplayIngest(PDO $pdo, ?string $rawBody = null): array
{
    $clickId = trim((string) ($_GET['subid'] ?? ''));
    $token = (string) ($_GET['k'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        orbitraReplayRespond(204);
        return ['ok' => false, 'reason' => 'preflight'];
    }
    if (!orbitraReplayValidClickId($clickId) || !hash_equals(orbitraReplayToken($pdo, $clickId), $token)) {
        orbitraReplayRespond(403);
        return ['ok' => false, 'reason' => 'signature'];
    }
    if ($rawBody === null) {
        $rawBody = (string) file_get_contents('php://input', false, null, 0, ORBITRA_REPLAY_MAX_BODY_BYTES + 1);
    }
    if ($rawBody === '' || strlen($rawBody) > ORBITRA_REPLAY_MAX_BODY_BYTES) {
        orbitraReplayRespond(413);
        return ['ok' => false, 'reason' => 'size'];
    }
    if (($_GET['z'] ?? '') === '1') {
        if (substr($rawBody, 0, 2) !== "\x1f\x8b") {
            orbitraReplayRespond(400);
            return ['ok' => false, 'reason' => 'gzip'];
        }
        $json = @gzdecode($rawBody, ORBITRA_REPLAY_MAX_JSON_BYTES);
        if (!is_string($json)) {
            orbitraReplayRespond(400);
            return ['ok' => false, 'reason' => 'gzip'];
        }
    } else {
        $json = $rawBody;
    }
    $batch = json_decode($json, true);
    if (!is_array($batch) || !isset($batch['e']) || !is_array($batch['e'])) {
        orbitraReplayRespond(400);
        return ['ok' => false, 'reason' => 'json'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM session_replays WHERE click_id = ? LIMIT 1");
        $stmt->execute([$clickId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        orbitraReplayRespond(503);
        return ['ok' => false, 'reason' => 'db'];
    }
    // Only a recording the server armed; a closed one stays closed.
    if (!$row || $row['status'] === 'closed') {
        orbitraReplayRespond(410);
        return ['ok' => false, 'reason' => 'not_armed'];
    }

    // Keep only well-formed events (objects with a numeric type + timestamp).
    $events = [];
    $minTs = null;
    $maxTs = null;
    $vw = null;
    $vh = null;
    // Meta events in arrival order: the LAST is the page the visitor is on
    // now (page_url), the FIRST of a recording is the entry page, and each
    // href change is one more page of a multi-page session.
    $metas = [];
    foreach ($batch['e'] as $ev) {
        if (!is_array($ev) || !isset($ev['type'], $ev['timestamp']) || !is_numeric($ev['type']) || !is_numeric($ev['timestamp'])) {
            continue;
        }
        $ts = (int) $ev['timestamp'];
        $minTs = $minTs === null ? $ts : min($minTs, $ts);
        $maxTs = $maxTs === null ? $ts : max($maxTs, $ts);
        // Meta event: page URL and viewport.
        if ((int) $ev['type'] === 4 && isset($ev['data']) && is_array($ev['data'])) {
            $vw = isset($ev['data']['width']) ? (int) $ev['data']['width'] : $vw;
            $vh = isset($ev['data']['height']) ? (int) $ev['data']['height'] : $vh;
            if (isset($ev['data']['href']) && is_string($ev['data']['href']) && $ev['data']['href'] !== '') {
                $metas[] = substr($ev['data']['href'], 0, 1000);
            }
        }
        $events[] = $ev;
    }
    $href = $metas !== [] ? $metas[count($metas) - 1] : null;
    $entryHref = $metas !== [] ? $metas[0] : null;
    // Pages seen in this batch that differ (by path) from the previous one.
    // Reloads of the same page do not inflate the counter.
    $lastPath = $row['page_url'] !== null ? orbitraReplayPagePath((string) $row['page_url']) : null;
    $pagesAdd = 0;
    foreach ($metas as $m) {
        $p = orbitraReplayPagePath($m);
        if ($p !== $lastPath) {
            $pagesAdd++;
            $lastPath = $p;
        }
    }
    $newPages = ((int) $row['pages'] > 0 ? (int) $row['pages'] : 0) + $pagesAdd;

    $firstTs = $row['first_ts'] !== null ? (int) $row['first_ts'] : null;
    $lastTs = $row['last_ts'] !== null ? (int) $row['last_ts'] : null;
    if ($minTs !== null) {
        $firstTs = $firstTs === null ? $minTs : min($firstTs, $minTs);
        $lastTs = $lastTs === null ? $maxTs : max($lastTs, $maxTs);
    }
    $duration = ($firstTs !== null && $lastTs !== null) ? max(0, $lastTs - $firstTs) : 0;

    $line = $events ? json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
    $gz = $line !== '' && $line !== false ? gzencode($line . "\n", 6) : '';
    $newBytes = (int) $row['bytes'] + strlen((string) $gz);

    // Caps: length and size of one recording. The batch that crosses a cap is
    // dropped and the recording closes — what is already stored stays.
    if ($newBytes > ORBITRA_REPLAY_MAX_RECORDING_BYTES || $duration > ORBITRA_REPLAY_MAX_SECONDS * 1000 + 60000) {
        try {
            $pdo->prepare("UPDATE session_replays SET status = 'closed', updated_at = ? WHERE click_id = ?")
                ->execute([gmdate('Y-m-d H:i:s'), $clickId]);
        } catch (\Throwable $e) {
        }
        orbitraReplayRespond(410);
        return ['ok' => false, 'reason' => 'cap'];
    }

    $file = (string) $row['file'];
    if ($gz !== '') {
        if ($file === '') {
            $day = substr((string) $row['created_at'], 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $day = gmdate('Y-m-d');
            }
            $file = $day . '/' . $clickId . '.ndjson.gz';
        }
        $path = orbitraReplayDir() . '/' . $file;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            orbitraReplayRespond(507);
            return ['ok' => false, 'reason' => 'mkdir'];
        }
        // One gzip member per batch; gzopen() reads concatenated members as one stream.
        if (@file_put_contents($path, $gz, FILE_APPEND | LOCK_EX) === false) {
            orbitraReplayRespond(507);
            return ['ok' => false, 'reason' => 'write'];
        }
    }

    // Heatmap clicks.
    $clicksIn = isset($batch['c']) && is_array($batch['c']) ? array_slice($batch['c'], 0, 300) : [];
    $clickRows = [];
    $rage = 0;
    foreach ($clicksIn as $c) {
        if (!is_array($c) || !isset($c['x'], $c['y']) || !is_numeric($c['x']) || !is_numeric($c['y'])) {
            continue;
        }
        $x = max(0.0, min((float) $c['x'], 1.0));
        $y = max(0, min((int) $c['y'], 200000));
        $dw = isset($c['w']) ? max(0, min((int) $c['w'], 10000)) : null;
        $dh = isset($c['h']) ? max(0, min((int) $c['h'], 200000)) : null;
        $r = !empty($c['r']) ? 1 : 0;
        $rage += $r;
        $sel = isset($c['s']) ? substr(preg_replace('/[^\w\-\.#:> \[\]="]/u', '', (string) $c['s']), 0, 160) : null;
        // The click's own page path when the recorder sent it: a late batch
        // from the previous page must not repaint its clicks onto the new
        // page's heatmap (beacons can overtake each other on navigation).
        $p = (isset($c['p']) && is_string($c['p']) && $c['p'] !== '' && $c['p'][0] === '/')
            ? substr($c['p'], 0, 500)
            : null;
        $clickRows[] = [$x, $y, $dw, $dh, $r, $sel, $p];
    }

    $now = gmdate('Y-m-d H:i:s');
    $vwFinal = $vw ?? ($row['viewport_w'] !== null ? (int) $row['viewport_w'] : null);
    $device = ($vwFinal !== null && $vwFinal > 0 && $vwFinal < ORBITRA_REPLAY_MOBILE_MAX_WIDTH) ? 'mobile' : 'desktop';
    // Heatmap points belong to the page that was on screen during this batch.
    $clickPage = orbitraReplayPagePath((string) ($href ?? $row['page_url'] ?? '/'));
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE session_replays
                SET status = 'recording', file = ?, bytes = ?, chunks = chunks + 1, events = events + ?,
                    first_ts = ?, last_ts = ?, duration_ms = ?, clicks = clicks + ?, rage_clicks = rage_clicks + ?,
                    viewport_w = COALESCE(?, viewport_w), viewport_h = COALESCE(?, viewport_h),
                    page_url = COALESCE(?, page_url), entry_url = COALESCE(entry_url, ?), pages = ?,
                    updated_at = ?
              WHERE click_id = ?"
        )->execute([
            $file, $newBytes, count($events), $firstTs, $lastTs, $duration, count($clickRows), $rage,
            $vw, $vh, $href, $entryHref, max(1, $newPages), $now, $clickId,
        ]);
        // landing_id stays 0 for campaign-only (organic) recordings; the
        // heatmap then aggregates by campaign_id instead.
        if ($clickRows && ((int) $row['landing_id'] > 0 || (int) ($row['campaign_id'] ?? 0) > 0)) {
            $ins = $pdo->prepare(
                "INSERT INTO replay_clicks (landing_id, campaign_id, click_id, device, x, y, doc_w, doc_h, rage, sel, page, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($clickRows as $cr) {
                $ins->execute([(int) $row['landing_id'], (int) ($row['campaign_id'] ?? 0) ?: null, $clickId, $device,
                    $cr[0], $cr[1], $cr[2], $cr[3], $cr[4], $cr[5], $cr[6] !== null ? $cr[6] : $clickPage, $now]);
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        orbitraReplayRespond(503);
        return ['ok' => false, 'reason' => 'db'];
    }

    orbitraReplayMaybeGc($pdo);
    orbitraReplayRespond(204);
    return ['ok' => true, 'events' => count($events), 'clicks' => count($clickRows), 'bytes' => $newBytes];
}

// ---------------------------------------------------------------------------
// Housekeeping
// ---------------------------------------------------------------------------

/** GC at most once a minute, driven by ingest traffic (and by panel reads). */
function orbitraReplayMaybeGc(PDO $pdo, bool $force = false): ?array
{
    $dir = orbitraReplayDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $stamp = $dir . '/.gc';
    $last = @filemtime($stamp);
    if (!$force && $last !== false && (time() - $last) < 60) {
        return null;
    }
    @touch($stamp);
    try {
        return orbitraReplayGc($pdo);
    } catch (\Throwable $e) {
        return null;
    }
}

function orbitraReplayDeleteRows(PDO $pdo, array $rows): int
{
    $n = 0;
    $base = orbitraReplayDir();
    $del = $pdo->prepare("DELETE FROM session_replays WHERE click_id = ?");
    foreach ($rows as $r) {
        $file = (string) ($r['file'] ?? '');
        if ($file !== '' && strpos($file, '..') === false) {
            @unlink($base . '/' . $file);
        }
        $del->execute([(string) $r['click_id']]);
        $n++;
    }
    return $n;
}

/**
 * 1. recordings older than the retention window go;
 * 2. armed rows that never received data go after two hours;
 * 3. while the total is over the cap, the oldest recordings go;
 * 4. heatmap clicks older than 30 days (or the retention, if longer) go;
 * 5. empty day folders go.
 */
function orbitraReplayGc(PDO $pdo): array
{
    $cfg = orbitraReplayStorageConfig($pdo);
    $out = ['expired' => 0, 'empty' => 0, 'over_cap' => 0, 'clicks' => 0];

    $cutoff = gmdate('Y-m-d H:i:s', time() - $cfg['retention_days'] * 86400);
    while (true) {
        $stmt = $pdo->prepare("SELECT click_id, file FROM session_replays WHERE created_at < ? LIMIT 200");
        $stmt->execute([$cutoff]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }
        $out['expired'] += orbitraReplayDeleteRows($pdo, $rows);
    }

    $stale = gmdate('Y-m-d H:i:s', time() - 7200);
    $out['empty'] = (int) $pdo->exec("DELETE FROM session_replays WHERE chunks = 0 AND created_at < " . $pdo->quote($stale));

    $maxBytes = $cfg['max_mb'] * 1024 * 1024;
    $total = (int) $pdo->query("SELECT COALESCE(SUM(bytes), 0) FROM session_replays")->fetchColumn();
    $guard = 0;
    while ($total > $maxBytes && $guard++ < 1000) {
        $rows = $pdo->query("SELECT click_id, file, bytes FROM session_replays WHERE bytes > 0 ORDER BY created_at ASC, click_id ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }
        $batch = [];
        foreach ($rows as $r) {
            $batch[] = $r;
            $total -= (int) $r['bytes'];
            if ($total <= $maxBytes) {
                break;
            }
        }
        $out['over_cap'] += orbitraReplayDeleteRows($pdo, $batch);
    }

    $clickDays = max(ORBITRA_REPLAY_CLICKS_KEEP_DAYS, $cfg['retention_days']);
    $out['clicks'] = (int) $pdo->exec("DELETE FROM replay_clicks WHERE created_at < "
        . $pdo->quote(gmdate('Y-m-d H:i:s', time() - $clickDays * 86400)));

    $base = orbitraReplayDir();
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (preg_match('#/\d{4}-\d{2}-\d{2}$#', $d) && count(scandir($d) ?: []) <= 2) {
            @rmdir($d);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Panel API
// ---------------------------------------------------------------------------

function orbitraReplayJson(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function orbitraReplayStorageSummary(PDO $pdo): array
{
    $cfg = orbitraReplayStorageConfig($pdo);
    $row = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM session_replays WHERE chunks > 0")->fetch(PDO::FETCH_ASSOC);
    $n = (int) ($row['n'] ?? 0);
    $b = (int) ($row['b'] ?? 0);
    $avg = $n >= 3 ? (int) round($b / $n) : ORBITRA_REPLAY_ESTIMATE_BYTES;
    $dir = orbitraReplayDir();
    $probe = is_dir($dir) ? $dir : dirname($dir);
    $free = @disk_free_space($probe);
    $totalDisk = @disk_total_space($probe);
    $maxBytes = $cfg['max_mb'] * 1024 * 1024;
    return [
        'retention_days' => $cfg['retention_days'],
        'max_mb' => $cfg['max_mb'],
        'used_bytes' => $b,
        'count' => $n,
        'avg_bytes' => $avg,
        'avg_is_estimate' => $n < 3,
        'fits_estimate' => $avg > 0 ? (int) floor($maxBytes / $avg) : null,
        'disk_free_bytes' => $free === false ? null : (int) $free,
        'disk_total_bytes' => $totalDisk === false ? null : (int) $totalDisk,
        'disk_low' => $free !== false && $free < ORBITRA_REPLAY_MIN_FREE_BYTES,
        'min_free_bytes' => ORBITRA_REPLAY_MIN_FREE_BYTES,
        'max_recording_seconds' => ORBITRA_REPLAY_MAX_SECONDS,
    ];
}

/** Scope helper: non-admin users only see recordings of campaigns they may see. */
function orbitraReplayScopeSql(PDO $pdo, string $column): string
{
    if (!function_exists('orbitraCampaignScope') || !function_exists('orbitraCampaignScopeInSql')) {
        return '';
    }
    $sql = orbitraCampaignScopeInSql(orbitraCampaignScope($pdo), $column);
    return $sql === '' ? '' : ' AND ' . $sql;
}

/**
 * FROM … WHERE for the recordings list and for "delete everything matching
 * these filters" — one builder, so the bulk delete removes exactly the rows
 * the operator is looking at. Returns [sql, args].
 */
function orbitraReplayFilterSql(PDO $pdo, array $q): array
{
    $where = ['r.chunks > 0'];
    $args = [];
    if (!empty($q['landing_id'])) {
        $where[] = 'r.landing_id = ?';
        $args[] = (int) $q['landing_id'];
    }
    if (!empty($q['campaign_id'])) {
        $where[] = 'r.campaign_id = ?';
        $args[] = (int) $q['campaign_id'];
    }
    if (!empty($q['click_id'])) {
        $where[] = 'r.click_id = ?';
        $args[] = (string) $q['click_id'];
    }
    if (!empty($q['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $q['date_from'])) {
        $where[] = 'r.created_at >= ?';
        $args[] = substr((string) $q['date_from'], 0, 19);
    }
    if (!empty($q['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $q['date_to'])) {
        $to = (string) $q['date_to'];
        $where[] = 'r.created_at <= ?';
        $args[] = strlen($to) === 10 ? $to . ' 23:59:59' : substr($to, 0, 19);
    }
    $device = (string) ($q['device'] ?? '');
    if ($device === 'mobile') {
        $where[] = 'r.viewport_w > 0 AND r.viewport_w < ' . ORBITRA_REPLAY_MOBILE_MAX_WIDTH;
    } elseif ($device === 'desktop') {
        $where[] = 'r.viewport_w >= ' . ORBITRA_REPLAY_MOBILE_MAX_WIDTH;
    }
    if (!empty($q['converted'])) {
        $where[] = 'COALESCE(c.is_conversion, 0) > 0';
    }
    if (!empty($q['offer'])) {
        $where[] = 'c.offer_at IS NOT NULL';
    }
    if (!empty($q['rage'])) {
        $where[] = 'r.rage_clicks > 0';
    }
    if (!empty($q['min_seconds'])) {
        $where[] = 'r.duration_ms >= ?';
        $args[] = (int) $q['min_seconds'] * 1000;
    }
    $country = strtoupper(trim((string) ($q['country'] ?? '')));
    if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country)) {
        $where[] = 'UPPER(COALESCE(c.country_code, c.country)) = ?';
        $args[] = $country;
    }
    // Organic filters. Page matching is substring over the full URLs recorded
    // in meta events: entry_url = the first page, page_url = the last one.
    // Pages in the middle of a session live only inside the recording file —
    // list filtering stays an index-friendly column scan.
    if (!empty($q['entry_page'])) {
        $where[] = 'r.entry_url LIKE ?';
        $args[] = '%' . substr((string) $q['entry_page'], 0, 300) . '%';
    }
    if (!empty($q['page'])) {
        $like = '%' . substr((string) $q['page'], 0, 300) . '%';
        $where[] = '(r.entry_url LIKE ? OR r.page_url LIKE ?)';
        array_push($args, $like, $like);
    }
    if (!empty($q['pages_min'])) {
        $where[] = 'r.pages >= ?';
        $args[] = max(1, (int) $q['pages_min']);
    }
    // Traffic source, derived from the se_referrer parameter the clients send.
    // Direct = no referrer captured at all.
    $source = strtolower(trim((string) ($q['source'] ?? '')));
    if ($source !== '') {
        if ($source === 'direct') {
            $where[] = '(c.parameters_json IS NULL OR c.parameters_json NOT LIKE \'%"se_referrer"%\')';
        } elseif (isset(ORBITRA_REPLAY_SOURCE_LIKES[$source])) {
            $ors = [];
            foreach (ORBITRA_REPLAY_SOURCE_LIKES[$source] as $like) {
                $ors[] = 'c.parameters_json LIKE ?';
                $args[] = $like;
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }
    }
    $sql = 'FROM session_replays r
            LEFT JOIN clicks c ON c.id = r.click_id
            LEFT JOIN landings l ON l.id = r.landing_id
            LEFT JOIN campaigns cp ON cp.id = r.campaign_id
            WHERE ' . implode(' AND ', $where) . orbitraReplayScopeSql($pdo, 'r.campaign_id');
    return [$sql, $args];
}

/** Sortable columns of the list → SQL expression (whitelist). */
function orbitraReplaySortSql(array $q): string
{
    $map = [
        'created_at' => 'r.created_at',
        'duration' => 'r.duration_ms',
        'clicks' => 'r.clicks',
        'rage' => 'r.rage_clicks',
        'scroll' => 'COALESCE(c.lp_scroll, -1)',
        'size' => 'r.bytes',
        'landing' => "COALESCE(l.name, '')",
        'country' => "COALESCE(c.country_code, c.country, '')",
        'result' => "(CASE WHEN COALESCE(c.is_conversion, 0) > 0 THEN 2 WHEN c.offer_at IS NOT NULL THEN 1 ELSE 0 END)",
    ];
    $key = (string) ($q['sort'] ?? 'created_at');
    $col = $map[$key] ?? $map['created_at'];
    $dir = strtolower((string) ($q['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    return $col . ' ' . $dir . ', r.created_at DESC, r.click_id DESC';
}

function orbitraReplayList(PDO $pdo, array $q): array
{
    [$sql, $args] = orbitraReplayFilterSql($pdo, $q);

    $limit = max(1, min((int) ($q['limit'] ?? 50), 1000));
    $offset = max(0, (int) ($q['offset'] ?? 0));
    $stmt = $pdo->prepare('SELECT COUNT(*) ' . $sql);
    $stmt->execute($args);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT r.click_id, r.landing_id, r.campaign_id, r.status, r.bytes, r.events, r.duration_ms,
                r.clicks, r.rage_clicks, r.viewport_w, r.viewport_h, r.page_url, r.entry_url, r.pages,
                r.created_at, r.updated_at,
                l.name AS landing_name, cp.name AS campaign_name,
                c.country, c.country_code, c.city, c.device_type, c.os, c.browser, c.ip,
                c.is_conversion, c.revenue, c.lp_scroll, c.lp_seconds, c.offer_at
         ' . $sql . ' ORDER BY ' . orbitraReplaySortSql($q) . ' LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $vw = (int) ($r['viewport_w'] ?? 0);
        $r['device'] = ($vw > 0 && $vw < ORBITRA_REPLAY_MOBILE_MAX_WIDTH) ? 'mobile' : 'desktop';
        $r['went_to_offer'] = !empty($r['offer_at']);
        unset($r['offer_at']);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Landings worth offering in the recordings filter: every landing that has
 * recordings on disk or has recording switched on, with its live state.
 */
function orbitraReplayLandingFacets(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT l.id, l.name,
                COALESCE(s.enabled, 0) AS enabled, s.sample_pct, s.until_at,
                (SELECT COUNT(*) FROM session_replays r WHERE r.landing_id = l.id AND r.chunks > 0) AS recordings
           FROM landings l
           LEFT JOIN replay_landing_settings s ON s.landing_id = l.id
          WHERE COALESCE(s.enabled, 0) = 1
             OR EXISTS (SELECT 1 FROM session_replays r2 WHERE r2.landing_id = l.id AND r2.chunks > 0)
          ORDER BY l.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $cfg = orbitraReplayLandingConfig($pdo, (int) $r['id']);
        $r['id'] = (int) $r['id'];
        $r['recordings'] = (int) $r['recordings'];
        $r['enabled'] = $cfg['enabled'];
        $r['active'] = $cfg['active'];
        $r['sample_pct'] = $cfg['sample_pct'];
        $r['until_at'] = $cfg['until_at'];
    }
    unset($r);
    return $rows;
}

/**
 * Campaigns worth offering in the recordings filter: every campaign with the
 * recording switch on or with recordings on disk — the organic-site twin of
 * orbitraReplayLandingFacets().
 */
function orbitraReplayCampaignFacets(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT cp.id, cp.name,
                    COALESCE(s.enabled, 0) AS enabled, s.sample_pct, s.until_at, s.consent,
                    (SELECT COUNT(*) FROM session_replays r WHERE r.campaign_id = cp.id AND r.chunks > 0) AS recordings
               FROM campaigns cp
               LEFT JOIN replay_campaign_settings s ON s.campaign_id = cp.id
              WHERE COALESCE(s.enabled, 0) = 1
                 OR EXISTS (SELECT 1 FROM session_replays r2 WHERE r2.campaign_id = cp.id AND r2.chunks > 0)
              ORDER BY cp.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $cfg = orbitraReplayCampaignConfig($pdo, (int) $r['id']);
        $r['id'] = (int) $r['id'];
        $r['recordings'] = (int) $r['recordings'];
        $r['enabled'] = $cfg['enabled'];
        $r['active'] = $cfg['active'];
        $r['sample_pct'] = $cfg['sample_pct'];
        $r['until_at'] = $cfg['until_at'];
        $r['consent'] = $cfg['consent'];
    }
    unset($r);
    return $rows;
}

/** Stream the recording as {"status":"success","meta":{…},"events":[…]} without decoding it. */
function orbitraReplayStreamEvents(PDO $pdo, string $clickId): void
{
    if (!orbitraReplayValidClickId($clickId)) {
        orbitraReplayJson(['status' => 'error', 'message' => 'Bad id'], 400);
        return;
    }
    $stmt = $pdo->prepare('SELECT r.*, l.name AS landing_name, cp.name AS campaign_name,
                c.country, c.country_code, c.city, c.device_type, c.os, c.browser, c.ip,
                c.is_conversion, c.revenue, c.offer_at, c.lp_scroll
            FROM session_replays r
            LEFT JOIN clicks c ON c.id = r.click_id
            LEFT JOIN landings l ON l.id = r.landing_id
            LEFT JOIN campaigns cp ON cp.id = r.campaign_id
            WHERE r.click_id = ?' . orbitraReplayScopeSql($pdo, 'r.campaign_id') . ' LIMIT 1');
    $stmt->execute([$clickId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $path = $row && $row['file'] !== '' && strpos((string) $row['file'], '..') === false
        ? orbitraReplayDir() . '/' . $row['file'] : '';
    if (!$row || $path === '' || !is_file($path)) {
        orbitraReplayJson(['status' => 'error', 'message' => 'Recording not found'], 404);
        return;
    }
    $row['went_to_offer'] = !empty($row['offer_at']);
    unset($row['offer_at'], $row['file']);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, max-age=60');
    }
    echo '{"status":"success","meta":' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . ',"events":[';
    $gz = gzopen($path, 'rb');
    $first = true;
    if ($gz) {
        while (!gzeof($gz)) {
            $line = gzgets($gz, 64 * 1024 * 1024);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            // Each line is one batch: a JSON array. Splice its contents in.
            if (strlen($line) < 3 || $line[0] !== '[' || substr($line, -1) !== ']') {
                continue;
            }
            echo ($first ? '' : ','), substr($line, 1, -1);
            $first = false;
        }
        gzclose($gz);
    }
    echo ']}';
}

function orbitraReplayHeatmap(PDO $pdo, array $q): array
{
    $landingId = (int) ($q['landing_id'] ?? 0);
    $campaignId = (int) ($q['campaign_id'] ?? 0);
    $device = ($q['device'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop';
    $days = max(1, min((int) ($q['days'] ?? 7), 90));
    $page = isset($q['page']) && $q['page'] !== '' ? orbitraReplayPagePath((string) $q['page']) : '';
    $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    // Scope: one landing, or a whole campaign (organic sites). Heatmap points
    // recorded before schema 78 carry no campaign_id — the campaign view fills
    // in as new traffic arrives.
    if ($landingId > 0) {
        $pointsWhere = 'landing_id = ?';
        $pointsArg = $landingId;
        $replaysWhere = 'landing_id = ?';
        $replaysArg = $landingId;
        $scrollWhere = 'landing_id = ?';
        $scrollArg = $landingId;
    } else {
        $pointsWhere = 'campaign_id = ?';
        $pointsArg = $campaignId;
        $replaysWhere = 'campaign_id = ?';
        $replaysArg = $campaignId;
        $scrollWhere = 'campaign_id = ?';
        $scrollArg = $campaignId;
    }

    $pageCond = '';
    $pageArgs = [];
    if ($page !== '') {
        $pageCond = ' AND page = ?';
        $pageArgs = [$page];
    }

    $stmt = $pdo->prepare("SELECT x, y, doc_w, doc_h, rage FROM replay_clicks
                           WHERE $pointsWhere AND device = ? AND created_at >= ?$pageCond
                           ORDER BY id DESC LIMIT 20000");
    $stmt->execute(array_merge([$pointsArg, $device, $since], $pageArgs));
    $points = [];
    $widths = [];
    $heights = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $points[] = [round((float) $p['x'], 4), (int) $p['y'], (int) $p['rage']];
        if ((int) $p['doc_w'] > 0) {
            $widths[] = (int) $p['doc_w'];
        }
        if ((int) $p['doc_h'] > 0) {
            $heights[] = (int) $p['doc_h'];
        }
    }
    sort($widths);
    sort($heights);
    $median = function (array $a) {
        return $a ? $a[(int) floor(count($a) / 2)] : null;
    };

    // Background: the latest recording of that device class still on disk —
    // for a page-filtered map, one that actually contains that page.
    $mobileCond = $device === 'mobile'
        ? 'viewport_w > 0 AND viewport_w < ' . ORBITRA_REPLAY_MOBILE_MAX_WIDTH
        : 'viewport_w >= ' . ORBITRA_REPLAY_MOBILE_MAX_WIDTH;
    $bgPageCond = '';
    $bgArgs = [$replaysArg];
    if ($page !== '') {
        // Entry match first (its first frame IS the page); fall back to any
        // recording whose last page matches (the stream still contains it).
        $bgPageCond = " AND (entry_url LIKE ? OR page_url LIKE ?)";
        $like = '%' . $page . '%';
        array_push($bgArgs, $like, $like);
    }
    $bg = $pdo->prepare("SELECT click_id, viewport_w, viewport_h FROM session_replays
                         WHERE $replaysWhere AND chunks > 0 AND $mobileCond$bgPageCond
                         ORDER BY (entry_url LIKE ?) DESC, created_at DESC LIMIT 1");
    $bg->execute(array_merge($bgArgs, [$page !== '' ? '%' . $page . '%' : '%']));
    $bgRow = $bg->fetch(PDO::FETCH_ASSOC) ?: null;

    $sessions = $pdo->prepare("SELECT COUNT(*) FROM session_replays WHERE $replaysWhere AND chunks > 0 AND created_at >= ? AND $mobileCond");
    $sessions->execute([$replaysArg, $since]);

    // Page list for the selector: the paths that actually collected clicks.
    $pagesRows = $pdo->prepare("SELECT page, COUNT(*) AS n FROM replay_clicks
                                WHERE $pointsWhere AND created_at >= ? AND page IS NOT NULL
                                GROUP BY page ORDER BY n DESC LIMIT 200");
    $pagesRows->execute([$pointsArg, $since]);
    $pagesList = [];
    foreach ($pagesRows->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $pagesList[] = ['page' => (string) $pr['page'], 'clicks' => (int) $pr['n']];
    }

    // Scroll map from the dwell timer — every measured visit of the landing,
    // recorded or not. Device split by clicks.device_type.
    $devSql = $device === 'mobile'
        ? "LOWER(COALESCE(device_type, '')) IN ('mobile', 'smartphone', 'tablet', 'phablet')"
        : "LOWER(COALESCE(device_type, '')) NOT IN ('mobile', 'smartphone', 'tablet', 'phablet')";
    $sc = $pdo->prepare("SELECT lp_scroll FROM clicks
                         WHERE $scrollWhere AND lp_scroll IS NOT NULL AND created_at >= ?
                           AND COALESCE(is_bot, 0) = 0 AND COALESCE(is_safe_page, 0) = 0 AND $devSql");
    $sc->execute([$scrollArg, $since]);
    $depths = array_map('intval', $sc->fetchAll(PDO::FETCH_COLUMN));
    $measured = count($depths);
    $reach = [];
    for ($p = 0; $p <= 100; $p += 10) {
        $hit = 0;
        foreach ($depths as $d) {
            if ($d >= $p) {
                $hit++;
            }
        }
        $reach[] = ['pct' => $p, 'share' => $measured ? round($hit / $measured, 4) : null];
    }

    return [
        'landing_id' => $landingId,
        'campaign_id' => $landingId > 0 ? null : $campaignId,
        'device' => $device,
        'days' => $days,
        'page' => $page !== '' ? $page : null,
        'pages' => $pagesList,
        'points' => $points,
        'total_clicks' => count($points),
        'sessions' => (int) $sessions->fetchColumn(),
        'doc_w' => $median($widths),
        'doc_h' => $median($heights),
        'background' => $bgRow,
        'scroll' => ['measured' => $measured, 'reach' => $reach],
    ];
}

/** Every replay_* action of api.php. */
function orbitraReplayApi(PDO $pdo, string $action): void
{
    try {
        orbitraReplayEnsureSchema($pdo);
    } catch (\Throwable $e) {
        // Read-only DB: reads below still work if the tables exist.
    }
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $body = [];
    if ($isPost) {
        $raw = function_exists('orbitraRequestBody') ? orbitraRequestBody() : (string) file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }
    }

    try {
        switch ($action) {
            case 'replays':
                orbitraReplayMaybeGc($pdo);
                $list = orbitraReplayList($pdo, $_GET);
                orbitraReplayJson(['status' => 'success', 'data' => $list['rows'], 'total' => $list['total'],
                    'limit' => $list['limit'], 'offset' => $list['offset'], 'storage' => orbitraReplayStorageSummary($pdo),
                    'landings' => orbitraReplayLandingFacets($pdo),
                    'campaigns' => orbitraReplayCampaignFacets($pdo)]);
                return;

            case 'replay_events':
                orbitraReplayStreamEvents($pdo, trim((string) ($_GET['id'] ?? '')));
                return;

            case 'replay_heatmap':
                orbitraReplayJson(['status' => 'success', 'data' => orbitraReplayHeatmap($pdo, $_GET)]);
                return;

            case 'replay_storage':
                orbitraReplayJson(['status' => 'success', 'data' => orbitraReplayStorageSummary($pdo)]);
                return;

            case 'replay_storage_save':
                if (!$isPost) {
                    orbitraReplayJson(['status' => 'error', 'message' => 'POST required'], 405);
                    return;
                }
                $days = max(1, min((int) ($body['retention_days'] ?? ORBITRA_REPLAY_DEFAULT_RETENTION_DAYS), 90));
                $mb = max(50, min((int) ($body['max_mb'] ?? ORBITRA_REPLAY_DEFAULT_MAX_MB), 512000));
                $up = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                $up->execute(['replay_retention_days', (string) $days]);
                $up->execute(['replay_max_mb', (string) $mb]);
                orbitraReplayMaybeGc($pdo, true);
                orbitraReplayJson(['status' => 'success', 'data' => orbitraReplayStorageSummary($pdo)]);
                return;

            case 'replay_landing_config':
                $lid = (int) ($_GET['landing_id'] ?? 0);
                $cfg = orbitraReplayLandingConfig($pdo, $lid);
                $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM session_replays WHERE landing_id = ? AND chunks > 0");
                $st->execute([$lid]);
                $stats = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'b' => 0];
                // Real traffic of the last 24 h, for the "≈ N MB a day" estimate.
                $visits = 0;
                try {
                    $vs = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE landing_id = ? AND created_at >= ? AND COALESCE(is_bot, 0) = 0");
                    $vs->execute([$lid, gmdate('Y-m-d H:i:s', time() - 86400)]);
                    $visits = (int) $vs->fetchColumn();
                } catch (\Throwable $e) {
                }
                orbitraReplayJson(['status' => 'success', 'data' => [
                    'config' => $cfg,
                    'visits_24h' => $visits,
                    'recordings' => (int) $stats['n'],
                    'bytes' => (int) $stats['b'],
                    'storage' => orbitraReplayStorageSummary($pdo),
                ]]);
                return;

            case 'replay_landing_config_save':
                if (!$isPost) {
                    orbitraReplayJson(['status' => 'error', 'message' => 'POST required'], 405);
                    return;
                }
                $lid = (int) ($body['landing_id'] ?? 0);
                if ($lid <= 0) {
                    orbitraReplayJson(['status' => 'error', 'message' => 'landing_id required'], 400);
                    return;
                }
                $enabled = !empty($body['enabled']) ? 1 : 0;
                $pct = max(1, min((int) ($body['sample_pct'] ?? ORBITRA_REPLAY_DEFAULT_SAMPLE_PCT), 100));
                // Recording window: hours from now (0 = no limit), or keep the
                // stored end when 'keep_until' is sent with the same config.
                $until = null;
                if (!empty($body['keep_until'])) {
                    $cur = orbitraReplayLandingConfig($pdo, $lid);
                    $until = $cur['until_at'];
                } elseif (!empty($body['duration_hours'])) {
                    $hours = max(1, min((int) $body['duration_hours'], 24 * 90));
                    $until = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
                }
                $pdo->prepare(
                    "INSERT INTO replay_landing_settings (landing_id, enabled, sample_pct, until_at, updated_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT(landing_id) DO UPDATE SET enabled = excluded.enabled, sample_pct = excluded.sample_pct,
                        until_at = excluded.until_at, updated_at = excluded.updated_at"
                )->execute([$lid, $enabled, $pct, $until, gmdate('Y-m-d H:i:s')]);
                orbitraReplayJson(['status' => 'success', 'data' => ['config' => orbitraReplayLandingConfig($pdo, $lid)]]);
                return;

            case 'replay_campaign_config':
                $cpid = (int) ($_GET['campaign_id'] ?? 0);
                $cfg = orbitraReplayCampaignConfig($pdo, $cpid);
                $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM session_replays WHERE campaign_id = ? AND chunks > 0");
                $st->execute([$cpid]);
                $stats = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'b' => 0];
                // Real traffic of the last 24 h, for the "≈ N MB a day" estimate.
                $visits = 0;
                try {
                    $vs = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE campaign_id = ? AND created_at >= ? AND COALESCE(is_bot, 0) = 0");
                    $vs->execute([$cpid, gmdate('Y-m-d H:i:s', time() - 86400)]);
                    $visits = (int) $vs->fetchColumn();
                } catch (\Throwable $e) {
                }
                orbitraReplayJson(['status' => 'success', 'data' => [
                    'config' => $cfg,
                    'visits_24h' => $visits,
                    'recordings' => (int) $stats['n'],
                    'bytes' => (int) $stats['b'],
                    'storage' => orbitraReplayStorageSummary($pdo),
                ]]);
                return;

            case 'replay_campaign_config_save':
                if (!$isPost) {
                    orbitraReplayJson(['status' => 'error', 'message' => 'POST required'], 405);
                    return;
                }
                $cpid = (int) ($body['campaign_id'] ?? 0);
                if ($cpid <= 0) {
                    orbitraReplayJson(['status' => 'error', 'message' => 'campaign_id required'], 400);
                    return;
                }
                $enabled = !empty($body['enabled']) ? 1 : 0;
                $pct = max(1, min((int) ($body['sample_pct'] ?? ORBITRA_REPLAY_DEFAULT_SAMPLE_PCT), 100));
                $consent = !empty($body['consent']) ? 1 : 0;
                $sessionContinue = !empty($body['session_continue']) ? 1 : 0;
                $until = null;
                if (!empty($body['keep_until'])) {
                    $cur = orbitraReplayCampaignConfig($pdo, $cpid);
                    $until = $cur['until_at'];
                } elseif (!empty($body['duration_hours'])) {
                    $hours = max(1, min((int) $body['duration_hours'], 24 * 90));
                    $until = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
                }
                $pdo->prepare(
                    "INSERT INTO replay_campaign_settings (campaign_id, enabled, sample_pct, until_at, consent, session_continue, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT(campaign_id) DO UPDATE SET enabled = excluded.enabled, sample_pct = excluded.sample_pct,
                        until_at = excluded.until_at, consent = excluded.consent,
                        session_continue = excluded.session_continue, updated_at = excluded.updated_at"
                )->execute([$cpid, $enabled, $pct, $until, $consent, $sessionContinue, gmdate('Y-m-d H:i:s')]);
                orbitraReplayJson(['status' => 'success', 'data' => ['config' => orbitraReplayCampaignConfig($pdo, $cpid)]]);
                return;

            case 'replay_delete':
                if (!$isPost) {
                    orbitraReplayJson(['status' => 'error', 'message' => 'POST required'], 405);
                    return;
                }
                $scope = orbitraReplayScopeSql($pdo, 'campaign_id');
                $rows = [];
                if (!empty($body['ids']) && is_array($body['ids'])) {
                    $ids = array_values(array_filter(array_map('strval', $body['ids']), 'orbitraReplayValidClickId'));
                    foreach (array_chunk($ids, 200) as $chunk) {
                        $st = $pdo->prepare('SELECT click_id, file FROM session_replays WHERE click_id IN ('
                            . implode(',', array_fill(0, count($chunk), '?')) . ')' . $scope);
                        $st->execute($chunk);
                        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
                    }
                } elseif (!empty($body['landing_id'])) {
                    $st = $pdo->prepare('SELECT click_id, file FROM session_replays WHERE landing_id = ?' . $scope);
                    $st->execute([(int) $body['landing_id']]);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    $pdo->prepare('DELETE FROM replay_clicks WHERE landing_id = ?')->execute([(int) $body['landing_id']]);
                } elseif (!empty($body['campaign_id'])) {
                    $st = $pdo->prepare('SELECT click_id, file FROM session_replays WHERE campaign_id = ?' . $scope);
                    $st->execute([(int) $body['campaign_id']]);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    $pdo->prepare('DELETE FROM replay_clicks WHERE campaign_id = ?')->execute([(int) $body['campaign_id']]);
                } elseif (!empty($body['filter']) && is_array($body['filter'])) {
                    // "Select all N matching" → the same WHERE the list used.
                    [$fsql, $fargs] = orbitraReplayFilterSql($pdo, $body['filter']);
                    $st = $pdo->prepare('SELECT r.click_id, r.file ' . $fsql);
                    $st->execute($fargs);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                } elseif (!empty($body['all'])) {
                    $rows = $pdo->query('SELECT click_id, file FROM session_replays WHERE 1 = 1' . $scope)->fetchAll(PDO::FETCH_ASSOC);
                    if ($scope === '') {
                        $pdo->exec('DELETE FROM replay_clicks');
                    }
                }
                $n = orbitraReplayDeleteRows($pdo, $rows);
                orbitraReplayJson(['status' => 'success', 'data' => ['deleted' => $n, 'storage' => orbitraReplayStorageSummary($pdo)]]);
                return;
        }
        orbitraReplayJson(['status' => 'error', 'message' => 'Unknown action'], 400);
    } catch (\Throwable $e) {
        error_log('replay api ' . $action . ' failed: ' . $e->getMessage());
        orbitraReplayJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
