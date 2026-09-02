import React, { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { CardMetricsButton, CardMetricsSheet } from './CardMetrics';

/**
 * Stacked-card rendering of a data table for narrow viewports (below lg).
 * The desktop table stays untouched; the same rows render as cards with the
 * row identity on top, the metrics the user actually checks as label/value
 * pairs, and the remaining fields behind a per-card "More" expander.
 *
 * `primaryIds` is the ordered list of metrics on the face of the card, and it
 * is the user's own choice (common/CardMetrics.jsx), not a slice of the desktop
 * column set: a phone shows four to eight numbers at a glance where the table
 * shows twenty. Pass `metricsPicker` to let them change it — the chip and the
 * sheet only exist here, because the cards only exist here.
 *
 * `fields` should therefore cover the union of the visible desktop columns and
 * the chosen card metrics, so a metric picked for the card still renders when
 * it is hidden in the table. Anything not on the face falls to "More".
 */
const MobileCards = ({
    rows,
    getId,
    renderTitle,
    renderSubtitle,
    renderHeaderRight,
    fields,
    primaryIds = [],
    metricsPicker = null,
    header,
    emptyState,
    className = '',
}) => {
    const { t } = useLanguage();
    const [expanded, setExpanded] = useState(() => new Set());
    const [pickerOpen, setPickerOpen] = useState(false);

    if (!rows || rows.length === 0) {
        return emptyState || null;
    }

    // The face of the card follows primaryIds' ORDER, not the order the page
    // happened to list its fields in — that order is the one the user set in
    // the picker, and reading it back from `fields` would silently ignore it.
    const byId = new Map(fields.map(f => [f.id, f]));
    const primary = primaryIds.map(id => byId.get(id)).filter(Boolean);
    const primarySet = new Set(primary.map(f => f.id));
    const more = fields.filter(f => !primarySet.has(f.id));
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
            {metricsPicker && (
                <div className="flex justify-end">
                    <CardMetricsButton count={primaryIds.length} onClick={() => setPickerOpen(true)} />
                </div>
            )}
            {metricsPicker && (
                <CardMetricsSheet
                    open={pickerOpen}
                    onClose={() => setPickerOpen(false)}
                    options={metricsPicker.options}
                    selected={primaryIds}
                    onChange={metricsPicker.onChange}
                    onReset={metricsPicker.onReset}
                />
            )}
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
                        {/* Title takes the card's full width so a long name
                            clamps to two lines instead of truncating to a
                            prefix every sibling card shares; actions moved
                            down to the subtitle row, keeping one header shape
                            for short and long names alike. */}
                        <div className="px-3.5 pt-3 pb-2.5">
                            <div className="flex items-start gap-2 min-w-0">{renderTitle(row)}</div>
                            {(renderSubtitle || renderHeaderRight) && (
                                <div className="flex items-center justify-between gap-2 mt-1 min-w-0">
                                    {renderSubtitle && (
                                        <div className="text-[11px] truncate flex-1 min-w-0" style={{ color: 'var(--color-text-muted)' }}>
                                            {renderSubtitle(row)}
                                        </div>
                                    )}
                                    {renderHeaderRight && (
                                        <div className="flex items-center gap-1.5 flex-shrink-0 flex-wrap justify-end">{renderHeaderRight(row)}</div>
                                    )}
                                </div>
                            )}
                        </div>

                        {primary.length > 0 && (
                            <div className="card-metric-grid gap-x-3 gap-y-2.5 px-3.5 pb-3">
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
