import React from 'react';
import { useLanguage } from '../../contexts/LanguageContext';
import { openLinkChecks } from './useLinkChecks';
import { reasonText } from './reason';

const COLORS = { failed: 'var(--color-danger, #dc2626)', warning: 'var(--color-warning, #d97706)' };

// The dot that stands next to an offer or a domain: red is broken, amber needs
// attention. A click opens the link under Notifications → Checks. Renders
// nothing when the link is fine or is not checked.
export default function LinkCheckMark({ check }) {
    const { t } = useLanguage();
    if (!check || !COLORS[check.status]) return null;
    const label = `${t(`linkChecks.${check.status}`)}: ${reasonText(t, check.error_code, check)}`;
    return (
        <button
            type="button"
            onClick={(e) => { e.stopPropagation(); openLinkChecks(check.id); }}
            title={label}
            aria-label={label}
            className="inline-flex shrink-0 items-center justify-center w-4 h-4 rounded-full text-[10px] font-bold leading-none text-white align-middle"
            style={{ backgroundColor: COLORS[check.status] }}
        >
            {check.status === 'failed' ? '✕' : '!'}
        </button>
    );
}
