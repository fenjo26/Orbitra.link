<?php
/**
 * CRM Anti-Shaving Vault.
 *
 * The conversions table answers "what did this click earn". It cannot answer
 * "what exactly did we send the network, and what did the network say back" —
 * which is the evidence an operator needs when a network rejects a lead as a
 * "bad number" or quietly drops it. crm_leads is that evidence: the raw phone
 * as typed, the E.164 phone actually delivered, every tracking sub-param and
 * UTM/ad attribution field, and the request/response dump of the CPA call.
 *
 * Three entry paths share this code:
 *   - in-process: a generated order.php running inside index.php calls
 *     orbitraCrmRecordLead() directly (no HTTP, no auth surface);
 *   - standalone: a landing ZIP deployed on foreign hosting POSTs the same
 *     payload to the public /crm-ingest route, which lands here as well;
 *   - the panel's manual "new lead" form (api.php action=crm_lead).
 */

/** ISO country code → international dial code, the GEOs LeadForge ships masks for. */
function orbitraGeoDialCodes(): array
{
    return [
        'IT' => '39', 'ES' => '34', 'DE' => '49', 'FR' => '33', 'PL' => '48',
        'RO' => '40', 'GR' => '30', 'RU' => '7', 'UA' => '380', 'KZ' => '7',
        'US' => '1', 'CA' => '1', 'MX' => '52', 'CO' => '57', 'BR' => '55',
        'CZ' => '420', 'PT' => '351', 'HU' => '36', 'BG' => '359', 'ID' => '62',
        'TH' => '66', 'VN' => '84', 'IN' => '91', 'PH' => '63', 'MY' => '60',
    ];
}

/**
 * Normalize a phone number as typed by a visitor into E.164.
 *
 * "+39 333 123 4567"   → "+393331234567"
 * "3331234567" (IT)    → "+393331234567"
 * "00393331234567"     → "+393331234567"
 *
 * Deliberately defensive rather than clever: a wrong-but-plausible guess can
 * never beat the operator's original input, which stays in raw_phone.
 */
function orbitraNormalizePhoneE164(string $rawPhone, string $geo): string
{
    $digits = preg_replace('/\D+/', '', $rawPhone);
    if ($digits === '' || strlen($digits) < 4) {
        return '';
    }
    $geo = strtoupper(trim($geo));
    $dial = orbitraGeoDialCodes()[$geo] ?? '';

    if (strncmp($rawPhone, '+', 1) === 0) {
        return '+' . substr($digits, 0, 15);
    }
    if (strncmp($digits, '00', 2) === 0) {
        return '+' . substr($digits, 2, 15);
    }
    if ($dial !== '' && strncmp($digits, $dial, strlen($dial)) === 0 && strlen($digits) > strlen($dial)) {
        return '+' . substr($digits, 0, 15);
    }
    if ($dial !== '') {
        $full = $dial . $digits;
        return '+' . substr($full, 0, 15);
    }
    return '+' . substr($digits, 0, 15);
}

/** A clean_phone is "provably valid" when it is a well-formed E.164 number. */
function orbitraPhoneLooksValidE164(string $phone): bool
{
    return (bool) preg_match('/^\+\d{7,15}$/', $phone);
}

/** Pick the first present key of an input array (callers send naming variants). */
function orbitraCrmPick(array $src, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
            return $src[$k];
        }
    }
    return $default;
}

/**
 * Store one lead snapshot. Also upserts the click's NULL-tid conversion the
 * way postback.php does (so a CRM-synced lead counts exactly once), unless
 * the lead is flagged is_qa_test — QA leads must not touch analytics.
 *
 * Accepts naming variants from the three callers: click_id / subid /
 * lf_click_id, network_request / network_request_dump, and so on.
 *
 * @param bool $allowCreateClick create a placeholder click for an unknown
 *                               subid (panel-side manual creation); the public
 *                               ingest route never does this
 * @return array ['ok'=>bool, 'message'=>string, 'lead_id'=>int|null, 'conversion'=>bool, 'is_duplicate'=>bool]
 */
