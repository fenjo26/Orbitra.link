import React from 'react';
import { useLanguage } from '../contexts/LanguageContext';

// "Direction" of a click, Keitaro-style: the URL the visitor was really sent to
// (offer + the network's sub1 + resolved macros) or what a non-offer stream did
// with them (404, text, safe page…). `destination` comes from the API
// (core/ClickDestination.php); `fallbackUrl` is the raw offer URL older API
// responses carried.
const ACTION_KEYS = {
    not_found: ['logs.dirNotFound', '404 Not Found'],
    show_text: ['logs.dirShowText', 'Text shown'],
    show_html: ['logs.dirShowHtml', 'HTML shown'],
    do_nothing: ['logs.dirDoNothing', 'Empty response'],
    to_campaign: ['logs.dirToCampaign', 'To campaign #{id}'],
};

const ClickDirection = ({ destination, fallbackUrl = '', wrap = false }) => {
    const { t } = useLanguage();
    const d = destination || {};
    const muted = { color: 'var(--color-text-muted)' };

    if ((d.kind === 'offer' || d.kind === 'landing') && d.url) {
        const isHttp = /^https?:\/\//i.test(d.url);
        const title = d.kind === 'offer' ? `${d.url}\n${t('logs.dirReconstructed')}` : d.url;
        return isHttp ? (
            <a
                href={d.url}
                target="_blank"
                rel="noreferrer"
                title={title}
                className={`text-[var(--color-primary)] hover:underline ${wrap ? 'break-all' : 'block truncate'}`}
            >
                {d.url}
            </a>
        ) : <span className={wrap ? 'break-all' : 'block truncate'} title={title}>{d.url}</span>;
    }
    if (d.kind === 'action') {
        const [key, fallback] = ACTION_KEYS[d.action] || ACTION_KEYS.do_nothing;
        const label = t(key, fallback).replace('{id}', d.payload || '?');
        return <span className="whitespace-nowrap" style={muted}>{label}</span>;
    }
    if (d.kind === 'safe_page') {
        return <span className="whitespace-nowrap" style={muted}>{t('logs.dirSafePage')}</span>;
    }
    if (fallbackUrl) {
        return (
            <a href={fallbackUrl} target="_blank" rel="noreferrer" title={fallbackUrl}
                className={`text-[var(--color-primary)] hover:underline ${wrap ? 'break-all' : 'block truncate'}`}>
                {fallbackUrl}
            </a>
        );
    }
    return <span style={muted}>-</span>;
};

export default ClickDirection;
