// Single source of truth for a PWA landing's public address — the URL an
// operator pastes into an ad campaign.
//
// A domain bound to the app (domains.pwa_landing_id) serves it from its ROOT:
// index.php resolves that binding before the campaign/stream hop, so the store
// listing is https://<domain>/ and nothing deeper. Without a binding the panel
// origin still serves it at /lander/<slug>/ — which is the *server* address,
// fine for a look but not something to put in front of traffic.
//
// The editor footer, its copy button and the Landings row menu all build the
// link here, or the copied URL and the one the operator clicks drift apart.
export function pwaLandingUrl(slug, id, domainName) {
    if (domainName) {
        return `https://${domainName}/`;
    }
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const tail = slug ? encodeURIComponent(slug) : (id ? String(id) : '');
    if (!tail) return '';
    return `${origin}/lander/${tail}/`;
}