function orbitraCrmRecordLead(PDO $pdo, array $lead, bool $allowCreateClick = false): array
{
    $clickId = trim((string) orbitraCrmPick($lead, ['click_id', 'subid', 'lf_click_id']));
    if ($clickId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clickId)) {
        return ['ok' => false, 'message' => 'Valid click_id (subid) required', 'lead_id' => null, 'conversion' => false, 'is_duplicate' => false];
    }
    $campaignId = (int) ($lead['campaign_id'] ?? 0);
    $landerId = (int) orbitraCrmPick($lead, ['lander_id', 'landing_id'], 0);
    $isQa = !empty($lead['is_qa_test']);

    $network = strtolower(substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) orbitraCrmPick($lead, ['network'], 'custom')), 0, 32));
    if ($network === '') {
        $network = 'custom';
    }
    $status = strtolower(trim((string) orbitraCrmPick($lead, ['status'], 'lead')));
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $status)) {
        $status = 'lead';
    }
    $geo = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($lead['geo'] ?? '')), 0, 8));

    $rawPhone = substr((string) ($lead['raw_phone'] ?? ''), 0, 64);
    $cleanPhone = trim((string) orbitraCrmPick($lead, ['clean_phone']));
    if ($cleanPhone === '') {
        $cleanPhone = orbitraNormalizePhoneE164($rawPhone, $geo);
    }

    // Full attribution set. Top-level UTM/ad fields are the contract the QA
    // report and the inspector read; anything else rides in sub_data.
    $utm = [
        'utm_source'    => substr((string) orbitraCrmPick($lead, ['utm_source']), 0, 128),
        'utm_campaign'  => substr((string) orbitraCrmPick($lead, ['utm_campaign']), 0, 128),
        'utm_placement' => substr((string) orbitraCrmPick($lead, ['utm_placement']), 0, 128),
        'adset_id'      => substr((string) orbitraCrmPick($lead, ['adset_id']), 0, 64),
        'adset_name'    => substr((string) orbitraCrmPick($lead, ['adset_name']), 0, 128),
        'ad_id'         => substr((string) orbitraCrmPick($lead, ['ad_id']), 0, 64),
        'ad_name'       => substr((string) orbitraCrmPick($lead, ['ad_name']), 0, 128),
    ];
    $subData = is_array($lead['sub_data'] ?? null) ? $lead['sub_data'] : [];
    $netRequest = orbitraCrmPick($lead, ['network_request', 'network_request_dump'], []);
    $netResponse = orbitraCrmPick($lead, ['network_response', 'network_response_dump'], []);
    $netRequest = is_array($netRequest) ? $netRequest : [];
    $netResponse = is_array($netResponse) ? $netResponse : [];

    // Campaign attribution: the click wins, because that is what reports join on.
    try {
        $stmt = $pdo->prepare("SELECT id, campaign_id, landing_id FROM clicks WHERE id = ? LIMIT 1");
        $stmt->execute([$clickId]);
        $click = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $click = false;
    }
    if ($click) {
        if ((int) $click['campaign_id'] > 0) {
            $campaignId = (int) $click['campaign_id'];
        }
        if ($landerId <= 0 && !empty($click['landing_id'])) {
            $landerId = (int) $click['landing_id'];
        }
    } elseif ($allowCreateClick && $campaignId > 0) {
        try {
            $chk = $pdo->prepare("SELECT id FROM campaigns WHERE id = ? LIMIT 1");
            $chk->execute([$campaignId]);
            if (!$chk->fetchColumn()) {
                return ['ok' => false, 'message' => 'Campaign not found', 'lead_id' => null, 'conversion' => false, 'is_duplicate' => false];
            }
            $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, country, country_code, device_type, os, browser, language, accept_language_raw, parameters_json)
                           VALUES (?, ?, ?, ?, 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'Unknown', 'Unknown', '{}')")
                ->execute([$clickId, $campaignId, substr((string) ($lead['ip'] ?? ''), 0, 45), substr((string) ($lead['user_agent'] ?? ''), 0, 255)]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not stage click: ' . $e->getMessage(), 'lead_id' => null, 'conversion' => false, 'is_duplicate' => false];
        }
    }

    // Duplicate heuristics: same E.164 number on the same network inside 30
    // days. QA rows never participate — every QA run sends the same number.
    $isDuplicate = 0;
    if (!$isQa && $cleanPhone !== '') {
        try {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM crm_leads
                                  WHERE clean_phone = ? AND network = ? AND is_qa_test = 0
                                    AND created_at >= datetime('now', '-30 days')");
            $dup->execute([$cleanPhone, $network]);
            $isDuplicate = ((int) $dup->fetchColumn()) > 0 ? 1 : 0;
        } catch (\Throwable $e) {
        }
    }

    $networkLeadIdSrc = (string) orbitraCrmPick($lead, ['network_lead_id']);
    if ($networkLeadIdSrc === '') {
        $networkLeadIdSrc = (string) ($netResponse['network_lead_id'] ?? '');
    }
    $networkLeadId = substr(trim($networkLeadIdSrc), 0, 128);
    $currency = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) orbitraCrmPick($lead, ['currency'], 'USD')), 0, 3)) ?: 'USD';
    $payout = max(0.0, (float) ($lead['payout'] ?? 0));
    $price = isset($lead['price']) && $lead['price'] !== '' ? (float) $lead['price'] : 0.0;
    $statusReason = substr((string) orbitraCrmPick($lead, ['status_reason', 'reason']), 0, 500);
    $statusSource = strtolower(substr(preg_replace('/[^a-z0-9_-]/', '', (string) orbitraCrmPick($lead, ['status_source'], 'form_submit')), 0, 32)) ?: 'form_submit';

    try {
        $stmt = $pdo->prepare("INSERT INTO crm_leads
            (click_id, campaign_id, lander_id, offer_id, network, network_lead_id, product, customer_name,
             raw_phone, clean_phone, price, payout, currency, geo, ip, user_agent,
             utm_source, utm_campaign, utm_placement, adset_id, adset_name, ad_id, ad_name,
             sub_data_json, network_request_json, network_response_json,
             status, status_reason, status_source, is_qa_test, is_duplicate, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))");
        $stmt->execute([
            $clickId, $campaignId, $landerId,
            substr(trim((string) orbitraCrmPick($lead, ['offer_id'])), 0, 64),
            $network, $networkLeadId,
            substr(trim((string) orbitraCrmPick($lead, ['product'])), 0, 128),
            substr((string) orbitraCrmPick($lead, ['customer_name', 'name']), 0, 255),
            $rawPhone, $cleanPhone, $price, $payout, $currency, $geo,
            substr((string) ($lead['ip'] ?? ''), 0, 45),
            substr((string) ($lead['user_agent'] ?? ''), 0, 512),
            $utm['utm_source'], $utm['utm_campaign'], $utm['utm_placement'],
            $utm['adset_id'], $utm['adset_name'], $utm['ad_id'], $utm['ad_name'],
            json_encode($subData, JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode($netRequest, JSON_UNESCAPED_UNICODE) ?: '{}',
            json_encode($netResponse, JSON_UNESCAPED_UNICODE) ?: '{}',
            $status, $statusReason, $statusSource, $isQa ? 1 : 0, $isDuplicate,
        ]);
        $leadRowId = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => 'Vault write failed: ' . $e->getMessage(), 'lead_id' => null, 'conversion' => false, 'is_duplicate' => (bool) $isDuplicate];
    }

    // The tracker-side conversion. QA leads stay out of analytics entirely.
    $madeConversion = false;
    if (!$isQa) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL");
            $stmt->execute([$clickId]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $pdo->prepare("UPDATE conversions SET status = ?, payout = ?, currency = ?, updated_at = datetime('now') WHERE id = ?")
                    ->execute([$status, $payout, $currency, (int) $existing]);
                $madeConversion = true;
            } else {
                $pdo->prepare("INSERT INTO conversions (click_id, status, payout, currency, campaign_id, ip, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))")
                    ->execute([$clickId, $status, $payout, $currency, $campaignId, substr((string) ($lead['ip'] ?? ''), 0, 45)]);
                $madeConversion = true;
            }
        } catch (\Throwable $e) {
            // The vault row is the evidence of record; a failed conversion
            // counter must not fail the lead. The thank-you pixel's postback
            // will retry the same upsert.
        }
    }

    return ['ok' => true, 'message' => 'stored', 'lead_id' => $leadRowId, 'conversion' => $madeConversion, 'is_duplicate' => (bool) $isDuplicate];
}

