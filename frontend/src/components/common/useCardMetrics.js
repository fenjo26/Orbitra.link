import { useCallback, useState } from 'react';

// Which metrics a mobile card shows, chosen and ordered by the user.
//
// Deliberately INDEPENDENT of the desktop column set: a phone shows four to
// eight numbers at a glance, a desktop table shows twenty, and forcing the card
// to mirror the table means either a wall of fields on the phone or a table
// stripped down to fit one. The two now travel separately, keyed per table.

export const MAX_CARD_METRICS = 8;

const storageKey = (tableId) => `orbitra_card_metrics_${tableId}`;

/**
 * Persisted card-metric selection for one table.
 *
 * @param {string}   tableId   storage scope: 'campaigns' | 'offers' | 'landings'
 * @param {string[]} defaults  used until the user chooses for themselves
 * @param {Set|null} allowed   ids that exist for this table; anything else is
 *                             dropped, so a metric removed from the app does
 *                             not linger as a blank card field forever
 */
export const useCardMetrics = (tableId, defaults, allowed = null) => {
    const clean = useCallback((ids) => {
        const seen = new Set();
        const out = [];
        for (const id of Array.isArray(ids) ? ids : []) {
            if (typeof id !== 'string' || seen.has(id)) continue;
            if (allowed && !allowed.has(id)) continue;
            seen.add(id);
            out.push(id);
            if (out.length >= MAX_CARD_METRICS) break;
        }
        return out;
    }, [allowed]);

    const [ids, setIdsState] = useState(() => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey(tableId)) || 'null');
            if (Array.isArray(saved)) {
                const cleaned = clean(saved);
                // An empty saved list is a real choice ("show me none"), so it is
                // only the ABSENCE of the key that falls back to the defaults.
                return cleaned;
            }
        } catch { /* unreadable saved value - fall through to defaults */ }
        return clean(defaults);
    });

    const setIds = useCallback((next) => {
        const cleaned = clean(typeof next === 'function' ? next(ids) : next);
        setIdsState(cleaned);
        try {
            localStorage.setItem(storageKey(tableId), JSON.stringify(cleaned));
        } catch { /* private mode / quota - the choice just does not persist */ }
    }, [clean, ids, tableId]);

    const reset = useCallback(() => {
        try {
            localStorage.removeItem(storageKey(tableId));
        } catch { /* see setIds */ }
        setIdsState(clean(defaults));
    }, [clean, defaults, tableId]);

    return { ids, setIds, reset };
};
