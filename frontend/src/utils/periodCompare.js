// "This period vs the one before" helpers shared by Campaigns, Reports,
// Trends and Analytics Compare.

const parse = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null;
};
const fmt = (d) => d.toISOString().slice(0, 10);

export function shiftIsoDate(dateStr, deltaDays) {
    const d = parse(dateStr);
    if (!d) return dateStr;
    return fmt(new Date(d.getTime() + deltaDays * 86400000));
}

export function rangeLengthDays(from, to) {
    const a = parse(from);
    const b = parse(to);
    if (!a || !b || b < a) return 0;
    return Math.round((b - a) / 86400000) + 1;
}

/** The equally long period that ends the day before `from`. */
export function previousPeriod(from, to) {
    const a = parse(from);
    const b = parse(to);
    if (!a || !b || b < a) return null;
    const days = Math.round((b - a) / 86400000) + 1;
    const prevTo = new Date(a.getTime() - 86400000);
    const prevFrom = new Date(prevTo.getTime() - (days - 1) * 86400000);
    return { from: fmt(prevFrom), to: fmt(prevTo), days };
}

/**
 * Compute comparison date range by preset:
 *   - 'prev': immediately preceding period of equal length
 *   - 'wow' : same weekdays one week earlier (-7 days)
 *   - 'mom' : same weekdays 4 weeks earlier (-28 days)
 */
export function shiftedPeriod(from, to, preset = 'prev') {
    if (preset === 'wow') {
        const days = rangeLengthDays(from, to);
        if (!days) return null;
        return { from: shiftIsoDate(from, -7), to: shiftIsoDate(to, -7), days };
    }
    if (preset === 'mom') {
        const days = rangeLengthDays(from, to);
        if (!days) return null;
        return { from: shiftIsoDate(from, -28), to: shiftIsoDate(to, -28), days };
    }
    return previousPeriod(from, to);
}

/** Current YYYY-MM-DD and hour (0..23) in the given IANA timezone (or local). */
export function nowInTimezone(timezone) {
    const now = new Date();
    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone || undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            hour12: false,
        }).formatToParts(now);
        const get = (type) => parts.find((p) => p.type === type)?.value || '';
        const hRaw = Number(get('hour'));
        return {
            date: `${get('year')}-${get('month')}-${get('day')}`,
            hour: Number.isFinite(hRaw) ? (hRaw === 24 ? 0 : hRaw) : now.getHours(),
        };
    } catch {
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return { date: `${y}-${m}-${d}`, hour: now.getHours() };
    }
}

// Rates and percentages compare in percentage points, everything else in %.
const POINT_METRICS = new Set([
    'roi', 'roi_all', 'roi_confirmed', 'real_roi', 'cr', 'cr_all', 'cr_sales', 'cr_holds', 'cr_leads',
    'cr_registrations', 'cr_deposits', 'cr_regs_to_deps', 'ucr', 'uc_rate', 'uc_rate_stream',
    'uc_rate_global', 'bot_rate', 'approve_rate', 'approve_rate_excl_trash', 'profitability',
    'ctr', 'lp_ctr', 'real_lp_ctr', 'lp_bounce_rate', 'lp_scroll_depth', 'pwa_install_rate',
    'popup_ctr', 'platform_ctr', 'click_loss',
]);
// Lower is better: spend and per-unit costs, junk shares.
const LOWER_BETTER = new Set([
    'cost', 'cabinet_cost', 'fee', 'cpm', 'cpc', 'ucpc', 'cpv', 'ecpc', 'cpa', 'cpl', 'cps', 'cpr', 'cpd',
    'bot_rate', 'bots', 'possible_bots', 'proxies', 'rejected', 'trash', 'lp_bounce_rate', 'click_loss',
]);
// Not worth a delta: identifiers and averages that read badly as change.
const SKIP = new Set(['id', 'name', 'state', 'group_name', 'actions', 'check', 'lp_dwell_avg', 'ad_status']);

/**
 * { text, sign: 1|-1|0, good: true|false|null } or null when there is
 * nothing meaningful to show (no numbers, both zero, non-metric column).
 */
export function metricDelta(metricId, cur, prev) {
    if (SKIP.has(metricId)) return null;
    if (cur === null || cur === undefined || prev === null || prev === undefined) return null;
    const c = Number(cur);
    const p = Number(prev);
    if (!isFinite(c) || !isFinite(p)) return null;
    if (c === 0 && p === 0) return null;
    const lowerBetter = LOWER_BETTER.has(metricId);
    const judge = (sign) => (sign === 0 ? null : (sign > 0) !== lowerBetter);
    if (POINT_METRICS.has(metricId)) {
        const diff = c - p;
        if (Math.abs(diff) < 0.05) return { text: '0', sign: 0, good: null, points: true };
        const sign = diff > 0 ? 1 : -1;
        return { text: `${diff > 0 ? '+' : '−'}${Math.abs(diff).toFixed(1)}`, sign, good: judge(sign), points: true };
    }
    if (p === 0) return { text: null, sign: 1, good: judge(1), isNew: true };
    const pct = ((c - p) / Math.abs(p)) * 100;
    if (Math.abs(pct) < 0.5) return { text: '0%', sign: 0, good: null };
    const sign = pct > 0 ? 1 : -1;
    const abs = Math.abs(pct);
    return { text: `${pct > 0 ? '+' : '−'}${abs >= 100 ? Math.round(abs) : abs.toFixed(1)}%`, sign, good: judge(sign) };
}
