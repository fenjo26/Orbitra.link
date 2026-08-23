// Single source of truth for a campaign's public click URL. A campaign bound
// to a tracking domain is served from that host; without one the panel origin
// itself routes /<alias>. The editor preview and the Campaigns row menu must
// build it the same way, or the copied link and the editor drift apart.
export function campaignLinkUrl(alias, domainName) {
    const baseUrl = domainName ? `https://${domainName}` : window.location.origin;
    return `${baseUrl}/${alias}`;
}
