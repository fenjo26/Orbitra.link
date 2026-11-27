// Texts and small formats shared by every surface that shows a link check:
// the list under Notifications → Checks, the dots next to offers and
// domains, the dashboard strip and the Integrations card.

export const hostOf = (url) => {
    try {
        return new URL(url).hostname;
    } catch {
        return '';
    }
};

/** A reason code as a sentence in the panel's language. */
export const reasonText = (t, code, row = {}) => {
    if (!code) return '';
    if (/^http_\d+$/.test(code)) return t('linkChecks.reasons.http', { code: code.slice(5) });
    if (code === 'unexpected_final_url') {
        const expected = row.expected_mode === 'list'
            ? (row.expected_domains || []).join(', ')
            : (row.learned_domain || '—');
        return t('linkChecks.reasons.unexpected_final_url', { expected, got: hostOf(row.final_url) || '—' });
    }
    if (code === 'slow_response') {
        return t('linkChecks.reasons.slow_response', { seconds: Math.round((row.duration_ms || 0) / 1000) });
    }
    return t(`linkChecks.reasons.${code}`) || code;
};

const TONES = { ok: 'success', warning: 'warning', failed: 'danger', pending: 'muted' };
export const statusTone = (status) => TONES[status] || 'muted';

const duration = (t, seconds) => {
    const abs = Math.abs(seconds);
    if (abs < 3600) return t('linkChecks.durMin', { n: Math.max(1, Math.floor(abs / 60)) });
    if (abs < 86400) return t('linkChecks.durHours', { n: Math.floor(abs / 3600) });
    return t('linkChecks.durDays', { n: Math.floor(abs / 86400) });
};

/** "5 min ago" / "in 40 min" for a Unix timestamp in seconds; "" when there is none. */
export const agoText = (t, ts, nowTs = Math.floor(Date.now() / 1000)) => {
    if (!ts) return '';
    const diff = ts - nowTs;
    if (Math.abs(diff) < 60) return t('linkChecks.justNow');
    return diff > 0 ? t('linkChecks.in', { text: duration(t, diff) }) : t('linkChecks.ago', { text: duration(t, diff) });
};

/** The hosts a check went through, as a short "a.example → b.example" line. */
export const chainText = (hops) => {
    const hosts = [];
    (hops || []).forEach((hop) => {
        const host = hostOf(hop.url) || hop.url || '';
        if (host && hosts[hosts.length - 1] !== host) hosts.push(host);
    });
    return hosts.join(' → ');
};
