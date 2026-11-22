import React, { useState, useEffect } from 'react';
import { Lightbulb, X } from 'lucide-react';
import { DemoButton } from './common/DemoTour';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * InfoBanner — dismissible help banner at top of pages
 * Usage:
 *   <InfoBanner storageKey="help_postback" title="...">
 *     <p>Explanation text here...</p>
 *   </InfoBanner>
 *
 * `onDemo` (optional): adds a "Show me on an example" button that starts the
 * page's demo mode (common/DemoTour.jsx). A banner with a demo does not vanish
 * for good when dismissed — it folds into one small line that can bring the
 * hint or the example back, so closing the hint once does not hide the demo.
 */
const InfoBanner = ({ storageKey, title, children, icon: Icon = Lightbulb, variant = 'info', onDemo }) => {
    const { t } = useLanguage();
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        const stored = localStorage.getItem(`help_dismissed_${storageKey}`);
        if (stored === '1') setDismissed(true);
    }, [storageKey]);

    const handleDismiss = () => {
        setDismissed(true);
        localStorage.setItem(`help_dismissed_${storageKey}`, '1');
    };

    if (dismissed) {
        if (!onDemo) return null;
        return (
            <div className="flex flex-wrap items-center gap-2" style={{ marginBottom: '16px' }}>
                <button type="button" className="btn btn-secondary btn-sm"
                    onClick={() => {
                        setDismissed(false);
                        localStorage.removeItem(`help_dismissed_${storageKey}`);
                    }}>
                    <Lightbulb className="w-4 h-4" />{t('demo.showHint')}
                </button>
                <DemoButton compact onClick={onDemo} />
            </div>
        );
    }

    const colors = {
        info: {
            bg: 'var(--color-primary-light, #eff6ff)',
            border: 'var(--color-primary, #3b82f6)',
            icon: 'var(--color-primary, #3b82f6)',
            title: 'var(--color-text-primary)',
            text: 'var(--color-text-secondary)',
        },
        tip: {
            bg: '#f0fdf4',
            border: '#22c55e',
            icon: '#22c55e',
            title: 'var(--color-text-primary)',
            text: 'var(--color-text-secondary)',
        },
        warning: {
            bg: '#fffbeb',
            border: '#f59e0b',
            icon: '#f59e0b',
            title: 'var(--color-text-primary)',
            text: 'var(--color-text-secondary)',
        },
    };

    const c = colors[variant] || colors.info;

    return (
        <div style={{
            background: c.bg,
            borderLeft: `4px solid ${c.border}`,
            borderRadius: '0 12px 12px 0',
            padding: '14px 16px',
            marginBottom: '16px',
            position: 'relative',
            animation: 'bannerSlideIn 0.25s ease-out',
        }}>
            <button
                onClick={handleDismiss}
                style={{
                    position: 'absolute',
                    top: '10px',
                    right: '10px',
                    background: 'none',
                    border: 'none',
                    cursor: 'pointer',
                    color: 'var(--color-text-muted)',
                    padding: '4px',
                    borderRadius: '6px',
                    transition: 'background 0.2s',
                    lineHeight: 1,
                }}
                onMouseOver={e => e.currentTarget.style.background = 'rgba(0,0,0,0.05)'}
                onMouseOut={e => e.currentTarget.style.background = 'none'}
                aria-label="Dismiss"
            >
                <X size={16} />
            </button>

            <div style={{ display: 'flex', gap: '12px', paddingRight: '24px' }}>
                <Icon size={20} style={{ color: c.icon, flexShrink: 0, marginTop: '1px' }} />
                <div>
                    {title && (
                        <div style={{
                            fontWeight: 600,
                            fontSize: '14px',
                            color: c.title,
                            marginBottom: '4px',
                        }}>
                            {title}
                        </div>
                    )}
                    <div style={{
                        fontSize: '13px',
                        color: c.text,
                        lineHeight: 1.6,
                    }}>
                        {children}
                    </div>
                    {onDemo && (
                        <div style={{ marginTop: '10px' }}>
                            <DemoButton onClick={onDemo} />
                        </div>
                    )}
                </div>
            </div>

            <style>{`
                @keyframes bannerSlideIn {
                    from { opacity: 0; transform: translateY(-8px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            `}</style>
        </div>
    );
};

export default InfoBanner;
