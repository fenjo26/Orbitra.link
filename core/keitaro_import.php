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

function orbitraKeitaroImportSqlDump(PDO $pdo, string $path, array $opts = []): array
{
    $dryRun = !empty($opts['dry_run']);
    $doDomains = array_key_exists('import_domains', $opts) ? (bool) $opts['import_domains'] : true;
    $doOffers = array_key_exists('import_offers', $opts) ? (bool) $opts['import_offers'] : true;
    $doCompanies = array_key_exists('import_companies', $opts) ? (bool) $opts['import_companies'] : true;
    $doCampaigns = array_key_exists('import_campaigns', $opts) ? (bool) $opts['import_campaigns'] : false;
    $doCampaignPostbacks = array_key_exists('import_campaign_postbacks', $opts) ? (bool) $opts['import_campaign_postbacks'] : false;

    $tablesToParse = [];
    if ($doCompanies) $tablesToParse[] = 'keitaro_affiliate_networks';
    if ($doOffers) {
        $tablesToParse[] = 'keitaro_groups';
        $tablesToParse[] = 'keitaro_offers';
    }
    if ($doDomains) $tablesToParse[] = 'keitaro_domains';
    if ($doCampaigns) {
        $tablesToParse[] = 'keitaro_campaigns';
        // campaign groups can also live in keitaro_groups (type='campaign' on some installs)
        $tablesToParse[] = 'keitaro_groups';
    }
    if ($doCampaignPostbacks) {
        $tablesToParse[] = 'keitaro_campaign_postbacks';
        // needs campaigns mapping
        $tablesToParse[] = 'keitaro_campaigns';
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
            'campaign_groups' => ['inserted' => 0, 'skipped' => 0],
            'campaigns' => ['inserted' => 0, 'skipped' => 0],
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

    $pdo->beginTransaction();
    try {
        // ---- Domains (needed by campaigns) ----
        $keitaroDomainIdToOrbitraDomainId = [];
        if ($doDomains) {
            $rows = $parsed['keitaro_domains']['rows'] ?? [];
            $stmtIns = $pdo->prepare("
                INSERT OR IGNORE INTO domains
                (name, index_campaign_id, catch_404, group_id, is_noindex, https_only)
                VALUES (?, NULL, ?, NULL, ?, ?)
            ");
            $stmtFind = $pdo->prepare("SELECT id FROM domains WHERE name = ? LIMIT 1");

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;

                $catch404 = (int) ($r['catch_not_found'] ?? 0) ? 1 : 0;
                $httpsOnly = (int) ($r['is_ssl'] ?? 0) ? 1 : 0;
                $allowIndexing = (int) ($r['allow_indexing'] ?? 1) ? 1 : 0;
                $isNoindex = $allowIndexing ? 0 : 1;

                $stmtIns->execute([$name, $catch404, $isNoindex, $httpsOnly]);
                $stmtFind->execute([$name]);
                $oid = (int) ($stmtFind->fetchColumn() ?: 0);
                if ($kid > 0 && $oid > 0) {
                    $keitaroDomainIdToOrbitraDomainId[$kid] = $oid;
                }

                if ($stmtIns->rowCount() > 0) {
                    $result['imported']['domains']['inserted']++;
                } else {
                    if ($oid > 0) $result['imported']['domains']['skipped']++;
                }
            }
        }

        // ---- Companies: affiliate networks ----
        $keitaroAffiliateIdToOrbitraId = [];
        if ($doCompanies) {
            $rows = $parsed['keitaro_affiliate_networks']['rows'] ?? [];
            $stmtFind = $pdo->prepare("SELECT id, postback_url, offer_params FROM affiliate_networks WHERE is_archived = 0 AND name = ? LIMIT 1");
            $stmtIns = $pdo->prepare("INSERT INTO affiliate_networks (name, template, offer_params, postback_url, notes, state) VALUES (?, ?, ?, ?, ?, ?)");

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
                }
                $result['imported']['affiliate_networks']['inserted']++;
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
            $stmtFind = $pdo->prepare("SELECT id FROM offers WHERE is_archived = 0 AND name = ? AND COALESCE(url,'') = COALESCE(?, '') LIMIT 1");
            $stmtIns = $pdo->prepare("
                INSERT INTO offers
                (name, group_id, affiliate_network_id, url, redirect_type, is_local, geo, payout_type, payout_value, payout_auto, allow_rebills, capping_limit, capping_timezone, alt_offer_id, notes, values_json, state)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($rows as $r) {
                $kid = (int) ($r['id'] ?? 0);
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;

                $kGroupId = (int) ($r['group_id'] ?? 0);
                if ($kGroupId <= 0) $kGroupId = 0;
                $groupId = ($kGroupId > 0 && isset($keitaroGroupIdToOfferGroupId[$kGroupId])) ? (int) $keitaroGroupIdToOfferGroupId[$kGroupId] : null;

                $kAffId = (int) ($r['affiliate_network_id'] ?? 0);
                if ($kAffId <= 0) $kAffId = 0;
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
                $existingId = $stmtFind->fetchColumn();
                if ($existingId) {
                    if ($kid > 0) $keitaroOfferIdToOrbitraOfferId[$kid] = (int) $existingId;
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
            $stmtFindByAlias = $pdo->prepare("SELECT id FROM campaigns WHERE is_archived = 0 AND alias = ? LIMIT 1");
            $stmtIns = $pdo->prepare("
                INSERT INTO campaigns
                (name, alias, domain_id, group_id, source_id, cost_model, cost_value, uniqueness_method, uniqueness_hours, catch_404_stream_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
            ");

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

                $sourceId = null; // requires keitaro_traffic_sources dump + importer (not in this migration)

                $stmtFindByAlias->execute([$alias]);
                $existingId = $stmtFindByAlias->fetchColumn();
                if ($existingId) {
                    $keitaroCampaignIdToOrbitraId[$kid] = (int) $existingId;
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
                }
                $result['imported']['campaigns']['inserted']++;

                if ($kDomainId > 0 && $domainId === null) {
                    $result['warnings'][] = "Campaign '{$alias}': keitaro domain_id={$kDomainId} was not mapped (domain not found in import)";
                }
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
