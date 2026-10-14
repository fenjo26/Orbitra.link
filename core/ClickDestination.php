<?php
/**
 * Where a logged click was sent — the "Direction" column of the traffic log and
 * the click card, Keitaro-style.
 *
 * clicks has no column for the final URL, and the log used to show offers.url
 * as entered: "…click?pid=15&offer_id=150", without the network's
 * "&sub1={subid}" and with macros unresolved. That hid the one thing the column
 * is for — seeing that the click id really travels to the affiliate network —
 * and bot clicks, which got a stream action (404, text…), showed nothing at all.
 *
 * Read-side reconstruction from the same functions the redirect uses
 * (orbitraGetOfferDestination + orbitraResolveOfferUrlMacros), with the click's
 * own id, parameters, IP and country. It reflects the offer and network as they
 * are configured NOW: an offer URL edited after the click shows the new URL.
 *
 * Returned per click id:
 *   kind    offer | landing | action | safe_page | none
 *   url     resolved destination (offer), landing URL/name, or ''
 *   action  stream action type (not_found, show_text, show_html, do_nothing,
 *           to_campaign) when kind = action
 *   payload action payload (target campaign id for to_campaign), else ''
 */

require_once __DIR__ . '/OfferUrl.php';

if (!function_exists('orbitraClickDestinations')) {
    /**
     * @param string[] $clickIds
     * @return array<string, array{kind:string,url:string,action:string,payload:string}>
     */
    function orbitraClickDestinations(PDO $pdo, array $clickIds): array
    {
        $clickIds = array_values(array_unique(array_filter(array_map('strval', $clickIds), 'strlen')));
        if (!$clickIds) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($clickIds, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT cl.id, cl.offer_id, cl.landing_id, cl.parameters_json, cl.ip,
                        COALESCE(NULLIF(cl.country_code, ''), cl.country) AS country,
                        cl.is_safe_page, s.schema_type, s.action_payload
                 FROM clicks cl
                 LEFT JOIN streams s ON s.id = cl.stream_id
                 WHERE cl.id IN ($in)"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(string) $r['id']] = $r;
            }
            $stmt->closeCursor();
        }

        $offerCache = [];
        $landingCache = [];
        $out = [];
        foreach ($rows as $id => $r) {
            $dest = ['kind' => 'none', 'url' => '', 'action' => '', 'payload' => ''];
            $offerId = (int) ($r['offer_id'] ?? 0);

            if ($offerId > 0) {
                if (!array_key_exists($offerId, $offerCache)) {
                    try {
                        $offerCache[$offerId] = orbitraGetOfferDestination($pdo, $offerId);
                    } catch (\Throwable $e) {
                        $offerCache[$offerId] = null;
                    }
                }
                $offer = $offerCache[$offerId];
                if ($offer && (string) ($offer['url'] ?? '') !== '') {
                    $params = json_decode((string) ($r['parameters_json'] ?? ''), true);
                    $dest['kind'] = 'offer';
                    $dest['url'] = orbitraResolveOfferUrlMacros(
                        (string) $offer['url'], $id, $offerId, is_array($params) ? $params : [],
                        ['ip' => (string) ($r['ip'] ?? ''), 'country' => (string) ($r['country'] ?? '')]
                    );
                }
            } elseif (!empty($r['is_safe_page'])) {
                $dest['kind'] = 'safe_page';
            } elseif (($r['schema_type'] ?? '') === 'action') {
                $raw = trim((string) ($r['action_payload'] ?? ''));
                $type = $raw;
                $payload = '';
                if (strpos($raw, ':') !== false) {
                    [$type, $payload] = explode(':', $raw, 2);
                }
                $type = trim($type);
                // Same fallback as performTrackerAction(): unknown = legacy HTML.
                if (!in_array($type, ['not_found', 'show_text', 'show_html', 'do_nothing', 'to_campaign'], true)) {
                    $payload = $raw;
                    $type = $raw === '' ? 'do_nothing' : 'show_html';
                }
                $dest['kind'] = 'action';
                $dest['action'] = $type;
                // Only the campaign id is worth showing; text/HTML bodies are not.
                $dest['payload'] = $type === 'to_campaign' ? trim($payload) : '';
            }

            // A landing-only click (offer chosen after the landing, or none yet).
            if ($dest['kind'] === 'none' && (int) ($r['landing_id'] ?? 0) > 0) {
                $landingId = (int) $r['landing_id'];
                if (!array_key_exists($landingId, $landingCache)) {
                    $ls = $pdo->prepare('SELECT name, url FROM landings WHERE id = ? LIMIT 1');
                    $ls->execute([$landingId]);
                    $landingCache[$landingId] = $ls->fetch(PDO::FETCH_ASSOC) ?: null;
                    $ls->closeCursor();
                }
                if ($landingCache[$landingId]) {
                    $dest['kind'] = 'landing';
                    $dest['url'] = (string) ($landingCache[$landingId]['url'] ?: $landingCache[$landingId]['name']);
                }
            }
            $out[$id] = $dest;
        }
        return $out;
    }
}
