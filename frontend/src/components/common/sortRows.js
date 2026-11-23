// Sorting for tables with sortable headers; SortableTh.jsx renders the header.

/**
 * Metric ids whose values sort numerically. Anything not listed sorts as text,
 * which is correct for names, states, URLs and country codes.
 */
export const NUMERIC_METRIC_KEYS = new Set([
    'id', 'payout_value', 'clicks', 'unique_clicks', 'visits', 'unique_visits',
    'lp_clicks', 'lp_ctr', 'conversions', 'leads', 'sales', 'rejected', 'trash',
    'approve_rate', 'revenue', 'revenue_confirmed', 'cost', 'cr', 'epc', 'epc_confirmed',
    'epv', 'cpc', 'cpv', 'profit', 'profit_confirmed', 'roi', 'roi_confirmed',
    // All derived metrics from orbitraComputeDerivedMetrics
    'cpa', 'cpl', 'cps', 'cpr', 'cpd', 'cr_sales', 'cr_holds', 'cr_leads', 'cr_registrations',
    'cr_deposits', 'registrations', 'deposits', 'uc_rate', 'bot_rate', 'uepc', 'uepc_confirmed',
    'epc_hold', 'uepc_hold', 'epc_registration', 'uepc_registration', 'ucpc', 'ecpm_all', 'ecpm_confirmed',
    'earnings_per_conv', 'ec_confirmed', 'revenue_hold', 'revenue_rejected', 'revenue_trash',
    'revenue_registration', 'revenue_deposit', 'real_revenue', 'real_profit', 'real_roi',
    'bots', 'proxies', 'empty_referrers', 'unique_clicks_stream', 'unique_clicks_global',
]);

/**
 * Sort rows by `sortBy` ({ key, dir }), stably. A null key keeps the API's own order.
 */
export const sortRows = (rows, sortBy) => {
    if (!sortBy?.key) return rows;
    const dirMul = sortBy.dir === 'asc' ? 1 : -1;
    const isNumeric = NUMERIC_METRIC_KEYS.has(sortBy.key);

    const getVal = (row) => {
        const val = row[sortBy.key];
        if (val === null || val === undefined) return '';
        if (typeof val === 'number') return val;
        return String(val);
    };

    return rows
        .map((row, idx) => ({ row, idx }))
        .sort((a, b) => {
            const av = getVal(a.row);
            const bv = getVal(b.row);
            const cmp = isNumeric
                ? (Number(av) || 0) - (Number(bv) || 0)
                : String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
            if (cmp !== 0) return cmp * dirMul;
            return a.idx - b.idx; // stable
        })
        .map(x => x.row);
};

/**
 * The shared toggle: same column flips direction, a new column starts at its
 * natural direction.
 */
export const nextSortState = (prev, key, defaultDir = 'asc') => (
    prev.key === key
        ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
        : { key, dir: defaultDir }
);
