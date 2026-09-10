<?php
// Runtime offer destinations shared by the tracker and both Click APIs.
// Keep offers.url as entered: changing a network must affect existing offers
// without rewriting their URLs or persisting visitor-specific macro values.

function orbitraGetOfferDestination(PDO $pdo, int $offerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT o.url, o.redirect_type, o.is_local, n.offer_params
         FROM offers o
         LEFT JOIN affiliate_networks n ON n.id = o.affiliate_network_id
         WHERE o.id = ? LIMIT 1'
    );
    $stmt->execute([$offerId]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$offer) {
        return null;
    }

    // Network state is not a traffic-routing switch. Use the association as
    // stored; the archive/delete handlers detach offers themselves.
    if (empty($offer['is_local'])) {
        $offer['url'] = orbitraAppendOfferParams(
            (string) ($offer['url'] ?? ''),
            (string) ($offer['offer_params'] ?? '')
        );
    }
    return $offer;
}

/** Append network defaults without decoding/rebuilding the destination URL. */
function orbitraAppendOfferParams(string $url, string $params): string
{
    $params = trim($params);
    if ($url === '' || $params === '') {
        return $url;
    }

    // Local paths and non-HTTP destinations do not have an affiliate query.
    // Scheme-less hosts and protocol-relative URLs follow the tracker's usual
    // URL rules. Do not reinterpret full LeadForge endpoints as URL suffixes.
    if (preg_match('~^(?:/(?!/)|\./|\.\./|[?#])~', $url)
        || (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)
            && !preg_match('~^https?://~i', $url)
            && !preg_match('~^[a-z0-9.-]+:\d+(?:[/?#]|$)~i', $url))
        || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', ltrim($params, '?&'))
        || preg_match('~[\x00-\x1F\x7F#]~', $params)) {
        return $url;
    }

    // A fragment belongs after the complete query. Keep every original byte,
    // including escaped values, duplicate keys, empty values and delimiters.
    $fragmentAt = strpos($url, '#');
    $fragment = $fragmentAt === false ? '' : substr($url, $fragmentAt);
    $destination = $fragmentAt === false ? $url : substr($url, 0, $fragmentAt);

    // Some bundled network templates use /{subid} or /{source}/{subid}.
    // Insert those at the end of the path, before an existing query/fragment.
    if ($params[0] === '/') {
        $suffixParts = explode('?', $params, 2);
        $pathSuffix = $suffixParts[0];
        $queryAt = strpos($destination, '?');
        $path = $queryAt === false ? $destination : substr($destination, 0, $queryAt);
        $query = $queryAt === false ? '' : substr($destination, $queryAt);
        if (!str_ends_with($path, $pathSuffix)) {
            $path .= str_ends_with($path, '/') ? substr($pathSuffix, 1) : $pathSuffix;
        }
        $destination = $path . $query;
        $params = $suffixParts[1] ?? '';
    }

    $params = ltrim($params, '?&');
    $queryAt = strpos($destination, '?');
    $existingKeys = [];
    if ($queryAt !== false) {
        foreach (explode('&', substr($destination, $queryAt + 1)) as $part) {
            $existingKeys[urldecode(explode('=', $part, 2)[0])] = true;
        }
    }

    $additional = [];
    foreach (explode('&', $params) as $part) {
        $key = urldecode(explode('=', $part, 2)[0]);
        if ($key !== '' && !isset($existingKeys[$key])) {
            // Compare against the offer's keys only: repeated network values
            // (e.g. tags[]=a&tags[]=b) are intentional and remain in order.
            $additional[] = $part;
        }
    }
    if ($additional) {
        $separator = $queryAt === false ? '?' : (preg_match('~[?&]$~', $destination) ? '' : '&');
        $destination .= $separator . implode('&', $additional);
    }
    return $destination . $fragment;
}

/** Resolve an offer after composition, before embedding it in a landing URL. */
function orbitraResolveOfferUrlMacros($url, $clickId, $offerId, $params, array $context = []): string
{
    $url = str_replace(['{clickid}', '{subid}'], [$clickId, $clickId], (string) $url);
    if (isset($context['ip'])) {
        $url = str_replace('{ip}', urlencode((string) $context['ip']), $url);
    }
    if (isset($context['country'])) {
        $url = str_replace('{country}', urlencode((string) $context['country']), $url);
    }
    if (!empty($params) && is_array($params)) {
        foreach ($params as $key => $val) {
            $url = str_replace('{' . $key . '}', urlencode((string) $val), $url);
        }
    }
    if ($offerId) {
        $url = str_replace('{offer_id}', (string) $offerId, $url);
    }
    // Preserve the existing tracker policy for unavailable macros.
    $url = preg_replace('#\{[a-zA-Z0-9_]+\}#', '', (string) $url);
    if ($url !== '' && !preg_match('#^(https?:)?//#i', $url) && !preg_match('#^/#', $url) && !preg_match('#^(mailto|tel):#i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }
    return $url;
}
