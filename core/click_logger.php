<?php
// core/click_logger.php
//
// Shared click-logging module used by all three entry points:
// - index.php (main click handler)
// - click.php (lightweight click handler for integrations)
// - core/click_api.php (Keitaro-compatible Click API)
//
// Extracts the duplicated INSERT logic into one place to prevent drift.
// The three paths have differences in prefetch and debounce handling - those
// differences are preserved by the parameterised functions below.

require_once __DIR__ . '/CloakDetector.php';
require_once __DIR__ . '/Device.php';

if (!function_exists('logCloakEvent')) {
    /**
     * Post-mortem cloak event line in the PHP error log. Kept next to the
     * shared click logger so core/click_api.php (which never passes through
     * index.php's definitions on its router entry) can call it too.
     */
    function logCloakEvent($stage, $campaignId, $streamId, array $visitor, array $reasons, $sensitivity)
    {
        $clean = static function ($value) {
            return preg_replace('/[\r\n]+/', ' ', (string) $value);
        };
        error_log(sprintf(
            'Orbitra cloak [campaign=%s stream=%s]: stage=%s ip=%s asn=%s isp=%s proxy=%s provider=%s ua=%.80s reasons=[%s] sensitivity=%s',
            $clean($campaignId),
            $clean($streamId),
            $clean($stage),
            $clean($visitor['ip'] ?? ''),
            $clean($visitor['asn'] ?? ''),
            $clean($visitor['isp'] ?? ''),
            $clean($visitor['proxy_type'] ?? ''),
            $clean($visitor['proxy_provider'] ?? ''),
            $clean($visitor['user_agent'] ?? ''),
            implode(', ', array_map($clean, $reasons)),
            $clean($sensitivity)
        ));
    }
}

/**
 * Build the complete click row array for INSERT.
 *
 * Single source of truth for the clicks table schema. Any new column
 * must be added here to be persisted by all entry points.
 *
 * @param array $ctx Context containing:
 *   - click_id: string UUID
 *   - campaign_id: int
 *   - offer_id: int|null (0 becomes null for foreign key safety)
 *   - stream_id: int|null
 *   - source_id: int|null
 *   - landing_id: int|null
 *   - ip: string
 *   - user_agent: string
 *   - referer: string
 *   - country: string (full country name)
 *   - country_code: string (ISO code)
 *   - region: string
 *   - city: string
 *   - latitude: float|null
 *   - longitude: float|null
 *   - zipcode: string
 *   - timezone: string
 *   - device_type: string
 *   - os: string
 *   - browser: string
 *   - language: string
 *   - accept_language_raw: string
 *   - parameters_json: string
 *   - cloak_verdict: string|null (W1: 'money', 'passive_safe', 'targeting_safe', 'js_safe')
 *   - cloak_reasons: string|null (W1: comma-separated reason codes)
 *   - is_safe_page: int (W1: 1 if safe page, 0 otherwise)
 *   - isp: string|null (W1: ISP from geo lookup)
 *   - asn: string|null (W1: ASN with 'AS' prefix)
 *   - proxy_type: string|null (W1: IP2Proxy proxy type)
 *   - cloak_sensitivity: string|null (W1: sensitivity level)
 * @return array Column => value map for INSERT
 */
