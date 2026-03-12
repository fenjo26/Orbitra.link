<?php
/**
 * Keitaro -> Orbitra importer.
 *
 * Accepts a mysqldump SQL file with CREATE TABLE + INSERT INTO ... VALUES (...) statements
 * and imports the "metadata" entities into Orbitra SQLite:
 * - affiliate networks ("companies")
 * - offers
 * - domains
 *
 * This file intentionally does NOT execute the SQL dump against any DB engine.
 * It parses a limited subset and uses prepared statements to insert into SQLite.
 */

declare(strict_types=1);

function orbitraKeitaroLoadSqlDump(string $path, int $maxBytes = 104857600): string
{
    if (!is_file($path)) {
        throw new RuntimeException("SQL file not found");
    }

    $size = filesize($path);
    if (is_int($size) && $size > $maxBytes) {
        throw new RuntimeException("SQL file is too large (" . $size . " bytes)");
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException("Failed to read SQL file");
    }

    // Support .gz if user uploaded a compressed dump.
    if (str_ends_with(strtolower($path), '.gz')) {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException("gzdecode is not available on this server");
        }
        $decoded = @gzdecode($raw);
        if (!is_string($decoded) || $decoded === '') {
            throw new RuntimeException("Failed to decode .gz SQL dump");
        }
        return $decoded;
    }

    return $raw;
}

function orbitraKeitaroExtractCreateTableColumns(string $sql, string $table): array
{
    // Capture column definitions within CREATE TABLE ... ( ... ) ENGINE=...
    $re = '/CREATE\\s+TABLE\\s+`' . preg_quote($table, '/') . '`\\s*\\((.*?)\\)\\s*ENGINE=/si';
    if (!preg_match($re, $sql, $m)) {
        return [];
    }

    $inner = (string) $m[1];
    $cols = [];

    // Column lines start with optional whitespace and a backtick.
    // Example:   `id` int(11) NOT NULL AUTO_INCREMENT,
    $lines = preg_split("/\\r\\n|\\n|\\r/", $inner);
    if (!is_array($lines)) return [];

    foreach ($lines as $line) {
        if (preg_match('/^\\s*`([^`]+)`\\s+/u', (string) $line, $mm)) {
            $cols[] = (string) $mm[1];
        }
    }

    return $cols;
}

function orbitraKeitaroExtractInsertValueBlobs(string $sql, string $table): array
{
    // Extract each INSERT ... VALUES <blob>;
    $re = '/INSERT\\s+INTO\\s+`' . preg_quote($table, '/') . '`\\s+VALUES\\s*(.*?);/si';
    if (!preg_match_all($re, $sql, $m, PREG_SET_ORDER)) {
        return [];
    }
    $out = [];
    foreach ($m as $row) {
        $out[] = (string) $row[1];
    }
    return $out;
}

function orbitraKeitaroParseMySqlValue(string $s, int &$i)
{
    $len = strlen($s);
    while ($i < $len && ctype_space($s[$i])) $i++;

    if ($i >= $len) {
        return null;
    }

    // NULL literal
    if (strncasecmp(substr($s, $i, 4), 'NULL', 4) === 0) {
        $i += 4;
        return null;
    }

    // Quoted string
    if ($s[$i] === "'") {
        $i++; // skip opening quote
        $out = '';
        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === '\\') {
                $i++;
                if ($i >= $len) break;
                $esc = $s[$i];
                // MySQL dump escapes
                if ($esc === 'n') $out .= "\n";
                elseif ($esc === 'r') $out .= "\r";
                elseif ($esc === 't') $out .= "\t";
                elseif ($esc === '0') $out .= "\0";
                elseif ($esc === 'Z') $out .= chr(26);
                else $out .= $esc;
                $i++;
                continue;
            }
            if ($ch === "'") {
                // MySQL can represent a quote as '' inside a string
                if (($i + 1) < $len && $s[$i + 1] === "'") {
                    $out .= "'";
                    $i += 2;
                    continue;
                }
                $i++; // skip closing quote
                return $out;
            }
            $out .= $ch;
            $i++;
        }
        return $out;
    }

    // Unquoted: number / bare token
    $start = $i;
    while ($i < $len) {
        $ch = $s[$i];
        if ($ch === ',' || $ch === ')') break;
        $i++;
    }
    $raw = trim(substr($s, $start, $i - $start));
    if ($raw === '') return '';

    if (preg_match('/^-?\\d+$/', $raw)) return (int) $raw;
    if (preg_match('/^-?\\d+\\.\\d+$/', $raw)) return (float) $raw;
    return $raw;
}

function orbitraKeitaroParseInsertValuesBlob(string $blob, array $columns): array
{
    $rows = [];
    $s = trim($blob);
    $len = strlen($s);
    $i = 0;

    while ($i < $len) {
        while ($i < $len && (ctype_space($s[$i]) || $s[$i] === ',')) $i++;
        if ($i >= $len) break;
        if ($s[$i] !== '(') {
            // Unexpected token; bail out to avoid wrong imports.
            throw new RuntimeException("Unexpected INSERT values format near: " . substr($s, max(0, $i - 10), 40));
        }
        $i++; // skip '('

        $vals = [];
        while ($i < $len) {
            $vals[] = orbitraKeitaroParseMySqlValue($s, $i);
            while ($i < $len && ctype_space($s[$i])) $i++;
            if ($i >= $len) break;
            if ($s[$i] === ',') {
                $i++; // next value
                continue;
            }
            if ($s[$i] === ')') {
                $i++; // end row
                break;
            }
            // Unexpected char
            throw new RuntimeException("Unexpected char in row values: " . $s[$i]);
        }

        if (!empty($columns)) {
            $assoc = [];
            foreach ($columns as $idx => $col) {
                $assoc[$col] = $vals[$idx] ?? null;
            }
            $rows[] = $assoc;
        } else {
            $rows[] = $vals;
        }
    }

    return $rows;
}

function orbitraKeitaroParseSqlDump(string $path, array $tables): array
{
    $sql = orbitraKeitaroLoadSqlDump($path);
    $out = [];

    foreach ($tables as $table) {
        $cols = orbitraKeitaroExtractCreateTableColumns($sql, $table);
        $blobs = orbitraKeitaroExtractInsertValueBlobs($sql, $table);
        $rows = [];
        foreach ($blobs as $blob) {
            $rows = array_merge($rows, orbitraKeitaroParseInsertValuesBlob($blob, $cols));
        }
        $out[$table] = [
            'columns' => $cols,
            'rows' => $rows,
        ];
    }

    return $out;
}