/**
 * S2S reconciliation: a postback from the network moves every CRM row of that
 * click to the network's verdict, and runs the shave heuristic.
 *
 * A lead is marked suspect when the network rejected or trashed it even though
 * (a) the phone we delivered was a well-formed E.164 number, and (b) the
 * network's API had accepted the payload with HTTP 200. Either claim being
 * false leaves shave_suspect alone — the badge must mean "we can prove both",
 * not "we are annoyed".
 */
function orbitraCrmSyncPostbackStatus(PDO $pdo, string $clickId, string $internalStatus, float $payout = 0.0, string $reason = ''): void
{
    try {
        $stmt = $pdo->prepare("SELECT id, clean_phone, network_response_json FROM crm_leads WHERE click_id = ? AND is_qa_test = 0");
        $stmt->execute([$clickId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }
        $internalStatus = strtolower(substr(preg_replace('/[^a-z0-9_-]/', '', $internalStatus), 0, 32));
        $isBadVerdict = in_array($internalStatus, ['rejected', 'trash'], true);
        $reason = substr($reason, 0, 500);

        $upd = $pdo->prepare("UPDATE crm_leads
                              SET status = ?, s2s_postback_status = ?,
                                  payout = CASE WHEN ? > 0 THEN ? ELSE payout END,
                                  status_reason = CASE WHEN ? <> '' THEN ? ELSE status_reason END,
                                  status_source = 'network_postback',
                                  updated_at = datetime('now')
                              WHERE id = ?");
        foreach ($rows as $row) {
            $suspect = 0;
            if ($isBadVerdict) {
                $resp = json_decode((string) ($row['network_response_json'] ?? ''), true);
                $httpOk = is_array($resp) && (int) ($resp['http_code'] ?? 0) === 200;
                if ($httpOk && orbitraPhoneLooksValidE164((string) ($row['clean_phone'] ?? ''))) {
                    $suspect = 1;
                }
            }
            $upd->execute([$internalStatus, $internalStatus !== '' ? $internalStatus : 'pending', $payout, $payout, $reason, $reason, (int) $row['id']]);
            if ($suspect) {
                $pdo->prepare("UPDATE crm_leads SET shave_suspect = 1 WHERE id = ?")->execute([(int) $row['id']]);
            }
        }
    } catch (\Throwable $e) {
        // Reconciliation is additive; never break the postback that pays.
    }
}
