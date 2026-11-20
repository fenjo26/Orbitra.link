import React from 'react';

// Change badge under a metric value — see utils/periodCompare.js.
export function DeltaBadge({ delta, t }) {
    if (!delta) return null;
    const color = delta.good === null
        ? 'var(--color-text-muted)'
        : delta.good ? 'var(--color-success, #10b981)' : 'var(--color-danger, #ef4444)';
    const arrow = delta.sign > 0 ? '▲' : delta.sign < 0 ? '▼' : '';
    const label = delta.isNew
        ? t('periodCompare.new')
        : delta.points ? `${delta.text} ${t('periodCompare.points')}` : delta.text;
    return (
        <span className="text-[10px] font-semibold whitespace-nowrap" style={{ color }} data-testid="period-delta">
            {arrow && <span style={{ fontSize: '8px', marginRight: 2 }}>{arrow}</span>}{label}
        </span>
    );
}
