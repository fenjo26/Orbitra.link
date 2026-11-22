import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X, PlayCircle, RotateCcw, LogOut, FlaskConical } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';

// "Show me on an example" for the help banners (InfoBanner `onDemo`).
//
// A page in demo mode renders made-up data instead of calling the API (nothing
// is written anywhere and nothing the user owns is touched), shows DemoBar on
// top so the numbers can never be mistaken for real statistics, and runs a
// short DemoTour: a spotlight on one element at a time with a sentence on how
// to read it. Steps point at elements by `data-tour="<id>"`, so a step whose
// element is not on screen (e.g. the table is still empty) is skipped instead
// of pointing at nothing.
//
// The spotlight is pointer-events: none — the user can click the highlighted
// control (sort a column, switch a metric) while the tour explains it.

const PAD = 6;
const CARD_W = 340;
const GAP = 12;

const findTarget = (id) => (id ? document.querySelector(`[data-tour="${id}"]`) : null);

export const DemoTour = ({ steps, onClose }) => {
    const { t } = useLanguage();
    const visibleSteps = steps.filter(s => findTarget(s.target));
    const [idx, setIdx] = useState(0);
    const [rect, setRect] = useState(null);
    const step = visibleSteps[Math.min(idx, visibleSteps.length - 1)];
    const target = step?.target;

    // Bring the step's element into view, then keep the spotlight glued to it
    // while the page scrolls, resizes or re-renders under it (one rAF loop,
    // state only changes when the box actually moved).
    useEffect(() => {
        const el = findTarget(target);
        if (!el) return undefined;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        let raf = 0;
        const tick = () => {
            const node = findTarget(target);
            if (node) {
                const r = node.getBoundingClientRect();
                setRect(prev => (prev && prev.top === r.top && prev.left === r.left
                    && prev.width === r.width && prev.height === r.height)
                    ? prev
                    : { top: r.top, left: r.left, width: r.width, height: r.height });
            }
            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [target]);

    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowRight') setIdx(i => Math.min(i + 1, visibleSteps.length - 1));
            if (e.key === 'ArrowLeft') setIdx(i => Math.max(i - 1, 0));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, visibleSteps.length]);

    if (!step || !rect) return null;

    const isLast = idx >= visibleSteps.length - 1;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const cardW = Math.min(CARD_W, vw - 24);
    // Below the element when there is room, above otherwise; a tall element
    // that fills the screen gets the card pinned to the bottom edge.
    const spaceBelow = vh - (rect.top + rect.height + PAD);
    const spaceAbove = rect.top - PAD;
    let cardTop;
    if (spaceBelow >= 190) cardTop = rect.top + rect.height + PAD + GAP;
    else if (spaceAbove >= 190) cardTop = null; // anchored by bottom below
    else cardTop = vh - 200;
    const cardBottom = cardTop === null ? vh - rect.top + PAD + GAP : undefined;
    const cardLeft = Math.max(12, Math.min(rect.left, vw - cardW - 12));

    return createPortal(
        <div data-demo-tour="" style={{ position: 'fixed', inset: 0, zIndex: 3000, pointerEvents: 'none' }}>
            <div
                style={{
                    position: 'fixed',
                    top: rect.top - PAD,
                    left: rect.left - PAD,
                    width: rect.width + PAD * 2,
                    height: rect.height + PAD * 2,
                    borderRadius: '12px',
                    boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.55), 0 0 0 2px var(--color-primary)',
                    transition: 'top .2s ease, left .2s ease, width .2s ease, height .2s ease',
                }}
            />
            <div
                role="dialog"
                aria-live="polite"
                style={{
                    position: 'fixed',
                    top: cardTop ?? undefined,
                    bottom: cardBottom,
                    left: cardLeft,
                    width: cardW,
                    pointerEvents: 'auto',
                    background: 'var(--color-bg-card)',
                    color: 'var(--color-text-primary)',
                    border: '1px solid var(--color-border)',
                    borderRadius: '14px',
                    boxShadow: '0 12px 32px rgba(0,0,0,0.25)',
                    padding: '14px 16px',
                }}
            >
                <div className="flex items-start justify-between gap-2" style={{ marginBottom: '6px' }}>
                    <div style={{ fontWeight: 600, fontSize: '14px' }}>{step.title}</div>
                    <button type="button" onClick={onClose} aria-label={t('demo.closeTour')} title={t('demo.closeTour')}
                        style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--color-text-muted)', padding: '2px', lineHeight: 1 }}>
                        <X size={16} />
                    </button>
                </div>
                <div style={{ fontSize: '13px', lineHeight: 1.55, color: 'var(--color-text-secondary)' }}>{step.text}</div>
                <div className="flex items-center justify-between" style={{ marginTop: '12px' }}>
                    <span style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                        {t('demo.stepOf', { n: idx + 1, total: visibleSteps.length })}
                    </span>
                    <div className="flex items-center gap-2">
                        {idx > 0 && (
                            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setIdx(idx - 1)}>
                                {t('demo.back')}
                            </button>
                        )}
                        <button type="button" className="btn btn-primary btn-sm"
                            onClick={() => (isLast ? onClose() : setIdx(idx + 1))}>
                            {isLast ? t('demo.done') : t('demo.next')}
                        </button>
                    </div>
                </div>
            </div>
        </div>,
        document.body
    );
};

// Always-visible strip while a page shows example data: the one thing that
// must never happen is someone reading made-up numbers as their own.
export const DemoBar = ({ onReplay, onExit }) => {
    const { t } = useLanguage();
    return (
        <div data-demo-bar="" role="status" style={{
            display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '10px',
            padding: '10px 14px', borderRadius: '12px',
            background: 'color-mix(in srgb, var(--color-warning) 16%, var(--color-bg-card))',
            border: '1px solid color-mix(in srgb, var(--color-warning) 55%, transparent)',
        }}>
            <FlaskConical size={18} style={{ color: 'var(--color-warning)', flexShrink: 0 }} />
            <div style={{ flex: '1 1 260px', minWidth: 0 }}>
                <div style={{ fontWeight: 600, fontSize: '14px', color: 'var(--color-text-primary)' }}>{t('demo.barTitle')}</div>
                <div style={{ fontSize: '13px', color: 'var(--color-text-secondary)' }}>{t('demo.barText')}</div>
            </div>
            <button type="button" className="btn btn-secondary btn-sm" onClick={onReplay}>
                <RotateCcw className="w-4 h-4" />{t('demo.replay')}
            </button>
            <button type="button" className="btn btn-primary btn-sm" onClick={onExit}>
                <LogOut className="w-4 h-4" />{t('demo.exit')}
            </button>
        </div>
    );
};

// The banner's call to action. Exported so a page can also place it elsewhere.
export const DemoButton = ({ onClick, compact = false }) => {
    const { t } = useLanguage();
    return (
        <button type="button" onClick={onClick} className={compact ? 'btn btn-secondary btn-sm' : 'btn btn-primary btn-sm'}
            data-demo-start="">
            <PlayCircle className="w-4 h-4" />{t('demo.show')}
        </button>
    );
};
