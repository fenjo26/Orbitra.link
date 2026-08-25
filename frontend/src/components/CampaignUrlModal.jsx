import React, { useState } from 'react';
import { X, Copy, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard } from '../utils/clipboard';

/**
 * Campaign URL fallback modal.
 *
 * Shown only when the copy button could not reach any clipboard transport
 * (plain-HTTP / bare-IP panels): here the URL can at least be selected by
 * hand. The URL renders as ONE continuous selectable run — breaking it into
 * styled fragments at macro boundaries made partial selection a puzzle, so
 * the macros are tinted with per-character spans instead of block elements.
 */
const CampaignUrlModal = ({ name, url, onClose }) => {
    const { t } = useLanguage();
    const [copied, setCopied] = useState(false);

    // {macro} tokens (Keitaro-style {sub_id}, {campaign_id}…) get their own
    // color inside the run; everything else stays plain. One text node's
    // worth of spans, no wrapping elements that would break selection.
    const renderUrl = (value) => {
        const parts = String(value || '').split(/(\{[^{}]+\})/g);
        return parts.map((part, i) => (
            /^\{[^{}]+\}$/.test(part)
                ? <span key={i} style={{ color: 'var(--color-primary)', fontWeight: 600 }}>{part}</span>
                : <span key={i}>{part}</span>
        ));
    };

    const handleCopy = async () => {
        const ok = await copyToClipboard(url);
        if (ok) {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    if (!url) return null;

    return (
        <div className="modal-overlay" onClick={onClose}>
            <div
                className="modal-content max-w-xl w-full rounded-2xl p-6"
                style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}
                onClick={e => e.stopPropagation()}
            >
                <div className="flex items-center justify-between mb-4">
                    <div className="min-w-0">
                        <h3 className="modal-title">{t('table.campaignUrl')}</h3>
                        {name && (
                            <div className="text-xs truncate mt-0.5" style={{ color: 'var(--color-text-muted)' }} title={name}>
                                {name}
                            </div>
                        )}
                    </div>
                    <button onClick={onClose} className="btn btn-ghost btn-icon">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div
                    className="rounded-xl px-3.5 py-3 font-mono text-xs leading-relaxed"
                    style={{
                        backgroundColor: 'var(--color-bg-soft)',
                        border: '1px solid var(--color-border)',
                        color: 'var(--color-text-primary)',
                        wordBreak: 'break-all',
                        userSelect: 'text',
                        WebkitUserSelect: 'text'
                    }}
                >
                    {renderUrl(url)}
                </div>

                <div className="modal-footer flex justify-end gap-2.5 pt-4" style={{ borderTop: '1px solid var(--color-border)' }}>
                    <button type="button" onClick={onClose} className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl">
                        {t('common.close')}
                    </button>
                    <button
                        type="button"
                        onClick={handleCopy}
                        className="btn btn-primary text-xs py-1.5 px-4 rounded-xl flex items-center gap-1.5 font-medium"
                    >
                        {copied
                            ? <Check className="w-3.5 h-3.5" style={{ color: 'var(--color-text-inverse, #fff)' }} />
                            : <Copy className="w-3.5 h-3.5" />}
                        {copied ? t('common.copied') : t('table.copyLink')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default CampaignUrlModal;
