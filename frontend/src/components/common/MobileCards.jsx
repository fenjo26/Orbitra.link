import React, { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';

/**
 * Stacked-card rendering of a data table for narrow viewports (below lg).
 * The desktop table stays untouched; the same rows render as cards with the
 * row identity on top, the metrics the user actually checks as label/value
 * pairs, and the remaining fields behind a per-card "More" expander.
 *
 * Which metrics appear is driven by the same column customiser the table
 * uses: pages pass `fields` for their visible columns (in customiser order)
 * plus a `primaryIds` priority list — a primary metric the user hid simply
 * does not appear, and if all of them are hidden the first visible columns
 * take their place so the card top is never empty.
 */
const MobileCards = ({
    rows,
    getId,
    renderTitle,
    renderSubtitle,
    renderHeaderRight,
    fields,
    primaryIds = [],
    header,
    emptyState,
    className = '',
}) => {
    const { t } = useLanguage();
    const [expanded, setExpanded] = useState(() => new Set());

    if (!rows || rows.length === 0) {
        return emptyState || null;
    }

    const primarySet = new Set(primaryIds);
    const primary = [];
    const more = [];
    fields.forEach(f => (primarySet.has(f.id) ? primary : more).push(f));
    if (primary.length === 0 && more.length > 0) {
        primary.push(...more.splice(0, Math.min(4, more.length)));
    }

    const toggle = (id) => {
        setExpanded(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    return (
        <div className={`space-y-3 ${className}`}>
            {header}
            {rows.map((row) => {
                const id = getId(row);
                const isOpen = expanded.has(id);
                return (
                    <div
                        key={id}
                        className="rounded-xl border overflow-hidden"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    >
                        <div className="flex items-start justify-between gap-2 px-3.5 pt-3 pb-2.5">
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">{renderTitle(row)}</div>
                                {renderSubtitle && (
                                    <div className="text-[11px] mt-1 truncate" style={{ color: 'var(--color-text-muted)' }}>
                                        {renderSubtitle(row)}
                                    </div>
                                )}
                            </div>
                            {renderHeaderRight && (
                                <div className="flex items-center gap-1.5 flex-shrink-0">{renderHeaderRight(row)}</div>
                            )}
                        </div>

                        {primary.length > 0 && (
                            <div className="grid grid-cols-2 gap-x-3 gap-y-2.5 px-3.5 pb-3">
                                {primary.map(f => <CardField key={f.id} label={f.label} value={f.render(row)} />)}
                            </div>
                        )}

                        {more.length > 0 && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => toggle(id)}
                                    className="w-full flex items-center justify-center gap-1.5 text-xs font-medium"
                                    style={{ borderTop: '1px solid var(--color-border)', color: 'var(--color-primary)', minHeight: 44 }}
                                >
                                    {isOpen ? t('mobileCards.showLess') : t('mobileCards.showMore')}
                                    <ChevronDown className={`w-3.5 h-3.5 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                                </button>
                                {isOpen && (
                                    <div
                                        className="grid grid-cols-2 gap-x-3 gap-y-2.5 px-3.5 py-3 border-t"
                                        style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}
                                    >
                                        {more.map(f => <CardField key={f.id} label={f.label} value={f.render(row)} />)}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                );
            })}
        </div>
    );
};

const CardField = ({ label, value }) => (
    <div className="min-w-0">
        <div className="text-[10px] font-semibold uppercase tracking-wide truncate" style={{ color: 'var(--color-text-muted)' }}>
            {label}
        </div>
        <div
            className="text-[13px] font-medium truncate"
            style={{ color: 'var(--color-text-primary)', fontVariantNumeric: 'tabular-nums' }}
        >
            {value}
        </div>
    </div>
);

export default MobileCards;
