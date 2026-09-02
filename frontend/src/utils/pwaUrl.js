// Single source of truth for a PWA landing's public address — the URL an
// operator pastes into an ad campaign.
//
// The path is ALWAYS /lander/<slug>/: it pins the link to THIS app. The bare
// domain root is useless for a campaign — it serves whatever PWA is bound at
// the moment (rebinding silently repoints every pasted link), and a domain
// without a binding shows no app at all. With a bound domain the link rides
// it (https://<domain>/lander/<slug>/); without one it falls back to the
// panel origin — the *server* address, fine for a look, not for traffic.
//
// The editor footer, its copy button and the Landings row menu all build the
// link here, or the copied URL and the one the operator clicks drift apart.
export function pwaLandingUrl(slug, id, domainName) {
    const tail = slug ? encodeURIComponent(slug) : (id ? String(id) : '');
    if (!tail) return '';
    if (domainName) {
        return `https://${domainName}/lander/${tail}/`;
    }
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    return `${origin}/lander/${tail}/`;
}

// The look-see address — what "Open preview" means. It is deliberately NOT
// the campaign link above: a freshly bound domain may have no SSL yet or its
// DNS may still point elsewhere, and the operator clicking preview would land
// on an error page instead of their app. The panel itself serves the generated
// statics at /lander/<slug>/ unconditionally, so the preview always works.
// The _preview stamp busts a cached index.html so the preview shows the
// latest render, not whatever the browser kept from the last look.
export function pwaPreviewUrl(slug, id) {
    const tail = slug ? encodeURIComponent(slug) : (id ? String(id) : '');
    if (!tail) return '';
    return `/lander/${tail}/?_preview=${Date.now()}`;
}