function orbitraBuildClickRow(array $ctx): array
{
    // Foreign key safety: offer_id must be NULL, not 0, for the foreign key
    $offerId = isset($ctx['offer_id']) && $ctx['offer_id'] > 0 ? (int) $ctx['offer_id'] : null;

    $row = [
        'id' => (string) ($ctx['click_id'] ?? ''),
        'campaign_id' => (int) ($ctx['campaign_id'] ?? 0),
        'offer_id' => $offerId,
        'stream_id' => isset($ctx['stream_id']) ? (int) $ctx['stream_id'] : null,
        'source_id' => isset($ctx['source_id']) ? (int) $ctx['source_id'] : null,
        'landing_id' => isset($ctx['landing_id']) ? (int) $ctx['landing_id'] : null,
        'ip' => (string) ($ctx['ip'] ?? ''),
        'user_agent' => (string) ($ctx['user_agent'] ?? ''),
        'referer' => (string) ($ctx['referer'] ?? ''),
        'country' => (string) ($ctx['country'] ?? ''),
        'country_code' => (string) ($ctx['country_code'] ?? ''),
        'region' => (string) ($ctx['region'] ?? ''),
        'city' => (string) ($ctx['city'] ?? ''),
        'latitude' => isset($ctx['latitude']) ? (float) $ctx['latitude'] : null,
        'longitude' => isset($ctx['longitude']) ? (float) $ctx['longitude'] : null,
        'zipcode' => (string) ($ctx['zipcode'] ?? ''),
        'timezone' => (string) ($ctx['timezone'] ?? ''),
        'device_type' => (string) ($ctx['device_type'] ?? ''),
        'os' => (string) ($ctx['os'] ?? ''),
        'browser' => (string) ($ctx['browser'] ?? ''),
        'language' => (string) ($ctx['language'] ?? ''),
        'accept_language_raw' => (string) ($ctx['accept_language_raw'] ?? ''),
        'parameters_json' => (string) ($ctx['parameters_json'] ?? ''),
        // W1: Cloak observability columns
        'cloak_verdict' => isset($ctx['cloak_verdict']) ? (string) $ctx['cloak_verdict'] : null,
        'cloak_reasons' => isset($ctx['cloak_reasons']) ? (string) $ctx['cloak_reasons'] : null,
        'is_safe_page' => isset($ctx['is_safe_page']) ? (int) $ctx['is_safe_page'] : 0,
        'isp' => isset($ctx['isp']) ? (string) $ctx['isp'] : null,
        'asn' => isset($ctx['asn']) ? (string) $ctx['asn'] : null,
        'proxy_type' => isset($ctx['proxy_type']) ? (string) $ctx['proxy_type'] : null,
        'cloak_sensitivity' => isset($ctx['cloak_sensitivity']) ? (string) $ctx['cloak_sensitivity'] : null,
    ];
    // Sent to a non-catalog URL (stream Direct URL): counts as an offer click
    // in the reports (migration 53). Only written when set, so writers and
    // tests that predate the column keep inserting unchanged rows.
    if (!empty($ctx['direct_offer']) && $offerId === null) {
        $row['direct_offer'] = 1;
    }
    return $row;
}

/**
 * Whether a failure looks like a transient SQLite lock (SQLITE_BUSY /
 * SQLITE_LOCKED, surfaced as "database is locked" / "database table is
 * locked") rather than a permanent data error. Only these are retried:
 * busy_timeout already queues the writer for 5s (web) / 30s (cli) before
 * giving up, so a retry pays off only when the holder released the lock
 * right around the timeout — a constraint violation would fail identically
 * every time.
 */
function orbitraIsSqliteLockError(\Throwable $e): bool
{
    $msg = (string) $e->getMessage();
    return stripos($msg, 'database is locked') !== false
        || stripos($msg, 'database table is locked') !== false;
}

/**
 * Append a click row to the on-disk spool (var/spool/clicks.log).
 *
 * Last-resort sink for clicks that could not be inserted even after the lock
 * retries: the row is serialized as one JSON object per line and replayed by
 * cli/click_spool_cron.php. The spool lives under var/ (not web-readable per
 * the nginx rules) and the .log suffix matches the project's file convention.
 *
 * @param array $row Click row from orbitraBuildClickRow()
 * @return bool True if the line was written
 */
