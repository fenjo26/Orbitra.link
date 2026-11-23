<?php
/**
 * The two statistics retention windows (ТЗ 2.1) — raw clicks and converted
 * clicks — with the one reader every consumer goes through.
 *
 * Raw clicks used to be purged by the pre-2.1 `stats_retention_days` row
 * while the panel wrote `stats_raw_retention_days`, so the "raw click
 * retention" field changed nothing on disk. New code reads both windows from
 * here; the legacy name is only a fallback for a database that never got the
 * 2.1 rows.
 */

/** A stored window in whole days; $default when the row is missing or not a positive number. */
function orbitraRetentionDays(PDO $pdo, string $key, int $default): int
{
    try {
        $value = $pdo->query('SELECT value FROM settings WHERE key = ' . $pdo->quote($key) . ' LIMIT 1')->fetchColumn();
    } catch (\Throwable $e) {
        return $default;
    }
    $days = (int) $value;
    return $days > 0 ? min($days, 3650) : $default;
}

/** The raw-click window: stats_raw_retention_days, else its pre-2.1 name stats_retention_days. */
function orbitraStatsRawRetentionDays(PDO $pdo): int
{
    return orbitraRetentionDays($pdo, 'stats_raw_retention_days', 0)
        ?: orbitraRetentionDays($pdo, 'stats_retention_days', 256);
}

/**
 * Check posted retention windows against each other and the stored ones.
 * Returns null when they are fine, otherwise the machine `code` the panel
 * translates (and an English `message` for API clients).
 *
 * Only a CHANGED raw window is range-checked: an inherited value below 31 from
 * a pre-2.1 install must not block saving unrelated settings.
 *
 * @return array{code: string, message: string}|null
 */
function orbitraStatsRetentionError(PDO $pdo, array $data): ?array
{
    if (array_key_exists('stats_raw_retention_days', $data)) {
        $rawDays = (int) $data['stats_raw_retention_days'];
        if ($rawDays !== orbitraRetentionDays($pdo, 'stats_raw_retention_days', 60) && ($rawDays < 31 || $rawDays > 3650)) {
            return [
                'code' => 'retention_raw_range',
                'message' => 'Raw click retention must be between 31 and 3650 days (automation rules look back up to 30 days over raw clicks).',
            ];
        }
        $converted = array_key_exists('stats_converted_retention_days', $data)
            ? (int) $data['stats_converted_retention_days']
            : orbitraRetentionDays($pdo, 'stats_converted_retention_days', 365);
        if ($converted < $rawDays) {
            return ['code' => 'retention_converted_short', 'message' => 'Converted click retention must be at least as long as raw click retention.'];
        }
    }
    if (array_key_exists('stats_converted_retention_days', $data)) {
        $rawNow = array_key_exists('stats_raw_retention_days', $data)
            ? (int) $data['stats_raw_retention_days']
            : orbitraRetentionDays($pdo, 'stats_raw_retention_days', 60);
        if ((int) $data['stats_converted_retention_days'] < $rawNow) {
            return ['code' => 'retention_converted_short', 'message' => 'Converted click retention must be at least as long as raw click retention.'];
        }
    }
    return null;
}