function orbitraKeitaroNormalizeGeo(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '""' || $raw === '\"\"') return '';
    // Some dumps have JSON like ["GR"] but escaped in SQL as [\"GR\"]
    $raw2 = $raw;
    // If it looks like JSON, try decode
    if ($raw2 !== '' && ($raw2[0] === '[' || $raw2[0] === '{')) {
        $j = json_decode($raw2, true);
        if (is_array($j)) {
            // Store as comma-separated ISO2 list for now.
            $parts = [];
            foreach ($j as $v) {
                if (is_string($v) && $v !== '') $parts[] = $v;
            }
            return implode(',', $parts);
        }
    }
    return $raw;
}

function orbitraKeitaroMapUniquenessMethod(string $v): string
{
    $v = strtolower(trim($v));
    if ($v === 'ip_ua' || $v === 'ip+ua' || $v === 'ipua') return 'IP_UA';
    if ($v === 'ip') return 'IP';
    if ($v === 'cookies') return 'Cookies';
    // Fallback to Orbitra defaults.
    return 'IP';
}

function orbitraKeitaroMapCostModel(string $v): string
{
    $v = strtoupper(trim($v));
    if ($v === '') return 'CPC';
    // Orbitra does not enforce a strict enum here; keep Keitaro value if set.
    return $v;
}

function orbitraKeitaroSqliteHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            if (isset($r['name']) && (string) $r['name'] === $column) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return false;
}