function orbitraSpoolClick(array $row): bool
{
    $dir = __DIR__ . '/../var/spool';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $line = json_encode($row, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return false;
    }
    $path = $dir . '/clicks.log';
    // The replay cron renames the spool away before it touches the database,
    // so the lock here is only ever held for a rename, never for a replay. A
    // writer that opened the file just before that rename would append to the
    // batch already taken — after locking, confirm the handle still is the
    // live spool (same inode as the path) and reopen otherwise.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $fp = @fopen($path, 'ab');
        if (!$fp) {
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        clearstatcache(true, $path);
        $live = @stat($path);
        $mine = fstat($fp);
        if ($live !== false && $mine !== false && $live['ino'] === $mine['ino']) {
            $ok = fwrite($fp, $line . "\n") !== false;
            flock($fp, LOCK_UN);
            fclose($fp);
            return $ok;
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return false;
}

/**
 * Persist a click row to the database.
 *
 * Wraps the INSERT in error handling so click logging failures never
 * break the redirect/landing response. If the row cannot be written (most
 * often "database is locked" after the busy_timeout ran out) it goes to the
 * file spool, and cli/click_spool_cron.php lands it within a minute. There is
 * no in-request retry: the INSERT already waited out busy_timeout, and every
 * retry would add that wait to the visitor's redirect again.
 *
 * @param PDO $pdo Database connection
 * @param array $row Click row from orbitraBuildClickRow()
 * @return bool True if INSERT succeeded, false otherwise (row spooled or lost)
 */
function orbitraPersistClick(PDO $pdo, array $row): bool
{
    $columns = array_keys($row);
    $placeholders = array_fill(0, count($columns), '?');
    $sql = "
        INSERT INTO clicks (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ";

    try {
        $insertStmt = $pdo->prepare($sql);
        $insertStmt->execute(array_values($row));
        return true;
    } catch (\Throwable $e) {
        // Never let click logging break the redirect/landing. Log and
        // continue, but keep the row on disk so the spool cron can still
        // land it in the database later.
        error_log('Orbitra click logging failed: ' . $e->getMessage());
        if (orbitraSpoolClick($row)) {
            error_log('Orbitra click spooled for retry: id=' . (string) ($row['id'] ?? ''));
        }
        return false;
    }
}

/**
 * Compute the cloak routing decision.
 *
 * Centralises the verdict computation that was duplicated across
 * index.php, click.php, and core/click_api.php. Returns all the
 * information needed for logging and routing.
 *
 * @param array $customSchema Stream's schema_custom_json (cloak config)
 * @param array $visitorCtx Visitor context: ip, user_agent, asn, isp, proxy_type, etc.
 * @param array $settings Global settings (for bot_isp_list, sensitivity defaults)
 * @param string $countryCode Visitor's country code (e.g., 'IN', 'US', 'Unknown')
 * @param string $deviceType Visitor's device type (mobile, tablet, desktop)
 * @param string $jsFailure Value of _ocjf query param ('' or 'webdriver')
 * @return array Array with keys:
 *   - show_safe: bool - should visitor see safe page?
 *   - verdict: string - 'money', 'passive_safe', 'targeting_safe', 'js_safe', or null
 *   - reasons: string[] - reason codes that led to the verdict
 *   - skip_click_log: bool - should this click be suppressed?
 *   - geo_ready: bool - was geo data available for targeting?
 *   - sensitivity: string - sensitivity level used for decision
 */
function orbitraCloakDecision(
    array $customSchema,
    array $visitorCtx,
    array $settings,
    string $countryCode,
    string $deviceType,
    string $jsFailure = ''
): array {
    // Extract cloak config with defaults
    $cloakConfig = [
        'detect_datacenter' => $customSchema['detect_datacenter'] ?? true,
        'detect_vpn' => $customSchema['detect_vpn'] ?? true,
        'detect_bots' => $customSchema['detect_bots'] ?? true,
        'detect_ua' => $customSchema['detect_ua'] ?? true,
        'sensitivity' => $customSchema['sensitivity'] ?? 'medium',
    ];

    $cloakVisitor = [
        'ip' => (string) ($visitorCtx['ip'] ?? ''),
        'user_agent' => (string) ($visitorCtx['user_agent'] ?? ''),
        'asn' => (string) ($visitorCtx['asn'] ?? ''),
        'isp' => (string) ($visitorCtx['isp'] ?? ''),
        'is_proxy' => (int) ($visitorCtx['is_proxy'] ?? 0),
        'proxy_type' => (string) ($visitorCtx['proxy_type'] ?? ''),
        'proxy_threat' => (string) ($visitorCtx['proxy_threat'] ?? ''),
        'proxy_provider' => (string) ($visitorCtx['proxy_provider'] ?? ''),
        'proxy_fraud_score' => isset($visitorCtx['proxy_fraud_score']) ? (int) $visitorCtx['proxy_fraud_score'] : null,
        'accept_language' => (string) ($visitorCtx['accept_language'] ?? ''),
        'pdo' => $visitorCtx['pdo'] ?? null,
    ];

    // Verdict: start with money, flip to safe if any layer triggers
    $showSafe = false;
    $verdict = 'money';
    $reasons = [];

    // Layer 1: JS challenge webdriver failure
    $jsChallengeEnabled = filter_var($customSchema['js_challenge'] ?? false, FILTER_VALIDATE_BOOL);
    if ($jsChallengeEnabled && $jsFailure === 'webdriver') {
        $showSafe = true;
        $verdict = 'js_safe';
        $reasons[] = 'webdriver';
    }

    // Layer 2: Passive detection layers (datacenter, VPN, bots, UA)
    if (!$showSafe) {
        $passiveVerdict = CloakDetector::detect($cloakVisitor, $cloakConfig);
        if ($passiveVerdict['is_suspicious']) {
            $showSafe = true;
            $verdict = 'passive_safe';
            $reasons = array_merge($reasons, $passiveVerdict['reasons']);
        }
    }

    // Layer 3: Targeting filters (country, device, ISP) - hard rules
    if (!$showSafe) {
        // W4: Check if geo data was available for targeting
        $geoReady = !empty($countryCode) && strtoupper($countryCode) !== 'UNKNOWN';
        $targetingReasons = CloakDetector::targetingReasons(
            $customSchema,
            $countryCode,
            $deviceType,
            ($visitorCtx['isp'] ?? '') . ' ' . ($visitorCtx['asn'] ?? ''),
            (string) ($settings['bot_isp_list'] ?? ''),
            $geoReady
        );
        if (!empty($targetingReasons)) {
            $showSafe = true;
            $verdict = 'targeting_safe';
            $reasons = array_merge($reasons, $targetingReasons);
        }
    }

    // Layer 4: JS re-check (if we haven't already failed on webdriver)
    if (!$showSafe && $jsChallengeEnabled && $jsFailure === 'webdriver') {
        $showSafe = true;
        $verdict = 'js_safe';
        if (!in_array('webdriver', $reasons, true)) {
            $reasons[] = 'webdriver';
        }
    }

    // Determine if click should be skipped (suppression)
    $skipClickLog = CloakDetector::shouldSkipSafePageClick($customSchema, $showSafe);

    // Check if geo data was available
    $geoReady = !empty($countryCode) && $countryCode !== 'Unknown';

    return [
        'show_safe' => $showSafe,
        'verdict' => $showSafe ? $verdict : null,
        'reasons' => $reasons,
        'skip_click_log' => $skipClickLog,
        'geo_ready' => $geoReady,
        'sensitivity' => $cloakConfig['sensitivity'],
    ];
}

/**
 * Build cloak-specific context fields for click logging.
 *
 * Extracts the cloak verdict information into the format expected
 * by orbitraBuildClickRow(). Called after orbitraCloakDecision().
 *
 * @param array $decision Return value from orbitraCloakDecision()
 * @param array $geoData Geo data from orbitraClickApiGetGeoData() or equivalent
 * @return array Context fields for orbitraBuildClickRow()
 */
function orbitraCloakClickContext(array $decision, array $geoData = []): array
{
    $verdict = $decision['verdict'];
    $reasonsString = !empty($decision['reasons']) ? implode(',', $decision['reasons']) : '';

    return [
        'cloak_verdict' => $verdict,
        'cloak_reasons' => $reasonsString,
        'is_safe_page' => $decision['show_safe'] ? 1 : 0,
        'isp' => (string) ($geoData['isp'] ?? ''),
        'asn' => (string) ($geoData['asn'] ?? ''),
        'proxy_type' => (string) ($geoData['proxy_type'] ?? ''),
        'cloak_sensitivity' => (string) ($decision['sensitivity'] ?? ''),
    ];
}

/**
 * Record a suppressed hit when a click is not persisted.
 *
 * Called when a click is suppressed due to dont_record_safe_clicks=true
 * or collect_clicks=0. Uses a single INSERT ... ON CONFLICT DO UPDATE
 * for minimal overhead on the click path.
 *
 * @param PDO $pdo Database connection
 * @param int $campaignId Campaign ID
 * @param int|null $streamId Stream ID (may be null for some cases)
 * @param string $verdict Cloak verdict (e.g., 'targeting_safe', 'passive_safe', 'js_safe')
 * @param string $reason Comma-separated reason codes (empty string for no reason)
 * @return bool True if record succeeded, false otherwise
 */
function orbitraRecordSuppressedHit(PDO $pdo, int $campaignId, ?int $streamId, string $verdict, string $reason = ''): bool
{
    try {
        $day = gmdate('Y-m-d'); // UTC day for consistent aggregation
        $stmt = $pdo->prepare("
            INSERT INTO cloak_suppressed_stats (campaign_id, stream_id, day, verdict, reason, hits)
            VALUES (?, ?, ?, ?, ?, 1)
            ON CONFLICT (campaign_id, stream_id, day, verdict, reason)
            DO UPDATE SET hits = hits + 1
        ");
        $stmt->execute([$campaignId, $streamId, $day, $verdict, $reason]);
        return true;
    } catch (\Throwable $e) {
        // Don't break the click path for stats recording failures
        error_log('Orbitra suppressed-hit recording failed: ' . $e->getMessage());
        return false;
    }
}
