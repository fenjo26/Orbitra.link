// Mirror of CloakDetector::parseBotIspEntries() in core/CloakDetector.php.
// The bot-ISP blocklist is the Keitaro "Провайдеры" format: one provider per
// LINE, matched as a whole phrase — names carry their own punctuation
// ("Amazon.com, Inc.", "ZSCALER, INC.", "Google India's Corporate Network").
// A line splits on commas only when every segment is itself a plausible
// standalone entry (the legacy comma-separated format); spaces never split.
// The tracker silently ignores entries shorter than 3 characters and entries
// that are nothing but a generic corporate suffix, because those match nearly
// every ISP on earth. The panel uses this mirror to warn next to the list
// textareas — keep both sides in sync, or the warning and the routing rules
// drift apart.

const GENERIC_ISP_SUFFIXES = new Set([
    'inc', 'ltd', 'llc', 'limited', 'gmbh', 'corp', 'co', 'sa', 'ag',
    'bv', 'oy', 'pty', 'plc', 'network', 'services',
]);

const isIgnorableEntry = (entry) => {
    // [...entry] counts code points like mb_strlen(), not UTF-16 units.
    if ([...entry].length < 3) return true;
    // "Inc." / "LTD." are the same suffix with a trailing period.
    const probe = entry.toLowerCase().replace(/[.\s]+$/, '');
    return GENERIC_ISP_SUFFIXES.has(probe);
};

const toEntries = (raw) => {
    if (Array.isArray(raw)) {
        return raw.map(String).map((entry) => entry.trim()).filter(Boolean);
    }
    const entries = [];
    for (const line of String(raw ?? '').split(/\r\n|\r|\n/)) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        const segments = trimmed.split(',').map((segment) => segment.trim()).filter(Boolean);
        // Same rule as the PHP: commas separate entries only when no segment
        // would be dropped by the guards — otherwise they belong to the name.
        if (segments.length > 0 && segments.every((segment) => !isIgnorableEntry(segment))) {
            entries.push(...segments);
        } else {
            entries.push(trimmed);
        }
    }
    return entries;
};

/** Entries the tracker will keep and match as whole phrases. */
export const parseBotIspEntries = (raw) => {
    const seen = new Set();
    return toEntries(raw).filter((entry) => {
        if (seen.has(entry) || isIgnorableEntry(entry)) return false;
        seen.add(entry);
        return true;
    });
};

/** Entries the tracker silently ignores, in input order — for the UI warning. */
export const ignoredBotIspEntries = (raw) => toEntries(raw).filter(isIgnorableEntry);
