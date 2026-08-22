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

require_once __DIR__ . '/Device.php';

/**
 * Build the complete click row array for INSERT.
 *
 * Single source of truth for the clicks table schema. Any new column
 * must be added here to be persisted by all entry points.
 *
 * NOTE: This is Stage A-1 - refactoring only, no new columns.
 * W1 columns (cloak_verdict, cloak_reasons, is_safe_page, isp, asn, proxy_type,
 * cloak_sensitivity) will be added in migration v38.
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
 * @return array Column => value map for INSERT
 */
function orbitraBuildClickRow(array $ctx): array
{
    // Foreign key safety: offer_id must be NULL, not 0, for the foreign key
    $offerId = isset($ctx['offer_id']) && $ctx['offer_id'] > 0 ? (int) $ctx['offer_id'] : null;

    return [
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
    ];
}

/**
 * Persist a click row to the database.
 *
 * Wraps the INSERT in error handling so click logging failures never
 * break the redirect/landing response.
 *
 * @param PDO $pdo Database connection
 * @param array $row Click row from orbitraBuildClickRow()
 * @return bool True if INSERT succeeded, false otherwise
 */
function orbitraPersistClick(PDO $pdo, array $row): bool
{
    try {
        $columns = array_keys($row);
        $placeholders = array_fill(0, count($columns), '?');

        $insertStmt = $pdo->prepare("
            INSERT INTO clicks (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")
        ");

        $insertStmt->execute(array_values($row));
        return true;
    } catch (\Throwable $e) {
        // Never let click logging break the redirect/landing. Log and continue.
        error_log('Orbitra click logging failed: ' . $e->getMessage());
        return false;
    }
}

// Cloak decision and context functions will be added in W1 (verdict persistence)
// after migration v38 adds the required columns.