function orbitraKeitaroLoadKeitaroIdMap(PDO $pdo, string $table, string $idCol = 'id', string $keitaroIdCol = 'keitaro_id'): array
{
    // Returns keitaro_id => orbitra_id mapping for existing DB rows.
    // Used so imports can be done in multiple steps/runs.
    $map = [];
    if (!orbitraKeitaroSqliteHasColumn($pdo, $table, $keitaroIdCol)) {
        return $map;
    }
    try {
        $stmt = $pdo->query("SELECT {$idCol} AS id, {$keitaroIdCol} AS keitaro_id FROM {$table} WHERE {$keitaroIdCol} IS NOT NULL AND {$keitaroIdCol} > 0");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            $kid = (int) ($r['keitaro_id'] ?? 0);
            $oid = (int) ($r['id'] ?? 0);
            if ($kid > 0 && $oid > 0) {
                $map[$kid] = $oid;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $map;
}

function orbitraKeitaroJsonDecodeMaybe($value)
{
    if (!is_string($value)) return null;
    $s = trim($value);
    if ($s === '') return null;
    if ($s[0] !== '[' && $s[0] !== '{') return null;
    $j = json_decode($s, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $j : null;
}

function orbitraKeitaroParseList($value): array
{
    // Parse a payload that might be JSON array, CSV, or a scalar.
    if ($value === null) return [];
    if (is_array($value)) return array_values($value);
    if (is_int($value) || is_float($value)) return [(string) $value];
    $s = trim((string) $value);
    if ($s === '' || strtoupper($s) === 'NULL') return [];
    $j = orbitraKeitaroJsonDecodeMaybe($s);
    if (is_array($j)) {
        return array_values($j);
    }
    if (strpos($s, ',') !== false) {
        $parts = array_map('trim', explode(',', $s));
        $parts = array_values(array_filter($parts, fn($x) => $x !== ''));
        return $parts;
    }
    return [$s];
}

function orbitraKeitaroMapFilterMode(string $v): string
{
    $v = strtolower(trim($v));
    if ($v === 'exclude' || $v === 'not_in' || $v === 'notin' || $v === '!' || $v === '!=') return 'exclude';
    return 'include';
}

function orbitraKeitaroMapDeviceValue(string $v): string
{
    $v = strtolower(trim($v));
    if ($v === 'mobile' || $v === 'm') return 'Mobile';
    if ($v === 'desktop' || $v === 'd' || $v === 'pc') return 'Desktop';
    // Keep as-is; Orbitra matcher uses exact strings.
    return $v !== '' ? ucfirst($v) : '';
}

function orbitraKeitaroBuildOrbitraFilters(array $keitaroFilters): array
{
    // Best-effort conversion of Keitaro stream filters into Orbitra's filters_json schema.
    // Orbitra supports: Country, Device, Bot, Language (see index.php streamMatchesFilters()).
    $out = [];

    foreach ($keitaroFilters as $r) {
        if (!is_array($r)) continue;

        $type = strtolower((string) ($r['type'] ?? $r['name'] ?? $r['filter'] ?? $r['field'] ?? ''));
        $field = strtolower((string) ($r['field'] ?? $r['name'] ?? $r['param'] ?? ''));
        $modeRaw = (string) ($r['mode'] ?? $r['operator'] ?? $r['include'] ?? 'include');
        $mode = orbitraKeitaroMapFilterMode($modeRaw);

        $payloadRaw = $r['payload'] ?? $r['value'] ?? $r['values'] ?? $r['data'] ?? $r['selected'] ?? null;
        $payload = orbitraKeitaroParseList($payloadRaw);

        $isBot = (strpos($type, 'bot') !== false) || (strpos($field, 'bot') !== false) || ($type === 'is_bot');
        if ($isBot) {
            // Orbitra only needs a non-empty payload to evaluate Bot.
            $out[] = ['name' => 'Bot', 'mode' => $mode, 'payload' => [1]];
            continue;
        }

        $isCountry = (strpos($type, 'country') !== false) || (strpos($type, 'geo') !== false) || (strpos($field, 'country') !== false);
        if ($isCountry) {
            $cc = [];
            foreach ($payload as $p) {
                $p = strtoupper(trim((string) $p));
                if ($p === '' || $p === '@empty') continue;
                // Keep 2-letter ISO2 (Orbitra stores country_code).
                if (preg_match('/^[A-Z]{2}$/', $p)) {
                    $cc[] = $p;
                }
            }
            $cc = array_values(array_unique($cc));
            if (!empty($cc)) {
                $out[] = ['name' => 'Country', 'mode' => $mode, 'payload' => $cc];
            }
            continue;
        }

        $isDevice = (strpos($type, 'device') !== false) || (strpos($field, 'device') !== false);
        if ($isDevice) {
            $dv = [];
            foreach ($payload as $p) {
                $m = orbitraKeitaroMapDeviceValue((string) $p);
                if ($m !== '') $dv[] = $m;
            }
            $dv = array_values(array_unique($dv));
            if (!empty($dv)) {
                $out[] = ['name' => 'Device', 'mode' => $mode, 'payload' => $dv];
            }
            continue;
        }

        $isLang = (strpos($type, 'language') !== false) || (strpos($type, 'lang') !== false) || (strpos($field, 'lang') !== false);
        if ($isLang) {
            $lv = [];
            foreach ($payload as $p) {
                $p = strtolower(trim((string) $p));
                $p = preg_split('/[-_]/', $p)[0] ?? $p;
                $p = preg_replace('/[^a-z]/', '', $p);
                if ($p !== '') $lv[] = $p;
            }
            $lv = array_values(array_unique($lv));
            if (!empty($lv)) {
                $out[] = ['name' => 'Language', 'mode' => $mode, 'payload' => $lv];
            }
            continue;
        }
    }

    return $out;
}

function orbitraKeitaroImportSqlDump(PDO $pdo, string $path, array $opts = []): array
{
    $dryRun = !empty($opts['dry_run']);
    $doDomains = array_key_exists('import_domains', $opts) ? (bool) $opts['import_domains'] : true;
    $doOffers = array_key_exists('import_offers', $opts) ? (bool) $opts['import_offers'] : true;
    $doCompanies = array_key_exists('import_companies', $opts) ? (bool) $opts['import_companies'] : true;
    $doTrafficSources = array_key_exists('import_traffic_sources', $opts) ? (bool) $opts['import_traffic_sources'] : false;
    $doLandings = array_key_exists('import_landings', $opts) ? (bool) $opts['import_landings'] : false;
    $doCampaigns = array_key_exists('import_campaigns', $opts) ? (bool) $opts['import_campaigns'] : false;
    $doStreams = array_key_exists('import_streams', $opts) ? (bool) $opts['import_streams'] : false;
    $doCampaignPostbacks = array_key_exists('import_campaign_postbacks', $opts) ? (bool) $opts['import_campaign_postbacks'] : false;

    $tablesToParse = [];
    if ($doCompanies) $tablesToParse[] = 'keitaro_affiliate_networks';
    if ($doOffers) {
        $tablesToParse[] = 'keitaro_groups';
        $tablesToParse[] = 'keitaro_offers';
    }
    if ($doDomains) $tablesToParse[] = 'keitaro_domains';
    if ($doTrafficSources) {
        // Keitaro installs vary: some use keitaro_ref_sources for traffic sources.
        $tablesToParse[] = 'keitaro_traffic_sources';
        $tablesToParse[] = 'keitaro_ref_sources';
    }
    if ($doLandings) {
        $tablesToParse[] = 'keitaro_landings';
    }
    if ($doCampaigns) {
        $tablesToParse[] = 'keitaro_campaigns';
        // campaign groups can also live in keitaro_groups (type='campaign' on some installs)
        $tablesToParse[] = 'keitaro_groups';
        // Needed to map campaign.domain_id -> Orbitra domain_id even if we're not importing domains in this run.
        $tablesToParse[] = 'keitaro_domains';
        if ($doTrafficSources) {
            $tablesToParse[] = 'keitaro_traffic_sources';
            $tablesToParse[] = 'keitaro_ref_sources';
        }
    }
    if ($doStreams) {
        $tablesToParse[] = 'keitaro_streams';
        $tablesToParse[] = 'keitaro_stream_filters';
        $tablesToParse[] = 'keitaro_stream_offer_associations';
        $tablesToParse[] = 'keitaro_stream_landing_associations';
        // Streams need campaigns/offers/landings to be mapped.
        $tablesToParse[] = 'keitaro_campaigns';
        $tablesToParse[] = 'keitaro_offers';
        $tablesToParse[] = 'keitaro_landings';
        $tablesToParse[] = 'keitaro_domains';
    }
    if ($doCampaignPostbacks) {
        $tablesToParse[] = 'keitaro_campaign_postbacks';
        // needs campaigns mapping
        $tablesToParse[] = 'keitaro_campaigns';
        // Needed for domain mapping when campaigns already exist and we re-run import.
        $tablesToParse[] = 'keitaro_domains';
    }

    $parsed = orbitraKeitaroParseSqlDump($path, array_values(array_unique($tablesToParse)));

    $result = [
        'dry_run' => $dryRun ? 1 : 0,
        'parsed' => [],
        'imported' => [
            'affiliate_networks' => ['inserted' => 0, 'skipped' => 0],
            'offer_groups' => ['inserted' => 0, 'skipped' => 0],
            'offers' => ['inserted' => 0, 'skipped' => 0],
            'domains' => ['inserted' => 0, 'skipped' => 0],
            'traffic_sources' => ['inserted' => 0, 'skipped' => 0],
            'landings' => ['inserted' => 0, 'skipped' => 0],
            'campaign_groups' => ['inserted' => 0, 'skipped' => 0],
            'campaigns' => ['inserted' => 0, 'skipped' => 0],
            'streams' => ['inserted' => 0, 'skipped' => 0, 'updated' => 0],
            'campaign_postbacks' => ['inserted' => 0, 'skipped' => 0],
        ],
        'warnings' => [],
    ];

    foreach ($parsed as $tbl => $p) {
        $result['parsed'][$tbl] = [
            'columns' => count($p['columns'] ?? []),
            'rows' => count($p['rows'] ?? []),
        ];
    }

        if ($dryRun) {
            return $result;
        }

        $hasDomainKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'domains', 'keitaro_id');
        $hasAffiliateKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'affiliate_networks', 'keitaro_id');
        $hasOfferKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'offers', 'keitaro_id');
        $hasCampaignKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'campaigns', 'keitaro_id');
        $hasLandingKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'landings', 'keitaro_id');
        $hasTrafficSourceKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'traffic_sources', 'keitaro_id');
        $hasStreamKeitaroId = orbitraKeitaroSqliteHasColumn($pdo, 'streams', 'keitaro_id');

        $dbDomainsByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'domains');
        $dbAffiliateByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'affiliate_networks');
        $dbOffersByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'offers');
        $dbCampaignsByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'campaigns');
        $dbLandingsByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'landings');
        $dbTrafficSourcesByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'traffic_sources');
        $dbStreamsByKeitaroId = orbitraKeitaroLoadKeitaroIdMap($pdo, 'streams');

        $pdo->beginTransaction();
        try {
            // ---- Domains (needed by campaigns) ----
            // We build the Keitaro domain_id -> Orbitra domain_id map even if import_domains=0,
            // so campaigns can still be mapped correctly in a separate run.
            $keitaroDomainIdToOrbitraDomainId = [];
            $needDomainMap = $doDomains || $doCampaigns || $doCampaignPostbacks;
            if ($needDomainMap) {
                $rows = $parsed['keitaro_domains']['rows'] ?? [];
                $stmtFind = $pdo->prepare("SELECT id, keitaro_id FROM domains WHERE name = ? LIMIT 1");

                $stmtIns = null;
                if ($doDomains) {
                    $stmtIns = $pdo->prepare("
                        INSERT OR IGNORE INTO domains
                        (name, index_campaign_id, catch_404, group_id, is_noindex, https_only)
                        VALUES (?, NULL, ?, NULL, ?, ?)
                    ");
                }
                $stmtUpdK = null;
                if ($hasDomainKeitaroId) {
                    $stmtUpdK = $pdo->prepare("UPDATE domains SET keitaro_id = ? WHERE id = ? AND (keitaro_id IS NULL OR keitaro_id = 0)");
                }

                foreach ($rows as $r) {
                    $kid = (int) ($r['id'] ?? 0);
                    $name = trim((string) ($r['name'] ?? ''));
                    if ($name === '') continue;

                    $catch404 = (int) ($r['catch_not_found'] ?? 0) ? 1 : 0;
                    $httpsOnly = (int) ($r['is_ssl'] ?? 0) ? 1 : 0;
                    $allowIndexing = (int) ($r['allow_indexing'] ?? 1) ? 1 : 0;
                    $isNoindex = $allowIndexing ? 0 : 1;

                    if ($doDomains && $stmtIns) {
                        $stmtIns->execute([$name, $catch404, $isNoindex, $httpsOnly]);
                    }

                    $stmtFind->execute([$name]);
                    $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
                    $oid = (int) (($existing['id'] ?? 0) ?: 0);
                    if ($kid > 0 && $oid > 0) {
                        $keitaroDomainIdToOrbitraDomainId[$kid] = $oid;
                        if ($stmtUpdK) {
                            $stmtUpdK->execute([$kid, $oid]);
                        }
                    }

                    if ($doDomains && $stmtIns) {
                        if ($stmtIns->rowCount() > 0) {
                            $result['imported']['domains']['inserted']++;
                        } else {
                            if ($oid > 0) $result['imported']['domains']['skipped']++;
                        }
                    }
                }
            }

            // ---- Companies: affiliate networks ----
            $keitaroAffiliateIdToOrbitraId = [];
        if ($doCompanies) {
            $rows = $parsed['keitaro_affiliate_networks']['rows'] ?? [];
            $stmtFind = $pdo->prepare("SELECT id, postback_url, offer_params, keitaro_id FROM affiliate_networks WHERE is_archived = 0 AND name = ? LIMIT 1");
            $stmtIns = $pdo->prepare("INSERT INTO affiliate_networks (name, template, offer_params, postback_url, notes, state) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtUpdK = null;
            if ($hasAffiliateKeitaroId) {
                $stmtUpdK = $pdo->prepare("UPDATE affiliate_networks SET keitaro_id = ? WHERE id = ? AND (keitaro_id IS NULL OR keitaro_id = 0)");
            }

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;

                $offerParam = (string) ($r['offer_param'] ?? '');
                $postbackUrl = (string) ($r['postback_url'] ?? '');
                $templateName = (string) ($r['template_name'] ?? '');
                $state = (string) ($r['state'] ?? 'active');
                if ($state === '') $state = 'active';

                $notes = (string) ($r['notes'] ?? '');
                $notes2 = trim($notes . "\n" . "[Keitaro] affiliate_network_id=" . $kid);

                $stmtFind->execute([$name]);
                $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
                if ($existing && isset($existing['id'])) {
                    $keitaroAffiliateIdToOrbitraId[$kid] = (int) $existing['id'];
                    if ($stmtUpdK && $kid > 0) {
                        $stmtUpdK->execute([$kid, (int) $existing['id']]);
                    }
                    $result['imported']['affiliate_networks']['skipped']++;
                    continue;
                }

                $stmtIns->execute([
                    $name,
                    $templateName,
                    $offerParam,
                    $postbackUrl,
                    $notes2,
                    $state,
                ]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($kid > 0 && $oid > 0) {
                    $keitaroAffiliateIdToOrbitraId[$kid] = $oid;
                    if ($stmtUpdK) {
                        $stmtUpdK->execute([$kid, $oid]);
                    }
                }
                $result['imported']['affiliate_networks']['inserted']++;
            }
        }

            // ---- Traffic sources ----
            $keitaroTrafficSourceIdToOrbitraId = [];
            if ($doTrafficSources) {
                $rows = $parsed['keitaro_traffic_sources']['rows'] ?? [];
                if (empty($rows)) {
                    // Fallback: Keitaro uses ref_sources as "traffic sources" catalog on many installs.
                    $rows = $parsed['keitaro_ref_sources']['rows'] ?? [];
                }

                if (!empty($rows)) {
                    $stmtFind = $pdo->prepare("SELECT id, keitaro_id FROM traffic_sources WHERE name = ? LIMIT 1");
                    $stmtIns = $pdo->prepare("INSERT INTO traffic_sources (name, template, postback_url, postback_statuses, parameters_json, notes, state, keitaro_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtUpdK = null;
                    if ($hasTrafficSourceKeitaroId) {
                        $stmtUpdK = $pdo->prepare("UPDATE traffic_sources SET keitaro_id = ? WHERE id = ? AND (keitaro_id IS NULL OR keitaro_id = 0)");
                    }

                    foreach ($rows as $r) {
                        $kid = (int) ($r['id'] ?? 0);
                        $name = trim((string) ($r['name'] ?? ''));
                        if ($name === '') continue;

                        $state = (string) ($r['state'] ?? 'active');
                        if ($state === '') $state = 'active';

                        // Keitaro has multiple schemas; keep minimal.
                        $template = (string) ($r['template_name'] ?? ($r['template'] ?? ''));
                        $postbackUrl = (string) ($r['postback_url'] ?? ($r['postback'] ?? ''));
                        $paramsJson = (string) ($r['parameters'] ?? ($r['params'] ?? ''));
                        $notes = (string) ($r['notes'] ?? '');
                        $postbackStatuses = 'lead,sale,rejected';

                        $stmtFind->execute([$name]);
                        $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
                        if ($existing && isset($existing['id'])) {
                            $oid = (int) $existing['id'];
                            $keitaroTrafficSourceIdToOrbitraId[$kid] = $oid;
                            if ($stmtUpdK && $kid > 0) $stmtUpdK->execute([$kid, $oid]);
                            $result['imported']['traffic_sources']['skipped']++;
                            continue;
                        }

                        $stmtIns->execute([
                            $name,
                            $template,
                            $postbackUrl,
                            $postbackStatuses,
                            is_string($paramsJson) && $paramsJson !== '' ? $paramsJson : json_encode(new stdClass()),
                            $notes,
                            $state,
                            $kid > 0 ? $kid : null,
                        ]);
                        $oid = (int) ($pdo->lastInsertId() ?: 0);
                        if ($kid > 0 && $oid > 0) $keitaroTrafficSourceIdToOrbitraId[$kid] = $oid;
                        $result['imported']['traffic_sources']['inserted']++;
                    }
                }
            }

            // ---- Landings ----
            $keitaroLandingIdToOrbitraId = [];
            if ($doLandings) {
                $rows = $parsed['keitaro_landings']['rows'] ?? [];
                if (!empty($rows)) {
                    $stmtFind = $pdo->prepare("SELECT id, keitaro_id FROM landings WHERE url = ? LIMIT 1");
                    $stmtIns = $pdo->prepare("INSERT INTO landings (name, url, group_id, type, state, action_payload, keitaro_id) VALUES (?, ?, NULL, ?, ?, ?, ?)");
                    $stmtUpdK = null;
                    if ($hasLandingKeitaroId) {
                        $stmtUpdK = $pdo->prepare("UPDATE landings SET keitaro_id = ? WHERE id = ? AND (keitaro_id IS NULL OR keitaro_id = 0)");
                    }

                    foreach ($rows as $r) {
                        $kid = (int) ($r['id'] ?? 0);
                        $name = trim((string) ($r['name'] ?? ''));
                        $url = trim((string) ($r['url'] ?? ''));
                        if ($url === '') {
                            // Some Keitaro builds may store it differently.
                            $url = trim((string) ($r['action_payload'] ?? ''));
                        }
                        if ($url === '') continue;
                        if ($name === '') $name = $url;

                        $state = (string) ($r['state'] ?? 'active');
                        if ($state === '') $state = 'active';

                        $type = 'redirect';
                        $actionPayload = null;

                        $stmtFind->execute([$url]);
                        $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
                        if ($existing && isset($existing['id'])) {
                            $oid = (int) $existing['id'];
                            $keitaroLandingIdToOrbitraId[$kid] = $oid;
                            if ($stmtUpdK && $kid > 0) $stmtUpdK->execute([$kid, $oid]);
                            $result['imported']['landings']['skipped']++;
                            continue;
                        }

                        $stmtIns->execute([$name, $url, $type, $state, $actionPayload, $kid > 0 ? $kid : null]);
                        $oid = (int) ($pdo->lastInsertId() ?: 0);
                        if ($kid > 0 && $oid > 0) $keitaroLandingIdToOrbitraId[$kid] = $oid;
                        $result['imported']['landings']['inserted']++;
                    }
                }
            }

        // ---- Offer groups (from keitaro_groups where type='offers') ----
        $keitaroGroupIdToOfferGroupId = [];
        if ($doOffers) {
            $rows = $parsed['keitaro_groups']['rows'] ?? [];
            $stmtFind = $pdo->prepare("SELECT id FROM offer_groups WHERE name = ? LIMIT 1");
            $stmtIns = $pdo->prepare("INSERT INTO offer_groups (name) VALUES (?)");

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $type = (string) ($r['type'] ?? '');
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '' || $type !== 'offers') continue;

                $stmtFind->execute([$name]);
                $existingId = $stmtFind->fetchColumn();
                if ($existingId) {
                    $keitaroGroupIdToOfferGroupId[$kid] = (int) $existingId;
                    $result['imported']['offer_groups']['skipped']++;
                    continue;
                }

                $stmtIns->execute([$name]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($kid > 0 && $oid > 0) {
                    $keitaroGroupIdToOfferGroupId[$kid] = $oid;
                }
                $result['imported']['offer_groups']['inserted']++;
            }
        }

            // ---- Offers ----
            $keitaroOfferIdToOrbitraOfferId = [];
        if ($doOffers) {
            $rows = $parsed['keitaro_offers']['rows'] ?? [];
            $stmtFind = $pdo->prepare("SELECT id, keitaro_id FROM offers WHERE is_archived = 0 AND name = ? AND COALESCE(url,'') = COALESCE(?, '') LIMIT 1");
            $stmtIns = $pdo->prepare("
                INSERT INTO offers
                (name, group_id, affiliate_network_id, url, redirect_type, is_local, geo, payout_type, payout_value, payout_auto, allow_rebills, capping_limit, capping_timezone, alt_offer_id, notes, values_json, state)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtUpdK = null;
            if ($hasOfferKeitaroId) {
                $stmtUpdK = $pdo->prepare("UPDATE offers SET keitaro_id = ? WHERE id = ? AND (keitaro_id IS NULL OR keitaro_id = 0)");
            }

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;

                $kGroupId = (int) ($r['group_id'] ?? 0);
                if ($kGroupId <= 0) $kGroupId = 0;
                $groupId = ($kGroupId > 0 && isset($keitaroGroupIdToOfferGroupId[$kGroupId])) ? (int) $keitaroGroupIdToOfferGroupId[$kGroupId] : null;

                $kAffId = (int) ($r['affiliate_network_id'] ?? 0);
                if ($kAffId <= 0) $kAffId = 0;
                if ($kAffId > 0 && !isset($keitaroAffiliateIdToOrbitraId[$kAffId]) && isset($dbAffiliateByKeitaroId[$kAffId])) {
                    $keitaroAffiliateIdToOrbitraId[$kAffId] = (int) $dbAffiliateByKeitaroId[$kAffId];
                }
                $affiliateId = ($kAffId > 0 && isset($keitaroAffiliateIdToOrbitraId[$kAffId])) ? (int) $keitaroAffiliateIdToOrbitraId[$kAffId] : null;

                $url = trim((string) ($r['url'] ?? ''));
                $actionPayload = trim((string) ($r['action_payload'] ?? ''));
                if ($url === '' && (str_starts_with($actionPayload, 'http://') || str_starts_with($actionPayload, 'https://'))) {
                    $url = $actionPayload;
                }

                $geo = orbitraKeitaroNormalizeGeo((string) ($r['country'] ?? ''));

                $payoutType = strtolower(trim((string) ($r['payout_type'] ?? 'cpa')));
                if ($payoutType === '') $payoutType = 'cpa';
                $payoutValue = (float) ($r['payout_value'] ?? 0.0);
                $payoutAuto = (int) ($r['payout_auto'] ?? 0) ? 1 : 0;

                $notes = (string) ($r['notes'] ?? '');
                $notes2 = trim($notes . "\n" . "[Keitaro] offer_id=" . $kid);

                $state = (string) ($r['state'] ?? 'active');
                if ($state === '') $state = 'active';

                $redirectType = 'redirect';
                $isLocal = 0;
                $allowRebills = 0;
                $cappingLimit = 0;
                $cappingTimezone = (string) ($r['conversion_timezone'] ?? 'UTC');
                if ($cappingTimezone === '') $cappingTimezone = 'UTC';

                $altOfferId = null;

                $stmtFind->execute([$name, $url]);
                $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);
                $existingId = (int) (($existing['id'] ?? 0) ?: 0);
                if ($existingId > 0) {
                    if ($kid > 0) {
                        $keitaroOfferIdToOrbitraOfferId[$kid] = $existingId;
                        if ($stmtUpdK) {
                            $stmtUpdK->execute([$kid, $existingId]);
                        }
                    }
                    $result['imported']['offers']['skipped']++;
                    continue;
                }

                $stmtIns->execute([
                    $name,
                    $groupId,
                    $affiliateId,
                    $url,
                    $redirectType,
                    $isLocal,
                    $geo,
                    $payoutType,
                    $payoutValue,
                    $payoutAuto,
                    $allowRebills,
                    $cappingLimit,
                    $cappingTimezone,
                    $altOfferId,
                    $notes2,
                    json_encode([]),
                    $state,
                ]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($kid > 0 && $oid > 0) {
                    $keitaroOfferIdToOrbitraOfferId[$kid] = $oid;
                    if ($stmtUpdK) {
                        $stmtUpdK->execute([$kid, $oid]);
                    }
                }
                $result['imported']['offers']['inserted']++;
            }
        }

        // ---- Campaign groups (from keitaro_groups where type='campaign') ----
        $keitaroCampaignGroupIdToOrbitraId = [];
        if ($doCampaigns) {
            $rows = $parsed['keitaro_groups']['rows'] ?? [];
            $stmtFind = $pdo->prepare("SELECT id FROM campaign_groups WHERE name = ? LIMIT 1");
            $stmtIns = $pdo->prepare("INSERT INTO campaign_groups (name) VALUES (?)");

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $type = (string) ($r['type'] ?? '');
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;

                // Keitaro versions vary; keep it conservative.
                if ($type !== 'campaign' && $type !== 'campaigns') continue;

                $stmtFind->execute([$name]);
                $existingId = $stmtFind->fetchColumn();
                if ($existingId) {
                    $keitaroCampaignGroupIdToOrbitraId[$kid] = (int) $existingId;
                    $result['imported']['campaign_groups']['skipped']++;
                    continue;
                }

                $stmtIns->execute([$name]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($kid > 0 && $oid > 0) {
                    $keitaroCampaignGroupIdToOrbitraId[$kid] = $oid;
                }
                $result['imported']['campaign_groups']['inserted']++;
            }
        }

            // ---- Campaigns ----
            $keitaroCampaignIdToOrbitraId = [];
            if ($doCampaigns) {
                $rows = $parsed['keitaro_campaigns']['rows'] ?? [];
                $stmtFindByAlias = $pdo->prepare("SELECT id, domain_id, keitaro_id FROM campaigns WHERE is_archived = 0 AND alias = ? LIMIT 1");
                $stmtIns = $pdo->prepare("
                    INSERT INTO campaigns
                    (name, alias, domain_id, group_id, source_id, cost_model, cost_value, uniqueness_method, uniqueness_hours, catch_404_stream_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
                ");
                $stmtUpdDomain = $pdo->prepare("UPDATE campaigns SET domain_id = ? WHERE id = ? AND (domain_id IS NULL OR domain_id = 0)");
                $stmtUpdK = null;
                if ($hasCampaignKeitaroId) {
                    $stmtUpdK = $pdo->prepare("UPDATE campaigns SET keitaro_id = ? WHERE id = ? AND (keitaro_id IS NULL OR keitaro_id = 0)");
                }

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $alias = trim((string) ($r['alias'] ?? ''));
                if ($alias === '') {
                    $alias = $kid > 0 ? ('k' . $kid) : ('k' . substr(md5(json_encode($r)), 0, 8));
                }

                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') {
                    $name = $alias;
                }

                $kDomainId = (int) ($r['domain_id'] ?? 0);
                $domainId = null;
                if ($kDomainId > 0 && isset($keitaroDomainIdToOrbitraDomainId[$kDomainId])) {
                    $domainId = (int) $keitaroDomainIdToOrbitraDomainId[$kDomainId];
                }

                $kGroupId = (int) ($r['group_id'] ?? 0);
                $groupId = null;
                if ($kGroupId > 0 && isset($keitaroCampaignGroupIdToOrbitraId[$kGroupId])) {
                    $groupId = (int) $keitaroCampaignGroupIdToOrbitraId[$kGroupId];
                }

                $costModel = orbitraKeitaroMapCostModel((string) ($r['cost_type'] ?? 'CPC'));
                $costValue = (float) ($r['cost_value'] ?? 0.0);
                $uniquenessMethod = orbitraKeitaroMapUniquenessMethod((string) ($r['uniqueness_method'] ?? 'ip'));
                $uniquenessHours = (int) ($r['cookies_ttl'] ?? 24);
                if ($uniquenessHours <= 0) $uniquenessHours = 24;

                $sourceId = null;
                $kSourceId = (int) ($r['traffic_source_id'] ?? 0);
                if ($kSourceId > 0) {
                    if (!isset($keitaroTrafficSourceIdToOrbitraId[$kSourceId]) && isset($dbTrafficSourcesByKeitaroId[$kSourceId])) {
                        $keitaroTrafficSourceIdToOrbitraId[$kSourceId] = (int) $dbTrafficSourcesByKeitaroId[$kSourceId];
                    }
                    if (isset($keitaroTrafficSourceIdToOrbitraId[$kSourceId])) {
                        $sourceId = (int) $keitaroTrafficSourceIdToOrbitraId[$kSourceId];
                    }
                }

                $stmtFindByAlias->execute([$alias]);
                $existing = $stmtFindByAlias->fetch(PDO::FETCH_ASSOC);
                $existingId = (int) (($existing['id'] ?? 0) ?: 0);
                if ($existingId > 0) {
                    $keitaroCampaignIdToOrbitraId[$kid] = $existingId;
                    // If campaign already exists, we can still fill in missing domain_id and keitaro_id safely.
                    if ($domainId !== null) {
                        $stmtUpdDomain->execute([(int) $domainId, $existingId]);
                    }
                    if ($stmtUpdK && $kid > 0) {
                        $stmtUpdK->execute([$kid, $existingId]);
                    }
                    $result['imported']['campaigns']['skipped']++;
                    continue;
                }

                $stmtIns->execute([
                    $name,
                    $alias,
                    $domainId,
                    $groupId,
                    $sourceId,
                    $costModel,
                    $costValue,
                    $uniquenessMethod,
                    $uniquenessHours,
                ]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($kid > 0 && $oid > 0) {
                    $keitaroCampaignIdToOrbitraId[$kid] = $oid;
                    if ($stmtUpdK) {
                        $stmtUpdK->execute([$kid, $oid]);
                    }
                }
                $result['imported']['campaigns']['inserted']++;

                if ($kDomainId > 0 && $domainId === null) {
                    $result['warnings'][] = "Campaign '{$alias}': keitaro domain_id={$kDomainId} was not mapped (domain not found in import)";
                }
            }
        }

            // ---- Streams (flows) ----
            if ($doStreams) {
                $rows = $parsed['keitaro_streams']['rows'] ?? [];
                $offerAssoc = $parsed['keitaro_stream_offer_associations']['rows'] ?? [];
                $landingAssoc = $parsed['keitaro_stream_landing_associations']['rows'] ?? [];
                $filtersRows = $parsed['keitaro_stream_filters']['rows'] ?? [];

                // Index associations by stream_id for fast lookup.
                $offersByStream = [];
                foreach ($offerAssoc as $a) {
                    $sid = (int) ($a['stream_id'] ?? ($a['streamId'] ?? 0));
                    $oid = (int) ($a['offer_id'] ?? ($a['offerId'] ?? 0));
                    if ($sid <= 0 || $oid <= 0) continue;
                    $w = (int) ($a['share'] ?? ($a['weight'] ?? ($a['percentage'] ?? 100)));
                    if ($w <= 0) $w = 100;
                    $offersByStream[$sid][] = ['offer_id' => $oid, 'weight' => $w];
                }
                $landingsByStream = [];
                foreach ($landingAssoc as $a) {
                    $sid = (int) ($a['stream_id'] ?? ($a['streamId'] ?? 0));
                    $lid = (int) ($a['landing_id'] ?? ($a['landingId'] ?? 0));
                    if ($sid <= 0 || $lid <= 0) continue;
                    $w = (int) ($a['share'] ?? ($a['weight'] ?? ($a['percentage'] ?? 100)));
                    if ($w <= 0) $w = 100;
                    $landingsByStream[$sid][] = ['landing_id' => $lid, 'weight' => $w];
                }
                $filtersByStream = [];
                foreach ($filtersRows as $f) {
                    $sid = (int) ($f['stream_id'] ?? ($f['streamId'] ?? 0));
                    if ($sid <= 0) continue;
                    $filtersByStream[$sid][] = $f;
                }

                // Prepare SQL.
                $stmtFind = null;
                if ($hasStreamKeitaroId) {
                    $stmtFind = $pdo->prepare("SELECT id FROM streams WHERE keitaro_id = ? LIMIT 1");
                } else {
                    $stmtFind = $pdo->prepare("SELECT id FROM streams WHERE campaign_id = ? AND name = ? AND position = ? LIMIT 1");
                }
                $stmtIns = $pdo->prepare("
                    INSERT INTO streams
                    (campaign_id, offer_id, name, weight, is_active, type, position, filters_json, schema_type, action_payload, schema_custom_json, keitaro_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtUpd = null;
                if ($hasStreamKeitaroId) {
                    $stmtUpd = $pdo->prepare("
                        UPDATE streams
                        SET offer_id = ?,
                            name = ?,
                            weight = ?,
                            is_active = ?,
                            type = ?,
                            position = ?,
                            filters_json = ?,
                            schema_type = ?,
                            action_payload = ?,
                            schema_custom_json = ?,
                            keitaro_id = COALESCE(keitaro_id, ?)
                        WHERE id = ?
                    ");
                } else {
                    $stmtUpd = $pdo->prepare("
                        UPDATE streams
                        SET offer_id = ?,
                            name = ?,
                            weight = ?,
                            is_active = ?,
                            type = ?,
                            position = ?,
                            filters_json = ?,
                            schema_type = ?,
                            action_payload = ?,
                            schema_custom_json = ?
                        WHERE id = ?
                    ");
                }

                foreach ($rows as $r) {
                    $kStreamId = (int) ($r['id'] ?? 0);
                    $kCampaignId = (int) ($r['campaign_id'] ?? 0);
                    if ($kCampaignId <= 0) continue;

                    if (!isset($keitaroCampaignIdToOrbitraId[$kCampaignId]) && isset($dbCampaignsByKeitaroId[$kCampaignId])) {
                        $keitaroCampaignIdToOrbitraId[$kCampaignId] = (int) $dbCampaignsByKeitaroId[$kCampaignId];
                    }
                    $campaignId = $keitaroCampaignIdToOrbitraId[$kCampaignId] ?? null;
                    if (!$campaignId) {
                        $result['warnings'][] = "Stream {$kStreamId}: keitaro campaign_id={$kCampaignId} not mapped";
                        continue;
                    }

                    $name = trim((string) ($r['name'] ?? ($r['title'] ?? '')));
                    if ($name === '') $name = 'Stream ' . ($kStreamId > 0 ? $kStreamId : '');

                    $position = (int) ($r['position'] ?? 0);
                    $weight = (int) ($r['weight'] ?? 100);
                    if ($weight <= 0) $weight = 100;

                    $state = strtolower(trim((string) ($r['state'] ?? ($r['status'] ?? 'active'))));
                    $isActive = ($state === '' || $state === 'active' || $state === 'enabled' || $state === 'on') ? 1 : 0;

                    $kType = strtolower(trim((string) ($r['type'] ?? 'regular')));
                    // Keitaro stream.type: regular | default | forced (varies by version)
                    // Orbitra stream.type: regular | fallback | intercepting
                    $kTypeNorm = $kType;
                    if ($kTypeNorm === 'default') $kTypeNorm = 'fallback';
                    if ($kTypeNorm === 'forced') $kTypeNorm = 'intercepting';
                    $type = in_array($kTypeNorm, ['regular', 'fallback', 'intercepting'], true) ? $kTypeNorm : 'regular';

                    // Keitaro "default" streams are evaluated last even if position=1. Put them at the end of the list.
                    if ($type === 'fallback') {
                        $position = 1000000 + max(0, $position);
                    }

                    $filters = orbitraKeitaroBuildOrbitraFilters($filtersByStream[$kStreamId] ?? []);
                    if ($type === 'regular') {
                        foreach ($filters as $ff) {
                            if (($ff['name'] ?? '') === 'Bot' && ($ff['mode'] ?? '') === 'include') {
                                $type = 'intercepting';
                                break;
                            }
                        }
                    }

                    // Build schema from associations.
                    $schemaOffers = [];
                    foreach (($offersByStream[$kStreamId] ?? []) as $a) {
                        $kOfferId = (int) ($a['offer_id'] ?? 0);
                        if ($kOfferId <= 0) continue;
                        if (!isset($keitaroOfferIdToOrbitraOfferId[$kOfferId]) && isset($dbOffersByKeitaroId[$kOfferId])) {
                            $keitaroOfferIdToOrbitraOfferId[$kOfferId] = (int) $dbOffersByKeitaroId[$kOfferId];
                        }
                        $oid = $keitaroOfferIdToOrbitraOfferId[$kOfferId] ?? null;
                        if ($oid) {
                            $schemaOffers[] = ['id' => (int) $oid, 'weight' => (int) ($a['weight'] ?? 100)];
                        }
                    }
                    $schemaLandings = [];
                    foreach (($landingsByStream[$kStreamId] ?? []) as $a) {
                        $kLandingId = (int) ($a['landing_id'] ?? 0);
                        if ($kLandingId <= 0) continue;
                        if (!isset($keitaroLandingIdToOrbitraId[$kLandingId]) && isset($dbLandingsByKeitaroId[$kLandingId])) {
                            $keitaroLandingIdToOrbitraId[$kLandingId] = (int) $dbLandingsByKeitaroId[$kLandingId];
                        }
                        $lid = $keitaroLandingIdToOrbitraId[$kLandingId] ?? null;
                        if ($lid) {
                            $schemaLandings[] = ['id' => (int) $lid, 'weight' => (int) ($a['weight'] ?? 100)];
                        }
                    }

                    $schemaType = 'redirect';
                    $actionPayload = null;
                    $custom = [];

                    $kSchema = strtolower(trim((string) ($r['schema'] ?? '')));
                    $kActionType = strtolower(trim((string) ($r['action_type'] ?? ($r['action'] ?? ''))));

                    // Keitaro action streams: schema='action', action_type often looks like 'status404'.
                    $isActionStream = ($kSchema === 'action')
                        || (strpos($kActionType, '404') !== false)
                        || in_array($kActionType, ['404', 'not_found', 'notfound', 'http_404'], true);

                    if ($isActionStream) {
                        $schemaType = 'action';
                        if (strpos($kActionType, '404') !== false) {
                            $actionPayload = 'not_found';
                        } else if (strpos($kActionType, 'html') !== false) {
                            $actionPayload = 'show_html';
                        } else {
                            // Keep "action" branch, but do nothing.
                            $actionPayload = 'do_nothing';
                        }
                    }

                    if ($schemaType !== 'action') {
                        if (!empty($schemaLandings)) {
                            $schemaType = 'landing_offer';
                            $custom['landings'] = $schemaLandings;
                            $custom['offers'] = $schemaOffers;
                        } else {
                            $schemaType = 'redirect';
                            $custom['offers'] = $schemaOffers;
                        }
                    }

                    $filtersJson = json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $customJson = json_encode($custom, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    // Try to set legacy offer_id too (for backward compatibility).
                    $legacyOfferId = null;
                    if (!empty($schemaOffers)) {
                        $legacyOfferId = (int) ($schemaOffers[0]['id'] ?? 0) ?: null;
                    }

                    $existingStreamId = null;
                    if ($hasStreamKeitaroId) {
                        $stmtFind->execute([$kStreamId]);
                        $existingStreamId = (int) ($stmtFind->fetchColumn() ?: 0);
                    } else {
                        $stmtFind->execute([(int) $campaignId, $name, $position]);
                        $existingStreamId = (int) ($stmtFind->fetchColumn() ?: 0);
                    }
                    if ($existingStreamId > 0) {
                        // Keep import idempotent: re-running import updates the existing stream.
                        if ($hasStreamKeitaroId) {
                            $stmtUpd->execute([
                                $legacyOfferId,
                                $name,
                                $weight,
                                $isActive,
                                $type,
                                $position,
                                $filtersJson,
                                $schemaType,
                                $actionPayload,
                                $customJson,
                                $kStreamId > 0 ? $kStreamId : null,
                                $existingStreamId,
                            ]);
                        } else {
                            $stmtUpd->execute([
                                $legacyOfferId,
                                $name,
                                $weight,
                                $isActive,
                                $type,
                                $position,
                                $filtersJson,
                                $schemaType,
                                $actionPayload,
                                $customJson,
                                $existingStreamId,
                            ]);
                        }
                        $result['imported']['streams']['updated']++;
                        continue;
                    }

                    $stmtIns->execute([
                        (int) $campaignId,
                        $legacyOfferId,
                        $name,
                        $weight,
                        $isActive,
                        $type,
                        $position,
                        $filtersJson,
                        $schemaType,
                        $actionPayload,
                        $customJson,
                        $kStreamId > 0 ? $kStreamId : null,
                    ]);
                    $result['imported']['streams']['inserted']++;
                }
            }

        // ---- Campaign postbacks ----
        if ($doCampaignPostbacks) {
            $rows = $parsed['keitaro_campaign_postbacks']['rows'] ?? [];
            $stmtFind = $pdo->prepare("SELECT id FROM campaign_postbacks WHERE campaign_id = ? AND url = ? LIMIT 1");
            $stmtIns = $pdo->prepare("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses) VALUES (?, ?, ?, ?)");

            foreach ($rows as $r) {
                $kCampaignId = (int) ($r['campaign_id'] ?? ($r['campaignId'] ?? 0));
                if ($kCampaignId <= 0) continue;
                $campaignId = $keitaroCampaignIdToOrbitraId[$kCampaignId] ?? null;
                if (!$campaignId) {
                    $result['warnings'][] = "Postback: keitaro campaign_id={$kCampaignId} was not mapped (campaign not found/imported)";
                    continue;
                }

                $url = trim((string) ($r['url'] ?? ($r['postback_url'] ?? '')));
                if ($url === '') continue;
                $method = strtoupper(trim((string) ($r['method'] ?? 'GET')));
                if ($method === '') $method = 'GET';
                $statuses = (string) ($r['statuses'] ?? 'lead,sale,rejected');
                if ($statuses === '') $statuses = 'lead,sale,rejected';

                $stmtFind->execute([(int) $campaignId, $url]);
                if ($stmtFind->fetchColumn()) {
                    $result['imported']['campaign_postbacks']['skipped']++;
                    continue;
                }

                $stmtIns->execute([(int) $campaignId, $url, $method, $statuses]);
                $result['imported']['campaign_postbacks']['inserted']++;
            }
        }

        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
