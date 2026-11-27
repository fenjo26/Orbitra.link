import React from 'react';
import { Activity } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { openLinkChecks } from './useLinkChecks';

const Parts = ({ label, counts, t }) => (
    <span className="flex items-center gap-1.5 whitespace-nowrap text-sm">
        <span style={{ color: 'var(--color-text-muted)' }}>{label}</span>
        {counts.ok > 0 && <span style={{ color: 'var(--color-success, #10b981)' }}>✓ {counts.ok}</span>}
        {counts.warning > 0 && <span style={{ color: 'var(--color-warning, #d97706)' }}>! {counts.warning}</span>}
        {counts.failed > 0 && <span style={{ color: 'var(--color-danger, #dc2626)' }}>✕ {counts.failed}</span>}
        {counts.ok + counts.warning + counts.failed === 0 && <span style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.pending')}</span>}
    </span>
);

// The dashboard strip: offers and domains by status, one click away from the
// list. Shows nothing until the feature is on and there is something checked.
export default function LinkChecksBlock({ summary }) {
    const { t } = useLanguage();
    if (!summary?.enabled) return null;
    const total = (summary.offers?.total || 0) + (summary.domains?.total || 0);
    if (total === 0) return null;
    const problem = (summary.offers?.failed || 0) + (summary.offers?.warning || 0) + (summary.domains?.failed || 0) + (summary.domains?.warning || 0);

    return (
        <button
            type="button"
            onClick={() => openLinkChecks()}
            className="w-full flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 rounded-lg border text-left transition hover:bg-[var(--color-bg-hover)]"
            style={{ borderColor: problem > 0 ? 'var(--color-danger, #dc2626)' : 'var(--color-border)' }}
            aria-label={t('linkChecks.title')}
        >
            <span className="flex items-center gap-2 text-sm font-semibold" style={{ color: problem > 0 ? 'var(--color-danger, #dc2626)' : 'var(--color-text-primary)' }}>
                <Activity size={16} />
                {t('linkChecks.title')}
            </span>
            <Parts t={t} label={t('linkChecks.typeOffer')} counts={summary.offers} />
            <Parts t={t} label={t('linkChecks.typeDomain')} counts={summary.domains} />
        </button>
    );
}
