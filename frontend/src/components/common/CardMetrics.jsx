import React, { useCallback, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowUp, ArrowDown, X, SlidersHorizontal } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';

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

/**
 * The picker itself: a bottom sheet on the phone, which is the only place the
 * card layout exists. Chosen metrics sit at the top in card order with up/down
 * arrows; everything else follows alphabetically by label.
 */
export const CardMetricsSheet = ({ open, onClose, options, selected, onChange, onReset }) => {
    const { t } = useLanguage();
    const selectedSet = useMemo(() => new Set(selected), [selected]);
    const rest = useMemo(
        () => options.filter(o => !selectedSet.has(o.id))
            .slice()
            .sort((a, b) => String(a.label).localeCompare(String(b.label))),
        [options, selectedSet]
    );
    const byId = useMemo(() => new Map(options.map(o => [o.id, o])), [options]);

    if (!open || typeof document === 'undefined') return null;

    const move = (idx, delta) => {
        const next = [...selected];
        const to = idx + delta;
        if (to < 0 || to >= next.length) return;
        const [item] = next.splice(idx, 1);
        next.splice(to, 0, item);
        onChange(next);
    };

    const atLimit = selected.length >= MAX_CARD_METRICS;

    return createPortal(
        <div
            className="fixed inset-0 z-[70] flex items-end lg:hidden"
            style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}
            onClick={onClose}
        >
            <div
                className="w-full rounded-t-2xl flex flex-col"
                style={{
                    backgroundColor: 'var(--color-bg-card)',
                    maxHeight: '85vh',
                    paddingBottom: 'env(safe-area-inset-bottom)',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                <div
                    className="flex items-center justify-between px-4 py-3 border-b flex-shrink-0"
                    style={{ borderColor: 'var(--color-border)' }}
                >
                    <div className="min-w-0">
                        <div className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                            {t('mobileCards.cardMetrics', 'Card metrics')}
                        </div>
                        <div className="text-[11px]" style={{ color: 'var(--color-text-muted)' }}>
                            {selected.length}/{MAX_CARD_METRICS} · {t('mobileCards.cardMetricsHint', 'Shown on every card, in this order')}
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="hit-44 p-2 rounded-lg" aria-label={t('common.close', 'Close')}>
                        <X className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                    </button>
                </div>

                <div className="overflow-y-auto px-2 py-2 flex-1">
                    {selected.map((id, idx) => {
                        const opt = byId.get(id);
                        if (!opt) return null;
                        return (
                            <div key={id} className="flex items-center gap-1 px-2 py-1.5 rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)', marginBottom: 4 }}>
                                <input
                                    type="checkbox"
                                    checked
                                    onChange={() => onChange(selected.filter(x => x !== id))}
                                    className="w-4 h-4 rounded flex-shrink-0"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />
                                <span className="text-[13px] flex-1 min-w-0 truncate" style={{ color: 'var(--color-text-primary)' }}>
                                    {opt.label}
                                </span>
                                {/* Arrows, not drag: a drag inside a scrolling
                                    bottom sheet fights the scroll on touch. */}
                                <button
                                    type="button"
                                    onClick={() => move(idx, -1)}
                                    disabled={idx === 0}
                                    className="hit-44 p-1.5 rounded-lg disabled:opacity-25"
                                    aria-label={t('mobileCards.moveUp', 'Move up')}
                                >
                                    <ArrowUp className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => move(idx, 1)}
                                    disabled={idx === selected.length - 1}
                                    className="hit-44 p-1.5 rounded-lg disabled:opacity-25"
                                    aria-label={t('mobileCards.moveDown', 'Move down')}
                                >
                                    <ArrowDown className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                                </button>
                            </div>
                        );
                    })}

                    {rest.map(opt => (
                        <label
                            key={opt.id}
                            className="flex items-center gap-2 px-2 py-2 rounded-lg"
                            style={{ opacity: atLimit ? 0.4 : 1 }}
                        >
                            <input
                                type="checkbox"
                                checked={false}
                                disabled={atLimit}
                                onChange={() => onChange([...selected, opt.id])}
                                className="w-4 h-4 rounded flex-shrink-0"
                                style={{ accentColor: 'var(--color-primary)' }}
                            />
                            <span className="text-[13px] min-w-0 truncate" style={{ color: 'var(--color-text-secondary)' }}>
                                {opt.label}
                            </span>
                        </label>
                    ))}
                </div>

                <div className="flex items-center justify-between px-4 py-3 border-t flex-shrink-0" style={{ borderColor: 'var(--color-border)' }}>
                    <button
                        type="button"
                        onClick={onReset}
                        className="hit-44 text-[13px] font-medium"
                        style={{ color: 'var(--color-text-muted)' }}
                    >
                        {t('common.reset', 'Reset')}
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="hit-44 px-4 rounded-lg text-[13px] font-semibold"
                        style={{ backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}
                    >
                        {t('common.done', 'Done')}
                    </button>
                </div>
            </div>
        </div>,
        document.body
    );
};

/**
 * The chip that opens the sheet. Rendered by MobileCards, so it exists only
 * where the cards do - there is nothing for it to configure on desktop.
 */
export const CardMetricsButton = ({ count, onClick }) => {
    const { t } = useLanguage();
    return (
        <button
            type="button"
            onClick={onClick}
            className="hit-44 inline-flex items-center gap-1.5 px-2.5 rounded-lg text-[11px] font-semibold"
            style={{
                backgroundColor: 'var(--color-bg-soft)',
                border: '1px solid var(--color-border)',
                color: 'var(--color-text-secondary)',
            }}
        >
            <SlidersHorizontal className="w-3.5 h-3.5" />
            {t('mobileCards.cardMetrics', 'Card metrics')}
            <span style={{ color: 'var(--color-text-muted)' }}>{count}</span>
        </button>
    );
};
