<?php
// core/ConversionAttribution.php
//
// A conversion is only ever born from a click: the affiliate network posts back
// the tracker's own click id (Dr. Cash carries it in &sub1={subid} and returns it
// as ?subid=), and everything the reports need — which campaign, which offer,
// which sub_id_1..5 the traffic arrived with — already lives on that click row.
//
// Why this file exists: postback.php used to insert the conversion with nothing
// but click_id/status/payout, leaving conversions.campaign_id NULL. The reports
// that join conversions to clicks still worked, but every consumer that reads the
// conversion row on its own — the conversions log and its campaign/offer filters,
// the campaign counters exported from it — saw an unlinked record. The columns
// exist in the schema; nobody was filling them.
//
// The one rule worth stating out loud: sub_id_1..5 on the conversion are copies of
// the CLICK's own sub_id_1..5 (from clicks.parameters_json), never the postback's
// subid. subid is the click id — writing it into the sub1 dimension would collapse
// every Sub1 report row into one unique value per click.

if (!function_exists('orbitraConversionAttributionColumns')) {

    /**
     * Attribution columns of `conversions` that actually exist in this database.
     *
     * Trackers upgraded from an older schema may be missing some of them, and a
     * postback must not die with "no such column" — dropping an attribute is
     * recoverable, losing the conversion is not.
     *
     * @return string[]
     */
    function orbitraConversionAttributionColumns(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $present = [];
        try {
            foreach ($pdo->query("PRAGMA table_info(conversions)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $present[strtolower((string) ($col['name'] ?? ''))] = true;
            }
        } catch (\Throwable $e) {
            return $cache = [];
        }

        $wanted = [
            'campaign_id', 'offer_id',
            'sub_id_1', 'sub_id_2', 'sub_id_3', 'sub_id_4', 'sub_id_5',
            'ip', 'user_agent',
        ];

        return $cache = array_values(array_filter($wanted, static fn($c) => isset($present[$c])));
    }

    /** Columns above that hold integers — they need a different "already set" test than text. */
    function orbitraConversionAttributionIntColumns(): array
    {
        return ['campaign_id', 'offer_id'];
    }

    /**
     * Build the attribution payload from an already-fetched click row.
     *
     * @param array<string,mixed> $click needs campaign_id, offer_id, ip, user_agent, parameters_json
     * @return array<string,mixed> column => value, nulls meaning "the click has nothing to say here"
     */
    function orbitraClickAttributionFromRow(array $click): array
    {
        $params = [];
        if (!empty($click['parameters_json'])) {
            $decoded = json_decode((string) $click['parameters_json'], true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        $text = static function ($value, int $max) {
            if ($value === null) {
                return null;
            }
            $value = trim((string) $value);
            return $value === '' ? null : mb_substr($value, 0, $max);
        };

        $attr = [
            'campaign_id' => !empty($click['campaign_id']) ? (int) $click['campaign_id'] : null,
            'offer_id'    => !empty($click['offer_id']) ? (int) $click['offer_id'] : null,
            'ip'          => $text($click['ip'] ?? null, 45),
            'user_agent'  => $text($click['user_agent'] ?? null, 512),
        ];

        // The click's own custom sub parameters — NOT the postback's subid.
        for ($i = 1; $i <= 5; $i++) {
            $attr['sub_id_' . $i] = $text($params['sub_id_' . $i] ?? null, 255);
        }

        return $attr;
    }

    /**
     * Attribution payload for a click id, or null when no such click exists.
     *
     * A null return is the signal not to create a conversion at all: a postback
     * whose subid matches nothing is an orphan by definition, and storing it
     * would put a row with campaign_id NULL in front of the user.
     *
     * @return array<string,mixed>|null
     */
    function orbitraClickAttribution(PDO $pdo, string $clickId): ?array
    {
        $clickId = trim($clickId);
        if ($clickId === '') {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, campaign_id, offer_id, ip, user_agent, parameters_json
                 FROM clicks WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$clickId]);
            $click = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }

        return $click ? orbitraClickAttributionFromRow($click) : null;
    }

    /**
     * Copy the attribution onto one conversion row.
     *
     * By default already-populated columns are left alone: a status update posted
     * days later must not silently rewrite what the conversion was attributed to
     * when it was created. $overwrite is for the repair paths.
     *
     * Best effort by design — the caller has already accepted the money.
     */
    function orbitraApplyConversionAttribution(PDO $pdo, int $conversionId, array $attr, bool $overwrite = false): bool
    {
        if ($conversionId <= 0) {
            return false;
        }

        $columns = orbitraConversionAttributionColumns($pdo);
        $ints = orbitraConversionAttributionIntColumns();

        $set = [];
        $values = [];
        foreach ($columns as $col) {
            $value = $attr[$col] ?? null;
            if ($value === null) {
                continue; // nothing to contribute — never blank an existing value
            }
            if ($overwrite) {
                $set[] = "$col = ?";
            } elseif (in_array($col, $ints, true)) {
                // 0 counts as unset for an id column.
                $set[] = "$col = CASE WHEN $col IS NULL OR $col = 0 THEN ? ELSE $col END";
            } else {
                $set[] = "$col = CASE WHEN $col IS NULL OR $col = '' THEN ? ELSE $col END";
            }
            $values[] = $value;
        }

        if (!$set) {
            return false;
        }

        $values[] = $conversionId;
        try {
            $pdo->prepare("UPDATE conversions SET " . implode(', ', $set) . " WHERE id = ?")->execute($values);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Repair conversions written before attribution existed.
     *
     * Set-based on purpose: this runs inside the schema migration, where a row
     * loop over a large conversions table would stall every request waiting on
     * the migration lock. Rows whose click is gone are left as they are — there
     * is nothing to attribute them to.
     *
     * @return int rows touched, or -1 when the statement could not run at all
     */
    function orbitraBackfillConversionAttribution(PDO $pdo): int
    {
        $columns = orbitraConversionAttributionColumns($pdo);
        if (!$columns) {
            return -1;
        }

        $sources = [
            'campaign_id' => 'c.campaign_id',
            'offer_id'    => 'c.offer_id',
            'ip'          => 'c.ip',
            'user_agent'  => 'c.user_agent',
        ];
        for ($i = 1; $i <= 5; $i++) {
            $sources['sub_id_' . $i] = "json_extract(c.parameters_json, '$.sub_id_$i')";
        }

        $set = [];
        $missing = [];
        foreach ($columns as $col) {
            if (!isset($sources[$col])) {
                continue;
            }
            $pick = "(SELECT {$sources[$col]} FROM clicks c WHERE c.id = conversions.click_id)";
            $set[] = "$col = COALESCE($col, $pick)";
            $missing[] = "$col IS NULL";
        }

        if (!$set) {
            return -1;
        }

        $sql = "UPDATE conversions SET " . implode(', ', $set) . "
                WHERE (" . implode(' OR ', $missing) . ")
                  AND EXISTS (SELECT 1 FROM clicks c WHERE c.id = conversions.click_id)";

        try {
            return (int) $pdo->exec($sql);
        } catch (\Throwable $e) {
            return -1;
        }
    }
}
