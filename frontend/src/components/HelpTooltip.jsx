import React, { useState, useRef, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { HelpCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * HelpTooltip — "?" icon with a popup tooltip
 * Usage: <HelpTooltip textKey="help.aliasTooltip" />
 *   or:  <HelpTooltip text="Custom text here" />
 */
const HelpTooltip = ({ textKey, text, position = 'top', size = 15, style = {} }) => {
    const { t } = useLanguage();
    const [visible, setVisible] = useState(false);
    const [tipCoords, setTipCoords] = useState({ left: 0, top: 0 });
    const tipRef = useRef(null);
    const btnRef = useRef(null);

    const content = text || (textKey ? t(textKey) : '');

    useEffect(() => {
        if (!visible) return;
        const handleClickOutside = (e) => {
            if (tipRef.current && !tipRef.current.contains(e.target) &&
                btnRef.current && !btnRef.current.contains(e.target)) {
                setVisible(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [visible]);

    useEffect(() => {
        if (!visible) return;

        const updatePosition = () => {
            if (!btnRef.current || !tipRef.current) return;

            const btnRect = btnRef.current.getBoundingClientRect();
            const tipRect = tipRef.current.getBoundingClientRect();
            const gap = 10;

            let left = btnRect.left + btnRect.width / 2 - tipRect.width / 2;
            let top = btnRect.top - tipRect.height - gap;

            if (position === 'bottom') {
                top = btnRect.bottom + gap;
            } else if (position === 'left') {
                left = btnRect.left - tipRect.width - gap;
                top = btnRect.top + btnRect.height / 2 - tipRect.height / 2;
            } else if (position === 'right') {
                left = btnRect.right + gap;
                top = btnRect.top + btnRect.height / 2 - tipRect.height / 2;
            }

            const padding = 8;
            left = Math.max(padding, Math.min(left, window.innerWidth - tipRect.width - padding));
            top = Math.max(padding, Math.min(top, window.innerHeight - tipRect.height - padding));

            setTipCoords({ left, top });
        };

        const rafId = requestAnimationFrame(updatePosition);
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
        return () => {
            cancelAnimationFrame(rafId);
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
        };
    }, [visible, content, position]);

    const positionStyles = {
        top: {},
        bottom: {},
        left: {},
        right: {},
    };

    const arrowStyles = {
        top: { bottom: '-5px', left: '50%', transform: 'translateX(-50%) rotate(45deg)' },
        bottom: { top: '-5px', left: '50%', transform: 'translateX(-50%) rotate(45deg)' },
        left: { right: '-5px', top: '50%', transform: 'translateY(-50%) rotate(45deg)' },
        right: { left: '-5px', top: '50%', transform: 'translateY(-50%) rotate(45deg)' },
    };

    return (
        <span style={{ display: 'inline-flex', alignItems: 'center', ...style }}>
            <button
                ref={btnRef}
                onClick={(e) => { e.preventDefault(); e.stopPropagation(); setVisible(!visible); }}
                onMouseEnter={() => setVisible(true)}
                onMouseLeave={() => setVisible(false)}
                style={{
                    background: 'none',
                    border: 'none',
                    cursor: 'help',
                    padding: '2px',
                    display: 'inline-flex',
                    alignItems: 'center',
                    color: 'var(--color-text-muted)',
                    transition: 'color 0.2s',
                    lineHeight: 1,
                }}
                onFocus={() => setVisible(true)}
                onBlur={() => setVisible(false)}
                type="button"
                aria-label="Help"
            >
                <HelpCircle size={size} />
            </button>

            {visible && content && createPortal(
                <div
                    ref={tipRef}
                    style={{
                        position: 'fixed',
                        left: `${tipCoords.left}px`,
                        top: `${tipCoords.top}px`,
                        ...positionStyles[position],
                        zIndex: 10000,
                        width: 'max-content',
                        maxWidth: '320px',
                        padding: '10px 14px',
                        borderRadius: '10px',
                        background: 'var(--color-bg-card, #fff)',
                        color: 'var(--color-text-secondary)',
                        fontSize: '13px',
                        lineHeight: 1.55,
                        boxShadow: '0 4px 20px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05)',
                        pointerEvents: 'none',
                        animation: 'helpFadeIn 0.15s ease-out',
                    }}
                >
                    {/* Arrow */}
                    <div style={{
                        position: 'absolute',
                        width: '10px',
                        height: '10px',
                        background: 'var(--color-bg-card, #fff)',
                        boxShadow: '-1px -1px 2px rgba(0,0,0,0.05)',
                        ...arrowStyles[position],
                    }} />
                    <span style={{ position: 'relative', zIndex: 1 }}>{content}</span>
                </div>,
                document.body
            )}

            <style>{`
                @keyframes helpFadeIn {
                    from { opacity: 0; transform: translateY(4px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            `}</style>
        </span>
    );
};

export default HelpTooltip;
